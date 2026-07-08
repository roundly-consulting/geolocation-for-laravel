<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Exceptions\GeolocationException;
use RoundlyConsulting\Geolocation\Exceptions\RateLimitExceededException;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\Providers\DefaultLocationProvider;
use RoundlyConsulting\Geolocation\Providers\GoogleProvider;
use RoundlyConsulting\Geolocation\Providers\IP2LocationProvider;
use RoundlyConsulting\Geolocation\Providers\IpInfoProvider;
use RoundlyConsulting\Geolocation\Providers\MaxMindDatabaseProvider;
use RoundlyConsulting\Geolocation\Providers\MaxMindWebServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;

beforeEach(function (): void {
    // A single attempt keeps faked failures from looping through native retries.
    config()->set('geolocation.services.ipinfo.retry', 1);
    config()->set('geolocation.services.google.retry', 1);
    config()->set('geolocation.services.ip2location.retry', 1);
    config()->set('geolocation.services.maxmind.web.retry', 1);
});

function googleGeocodeFake(): array
{
    return ['*/geocode/json*' => Http::response([
        'results' => [[
            'formatted_address' => 'Somewhere',
            'address_components' => [],
            'geometry' => ['location' => ['lat' => 1.0, 'lng' => 2.0]],
        ]],
    ])];
}

it('paces an allowed ipinfo lookup under its per-provider key', function (): void {
    $fake = RateLimits::fake();
    Http::fake(['ipinfo.io/*' => Http::response(['city' => 'Mountain View', 'country' => 'US', 'loc' => '37.4,-122.07'])]);

    $location = (new IpInfoProvider)->locate(new GeolocationQuery('8.8.8.8'));

    expect($location)->toBeInstanceOf(Location::class);
    $fake->assertAllowed('geolocation:ipinfo:app')->assertNothingDeferred();
});

it('paces an allowed google geocode under the google key', function (): void {
    $fake = RateLimits::fake();
    Http::fake(googleGeocodeFake());

    (new GoogleProvider)->locate(GeolocationQuery::forAddress('1 Main St'));

    $fake->assertAllowed('geolocation:google:app');
});

it('paces an allowed ip2location lookup under the ip2location key', function (): void {
    config()->set('geolocation.services.ip2location.key', 'test-key');
    $fake = RateLimits::fake();
    Http::fake(['api.ip2location.io*' => Http::response([
        'country_code' => 'US', 'city_name' => 'New York', 'latitude' => 1, 'longitude' => 2,
    ])]);

    (new IP2LocationProvider)->locate(new GeolocationQuery('8.8.8.8'));

    $fake->assertAllowed('geolocation:ip2location:app');
});

it('paces an allowed maxmind web lookup under the maxmind_web key', function (): void {
    config()->set('geolocation.services.maxmind.web.enabled', true);
    $fake = RateLimits::fake();
    Http::fake(['geoip.maxmind.com/*' => Http::response([
        'country' => ['iso_code' => 'US'],
        'city' => ['names' => ['en' => 'New York']],
        'location' => ['latitude' => 1, 'longitude' => 2],
    ])]);

    (new MaxMindWebServiceProvider)->locate(new GeolocationQuery('8.8.8.8'));

    $fake->assertAllowed('geolocation:maxmind_web:app');
});

it('defers the second call once a provider window is exhausted', function (): void {
    config()->set('geolocation.services.ipinfo.rate_limits.limit', 1);
    $fake = RateLimits::fake();
    Http::fake(['ipinfo.io/*' => Http::response(['city' => 'X', 'country' => 'US', 'loc' => '1,2'])]);

    $provider = new IpInfoProvider;
    $provider->locate(new GeolocationQuery('1.1.1.1'));
    $provider->locate(new GeolocationQuery('8.8.8.8'));

    $fake->assertDeferred('geolocation:ipinfo:app');
});

it('throws a native RateLimitExceededException when max_wait is exceeded', function (): void {
    config()->set('geolocation.services.ipinfo.rate_limits.limit', 1);
    config()->set('geolocation.services.ipinfo.rate_limits.max_wait', 10);
    RateLimits::fake();
    Http::fake(['ipinfo.io/*' => Http::response(['city' => 'X', 'country' => 'US', 'loc' => '1,2'])]);

    $provider = new IpInfoProvider;
    $provider->locate(new GeolocationQuery('1.1.1.1'));

    try {
        $provider->locate(new GeolocationQuery('8.8.8.8'));
        $this->fail('Expected a RateLimitExceededException to be thrown.');
    } catch (GeolocationException $exception) {
        expect($exception)->toBeInstanceOf(RateLimitExceededException::class)
            ->and($exception->provider)->toBe('ipinfo')
            ->and($exception->availableInSeconds)->toBeGreaterThan(0);
    }
});

it('bypasses the limiter entirely when disabled for a provider', function (): void {
    config()->set('geolocation.services.ipinfo.rate_limits.enabled', false);
    config()->set('geolocation.services.ipinfo.rate_limits.limit', 1);
    $fake = RateLimits::fake();
    Http::fake(['ipinfo.io/*' => Http::response(['city' => 'X', 'country' => 'US', 'loc' => '1,2'])]);

    $provider = new IpInfoProvider;
    $provider->locate(new GeolocationQuery('1.1.1.1'));
    $last = $provider->locate(new GeolocationQuery('8.8.8.8'));

    $fake->assertNothingDeferred();
    expect($fake->allowedCount())->toBe(0)
        ->and($last)->toBeInstanceOf(Location::class);
});

it('records a server penalty from a 429 Retry-After and defers the next call', function (): void {
    $fake = RateLimits::fake();
    Http::fake(['ipinfo.io/*' => Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '2'])]);

    $provider = new IpInfoProvider;

    // 429 degrades to null (the provider's failed() branch) while adaptive
    // records the server penalty off Retry-After.
    expect($provider->locate(new GeolocationQuery('1.1.1.1')))->toBeNull();

    $provider->locate(new GeolocationQuery('8.8.8.8'));

    $fake->assertDeferred('geolocation:ipinfo:app');
});

it('keeps per-provider budgets isolated', function (): void {
    config()->set('geolocation.services.google.rate_limits.limit', 1);
    config()->set('geolocation.services.ipinfo.rate_limits.limit', 1);
    $fake = RateLimits::fake();
    Http::fake([
        ...googleGeocodeFake(),
        'ipinfo.io/*' => Http::response(['city' => 'X', 'country' => 'US', 'loc' => '1,2']),
    ]);

    $google = new GoogleProvider;
    $google->locate(GeolocationQuery::forAddress('a'));
    $google->locate(GeolocationQuery::forAddress('b'));

    (new IpInfoProvider)->locate(new GeolocationQuery('1.1.1.1'));

    $fake->assertDeferred('geolocation:google:app')
        ->assertAllowed('geolocation:ipinfo:app');

    // Only google was deferred — ipinfo's distinct key was untouched.
    expect($fake->deferredCount())->toBe(1);
});

it('never throttles the offline default provider', function (): void {
    $fake = RateLimits::fake();

    (new DefaultLocationProvider)->locate(new GeolocationQuery('1.1.1.1'));

    $fake->assertNothingDeferred();
    expect($fake->allowedCount())->toBe(0);
});

it('never throttles the offline maxmind database provider', function (): void {
    config()->set('geolocation.services.maxmind.database.enabled', true);
    config()->set('geolocation.services.maxmind.database.path', __DIR__.'/../Fixtures/test-data/GeoIP2-City-Test.mmdb');
    $fake = RateLimits::fake();

    (new MaxMindDatabaseProvider)->locate(new GeolocationQuery('2.125.160.216'));

    $fake->assertNothingDeferred();
    expect($fake->allowedCount())->toBe(0);
});

it('paces bulk ip lookups through the manager batch', function (): void {
    config()->set('geolocation.pipeline', ['ipinfo']);
    config()->set('geolocation.services.ipinfo.rate_limits.limit', 1);
    $fake = RateLimits::fake();
    Http::fake(['ipinfo.io/*' => Http::response(['city' => 'X', 'country' => 'US', 'loc' => '1,2'])]);

    Geolocation::batch(['1.1.1.1', '8.8.8.8']);

    $fake->assertDeferred('geolocation:ipinfo:app');
});
