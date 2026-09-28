<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

afterEach(function () {
    $path = storage_path('app/geolocation/GeoLite2-City.mmdb');
    if (is_file($path)) {
        unlink($path);
    }
});

it('fails without a license key', function () {
    config()->set('geolocation.services.maxmind_database.license_key', '');

    $this->artisan('geolocation:db:update')
        ->expectsOutputToContain('license key is required')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('fails when no destination path is configured', function () {
    config()->set('geolocation.services.maxmind_database.license_key', 'key');
    config()->set('geolocation.services.maxmind_database.path', '');

    $this->artisan('geolocation:db:update')
        ->expectsOutputToContain('No destination path')
        ->assertExitCode(1);
});

it('downloads and writes the mmdb database', function () {
    $destination = storage_path('app/geolocation/Test-City.mmdb');

    config()->set('geolocation.services.maxmind_database.license_key', 'key');
    config()->set('geolocation.services.maxmind_database.edition', 'GeoLite2-City');

    Http::fake([
        'download.maxmind.com/*' => Http::response(fakeMaxMindArchive('GeoLite2-City', 'BINARY-DB')),
    ]);

    $this->artisan('geolocation:db:update', ['--path' => $destination])
        ->assertExitCode(0);

    expect(file_get_contents($destination))->toBe('BINARY-DB');

    unlink($destination);
});

it('reports a clear error when the download fails', function () {
    config()->set('geolocation.services.maxmind_database.license_key', 'key');

    Http::fake([
        'download.maxmind.com/*' => Http::response(status: 401),
    ]);

    $this->artisan('geolocation:db:update', ['--path' => storage_path('app/geolocation/x.mmdb')])
        ->expectsOutputToContain('Download failed')
        ->assertExitCode(1);
});

it('reports a clear error when the archive is not valid gzip', function () {
    config()->set('geolocation.services.maxmind_database.license_key', 'key');

    Http::fake([
        'download.maxmind.com/*' => Http::response('not-gzip-data'),
    ]);

    $this->artisan('geolocation:db:update', ['--path' => storage_path('app/geolocation/x.mmdb')])
        ->expectsOutputToContain('Could not unpack')
        ->assertExitCode(1);
});
