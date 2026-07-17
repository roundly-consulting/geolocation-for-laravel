<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Exceptions;

final class DatabaseNotFoundException extends GeolocationException
{
    public static function forPath(?string $path): self
    {
        return new self(
            $path === null || $path === ''
                ? 'No MaxMind database path is configured (geolocation.services.maxmind_database.path).'
                : "The MaxMind database at [{$path}] does not exist or is not readable.",
        );
    }

    public static function missing(?string $path): self
    {
        $location = $path === null || $path === ''
            ? 'No MaxMind database path is configured (geolocation.services.maxmind_database.path).'
            : "The MaxMind database at [{$path}] does not exist.";

        return new self(
            $location.' Download it by configuring a MaxMind account ID and license key, '
            .'then running: php artisan geolocation:db:update',
        );
    }
}
