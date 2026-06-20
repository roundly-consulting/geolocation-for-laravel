<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Providers\DefaultLocationProvider;

it('returns default geolocation from config', function () {
    config()->set('geolocation.default', [
        'humanReadable' => 'Somewhere, Smallville',
        'street' => 'Somewhere',
        'city' => 'Smallville',
        'country' => 'SM',
        'latitude' => 123.456,
        'longitude' => 789.1011,
    ]);

    $provider = new DefaultLocationProvider;

    expect($provider->locate(new GeolocationQuery))
        ->humanReadable->toBe('Somewhere, Smallville')
        ->street->toBe('Somewhere')
        ->city->toBe('Smallville')
        ->countryIsoCode->toBe('SM')
        ->latitude->toBe(123.456)
        ->longitude->toBe(789.1011)
        ->type->toBe(GeolocationType::Default);
});
