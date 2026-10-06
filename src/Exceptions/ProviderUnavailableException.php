<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Exceptions;

use RoundlyConsulting\Geolocation\Support\Redactor;
use Throwable;

/**
 * Thrown by an HTTP-backed provider when its API cannot be reached (timeout, DNS failure,
 * refused connection) or refuses the request as a whole. The manager catches it, records it and moves on to the next provider
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

    /**
     * The API answered but refused the request as a whole (a denied or restricted key, an
     * exhausted quota, an API not enabled on the key) — as useless to the pipeline as an
     * unreachable API, and worth saying out loud instead of looking like "no match".
     *
     * @param  list<mixed>  $secrets  credential values to scrub from the reason
     */
    public static function rejected(string $provider, string $reason, array $secrets = []): self
    {
        return new self(
            "Geolocation provider [{$provider}] is unavailable: ".Redactor::redact($reason, $secrets),
            $provider,
        );
    }
}
