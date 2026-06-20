<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeGeolocationProvider;

beforeEach(function (): void {
    config()->set('geolocation.providers', ['fake' => FakeGeolocationProvider::class]);
    config()->set('geolocation.pipeline', ['fake']);
});

it('renders a resolved location as a table', function (): void {
    $this->artisan('geolocation:locate', ['target' => '8.8.8.8'])
        ->assertSuccessful()
        ->expectsOutputToContain('Smallville');
});

it('outputs json when requested', function (): void {
    $this->artisan('geolocation:locate', ['target' => '8.8.8.8', '--json' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('"city": "Smallville"');
});

it('treats the target as an address with the address flag', function (): void {
    app()->bind(GeolocationManager::class, function (): GeolocationManager {
        return new class extends GeolocationManager
        {
            public function locate(GeolocationQuery $query): ?Location
            {
                expect($query->address)->toBe('Mountain View');

                return new Location('Mountain View', '', 'Mountain View', 'US', 1.0, 2.0, GeolocationType::Geolocation);
            }
        };
    });

    $this->artisan('geolocation:locate', ['target' => 'Mountain View', '--address' => true])
        ->assertSuccessful();
});

it('exits non-zero when nothing resolves', function (): void {
    app()->bind(GeolocationManager::class, function (): GeolocationManager {
        return new class extends GeolocationManager
        {
            public function locate(GeolocationQuery $query): ?Location
            {
                return null;
            }
        };
    });

    $this->artisan('geolocation:locate', ['target' => '0.0.0.0'])->assertFailed();
});
