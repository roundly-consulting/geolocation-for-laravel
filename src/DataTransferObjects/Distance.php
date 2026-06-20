<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\DataTransferObjects;

use RoundlyConsulting\Geolocation\Enum\DistanceType;

final readonly class Distance
{
    public function __construct(
        public string $humanReadableDistance,
        public int $distanceInMeters,
        public string $humanReadableDuration,
        public int $durationInSeconds,
        public DistanceType $type,
    ) {}
}
