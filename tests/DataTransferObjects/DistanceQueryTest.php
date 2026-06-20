<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\Enum\DistanceType;

it('holds values', function () {
    $q = new DistanceQuery(
        1.2,
        3.4,
        5.6,
        7.8,
        DistanceType::Driving,
    );

    expect($q)
        ->fromLatitude->toBe(1.2)
        ->fromLongitude->toBe(3.4)
        ->toLatitude->toBe(5.6)
        ->toLongitude->toBe(7.8)
        ->type->toBe(DistanceType::Driving);
});
