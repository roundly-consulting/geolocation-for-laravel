<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Geolocation\Support\ProviderOverrides;

it('binds the geolocation manager and its container alias', function (): void {
    expect(app(GeolocationManager::class))->toBeInstanceOf(GeolocationManager::class)
        ->and(app('geolocation'))->toBe(app(GeolocationManager::class))
        ->and(app(ProviderOverrides::class))->toBe(app(ProviderOverrides::class));
});

it('registers the package commands', function (): void {
    expect(Artisan::all())
        ->toHaveKey('geolocation:locate')
        ->toHaveKey('geolocation:db:update');
});

it('merges the package config', function (): void {
    expect(config('geolocation.pipeline'))->toBeArray()
        ->and(config('geolocation.timeout'))->toBe(5);
});

it('publishes the config under the geolocation-config tag', function (): void {
    expect(ServiceProvider::pathsToPublish(null, 'geolocation-config'))->not->toBeEmpty();
});

it('contributes a section to the about command', function (): void {
    $this->artisan('about', ['--only' => 'geolocation'])
        ->expectsOutputToContain('Pipeline')
        ->expectsOutputToContain('OFF')
        ->assertSuccessful();
});

it('reports enabled caching and events to the about command', function (): void {
    config()->set('geolocation.cache.enabled', true);

    $this->artisan('about', ['--only' => 'geolocation'])
        ->expectsOutputToContain('ENABLED')
        ->assertSuccessful();
});

it('reports an empty pipeline to the about command', function (): void {
    config()->set('geolocation.pipeline', []);
    config()->set('geolocation.providers', []);

    $this->artisan('about', ['--only' => 'geolocation'])
        ->expectsOutputToContain('NONE')
        ->assertSuccessful();
});
