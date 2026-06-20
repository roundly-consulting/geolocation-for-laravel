<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Geolocation;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeAlternativeDistanceProvider;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeAlternativeGeolocationProvider;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeDistanceProvider;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeGeolocationProvider;

it('returns null for geolocation when no provider is defined', function () {
    $geolocation = new Geolocation;

    config()->set('geolocation.providers', []);

    expect($geolocation->locate(new GeolocationQuery('127.0.0.1')))->toBeNull();
});

it('returns null for calculating distance when no provider is defined', function () {
    $geolocation = new Geolocation;

    config()->set('geolocation.providers', []);

    expect($geolocation->distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving)))->toBeNull();
});

it('returns first geolocation provided by any provider', function () {
    $geolocation = new Geolocation;

    config()->set('geolocation.providers', [
        FakeGeolocationProvider::class,
        FakeAlternativeGeolocationProvider::class,
    ]);

    expect($geolocation->locate(new GeolocationQuery('127.0.0.1')))
        ->toBeInstanceOf(Location::class)
        ->humanReadable->toBe('Somewhere, Smallville')
        ->street->toBe('Somewhere')
        ->city->toBe('Smallville')
        ->countryIsoCode->toBe('SM')
        ->latitude->toBe(123.456)
        ->longitude->toBe(789.1011)
        ->type->toBe(GeolocationType::Default);

    config()->set('geolocation.providers', [
        FakeAlternativeGeolocationProvider::class,
        FakeGeolocationProvider::class,
    ]);

    expect($geolocation->locate(new GeolocationQuery('127.0.0.1')))
        ->toBeInstanceOf(Location::class)
        ->humanReadable->toBe('Alternative Human Readable')
        ->street->toBe('Somewhere')
        ->city->toBe('Smallville')
        ->countryIsoCode->toBe('SM')
        ->latitude->toBe(123.456)
        ->longitude->toBe(789.1011)
        ->type->toBe(GeolocationType::Default);
});

it('returns distance provided by any provider', function () {
    $geolocation = new Geolocation;

    config()->set('geolocation.providers', [
        FakeDistanceProvider::class,
        FakeAlternativeDistanceProvider::class,
    ]);

    expect($geolocation->distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving)))
        ->toBeInstanceOf(Distance::class)
        ->humanReadableDistance->toBe('10km')
        ->distanceInMeters->toBe(10000)
        ->humanReadableDuration->toBe('10min')
        ->durationInSeconds->toBe(600)
        ->type->toBe(DistanceType::Walking);

    config()->set('geolocation.providers', [
        FakeAlternativeDistanceProvider::class,
        FakeDistanceProvider::class,
    ]);

    expect($geolocation->distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving)))
        ->toBeInstanceOf(Distance::class)
        ->humanReadableDistance->toBe('15km')
        ->distanceInMeters->toBe(15000)
        ->humanReadableDuration->toBe('1min')
        ->durationInSeconds->toBe(60)
        ->type->toBe(DistanceType::Driving);
});
