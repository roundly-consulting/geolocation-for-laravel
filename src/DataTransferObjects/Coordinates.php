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
     * Whether another point lies within the given radius (in kilometres) of this one.
     */
    public function near(self $other, float $radiusKm): bool
    {
        return $this->distanceTo($other) <= $radiusKm * 1000;
    }

    /**
     * Point-in-polygon test (ray casting) against a closed polygon described by its
     * vertices. The ring need not repeat the first point at the end.
     *
     * @param  list<self>  $polygon
     */
    public function within(array $polygon): bool
    {
        $count = count($polygon);

        if ($count < 3) {
            return false;
        }

        $inside = false;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $polygon[$i]->longitude;
            $yi = $polygon[$i]->latitude;
            $xj = $polygon[$j]->longitude;
            $yj = $polygon[$j]->latitude;

            $intersects = (($yi > $this->latitude) !== ($yj > $this->latitude))
                && ($this->longitude < ($xj - $xi) * ($this->latitude - $yi) / ($yj - $yi) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Initial bearing (degrees, 0–360 clockwise from north) from this point to another.
     */
    public function bearingTo(self $other): float
    {
        $latFrom = deg2rad($this->latitude);
        $latTo = deg2rad($other->latitude);
        $lonDelta = deg2rad($other->longitude - $this->longitude);

        $y = sin($lonDelta) * cos($latTo);
        $x = cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta);

        return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
    }

    /**
     * The geographic midpoint along the great circle to another point.
     */
    public function midpointTo(self $other): self
    {
        $latFrom = deg2rad($this->latitude);
        $latTo = deg2rad($other->latitude);
        $lonFrom = deg2rad($this->longitude);
        $lonDelta = deg2rad($other->longitude - $this->longitude);

        $bx = cos($latTo) * cos($lonDelta);
        $by = cos($latTo) * sin($lonDelta);

        $latMid = atan2(
            sin($latFrom) + sin($latTo),
            sqrt((cos($latFrom) + $bx) ** 2 + $by ** 2),
        );
        $lonMid = $lonFrom + atan2($by, cos($latFrom) + $bx);

        return new self(rad2deg($latMid), rad2deg($lonMid));
    }

    /**
     * An axis-aligned bounding box of the given radius (kilometres) around this point.
     */
    public function boundingBox(float $radiusKm): BoundingBox
    {
        $radius = $radiusKm * 1000;
        $latDelta = rad2deg($radius / self::EARTH_RADIUS_METERS);
        $cos = cos(deg2rad($this->latitude));
        $lonDelta = $cos > 0.0 ? rad2deg($radius / (self::EARTH_RADIUS_METERS * $cos)) : 180.0;

        return new BoundingBox(
            southWest: new self(
                max(-90.0, $this->latitude - $latDelta),
                max(-180.0, $this->longitude - $lonDelta),
            ),
            northEast: new self(
                min(90.0, $this->latitude + $latDelta),
                min(180.0, $this->longitude + $lonDelta),
            ),
        );
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
