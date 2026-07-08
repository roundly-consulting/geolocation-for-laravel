<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Exceptions;

/**
 * Thrown when a provider's client-side rate limiter would defer a request past
 * its configured `max_wait` ceiling — the opt-in fail-fast path. Extends the
 * package's base GeolocationException so `catch (GeolocationException)` keeps
 * catching everything.
 */
final class RateLimitExceededException extends GeolocationException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly int $availableInSeconds,
    ) {
        parent::__construct($message);
    }

    public static function for(string $provider, int $availableInSeconds): self
    {
        return new self(
            "Rate limit for provider [{$provider}] exceeded. Retry in {$availableInSeconds} second(s).",
            $provider,
            $availableInSeconds,
        );
    }
}
