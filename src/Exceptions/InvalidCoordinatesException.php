<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Exceptions;

final class InvalidCoordinatesException extends GeolocationException
{
    public static function latitude(float $latitude): self
    {
        return new self("Latitude [{$latitude}] is out of the range [-90, 90].");
    }

    public static function longitude(float $longitude): self
    {
        return new self("Longitude [{$longitude}] is out of the range [-180, 180].");
    }

    public static function notCoordinates(): self
    {
        return new self('The coordinates attribute must be set to a Coordinates instance or null.');
    }
}
