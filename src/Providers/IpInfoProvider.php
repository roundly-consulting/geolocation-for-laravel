<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\Concerns\HasProviderOverrides;
use RoundlyConsulting\Geolocation\Concerns\InteractsWithRateLimits;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Exceptions\ProviderUnavailableException;
use RoundlyConsulting\Geolocation\GeolocationProvider;
use RoundlyConsulting\Geolocation\Support\GeolocationConfig;
use RoundlyConsulting\Geolocation\Support\RetryPolicy;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class IpInfoProvider implements GeolocationProvider
{
    use HasProviderOverrides;
    use InteractsWithRateLimits;

    public function locate(GeolocationQuery $query): ?Location
    {
        if ($query->ipAddress === null || filter_var($query->ipAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        try {
            $response = $this->throttled('ipinfo', fn (): Response => $this->client()->get("/{$query->ipAddress}/json"));
        } catch (ConnectionException $e) {
            throw ProviderUnavailableException::for('ipinfo', $e, [$this->token()]);
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
        $client = Http::baseUrl(rtrim(GeolocationConfig::string('geolocation.services.ipinfo.url', config('geolocation.services.ipinfo.url'), 'https://ipinfo.io/'), '/'))
            ->timeout(GeolocationConfig::timeout($this->override('timeout')))
            ->retry(
                Config::integer('geolocation.services.ipinfo.retry', 3, min: 0),
                Config::integer('geolocation.services.ipinfo.retry_delay', 100, min: 0),
                when: RetryPolicy::transient(...),
                throw: false,
            )
            ->acceptJson();

        $token = $this->token();

        // IPinfo's anonymous tier works without a token; only attach one when present so
        // we never send an empty bearer header.
        if ($token !== null) {
            $client = $client->withToken($token);
        }

        return $client;
    }

    private function token(): ?string
    {
        $override = $this->override('token');
        $token = is_string($override) && $override !== '' ? $override : config('geolocation.services.ipinfo.token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
