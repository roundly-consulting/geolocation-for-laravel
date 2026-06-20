<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Tests\FakeProviders;

use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DistanceProvider;
use RoundlyConsulting\Geolocation\Enum\DistanceType;

class FakeAlternativeDistanceProvider implements DistanceProvider
{
    public function distance(DistanceQuery $query): ?Distance
    {
        return new Distance(
            humanReadableDistance: '15km',
            distanceInMeters: 15000,
            humanReadableDuration: '1min',
            durationInSeconds: 60,
            type: DistanceType::Driving,
        );
    }
}
