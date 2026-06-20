<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\GeolocationProvider;

final class IpInfoProvider implements GeolocationProvider
{
    public function locate(GeolocationQuery $query): ?Location
    {
        if (is_null($query->ipAddress)) {
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

        return new Location(
            humanReadable: (string) $response->json('city'),
            street: '',
            city: (string) $response->json('city'),
            countryIsoCode: (string) $response->json('country'),
            latitude: (float) $latitude,
            longitude: (float) $longitude,
            type: GeolocationType::Ip,
        );
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('geolocation.services.ipinfo.token'))
            ->baseUrl(rtrim((string) config('geolocation.services.ipinfo.url'), '/'))
            ->retry(
                (int) config('geolocation.services.ipinfo.retry'),
                (int) config('geolocation.services.ipinfo.retry_delay'),
            )
            ->acceptJson();
    }
}
