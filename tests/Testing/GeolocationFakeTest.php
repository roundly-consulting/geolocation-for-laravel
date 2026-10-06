<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Exceptions\UnknownProviderException;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\GeolocationManager;

function fakeLocation(string $city = 'Somewhere'): Location
{
    return new Location(
        humanReadable: $city,
        street: '',
        city: $city,
        countryIsoCode: 'SK',
        latitude: 1.0,
        longitude: 2.0,
        type: GeolocationType::Ip,
    );
}

it('returns seeded results per ip', function () {
    Geolocation::fake(['8.8.8.8' => fakeLocation('Mountain View')]);

    expect(Geolocation::locateIp('8.8.8.8')->city)->toBe('Mountain View');
});

it('seeds results fluently for addresses and coordinates', function () {
    $fake = Geolocation::fake();
    $fake->seed('Main Street', fakeLocation('Town'))
        ->seed('1,2', fakeLocation('Point'));

    expect(Geolocation::locateAddress('Main Street')->city)->toBe('Town')
        ->and(Geolocation::locateCoordinates(new Coordinates(1.0, 2.0))->city)->toBe('Point');
});

it('falls back to a default seeded location', function () {
    Geolocation::fake()->seedDefault(fakeLocation('Default'));

    expect(Geolocation::locateIp('9.9.9.9')->city)->toBe('Default');
});

it('records and asserts located keys', function () {
    $fake = Geolocation::fake();
    Geolocation::locateIp('1.1.1.1');

    $fake->assertLocated('1.1.1.1');

    expect(fn () => $fake->assertLocated('2.2.2.2'))->toThrow(AssertionFailedError::class);
});

it('asserts nothing was located', function () {
    $fake = Geolocation::fake();

    $fake->assertNothingLocated();

    Geolocation::locateIp('1.1.1.1');

    expect(fn () => $fake->assertNothingLocated())->toThrow(AssertionFailedError::class);
});

it('asserts which provider was used', function () {
    $fake = Geolocation::fake();

    Geolocation::provider('ipinfo')->locateIp('1.1.1.1');

    $fake->assertProviderUsed('ipinfo');

    expect(fn () => $fake->assertProviderUsed('google'))->toThrow(AssertionFailedError::class);
});

it('records the provider pinned via using', function () {
    $fake = Geolocation::fake();

    Geolocation::using('maxmind_database')->locateIp('1.1.1.1');

    $fake->assertProviderUsed('maxmind_database');
});

it('records every provider named in using', function () {
    $fake = Geolocation::fake();

    Geolocation::using('maxmind_database', 'ipinfo')->locateIp('1.1.1.1');

    $fake->assertProviderUsed('maxmind_database');
    $fake->assertProviderUsed('ipinfo');
});

it('records a provider pinned for a distance, a matrix or a batch', function () {
    $fake = Geolocation::fake();

    Geolocation::provider('google')->distanceBetween(new Coordinates(1, 2), new Coordinates(3, 4));
    Geolocation::provider('maxmind_web')->distanceMatrix([new Coordinates(1, 2)], [new Coordinates(3, 4)]);
    Geolocation::provider('maxmind_database')->batch(['1.1.1.1', '2.2.2.2']);

    $fake->assertProviderUsed('google');
    $fake->assertProviderUsed('maxmind_web');
    $fake->assertProviderUsed('maxmind_database');
    $fake->assertLocated('2.2.2.2');
});

it('asserts a provider was not pinned', function () {
    $fake = Geolocation::fake();

    Geolocation::locateIp('1.1.1.1');
    $fake->assertProviderNotUsed('ipinfo');

    Geolocation::provider('ipinfo')->locateIp('1.1.1.1');

    expect(fn () => $fake->assertProviderNotUsed('ipinfo'))->toThrow(AssertionFailedError::class);
});

it('returns a seeded distance and an empty matrix', function () {
    $fake = Geolocation::fake();
    $distance = new Distance('1 km', 1000, '2 mins', 120, DistanceType::Driving);
    $fake->seedDistance($distance);

    expect(Geolocation::distance(
        DistanceQuery::between(
            new Coordinates(1, 2),
            new Coordinates(3, 4),
        )
    ))->toBe($distance);

    $matrix = Geolocation::distanceMatrix([new Coordinates(1, 2)], [new Coordinates(3, 4)]);
    expect($matrix->rows)->toBe([]);
});

it('ignores override calls on the fake', function () {
    $fake = Geolocation::fake(['1.1.1.1' => fakeLocation()]);

    $result = Geolocation::withToken('ipinfo', 'x')->withTimeout(1)->withConfig('ipinfo', ['a' => 'b'])->locateIp('1.1.1.1');

    expect($result)->toBeInstanceOf(Location::class);
});

it('returns null when locating an empty query', function () {
    $fake = Geolocation::fake();

    expect($fake->locate(new GeolocationQuery))->toBeNull();
});

it('installs the fake behind the facade and in the container', function () {
    $fake = Geolocation::fake();

    expect(Geolocation::getFacadeRoot())->toBe($fake)
        ->and(app(GeolocationManager::class))->toBe($fake)
        ->and(app('geolocation'))->toBe($fake)
        ->and($fake)->toBeInstanceOf(GeolocationManager::class);
});

it('fakes injected managers and the request macro too', function () {
    $fake = Geolocation::fake(['8.8.8.8' => fakeLocation('Mountain View')]);

    $request = Request::create('/', server: ['REMOTE_ADDR' => '8.8.8.8']);

    expect($request->location()?->city)->toBe('Mountain View');

    app(GeolocationManager::class)->locateIp('1.1.1.1');

    $fake->assertLocated('8.8.8.8');
    $fake->assertLocated('1.1.1.1');
});

it('records distances requested through distanceBetween and distance', function () {
    $fake = Geolocation::fake();
    $from = new Coordinates(48.1, 17.1);
    $to = new Coordinates(50.0, 14.4);

    Geolocation::distanceBetween($from, $to, DistanceType::Walking);

    $fake->assertDistanceRequested();
    $fake->assertDistanceRequested($from);
    $fake->assertDistanceRequested($from, $to, DistanceType::Walking);
    Geolocation::assertDistanceRequested(to: $to);

    expect(fn () => $fake->assertDistanceRequested($to))->toThrow(AssertionFailedError::class, 'a matching distance')
        ->and(fn () => $fake->assertDistanceRequested(type: DistanceType::Driving))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNoDistanceRequested())->toThrow(AssertionFailedError::class, '(1 were)');
});

it('fails assertDistanceRequested when nothing was measured', function () {
    $fake = Geolocation::fake();

    $fake->assertNoDistanceRequested();

    expect(fn () => $fake->assertDistanceRequested())->toThrow(AssertionFailedError::class, 'a distance was requested');
});

it('records a database refresh without downloading anything', function () {
    config()->set('geolocation.services.maxmind_database.edition', 'GeoLite2-City');
    config()->set('geolocation.services.maxmind_database.path', '/tmp/City.mmdb');
    $fake = Geolocation::fake();

    $fake->assertDatabaseNotUpdated();

    expect(Geolocation::updateDatabase())->toBe('/tmp/City.mmdb')
        ->and(Geolocation::updateDatabase('GeoLite2-Country', '/tmp/Country.mmdb'))->toBe('/tmp/Country.mmdb');

    Http::assertNothingSent();
    $fake->assertDatabaseUpdated();
    $fake->assertDatabaseUpdated('GeoLite2-City');
    $fake->assertDatabaseUpdated('GeoLite2-Country');

    expect(fn () => $fake->assertDatabaseUpdated('GeoIP2-City'))->toThrow(AssertionFailedError::class, '[GeoIP2-City]')
        ->and(fn () => $fake->assertDatabaseNotUpdated())->toThrow(AssertionFailedError::class);
});

it('records a blank configured edition as the default one, like the real refresh', function (string $blank): void {
    config()->set('geolocation.services.maxmind_database.edition', $blank);
    $fake = Geolocation::fake();

    Geolocation::updateDatabase();

    $fake->assertDatabaseUpdated('GeoLite2-City');
})->with(['empty' => '', 'whitespace' => '  ']);

it('records a database refresh made through the artisan command', function () {
    $fake = Geolocation::fake();

    $this->artisan('geolocation:db:update', ['--edition' => 'GeoLite2-ASN'])->assertExitCode(0);

    $fake->assertDatabaseUpdated('GeoLite2-ASN');
});

it('fails assertDatabaseUpdated when nothing was refreshed', function () {
    expect(fn () => Geolocation::fake()->assertDatabaseUpdated())
        ->toThrow(AssertionFailedError::class, 'the MaxMind database was updated');
});

it('records forgotten queries', function () {
    $fake = Geolocation::fake();
    $lookup = GeolocationQuery::forIp('8.8.8.8');
    $distance = DistanceQuery::between(new Coordinates(1, 2), new Coordinates(3, 4));

    $fake->assertNothingForgotten();

    expect(Geolocation::forget($lookup))->toBeTrue();
    Geolocation::forget($distance);

    $fake->assertForgotten(GeolocationQuery::forIp('8.8.8.8'));
    $fake->assertForgotten($distance);

    expect(fn () => $fake->assertForgotten(GeolocationQuery::forIp('1.1.1.1')))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNothingForgotten())->toThrow(AssertionFailedError::class);
});

it('records cache flushes', function () {
    $fake = Geolocation::fake();

    $fake->assertCacheNotFlushed();

    expect(fn () => $fake->assertCacheFlushed())->toThrow(AssertionFailedError::class);

    Geolocation::flushCache();

    $fake->assertCacheFlushed();

    expect(fn () => $fake->assertCacheNotFlushed())->toThrow(AssertionFailedError::class);
});

it('keys a tiny coordinate lookup as a plain decimal', function (): void {
    $fake = Geolocation::fake();

    Geolocation::locateCoordinates(new Coordinates(0.00001, 0.0));

    $fake->assertLocated('0.00001,0');
});

it('never lets an unchained provider() pin leak into the next call', function () {
    $fake = Geolocation::fake();

    // In production the scoped copy is discarded; the facade call after it is unscoped.
    Geolocation::provider('ipinfo');
    Geolocation::locateIp('1.1.1.1');

    $fake->assertProviderNotUsed('ipinfo');
    $fake->assertLocated('1.1.1.1');
});

it('hands out a scoped copy that records into the shared fake', function () {
    $fake = Geolocation::fake();

    $scoped = Geolocation::using('maxmind_database', 'ipinfo');
    $fake->seed('9.9.9.9', fakeLocation('Seeded later'));

    expect($scoped)->not->toBe($fake)
        ->and($scoped)->toBeInstanceOf(GeolocationManager::class)
        ->and($scoped->locateIp('9.9.9.9')?->city)->toBe('Seeded later');

    Geolocation::locateIp('1.1.1.1');

    $fake->assertProviderUsed('maxmind_database');
    $fake->assertProviderUsed('ipinfo');
    $fake->assertLocated('9.9.9.9');
    $fake->assertLocated('1.1.1.1');
    Geolocation::assertProviderUsed('ipinfo');
});

it('keeps the pin for every call made through the scoped copy', function () {
    $fake = Geolocation::fake();
    $scoped = Geolocation::provider('ipinfo');

    $scoped->locateIp('1.1.1.1');
    $scoped->distanceBetween(new Coordinates(1, 2), new Coordinates(3, 4));

    $fake->assertProviderUsed('ipinfo');
    $fake->assertLocated('1.1.1.1');
    $fake->assertDistanceRequested();
});

it('refuses an override for a provider that is not registered, like the real manager', function (Closure $override) {
    Geolocation::fake();

    expect($override)->toThrow(UnknownProviderException::class, '[ip2locaton]');
})->with([
    'withToken' => [fn () => Geolocation::withToken('ip2locaton', 'k')],
    'withConfig' => [fn () => Geolocation::withConfig('ip2locaton', ['token' => 'k'])],
]);

it('accepts an override for a configured or extended provider', function () {
    $fake = Geolocation::fake(['1.1.1.1' => fakeLocation()]);
    Geolocation::extend('custom', fn () => new stdClass);

    expect(Geolocation::withToken('custom', 'k')->withConfig('ip2location', ['a' => 1])->locateIp('1.1.1.1'))
        ->toBeInstanceOf(Location::class);

    $fake->assertLocated('1.1.1.1');
});

it('refuses a call pinned to a provider that is not registered, like the real manager', function (Closure $call) {
    $fake = Geolocation::fake();

    expect($call)->toThrow(UnknownProviderException::class, '[ip2locaton]');

    $fake->assertProviderNotUsed('ip2locaton');
})->with([
    'lookup' => [fn () => Geolocation::using('ip2locaton')->locateIp('1.1.1.1')],
    'distance' => [fn () => Geolocation::provider('ip2locaton')->distanceBetween(new Coordinates(1, 2), new Coordinates(3, 4))],
    'matrix' => [fn () => Geolocation::provider('ip2locaton')->distanceMatrix([new Coordinates(1, 2)], [new Coordinates(3, 4)])],
]);

it('answers null per ip for a batch pinned to an unregistered provider, like the real batch', function () {
    $fake = Geolocation::fake(['1.1.1.1' => fakeLocation()]);

    expect(Geolocation::provider('ip2locaton')->batch(['1.1.1.1']))->toBe(['1.1.1.1' => null]);

    $fake->assertProviderNotUsed('ip2locaton');
});

it('accepts a pin to a runtime-registered provider', function () {
    $fake = Geolocation::fake();
    Geolocation::extend('custom', fn () => new stdClass);

    Geolocation::provider('custom')->locateIp('1.1.1.1');

    $fake->assertProviderUsed('custom');
});

it('records a blank path as the configured one, like the real refresh', function (): void {
    config()->set('geolocation.services.maxmind_database.path', '/tmp/conf.mmdb');
    $fake = Geolocation::fake();

    expect(Geolocation::updateDatabase(null, ''))->toBe('/tmp/conf.mmdb');

    $fake->assertDatabaseUpdated('GeoLite2-City');
});
