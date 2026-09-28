<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Exceptions;

use Throwable;

/**
 * Thrown by `Geolocation::updateDatabase()` when the MaxMind database cannot be downloaded
 * or unpacked. The message is written for a human reading a console or a log.
 */
final class DatabaseUpdateException extends GeolocationException
{
    public static function missingLicenseKey(): self
    {
        return new self(
            'A MaxMind license key is required. Set MAXMIND_LICENSE_KEY '
            .'(geolocation.services.maxmind_database.license_key).',
        );
    }

    public static function missingPath(): self
    {
        return new self(
            'No destination path is configured. Set MAXMIND_DB_PATH '
            .'(geolocation.services.maxmind_database.path) or pass a path.',
        );
    }

    public static function downloadFailed(Throwable $previous): self
    {
        return new self("Download failed: {$previous->getMessage()}", previous: $previous);
    }

    public static function unpackFailed(Throwable $previous): self
    {
        return new self("Could not unpack the database: {$previous->getMessage()}", previous: $previous);
    }
}
