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
     * @var array<string, Location>
     */
    private array $results;

    private ?Location $default = null;

    private ?Distance $distance = null;

    /**
     * @var list<string>
     */
    private array $located = [];

    /**
     * The provider names pinned via using()/provider() for the next call, recorded when that
     * call (a lookup, batch, distance or matrix) runs so they can be asserted on.
     *
     * @var list<string>
     */
    private array $pinned = [];

    /**
     * @var list<string>
     */
    private array $providersUsed = [];

    /**
     * @var list<DistanceQuery>
     */
    private array $distances = [];

    /**
     * @var list<array{edition: string, path: string}>
     */
    private array $databaseUpdates = [];

    /**
     * @var list<GeolocationQuery|DistanceQuery>
     */
    private array $forgotten = [];

    private int $flushes = 0;

    /**
     * @param  array<string, Location>  $results
     */
    public function __construct(Container $container, array $results = [])
    {
        parent::__construct($container);

        $this->results = $results;
    }

    /**
     * Seed a canned location for a key (an IP, address or "lat,lng" string).
     */
    public function seed(string $key, Location $location): self
    {
        $this->results[$key] = $location;

        return $this;
    }

    /**
     * The location returned when no seeded result matches a lookup.
     */
    public function seedDefault(?Location $location): self
    {
        $this->default = $location;

        return $this;
    }

    public function seedDistance(?Distance $distance): self
    {
        $this->distance = $distance;

        return $this;
    }

    public function locate(GeolocationQuery $query): ?Location
    {
        $this->consumePinned();

        return $this->lookup($query);
    }

    /**
     * @param  list<string>  $ips
     * @return array<string, Location|null>
     */
    public function batch(array $ips): array
    {
        $this->consumePinned();

        $results = [];

        foreach ($ips as $ip) {
            $results[$ip] = $this->lookup(GeolocationQuery::forIp($ip));
        }

        return $results;
    }

    public function distance(DistanceQuery $query): ?Distance
    {
        $this->consumePinned();
        $this->distances[] = $query;

        return $this->distance;
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
        $this->consumePinned();

        return new DistanceMatrix($origins, $destinations, []);
    }

    /**
     * Records the refresh and returns the path it would have written — nothing is downloaded.
     */
    public function updateDatabase(?string $edition = null, ?string $path = null): string
    {
        $edition = $edition ?? (string) config('geolocation.services.maxmind_database.edition', 'GeoLite2-City');
        $path = $path ?? (string) config('geolocation.services.maxmind_database.path', '');

        $this->databaseUpdates[] = ['edition' => $edition, 'path' => $path];

        return $path;
    }

    public function forget(GeolocationQuery|DistanceQuery $query): bool
    {
        $this->forgotten[] = $query;

        return true;
    }

    public function flushCache(): void
    {
        $this->flushes++;
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
     * Pins the names for the next call — the fake runs no provider, so this is what
     * assertProviderUsed() checks.
     */
    public function using(string ...$providers): GeolocationManager
    {
        $this->pinned = array_values($providers);

        return $this;
    }

    public function provider(string $name): GeolocationManager
    {
        return $this->using($name);
    }

    public function withToken(string $provider, #[SensitiveParameter] string $token): GeolocationManager
    {
        return $this;
    }

    public function withTimeout(int $seconds): GeolocationManager
    {
        return $this;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function withConfig(string $provider, array $overrides): GeolocationManager
    {
        return $this;
    }

    public function assertLocated(string $key): void
    {
        Assert::assertContains(
            $key,
            $this->located,
            "Failed asserting that [{$key}] was located.",
        );
    }

    public function assertNothingLocated(): void
    {
        Assert::assertSame(
            [],
            $this->located,
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
            $this->providersUsed,
            "Failed asserting that provider [{$name}] was used.",
        );
    }

    public function assertProviderNotUsed(string $name): void
    {
        Assert::assertNotContains(
            $name,
            $this->providersUsed,
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
            $this->distances,
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
            $this->distances,
            sprintf('Failed asserting that no distance was requested (%d were).', count($this->distances)),
        );
    }

    /**
     * Assert the MaxMind database was refreshed, optionally for a given edition.
     */
    public function assertDatabaseUpdated(?string $edition = null): void
    {
        $matching = array_filter(
            $this->databaseUpdates,
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
            $this->databaseUpdates,
            'Failed asserting that the MaxMind database was not updated.',
        );
    }

    /**
     * Assert the cached result of this lookup or distance query was forgotten.
     */
    public function assertForgotten(GeolocationQuery|DistanceQuery $query): void
    {
        $matching = array_filter(
            $this->forgotten,
            static fn (GeolocationQuery|DistanceQuery $forgotten): bool => $forgotten::class === $query::class
                && $forgotten->cacheKey() === $query->cacheKey(),
        );

        Assert::assertNotEmpty($matching, 'Failed asserting that the query was forgotten.');
    }

    public function assertNothingForgotten(): void
    {
        Assert::assertSame(
            [],
            $this->forgotten,
            'Failed asserting that nothing was forgotten.',
        );
    }

    public function assertCacheFlushed(): void
    {
        Assert::assertGreaterThan(0, $this->flushes, 'Failed asserting that the geolocation cache was flushed.');
    }

    public function assertCacheNotFlushed(): void
    {
        Assert::assertSame(0, $this->flushes, 'Failed asserting that the geolocation cache was not flushed.');
    }

    private function consumePinned(): void
    {
        array_push($this->providersUsed, ...$this->pinned);
        $this->pinned = [];
    }

    private function lookup(GeolocationQuery $query): ?Location
    {
        $key = $this->keyFor($query);
        $this->located[] = $key;

        return $this->results[$key] ?? $this->default;
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
