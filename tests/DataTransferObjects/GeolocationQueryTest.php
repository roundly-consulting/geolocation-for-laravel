<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;

it('holds values', function () {
    $q = new GeolocationQuery(
        '127.0.0.1',
        12.34,
        56.78
    );

    expect($q)
        ->ipAddress->toBe('127.0.0.1')
        ->latitude->toBe(12.34)
        ->longitude->toBe(56.78);
});
