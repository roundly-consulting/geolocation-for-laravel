<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Testing;

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

/**
 * A recording, network-free stand-in for the manager used in host-application tests. Seed
 * canned results per IP/address/coordinate and assert on what was looked up.
 */
final class FakeGeolocationManager extends GeolocationManager
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
     * The provider name pinned via using()/provider() for the next lookup, recorded so it
     * can be asserted on.
     */
    private ?string $pinnedProvider = null;

    /**
     * @var list<string>
     */
    private array $providersUsed = [];

    /**
     * @param  array<string, Location>  $results
     */
    public function __construct(array $results = [])
    {
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
        $key = $this->keyFor($query);
        $this->located[] = $key;

        if ($this->pinnedProvider !== null) {
            $this->providersUsed[] = $this->pinnedProvider;
            $this->pinnedProvider = null;
        }

        return $this->results[$key] ?? $this->default;
    }

    public function distance(DistanceQuery $query): ?Distance
    {
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
        return new DistanceMatrix($origins, $destinations, []);
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

    public function using(string ...$providers): GeolocationManager
    {
        $this->pinnedProvider = $providers[0] ?? null;

        return $this;
    }

    public function provider(string $name): GeolocationManager
    {
        $this->pinnedProvider = $name;

        return $this;
    }

    public function withToken(#[\SensitiveParameter] string $token): GeolocationManager
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
    public function withConfig(array $overrides): GeolocationManager
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

    public function assertProviderUsed(string $name): void
    {
        Assert::assertContains(
            $name,
            $this->providersUsed,
            "Failed asserting that provider [{$name}] was used.",
        );
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
            return "{$query->latitude},{$query->longitude}";
        }

        return '';
    }
}
