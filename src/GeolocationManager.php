<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Events\DistanceResolved;
use RoundlyConsulting\Geolocation\Events\LocationResolutionFailed;
use RoundlyConsulting\Geolocation\Events\LocationResolved;
use RoundlyConsulting\Geolocation\Exceptions\UnknownProviderException;

class GeolocationManager
{
    /**
     * Custom providers registered at runtime by the host application.
     *
     * @var array<string, Closure(): object>
     */
    private array $extensions = [];

    /**
     * When non-null, restricts the next resolution to these provider names only.
     *
     * @var list<string>|null
     */
    private ?array $only = null;

    public function locate(GeolocationQuery $query): ?Location
    {
        if ($this->cacheEnabled()) {
            $cached = $this->cache()->get($this->cacheKey('locate', $query->cacheKey()));

            if ($cached instanceof Location) {
                return $cached;
            }
        }

        $resolved = $this->resolveLocation($query);

        if ($resolved instanceof Location && $this->cacheEnabled()) {
            $this->cache()->put($this->cacheKey('locate', $query->cacheKey()), $resolved, $this->cacheTtl());
        }

        $this->only = null;

        return $resolved;
    }

    public function distance(DistanceQuery $query): ?Distance
    {
        if ($this->cacheEnabled()) {
            $cached = $this->cache()->get($this->cacheKey('distance', $query->cacheKey()));

            if ($cached instanceof Distance) {
                $this->only = null;

                return $cached;
            }
        }

        $resolved = null;
        $providerName = null;

        foreach ($this->resolveProviders() as $name => $provider) {
            if (! $provider instanceof DistanceProvider) {
                continue;
            }

            $result = $provider->distance($query);

            if ($result instanceof Distance) {
                $resolved = $result;
                $providerName = $name;
                break;
            }
        }

        if ($resolved instanceof Distance) {
            if ($this->cacheEnabled()) {
                $this->cache()->put($this->cacheKey('distance', $query->cacheKey()), $resolved, $this->cacheTtl());
            }

            $this->dispatch(new DistanceResolved($query, $resolved, (string) $providerName));
        }

        $this->only = null;

        return $resolved;
    }

    public function locateIp(string $ip): ?Location
    {
        return $this->locate(GeolocationQuery::forIp($ip));
    }

    public function locateAddress(string $address): ?Location
    {
        return $this->locate(GeolocationQuery::forAddress($address));
    }

    public function locateCoordinates(Coordinates $coordinates): ?Location
    {
        return $this->locate(GeolocationQuery::forCoordinates($coordinates));
    }

    public function locateRequest(?Request $request = null): ?Location
    {
        $ip = ($request ?? request())->ip();

        if ($ip === null || $ip === '') {
            return null;
        }

        return $this->locateIp($ip);
    }

    /**
     * Register a custom provider resolved by a factory closure.
     */
    public function extend(string $name, Closure $factory): self
    {
        $this->extensions[$name] = $factory;

        return $this;
    }

    /**
     * Scope the next resolution to the given provider names only.
     */
    public function using(string ...$providers): self
    {
        $this->only = array_values($providers);

        return $this;
    }

    private function resolveLocation(GeolocationQuery $query): ?Location
    {
        foreach ($this->resolveProviders() as $name => $provider) {
            if (! $provider instanceof GeolocationProvider) {
                continue;
            }

            $location = $provider->locate($query);

            if ($location instanceof Location) {
                $this->dispatch(new LocationResolved($query, $location, $name));

                return $location;
            }
        }

        $this->dispatch(new LocationResolutionFailed($query));

        return null;
    }

    /**
     * @return iterable<string, object>
     */
    private function resolveProviders(): iterable
    {
        foreach ($this->providerNames() as $name) {
            yield $name => $this->resolveProvider($name);
        }
    }

    private function resolveProvider(string $name): object
    {
        if (isset($this->extensions[$name])) {
            return ($this->extensions[$name])();
        }

        $class = $this->configuredProviders()[$name] ?? null;

        if ($class === null) {
            throw UnknownProviderException::named($name);
        }

        return resolve($class);
    }

    /**
     * The ordered list of provider names to consult.
     *
     * @return list<string>
     */
    private function providerNames(): array
    {
        if ($this->only !== null) {
            return $this->only;
        }

        $available = array_keys($this->configuredProviders());

        /** @var mixed $pipeline */
        $pipeline = config('geolocation.pipeline');

        if (is_array($pipeline) && $pipeline !== []) {
            $names = array_values(array_filter(
                array_map(static fn (mixed $name): string => (string) $name, $pipeline),
                fn (string $name): bool => in_array($name, $available, true) || isset($this->extensions[$name]),
            ));

            // The configured pipeline applies only when it actually matches the available
            // providers; a custom/legacy "providers" config falls back to its own order.
            if ($names !== []) {
                return $names;
            }
        }

        return $available;
    }

    /**
     * The name => class-string provider map, accepting both the named-map form and the
     * legacy flat list form (where each class-string is its own name).
     *
     * @return array<string, class-string>
     */
    private function configuredProviders(): array
    {
        /** @var array<int|string, string> $providers */
        $providers = config('geolocation.providers', []);

        $map = [];

        foreach ($providers as $name => $class) {
            $map[is_int($name) ? $class : (string) $name] = $class;
        }

        /** @var array<string, class-string> $map */
        return $map;
    }

    private function dispatch(object $event): void
    {
        if ((bool) config('geolocation.events.enabled', true)) {
            Event::dispatch($event);
        }
    }

    private function cacheEnabled(): bool
    {
        return $this->only === null && (bool) config('geolocation.cache.enabled', false);
    }

    private function cache(): Repository
    {
        /** @var string|null $store */
        $store = config('geolocation.cache.store');

        return Cache::store($store);
    }

    private function cacheTtl(): int
    {
        return (int) config('geolocation.cache.ttl', 86400);
    }

    private function cacheKey(string $kind, string $hash): string
    {
        $prefix = (string) config('geolocation.cache.prefix', 'geolocation');

        return "{$prefix}:{$kind}:{$hash}";
    }
}
