<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation;

use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;

interface DistanceProvider
{
    public function distance(DistanceQuery $query): ?Distance;
}
