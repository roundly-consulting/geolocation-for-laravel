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
 * Overrides are either shared (every provider sees them — only `withTimeout()`) or scoped to
 * one provider name (`withToken()`, `withConfig()`), and a provider only ever sees the scoped
 * values of the pipeline entry the manager has activated — so one vendor's credential never
 * reaches another vendor's API.
 *
 * @internal
 */
final class ProviderOverrides
{
    /**
     * @var array<string, mixed>
     */
    private array $shared = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $scoped = [];

    private ?string $active = null;

    /**
     * Run $callback with these overrides active, then put back whatever was active before —
     * even when the callback throws — so nothing outlives the call it was set for (and a
     * lookup nested inside another one sees only its own overrides).
     *
     * @template TResult
     *
     * @param  array<string, mixed>  $shared
     * @param  array<string, array<string, mixed>>  $scoped  provider name => overrides
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function during(array $shared, array $scoped, Closure $callback): mixed
    {
        [$previousShared, $previousScoped, $previousActive] = [$this->shared, $this->scoped, $this->active];
        [$this->shared, $this->scoped, $this->active] = [$shared, $scoped, null];

        try {
            return $callback();
        } finally {
            [$this->shared, $this->scoped, $this->active] = [$previousShared, $previousScoped, $previousActive];
        }
    }

    /**
     * Mark the pipeline entry about to be asked, so get() serves its scoped overrides only.
     */
    public function activate(?string $provider): void
    {
        $this->active = $provider;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The overrides the active provider sees: shared ones, with its own scoped ones on top.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $scoped = $this->active !== null ? ($this->scoped[$this->active] ?? []) : [];

        return array_merge($this->shared, $scoped);
    }
}
