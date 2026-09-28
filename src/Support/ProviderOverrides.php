<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Support;

use Closure;

/**
 * The call-time provider configuration overrides (token, timeout, …) of the resolution that
 * is running right now, set by the manager for exactly the duration of one call and read by
 * providers through HasProviderOverrides — so a single lookup can tweak provider settings
 * without mutating global config.
 *
 * @internal
 */
final class ProviderOverrides
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * Run $callback with $values as the active overrides, then put back whatever was active
     * before — even when the callback throws — so nothing outlives the call it was set for
     * (and a lookup nested inside another one sees only its own overrides).
     *
     * @template TResult
     *
     * @param  array<string, mixed>  $values
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function during(array $values, Closure $callback): mixed
    {
        $previous = $this->values;
        $this->values = $values;

        try {
            return $callback();
        } finally {
            $this->values = $previous;
        }
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
