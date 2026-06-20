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

    Http::assertNothingSent();
});

it('returns null and sends nothing for a malformed ip address', function () {
    $provider = new IpInfoProvider;

    expect($provider->locate(new GeolocationQuery('not-an-ip')))->toBeNull();

    Http::assertNothingSent();
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
        'ipinfo.io/127.0.0.1/json' => Http::response(status: 429),
    ]);

    $provider = new IpInfoProvider;

    expect($provider->locate(new GeolocationQuery('127.0.0.1')))->toBeNull();
});

it('returns null when the loc field is missing', function () {
    Http::fake([
        'ipinfo.io/127.0.0.1/json' => Http::response(['city' => 'Nowhere']),
    ]);

    expect((new IpInfoProvider)->locate(new GeolocationQuery('127.0.0.1')))->toBeNull();
});

it('omits the authorization header when no token is configured', function () {
    config()->set('geolocation.services.ipinfo.token', null);

    Http::fake([
        'ipinfo.io/8.8.8.8/json' => Http::response([
            'city' => 'Mountain View',
            'country' => 'US',
            'loc' => '37.4,-122.07',
        ]),
    ]);

    (new IpInfoProvider)->locate(new GeolocationQuery('8.8.8.8'));

    Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
});

it('attaches a bearer token when one is configured', function () {
    config()->set('geolocation.services.ipinfo.token', 'secret-token');

    Http::fake([
        'ipinfo.io/8.8.8.8/json' => Http::response([
            'city' => 'Mountain View',
            'country' => 'US',
            'loc' => '37.4,-122.07',
        ]),
    ]);

    (new IpInfoProvider)->locate(new GeolocationQuery('8.8.8.8'));

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('returns a richly populated location by ip address', function () {
    Http::fake([
        'ipinfo.io/127.0.0.1/json' => Http::response([
            'city' => 'Somewhere',
            'region' => 'Some Region',
            'country' => 'SK',
            'postal' => '12345',
            'timezone' => 'Europe/Bratislava',
            'loc' => '123.45,67.91',
        ]),
    ]);

    expect((new IpInfoProvider)->locate(new GeolocationQuery('127.0.0.1')))
        ->toBeInstanceOf(Location::class)
        ->humanReadable->toBe('Somewhere, Some Region SK')
        ->city->toBe('Somewhere')
        ->region->toBe('Some Region')
        ->postalCode->toBe('12345')
        ->timezone->toBe('Europe/Bratislava')
        ->countryIsoCode->toBe('SK')
        ->latitude->toBe(123.45)
        ->longitude->toBe(67.91)
        ->type->toBe(GeolocationType::Ip);
});
