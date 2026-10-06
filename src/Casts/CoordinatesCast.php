<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\Exceptions\InvalidCoordinatesException;

/**
 * Stores and restores a {@see Coordinates} value object transparently across two columns
 * (defaults: `latitude` and `longitude`). Declare on a model via:
 *
 *   protected $casts = ['coordinates' => Coordinates::class];
 *
 * @implements CastsAttributes<Coordinates, mixed>
 */
final class CoordinatesCast implements CastsAttributes
{
    /**
     * The point is derived from two columns: a cached object would go stale when either
     * column changes, and save() would merge it back over the newer values.
     */
    public bool $withoutObjectCaching = true;

    public function __construct(
        private readonly string $latitudeColumn = 'latitude',
        private readonly string $longitudeColumn = 'longitude',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Coordinates
    {
        $latitude = $attributes[$this->latitudeColumn] ?? null;
        $longitude = $attributes[$this->longitudeColumn] ?? null;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return new Coordinates((float) $latitude, (float) $longitude);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, float|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [
                $this->latitudeColumn => null,
                $this->longitudeColumn => null,
            ];
        }

        if (! $value instanceof Coordinates) {
            throw InvalidCoordinatesException::notCoordinates();
        }

        return [
            $this->latitudeColumn => $value->latitude,
            $this->longitudeColumn => $value->longitude,
        ];
    }
}
