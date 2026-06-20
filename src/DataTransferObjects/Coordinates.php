<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\DataTransferObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use RoundlyConsulting\Geolocation\Exceptions\InvalidCoordinatesException;

/**
 * @implements Arrayable<string, float>
 */
final readonly class Coordinates implements Arrayable, JsonSerializable
{
    private const EARTH_RADIUS_METERS = 6_371_000.0;

    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw InvalidCoordinatesException::latitude($latitude);
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            throw InvalidCoordinatesException::longitude($longitude);
        }
    }

    public static function make(float $latitude, float $longitude): self
    {
        return new self($latitude, $longitude);
    }

    /**
     * Great-circle distance to another point, in metres (Haversine).
     */
    public function distanceTo(self $other): float
    {
        $latFrom = deg2rad($this->latitude);
        $latTo = deg2rad($other->latitude);
        $latDelta = deg2rad($other->latitude - $this->latitude);
        $lonDelta = deg2rad($other->longitude - $this->longitude);

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * @return array{latitude: float, longitude: float}
     */
    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    /**
     * @return array{latitude: float, longitude: float}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
