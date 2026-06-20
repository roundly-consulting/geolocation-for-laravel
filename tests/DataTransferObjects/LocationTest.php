<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;

it('holds values', function () {
    $location = new Location(
        'Somewhere, Pretty',
        'Somewhere',
        'Pretty',
        'SM',
        12.34,
        56.78,
        GeolocationType::Ip,
    );

    expect($location)
        ->humanReadable->toBe('Somewhere, Pretty')
        ->street->toBe('Somewhere')
        ->city->toBe('Pretty')
        ->countryIsoCode->toBe('SM')
        ->latitude->toBe(12.34)
        ->longitude->toBe(56.78)
        ->type->toBe(GeolocationType::Ip);
});

it('creates instance from defaults', function () {
    $location = Location::createFromDefaults([
        'humanReadable' => 'Somewhere, Smallville',
        'street' => 'Somewhere',
        'city' => 'Smallville',
        'country' => 'SM',
        'latitude' => 123.456,
        'longitude' => 789.1011,
    ]);

    expect($location)
        ->humanReadable->toBe('Somewhere, Smallville')
        ->street->toBe('Somewhere')
        ->city->toBe('Smallville')
        ->countryIsoCode->toBe('SM')
        ->latitude->toBe(123.456)
        ->longitude->toBe(789.1011)
        ->type->toBe(GeolocationType::Default);
});
