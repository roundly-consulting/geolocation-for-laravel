<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\Concerns\HasProviderOverrides;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Geolocation\GeolocationProvider;
use RoundlyConsulting\Geolocation\Support\ProviderOverrides;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeAlternativeGeolocationProvider;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeGeolocationProvider;

beforeEach(function (): void {
    config()->set('geolocation.providers', [
        'fake' => FakeGeolocationProvider::class,
        'alt' => FakeAlternativeGeolocationProvider::class,
    ]);
    config()->set('geolocation.pipeline', ['fake']);

    app(GeolocationManager::class)->extend('broken', fn () => new class implements GeolocationProvider
    {
        public function locate(GeolocationQuery $query): ?Location
        {
            throw new RuntimeException('provider blew up');
        }
    });
});

it('does not stay pinned to a provider after that provider threw', function (): void {
    expect(fn () => Geolocation::provider('broken')->locateIp('1.1.1.1'))->toThrow(RuntimeException::class);

    expect(Geolocation::locateIp('1.1.1.1')?->city)->toBe('Smallville');
});

it('never changes the shared manager when a call is scoped', function (): void {
    $scoped = Geolocation::provider('alt');

    expect(Geolocation::locateIp('1.1.1.1')?->city)->toBe('Smallville')
        ->and($scoped)->not->toBe(app(GeolocationManager::class))
        ->and($scoped->locateIp('1.1.1.1')?->humanReadable)->toBe('Alternative Human Readable');
});

it('restores call-time overrides even when a provider throws', function (): void {
    expect(fn () => Geolocation::withTimeout(7)->provider('broken')->locateIp('1.1.1.1'))->toThrow(RuntimeException::class);

    expect(app(ProviderOverrides::class)->get('timeout'))->toBeNull();
});

it('applies the provider scope to every ip of a batch', function (): void {
    $results = Geolocation::provider('alt')->batch(['1.1.1.1', '2.2.2.2', '3.3.3.3']);

    expect(array_map(static fn (?Location $location): ?string => $location?->humanReadable, $results))->toBe([
        '1.1.1.1' => 'Alternative Human Readable',
        '2.2.2.2' => 'Alternative Human Readable',
        '3.3.3.3' => 'Alternative Human Readable',
    ]);
});

it('applies call-time overrides to every ip of a batch', function (): void {
    $seen = new ArrayObject;

    app(GeolocationManager::class)->extend('spy', fn () => new class($seen) implements GeolocationProvider
    {
        use HasProviderOverrides;

        /** @param ArrayObject<int, mixed> $seen */
        public function __construct(private readonly ArrayObject $seen) {}

        public function locate(GeolocationQuery $query): ?Location
        {
            $this->seen[] = $this->override('timeout');

            return null;
        }
    });
    config()->set('geolocation.pipeline', ['spy']);

    Geolocation::withTimeout(9)->batch(['1.1.1.1', '2.2.2.2']);

    expect($seen->getArrayCopy())->toBe([9, 9])
        ->and(app(ProviderOverrides::class)->get('timeout'))->toBeNull();
});
