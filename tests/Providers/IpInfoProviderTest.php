<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Providers\IpInfoProvider;

it('returns null when no ip address was defined in geolocation query', function () {
    $provider = new IpInfoProvider;

    expect($provider->locate(new GeolocationQuery))->toBeNull();
});

it('returns null request to ipinfo fails', function () {
    Http::fake([
        'ipinfo.io/127.0.0.1/json' => Http::response(status: 500),
    ]);

    $provider = new IpInfoProvider;

    expect($provider->locate(new GeolocationQuery('127.0.0.1')))->toBeNull();
});

it('returns null request to ipinfo returns non successfull status code', function () {
    Http::fake([
        'ipinfo.io/127.0.0.1/json' => Http::response(status: 301),
    ]);

    $provider = new IpInfoProvider;

    expect($provider->locate(new GeolocationQuery('127.0.0.1')))->toBeNull();
});

it('returns location by ip address', function () {
    Http::fake([
        'ipinfo.io/127.0.0.1/json' => Http::response([
            'city' => 'Somewhere',
            'country' => 'SK',
            'loc' => '123.45,678.91',
        ]),
    ]);

    $provider = new IpInfoProvider;

    expect($provider->locate(new GeolocationQuery('127.0.0.1')))
        ->toBeInstanceOf(Location::class)
        ->humanReadable->toBe('Somewhere')
        ->city->toBe('Somewhere')
        ->countryIsoCode->toBe('SK')
        ->latitude->toBe(123.45)
        ->longitude->toBe(678.91)
        ->type->toBe(GeolocationType::Ip);
});
