<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Exceptions;

final class UnknownProviderException extends GeolocationException
{
    public static function named(string $name): self
    {
        return new self("No geolocation provider is registered under the name [{$name}].");
    }
}
