<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Tests\FakeProviders;

use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DistanceProvider;
use RoundlyConsulting\Geolocation\Enum\DistanceType;

class FakeDistanceProvider implements DistanceProvider
{
    public function distance(DistanceQuery $query): ?Distance
    {
        return new Distance(
            humanReadableDistance: '10km',
            distanceInMeters: 10000,
            humanReadableDuration: '10min',
            durationInSeconds: 600,
            type: DistanceType::Walking,
        );
    }
}
