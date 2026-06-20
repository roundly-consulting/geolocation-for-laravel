<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\Concerns\HasProviderOverrides;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\GeolocationProvider;

/**
 * Resolves a location from an IP address through the IP2Location.io HTTP API using
 * Laravel's HTTP client (no third-party SDK).
 */
final class IP2LocationProvider implements GeolocationProvider
{
    use HasProviderOverrides;

    public function locate(GeolocationQuery $query): ?Location
    {
        if ($query->ipAddress === null || filter_var($query->ipAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        try {
            $response = $this->client()->get('/', [
                'key' => $this->override('token') ?? config('geolocation.services.ip2location.key'),
                'ip' => $query->ipAddress,
            ]);
        } catch (RequestException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        if (! is_array($body) || isset($body['error'])) {
            return null;
        }

        return $this->toLocation($body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function toLocation(array $body): Location
    {
        $city = isset($body['city_name']) ? (string) $body['city_name'] : '';
        $region = isset($body['region_name']) ? (string) $body['region_name'] : '';
        $country = isset($body['country_code']) ? (string) $body['country_code'] : '';

        return new Location(
            humanReadable: $this->humanReadable($city, $region, $country),
            street: '',
            city: $city,
            countryIsoCode: $country,
            latitude: isset($body['latitude']) ? (float) $body['latitude'] : 0.0,
            longitude: isset($body['longitude']) ? (float) $body['longitude'] : 0.0,
            type: GeolocationType::Ip,
            region: $region,
            postalCode: isset($body['zip_code']) ? (string) $body['zip_code'] : '',
            timezone: isset($body['time_zone']) ? (string) $body['time_zone'] : '',
        );
    }

    private function humanReadable(string $city, string $region, string $country): string
    {
        $head = trim(implode(', ', array_filter([$city, $region])));

        return trim(implode(' ', array_filter([$head, $country])));
    }

    private function client(): PendingRequest
    {
        $timeout = $this->override('timeout');

        return Http::baseUrl(rtrim((string) config('geolocation.services.ip2location.url'), '/'))
            ->timeout($timeout !== null ? (int) $timeout : (int) config('geolocation.timeout', 5))
            ->retry(
                (int) config('geolocation.services.ip2location.retry', 2),
                (int) config('geolocation.services.ip2location.retry_delay', 100),
            )
            ->acceptJson();
    }
}
