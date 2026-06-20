<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Enum;

enum DistanceType: string
{
    case Walking = 'Walking';
    case Driving = 'Driving';
}
