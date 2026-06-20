<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\GeolocationManager;

final class LocateCommand extends Command
{
    protected $signature = 'geolocation:locate
        {target : An IP address, or with --address a street address}
        {--address : Treat the target as a street address}
        {--json : Output the resolved location as JSON}';

    protected $description = 'Resolve a location for an IP address or street address';

    public function handle(GeolocationManager $manager): int
    {
        /** @var string $target */
        $target = $this->argument('target');

        $query = $this->option('address')
            ? GeolocationQuery::forAddress($target)
            : GeolocationQuery::forIp($target);

        $location = $manager->locate($query);

        if (! $location instanceof Location) {
            $this->components->error("Could not resolve a location for [{$target}].");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($location, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Field', 'Value'],
            collect($location->toArray())
                ->map(static fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        return self::SUCCESS;
    }
}
