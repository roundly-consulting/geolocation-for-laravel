<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\DataTransferObjects;

final readonly class GeolocationQuery
{
    public function __construct(
        public ?string $ipAddress = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $address = null,
    ) {}

    public static function forIp(string $ipAddress): self
    {
        return new self(ipAddress: $ipAddress);
    }

    public static function forCoordinates(Coordinates $coordinates): self
    {
        return new self(
            latitude: $coordinates->latitude,
            longitude: $coordinates->longitude,
        );
    }

    public static function forAddress(string $address): self
    {
        return new self(address: $address);
    }

    public function coordinates(): ?Coordinates
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return new Coordinates($this->latitude, $this->longitude);
    }

    public function cacheKey(): string
    {
        return sha1(implode('|', [
            $this->ipAddress ?? '',
            $this->latitude ?? '',
            $this->longitude ?? '',
            $this->address ?? '',
        ]));
    }
}
