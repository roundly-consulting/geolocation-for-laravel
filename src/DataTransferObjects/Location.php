<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\DataTransferObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Support\GeolocationConfig;

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
     * Build the `DefaultLocationProvider`'s answer from `geolocation.default`. The
     * coordinates are read strictly: a number or a decimal string within range, or the
     * read throws `InvalidConfigurationException` naming the key.
     *
     * @param  array{humanReadable: string, street: string, city: string, country: string, latitude: float|int|string|null, longitude: float|int|string|null}  $config
     */
    public static function createFromDefaults(array $config): self
    {
        return new self(
            humanReadable: $config['humanReadable'],
            street: $config['street'],
            city: $config['city'],
            countryIsoCode: $config['country'],
            latitude: GeolocationConfig::coordinate('geolocation.default.latitude', $config['latitude'], 90.0),
            longitude: GeolocationConfig::coordinate('geolocation.default.longitude', $config['longitude'], 180.0),
            type: GeolocationType::Default,
        );
    }

    public function coordinates(): Coordinates
    {
        return new Coordinates($this->latitude, $this->longitude);
    }

    /**
     * Whether this places nothing at all: no address part, no country and 0,0 coordinates
     * (a timezone alone does not count). The manager treats such an answer as a miss, so a
     * lookup never returns one.
     */
    public function isEmpty(): bool
    {
        return trim($this->humanReadable.$this->street.$this->city.$this->region.$this->postalCode.$this->countryIsoCode) === ''
            && $this->latitude === 0.0
            && $this->longitude === 0.0;
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

    /**
     * Rebuild from {@see toArray()}, or `null` when the payload is not one.
     *
     * The counterpart a cache needs: a store may refuse to unserialize classes
     * (Laravel's `cache.serializable_classes` defaults to `false`, guarding against
     * gadget chains if `APP_KEY` leaks), so an object put into one comes back a
     * `__PHP_Incomplete_Class`. Caching this array and reading it back through here
     * is the only shape that survives that.
     *
     * Tolerant on purpose: a payload written by an older version of this package is
     * a cache MISS, never an exception.
     */
    public static function tryFromArray(mixed $payload): ?self
    {
        if (! is_array($payload)) {
            return null;
        }

        $type = is_string($payload['type'] ?? null) ? GeolocationType::tryFrom($payload['type']) : null;

        if ($type === null || ! is_string($payload['humanReadable'] ?? null)) {
            return null;
        }

        foreach (['street', 'city', 'countryIsoCode', 'region', 'postalCode', 'timezone'] as $key) {
            if (! is_string($payload[$key] ?? null)) {
                return null;
            }
        }

        if (! is_numeric($payload['latitude'] ?? null) || ! is_numeric($payload['longitude'] ?? null)) {
            return null;
        }

        return new self(
            humanReadable: $payload['humanReadable'],
            street: $payload['street'],
            city: $payload['city'],
            countryIsoCode: $payload['countryIsoCode'],
            latitude: (float) $payload['latitude'],
            longitude: (float) $payload['longitude'],
            type: $type,
            region: $payload['region'],
            postalCode: $payload['postalCode'],
            timezone: $payload['timezone'],
        );
    }
}
