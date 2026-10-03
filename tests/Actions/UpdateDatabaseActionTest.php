<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RoundlyConsulting\Geolocation\Actions\UpdateDatabaseAction;
use RoundlyConsulting\Geolocation\Exceptions\DatabaseUpdateException;
use RoundlyConsulting\Geolocation\Tests\TestCase;

beforeEach(function (): void {
    $this->sandboxStorage();

    config()->set('geolocation.services.maxmind_database.license_key', 'key');
    config()->set('geolocation.services.maxmind_database.edition', 'GeoLite2-City');
    config()->set('geolocation.services.maxmind_database.path', storage_path('app/geolocation/Action-City.mmdb'));
});

/**
 * The database lands in a throwaway storage/ per test ({@see TestCase::sandboxStorage()}),
 * never the testbench skeleton's — where another process's in-flight `.tmp` would fail the
 * "replaced atomically" glob below.
 */
it('writes into the sandbox, never the shared skeleton', function (): void {
    expect(storage_path('app/geolocation'))->toContain('geolocation-storage-');
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
            ->and($e->getPrevious())->toBeNull();
    }
});

it('wraps a connection failure and never leaks the license key', function (): void {
    Sleep::fake();
    config()->set('geolocation.services.maxmind_database.license_key', 'MM-LICENSE-SECRET');
    Http::fake(['download.maxmind.com/*' => failedConnection()]);

    try {
        app(UpdateDatabaseAction::class)->execute();
        $this->fail('Expected a DatabaseUpdateException.');
    } catch (DatabaseUpdateException $e) {
        expect($e->getMessage())->toStartWith('Download failed')
            ->not->toContain('MM-LICENSE-SECRET')
            ->toContain('license_key=[redacted]')
            // The transport exception quotes the full URL: it is not chained.
            ->and($e->getPrevious())->toBeNull();
    }
});

it('replaces the live database atomically instead of rewriting it in place', function (): void {
    $path = storage_path('app/geolocation/Action-City.mmdb');
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, 'OLD-DB');
    $inodeBefore = fileinode($path);
    Http::fake(['download.maxmind.com/*' => Http::response(fakeMaxMindArchive('GeoLite2-City', 'NEW-DB'))]);

    app(UpdateDatabaseAction::class)->execute();

    clearstatcache();

    // A rename swaps in a new file; an in-place write keeps the inode and lets a concurrent
    // reader see a half-written database.
    expect(file_get_contents($path))->toBe('NEW-DB')
        ->and(fileinode($path))->not->toBe($inodeBefore)
        ->and(glob(dirname($path).'/*.tmp*') ?: [])->toBe([]);
});

it('keeps the live database when the new archive cannot be unpacked', function (): void {
    $path = storage_path('app/geolocation/Action-City.mmdb');
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, 'OLD-DB');
    Http::fake(['download.maxmind.com/*' => Http::response(fakeMaxMindArchive('GeoLite2-City', 'readme', 'README.txt'))]);

    expect(fn () => app(UpdateDatabaseAction::class)->execute())->toThrow(DatabaseUpdateException::class);

    expect(file_get_contents($path))->toBe('OLD-DB');
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

it('wraps a destination it cannot write and leaves nothing behind', function (): void {
    $directory = sys_get_temp_dir().'/'.uniqid('mmro_', true);
    mkdir($directory, 0755);
    chmod($directory, 0555);
    Http::fake(['download.maxmind.com/*' => Http::response(fakeMaxMindArchive('GeoLite2-City', 'NEW-DB'))]);

    try {
        expect(fn () => app(UpdateDatabaseAction::class)->execute(path: $directory.'/City.mmdb'))
            ->toThrow(DatabaseUpdateException::class, 'Could not unpack the database');

        expect(glob($directory.'/*') ?: [])->toBe([]);
    } finally {
        chmod($directory, 0755);
        @rmdir($directory);
    }
});

it('wraps a destination that cannot be replaced', function (): void {
    $directory = sys_get_temp_dir().'/'.uniqid('mmdir_', true);
    mkdir($directory.'/City.mmdb', 0755, true);
    touch($directory.'/City.mmdb/keep');
    Http::fake(['download.maxmind.com/*' => Http::response(fakeMaxMindArchive('GeoLite2-City', 'NEW-DB'))]);

    try {
        expect(fn () => app(UpdateDatabaseAction::class)->execute(path: $directory.'/City.mmdb'))
            ->toThrow(DatabaseUpdateException::class, 'Could not unpack the database');

        expect(glob($directory.'/*.tmp*') ?: [])->toBe([]);
    } finally {
        @unlink($directory.'/City.mmdb/keep');
        @rmdir($directory.'/City.mmdb');
        @rmdir($directory);
    }
});
