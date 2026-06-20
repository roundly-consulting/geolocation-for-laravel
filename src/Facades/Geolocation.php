<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Geolocation\GeolocationManager;

/**
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Location|null locate(\RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery $query)
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Location|null locateIp(string $ip)
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Location|null locateAddress(string $address)
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Location|null locateCoordinates(\RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates $coordinates)
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Location|null locateRequest(?\Illuminate\Http\Request $request = null)
 * @method static \RoundlyConsulting\Geolocation\DataTransferObjects\Distance|null distance(\RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery $query)
 * @method static \RoundlyConsulting\Geolocation\GeolocationManager extend(string $name, \Closure $factory)
 * @method static \RoundlyConsulting\Geolocation\GeolocationManager using(string ...$providers)
 *
 * @see GeolocationManager
 */
final class Geolocation extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GeolocationManager::class;
    }
}
