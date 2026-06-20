<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Providers;

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\GeolocationProvider;
use RoundlyConsulting\Geolocation\MaxMind\Reader;

/**
 * Resolves a location from a local MaxMind .mmdb file using a native binary reader
 * (no geoip2/maxmind-db runtime dependency).
 */
final class MaxMindDatabaseProvider implements GeolocationProvider
{
    private ?Reader $reader = null;

    public function locate(GeolocationQuery $query): ?Location
    {
        if (! (bool) config('geolocation.services.maxmind.database.enabled', false)) {
            return null;
        }

        if ($query->ipAddress === null || filter_var($query->ipAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $record = $this->reader()->get($query->ipAddress);

        if ($record === null) {
            return null;
        }

        return $this->toLocation($record);
    }

    private function reader(): Reader
    {
        if ($this->reader instanceof Reader) {
            return $this->reader;
        }

        /** @var string|null $path */
        $path = config('geolocation.services.maxmind.database.path');

        // Throws DatabaseNotFoundException when the path is missing/unreadable.
        return $this->reader = new Reader((string) $path);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function toLocation(array $record): Location
    {
        $location = $this->section($record, 'location');
        $city = $this->section($record, 'city');
        $country = $this->section($record, 'country');
        $postal = $this->section($record, 'postal');
        $subdivisions = $record['subdivisions'] ?? [];
        $firstSubdivision = is_array($subdivisions) ? ($subdivisions[0] ?? []) : [];

        $cityName = $this->englishName($city);
        $region = is_array($firstSubdivision) ? $this->englishName($firstSubdivision) : '';
        $countryIso = isset($country['iso_code']) ? (string) $country['iso_code'] : '';

        return new Location(
            humanReadable: trim(implode(', ', array_filter([$cityName, $region, $countryIso]))),
            street: '',
            city: $cityName,
            countryIsoCode: $countryIso,
            latitude: isset($location['latitude']) ? (float) $location['latitude'] : 0.0,
            longitude: isset($location['longitude']) ? (float) $location['longitude'] : 0.0,
            type: GeolocationType::Ip,
            region: $region,
            postalCode: isset($postal['code']) ? (string) $postal['code'] : '',
            timezone: isset($location['time_zone']) ? (string) $location['time_zone'] : '',
        );
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function section(array $record, string $key): array
    {
        $value = $record[$key] ?? [];

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
