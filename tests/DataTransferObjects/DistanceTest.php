<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\Enum\DistanceType;

it('holds values', function () {
    $distance = new Distance(
        humanReadableDistance: '10km',
        distanceInMeters: 10000,
        humanReadableDuration: '10min',
        durationInSeconds: 600,
        type: DistanceType::Walking,
    );

    expect($distance)
        ->humanReadableDistance->toBe('10km')
        ->distanceInMeters->toBe(10000)
        ->humanReadableDuration->toBe('10min')
        ->durationInSeconds->toBe(600)
        ->type->toBe(DistanceType::Walking);
});
