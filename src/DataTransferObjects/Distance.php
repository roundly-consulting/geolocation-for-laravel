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
}
