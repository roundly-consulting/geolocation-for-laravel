<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Providers\IP2LocationProvider;

it('returns null when no ip address is present', function () {
    expect((new IP2LocationProvider)->locate(new GeolocationQuery))->toBeNull();

    Http::assertNothingSent();
});

it('returns null for a malformed ip address', function () {
    expect((new IP2LocationProvider)->locate(new GeolocationQuery('nope')))->toBeNull();

    Http::assertNothingSent();
});

it('returns null when the api responds with an error payload', function () {
    Http::fake([
        'api.ip2location.io*' => Http::response(['error' => ['error_message' => 'Invalid key']]),
    ]);

    expect((new IP2LocationProvider)->locate(new GeolocationQuery('8.8.8.8')))->toBeNull();
});

it('returns null on a failed http response', function () {
    Http::fake([
        'api.ip2location.io*' => Http::response(status: 500),
    ]);

    expect((new IP2LocationProvider)->locate(new GeolocationQuery('8.8.8.8')))->toBeNull();
});

it('resolves a rich location from the ip2location api', function () {
    Http::fake([
        'api.ip2location.io*' => Http::response([
            'country_code' => 'US',
            'region_name' => 'California',
            'city_name' => 'Mountain View',
            'latitude' => 37.4056,
            'longitude' => -122.0775,
            'zip_code' => '94043',
            'time_zone' => '-07:00',
        ]),
    ]);

    expect((new IP2LocationProvider)->locate(new GeolocationQuery('8.8.8.8')))
        ->toBeInstanceOf(Location::class)
        ->city->toBe('Mountain View')
        ->region->toBe('California')
        ->countryIsoCode->toBe('US')
        ->postalCode->toBe('94043')
        ->timezone->toBe('-07:00')
        ->latitude->toBe(37.4056)
        ->longitude->toBe(-122.0775)
        ->type->toBe(GeolocationType::Ip)
        ->humanReadable->toBe('Mountain View, California US');
});

it('sends the configured api key as a query parameter', function () {
    config()->set('geolocation.services.ip2location.key', 'my-key');

    Http::fake([
        'api.ip2location.io*' => Http::response(['country_code' => 'US', 'latitude' => 1, 'longitude' => 2]),
    ]);

    (new IP2LocationProvider)->locate(new GeolocationQuery('8.8.8.8'));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'key=my-key'));
});

it('answers null when the api has no data for the ip', function (array $body): void {
    Http::fake(['api.ip2location.io*' => Http::response($body)]);

    expect((new IP2LocationProvider)->locate(new GeolocationQuery('10.0.0.1')))->toBeNull();
})->with([
    'nulls (the live api, private ip)' => [[
        'ip' => '10.0.0.1', 'country_code' => null, 'region_name' => null, 'city_name' => null,
        'latitude' => null, 'longitude' => null, 'zip_code' => null, 'time_zone' => null,
    ]],
    'dash placeholders' => [[
        'ip' => '10.0.0.1', 'country_code' => '-', 'region_name' => '-', 'city_name' => '-',
        'latitude' => 0, 'longitude' => 0, 'zip_code' => '-', 'time_zone' => '-',
    ]],
]);
