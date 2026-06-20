<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\Exceptions\DatabaseNotFoundException;
use RoundlyConsulting\Geolocation\Providers\MaxMindDatabaseProvider;

it('throws an actionable exception when the database file is missing', function () {
    config()->set('geolocation.services.maxmind.database.enabled', true);
    config()->set('geolocation.services.maxmind.database.path', '/tmp/does-not-exist-'.uniqid().'.mmdb');

    (new MaxMindDatabaseProvider)->locate(new GeolocationQuery('8.8.8.8'));
})->throws(DatabaseNotFoundException::class, 'geolocation:db:update');

it('throws an actionable exception when no path is configured', function () {
    config()->set('geolocation.services.maxmind.database.enabled', true);
    config()->set('geolocation.services.maxmind.database.path', null);

    (new MaxMindDatabaseProvider)->locate(new GeolocationQuery('8.8.8.8'));
})->throws(DatabaseNotFoundException::class, 'geolocation:db:update');

it('does not touch the database when the provider is disabled', function () {
    config()->set('geolocation.services.maxmind.database.enabled', false);
    config()->set('geolocation.services.maxmind.database.path', '/tmp/missing.mmdb');

    expect((new MaxMindDatabaseProvider)->locate(new GeolocationQuery('8.8.8.8')))->toBeNull();
});
