<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Support;

/**
 * A per-resolution bag of provider configuration overrides (token, timeout, …) set by the
 * manager via withToken()/withTimeout()/withConfig() and read by providers, so a single
 * lookup can tweak provider settings without mutating global config.
 */
final class ProviderOverrides
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * @param  array<string, mixed>  $values
     */
    public function merge(array $values): void
    {
        $this->values = array_merge($this->values, $values);
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function reset(): void
    {
        $this->values = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
