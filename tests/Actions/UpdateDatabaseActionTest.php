<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
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

/**
 * Generate a MaxMind-shaped .tar.gz whose .mmdb member is $bytes long, streamed to disk in
 * 1 MB blocks so building it never holds the member in memory. Highly compressible, so the
 * archive itself (what the faked download returns) stays a few KB. Returns the archive's
 * contents and the member's sha1.
 *
 * @return array{0: string, 1: string}
 */
function largeMaxMindArchive(string $edition, int $bytes): array
{
    $work = sys_get_temp_dir().'/'.uniqid('mmlarge_', true);
    $directory = "{$work}/{$edition}_20240101";
    mkdir($directory, 0755, true);

    $member = fopen("{$directory}/{$edition}.mmdb", 'wb');
    $hash = hash_init('sha1');
    $block = str_repeat('MaxMind.com', intdiv(1 << 20, 11) + 1);

    for ($written = 0; $written < $bytes; $written += strlen($chunk)) {
        $chunk = substr($block, 0, min(strlen($block), $bytes - $written));
        fwrite($member, $chunk);
        hash_update($hash, $chunk);
    }

    fclose($member);

    $tar = new PharData("{$work}/archive.tar");
    $tar->addFile("{$directory}/{$edition}.mmdb", "{$edition}_20240101/{$edition}.mmdb");
    unset($tar);

    $in = fopen("{$work}/archive.tar", 'rb');
    $out = gzopen("{$work}/archive.tar.gz", 'wb9');

    while (! feof($in)) {
        gzwrite($out, (string) fread($in, 1 << 20));
    }

    fclose($in);
    gzclose($out);

    $archive = (string) file_get_contents("{$work}/archive.tar.gz");

    File::deleteDirectory($work);

    return [$archive, hash_final($hash)];
}

it('streams a large database through the update instead of holding it in memory', function (): void {
    $bytes = 32 * 1024 * 1024;
    [$archive, $sha1] = largeMaxMindArchive('GeoLite2-City', $bytes);
    $sinks = [];
    Http::fake(['download.maxmind.com/*' => function (Request $request, array $options) use ($archive, &$sinks) {
        $sinks[] = $options['sink'] ?? null;

        return Http::response($archive);
    }]);
    $action = app(UpdateDatabaseAction::class);

    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $path = $action->execute();

    $grown = memory_get_peak_usage() - $before;

    expect($grown)->toBeLessThan(intdiv($bytes, 4))
        ->and(filesize($path))->toBe($bytes)
        ->and(hash_file('sha1', $path))->toBe($sha1)
        ->and(glob(dirname($path).'/*') ?: [])->toBe([$path]);

    // The download streamed to a temporary file, and nothing temporary is left behind.
    expect($sinks)->toHaveCount(1)
        ->and($sinks[0])->toBeString()
        ->and(file_exists((string) $sinks[0]))->toBeFalse()
        ->and(file_exists($sinks[0].'.tar'))->toBeFalse();
});

it('cleans up the streamed download when the server refuses it', function (): void {
    $sinks = [];
    Http::fake(['download.maxmind.com/*' => function (Request $request, array $options) use (&$sinks) {
        $sinks[] = $options['sink'] ?? null;

        return Http::response('Invalid license key', 401);
    }]);

    expect(fn () => app(UpdateDatabaseAction::class)->execute())
        ->toThrow(DatabaseUpdateException::class, 'Download failed');

    expect($sinks)->toHaveCount(1)
        ->and($sinks[0])->toBeString()
        ->and(file_exists((string) $sinks[0]))->toBeFalse();
});

it('wraps a gzip archive that is cut short', function (): void {
    Http::fake(['download.maxmind.com/*' => Http::response(substr(fakeMaxMindArchive('GeoLite2-City', str_repeat('x', 4096)), 0, 40))]);

    app(UpdateDatabaseAction::class)->execute();
})->throws(DatabaseUpdateException::class, 'Could not unpack the database');
