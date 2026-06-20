<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Exceptions;

final class InvalidDatabaseException extends GeolocationException
{
    public static function missingMetadata(): self
    {
        return new self('The MaxMind database is corrupt: metadata marker not found.');
    }

    public static function truncated(): self
    {
        return new self('The MaxMind database is corrupt: unexpected end of file.');
    }

    public static function malformed(string $reason): self
    {
        return new self("The MaxMind database is corrupt: {$reason}.");
    }
}
