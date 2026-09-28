<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\DataTransferObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * An axis-aligned latitude/longitude rectangle, typically derived from a centre point and
 * a radius. Useful as a cheap pre-filter (e.g. a SQL WHERE) before an exact distance check.
 *
 * A box that wraps across the antimeridian has a south-west longitude GREATER than its
 * north-east one (e.g. 179.5 → -179.5): it covers longitudes >= the first OR <= the second.
 *
 * @implements Arrayable<string, float>
 */
final readonly class BoundingBox implements Arrayable, JsonSerializable
{
    public function __construct(
        public Coordinates $southWest,
        public Coordinates $northEast,
    ) {}

    public function contains(Coordinates $point): bool
    {
        if ($point->latitude < $this->southWest->latitude || $point->latitude > $this->northEast->latitude) {
            return false;
        }

        if ($this->crossesAntimeridian()) {
            return $point->longitude >= $this->southWest->longitude
                || $point->longitude <= $this->northEast->longitude;
        }

        return $point->longitude >= $this->southWest->longitude
            && $point->longitude <= $this->northEast->longitude;
    }

    /**
     * Whether the box wraps across the ±180° meridian.
     */
    public function crossesAntimeridian(): bool
    {
        return $this->southWest->longitude > $this->northEast->longitude;
    }

    /**
     * @return array{minLatitude: float, minLongitude: float, maxLatitude: float, maxLongitude: float}
     */
    public function toArray(): array
    {
        return [
            'minLatitude' => $this->southWest->latitude,
            'minLongitude' => $this->southWest->longitude,
            'maxLatitude' => $this->northEast->latitude,
            'maxLongitude' => $this->northEast->longitude,
        ];
    }

    /**
     * @return array{minLatitude: float, minLongitude: float, maxLatitude: float, maxLongitude: float}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
