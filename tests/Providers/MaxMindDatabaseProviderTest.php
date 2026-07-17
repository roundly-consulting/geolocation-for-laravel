<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Exceptions\DatabaseNotFoundException;
use RoundlyConsulting\Geolocation\Providers\MaxMindDatabaseProvider;

function cityDatabasePath(): string
{
    return __DIR__.'/../Fixtures/test-data/GeoIP2-City-Test.mmdb';
}

beforeEach(function (): void {
    config()->set('geolocation.services.maxmind_database.enabled', true);
    config()->set('geolocation.services.maxmind_database.path', cityDatabasePath());
});

it('returns null when the database provider is disabled', function (): void {
    config()->set('geolocation.services.maxmind_database.enabled', false);

    expect((new MaxMindDatabaseProvider)->locate(new GeolocationQuery('2.125.160.216')))->toBeNull();
});

it('returns null for a missing or invalid ip', function (): void {
    $provider = new MaxMindDatabaseProvider;

    expect($provider->locate(new GeolocationQuery))->toBeNull()
        ->and($provider->locate(new GeolocationQuery('not-an-ip')))->toBeNull();
});

it('maps a database record into a location', function (): void {
    $location = (new MaxMindDatabaseProvider)->locate(new GeolocationQuery('2.125.160.216'));

    expect($location)->toBeInstanceOf(Location::class)
        ->city->toBe('Boxford')
        ->countryIsoCode->toBe('GB')
        ->region->toBe('England')
        ->postalCode->toBe('OX1')
        ->timezone->toBe('Europe/London')
        ->latitude->toBe(51.75)
        ->longitude->toBe(-1.25)
        ->humanReadable->toBe('Boxford, England, GB')
        ->type->toBe(GeolocationType::Ip);
});

it('returns null for an ip absent from the database', function (): void {
    expect((new MaxMindDatabaseProvider)->locate(new GeolocationQuery('10.10.10.10')))->toBeNull();
});

it('throws when the configured database path is missing', function (): void {
    config()->set('geolocation.services.maxmind_database.path', null);

    (new MaxMindDatabaseProvider)->locate(new GeolocationQuery('2.125.160.216'));
})->throws(DatabaseNotFoundException::class);

it('reuses the reader across lookups', function (): void {
    $provider = new MaxMindDatabaseProvider;

    expect($provider->locate(new GeolocationQuery('2.125.160.216')))->not->toBeNull()
        ->and($provider->locate(new GeolocationQuery('81.2.69.142')))->not->toBeNull();
});
