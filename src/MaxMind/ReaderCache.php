<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\MaxMind;

/**
 * Keeps one open Reader per database path for the life of the process (a container
 * singleton), so a GeoLite2-City file — tens of megabytes read into memory — is loaded
 * once, not once per lookup.
 *
 * A cheap stat() per lookup notices when the file was replaced (inode, mtime or size
 * changed — `geolocation:db:update` renames a new file into place) and reopens it, so a
 * long-running worker picks up a refreshed database without a restart.
 *
 * @internal
 */
final class ReaderCache
{
    /**
     * @var array<string, Reader>
     */
    private array $readers = [];

    /**
     * @var array<string, string>
     */
    private array $signatures = [];

    public function get(string $path): Reader
    {
        $signature = $this->signature($path);

        if (isset($this->readers[$path]) && $this->signatures[$path] === $signature) {
            return $this->readers[$path];
        }

        $this->signatures[$path] = $signature;

        return $this->readers[$path] = new Reader($path);
    }

    private function signature(string $path): string
    {
        clearstatcache(true, $path);

        $stat = @stat($path);

        return $stat === false ? '' : "{$stat['ino']}:{$stat['mtime']}:{$stat['size']}";
    }
}
