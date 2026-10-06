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

/**
 * Resolves a location from an IP address through the IP2Location.io HTTP API using
 * Laravel's HTTP client (no third-party SDK).
 */
final class IP2LocationProvider implements GeolocationProvider
{
    use HasProviderOverrides;
    use InteractsWithRateLimits;

    public function locate(GeolocationQuery $query): ?Location
    {
        if ($query->ipAddress === null || filter_var($query->ipAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        try {
            $response = $this->throttled('ip2location', fn (): Response => $this->client()->get('/', [
                'key' => $this->key(),
                'ip' => $query->ipAddress,
            ]));
        } catch (ConnectionException $e) {
            // The key rides in the query string, which the transport error message quotes.
            throw ProviderUnavailableException::for('ip2location', $e, [$this->key()]);
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        if (! is_array($body) || isset($body['error'])) {
            return null;
        }

        $location = $this->toLocation($body);

        // A private or reserved IP gets HTTP 200 with every field null: no answer, not a place.
        return $location->isEmpty() ? null : $location;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function toLocation(array $body): Location
    {
        $city = $this->field($body, 'city_name');
        $region = $this->field($body, 'region_name');
        $country = $this->field($body, 'country_code');

        return new Location(
            humanReadable: $this->humanReadable($city, $region, $country),
            street: '',
            city: $city,
            countryIsoCode: $country,
            latitude: is_numeric($body['latitude'] ?? null) ? (float) $body['latitude'] : 0.0,
            longitude: is_numeric($body['longitude'] ?? null) ? (float) $body['longitude'] : 0.0,
            type: GeolocationType::Ip,
            region: $region,
            postalCode: $this->field($body, 'zip_code'),
            timezone: $this->field($body, 'time_zone'),
        );
    }

    /**
     * A text field, with IP2Location's "no data" markers (null, or "-" in its database
     * format) read as empty.
     *
     * @param  array<string, mixed>  $body
     */
    private function field(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return is_scalar($value) && (string) $value !== '-' ? (string) $value : '';
    }

    private function humanReadable(string $city, string $region, string $country): string
    {
        $head = trim(implode(', ', array_filter([$city, $region])));

        return trim(implode(' ', array_filter([$head, $country])));
    }

    private function key(): ?string
    {
        $override = $this->override('token');
        $key = is_string($override) && $override !== '' ? $override : config('geolocation.services.ip2location.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim(GeolocationConfig::string('geolocation.services.ip2location.url', config('geolocation.services.ip2location.url'), 'https://api.ip2location.io'), '/'))
            ->timeout(GeolocationConfig::timeout($this->override('timeout')))
            ->retry(
                Config::integer('geolocation.services.ip2location.retry', 2, min: 0),
                Config::integer('geolocation.services.ip2location.retry_delay', 100, min: 0),
                when: RetryPolicy::transient(...),
                throw: false,
            )
            ->acceptJson();
    }
}
