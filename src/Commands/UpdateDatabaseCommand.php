<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PharData;
use RuntimeException;
use Throwable;

/**
 * Downloads (or refreshes) a MaxMind GeoLite2/GeoIP2 .mmdb database and writes it to the
 * configured database path, unpacking the gzipped tarball natively (no new dependency).
 */
final class UpdateDatabaseCommand extends Command
{
    protected $signature = 'geolocation:db:update
        {--edition= : The MaxMind edition id to download (defaults to config)}
        {--path= : Where to write the .mmdb file (defaults to config)}';

    protected $description = 'Download or refresh the MaxMind GeoLite2/GeoIP2 database file';

    public function handle(): int
    {
        $licenseKey = (string) config('geolocation.services.maxmind.database.license_key');

        if ($licenseKey === '') {
            $this->components->error(
                'A MaxMind license key is required. Set MAXMIND_LICENSE_KEY '
                .'(geolocation.services.maxmind.database.license_key).'
            );

            return self::FAILURE;
        }

        $edition = $this->editionId();
        $destination = $this->destinationPath();

        if ($destination === '') {
            $this->components->error(
                'No destination path is configured. Set MAXMIND_DB_PATH '
                .'(geolocation.services.maxmind.database.path) or pass --path.'
            );

            return self::FAILURE;
        }

        $this->components->info("Downloading MaxMind edition [{$edition}]...");

        try {
            $archive = $this->download($edition, $licenseKey);
        } catch (RequestException|RuntimeException $e) {
            $this->components->error("Download failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        try {
            $this->extract($archive, $edition, $destination);
        } catch (Throwable $e) {
            @unlink($archive);
            $this->components->error("Could not unpack the database: {$e->getMessage()}");

            return self::FAILURE;
        }

        @unlink($archive);

        $this->components->info("MaxMind database written to [{$destination}].");

        return self::SUCCESS;
    }

    private function editionId(): string
    {
        $edition = $this->option('edition');

        if (is_string($edition) && $edition !== '') {
            return $edition;
        }

        return (string) config('geolocation.services.maxmind.database.edition', 'GeoLite2-City');
    }

    private function destinationPath(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $path;
        }

        /** @var string|null $configured */
        $configured = config('geolocation.services.maxmind.database.path');

        return (string) ($configured ?? '');
    }

    /**
     * Download the .tar.gz archive to a temporary file and return its path.
     */
    private function download(string $edition, string $licenseKey): string
    {
        $baseUrl = (string) config(
            'geolocation.services.maxmind.database.download_url',
            'https://download.maxmind.com/app/geoip_download'
        );

        $response = Http::timeout((int) config('geolocation.timeout', 5) * 12)
            ->get($baseUrl, [
                'edition_id' => $edition,
                'license_key' => $licenseKey,
                'suffix' => 'tar.gz',
            ])
            ->throw();

        $temp = tempnam(sys_get_temp_dir(), 'mmdb');

        if ($temp === false) {
            throw new RuntimeException('Unable to create a temporary file for the download.');
        }

        file_put_contents($temp, $response->body());

        return $temp;
    }

    /**
     * Unpack the gzipped tarball, locate the .mmdb member and copy it to the destination.
     */
    private function extract(string $archive, string $edition, string $destination): void
    {
        $this->ensureDirectory($destination);

        $tarPath = $archive.'.tar';
        $gzipped = (string) file_get_contents($archive);
        $decoded = @gzdecode($gzipped);

        if ($decoded === false) {
            throw new RuntimeException('The downloaded archive is not valid gzip data.');
        }

        file_put_contents($tarPath, $decoded);

        try {
            $phar = new PharData($tarPath);
            $contents = $this->locateMmdb($phar, $edition);

            if ($contents === null) {
                throw new RuntimeException("No .mmdb file was found inside the [{$edition}] archive.");
            }

            file_put_contents($destination, $contents);
        } finally {
            @unlink($tarPath);
        }
    }

    private function locateMmdb(PharData $phar, string $edition): ?string
    {
        $expected = "{$edition}.mmdb";
        $fallback = null;

        /** @var \PharFileInfo $file */
        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            $name = $file->getFilename();

            if ($name === $expected) {
                return $file->getContent();
            }

            if ($fallback === null && str_ends_with($name, '.mmdb')) {
                $fallback = $file->getContent();
            }
        }

        return $fallback;
    }

    private function ensureDirectory(string $destination): void
    {
        $directory = dirname($destination);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
