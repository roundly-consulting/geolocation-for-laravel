<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Enum;

enum GeolocationType: string
{
    case Default = 'Default';
    case Ip = 'IP';
    case Geolocation = 'Geolocation';
}
