<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Exceptions\ProviderUnavailableException;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\Providers\GoogleProvider;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

beforeEach(function (): void {
    Sleep::fake();
    config()->set('geolocation.services.google.key', 'GOOGLE-SECRET');
});

/**
 * One computeRouteMatrix element, as the Routes API answers with the package's field mask.
 *
 * @return array<string, mixed>
 */
function routeElement(int $origin, int $destination, int $meters, string $duration, string $distanceText = '10 km', string $durationText = '10 mins'): array
{
    return [
        'originIndex' => $origin,
        'destinationIndex' => $destination,
        'status' => [],
        'distanceMeters' => $meters,
        'duration' => $duration,
        'condition' => 'ROUTE_EXISTS',
        'localizedValues' => [
            'distance' => ['text' => $distanceText],
            'duration' => ['text' => $durationText],
            'staticDuration' => ['text' => $durationText],
        ],
    ];
}

it('measures a distance through the routes api computeRouteMatrix', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::response([routeElement(0, 0, 133000, '5400s', '133 km', '1 hour 30 mins')])]);

    $distance = (new GoogleProvider)->distance(new DistanceQuery(48.1482, 17.1067, 49.2, 16.6068, DistanceType::Driving));

    expect($distance)->toBeInstanceOf(Distance::class)
        ->humanReadableDistance->toBe('133 km')
        ->distanceInMeters->toBe(133000)
        ->humanReadableDuration->toBe('1 hour 30 mins')
        ->durationInSeconds->toBe(5400)
        ->type->toBe(DistanceType::Driving);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix'
        && $request->hasHeader('X-Goog-Api-Key', 'GOOGLE-SECRET')
        && $request->hasHeader('X-Goog-FieldMask', 'originIndex,destinationIndex,status,condition,distanceMeters,duration,localizedValues')
        && $request->isJson()
        && $request->data() === [
            'origins' => [['waypoint' => ['location' => ['latLng' => ['latitude' => 48.1482, 'longitude' => 17.1067]]]]],
            'destinations' => [['waypoint' => ['location' => ['latLng' => ['latitude' => 49.2, 'longitude' => 16.6068]]]]],
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_UNAWARE',
        ]);
});

it('walks without a routing preference', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::response([routeElement(0, 0, 900, '660s', '0.9 km', '11 mins')])]);

    $distance = (new GoogleProvider)->distance(new DistanceQuery(1.0, 2.0, 1.001, 2.001, DistanceType::Walking));

    expect($distance?->type)->toBe(DistanceType::Walking);

    Http::assertSent(fn (Request $request): bool => $request['travelMode'] === 'WALK'
        && ! array_key_exists('routingPreference', $request->data()));
});

it('sends the key in a header, never in the url', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::response([routeElement(0, 0, 1, '1s')])]);

    (new GoogleProvider)->distanceMatrix([new Coordinates(1, 2)], [new Coordinates(3, 4)]);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Goog-Api-Key', 'GOOGLE-SECRET'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'GOOGLE-SECRET'));
});

it('sends no key header when no key is configured', function (): void {
    config()->set('geolocation.services.google.key', null);
    Http::fake(['routes.googleapis.com/*' => Http::response([routeElement(0, 0, 1, '1s')])]);

    (new GoogleProvider)->distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving));

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Goog-Api-Key'));
});

it('sends a withToken() key to the routes api', function (): void {
    config()->set('geolocation.pipeline', ['google']);
    Http::fake(['routes.googleapis.com/*' => Http::response([routeElement(0, 0, 1, '1s')])]);

    Geolocation::withToken('google', 'RUNTIME-KEY')->distanceBetween(new Coordinates(1, 2), new Coordinates(3, 4));

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Goog-Api-Key', 'RUNTIME-KEY'));
});

it('reports a request the routes api rejects as an unavailable provider', function (int $code, string $status): void {
    Http::fake(['routes.googleapis.com/*' => Http::response(['error' => [
        'code' => $code,
        'message' => 'Routes API has not been used with key GOOGLE-SECRET before or it is disabled.',
        'status' => $status,
    ]], $code)]);

    expect(fn () => (new GoogleProvider)->distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving)))
        ->toThrow(fn (ProviderUnavailableException $e) => expect($e->getMessage())
            ->toBe("Geolocation provider [google] is unavailable: {$status}: Routes API has not been used with key [redacted] before or it is disabled.")
            ->and($e->provider)->toBe('google'));
})->with([
    'invalid argument' => [400, 'INVALID_ARGUMENT'],
    'api not enabled' => [403, 'PERMISSION_DENIED'],
    'quota' => [429, 'RESOURCE_EXHAUSTED'],
    'server error' => [500, 'INTERNAL'],
]);

it('reports an error element inside a streamed answer', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::response([['error' => ['code' => 403, 'message' => 'Denied.', 'status' => 'PERMISSION_DENIED']]])]);

    expect(fn () => (new GoogleProvider)->distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving)))
        ->toThrow(ProviderUnavailableException::class, 'Geolocation provider [google] is unavailable: PERMISSION_DENIED: Denied.');
});

it('names the bare http status when the error has no body', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::response('Bad Gateway', 502)]);

    expect(fn () => (new GoogleProvider)->distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving)))
        ->toThrow(ProviderUnavailableException::class, 'Geolocation provider [google] is unavailable: HTTP 502');
});

it('answers null for a leg without a route', function (array $element): void {
    Http::fake(['routes.googleapis.com/*' => Http::response([$element])]);

    expect((new GoogleProvider)->distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving)))->toBeNull();
})->with([
    'route not found' => [['originIndex' => 0, 'destinationIndex' => 0, 'status' => [], 'condition' => 'ROUTE_NOT_FOUND']],
    'element error' => [['originIndex' => 0, 'destinationIndex' => 0, 'status' => ['code' => 5, 'message' => 'Not found.'], 'condition' => 'ROUTE_MATRIX_ELEMENT_CONDITION_UNSPECIFIED']],
    'malformed duration' => [['originIndex' => 0, 'destinationIndex' => 0, 'status' => [], 'condition' => 'ROUTE_EXISTS', 'distanceMeters' => 5, 'duration' => 'soon']],
]);

it('answers null when the routes api returns no element', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::response([])]);

    expect((new GoogleProvider)->distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving)))->toBeNull();
});

it('reads fractional durations and omitted zero fields', function (): void {
    // Proto3 JSON leaves zero values out: no indices, no distanceMeters for a 0 m leg.
    Http::fake(['routes.googleapis.com/*' => Http::response([
        ['status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '90.6s'],
    ])]);

    expect((new GoogleProvider)->distance(new DistanceQuery(1, 2, 1, 2, DistanceType::Driving))?->toArray())->toBe([
        'humanReadableDistance' => '0 m',
        'distanceInMeters' => 0,
        'humanReadableDuration' => '91 s',
        'durationInSeconds' => 91,
        'type' => 'Driving',
    ]);
});

it('places matrix cells by the indices google returns, in any order', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::response([
        routeElement(1, 1, 1100, '110s'),
        routeElement(0, 1, 100, '10s'),
        ['originIndex' => 1, 'destinationIndex' => 0, 'status' => [], 'condition' => 'ROUTE_NOT_FOUND'],
        routeElement(0, 0, 0, '0s'),
        'junk',
    ])]);

    $matrix = (new GoogleProvider)->distanceMatrix(
        [new Coordinates(1, 2), new Coordinates(3, 4)],
        [new Coordinates(5, 6), new Coordinates(7, 8)],
    );

    expect($matrix->get(0, 0)?->distanceInMeters)->toBe(0)
        ->and($matrix->get(0, 1)?->distanceInMeters)->toBe(100)
        ->and($matrix->get(1, 0))->toBeNull()
        ->and($matrix->get(1, 1)?->durationInSeconds)->toBe(110)
        ->and(array_map(fn (array $row): int => count($row), $matrix->rows))->toBe([2, 2]);
});

it('degrades every matrix cell to null when the routes api rejects the request', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']], 403)]);

    $matrix = (new GoogleProvider)->distanceMatrix([new Coordinates(1, 2)], [new Coordinates(3, 4), new Coordinates(5, 6)]);

    expect($matrix->rows)->toBe([[0 => null, 1 => null]]);
});

it('calls the configured routes url', function (): void {
    config()->set('geolocation.services.google.routes_url', 'https://routes.example.test/');
    Http::fake(['routes.example.test/*' => Http::response([routeElement(0, 0, 1, '1s')])]);

    (new GoogleProvider)->distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://routes.example.test/distanceMatrix/v2:computeRouteMatrix');
});

it('refuses a non-string routes url (strict config)', function (): void {
    config()->set('geolocation.services.google.routes_url', 443);
    Http::fake();

    expect(fn () => (new GoogleProvider)->distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving)))
        ->toThrow(InvalidConfigurationException::class, 'geolocation.services.google.routes_url');

    Http::assertNothingSent();
});

it('serves the readme distanceBetween() example from the routes api', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::response([routeElement(0, 0, 133000, '5400s', '133 km', '1 hour 30 mins')])]);

    $distance = Geolocation::distanceBetween(
        new Coordinates(48.1482, 17.1067),
        new Coordinates(49.2000, 16.6068),
        DistanceType::Driving,
    );

    expect($distance?->humanReadableDistance)->toBe('133 km')
        ->and($distance?->durationInSeconds)->toBe(5400);

    Http::assertSentCount(1);
});

/**
 * A Routes API stand-in that answers every pair of the request it got, encoding the pair's
 * ABSOLUTE position (origin latitude × 10, destination longitude × 10) into distanceMeters,
 * and recording each request's size.
 *
 * @param  list<array{origins: int, destinations: int}>  $sizes
 */
function routeMatrixEcho(array &$sizes): Closure
{
    return static function (Request $request) use (&$sizes) {
        $origins = $request['origins'];
        $destinations = $request['destinations'];
        $sizes[] = ['origins' => count($origins), 'destinations' => count($destinations)];
        $elements = [];

        foreach ($origins as $o => $origin) {
            foreach ($destinations as $d => $destination) {
                $absolute = (int) round($origin['waypoint']['location']['latLng']['latitude'] * 10) * 10000
                    + (int) round($destination['waypoint']['location']['latLng']['longitude'] * 10);
                $elements[] = routeElement($o, $d, $absolute, '1s');
            }
        }

        return Http::response($elements);
    };
}

it('splits a matrix over the routes api element limit and merges cells by index', function (int $originCount, int $destinationCount, int $requests): void {
    $sizes = [];
    Http::fake(['routes.googleapis.com/*' => routeMatrixEcho($sizes)]);

    $origins = array_map(fn (int $i): Coordinates => new Coordinates($i / 10, 0.0), range(0, $originCount - 1));
    $destinations = array_map(fn (int $j): Coordinates => new Coordinates(0.0, $j / 10), range(0, $destinationCount - 1));

    $matrix = (new GoogleProvider)->distanceMatrix($origins, $destinations);

    expect($sizes)->toHaveCount($requests);

    foreach ($sizes as $size) {
        expect($size['origins'] * $size['destinations'])->toBeLessThanOrEqual(625);
    }

    foreach (array_keys($origins) as $i) {
        foreach (array_keys($destinations) as $j) {
            expect($matrix->get($i, $j)?->distanceInMeters)->toBe($i * 10000 + $j);
        }
    }
})->with([
    '26 x 25' => [26, 25, 2],
    '1 x 700' => [1, 700, 2],
    '30 x 30' => [30, 30, 2],
    '25 x 25 fits' => [25, 25, 1],
]);

it('keeps the answered tiles when one tile of a split matrix fails', function (): void {
    $calls = 0;
    Http::fake(['routes.googleapis.com/*' => function (Request $request) use (&$calls) {
        $calls++;

        if ($calls === 2) {
            return Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429);
        }

        $elements = [];

        foreach (array_keys($request['origins']) as $o) {
            foreach (array_keys($request['destinations']) as $d) {
                $elements[] = routeElement($o, $d, 1, '1s');
            }
        }

        return Http::response($elements);
    }]);

    $matrix = (new GoogleProvider)->distanceMatrix(
        array_fill(0, 26, new Coordinates(1, 2)),
        array_fill(0, 25, new Coordinates(3, 4)),
    );

    $answered = array_sum(array_map(fn (array $row): int => count(array_filter($row)), $matrix->rows));

    expect($calls)->toBe(2)
        ->and($answered)->toBeGreaterThan(0)
        ->and($answered)->toBeLessThan(26 * 25)
        ->and(array_map(fn (array $row): int => count($row), $matrix->rows))->toBe(array_fill(0, 26, 25));
});

it('ignores an element whose index is outside the request', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::response([routeElement(3, 0, 1, '1s'), routeElement(0, 7, 1, '1s')])]);

    $matrix = (new GoogleProvider)->distanceMatrix([new Coordinates(1, 2)], [new Coordinates(3, 4)]);

    expect($matrix->rows)->toBe([[0 => null]]);
});
