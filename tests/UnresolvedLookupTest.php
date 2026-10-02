<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Events\LocationResolutionFailed;
use RoundlyConsulting\Geolocation\Events\LocationResolved;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\GeolocationProvider;

/**
 * The contract consumers build on: an IP, address or point nothing can place is `null` —
 * never a placeholder Location with an empty country, which a caller could not tell apart
 * from a viewer it did locate.
 */

/**
 * What api.ip2location.io really answers (HTTP 200) for a private or reserved IP.
 *
 * @return array<string, mixed>
 */
function ip2locationNoData(): array
{
    return [
        'ip' => '10.0.0.1', 'country_code' => null, 'country_name' => null, 'region_name' => null,
        'city_name' => null, 'latitude' => null, 'longitude' => null, 'zip_code' => null,
        'time_zone' => null, 'asn' => null, 'as' => null, 'is_proxy' => false,
    ];
}

beforeEach(function (): void {
    config()->set('geolocation.events.enabled', true);
});

it('answers null, never an empty location, for an ip no provider can place', function (): void {
    Event::fake([LocationResolved::class, LocationResolutionFailed::class]);
    Http::fake([
        'api.ip2location.io/*' => Http::response(ip2locationNoData()),
        'ipinfo.io/*' => Http::response(['ip' => '10.0.0.1', 'bogon' => true]),
    ]);

    expect(Geolocation::locateIp('10.0.0.1'))->toBeNull();

    Event::assertNotDispatched(LocationResolved::class);
    Event::assertDispatched(LocationResolutionFailed::class);
});

it('asks the next provider when one answers with an empty location', function (): void {
    config()->set('geolocation.pipeline', ['ip2location', 'ipinfo']);
    Http::fake([
        'api.ip2location.io/*' => Http::response(ip2locationNoData()),
        'ipinfo.io/*' => Http::response(['city' => 'Mountain View', 'country' => 'US', 'loc' => '37.4,-122.07']),
    ]);

    expect(Geolocation::locateIp('8.8.8.8'))->countryIsoCode->toBe('US');
});

it('treats an empty location from any provider as a miss and never caches it', function (): void {
    config()->set('geolocation.cache.enabled', true);
    config()->set('geolocation.pipeline', ['blank']);

    Geolocation::extend('blank', fn (): GeolocationProvider => new class implements GeolocationProvider
    {
        public function locate(GeolocationQuery $query): Location
        {
            return new Location('', '', '', '', 0.0, 0.0, GeolocationType::Ip);
        }
    });

    expect(Geolocation::locateIp('8.8.8.8'))->toBeNull();

    Geolocation::extend('blank', fn (): GeolocationProvider => new class implements GeolocationProvider
    {
        public function locate(GeolocationQuery $query): Location
        {
            return new Location('Bratislava', '', 'Bratislava', 'SK', 48.14, 17.10, GeolocationType::Ip);
        }
    });

    expect(Geolocation::locateIp('8.8.8.8'))->countryIsoCode->toBe('SK');
});
