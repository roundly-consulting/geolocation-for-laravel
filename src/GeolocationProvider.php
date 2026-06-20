<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation;

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;

interface GeolocationProvider
{
    public function locate(GeolocationQuery $query): ?Location;
}
