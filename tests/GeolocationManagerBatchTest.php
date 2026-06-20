<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceMatrix;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\GeolocationManager;

beforeEach(function () {
    config()->set('geolocation.pipeline', ['ipinfo']);
});

it('locates a batch of ips and tolerates per-item failures', function () {
    Http::fake([
        'ipinfo.io/1.1.1.1/json' => Http::response(['city' => 'One', 'country' => 'US', 'loc' => '1,2']),
        'ipinfo.io/2.2.2.2/json' => Http::response(status: 500),
    ]);

    $results = Geolocation::batch(['1.1.1.1', '2.2.2.2']);

    expect($results['1.1.1.1']?->city)->toBe('One')
        ->and($results['2.2.2.2'])->toBeNull();
});

it('builds a distance matrix via the google provider', function () {
    config()->set('geolocation.pipeline', ['google']);

    Http::fake([
        '*distancematrix*' => Http::response([
            'rows' => [
                ['elements' => [
                    ['status' => 'OK', 'distance' => ['text' => '5 km', 'value' => 5000], 'duration' => ['text' => '6 mins', 'value' => 360]],
                    ['status' => 'ZERO_RESULTS'],
                ]],
            ],
        ]),
    ]);

    $matrix = Geolocation::distanceMatrix(
        [new Coordinates(48.1, 17.1)],
        [new Coordinates(48.2, 16.3), new Coordinates(0.0, 0.0)],
        DistanceType::Driving,
    );

    expect($matrix)->toBeInstanceOf(DistanceMatrix::class)
        ->and($matrix->get(0, 0)?->distanceInMeters)->toBe(5000)
        ->and($matrix->get(0, 1))->toBeNull();
});

it('returns an empty matrix when no google provider is in the pipeline', function () {
    $matrix = Geolocation::distanceMatrix([new Coordinates(1, 2)], [new Coordinates(3, 4)]);

    expect($matrix->rows)->toBe([]);
});

it('passes a call-time token override to the provider', function () {
    Http::fake([
        'ipinfo.io/*' => Http::response(['city' => 'Tokened', 'country' => 'US', 'loc' => '1,2']),
    ]);

    Geolocation::withToken('runtime-token')->locateIp('8.8.8.8');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer runtime-token'));
});

it('clears overrides after a resolution', function () {
    Http::fake([
        'ipinfo.io/*' => Http::response(['city' => 'X', 'country' => 'US', 'loc' => '1,2']),
    ]);

    Geolocation::withToken('once')->locateIp('8.8.8.8');
    Geolocation::locateIp('8.8.8.8');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
});

it('runs exactly one provider when pinned via provider()', function () {
    config()->set('geolocation.pipeline', ['ipinfo', 'google']);

    Http::fake([
        'ipinfo.io/*' => Http::response(['city' => 'Solo', 'country' => 'US', 'loc' => '1,2']),
    ]);

    expect(Geolocation::provider('ipinfo')->locateIp('8.8.8.8')?->city)->toBe('Solo');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'ipinfo.io'));
});

it('applies a timeout and arbitrary config override for one resolution', function () {
    Http::fake([
        'ipinfo.io/*' => Http::response(['city' => 'X', 'country' => 'US', 'loc' => '1,2']),
    ]);

    $manager = app(GeolocationManager::class);

    expect($manager->withTimeout(11)->withConfig(['token' => 'cfg-token'])->locateIp('8.8.8.8'))
        ->not->toBeNull();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer cfg-token'));
});

it('is macroable', function () {
    GeolocationManager::macro('hello', fn (): string => 'world');

    expect(app(GeolocationManager::class)->hello())->toBe('world');
});
