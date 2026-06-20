<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Events\DistanceResolved;
use RoundlyConsulting\Geolocation\Events\LocationResolutionFailed;
use RoundlyConsulting\Geolocation\Events\LocationResolved;
use RoundlyConsulting\Geolocation\Exceptions\UnknownProviderException;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Geolocation\GeolocationProvider;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeAlternativeGeolocationProvider;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeDistanceProvider;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeGeolocationProvider;

beforeEach(function (): void {
    config()->set('geolocation.providers', [
        'fake' => FakeGeolocationProvider::class,
        'alt' => FakeAlternativeGeolocationProvider::class,
        'distance' => FakeDistanceProvider::class,
    ]);
    config()->set('geolocation.pipeline', ['fake', 'alt', 'distance']);
    config()->set('geolocation.events.enabled', true);
    config()->set('geolocation.cache.enabled', false);
});

it('resolves a location through the named pipeline and dispatches an event', function (): void {
    Event::fake();

    $location = app(GeolocationManager::class)->locate(new GeolocationQuery('127.0.0.1'));

    expect($location)->toBeInstanceOf(Location::class)->humanReadable->toBe('Somewhere, Smallville');

    Event::assertDispatched(LocationResolved::class, fn (LocationResolved $e): bool => $e->provider === 'fake');
});

it('dispatches a failure event when nothing resolves', function (): void {
    config()->set('geolocation.pipeline', []);
    config()->set('geolocation.providers', []);

    Event::fake();

    expect(app(GeolocationManager::class)->locate(new GeolocationQuery('127.0.0.1')))->toBeNull();

    Event::assertDispatched(LocationResolutionFailed::class);
});

it('does not dispatch events when events are disabled', function (): void {
    config()->set('geolocation.events.enabled', false);

    Event::fake();

    app(GeolocationManager::class)->locate(new GeolocationQuery('127.0.0.1'));

    Event::assertNothingDispatched();
});

it('resolves a distance and dispatches a distance event', function (): void {
    Event::fake();

    $distance = app(GeolocationManager::class)->distance(
        new DistanceQuery(1, 2, 3, 4, DistanceType::Driving),
    );

    expect($distance)->toBeInstanceOf(Distance::class)->distanceInMeters->toBe(10000);

    Event::assertDispatched(DistanceResolved::class, fn (DistanceResolved $e): bool => $e->provider === 'distance');
});

it('registers a custom provider via extend', function (): void {
    $manager = app(GeolocationManager::class);

    $manager->extend('memory', fn () => new class implements GeolocationProvider
    {
        public function locate(GeolocationQuery $query): Location
        {
            return new Location('Custom', '', 'Custom City', 'CX', 1.0, 2.0, GeolocationType::Ip);
        }
    });

    config()->set('geolocation.pipeline', ['memory']);

    expect($manager->locate(new GeolocationQuery('1.1.1.1')))->city->toBe('Custom City');
});

it('scopes a single call to the named providers via using', function (): void {
    $location = app(GeolocationManager::class)
        ->using('alt')
        ->locate(new GeolocationQuery('127.0.0.1'));

    expect($location)->humanReadable->toBe('Alternative Human Readable');
});

it('throws for an unknown provider name', function (): void {
    app(GeolocationManager::class)->using('nope')->locate(new GeolocationQuery('1.1.1.1'));
})->throws(UnknownProviderException::class);

it('caches a successful lookup', function (): void {
    config()->set('geolocation.cache.enabled', true);
    config()->set('geolocation.cache.ttl', 60);
    Cache::flush();

    $manager = app(GeolocationManager::class);

    $first = $manager->locate(new GeolocationQuery('127.0.0.1'));
    $second = $manager->locate(new GeolocationQuery('127.0.0.1'));

    expect($first)->toEqual($second)
        ->and(Cache::has('geolocation:locate:'.(new GeolocationQuery('127.0.0.1'))->cacheKey()))->toBeTrue();
});

it('caches a successful distance lookup', function (): void {
    config()->set('geolocation.cache.enabled', true);
    Cache::flush();

    $query = new DistanceQuery(1, 2, 3, 4, DistanceType::Driving);
    $manager = app(GeolocationManager::class);

    expect($manager->distance($query))->toEqual($manager->distance($query));
});

it('locates by ip helper', function (): void {
    expect(app(GeolocationManager::class)->locateIp('127.0.0.1'))->city->toBe('Smallville');
});

it('locates by address helper', function (): void {
    expect(app(GeolocationManager::class)->locateAddress('anywhere'))->city->toBe('Smallville');
});

it('locates by coordinates helper', function (): void {
    expect(app(GeolocationManager::class)->locateCoordinates(new Coordinates(1.0, 2.0)))->city->toBe('Smallville');
});

it('locates by the current request ip', function (): void {
    $request = Request::create('/', server: ['REMOTE_ADDR' => '8.8.8.8']);

    expect(app(GeolocationManager::class)->locateRequest($request))->city->toBe('Smallville');
});

it('returns null when the request has no ip', function (): void {
    $request = Mockery::mock(Request::class);
    $request->shouldReceive('ip')->andReturn(null);

    expect(app(GeolocationManager::class)->locateRequest($request))->toBeNull();
});

it('skips providers that do not implement the needed contract', function (): void {
    config()->set('geolocation.pipeline', ['distance', 'fake']);

    expect(app(GeolocationManager::class)->locate(new GeolocationQuery('127.0.0.1')))
        ->humanReadable->toBe('Somewhere, Smallville');
});

it('does not use the cache for scoped using calls', function (): void {
    config()->set('geolocation.cache.enabled', true);
    Cache::flush();

    app(GeolocationManager::class)->using('alt')->locate(new GeolocationQuery('127.0.0.1'));

    expect(Cache::has('geolocation:locate:'.(new GeolocationQuery('127.0.0.1'))->cacheKey()))->toBeFalse();
});
