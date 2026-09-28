<?php

declare(strict_types=1);

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
