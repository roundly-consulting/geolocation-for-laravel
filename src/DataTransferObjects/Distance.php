<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\DataTransferObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use RoundlyConsulting\Geolocation\Enum\DistanceType;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class Distance implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $humanReadableDistance,
        public int $distanceInMeters,
        public string $humanReadableDuration,
        public int $durationInSeconds,
        public DistanceType $type,
    ) {}

    /**
     * @return array{humanReadableDistance: string, distanceInMeters: int, humanReadableDuration: string, durationInSeconds: int, type: string}
     */
    public function toArray(): array
    {
        return [
            'humanReadableDistance' => $this->humanReadableDistance,
            'distanceInMeters' => $this->distanceInMeters,
            'humanReadableDuration' => $this->humanReadableDuration,
            'durationInSeconds' => $this->durationInSeconds,
            'type' => $this->type->value,
        ];
    }

    /**
     * @return array{humanReadableDistance: string, distanceInMeters: int, humanReadableDuration: string, durationInSeconds: int, type: string}
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

        $type = is_string($payload['type'] ?? null) ? DistanceType::tryFrom($payload['type']) : null;

        if ($type === null) {
            return null;
        }

        if (! is_string($payload['humanReadableDistance'] ?? null) || ! is_string($payload['humanReadableDuration'] ?? null)) {
            return null;
        }

        if (! is_int($payload['distanceInMeters'] ?? null) || ! is_int($payload['durationInSeconds'] ?? null)) {
            return null;
        }

        return new self(
            humanReadableDistance: $payload['humanReadableDistance'],
            distanceInMeters: $payload['distanceInMeters'],
            humanReadableDuration: $payload['humanReadableDuration'],
            durationInSeconds: $payload['durationInSeconds'],
            type: $type,
        );
    }
}
