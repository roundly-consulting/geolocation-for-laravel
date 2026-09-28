<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Providers;

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\GeolocationProvider;

/**
 * The last-resort fallback: answers every query with the static location configured under
 * `geolocation.default` (a `GeolocationType::Default` location, never cached) — but only once
 * one is configured. The shipped empty values are not an answer: a country-less "location"
 * would be indistinguishable from a real lookup, so while nothing is configured this
 * provider answers null and an unresolved lookup stays null.
 */
final class DefaultLocationProvider implements GeolocationProvider
{
    public function locate(GeolocationQuery $query): ?Location
    {
        /** @var array{humanReadable: string, street: string, city: string, country: string, latitude: float|int|string, longitude: float|int|string} $config */
        $config = config('geolocation.default');

        $location = Location::createFromDefaults($config);

        return $this->isConfigured($location) ? $location : null;
    }

    private function isConfigured(Location $location): bool
    {
        return trim($location->humanReadable.$location->street.$location->city.$location->countryIsoCode) !== ''
            || $location->latitude !== 0.0
            || $location->longitude !== 0.0;
    }
}
