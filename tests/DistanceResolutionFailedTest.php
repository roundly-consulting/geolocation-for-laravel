<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DistanceProvider;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Events\DistanceResolutionFailed;
use RoundlyConsulting\Geolocation\Events\DistanceResolved;
use RoundlyConsulting\Geolocation\Events\LocationResolutionFailed;
use RoundlyConsulting\Geolocation\Exceptions\ProviderUnavailableException;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeDistanceProvider;

beforeEach(function (): void {
    config()->set('geolocation.events.enabled', true);
    config()->set('geolocation.cache.enabled', false);
    config()->set('geolocation.services.google.key', 'GOOGLE-SECRET');
});

function missingDistanceProvider(): DistanceProvider
{
    return new class implements DistanceProvider
    {
        public function distance(DistanceQuery $query): ?Distance
        {
            return null;
        }
    };
}

it('names the provider and its redacted error when the routes api rejects the request', function (): void {
    config()->set('geolocation.pipeline', ['google']);
    Http::fake(['routes.googleapis.com/*' => Http::response(['error' => [
        'code' => 403,
        'message' => 'Routes API is disabled for key GOOGLE-SECRET.',
        'status' => 'PERMISSION_DENIED',
    ]], 403)]);
    Event::fake([DistanceResolutionFailed::class, DistanceResolved::class]);
    $query = DistanceQuery::between(new Coordinates(1, 2), new Coordinates(3, 4));

    expect(Geolocation::distance($query))->toBeNull();

    Event::assertDispatchedTimes(DistanceResolutionFailed::class, 1);
    Event::assertDispatched(DistanceResolutionFailed::class, fn (DistanceResolutionFailed $event): bool => $event->query === $query
        && $event->provider === 'google'
        && $event->error instanceof ProviderUnavailableException
        && str_contains($event->error->getMessage(), 'PERMISSION_DENIED: Routes API is disabled for key [redacted].'));
    Event::assertNotDispatched(DistanceResolved::class);
});

it('fires through distanceBetween() too', function (): void {
    config()->set('geolocation.pipeline', ['google']);
    Http::fake(['routes.googleapis.com/*' => failedConnection()]);
    config()->set('geolocation.services.google.retry', 1);
    Event::fake([DistanceResolutionFailed::class]);

    expect(Geolocation::distanceBetween(new Coordinates(1, 2), new Coordinates(3, 4), DistanceType::Walking))->toBeNull();

    Event::assertDispatched(DistanceResolutionFailed::class, fn (DistanceResolutionFailed $event): bool => $event->query->type === DistanceType::Walking
        && $event->provider === 'google'
        && $event->error instanceof ProviderUnavailableException);
});

it('reports the last unreachable provider when a later one simply misses', function (): void {
    config()->set('geolocation.pipeline', ['google', 'empty']);
    Geolocation::extend('empty', fn (): DistanceProvider => missingDistanceProvider());
    Http::fake(['routes.googleapis.com/*' => Http::response(status: 503)]);
    config()->set('geolocation.services.google.retry', 1);
    Event::fake([DistanceResolutionFailed::class]);

    expect(Geolocation::distanceBetween(new Coordinates(1, 2), new Coordinates(3, 4)))->toBeNull();

    Event::assertDispatched(DistanceResolutionFailed::class, fn (DistanceResolutionFailed $event): bool => $event->provider === 'google'
        && $event->error instanceof ProviderUnavailableException);
});

it('leaves provider and error null when every provider simply misses', function (): void {
    config()->set('geolocation.pipeline', ['empty']);
    Geolocation::extend('empty', fn (): DistanceProvider => missingDistanceProvider());
    Event::fake([DistanceResolutionFailed::class]);

    expect(Geolocation::distanceBetween(new Coordinates(1, 2), new Coordinates(3, 4)))->toBeNull();

    Event::assertDispatched(DistanceResolutionFailed::class, fn (DistanceResolutionFailed $event): bool => $event->provider === null
        && $event->error === null);
});

it('fires when no provider in the pipeline measures distances', function (): void {
    config()->set('geolocation.pipeline', ['ipinfo']);
    Event::fake([DistanceResolutionFailed::class]);

    expect(Geolocation::distanceBetween(new Coordinates(1, 2), new Coordinates(3, 4)))->toBeNull();

    Event::assertDispatched(DistanceResolutionFailed::class, fn (DistanceResolutionFailed $event): bool => $event->provider === null);
});

it('fires before rethrowing what a provider throws', function (): void {
    $boom = new RuntimeException('router exploded');
    config()->set('geolocation.pipeline', ['broken']);
    Geolocation::extend('broken', fn (): DistanceProvider => new class($boom) implements DistanceProvider
    {
        public function __construct(private RuntimeException $boom) {}

        public function distance(DistanceQuery $query): ?Distance
        {
            throw $this->boom;
        }
    });
    Event::fake([DistanceResolutionFailed::class]);

    expect(fn () => Geolocation::distanceBetween(new Coordinates(1, 2), new Coordinates(3, 4)))
        ->toThrow(RuntimeException::class, 'router exploded');

    Event::assertDispatched(DistanceResolutionFailed::class, fn (DistanceResolutionFailed $event): bool => $event->provider === 'broken'
        && $event->error === $boom);
});

it('stays quiet when a distance resolves', function (): void {
    config()->set('geolocation.providers.distance', FakeDistanceProvider::class);
    config()->set('geolocation.pipeline', ['distance']);
    Event::fake([DistanceResolutionFailed::class, DistanceResolved::class]);

    expect(app(GeolocationManager::class)->distanceBetween(new Coordinates(1, 2), new Coordinates(3, 4)))->toBeInstanceOf(Distance::class);

    Event::assertDispatched(DistanceResolved::class);
    Event::assertNotDispatched(DistanceResolutionFailed::class);
});

it('is governed by events.enabled', function (): void {
    config()->set('geolocation.events.enabled', false);
    config()->set('geolocation.pipeline', ['empty']);
    Geolocation::extend('empty', fn (): DistanceProvider => missingDistanceProvider());
    Event::fake();

    Geolocation::distanceBetween(new Coordinates(1, 2), new Coordinates(3, 4));

    Event::assertNothingDispatched();
});

it('is not dispatched by the fake, which runs no provider — like every resolution event', function (): void {
    Event::fake();
    $fake = Geolocation::fake();

    expect(Geolocation::distanceBetween(new Coordinates(1, 2), new Coordinates(3, 4)))->toBeNull()
        ->and(Geolocation::locateIp('1.1.1.1'))->toBeNull();

    Event::assertNotDispatched(DistanceResolutionFailed::class);
    Event::assertNotDispatched(LocationResolutionFailed::class);
    $fake->assertDistanceRequested();
});
