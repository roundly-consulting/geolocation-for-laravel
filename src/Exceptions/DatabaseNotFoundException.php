<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Exceptions;

final class DatabaseNotFoundException extends GeolocationException
{
    public static function forPath(?string $path): self
    {
        return new self(
            $path === null || $path === ''
                ? 'No MaxMind database path is configured (geolocation.services.maxmind.database.path).'
                : "The MaxMind database at [{$path}] does not exist or is not readable.",
        );
    }
}
