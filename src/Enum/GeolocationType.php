<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Enum;

use Illuminate\Support\Str;
use RoundlyConsulting\Enums\Helpers;

enum GeolocationType: string
{
    use Helpers;

    case Default = 'Default';
    case Ip = 'IP';
    case Geolocation = 'Geolocation';

    /**
     * Preserve the "IP" acronym; other cases keep the trait's headline label.
     */
    public function readable(): string
    {
        return (string) __(match ($this) {
            self::Ip => 'IP',
            default => Str::headline($this->value),
        });
    }
}
