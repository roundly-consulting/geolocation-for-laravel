<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Testing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceMatrix;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Geolocation\Support\Decimal;
use RoundlyConsulting\Geolocation\Support\GeolocationConfig;
use SensitiveParameter;

/**
 * A recording, network-free stand-in for the manager, installed by `Geolocation::fake()`.
 * It extends the manager, so constructor-injected managers keep type-checking. Seed canned
 * results per IP/address/coordinate and assert on what was looked up, measured, refreshed
 * and forgotten — no provider, cache store or MaxMind download is ever touched.
 */
final class GeolocationFake extends GeolocationManager
{
    /**
     * The seeds and recordings, shared with every scoped copy this fake hands out.
     */
    private readonly GeolocationFakeState $state;

    /**
     * The provider names this (scoped copy of the) fake is pinned to via using()/provider(),
     * recorded on every call made through it — null on the facade's own, unscoped fake.
     *
     * @var list<string>|null
     */
    private ?array $pinned = null;

    /**
     * @param  array<string, Location>  $results
     * @param  GeolocationManager|null  $manager  the manager being faked; the providers it
     *                                            registered via extend() stay registered here
     */
    public function __construct(Container $container, array $results = [], ?GeolocationManager $manager = null)
    {
        parent::__construct($container);

        $this->state = new GeolocationFakeState($results);

        if ($manager instanceof GeolocationManager) {
            $this->inheritExtensionsFrom($manager);
        }
    }

    /**
     * Seed a canned location for a key (an IP, address or "lat,lng" string).
     */
    public function seed(string $key, Location $location): self
    {
        $this->state->results[$key] = $location;

        return $this;
    }

    /**
     * The location returned when no seeded result matches a lookup.
     */
    public function seedDefault(?Location $location): self
    {
        $this->state->default = $location;

        return $this;
    }

    public function seedDistance(?Distance $distance): self
    {
        $this->state->distance = $distance;

        return $this;
    }

    public function locate(GeolocationQuery $query): ?Location
    {
        $this->recordPinned();

        return $this->lookup($query);
    }

    public function distance(DistanceQuery $query): ?Distance
    {
        $this->recordPinned();
        $this->state->distances[] = $query;

        return $this->state->distance;
    }

    /**
     * @param  list<Coordinates>  $origins
     * @param  list<Coordinates>  $destinations
     */
    public function distanceMatrix(
        array $origins,
        array $destinations,
        DistanceType $type = DistanceType::Driving,
    ): DistanceMatrix {
        $this->recordPinned();

        return new DistanceMatrix($origins, $destinations, []);
    }

    /**
     * Records the refresh and returns the path it would have written — nothing is downloaded.
     */
    public function updateDatabase(?string $edition = null, ?string $path = null): string
    {
        // Resolved like the real action: a blank edition (argument or config) or path
        // argument is not set.
        $edition = $edition !== null && $edition !== ''
            ? $edition
            : GeolocationConfig::string('geolocation.services.maxmind_database.edition', config('geolocation.services.maxmind_database.edition'), 'GeoLite2-City');
        $path = $path !== null && $path !== '' ? $path : (string) config('geolocation.services.maxmind_database.path', '');

        $this->state->databaseUpdates[] = ['edition' => $edition, 'path' => $path];

        return $path;
    }

    public function forget(GeolocationQuery|DistanceQuery $query): bool
    {
        $this->state->forgotten[] = $query;

        return true;
    }

    public function flushCache(): void
    {
        $this->state->flushes++;
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
     * A scoped copy pinned to these names, like the real manager's: the scope applies to the
     * calls made through the copy only and never leaks into a later call on the facade. The
     * copy records into this fake, and since the fake runs no provider, the pin is what
     * assertProviderUsed() checks. A name that is not registered throws
     * UnknownProviderException when a call runs, as it does for real.
     */
    public function using(string ...$providers): GeolocationManager
    {
        $scoped = clone $this;
        $scoped->pinned = array_values($providers);

        return $scoped;
    }

    public function provider(string $name): GeolocationManager
    {
        return $this->using($name);
    }

    /**
     * The fake runs no provider, so the token goes nowhere — but an unregistered name throws
     * UnknownProviderException, as it does for real.
     */
    public function withToken(string $provider, #[SensitiveParameter] string $token): GeolocationManager
    {
        $this->ensureRegistered($provider);

        return $this;
    }

    public function withTimeout(int $seconds): GeolocationManager
    {
        return $this;
    }

    /**
     * A no-op like withToken(), refusing an unregistered name the same way.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function withConfig(string $provider, array $overrides): GeolocationManager
    {
        $this->ensureRegistered($provider);

        return $this;
    }

    public function assertLocated(string $key): void
    {
        Assert::assertContains(
            $key,
            $this->state->located,
            "Failed asserting that [{$key}] was located.",
        );
    }

    public function assertNothingLocated(): void
    {
        Assert::assertSame(
            [],
            $this->state->located,
            'Failed asserting that nothing was located.',
        );
    }

    /**
     * Assert a call ran pinned to this provider via `provider()` / `using()`. The fake runs
     * no pipeline, so a provider that merely "would have answered" is never recorded.
     */
    public function assertProviderUsed(string $name): void
    {
        Assert::assertContains(
            $name,
            $this->state->providersUsed,
            "Failed asserting that provider [{$name}] was used.",
        );
    }

    public function assertProviderNotUsed(string $name): void
    {
        Assert::assertNotContains(
            $name,
            $this->state->providersUsed,
            "Failed asserting that provider [{$name}] was not used.",
        );
    }

    /**
     * Assert a distance was requested — through `distance()` or `distanceBetween()`. Each
     * argument narrows the match; omit them all to accept any request.
     */
    public function assertDistanceRequested(
        ?Coordinates $from = null,
        ?Coordinates $to = null,
        ?DistanceType $type = null,
    ): void {
        $matching = array_filter(
            $this->state->distances,
            static fn (DistanceQuery $query): bool => ($from === null || $query->from()->toArray() === $from->toArray())
                && ($to === null || $query->to()->toArray() === $to->toArray())
                && ($type === null || $query->type === $type),
        );

        Assert::assertNotEmpty($matching, $from === null && $to === null && $type === null
            ? 'Failed asserting that a distance was requested.'
            : 'Failed asserting that a matching distance was requested.');
    }

    public function assertNoDistanceRequested(): void
    {
        Assert::assertSame(
            [],
            $this->state->distances,
            sprintf('Failed asserting that no distance was requested (%d were).', count($this->state->distances)),
        );
    }

    /**
     * Assert the MaxMind database was refreshed, optionally for a given edition.
     */
    public function assertDatabaseUpdated(?string $edition = null): void
    {
        $matching = array_filter(
            $this->state->databaseUpdates,
            static fn (array $update): bool => $edition === null || $update['edition'] === $edition,
        );

        Assert::assertNotEmpty($matching, $edition === null
            ? 'Failed asserting that the MaxMind database was updated.'
            : "Failed asserting that the MaxMind database [{$edition}] was updated.");
    }

    public function assertDatabaseNotUpdated(): void
    {
        Assert::assertSame(
            [],
            $this->state->databaseUpdates,
            'Failed asserting that the MaxMind database was not updated.',
        );
    }

    /**
     * Assert the cached result of this lookup or distance query was forgotten.
     */
    public function assertForgotten(GeolocationQuery|DistanceQuery $query): void
    {
        $matching = array_filter(
            $this->state->forgotten,
            static fn (GeolocationQuery|DistanceQuery $forgotten): bool => $forgotten::class === $query::class
                && $forgotten->cacheKey() === $query->cacheKey(),
        );

        Assert::assertNotEmpty($matching, 'Failed asserting that the query was forgotten.');
    }

    public function assertNothingForgotten(): void
    {
        Assert::assertSame(
            [],
            $this->state->forgotten,
            'Failed asserting that nothing was forgotten.',
        );
    }

    public function assertCacheFlushed(): void
    {
        Assert::assertGreaterThan(0, $this->state->flushes, 'Failed asserting that the geolocation cache was flushed.');
    }

    public function assertCacheNotFlushed(): void
    {
        Assert::assertSame(0, $this->state->flushes, 'Failed asserting that the geolocation cache was not flushed.');
    }

    private function recordPinned(): void
    {
        if ($this->pinned === null) {
            return;
        }

        foreach ($this->pinned as $name) {
            $this->ensureRegistered($name);
        }

        array_push($this->state->providersUsed, ...$this->pinned);
    }

    private function lookup(GeolocationQuery $query): ?Location
    {
        $key = $this->keyFor($query);
        $this->state->located[] = $key;

        return $this->state->results[$key] ?? $this->state->default;
    }

    private function keyFor(GeolocationQuery $query): string
    {
        if ($query->ipAddress !== null) {
            return $query->ipAddress;
        }

        if ($query->address !== null) {
            return $query->address;
        }

        if ($query->latitude !== null && $query->longitude !== null) {
            return Decimal::pair($query->latitude, $query->longitude);
        }

        return '';
    }
}
