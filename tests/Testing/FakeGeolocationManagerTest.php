<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Facades\Geolocation;

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
