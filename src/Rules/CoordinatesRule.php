<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a "lat,lng" string or a [lat, lng] / ['latitude' => …, 'longitude' => …] pair
 * as a geographic coordinate within valid latitude/longitude ranges.
 */
final class CoordinatesRule implements ValidationRule
{
    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        [$latitude, $longitude] = $this->extract($value);

        if ($latitude === null || $longitude === null || ! is_numeric($latitude) || ! is_numeric($longitude)) {
            $fail(trans('geolocation::validation.coordinates'));

            return;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            $fail(trans('geolocation::validation.coordinates'));
        }
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private function extract(mixed $value): array
    {
        if (is_string($value) && str_contains($value, ',')) {
            $parts = array_map('trim', explode(',', $value, 2));

            return [$parts[0], $parts[1]];
        }

        if (is_array($value)) {
            $latitude = $value['latitude'] ?? $value['lat'] ?? $value[0] ?? null;
            $longitude = $value['longitude'] ?? $value['lng'] ?? $value['lon'] ?? $value[1] ?? null;

            return [$latitude, $longitude];
        }

        return [null, null];
    }
}
