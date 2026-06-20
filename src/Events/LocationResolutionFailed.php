<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Events;

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use Throwable;

final readonly class LocationResolutionFailed
{
    public function __construct(
        public GeolocationQuery $query,
        public ?string $provider = null,
        public ?Throwable $error = null,
    ) {}
}
