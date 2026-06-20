<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\DataTransferObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class Location implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $humanReadable,
        public string $street,
        public string $city,
        public string $countryIsoCode,
        public float $latitude,
        public float $longitude,
        public GeolocationType $type,
        public string $region = '',
        public string $postalCode = '',
        public string $timezone = '',
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

    public function coordinates(): Coordinates
    {
        return new Coordinates($this->latitude, $this->longitude);
    }

    /**
     * @return array{humanReadable: string, street: string, city: string, region: string, postalCode: string, countryIsoCode: string, latitude: float, longitude: float, timezone: string, type: string}
     */
    public function toArray(): array
    {
        return [
            'humanReadable' => $this->humanReadable,
            'street' => $this->street,
            'city' => $this->city,
            'region' => $this->region,
            'postalCode' => $this->postalCode,
            'countryIsoCode' => $this->countryIsoCode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'timezone' => $this->timezone,
            'type' => $this->type->value,
        ];
    }

    /**
     * @return array{humanReadable: string, street: string, city: string, region: string, postalCode: string, countryIsoCode: string, latitude: float, longitude: float, timezone: string, type: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
