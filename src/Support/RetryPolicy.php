<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * The `when:` filter every bundled HTTP provider hands to Laravel's `retry()`: only a
 * transient failure — a connection error or a 5xx — is worth sending again. A 4xx (bad key,
 * unknown IP, 429) answers the same on every attempt, and a re-sent 429 would hammer an API
 * that just asked us to back off.
 *
 * @internal
 */
final class RetryPolicy
{
    public static function transient(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->serverError());
    }
}
