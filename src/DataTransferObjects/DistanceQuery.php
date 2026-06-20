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
}
