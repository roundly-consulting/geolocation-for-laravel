<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Traits\Macroable;
use RoundlyConsulting\Geolocation\Actions\UpdateDatabaseAction;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceMatrix;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Events\DistanceResolved;
use RoundlyConsulting\Geolocation\Events\LocationResolutionFailed;
use RoundlyConsulting\Geolocation\Events\LocationResolved;
use RoundlyConsulting\Geolocation\Exceptions\DatabaseUpdateException;
use RoundlyConsulting\Geolocation\Exceptions\UnknownProviderException;
use RoundlyConsulting\Geolocation\Providers\GoogleProvider;
use RoundlyConsulting\Geolocation\Support\ProviderOverrides;

/**
 * The package's public API: the root behind the Geolocation facade, injectable by its own
 * class-string and bound as a singleton. Lookups and distances run through the configured
 * provider pipeline (remote-API clients, so they stay provider objects rather than actions);
 * the MaxMind database refresh is an action resolved through the container.
 *
 * Not final on purpose: GeolocationFake extends it, so code that constructor-injects this
 * class still type-checks under `Geolocation::fake()`.
 */
class GeolocationManager
{
    use Macroable;

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

    /**
     * Call-time provider configuration overrides queued for the next resolution.
     *
     * @var array<string, mixed>
     */
    private array $overrides = [];

    public function __construct(
        protected readonly Container $container,
    ) {}

    public function locate(GeolocationQuery $query): ?Location
    {
        if ($this->cacheEnabled()) {
            // Read back through `tryFromArray`, because what is STORED is the array —
            // a cache store may refuse to unserialize classes (Laravel's
            // `cache.serializable_classes` defaults to `false`), which turned an
            // object put here into a `__PHP_Incomplete_Class` and made the
            // `instanceof` below false forever: a cache that never hit, silently
            // re-billing the provider for every lookup.
            $cached = Location::tryFromArray($this->cache()->get($this->cacheKey('locate', $query->cacheKey())));

            if ($cached instanceof Location) {
                $this->resetScope();

                return $cached;
            }
        }

        $this->applyOverrides();

        $resolved = $this->resolveLocation($query);

        if ($resolved instanceof Location && $this->cacheEnabled()) {
            $this->cache()->put($this->cacheKey('locate', $query->cacheKey()), $resolved->toArray(), $this->cacheTtl());
        }

        $this->resetScope();

        return $resolved;
    }

    /**
     * Resolve several IP addresses at once, returning a name-preserving map of results
     * (null where a lookup failed). Failures never abort the batch.
     *
     * @param  list<string>  $ips
     * @return array<string, Location|null>
     */
    public function batch(array $ips): array
    {
        $results = [];

        foreach ($ips as $ip) {
            try {
                $results[$ip] = $this->locateIp($ip);
            } catch (\Throwable) {
                $results[$ip] = null;
            }
        }

        return $results;
    }

    /**
     * Resolve a distance grid between several origins and destinations via the Google
     * Distance Matrix provider, degrading gracefully when it is unavailable.
     *
     * @param  list<Coordinates>  $origins
     * @param  list<Coordinates>  $destinations
     */
    public function distanceMatrix(
        array $origins,
        array $destinations,
        DistanceType $type = DistanceType::Driving,
    ): DistanceMatrix {
        $this->applyOverrides();

        foreach ($this->resolveProviders() as $provider) {
            if ($provider instanceof GoogleProvider) {
                $matrix = $provider->distanceMatrix($origins, $destinations, $type);
                $this->resetScope();

                return $matrix;
            }
        }

        $this->resetScope();

        return new DistanceMatrix($origins, $destinations, []);
    }

    public function distance(DistanceQuery $query): ?Distance
    {
        if ($this->cacheEnabled()) {
            $cached = Distance::tryFromArray($this->cache()->get($this->cacheKey('distance', $query->cacheKey())));

            if ($cached instanceof Distance) {
                $this->resetScope();

                return $cached;
            }
        }

        $this->applyOverrides();

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
                $this->cache()->put($this->cacheKey('distance', $query->cacheKey()), $resolved->toArray(), $this->cacheTtl());
            }

            $this->dispatch(new DistanceResolved($query, $resolved, (string) $providerName));
        }

        $this->resetScope();

        return $resolved;
    }

    /**
     * The travel distance between two points — `distance()` without hand-building a
     * DistanceQuery.
     */
    public function distanceBetween(
        Coordinates $from,
        Coordinates $to,
        DistanceType $type = DistanceType::Driving,
    ): ?Distance {
        return $this->distance(DistanceQuery::between($from, $to, $type));
    }

    /**
     * Download (or refresh) the MaxMind GeoLite2/GeoIP2 database and return the path it was
     * written to. Edition and path default to `geolocation.services.maxmind_database.*`.
     *
     * @throws DatabaseUpdateException
     */
    public function updateDatabase(?string $edition = null, ?string $path = null): string
    {
        return $this->container->make(UpdateDatabaseAction::class)->execute($edition, $path);
    }

    /**
     * Drop the cached result of one lookup or distance query, so the next call asks the
     * providers again. Returns whether an entry was removed.
     */
    public function forget(GeolocationQuery|DistanceQuery $query): bool
    {
        return $query instanceof DistanceQuery
            ? $this->cache()->forget($this->cacheKey('distance', $query->cacheKey()))
            : $this->cache()->forget($this->cacheKey('locate', $query->cacheKey()));
    }

    /**
     * Invalidate every cached lookup and distance at once. Works on any cache store: the
     * cache keys carry a generation number and this moves it forward, so older entries are
     * never read again and expire on their own TTL.
     */
    public function flushCache(): void
    {
        $this->cache()->forever($this->generationKey(), $this->cacheGeneration() + 1);
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

    /**
     * Scope the next resolution to a single provider (alias of using() for one name).
     */
    public function provider(string $name): self
    {
        $this->only = [$name];

        return $this;
    }

    /**
     * Override the API token/key the provider(s) use for the next resolution only.
     */
    public function withToken(#[\SensitiveParameter] string $token): self
    {
        $this->overrides['token'] = $token;

        return $this;
    }

    /**
     * Override the HTTP timeout (seconds) the provider(s) use for the next resolution only.
     */
    public function withTimeout(int $seconds): self
    {
        $this->overrides['timeout'] = $seconds;

        return $this;
    }

    /**
     * Merge arbitrary call-time overrides applied to the provider(s) for the next
     * resolution only, without mutating global config.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function withConfig(array $overrides): self
    {
        $this->overrides = array_merge($this->overrides, $overrides);

        return $this;
    }

    private function applyOverrides(): void
    {
        if ($this->overrides === [] || ! app()->bound(ProviderOverrides::class)) {
            return;
        }

        app(ProviderOverrides::class)->merge($this->overrides);
    }

    private function resetScope(): void
    {
        $this->only = null;
        $this->overrides = [];

        if (app()->bound(ProviderOverrides::class)) {
            app(ProviderOverrides::class)->reset();
        }
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
            // providers; a custom "providers" config falls back to its own order.
            if ($names !== []) {
                return $names;
            }
        }

        return $available;
    }

    /**
     * The name => class-string provider map. Every entry needs a name — the pipeline and
     * `provider()` address providers by it.
     *
     * @return array<string, class-string>
     */
    private function configuredProviders(): array
    {
        /** @var array<int|string, string> $providers */
        $providers = config('geolocation.providers', []);

        $map = [];

        foreach ($providers as $name => $class) {
            if (is_int($name)) {
                throw UnknownProviderException::unnamed($class);
            }

            $map[$name] = $class;
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
        return $this->only === null
            && $this->overrides === []
            && (bool) config('geolocation.cache.enabled', false);
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
        return "{$this->cachePrefix()}:v{$this->cacheGeneration()}:{$kind}:{$hash}";
    }

    private function cacheGeneration(): int
    {
        return (int) $this->cache()->get($this->generationKey(), 0);
    }

    private function generationKey(): string
    {
        return "{$this->cachePrefix()}:generation";
    }

    private function cachePrefix(): string
    {
        return (string) config('geolocation.cache.prefix', 'geolocation');
    }
}
