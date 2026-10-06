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
use RoundlyConsulting\Geolocation\Support\GeolocationConfig;
use RoundlyConsulting\Geolocation\Support\RetryPolicy;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Resolves geolocation via Google's Geocoding API and travel distance via the Routes API's
 * computeRouteMatrix, using Laravel's HTTP client (no third-party SDK).
 */
final class GoogleProvider implements DistanceProvider, GeolocationProvider
{
    use HasProviderOverrides;
    use InteractsWithRateLimits;

    /**
     * The Routes API's response field mask: it returns no field unless asked for one.
     * Every field here is in the Compute Route Matrix Essentials SKU.
     */
    private const string ROUTE_MATRIX_FIELDS = 'originIndex,destinationIndex,status,condition,distanceMeters,duration,localizedValues';

    /**
     * The Routes API's cap on origins × destinations in one computeRouteMatrix request (for a
     * traffic-unaware, non-transit matrix — the only kind this provider sends).
     */
    private const int ROUTE_MATRIX_MAX_ELEMENTS = 625;

    public function distance(DistanceQuery $query): ?Distance
    {
        return $this->routeMatrix([$query->from()], [$query->to()], $query->type)[0][0] ?? null;
    }

    /**
     * Resolve a full distance grid between several origins and destinations through the
     * Routes API's computeRouteMatrix, degrading to null cells when the API is unreachable or
     * rejects the request, or when a leg has no route. A grid over the API's element cap is
     * split into tiles that each fit, sent one by one through the rate limiter, and merged back
     * by original index; a tile that fails leaves only its own cells null.
     *
     * @param  list<Coordinates>  $origins
     * @param  list<Coordinates>  $destinations
     */
    public function distanceMatrix(
        array $origins,
        array $destinations,
        DistanceType $type = DistanceType::Driving,
    ): DistanceMatrix {
        if ($origins === [] || $destinations === []) {
            return new DistanceMatrix($origins, $destinations, []);
        }

        [$originsPerTile, $destinationsPerTile] = $this->tileSize(count($origins), count($destinations));
        $cells = [];

        foreach (array_chunk($origins, $originsPerTile, true) as $originTile) {
            foreach (array_chunk($destinations, $destinationsPerTile, true) as $destinationTile) {
                try {
                    $tile = $this->routeMatrix(array_values($originTile), array_values($destinationTile), $type);
                } catch (ProviderUnavailableException) {
                    // An unreachable or refusing API degrades the tile to null cells rather
                    // than aborting the whole grid.
                    continue;
                }

                $originKeys = array_keys($originTile);
                $destinationKeys = array_keys($destinationTile);

                foreach ($tile as $originIndex => $row) {
                    foreach ($row as $destinationIndex => $distance) {
                        // Google numbers the elements within the request; an index outside the
                        // tile is junk and must not land in a neighbouring tile's cell.
                        if (isset($originKeys[$originIndex], $destinationKeys[$destinationIndex])) {
                            $cells[$originKeys[$originIndex]][$destinationKeys[$destinationIndex]] = $distance;
                        }
                    }
                }
            }
        }

        $rows = [];

        foreach (array_keys($origins) as $originIndex) {
            foreach (array_keys($destinations) as $destinationIndex) {
                $rows[$originIndex][$destinationIndex] = $cells[$originIndex][$destinationIndex] ?? null;
            }
        }

        return new DistanceMatrix($origins, $destinations, $rows);
    }

    /**
     * The tile (origins × destinations per request) that covers the grid in the fewest
     * requests while staying within the element cap.
     *
     * @return array{int<1, max>, int<1, max>}
     */
    private function tileSize(int $origins, int $destinations): array
    {
        $best = [1, 1];
        $fewest = PHP_INT_MAX;

        for ($rows = 1; $rows <= min($origins, self::ROUTE_MATRIX_MAX_ELEMENTS); $rows++) {
            $columns = max(1, min($destinations, intdiv(self::ROUTE_MATRIX_MAX_ELEMENTS, $rows)));
            $requests = (int) (ceil($origins / $rows) * ceil($destinations / $columns));

            if ($requests < $fewest) {
                [$best, $fewest] = [[$rows, $columns], $requests];
            }
        }

        return $best;
    }

    /**
     * One computeRouteMatrix call, keyed [originIndex][destinationIndex] as Google numbers
     * them (the elements arrive in no particular order). A cell without a route is null.
     *
     * @param  list<Coordinates>  $origins
     * @param  list<Coordinates>  $destinations
     * @return array<int, array<int, Distance|null>>
     *
     * @throws ProviderUnavailableException when the API is unreachable or rejects the request
     */
    private function routeMatrix(array $origins, array $destinations, DistanceType $type): array
    {
        try {
            $response = $this->throttled('google', fn (): Response => $this->routesClient()->post(
                '/distanceMatrix/v2:computeRouteMatrix',
                $this->routeMatrixBody($origins, $destinations, $type),
            ));
        } catch (ConnectionException $e) {
            throw $this->unavailable($e);
        }

        if ($response->failed() || is_array($response->json('error')) || is_array($response->json('0.error'))) {
            throw $this->rejected($response);
        }

        $cells = [];

        foreach ((array) $response->json() as $element) {
            if (! is_array($element)) {
                continue;
            }

            // Proto3 JSON may leave a zero index out.
            $originIndex = $element['originIndex'] ?? 0;
            $destinationIndex = $element['destinationIndex'] ?? 0;

            if (is_int($originIndex) && is_int($destinationIndex)) {
                $cells[$originIndex][$destinationIndex] = $this->elementToDistance($element, $type);
            }
        }

        return $cells;
    }

    /**
     * @param  list<Coordinates>  $origins
     * @param  list<Coordinates>  $destinations
     * @return array<string, mixed>
     */
    private function routeMatrixBody(array $origins, array $destinations, DistanceType $type): array
    {
        $waypoint = static fn (Coordinates $point): array => ['waypoint' => ['location' => ['latLng' => [
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
        ]]]];

        $body = [
            'origins' => array_map($waypoint, $origins),
            'destinations' => array_map($waypoint, $destinations),
            'travelMode' => $type === DistanceType::Driving ? 'DRIVE' : 'WALK',
        ];

        // Traffic-unaware, like the Distance Matrix default it replaces: it keeps the request
        // in the Essentials SKU. A routing preference is only valid for DRIVE.
        if ($type === DistanceType::Driving) {
            $body['routingPreference'] = 'TRAFFIC_UNAWARE';
        }

        return $body;
    }

    /**
     * @param  array<mixed>  $element
     */
    private function elementToDistance(array $element, DistanceType $type): ?Distance
    {
        $status = $element['status'] ?? [];

        if ((is_array($status) && ($status['code'] ?? 0) !== 0) || ($element['condition'] ?? null) !== 'ROUTE_EXISTS') {
            return null;
        }

        $seconds = $this->seconds($element['duration'] ?? '0s');

        if ($seconds === null) {
            return null;
        }

        $meters = is_numeric($element['distanceMeters'] ?? null) ? (int) $element['distanceMeters'] : 0;
        $localized = is_array($element['localizedValues'] ?? null) ? $element['localizedValues'] : [];

        return new Distance(
            humanReadableDistance: $this->localizedText($localized, 'distance') ?? "{$meters} m",
            distanceInMeters: $meters,
            humanReadableDuration: $this->localizedText($localized, 'duration') ?? "{$seconds} s",
            durationInSeconds: $seconds,
            type: $type,
        );
    }

    /**
     * A protobuf Duration as JSON — `"160s"`, `"160.5s"` — in whole seconds.
     */
    private function seconds(mixed $duration): ?int
    {
        if (! is_string($duration) || preg_match('/^(\d+(?:\.\d+)?)s$/', $duration, $matches) !== 1) {
            return null;
        }

        return (int) round((float) $matches[1]);
    }

    /**
     * @param  array<mixed>  $localized
     */
    private function localizedText(array $localized, string $field): ?string
    {
        $text = is_array($localized[$field] ?? null) ? ($localized[$field]['text'] ?? null) : null;

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * The Routes API reports a rejected request (key without the Routes API enabled, quota,
     * invalid argument) as an HTTP error with a google.rpc.Status body — or, once the stream
     * has started, as an error element inside a 200 array. Either way the provider is unusable
     * for this call: say why, with the key scrubbed.
     */
    private function rejected(Response $response): ProviderUnavailableException
    {
        $error = $response->json('error') ?? $response->json('0.error');
        $error = is_array($error) ? $error : [];

        $status = is_string($error['status'] ?? null) && $error['status'] !== ''
            ? $error['status']
            : "HTTP {$response->status()}";
        $message = is_string($error['message'] ?? null) && $error['message'] !== '' ? $error['message'] : null;

        return ProviderUnavailableException::rejected(
            'google',
            $message !== null ? "{$status}: {$message}" : $status,
            [$this->key()],
        );
    }

    public function locate(GeolocationQuery $query): ?Location
    {
        $parameters = $this->geocodeParameters($query);

        if ($parameters === null) {
            return null;
        }

        try {
            $response = $this->throttled('google', fn (): Response => $this->geocodingClient()->get('/geocode/json', $parameters));
        } catch (ConnectionException $e) {
            throw $this->unavailable($e);
        }

        if ($response->failed()) {
            return null;
        }

        $this->ensureGeocodingAccepted($response);

        /** @var array<string, mixed>|null $result */
        $result = $response->json('results.0');

        if (! is_array($result)) {
            return null;
        }

        return $this->toLocation($result);
    }

    /**
     * The Geocoding API reports a rejected request (denied or restricted key, exhausted quota,
     * malformed query) as HTTP 200 with a top-level status and no results — which would
     * otherwise read as "no match". Surface it as an unavailable provider instead, so the
     * pipeline moves on and LocationResolutionFailed names the reason.
     */
    private function ensureGeocodingAccepted(Response $response): void
    {
        $status = $response->json('status');

        if (! is_string($status) || in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
            return;
        }

        $message = $response->json('error_message');

        throw ProviderUnavailableException::rejected(
            'google',
            is_string($message) && $message !== '' ? "{$status}: {$message}" : $status,
            [$this->key()],
        );
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
     * The geocoding key rides in the query string, so a transport error's message (which ends
     * in the request URL) carries it — redact before it leaves the provider.
     */
    private function unavailable(ConnectionException $e): ProviderUnavailableException
    {
        return ProviderUnavailableException::for('google', $e, [$this->key()]);
    }

    /**
     * The Geocoding API client: the key travels as the `key` query parameter.
     */
    private function geocodingClient(): PendingRequest
    {
        return $this->client(GeolocationConfig::string('geolocation.services.google.url', config('geolocation.services.google.url'), 'https://maps.googleapis.com/maps/api'))
            ->withQueryParameters(['key' => $this->key()]);
    }

    /**
     * The Routes API client: the key travels in the `X-Goog-Api-Key` header, and the field
     * mask is mandatory (the API returns an error without one).
     */
    private function routesClient(): PendingRequest
    {
        $client = $this->client(GeolocationConfig::string('geolocation.services.google.routes_url', config('geolocation.services.google.routes_url'), 'https://routes.googleapis.com'))
            ->withHeaders(['X-Goog-FieldMask' => self::ROUTE_MATRIX_FIELDS]);

        $key = $this->key();

        return $key !== null ? $client->withHeaders(['X-Goog-Api-Key' => $key]) : $client;
    }

    private function client(string $baseUrl): PendingRequest
    {
        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->timeout(GeolocationConfig::timeout($this->override('timeout')))
            ->retry(
                Config::integer('geolocation.services.google.retry', 3, min: 0),
                Config::integer('geolocation.services.google.retry_delay', 100, min: 0),
                when: RetryPolicy::transient(...),
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
