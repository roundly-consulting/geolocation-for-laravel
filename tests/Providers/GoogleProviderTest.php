<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Providers\GoogleProvider;

it('returns distance between two locations', function (): void {
    Http::fake([
        '*/distancematrix/json*' => Http::response([
            'rows' => [[
                'elements' => [[
                    'status' => 'OK',
                    'distance' => ['text' => '10 km', 'value' => 10000],
                    'duration' => ['text' => '10 mins', 'value' => 600],
                ]],
            ]],
        ]),
    ]);

    $distance = (new GoogleProvider)->distance(
        new DistanceQuery(1.2, 3.4, 5.6, 7.8, DistanceType::Driving),
    );

    expect($distance)
        ->toBeInstanceOf(Distance::class)
        ->humanReadableDistance->toBe('10 km')
        ->distanceInMeters->toBe(10000)
        ->humanReadableDuration->toBe('10 mins')
        ->durationInSeconds->toBe(600)
        ->type->toBe(DistanceType::Driving);
});

it('returns null when the distance request fails', function (): void {
    Http::fake([
        '*/distancematrix/json*' => Http::response(status: 500),
    ]);

    expect((new GoogleProvider)->distance(
        new DistanceQuery(1.2, 3.4, 5.6, 7.8, DistanceType::Walking),
    ))->toBeNull();
});

it('returns null when the distance element status is not ok', function (): void {
    Http::fake([
        '*/distancematrix/json*' => Http::response([
            'rows' => [['elements' => [['status' => 'ZERO_RESULTS']]]],
        ]),
    ]);

    expect((new GoogleProvider)->distance(
        new DistanceQuery(1.2, 3.4, 5.6, 7.8, DistanceType::Driving),
    ))->toBeNull();
});

it('returns null when no latitude or longitude is provided to geolocate', function (): void {
    $provider = new GoogleProvider;

    expect($provider->locate(new GeolocationQuery))
        ->toBeNull()
        ->and($provider->locate(new GeolocationQuery(latitude: 1.2)))->toBeNull()
        ->and($provider->locate(new GeolocationQuery(longitude: 1.2)))->toBeNull();
});

it('returns geolocation by latitude and longitude using the geocoding api', function (): void {
    Http::fake([
        '*/geocode/json*' => Http::response([
            'results' => [[
                'formatted_address' => 'Somewhere, Smallville, SM',
                'geometry' => ['location' => ['lat' => 1, 'lng' => 2]],
                'address_components' => [
                    ['long_name' => 'Somewhere 1', 'short_name' => 'Somewhere', 'types' => ['street_address']],
                    ['long_name' => 'Smallville New', 'short_name' => 'Smallville', 'types' => ['locality']],
                    ['long_name' => 'SmallvilleCountry', 'short_name' => 'SM', 'types' => ['country']],
                ],
            ]],
        ]),
    ]);

    $location = (new GoogleProvider)->locate(new GeolocationQuery(latitude: 1, longitude: 2));

    expect($location)
        ->toBeInstanceOf(Location::class)
        ->humanReadable->toBe('Somewhere, Smallville, SM')
        ->street->toBe('Somewhere 1')
        ->city->toBe('Smallville New')
        ->countryIsoCode->toBe('SM')
        ->latitude->toBe(1.0)
        ->longitude->toBe(2.0)
        ->type->toBe(GeolocationType::Geolocation);
});

it('falls back to the route component for the street', function (): void {
    Http::fake([
        '*/geocode/json*' => Http::response([
            'results' => [[
                'formatted_address' => 'Main Street, Town, TS',
                'geometry' => ['location' => ['lat' => 3, 'lng' => 4]],
                'address_components' => [
                    ['long_name' => 'Main Street', 'short_name' => 'Main St', 'types' => ['route']],
                    ['long_name' => 'Town', 'short_name' => 'Town', 'types' => ['locality']],
                    ['long_name' => 'Testland', 'short_name' => 'TS', 'types' => ['country']],
                ],
            ]],
        ]),
    ]);

    expect((new GoogleProvider)->locate(new GeolocationQuery(latitude: 3, longitude: 4)))
        ->street->toBe('Main Street');
});

it('returns null when the geocoding request fails', function (): void {
    Http::fake([
        '*/geocode/json*' => Http::response(status: 500),
    ]);

    expect((new GoogleProvider)->locate(new GeolocationQuery(latitude: 1, longitude: 2)))->toBeNull();
});

it('returns null when geocoding yields no results', function (): void {
    Http::fake([
        '*/geocode/json*' => Http::response(['results' => []]),
    ]);

    expect((new GoogleProvider)->locate(new GeolocationQuery(latitude: 1, longitude: 2)))->toBeNull();
});
