<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Exceptions;

use RoundlyConsulting\PackageToolkit\Concerns\ProvidesRetryAfter;
use RoundlyConsulting\PackageToolkit\Contracts\HasRetryAfter;

/**
 * Thrown when a provider's client-side rate limiter would defer a request past
 * its configured `max_wait` ceiling — the opt-in fail-fast path. Extends the
 * package's base GeolocationException so `catch (GeolocationException)` keeps
 * catching everything, and carries the retry hint through the toolkit's
 * HasRetryAfter contract so hosts can turn it into a `Retry-After` header.
 */
final class RateLimitExceededException extends GeolocationException implements HasRetryAfter
{
    use ProvidesRetryAfter;

    public function __construct(
        string $message,
        public readonly string $provider,
    ) {
        parent::__construct($message);
    }

    public static function for(string $provider, int $retryAfterSeconds): self
    {
        $exception = new self(
            "Rate limit for provider [{$provider}] exceeded. Retry in {$retryAfterSeconds} second(s).",
            $provider,
        );

        return $exception->withRetryAfter($retryAfterSeconds);
    }
}
