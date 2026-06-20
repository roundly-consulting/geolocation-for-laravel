<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Events;

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;

final readonly class LocationResolved
{
    public function __construct(
        public GeolocationQuery $query,
        public Location $location,
        public string $provider,
    ) {}
}
