<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Enum;

use RoundlyConsulting\Enums\Helpers;

enum GeolocationType: string
{
    use Helpers;

    case Default = 'Default';
    case Ip = 'IP';
    case Geolocation = 'Geolocation';
}
