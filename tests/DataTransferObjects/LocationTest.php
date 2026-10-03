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
        'latitude' => 48.1486,
        'longitude' => 17.1077,
    ]);

    expect($location)
        ->humanReadable->toBe('Somewhere, Smallville')
        ->street->toBe('Somewhere')
        ->city->toBe('Smallville')
        ->countryIsoCode->toBe('SM')
        ->latitude->toBe(48.1486)
        ->longitude->toBe(17.1077)
        ->type->toBe(GeolocationType::Default);
});

it('exposes its coordinates as a value object', function () {
    $coordinates = (new Location('x', '', 'c', 'CC', 12.34, 56.78, GeolocationType::Ip))->coordinates();

    expect($coordinates->latitude)->toBe(12.34)->and($coordinates->longitude)->toBe(56.78);
});

it('serializes to an array and json with the new fields', function () {
    $location = new Location(
        humanReadable: 'Somewhere, Pretty',
        street: 'Somewhere',
        city: 'Pretty',
        countryIsoCode: 'SM',
        latitude: 12.34,
        longitude: 56.78,
        type: GeolocationType::Ip,
        region: 'Region',
        postalCode: '99999',
        timezone: 'Europe/Bratislava',
    );

    expect($location->toArray())->toBe([
        'humanReadable' => 'Somewhere, Pretty',
        'street' => 'Somewhere',
        'city' => 'Pretty',
        'region' => 'Region',
        'postalCode' => '99999',
        'countryIsoCode' => 'SM',
        'latitude' => 12.34,
        'longitude' => 56.78,
        'timezone' => 'Europe/Bratislava',
        'type' => 'IP',
    ])->and($location->jsonSerialize())->toBe($location->toArray())
        ->and(json_decode((string) json_encode($location), true)['region'])->toBe('Region');
});

it('is empty only when it places nothing', function (Location $location, bool $empty): void {
    expect($location->isEmpty())->toBe($empty);
})->with([
    'nothing at all' => [new Location('', '', '', '', 0.0, 0.0, GeolocationType::Ip, timezone: 'UTC'), true],
    'whitespace only' => [new Location(' ', '', '', '', 0.0, 0.0, GeolocationType::Ip), true],
    'a country' => [new Location('', '', '', 'SK', 0.0, 0.0, GeolocationType::Ip), false],
    'a region' => [new Location('', '', '', '', 0.0, 0.0, GeolocationType::Ip, region: 'Bratislava'), false],
    'a postal code' => [new Location('', '', '', '', 0.0, 0.0, GeolocationType::Ip, postalCode: '811 01'), false],
    'coordinates' => [new Location('', '', '', '', 0.0, 17.1, GeolocationType::Ip), false],
]);
