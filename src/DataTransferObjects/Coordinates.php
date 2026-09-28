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
     * Point-in-polygon test (ray casting in latitude/longitude space) against a closed
     * polygon described by its vertices. The ring need not repeat the first point at the end.
     *
     * Every edge is taken the short way round, so a polygon drawn across the antimeridian
     * (170° → -170°) works; a polygon that encloses a pole is not supported.
     *
     * @param  list<self>  $polygon
     */
    public function within(array $polygon): bool
    {
        if (count($polygon) < 3) {
            return false;
        }

        $ring = [];
        $previous = null;

        foreach ($polygon as $vertex) {
            $longitude = $previous === null ? $vertex->longitude : self::nearestTurn($vertex->longitude, $previous);
            $ring[] = [$vertex->latitude, $longitude];
            $previous = $longitude;
        }

        // The unwrapped ring may sit up to one turn east or west of the point's longitude.
        foreach ([0.0, 360.0, -360.0] as $shift) {
            if (self::insideRing($ring, $this->latitude, $this->longitude + $shift)) {
                return true;
            }
        }

        return false;
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
     * The geographic midpoint along the great circle to another point (longitude normalised
     * into -180..180, so a pair straddling the antimeridian works).
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

        return new self(
            max(-90.0, min(90.0, rad2deg($latMid))),
            self::normaliseLongitude(rad2deg($lonMid)),
        );
    }

    /**
     * The smallest latitude/longitude box that contains every point within the given radius
     * (kilometres) of this one — a cheap pre-filter before an exact distance check.
     *
     * Near the antimeridian the box wraps: its south-west longitude is then greater than its
     * north-east one ({@see BoundingBox::crossesAntimeridian()}). When the circle reaches a
     * pole the box spans every longitude.
     */
    public function boundingBox(float $radiusKm): BoundingBox
    {
        $angular = max(0.0, $radiusKm * 1000) / self::EARTH_RADIUS_METERS;
        $latitude = deg2rad($this->latitude);
        $minLatitude = $latitude - $angular;
        $maxLatitude = $latitude + $angular;

        if ($minLatitude > -M_PI_2 && $maxLatitude < M_PI_2) {
            // The circle's widest longitude reach: asin(sin r / cos φ), not r / cos φ.
            $ratio = sin($angular) / cos($latitude);

            if ($ratio < 1.0) {
                $lonDelta = rad2deg(asin($ratio));

                return new BoundingBox(
                    southWest: new self(rad2deg($minLatitude), self::normaliseLongitude($this->longitude - $lonDelta)),
                    northEast: new self(rad2deg($maxLatitude), self::normaliseLongitude($this->longitude + $lonDelta)),
                );
            }
        }

        // The circle reaches a pole: it contains every longitude up there.
        return new BoundingBox(
            southWest: new self(max(-90.0, rad2deg($minLatitude)), -180.0),
            northEast: new self(min(90.0, rad2deg($maxLatitude)), 180.0),
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

    /**
     * Fold a longitude into -180..180 (180 itself stays 180).
     */
    private static function normaliseLongitude(float $longitude): float
    {
        if ($longitude >= -180.0 && $longitude <= 180.0) {
            return $longitude;
        }

        return fmod(fmod($longitude + 180.0, 360.0) + 360.0, 360.0) - 180.0;
    }

    /**
     * $longitude shifted by whole turns to lie within 180° of $reference.
     */
    private static function nearestTurn(float $longitude, float $reference): float
    {
        while ($longitude - $reference > 180.0) {
            $longitude -= 360.0;
        }

        while ($longitude - $reference < -180.0) {
            $longitude += 360.0;
        }

        return $longitude;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring  [latitude, longitude] vertices
     */
    private static function insideRing(array $ring, float $latitude, float $longitude): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$yi, $xi] = $ring[$i];
            [$yj, $xj] = $ring[$j];

            $intersects = (($yi > $latitude) !== ($yj > $latitude))
                && ($longitude < ($xj - $xi) * ($latitude - $yi) / ($yj - $yi) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
