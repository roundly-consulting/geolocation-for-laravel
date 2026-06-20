<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Tests\FakeProviders;

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\GeolocationProvider;

class FakeGeolocationProvider implements GeolocationProvider
{
    public function locate(GeolocationQuery $query): ?Location
    {
        return Location::createFromDefaults([
            'humanReadable' => 'Somewhere, Smallville',
            'street' => 'Somewhere',
            'city' => 'Smallville',
            'country' => 'SM',
            'latitude' => 123.456,
            'longitude' => 789.1011,
        ]);
    }
}
