<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\BoundingBox;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;

it('knows whether a point is near another within a radius', function () {
    $a = new Coordinates(48.1486, 17.1077); // Bratislava
    $b = new Coordinates(48.2082, 16.3738); // Vienna (~55 km)

    expect($a->near($b, 100))->toBeTrue()
        ->and($a->near($b, 10))->toBeFalse();
});

it('detects points inside and outside a polygon', function () {
    $square = [
        new Coordinates(0.0, 0.0),
        new Coordinates(0.0, 10.0),
        new Coordinates(10.0, 10.0),
        new Coordinates(10.0, 0.0),
    ];

    expect((new Coordinates(5.0, 5.0))->within($square))->toBeTrue()
        ->and((new Coordinates(20.0, 20.0))->within($square))->toBeFalse();
});

it('returns false for a degenerate polygon', function () {
    expect((new Coordinates(1.0, 1.0))->within([new Coordinates(0.0, 0.0)]))->toBeFalse();
});

it('computes an initial bearing between two points', function () {
    $from = new Coordinates(0.0, 0.0);

    expect($from->bearingTo(new Coordinates(1.0, 0.0)))->toBe(0.0)
        ->and(round($from->bearingTo(new Coordinates(0.0, 1.0)), 1))->toBe(90.0);
});

it('computes a midpoint between two points', function () {
    $mid = (new Coordinates(0.0, 0.0))->midpointTo(new Coordinates(0.0, 10.0));

    expect(round($mid->latitude, 4))->toBe(0.0)
        ->and(round($mid->longitude, 4))->toBe(5.0);
});

it('builds a bounding box around a point', function () {
    $center = new Coordinates(48.1486, 17.1077);
    $box = $center->boundingBox(10);

    expect($box)->toBeInstanceOf(BoundingBox::class)
        ->and($box->contains($center))->toBeTrue()
        ->and($box->southWest->latitude)->toBeLessThan($center->latitude)
        ->and($box->northEast->latitude)->toBeGreaterThan($center->latitude);

    $faraway = new Coordinates(0.0, 0.0);
    expect($box->contains($faraway))->toBeFalse();
});

it('clamps the bounding box at the poles', function () {
    $pole = new Coordinates(89.9, 0.0);
    $box = $pole->boundingBox(500);

    expect($box->northEast->latitude)->toBeLessThanOrEqual(90.0)
        ->and($box->southWest->longitude)->toBeGreaterThanOrEqual(-180.0);
});

it('exposes the bounding box as an array and json', function () {
    $box = (new Coordinates(1.0, 2.0))->boundingBox(1);

    expect($box->toArray())->toHaveKeys(['minLatitude', 'minLongitude', 'maxLatitude', 'maxLongitude'])
        ->and($box->jsonSerialize())->toBe($box->toArray());
});

/**
 * The point $distanceKm from $from along $bearing (degrees) on the sphere the package uses.
 */
function destinationPoint(Coordinates $from, float $bearing, float $distanceKm): Coordinates
{
    $angular = $distanceKm * 1000 / 6_371_000.0;
    $lat = deg2rad($from->latitude);
    $lon = deg2rad($from->longitude);
    $theta = deg2rad($bearing);

    $lat2 = asin(sin($lat) * cos($angular) + cos($lat) * sin($angular) * cos($theta));
    $lon2 = $lon + atan2(sin($theta) * sin($angular) * cos($lat), cos($angular) - sin($lat) * sin($lat2));

    return new Coordinates(
        max(-90.0, min(90.0, rad2deg($lat2))),
        fmod(rad2deg($lon2) + 540.0, 360.0) - 180.0,
    );
}

it('normalises a midpoint across the antimeridian', function () {
    $mid = (new Coordinates(0.0, 175.0))->midpointTo(new Coordinates(0.0, -170.0));

    expect(round($mid->latitude, 6))->toBe(0.0)
        ->and(round($mid->longitude, 6))->toBe(-177.5);
});

it('wraps a bounding box across the antimeridian', function () {
    $box = (new Coordinates(0.0, 179.9))->boundingBox(50);

    expect($box->crossesAntimeridian())->toBeTrue()
        ->and($box->southWest->longitude)->toBeGreaterThan($box->northEast->longitude)
        ->and($box->contains(new Coordinates(0.0, -179.9)))->toBeTrue()
        ->and($box->contains(new Coordinates(0.0, 179.5)))->toBeTrue()
        ->and($box->contains(new Coordinates(0.0, 0.0)))->toBeFalse()
        ->and($box->contains(new Coordinates(0.0, 178.0)))->toBeFalse();
});

it('spans every longitude when the circle contains a pole', function () {
    $box = (new Coordinates(89.5, 0.0))->boundingBox(100);

    expect($box->southWest->longitude)->toBe(-180.0)
        ->and($box->northEast->longitude)->toBe(180.0)
        ->and($box->northEast->latitude)->toBe(90.0)
        ->and($box->contains(new Coordinates(89.9, 180.0)))->toBeTrue();
});

it('contains every point of the circle it was built for', function (float $latitude, float $longitude, float $radiusKm) {
    $center = new Coordinates($latitude, $longitude);
    $box = $center->boundingBox($radiusKm);

    for ($bearing = 0; $bearing < 360; $bearing += 5) {
        $edge = destinationPoint($center, (float) $bearing, $radiusKm * 0.999);

        expect($box->contains($edge))->toBeTrue("bearing {$bearing} escapes the box");
    }
})->with([
    'mid latitude' => [48.1486, 17.1077, 50.0],
    'high latitude, wide' => [70.0, 20.0, 1500.0],
    'southern, near the dateline' => [-45.0, -179.5, 300.0],
    'arctic' => [85.0, 100.0, 400.0],
]);

it('keeps a point-in-polygon test working across the antimeridian', function () {
    $square = [
        new Coordinates(-10.0, 170.0),
        new Coordinates(10.0, 170.0),
        new Coordinates(10.0, -170.0),
        new Coordinates(-10.0, -170.0),
    ];

    expect((new Coordinates(0.0, 179.0))->within($square))->toBeTrue()
        ->and((new Coordinates(0.0, -175.0))->within($square))->toBeTrue()
        ->and((new Coordinates(0.0, 0.0))->within($square))->toBeFalse()
        ->and((new Coordinates(0.0, 160.0))->within($square))->toBeFalse();
});
