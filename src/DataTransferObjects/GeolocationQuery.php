<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\DataTransferObjects;

final readonly class GeolocationQuery
{
    public function __construct(
        public ?string $ipAddress = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {}
}
