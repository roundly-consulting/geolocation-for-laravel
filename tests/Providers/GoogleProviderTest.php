<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Events\LocationResolutionFailed;
use RoundlyConsulting\Geolocation\Exceptions\ProviderUnavailableException;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
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

it('returns null when no latitude, longitude or address is provided to geolocate', function (): void {
    $provider = new GoogleProvider;

    expect($provider->locate(new GeolocationQuery))
        ->toBeNull()
        ->and($provider->locate(new GeolocationQuery(latitude: 1.2)))->toBeNull()
        ->and($provider->locate(new GeolocationQuery(longitude: 1.2)))->toBeNull();

    Http::assertNothingSent();
});

it('forward geocodes an address into a location', function (): void {
    Http::fake([
        '*/geocode/json*' => Http::response([
            'results' => [[
                'formatted_address' => '1600 Amphitheatre Pkwy, Mountain View, CA 94043, USA',
                'geometry' => ['location' => ['lat' => 37.4224, 'lng' => -122.0841]],
                'address_components' => [
                    ['long_name' => 'Amphitheatre Parkway', 'short_name' => 'Amphitheatre Pkwy', 'types' => ['route']],
                    ['long_name' => 'Mountain View', 'short_name' => 'Mountain View', 'types' => ['locality']],
                    ['long_name' => 'California', 'short_name' => 'CA', 'types' => ['administrative_area_level_1']],
                    ['long_name' => '94043', 'short_name' => '94043', 'types' => ['postal_code']],
                    ['long_name' => 'United States', 'short_name' => 'US', 'types' => ['country']],
                ],
            ]],
        ]),
    ]);

    $location = (new GoogleProvider)->locate(new GeolocationQuery(address: '1600 Amphitheatre Pkwy'));

    expect($location)
        ->toBeInstanceOf(Location::class)
        ->city->toBe('Mountain View')
        ->region->toBe('California')
        ->postalCode->toBe('94043')
        ->countryIsoCode->toBe('US')
        ->latitude->toBe(37.4224)
        ->longitude->toBe(-122.0841)
        ->type->toBe(GeolocationType::Geolocation);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'address=1600'));
});

it('returns null when an address yields no results', function (): void {
    Http::fake([
        '*/geocode/json*' => Http::response(['results' => []]),
    ]);

    expect((new GoogleProvider)->locate(new GeolocationQuery(address: 'nowhere at all')))->toBeNull();
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

it('never sends coordinates in scientific notation', function (): void {
    Http::fake([
        '*/geocode/json*' => Http::response(['results' => []]),
        '*/distancematrix/json*' => Http::response(['rows' => []]),
    ]);

    $provider = new GoogleProvider;
    $provider->locate(GeolocationQuery::forCoordinates(new Coordinates(0.00001, -0.00002)));
    $provider->distance(new DistanceQuery(0.00001, 0.0, -0.00005, 0.00003, DistanceType::Driving));
    $provider->distanceMatrix(
        [new Coordinates(0.00001, 0.0)],
        [new Coordinates(-0.00005, 0.00003)],
    );

    Http::assertSent(fn ($request): bool => ($request->data()['latlng'] ?? null) === '0.00001,-0.00002');
    Http::assertSent(fn ($request): bool => ($request->data()['origins'] ?? null) === '0.00001,0'
        && ($request->data()['destinations'] ?? null) === '-0.00005,0.00003');
    Http::assertNotSent(fn ($request): bool => str_contains(urldecode($request->url()), 'E-'));
});

it('reports a geocoding request google rejected as an unavailable provider', function (string $status): void {
    config()->set('geolocation.services.google.key', 'GOOGLE-SECRET');
    config()->set('geolocation.pipeline', ['google']);
    Event::fake([LocationResolutionFailed::class]);
    Http::fake([
        '*/geocode/json*' => Http::response([
            'status' => $status,
            'error_message' => 'The provided API key GOOGLE-SECRET is not allowed.',
            'results' => [],
        ]),
    ]);

    expect(Geolocation::locateAddress('1 Main St'))->toBeNull();

    Event::assertDispatched(LocationResolutionFailed::class, fn (LocationResolutionFailed $event): bool => $event->provider === 'google'
        && $event->error instanceof ProviderUnavailableException
        && str_contains($event->error->getMessage(), "[google] is unavailable: {$status}: The provided API key [redacted] is not allowed.")
        && ! str_contains($event->error->getMessage(), 'GOOGLE-SECRET'));
})->with(['REQUEST_DENIED', 'INVALID_REQUEST', 'OVER_QUERY_LIMIT', 'OVER_DAILY_LIMIT', 'UNKNOWN_ERROR']);

it('treats a geocoding ZERO_RESULTS status as a plain miss', function (): void {
    Http::fake(['*/geocode/json*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);

    expect((new GoogleProvider)->locate(GeolocationQuery::forAddress('nowhere')))->toBeNull();
});

it('names the bare status when google sends no error message', function (): void {
    Http::fake(['*/geocode/json*' => Http::response(['status' => 'REQUEST_DENIED', 'results' => []])]);

    expect(fn () => (new GoogleProvider)->locate(GeolocationQuery::forAddress('1 Main St')))
        ->toThrow(ProviderUnavailableException::class, 'Geolocation provider [google] is unavailable: REQUEST_DENIED');
});
