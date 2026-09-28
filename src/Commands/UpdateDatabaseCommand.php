<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Geolocation\Exceptions\DatabaseUpdateException;
use RoundlyConsulting\Geolocation\GeolocationManager;

/**
 * Downloads (or refreshes) the MaxMind GeoLite2/GeoIP2 .mmdb database — a thin console
 * wrapper over `Geolocation::updateDatabase()`.
 */
final class UpdateDatabaseCommand extends Command
{
    protected $signature = 'geolocation:db:update
        {--edition= : The MaxMind edition id to download (defaults to config)}
        {--path= : Where to write the .mmdb file (defaults to config)}';

    protected $description = 'Download or refresh the MaxMind GeoLite2/GeoIP2 database file';

    public function handle(GeolocationManager $geolocation): int
    {
        $this->components->info('Downloading the MaxMind database...');

        try {
            $destination = $geolocation->updateDatabase($this->stringOption('edition'), $this->stringOption('path'));
        } catch (DatabaseUpdateException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("MaxMind database written to [{$destination}].");

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
