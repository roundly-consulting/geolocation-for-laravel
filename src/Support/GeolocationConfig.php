<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Support;

use BackedEnum;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Strict reads of the host's non-boolean geolocation settings. An absent (null) value takes
 * the default; a present but invalid one throws {@see InvalidConfigurationException} naming
 * the key, so a typo never silently becomes a different setting (a timeout of `five` used
 * to be cast to 0 — no timeout at all).
 *
 * The value-taking helpers validate a value the caller already read, so each call site
 * keeps its literal `config('geolocation.…')` read.
 *
 * @internal
 */
final class GeolocationConfig
{
    /**
     * The HTTP timeout in seconds: a per-call `timeout` override when one is set, otherwise
     * `geolocation.timeout`. Never below 1 — Laravel reads a timeout of 0 as "wait forever".
     */
    public static function timeout(mixed $override = null): int
    {
        if ($override !== null) {
            return self::integer('timeout override', $override, 5, min: 1);
        }

        return Config::integer('geolocation.timeout', 5, min: 1);
    }

    /**
     * An integer the caller read from config: the default when null, otherwise an int or a
     * canonical integer string within the bounds.
     */
    public static function integer(string $key, mixed $value, int $default, ?int $min = null, ?int $max = null): int
    {
        return Config::for([$key => $value])->integer($key, $default, $min, $max);
    }

    /**
     * An optional integer: null stays null (the feature is off), anything else must be a
     * canonical integer within the bounds.
     */
    public static function optionalInteger(string $key, mixed $value, ?int $min = null): ?int
    {
        return $value === null ? null : self::integer($key, $value, 0, $min);
    }

    /**
     * A string setting: the default when null, otherwise a non-empty string.
     */
    public static function string(string $key, mixed $value, string $default): string
    {
        if ($value === null) {
            return $default;
        }

        if (! is_string($value) || trim($value) === '') {
            throw InvalidConfigurationException::notAString($key, $value);
        }

        return $value;
    }

    /**
     * An optional string setting (a cache store): null stays null, anything else must be a
     * non-empty string.
     */
    public static function optionalString(string $key, mixed $value): ?string
    {
        return $value === null ? null : self::string($key, $value, '');
    }

    /**
     * @param  non-empty-list<string>  $allowed
     */
    public static function oneOf(string $key, mixed $value, array $allowed, string $default): string
    {
        return Config::for([$key => $value])->oneOf($key, $allowed, $default);
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @param  TEnum  $default
     * @return TEnum
     */
    public static function enum(string $key, mixed $value, string $enum, BackedEnum $default): BackedEnum
    {
        return Config::for([$key => $value])->enum($key, $enum, $default);
    }

    /**
     * A coordinate: the default when null, otherwise an int, a float or a decimal string
     * (env values are strings) within `[-$bound, $bound]`. `north`, `''`, `0x1A`, `1e3`
     * and booleans throw — they used to be cast to 0, placing the default on Null Island.
     */
    public static function coordinate(string $key, mixed $value, float $bound): float
    {
        if ($value === null) {
            return 0.0;
        }

        $number = match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && preg_match('/^\s*-?\d+(\.\d+)?\s*$/', $value) === 1 => (float) $value,
            default => null,
        };

        if ($number === null || $number < -$bound || $number > $bound) {
            throw new InvalidConfigurationException(sprintf(
                'Configuration value [%s] must be a number between -%s and %s, [%s] given.',
                $key,
                $bound,
                $bound,
                is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            ));
        }

        return $number;
    }
}
