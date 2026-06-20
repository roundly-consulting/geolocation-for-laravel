<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Events;

use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;

final readonly class DistanceResolved
{
    public function __construct(
        public DistanceQuery $query,
        public Distance $distance,
        public string $provider,
    ) {}
}
