<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Events\LocationResolutionFailed;
use RoundlyConsulting\Geolocation\Exceptions\ProviderUnavailableException;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\Providers\GoogleProvider;
use RoundlyConsulting\Geolocation\Providers\IP2LocationProvider;
use RoundlyConsulting\Geolocation\Providers\IpInfoProvider;
use RoundlyConsulting\Geolocation\Providers\MaxMindWebServiceProvider;

beforeEach(function (): void {
    Sleep::fake();

    config()->set('geolocation.services.google.key', 'GOOGLE-SECRET');
    config()->set('geolocation.services.ip2location.key', 'IP2L-SECRET');
    config()->set('geolocation.services.ipinfo.token', 'IPINFO-SECRET');
    config()->set('geolocation.services.maxmind_web.enabled', true);
    config()->set('geolocation.services.maxmind_web.account_id', '42');
    config()->set('geolocation.services.maxmind_web.license_key', 'MM-SECRET');
});

it('falls through to the next provider when an ip provider cannot connect', function (): void {
    config()->set('geolocation.pipeline', ['maxmind_web', 'ip2location', 'ipinfo']);

    Http::fake([
        'geoip.maxmind.com/*' => failedConnection(),
        'api.ip2location.io/*' => failedConnection(),
        'ipinfo.io/*' => Http::response(['city' => 'Reached', 'country' => 'US', 'loc' => '1,2']),
    ]);

    expect(Geolocation::locateIp('8.8.8.8')?->city)->toBe('Reached');
});

it('falls through to the next distance provider when google cannot connect', function (): void {
    config()->set('geolocation.pipeline', ['google']);
    Http::fake(['*' => failedConnection()]);

    expect(Geolocation::distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving)))->toBeNull();
});

it('degrades every distance matrix cell to null when google cannot connect', function (): void {
    config()->set('geolocation.pipeline', ['google']);
    Http::fake(['*' => failedConnection()]);

    $matrix = Geolocation::distanceMatrix([new Coordinates(1, 2)], [new Coordinates(3, 4)]);

    expect($matrix->get(0, 0))->toBeNull();
});

it('turns a transport failure into a redacted ProviderUnavailableException', function (object $provider, GeolocationQuery $query, string $name, string $secret): void {
    Http::fake(['*' => failedConnection()]);

    try {
        $provider->locate($query);
        test()->fail('Expected a ProviderUnavailableException.');
    } catch (ProviderUnavailableException $e) {
        expect($e->provider)->toBe($name)
            ->and($e->getMessage())->toContain("[{$name}]")
            ->and($e->getMessage())->not->toContain($secret)
            ->and($e->getPrevious())->toBeNull();
    }
})->with([
    'google' => fn (): array => [new GoogleProvider, GeolocationQuery::forAddress('1 Main St'), 'google', 'GOOGLE-SECRET'],
    'ip2location' => fn (): array => [new IP2LocationProvider, GeolocationQuery::forIp('8.8.8.8'), 'ip2location', 'IP2L-SECRET'],
    'ipinfo' => fn (): array => [new IpInfoProvider, GeolocationQuery::forIp('8.8.8.8'), 'ipinfo', 'IPINFO-SECRET'],
    'maxmind_web' => fn (): array => [new MaxMindWebServiceProvider, GeolocationQuery::forIp('8.8.8.8'), 'maxmind_web', 'MM-SECRET'],
]);

it('keeps the google key out of a failed distance lookup', function (): void {
    Http::fake(['*' => failedConnection()]);

    // The Routes API key travels in a header, so the URL a transport error quotes never has it.
    expect(fn () => (new GoogleProvider)->distance(new DistanceQuery(1, 2, 3, 4, DistanceType::Driving)))
        ->toThrow(fn (ProviderUnavailableException $e) => expect($e->getMessage())
            ->not->toContain('GOOGLE-SECRET')
            ->toContain('routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix'));
});

it('reports the failing provider and its redacted error when nothing resolves', function (): void {
    config()->set('geolocation.pipeline', ['google']);
    Http::fake(['*' => failedConnection()]);
    Event::fake([LocationResolutionFailed::class]);

    expect(Geolocation::locateAddress('1 Main St'))->toBeNull();

    Event::assertDispatched(LocationResolutionFailed::class, fn (LocationResolutionFailed $event): bool => $event->provider === 'google'
        && $event->error instanceof ProviderUnavailableException
        && ! str_contains($event->error->getMessage(), 'GOOGLE-SECRET'));
});
