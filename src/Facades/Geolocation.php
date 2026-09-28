<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Geolocation\Testing\GeolocationFake;

/**
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Location|null locate(\RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery $query)
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Location|null locateIp(string $ip)
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Location|null locateAddress(string $address)
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Location|null locateCoordinates(\RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates $coordinates)
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Location|null locateRequest(?\Illuminate\Http\Request $request = null)
 * @method static array<string, \RoundlyConsulting\Geolocation\DataTransferObjects\Location|null> batch(list<string> $ips)
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Distance|null distance(\RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery $query)
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Distance|null distanceBetween(\RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates $from, \RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates $to, \RoundlyConsulting\Geolocation\Enum\DistanceType $type = \RoundlyConsulting\Geolocation\Enum\DistanceType::Driving)
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\DistanceMatrix distanceMatrix(list<\RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates> $origins, list<\RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates> $destinations, \RoundlyConsulting\Geolocation\Enum\DistanceType $type = \RoundlyConsulting\Geolocation\Enum\DistanceType::Driving)
 * @method static string updateDatabase(?string $edition = null, ?string $path = null)
 * @method static bool forget(\RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery|\RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery $query)
 * @method static void flushCache()
 * @method static \RoundlyConsulting\Geolocation\GeolocationManager extend(string $name, \Closure $factory)
 * @method static \RoundlyConsulting\Geolocation\GeolocationManager using(string ...$providers)
 * @method static \RoundlyConsulting\Geolocation\GeolocationManager provider(string $name)
 * @method static \RoundlyConsulting\Geolocation\GeolocationManager withToken(string $token)
 * @method static \RoundlyConsulting\Geolocation\GeolocationManager withTimeout(int $seconds)
 * @method static \RoundlyConsulting\Geolocation\GeolocationManager withConfig(array<string, mixed> $overrides)
 * @method static void assertLocated(string $key)
 * @method static void assertNothingLocated()
 * @method static void assertProviderUsed(string $name)
 * @method static void assertDistanceRequested(?\RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates $from = null, ?\RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates $to = null, ?\RoundlyConsulting\Geolocation\Enum\DistanceType $type = null)
 * @method static void assertNoDistanceRequested()
 * @method static void assertDatabaseUpdated(?string $edition = null)
 * @method static void assertDatabaseNotUpdated()
 * @method static void assertForgotten(\RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery|\RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery $query)
 * @method static void assertNothingForgotten()
 * @method static void assertCacheFlushed()
 * @method static void assertCacheNotFlushed()
 *
 * @see GeolocationManager
 */
final class Geolocation extends Facade
{
    /**
     * Swap the manager for a recording, network-free fake — behind the facade and in the
     * container, so an injected GeolocationManager (and `$request->location()`) is faked too.
     * Seed canned results per IP, address or "lat,lng" key via $results.
     *
     * @param  array<string, Location>  $results
     */
    public static function fake(array $results = []): GeolocationFake
    {
        $fake = app()->make(GeolocationFake::class, ['results' => $results]);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return GeolocationManager::class;
    }
}
