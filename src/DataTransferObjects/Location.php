<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\DataTransferObjects;

use RoundlyConsulting\Geolocation\Enum\GeolocationType;

final readonly class Location
{
    public function __construct(
        public string $humanReadable,
        public string $street,
        public string $city,
        public string $countryIsoCode,
        public float $latitude,
        public float $longitude,
        public GeolocationType $type,
    ) {}

    /**
     * @param  array{humanReadable: string, street: string, city: string, country: string, latitude: float|int|string, longitude: float|int|string}  $config
     */
    public static function createFromDefaults(array $config): self
    {
        return new self(
            humanReadable: $config['humanReadable'],
            street: $config['street'],
            city: $config['city'],
            countryIsoCode: $config['country'],
            latitude: (float) $config['latitude'],
            longitude: (float) $config['longitude'],
            type: GeolocationType::Default,
        );
    }
}
