<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Providers\MaxMindWebServiceProvider;

beforeEach(function (): void {
    config()->set('geolocation.services.maxmind_web.enabled', true);
    config()->set('geolocation.services.maxmind_web.base_url', 'https://geoip.maxmind.com/geoip/v2.1');
    config()->set('geolocation.services.maxmind_web.account_id', '123456');
    config()->set('geolocation.services.maxmind_web.license_key', 'license-key');
    config()->set('geolocation.services.maxmind_web.service', 'city');
});

function cityResponse(): array
{
    return [
        'city' => ['names' => ['en' => 'London']],
        'country' => ['iso_code' => 'GB'],
        'postal' => ['code' => 'SW1'],
        'subdivisions' => [['names' => ['en' => 'England']]],
        'location' => ['latitude' => 51.5, 'longitude' => -0.12, 'time_zone' => 'Europe/London'],
    ];
}

it('returns null when disabled', function (): void {
    config()->set('geolocation.services.maxmind_web.enabled', false);

    expect((new MaxMindWebServiceProvider)->locate(new GeolocationQuery('81.2.69.142')))->toBeNull();

    Http::assertNothingSent();
});

it('returns null for a missing or invalid ip', function (): void {
    $provider = new MaxMindWebServiceProvider;

    expect($provider->locate(new GeolocationQuery))->toBeNull()
        ->and($provider->locate(new GeolocationQuery('nope')))->toBeNull();

    Http::assertNothingSent();
});

it('maps a city response and sends basic auth', function (): void {
    Http::fake([
        'geoip.maxmind.com/geoip/v2.1/city/81.2.69.142' => Http::response(cityResponse()),
    ]);

    $location = (new MaxMindWebServiceProvider)->locate(new GeolocationQuery('81.2.69.142'));

    expect($location)->toBeInstanceOf(Location::class)
        ->city->toBe('London')
        ->region->toBe('England')
        ->postalCode->toBe('SW1')
        ->countryIsoCode->toBe('GB')
        ->timezone->toBe('Europe/London')
        ->latitude->toBe(51.5)
        ->longitude->toBe(-0.12)
        ->type->toBe(GeolocationType::Ip);

    Http::assertSent(fn ($request) => $request->hasHeader(
        'Authorization',
        'Basic '.base64_encode('123456:license-key'),
    ));
});

it('uses the configured service path', function (): void {
    config()->set('geolocation.services.maxmind_web.service', 'insights');

    Http::fake([
        'geoip.maxmind.com/geoip/v2.1/insights/81.2.69.142' => Http::response(cityResponse()),
    ]);

    (new MaxMindWebServiceProvider)->locate(new GeolocationQuery('81.2.69.142'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/insights/'));
});

it('falls back to the city service for an unknown service name', function (): void {
    config()->set('geolocation.services.maxmind_web.service', 'bogus');

    Http::fake([
        'geoip.maxmind.com/geoip/v2.1/city/81.2.69.142' => Http::response(cityResponse()),
    ]);

    (new MaxMindWebServiceProvider)->locate(new GeolocationQuery('81.2.69.142'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/city/'));
});

it('returns null on bad credentials', function (): void {
    Http::fake([
        'geoip.maxmind.com/*' => Http::response(status: 401),
    ]);

    expect((new MaxMindWebServiceProvider)->locate(new GeolocationQuery('81.2.69.142')))->toBeNull();
});

it('returns null when the ip is not found', function (): void {
    Http::fake([
        'geoip.maxmind.com/*' => Http::response(status: 404),
    ]);

    expect((new MaxMindWebServiceProvider)->locate(new GeolocationQuery('81.2.69.142')))->toBeNull();
});

it('returns null when the body is not an object', function (): void {
    Http::fake([
        'geoip.maxmind.com/*' => Http::response('"plain"'),
    ]);

    expect((new MaxMindWebServiceProvider)->locate(new GeolocationQuery('81.2.69.142')))->toBeNull();
});
