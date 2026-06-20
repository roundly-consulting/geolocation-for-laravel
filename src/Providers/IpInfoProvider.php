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

final class IpInfoProvider implements GeolocationProvider
{
    use HasProviderOverrides;

    public function locate(GeolocationQuery $query): ?Location
    {
        if ($query->ipAddress === null || filter_var($query->ipAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        try {
            $response = $this->client()->get("/{$query->ipAddress}/json");
        } catch (RequestException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $loc = (string) $response->json('loc');

        if (! str_contains($loc, ',')) {
            return null;
        }

        [$latitude, $longitude] = explode(',', $loc);

        $city = (string) $response->json('city');
        $region = (string) $response->json('region');
        $country = (string) $response->json('country');

        return new Location(
            humanReadable: $this->humanReadable($city, $region, $country),
            street: '',
            city: $city,
            countryIsoCode: $country,
            latitude: (float) $latitude,
            longitude: (float) $longitude,
            type: GeolocationType::Ip,
            region: $region,
            postalCode: (string) $response->json('postal'),
            timezone: (string) $response->json('timezone'),
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

        $client = Http::baseUrl(rtrim((string) config('geolocation.services.ipinfo.url'), '/'))
            ->timeout($timeout !== null ? (int) $timeout : (int) config('geolocation.timeout', 5))
            ->retry(
                (int) config('geolocation.services.ipinfo.retry'),
                (int) config('geolocation.services.ipinfo.retry_delay'),
            )
            ->acceptJson();

        $override = $this->override('token');
        /** @var string|null $token */
        $token = is_string($override) && $override !== '' ? $override : config('geolocation.services.ipinfo.token');

        // IPinfo's anonymous tier works without a token; only attach one when present so
        // we never send an empty bearer header.
        if ($token !== null && $token !== '') {
            $client = $client->withToken($token);
        }

        return $client;
    }
}
