<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\DistanceProvider;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\GeolocationProvider;

/**
 * Resolves geolocation via Google's Geocoding API and travel distance via the
 * Distance Matrix API, using Laravel's HTTP client (no third-party SDK).
 */
final class GoogleProvider implements DistanceProvider, GeolocationProvider
{
    public function distance(DistanceQuery $query): ?Distance
    {
        try {
            $response = $this->client()->get('/distancematrix/json', [
                'origins' => "{$query->fromLatitude},{$query->fromLongitude}",
                'destinations' => "{$query->toLatitude},{$query->toLongitude}",
                'mode' => $query->type === DistanceType::Driving ? 'driving' : 'walking',
            ]);
        } catch (RequestException) {
            return null;
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

    public function locate(GeolocationQuery $query): ?Location
    {
        $parameters = $this->geocodeParameters($query);

        if ($parameters === null) {
            return null;
        }

        try {
            $response = $this->client()->get('/geocode/json', $parameters);
        } catch (RequestException) {
            return null;
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
            return ['latlng' => "{$query->latitude},{$query->longitude}"];
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

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('geolocation.services.google.url'), '/'))
            ->withQueryParameters(['key' => config('geolocation.services.google.key')])
            ->timeout((int) config('geolocation.timeout', 5))
            ->retry(
                (int) config('geolocation.services.google.retry'),
                (int) config('geolocation.services.google.retry_delay'),
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
