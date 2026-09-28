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

    $result = Geolocation::withToken('x')->withTimeout(1)->withConfig(['a' => 'b'])->locateIp('1.1.1.1');

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
