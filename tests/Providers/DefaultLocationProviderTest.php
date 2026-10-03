<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\Providers\DefaultLocationProvider;

it('returns default geolocation from config', function () {
    config()->set('geolocation.default', [
        'humanReadable' => 'Somewhere, Smallville',
        'street' => 'Somewhere',
        'city' => 'Smallville',
        'country' => 'SM',
        'latitude' => 48.1486,
        'longitude' => 17.1077,
    ]);

    $provider = new DefaultLocationProvider;

    expect($provider->locate(new GeolocationQuery))
        ->humanReadable->toBe('Somewhere, Smallville')
        ->street->toBe('Somewhere')
        ->city->toBe('Smallville')
        ->countryIsoCode->toBe('SM')
        ->latitude->toBe(48.1486)
        ->longitude->toBe(17.1077)
        ->type->toBe(GeolocationType::Default);
});

it('answers null while no default location is configured', function () {
    // The shipped config: every default.* value empty / zero.
    expect((new DefaultLocationProvider)->locate(GeolocationQuery::forIp('8.8.8.8')))->toBeNull();
});

it('answers with a default that sets only coordinates', function () {
    config()->set('geolocation.default.latitude', '48.1486');
    config()->set('geolocation.default.longitude', '17.1077');

    expect((new DefaultLocationProvider)->locate(GeolocationQuery::forIp('8.8.8.8')))
        ->latitude->toBe(48.1486)
        ->countryIsoCode->toBe('');
});

it('leaves an unresolved lookup null when the default is not configured', function () {
    Sleep::fake();
    config()->set('geolocation.pipeline', ['ipinfo', 'default']);
    Http::fake(['ipinfo.io/*' => Http::response(status: 404)]);

    expect(Geolocation::locateIp('8.8.8.8'))->toBeNull();
});

it('never caches the default fallback as a real lookup', function () {
    Sleep::fake();
    config()->set('geolocation.services.ipinfo.retry', 0);
    config()->set('geolocation.pipeline', ['ipinfo', 'default']);
    config()->set('geolocation.cache.enabled', true);
    config()->set('geolocation.default.country', 'FALLBACK');
    Cache::flush();

    Http::fake(['ipinfo.io/*' => Http::sequence()
        ->push(status: 503)
        ->push(['city' => 'Real', 'country' => 'US', 'loc' => '1,2'])]);

    $duringOutage = Geolocation::locateIp('8.8.8.8');
    $afterRecovery = Geolocation::locateIp('8.8.8.8');
    $cachedHit = Geolocation::locateIp('8.8.8.8');

    expect($duringOutage?->countryIsoCode)->toBe('FALLBACK')
        ->and($duringOutage?->type)->toBe(GeolocationType::Default)
        ->and($afterRecovery?->countryIsoCode)->toBe('US')
        ->and($cachedHit?->countryIsoCode)->toBe('US');

    Http::assertSentCount(2);
});
