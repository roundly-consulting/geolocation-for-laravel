<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\Exceptions\InvalidCoordinatesException;

it('holds valid coordinates', function (): void {
    $coordinates = new Coordinates(48.14, 17.10);

    expect($coordinates->latitude)->toBe(48.14)->and($coordinates->longitude)->toBe(17.10);
});

it('builds via the make helper', function (): void {
    expect(Coordinates::make(1.0, 2.0))->toBeInstanceOf(Coordinates::class);
});

it('rejects an out-of-range latitude', function (): void {
    new Coordinates(91.0, 0.0);
})->throws(InvalidCoordinatesException::class);

it('rejects an out-of-range longitude', function (): void {
    new Coordinates(0.0, 181.0);
})->throws(InvalidCoordinatesException::class);

it('rejects a latitude below the minimum', function (): void {
    new Coordinates(-91.0, 0.0);
})->throws(InvalidCoordinatesException::class);

it('rejects a longitude below the minimum', function (): void {
    new Coordinates(0.0, -181.0);
})->throws(InvalidCoordinatesException::class);

it('measures the great-circle distance between two points', function (): void {
    // Bratislava -> Prague is roughly 290 km.
    $bratislava = new Coordinates(48.1486, 17.1077);
    $prague = new Coordinates(50.0755, 14.4378);

    expect($bratislava->distanceTo($prague))
        ->toBeGreaterThan(280_000.0)
        ->toBeLessThan(300_000.0);
});

it('is zero distance to itself', function (): void {
    $point = new Coordinates(10.0, 20.0);

    expect($point->distanceTo($point))->toBe(0.0);
});

it('serializes to an array and json', function (): void {
    $coordinates = new Coordinates(1.5, 2.5);

    expect($coordinates->toArray())->toBe(['latitude' => 1.5, 'longitude' => 2.5])
        ->and($coordinates->jsonSerialize())->toBe(['latitude' => 1.5, 'longitude' => 2.5])
        ->and(json_encode($coordinates))->toBe('{"latitude":1.5,"longitude":2.5}');
});
