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
use RoundlyConsulting\Geolocation\Exceptions\ProviderUnavailableException;
use RoundlyConsulting\Geolocation\Exceptions\UnknownProviderException;
use RoundlyConsulting\Geolocation\Providers\GoogleProvider;
use RoundlyConsulting\Geolocation\Support\ProviderOverrides;
use SensitiveParameter;
use Throwable;

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
     * When non-null, restricts this (scoped copy's) resolutions to these provider names only.
     *
     * @var list<string>|null
     */
    private ?array $only = null;

    /**
     * Call-time overrides every provider sees in this (scoped copy's) resolutions.
     *
     * @var array<string, mixed>
     */
    private array $sharedOverrides = [];

    /**
     * Call-time overrides only the named provider sees, keyed by provider name.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $providerOverrides = [];

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
                return $cached;
            }
        }

        $resolved = $this->scoped(fn (): ?Location => $this->resolveLocation($query));

        if ($resolved instanceof Location && $this->cacheEnabled()) {
            $this->cache()->put($this->cacheKey('locate', $query->cacheKey()), $resolved->toArray(), $this->cacheTtl());
        }

        return $resolved;
    }

    /**
     * Resolve several IP addresses at once, returning a name-preserving map of results
     * (null where a lookup failed). Failures never abort the batch. A scope or override
     * (`provider()`, `using()`, `with*()`) applies to every IP.
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
            } catch (Throwable) {
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
        return $this->scoped(function () use ($origins, $destinations, $type): DistanceMatrix {
            foreach ($this->providerNames() as $name) {
                $provider = $this->activate($name);

                if ($provider instanceof GoogleProvider) {
                    return $provider->distanceMatrix($origins, $destinations, $type);
                }
            }

            return new DistanceMatrix($origins, $destinations, []);
        });
    }

    public function distance(DistanceQuery $query): ?Distance
    {
        if ($this->cacheEnabled()) {
            $cached = Distance::tryFromArray($this->cache()->get($this->cacheKey('distance', $query->cacheKey())));

            if ($cached instanceof Distance) {
                return $cached;
            }
        }

        $resolved = $this->scoped(fn (): ?Distance => $this->resolveDistance($query));

        if ($resolved instanceof Distance && $this->cacheEnabled()) {
            $this->cache()->put($this->cacheKey('distance', $query->cacheKey()), $resolved->toArray(), $this->cacheTtl());
        }

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
     * A copy of this manager scoped to the given provider names only. The shared manager is
     * never changed, so the scope cannot outlive the call chain it was set on.
     */
    public function using(string ...$providers): self
    {
        $scoped = clone $this;
        $scoped->only = array_values($providers);

        return $scoped;
    }

    /**
     * A copy of this manager scoped to a single provider (using() for one name).
     */
    public function provider(string $name): self
    {
        return $this->using($name);
    }

    /**
     * A copy of this manager whose calls send this API token/key to the named provider — and
     * only to it: a credential is vendor-specific, so every other provider keeps its own.
     *
     * @throws UnknownProviderException when no provider is registered under $provider
     */
    public function withToken(string $provider, #[SensitiveParameter] string $token): self
    {
        return $this->withConfig($provider, ['token' => $token]);
    }

    /**
     * A copy of this manager whose calls use this HTTP timeout (seconds) for every provider.
     */
    public function withTimeout(int $seconds): self
    {
        $scoped = clone $this;
        $scoped->sharedOverrides['timeout'] = $seconds;

        return $scoped;
    }

    /**
     * A copy of this manager whose calls apply these config overrides to the named provider
     * only, without mutating global config.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @throws UnknownProviderException when no provider is registered under $provider
     */
    public function withConfig(string $provider, array $overrides): self
    {
        if (! isset($this->extensions[$provider]) && ! array_key_exists($provider, $this->configuredProviders())) {
            throw UnknownProviderException::named($provider);
        }

        $scoped = clone $this;
        $scoped->providerOverrides[$provider] = array_merge($this->providerOverrides[$provider] ?? [], $overrides);

        return $scoped;
    }

    /**
     * Run a resolution with this instance's overrides active for its providers, restored
     * afterwards even when a provider throws.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function scoped(Closure $callback): mixed
    {
        return $this->overrides()->during($this->sharedOverrides, $this->providerOverrides, $callback);
    }

    /**
     * Resolve a pipeline entry and make its scoped overrides the ones its provider reads.
     */
    private function activate(string $name): object
    {
        $provider = $this->resolveProvider($name);

        $this->overrides()->activate($name);

        return $provider;
    }

    private function overrides(): ProviderOverrides
    {
        return $this->container->make(ProviderOverrides::class);
    }

    /**
     * Walk the pipeline until a provider answers. A provider whose API is unreachable
     * (ProviderUnavailableException) is skipped; any other exception aborts the lookup.
     * Either way a lookup that ends without a location dispatches LocationResolutionFailed,
     * naming the provider that failed last and its (redacted) error.
     */
    private function resolveLocation(GeolocationQuery $query): ?Location
    {
        $failedProvider = null;
        $error = null;

        foreach ($this->providerNames() as $name) {
            try {
                $provider = $this->activate($name);

                if (! $provider instanceof GeolocationProvider) {
                    continue;
                }

                $location = $provider->locate($query);
            } catch (ProviderUnavailableException $e) {
                [$failedProvider, $error] = [$name, $e];

                continue;
            } catch (Throwable $e) {
                $this->dispatch(new LocationResolutionFailed($query, $name, $e));

                throw $e;
            }

            if ($location instanceof Location) {
                $this->dispatch(new LocationResolved($query, $location, $name));

                return $location;
            }
        }

        $this->dispatch(new LocationResolutionFailed($query, $failedProvider, $error));

        return null;
    }

    private function resolveDistance(DistanceQuery $query): ?Distance
    {
        foreach ($this->providerNames() as $name) {
            $provider = $this->activate($name);

            if (! $provider instanceof DistanceProvider) {
                continue;
            }

            try {
                $distance = $provider->distance($query);
            } catch (ProviderUnavailableException) {
                // An unreachable API is a miss, not an abort: ask the next provider.
                continue;
            }

            if ($distance instanceof Distance) {
                $this->dispatch(new DistanceResolved($query, $distance, $name));

                return $distance;
            }
        }

        return null;
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
            && $this->sharedOverrides === []
            && $this->providerOverrides === []
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
