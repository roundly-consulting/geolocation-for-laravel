<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Exceptions;

use RoundlyConsulting\Geolocation\Support\Redactor;
use Throwable;

/**
 * Thrown by an HTTP-backed provider when its API cannot be reached (timeout, DNS failure,
 * refused connection). The manager catches it, records it and moves on to the next provider
 * in the pipeline, so one unreachable API never aborts a lookup.
 *
 * The message is redacted and the transport exception is deliberately NOT chained as
 * `previous`: its message carries the full request URL, credentials included.
 */
final class ProviderUnavailableException extends GeolocationException
{
    public function __construct(
        string $message,
        public readonly string $provider,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<mixed>  $secrets  credential values to scrub from the cause's message
     */
    public static function for(string $provider, Throwable $cause, array $secrets = []): self
    {
        return new self(
            "Geolocation provider [{$provider}] is unavailable: ".Redactor::redact($cause->getMessage(), $secrets),
            $provider,
        );
    }
}
