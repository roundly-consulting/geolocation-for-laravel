<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Actions;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use PharData;
use PharFileInfo;
use RecursiveIteratorIterator;
use RoundlyConsulting\Geolocation\Exceptions\DatabaseUpdateException;
use RuntimeException;
use Throwable;

/**
 * Downloads (or refreshes) a MaxMind GeoLite2/GeoIP2 .mmdb database and writes it to the
 * configured database path, unpacking the gzipped tarball natively (no new dependency).
 *
 * Reached through `Geolocation::updateDatabase()`; the `geolocation:db:update` command is a
 * thin wrapper over that call.
 */
final readonly class UpdateDatabaseAction
{
    public function __construct(
        private Repository $config,
        private Factory $http,
    ) {}

    /**
     * @param  string|null  $edition  the MaxMind edition id (defaults to config)
     * @param  string|null  $path  where to write the .mmdb file (defaults to config)
     * @return string the path the database was written to
     *
     * @throws DatabaseUpdateException
     */
    public function execute(?string $edition = null, ?string $path = null): string
    {
        $licenseKey = (string) $this->config->get('geolocation.services.maxmind_database.license_key');

        if ($licenseKey === '') {
            throw DatabaseUpdateException::missingLicenseKey();
        }

        $edition = $this->editionId($edition);
        $destination = $this->destinationPath($path);

        if ($destination === '') {
            throw DatabaseUpdateException::missingPath();
        }

        try {
            $archive = $this->download($edition, $licenseKey);
        } catch (RequestException|RuntimeException $e) {
            throw DatabaseUpdateException::downloadFailed($e);
        }

        try {
            $this->extract($archive, $edition, $destination);
        } catch (Throwable $e) {
            throw DatabaseUpdateException::unpackFailed($e);
        } finally {
            @unlink($archive);
        }

        return $destination;
    }

    private function editionId(?string $edition): string
    {
        if ($edition !== null && $edition !== '') {
            return $edition;
        }

        return (string) $this->config->get('geolocation.services.maxmind_database.edition', 'GeoLite2-City');
    }

    private function destinationPath(?string $path): string
    {
        if ($path !== null && $path !== '') {
            return $path;
        }

        /** @var string|null $configured */
        $configured = $this->config->get('geolocation.services.maxmind_database.path');

        return (string) ($configured ?? '');
    }

    /**
     * Download the .tar.gz archive to a temporary file and return its path.
     */
    private function download(string $edition, #[\SensitiveParameter] string $licenseKey): string
    {
        $baseUrl = (string) $this->config->get(
            'geolocation.services.maxmind_database.download_url',
            'https://download.maxmind.com/app/geoip_download',
        );

        $response = $this->http
            ->timeout((int) $this->config->get('geolocation.timeout', 5) * 12)
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
        $decoded = @gzdecode((string) file_get_contents($archive));

        if ($decoded === false) {
            throw new RuntimeException('The downloaded archive is not valid gzip data.');
        }

        file_put_contents($tarPath, $decoded);

        try {
            $contents = $this->locateMmdb(new PharData($tarPath), $edition);

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

        /** @var PharFileInfo $file */
        foreach (new RecursiveIteratorIterator($phar) as $file) {
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
