<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\Concerns\HasProviderOverrides;
use RoundlyConsulting\Geolocation\Concerns\InteractsWithRateLimits;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceMatrix;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\DistanceProvider;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Exceptions\ProviderUnavailableException;
use RoundlyConsulting\Geolocation\GeolocationProvider;
use RoundlyConsulting\Geolocation\Support\Decimal;

/**
 * Resolves geolocation via Google's Geocoding API and travel distance via the
 * Distance Matrix API, using Laravel's HTTP client (no third-party SDK).
 */
final class GoogleProvider implements DistanceProvider, GeolocationProvider
{
    use HasProviderOverrides;
    use InteractsWithRateLimits;

    public function distance(DistanceQuery $query): ?Distance
    {
        try {
            $response = $this->throttled('google', fn (): Response => $this->client()->get('/distancematrix/json', [
                'origins' => Decimal::pair($query->fromLatitude, $query->fromLongitude),
                'destinations' => Decimal::pair($query->toLatitude, $query->toLongitude),
                'mode' => $query->type === DistanceType::Driving ? 'driving' : 'walking',
            ]));
        } catch (ConnectionException $e) {
            throw $this->unavailable($e);
        }

        if ($response->failed()) {
            return null;
        }

        /** @var array<string, mixed>|null $element */
        $element = $response->json('rows.0.elements.0');

        if (! is_array($element) || ($element['status'] ?? null) !== 'OK') {
            return null;
        }

        /** @var array{text: string, value: int} $distance */
        $distance = $element['distance'];
        /** @var array{text: string, value: int} $duration */
        $duration = $element['duration'];

        return new Distance(
            humanReadableDistance: $distance['text'],
            distanceInMeters: (int) $distance['value'],
            humanReadableDuration: $duration['text'],
            durationInSeconds: (int) $duration['value'],
            type: $query->type,
        );
    }

    /**
     * Resolve a full distance grid between several origins and destinations in a single
     * Distance Matrix call, degrading to null cells when the API fails or a leg is missing.
     *
     * @param  list<Coordinates>  $origins
     * @param  list<Coordinates>  $destinations
     */
    public function distanceMatrix(
        array $origins,
        array $destinations,
        DistanceType $type = DistanceType::Driving,
    ): DistanceMatrix {
        $rows = [];

        if ($origins === [] || $destinations === []) {
            return new DistanceMatrix($origins, $destinations, []);
        }

        try {
            $response = $this->throttled('google', fn (): Response => $this->client()->get('/distancematrix/json', [
                'origins' => $this->encode($origins),
                'destinations' => $this->encode($destinations),
                'mode' => $type === DistanceType::Driving ? 'driving' : 'walking',
            ]));
        } catch (ConnectionException) {
            // An unreachable API degrades every cell to null rather than aborting.
            $response = null;
        }

        /** @var list<array<string, mixed>> $responseRows */
        $responseRows = $response !== null && $response->successful()
            ? (array) $response->json('rows', [])
            : [];

        foreach ($origins as $originIndex => $origin) {
            $elements = $responseRows[$originIndex]['elements'] ?? [];
            $cells = [];

            foreach ($destinations as $destinationIndex => $destination) {
                $element = is_array($elements) ? ($elements[$destinationIndex] ?? null) : null;
                $cells[$destinationIndex] = is_array($element)
                    ? $this->elementToDistance($element, $type)
                    : null;
            }

            $rows[$originIndex] = $cells;
        }

        return new DistanceMatrix($origins, $destinations, $rows);
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function elementToDistance(array $element, DistanceType $type): ?Distance
    {
        if (($element['status'] ?? null) !== 'OK') {
            return null;
        }

        /** @var array{text: string, value: int} $distance */
        $distance = $element['distance'];
        /** @var array{text: string, value: int} $duration */
        $duration = $element['duration'];

        return new Distance(
            humanReadableDistance: $distance['text'],
            distanceInMeters: (int) $distance['value'],
            humanReadableDuration: $duration['text'],
            durationInSeconds: (int) $duration['value'],
            type: $type,
        );
    }

    /**
     * @param  list<Coordinates>  $points
     */
    private function encode(array $points): string
    {
        return implode('|', array_map(
            static fn (Coordinates $point): string => Decimal::pair($point->latitude, $point->longitude),
            $points,
        ));
    }

    public function locate(GeolocationQuery $query): ?Location
    {
        $parameters = $this->geocodeParameters($query);

        if ($parameters === null) {
            return null;
        }

        try {
            $response = $this->throttled('google', fn (): Response => $this->client()->get('/geocode/json', $parameters));
        } catch (ConnectionException $e) {
            throw $this->unavailable($e);
        }

        if ($response->failed()) {
            return null;
        }

        /** @var array<string, mixed>|null $result */
        $result = $response->json('results.0');

        if (! is_array($result)) {
            return null;
        }

        return $this->toLocation($result);
    }

    /**
     * Build the geocoding query parameters for either a reverse (coords) or forward
     * (address) lookup, or null when neither is present.
     *
     * @return array{latlng: string}|array{address: string}|null
     */
    private function geocodeParameters(GeolocationQuery $query): ?array
    {
        if ($query->latitude !== null && $query->longitude !== null) {
            return ['latlng' => Decimal::pair($query->latitude, $query->longitude)];
        }

        if ($query->address !== null && $query->address !== '') {
            return ['address' => $query->address];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function toLocation(array $result): Location
    {
        /** @var list<array<string, mixed>> $components */
        $components = $result['address_components'] ?? [];

        /** @var array{lat: float|int, lng: float|int} $location */
        $location = $result['geometry']['location'];

        return new Location(
            humanReadable: (string) ($result['formatted_address'] ?? ''),
            street: $this->component($components, 'street_address', 'long_name')
                ?? $this->component($components, 'route', 'long_name')
                ?? '',
            city: $this->component($components, 'locality', 'long_name') ?? '',
            countryIsoCode: $this->component($components, 'country', 'short_name') ?? '',
            latitude: (float) $location['lat'],
            longitude: (float) $location['lng'],
            type: GeolocationType::Geolocation,
            region: $this->component($components, 'administrative_area_level_1', 'long_name') ?? '',
            postalCode: $this->component($components, 'postal_code', 'long_name') ?? '',
        );
    }

    private function key(): ?string
    {
        $override = $this->override('token');
        $key = is_string($override) && $override !== '' ? $override : config('geolocation.services.google.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * The key rides in the query string, so a transport error's message (which ends in the
     * request URL) carries it — redact before it leaves the provider.
     */
    private function unavailable(ConnectionException $e): ProviderUnavailableException
    {
        return ProviderUnavailableException::for('google', $e, [$this->key()]);
    }

    private function client(): PendingRequest
    {
        $timeout = $this->override('timeout');

        return Http::baseUrl(rtrim((string) config('geolocation.services.google.url'), '/'))
            ->withQueryParameters(['key' => $this->key()])
            ->timeout($timeout !== null ? (int) $timeout : (int) config('geolocation.timeout', 5))
            ->retry(
                (int) config('geolocation.services.google.retry'),
                (int) config('geolocation.services.google.retry_delay'),
                throw: false,
            )
            ->acceptJson();
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    private function component(array $components, string $type, string $key): ?string
    {
        foreach ($components as $component) {
            /** @var list<string> $types */
            $types = $component['types'] ?? [];

            if (in_array($type, $types, true)) {
                return isset($component[$key]) ? (string) $component[$key] : null;
            }
        }

        return null;
    }
}
