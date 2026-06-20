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
