<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\DataTransferObjects;

use RoundlyConsulting\Geolocation\Enum\DistanceType;

final readonly class DistanceQuery
{
    public function __construct(
        public float $fromLatitude,
        public float $fromLongitude,
        public float $toLatitude,
        public float $toLongitude,
        public DistanceType $type,
    ) {}

    public static function between(
        Coordinates $from,
        Coordinates $to,
        DistanceType $type = DistanceType::Driving,
    ): self {
        return new self(
            fromLatitude: $from->latitude,
            fromLongitude: $from->longitude,
            toLatitude: $to->latitude,
            toLongitude: $to->longitude,
            type: $type,
        );
    }

    public function from(): Coordinates
    {
        return new Coordinates($this->fromLatitude, $this->fromLongitude);
    }

    public function to(): Coordinates
    {
        return new Coordinates($this->toLatitude, $this->toLongitude);
    }

    public function cacheKey(): string
    {
        return sha1(implode('|', [
            $this->fromLatitude,
            $this->fromLongitude,
            $this->toLatitude,
            $this->toLongitude,
            $this->type->value,
        ]));
    }
}
