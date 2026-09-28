<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Support;

/**
 * Renders a coordinate as a plain decimal string. PHP prints floats below 1e-4 in scientific
 * notation (`1.0E-5`), which an API expecting "lat,lng" rejects or misreads — and the
 * equator / Greenwich band is exactly where such values occur.
 *
 * @internal
 */
final class Decimal
{
    public static function format(float $value): string
    {
        $plain = (string) $value;

        if (stripos($plain, 'e') !== false) {
            $plain = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        }

        return $plain === '-0' ? '0' : $plain;
    }

    public static function pair(float $latitude, float $longitude): string
    {
        return self::format($latitude).','.self::format($longitude);
    }
}
