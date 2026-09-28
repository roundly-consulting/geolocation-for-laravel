<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Support;

/**
 * Scrubs credentials out of transport error messages before they reach an exception, an
 * event or a log: a failed request's message ends in its full URL, and Google, IP2Location
 * and the MaxMind download all carry their key in the query string.
 *
 * @internal
 */
final class Redactor
{
    private const REDACTED = '[redacted]';

    /**
     * @param  list<mixed>  $secrets  known credential values to blank out wherever they appear
     */
    public static function redact(string $message, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }

            $message = str_replace([$secret, rawurlencode($secret), urlencode($secret)], self::REDACTED, $message);
        }

        return preg_replace(
            '/([?&](?:key|api_key|license_key|token|access_token)=)[^&\s#]*/i',
            '$1'.self::REDACTED,
            $message,
        ) ?? $message;
    }
}
