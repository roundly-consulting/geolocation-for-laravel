<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Concerns;

use RoundlyConsulting\Geolocation\Support\ProviderOverrides;

/**
 * Lets a provider read the call-time overrides (token, timeout, arbitrary config) that the
 * manager set for the current resolution via withToken()/withTimeout()/withConfig().
 */
trait HasProviderOverrides
{
    protected function override(string $key): mixed
    {
        if (! app()->bound(ProviderOverrides::class)) {
            return null;
        }

        return app(ProviderOverrides::class)->get($key);
    }
}
