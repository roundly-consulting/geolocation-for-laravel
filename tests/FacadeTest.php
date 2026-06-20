<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeGeolocationProvider;

it('resolves the manager from the facade accessor', function (): void {
    expect(Geolocation::getFacadeRoot())->toBeInstanceOf(GeolocationManager::class);
});

it('proxies calls through the facade', function (): void {
    config()->set('geolocation.providers', ['fake' => FakeGeolocationProvider::class]);
    config()->set('geolocation.pipeline', ['fake']);

    expect(Geolocation::locate(new GeolocationQuery('1.1.1.1')))->city->toBe('Smallville');
});

it('exposes the registered class alias', function (): void {
    expect(class_exists('Geolocation'))->toBeTrue();
});
