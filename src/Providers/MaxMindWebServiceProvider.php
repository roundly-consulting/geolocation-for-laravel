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

/**
 * Resolves a location through the MaxMind GeoIP2 Precision web service using HTTP Basic
 * auth (account ID + license key) over Laravel's HTTP client. A per-call `token` override
 * (`withToken('maxmind_web', …)`) replaces the license key; the account ID stays the
 * configured one.
 */
final class MaxMindWebServiceProvider implements GeolocationProvider
{
    use HasProviderOverrides;
    use InteractsWithRateLimits;

    public function locate(GeolocationQuery $query): ?Location
    {
        if (! (bool) config('geolocation.services.maxmind_web.enabled', false)) {
            return null;
        }

        if ($query->ipAddress === null || filter_var($query->ipAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $service = $this->service();

        try {
            $response = $this->throttled('maxmind_web', fn (): Response => $this->client()->get("/{$service}/{$query->ipAddress}"));
        } catch (ConnectionException $e) {
            throw ProviderUnavailableException::for('maxmind_web', $e, [$this->licenseKey()]);
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        return $this->toLocation($body);
    }

    private function service(): string
    {
        /** @var string $service */
        $service = config('geolocation.services.maxmind_web.service', 'city');

        return in_array($service, ['city', 'country', 'insights'], true) ? $service : 'city';
    }

    private function licenseKey(): string
    {
        $override = $this->override('token');

        return is_string($override) && $override !== ''
            ? $override
            : (string) config('geolocation.services.maxmind_web.license_key');
    }

    private function client(): PendingRequest
    {
        $timeout = $this->override('timeout');

        return Http::baseUrl(rtrim((string) config('geolocation.services.maxmind_web.base_url'), '/'))
            ->withBasicAuth((string) config('geolocation.services.maxmind_web.account_id'), $this->licenseKey())
            ->timeout($timeout !== null ? (int) $timeout : (int) config('geolocation.timeout', 5))
            ->retry(
                (int) config('geolocation.services.maxmind_web.retry', 2),
                (int) config('geolocation.services.maxmind_web.retry_delay', 100),
                throw: false,
            )
            ->acceptJson();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function toLocation(array $body): Location
    {
        $location = $this->section($body, 'location');
        $cityName = $this->englishName($this->section($body, 'city'));
        $countryIso = $this->section($body, 'country')['iso_code'] ?? '';
        $postal = $this->section($body, 'postal')['code'] ?? '';

        $subdivisions = $body['subdivisions'] ?? [];
        $firstSubdivision = is_array($subdivisions) && isset($subdivisions[0]) && is_array($subdivisions[0])
            ? $subdivisions[0]
            : [];
        $region = $this->englishName($firstSubdivision);

        return new Location(
            humanReadable: trim(implode(', ', array_filter([$cityName, $region, (string) $countryIso]))),
            street: '',
            city: $cityName,
            countryIsoCode: (string) $countryIso,
            latitude: isset($location['latitude']) ? (float) $location['latitude'] : 0.0,
            longitude: isset($location['longitude']) ? (float) $location['longitude'] : 0.0,
            type: GeolocationType::Ip,
            region: $region,
            postalCode: (string) $postal,
            timezone: isset($location['time_zone']) ? (string) $location['time_zone'] : '',
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function section(array $body, string $key): array
    {
        $value = $body[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function englishName(array $section): string
    {
        $names = $section['names'] ?? [];

        if (is_array($names) && isset($names['en'])) {
            return (string) $names['en'];
        }

        return '';
    }
}
