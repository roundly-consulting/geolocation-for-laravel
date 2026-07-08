<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Enum;

use RoundlyConsulting\Enums\Helpers;

enum DistanceType: string
{
    use Helpers;

    case Walking = 'Walking';
    case Driving = 'Driving';
}
