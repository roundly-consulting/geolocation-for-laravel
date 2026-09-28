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
        ->and(Cache::has('geolocation:v0:locate:'.(new GeolocationQuery('127.0.0.1'))->cacheKey()))->toBeTrue();
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

    expect(Cache::has('geolocation:v0:locate:'.(new GeolocationQuery('127.0.0.1'))->cacheKey()))->toBeFalse();
});

it('hits the cache through a store that refuses to unserialize classes', function (): void {
    // The trap this closes: the manager used to `put()` the `Location` object and read
    // it back with `instanceof`. Under `cache.serializable_classes => false` — Laravel's
    // default, guarding against gadget chains if `APP_KEY` leaks — that object returns
    // as `__PHP_Incomplete_Class`, so the `instanceof` was false FOREVER: a cache that
    // never hit, quietly re-billing the provider on every lookup. The default `array`
    // store does not serialize, which is why no test could see it.
    config()->set('geolocation.cache.enabled', true);
    config()->set('cache.serializable_classes', false);
    config()->set('cache.stores.array.serialize', true);
    app('cache')->forgetDriver('array');
    Cache::flush();

    $manager = app(GeolocationManager::class);
    $query = new GeolocationQuery('127.0.0.1');

    $first = $manager->locate($query);
    $stored = Cache::get('geolocation:v0:locate:'.$query->cacheKey());
    $second = $manager->locate($query);

    expect($stored)->toBeArray()
        ->and($second)->toBeInstanceOf(Location::class)
        ->and($second)->toEqual($first);
});

it('reads a payload it does not understand as a miss', function (): void {
    config()->set('geolocation.cache.enabled', true);
    Cache::flush();

    $query = new GeolocationQuery('127.0.0.1');
    // What an older version of this package left behind.
    Cache::put('geolocation:v0:locate:'.$query->cacheKey(), 'nonsense', 60);

    expect(app(GeolocationManager::class)->locate($query))->toBeInstanceOf(Location::class);
});

it('names the provider and error on the failure event when a provider throws', function (): void {
    $manager = app(GeolocationManager::class);
    $boom = new RuntimeException('database exploded');

    $manager->extend('broken', fn () => new class($boom) implements GeolocationProvider
    {
        public function __construct(private readonly Throwable $boom) {}

        public function locate(GeolocationQuery $query): ?Location
        {
            throw $this->boom;
        }
    });
    config()->set('geolocation.pipeline', ['broken']);
    Event::fake([LocationResolutionFailed::class]);

    expect(fn () => $manager->locateIp('1.1.1.1'))->toThrow(RuntimeException::class, 'database exploded');

    Event::assertDispatched(LocationResolutionFailed::class, fn (LocationResolutionFailed $event): bool => $event->provider === 'broken'
        && $event->error === $boom);
});

it('leaves provider and error null on the failure event when every provider simply misses', function (): void {
    $manager = app(GeolocationManager::class);
    $manager->extend('empty', fn () => new class implements GeolocationProvider
    {
        public function locate(GeolocationQuery $query): ?Location
        {
            return null;
        }
    });
    config()->set('geolocation.pipeline', ['empty']);
    Event::fake([LocationResolutionFailed::class]);

    expect($manager->locateIp('1.1.1.1'))->toBeNull();

    Event::assertDispatched(LocationResolutionFailed::class, fn (LocationResolutionFailed $event): bool => $event->provider === null
        && $event->error === null);
});
