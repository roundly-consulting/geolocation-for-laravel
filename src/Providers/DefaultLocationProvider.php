<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Providers;

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\GeolocationProvider;

final class DefaultLocationProvider implements GeolocationProvider
{
    public function locate(GeolocationQuery $query): Location
    {
        /** @var array{humanReadable: string, street: string, city: string, country: string, latitude: float|int|string, longitude: float|int|string} $config */
        $config = config('geolocation.default');

        return Location::createFromDefaults($config);
    }
}
