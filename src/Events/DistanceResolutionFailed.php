<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Events;

use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use Throwable;

/**
 * Dispatched whenever `distance()` / `distanceBetween()` ends without a distance — the
 * distance counterpart of LocationResolutionFailed. `$provider` / `$error` name the last
 * provider that was unreachable or refused the request (its error redacted), or a provider
 * that threw anything else (then rethrown); both are null when every provider simply missed.
 */
final readonly class DistanceResolutionFailed
{
    public function __construct(
        public DistanceQuery $query,
        public ?string $provider = null,
        public ?Throwable $error = null,
    ) {}
}
