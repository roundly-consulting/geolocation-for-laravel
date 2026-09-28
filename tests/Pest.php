<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use RoundlyConsulting\Geolocation\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Build a gzipped tar archive containing a single member, mirroring the layout MaxMind
 * serves (an edition-named directory holding the database file). `$member` overrides the
 * file name, to exercise the fallback and the no-database branches.
 */
function fakeMaxMindArchive(string $edition, string $contents, ?string $member = null): string
{
    $work = sys_get_temp_dir().'/'.uniqid('mmtest_', true);
    mkdir($work."/{$edition}_20240101", 0755, true);
    file_put_contents($work."/{$edition}_20240101/".($member ?? "{$edition}.mmdb"), $contents);

    $tarPath = $work.'/archive.tar';
    $phar = new PharData($tarPath);
    $phar->buildFromDirectory($work, '/\.(mmdb|txt)$/');

    $gz = gzencode((string) file_get_contents($tarPath));

    unlink($tarPath);

    return (string) $gz;
}

/**
 * An `Http::fake()` stub that fails the way a real timeout / refused connection does:
 * a rejected transfer whose message ends in the full request URL — query-string
 * credentials included, which is exactly what must never leak.
 */
function failedConnection(): Closure
{
    return static fn (Request $request): PromiseInterface => Create::rejectionFor(
        new ConnectException(
            "cURL error 7: Failed to connect (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for {$request->toPsrRequest()->getUri()}",
            $request->toPsrRequest(),
        ),
    );
}
