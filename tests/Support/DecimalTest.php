<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\Support\Decimal;

it('formats floats as plain decimals', function (float $value, string $expected): void {
    expect(Decimal::format($value))->toBe($expected);
})->with([
    'regular' => [48.1486, '48.1486'],
    'negative' => [-122.0841, '-122.0841'],
    'integral' => [17.0, '17'],
    'tiny' => [0.00001, '0.00001'],
    'tiny negative' => [-0.000025, '-0.000025'],
    'zero' => [0.0, '0'],
    'negative zero' => [-0.0, '0'],
]);
