<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\Actions\UpdateDatabaseAction;
use RoundlyConsulting\Geolocation\Exceptions\DatabaseUpdateException;

beforeEach(function (): void {
    config()->set('geolocation.services.maxmind_database.license_key', 'key');
    config()->set('geolocation.services.maxmind_database.edition', 'GeoLite2-City');
    config()->set('geolocation.services.maxmind_database.path', storage_path('app/geolocation/Action-City.mmdb'));
});

afterEach(function (): void {
    @unlink(storage_path('app/geolocation/Action-City.mmdb'));
});

it('downloads, unpacks and writes the database, returning the path', function (): void {
    Http::fake(['download.maxmind.com/*' => Http::response(fakeMaxMindArchive('GeoLite2-City', 'ACTION-DB'))]);

    $path = app(UpdateDatabaseAction::class)->execute();

    expect($path)->toBe(storage_path('app/geolocation/Action-City.mmdb'))
        ->and(file_get_contents($path))->toBe('ACTION-DB');

    Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'license_key=key')
        && str_contains($request->url(), 'suffix=tar.gz'));
});

it('falls back to the first database in the archive when the edition name differs', function (): void {
    Http::fake(['download.maxmind.com/*' => Http::response(fakeMaxMindArchive('GeoLite2-City', 'RENAMED-DB', 'Other.mmdb'))]);

    $path = app(UpdateDatabaseAction::class)->execute();

    expect(file_get_contents($path))->toBe('RENAMED-DB');
});

it('refuses without a license key', function (): void {
    config()->set('geolocation.services.maxmind_database.license_key', '');

    app(UpdateDatabaseAction::class)->execute();
})->throws(DatabaseUpdateException::class, 'license key is required');

it('refuses without a destination path', function (): void {
    config()->set('geolocation.services.maxmind_database.path', '');

    app(UpdateDatabaseAction::class)->execute();
})->throws(DatabaseUpdateException::class, 'No destination path');

it('wraps a failed download', function (): void {
    Http::fake(['download.maxmind.com/*' => Http::response(status: 401)]);

    try {
        app(UpdateDatabaseAction::class)->execute();
        $this->fail('Expected a DatabaseUpdateException.');
    } catch (DatabaseUpdateException $e) {
        expect($e->getMessage())->toStartWith('Download failed')
            ->and($e->getPrevious())->not->toBeNull();
    }
});

it('wraps an archive that is not gzip', function (): void {
    Http::fake(['download.maxmind.com/*' => Http::response('not-gzip-data')]);

    app(UpdateDatabaseAction::class)->execute();
})->throws(DatabaseUpdateException::class, 'not valid gzip data');

it('wraps an archive without a database file', function (): void {
    Http::fake(['download.maxmind.com/*' => Http::response(fakeMaxMindArchive('GeoLite2-City', 'readme', 'README.txt'))]);

    app(UpdateDatabaseAction::class)->execute();
})->throws(DatabaseUpdateException::class, 'No .mmdb file was found inside the [GeoLite2-City] archive.');

it('leaves no temporary archive behind', function (): void {
    Http::fake(['download.maxmind.com/*' => Http::response('not-gzip-data')]);
    $before = glob(sys_get_temp_dir().'/mmdb*') ?: [];

    try {
        app(UpdateDatabaseAction::class)->execute();
    } catch (DatabaseUpdateException) {
    }

    expect(glob(sys_get_temp_dir().'/mmdb*') ?: [])->toBe($before);
});
