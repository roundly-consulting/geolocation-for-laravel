<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\Actions\UpdateDatabaseAction;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Events\DistanceResolved;
use RoundlyConsulting\Geolocation\Events\LocationResolved;
use RoundlyConsulting\Geolocation\Exceptions\DatabaseUpdateException;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeDistanceProvider;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeGeolocationProvider;

beforeEach(function (): void {
    config()->set('geolocation.providers', [
        'fake' => FakeGeolocationProvider::class,
        'distance' => FakeDistanceProvider::class,
    ]);
    config()->set('geolocation.pipeline', ['fake', 'distance']);
    config()->set('geolocation.events.enabled', true);
});

/**
 * The public API contract — Manager → Facade (+ fake). Lookups and distances are
 * remote-API provider clients (service objects, per the skill); the one state-changing
 * use case, the MaxMind database refresh, is `Actions\UpdateDatabaseAction`.
 */
it('pins the facade contract', function (): void {
    expect(Geolocation::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

it('resolves the manager from the facade accessor', function (): void {
    expect(Geolocation::getFacadeRoot())->toBeInstanceOf(GeolocationManager::class);
});

it('proxies calls through the facade', function (): void {
    expect(Geolocation::locate(new GeolocationQuery('1.1.1.1')))->city->toBe('Smallville');
});

it('declares the global alias for the facade and never a core facade name', function (): void {
    /** @var array{extra: array{laravel: array{aliases: array<string, string>}}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    $aliases = $composer['extra']['laravel']['aliases'];

    expect($aliases)->toBe(['Geolocation' => Geolocation::class])
        ->and(class_exists('Illuminate\\Support\\Facades\\Geolocation'))->toBeFalse();
});

describe('distanceBetween', function (): void {
    it('measures two points without a hand-built query', function (): void {
        Event::fake();

        $distance = Geolocation::distanceBetween(new Coordinates(48.1, 17.1), new Coordinates(50.0, 14.4), DistanceType::Walking);

        expect($distance)->toBeInstanceOf(Distance::class)
            ->and($distance?->distanceInMeters)->toBe(10000);

        Event::assertDispatched(
            DistanceResolved::class,
            static fn (DistanceResolved $event): bool => $event->query->type === DistanceType::Walking
                && $event->query->fromLatitude === 48.1
                && $event->query->toLongitude === 14.4,
        );
    });

    it('defaults to driving', function (): void {
        Event::fake();

        Geolocation::distanceBetween(new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0));

        Event::assertDispatched(DistanceResolved::class, static fn (DistanceResolved $event): bool => $event->query->type === DistanceType::Driving);
    });
});

describe('cache', function (): void {
    beforeEach(function (): void {
        config()->set('geolocation.cache.enabled', true);
        Cache::flush();
    });

    it('forgets one cached lookup so the next call asks the providers again', function (): void {
        Event::fake();
        $query = GeolocationQuery::forIp('8.8.8.8');

        Geolocation::locate($query);
        Geolocation::locate($query);
        Event::assertDispatchedTimes(LocationResolved::class, 1);

        expect(Geolocation::forget($query))->toBeTrue()
            ->and(Geolocation::forget($query))->toBeFalse();

        Geolocation::locate($query);
        Event::assertDispatchedTimes(LocationResolved::class, 2);
    });

    it('forgets only the query it was given', function (): void {
        Event::fake();

        Geolocation::locateIp('8.8.8.8');
        Geolocation::locateIp('1.1.1.1');

        Geolocation::forget(GeolocationQuery::forIp('8.8.8.8'));

        Geolocation::locateIp('1.1.1.1');
        Event::assertDispatchedTimes(LocationResolved::class, 2);
    });

    it('forgets a cached distance', function (): void {
        Event::fake();
        $query = DistanceQuery::between(new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0));

        Geolocation::distance($query);
        Geolocation::distance($query);
        Event::assertDispatchedTimes(DistanceResolved::class, 1);

        expect(Geolocation::forget($query))->toBeTrue();

        Geolocation::distance($query);
        Event::assertDispatchedTimes(DistanceResolved::class, 2);
    });

    it('flushes every cached lookup and distance at once', function (): void {
        Event::fake();
        $distance = DistanceQuery::between(new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0));

        Geolocation::locateIp('8.8.8.8');
        Geolocation::locateIp('1.1.1.1');
        Geolocation::distance($distance);

        Geolocation::flushCache();

        Geolocation::locateIp('8.8.8.8');
        Geolocation::locateIp('1.1.1.1');
        Geolocation::distance($distance);

        Event::assertDispatchedTimes(LocationResolved::class, 4);
        Event::assertDispatchedTimes(DistanceResolved::class, 2);

        // Caching resumes under the new generation.
        Geolocation::locateIp('8.8.8.8');
        Event::assertDispatchedTimes(LocationResolved::class, 4);
    });

    it('leaves unrelated keys in a shared store alone', function (): void {
        Cache::put('someone-else', 'kept', 60);

        Geolocation::locateIp('8.8.8.8');
        Geolocation::flushCache();

        expect(Cache::get('someone-else'))->toBe('kept');
    });

    it('sees a flush made through another manager instance', function (): void {
        Event::fake();

        Geolocation::locateIp('8.8.8.8');
        (new GeolocationManager(app()))->flushCache();
        Geolocation::locateIp('8.8.8.8');

        Event::assertDispatchedTimes(LocationResolved::class, 2);
    });
});

describe('updateDatabase', function (): void {
    beforeEach(function (): void {
        config()->set('geolocation.services.maxmind_database.license_key', 'key');
        config()->set('geolocation.services.maxmind_database.edition', 'GeoLite2-City');
        config()->set('geolocation.services.maxmind_database.path', storage_path('app/geolocation/Facade-City.mmdb'));
    });

    afterEach(function (): void {
        foreach (['Facade-City.mmdb', 'Facade-Country.mmdb'] as $file) {
            @unlink(storage_path("app/geolocation/{$file}"));
        }
    });

    it('downloads the configured edition to the configured path', function (): void {
        Http::fake(['download.maxmind.com/*' => Http::response(fakeMaxMindArchive('GeoLite2-City', 'CITY-DB'))]);

        $path = Geolocation::updateDatabase();

        expect($path)->toBe(storage_path('app/geolocation/Facade-City.mmdb'))
            ->and(file_get_contents($path))->toBe('CITY-DB');

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'edition_id=GeoLite2-City'));
    });

    it('takes an edition and a path', function (): void {
        Http::fake(['download.maxmind.com/*' => Http::response(fakeMaxMindArchive('GeoLite2-Country', 'COUNTRY-DB'))]);

        $path = Geolocation::updateDatabase('GeoLite2-Country', storage_path('app/geolocation/Facade-Country.mmdb'));

        expect(file_get_contents($path))->toBe('COUNTRY-DB');

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'edition_id=GeoLite2-Country'));
    });

    it('throws a typed exception instead of writing anything', function (): void {
        config()->set('geolocation.services.maxmind_database.license_key', '');

        Geolocation::updateDatabase();
    })->throws(DatabaseUpdateException::class, 'license key is required');

    it('resolves the action through the container so a host override applies', function (): void {
        app()->bind(UpdateDatabaseAction::class, static fn (): never => throw new RuntimeException('overridden'));

        Geolocation::updateDatabase();
    })->throws(RuntimeException::class, 'overridden');
});

it('serves the same API through an injected manager', function (): void {
    Http::fake(['download.maxmind.com/*' => Http::response(fakeMaxMindArchive('GeoLite2-City', 'DI-DB'))]);
    config()->set('geolocation.services.maxmind_database.license_key', 'key');
    $path = storage_path('app/geolocation/Di-City.mmdb');

    $manager = app(GeolocationManager::class);

    expect($manager)->toBe(app(GeolocationManager::class))
        ->and($manager)->toBe(Geolocation::getFacadeRoot())
        ->and($manager->distanceBetween(new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0)))->toBeInstanceOf(Distance::class)
        ->and(file_get_contents($manager->updateDatabase(path: $path)))->toBe('DI-DB');

    unlink($path);
});
