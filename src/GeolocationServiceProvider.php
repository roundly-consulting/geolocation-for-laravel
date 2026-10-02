<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation;

use RoundlyConsulting\Geolocation\Commands\LocateCommand;
use RoundlyConsulting\Geolocation\Commands\UpdateDatabaseCommand;
use RoundlyConsulting\Geolocation\MaxMind\ReaderCache;
use RoundlyConsulting\Geolocation\Support\ProviderOverrides;
use RoundlyConsulting\Geolocation\Support\RequestMacro;
use RoundlyConsulting\Geolocation\Support\ValidationRules;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class GeolocationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('geolocation')
            ->hasConfigFile()
            ->hasCommands([
                LocateCommand::class,
                UpdateDatabaseCommand::class,
            ])
            ->contributesToAbout(static function (): array {
                $pipeline = config('geolocation.pipeline', []);

                return [
                    'Pipeline' => is_array($pipeline) && $pipeline !== []
                        ? implode(', ', array_map(strval(...), $pipeline))
                        : 'NONE',
                    'Cache' => Config::boolean('geolocation.cache.enabled') ? 'ENABLED' : 'OFF',
                    'Events' => Config::boolean('geolocation.events.enabled', true) ? 'ENABLED' : 'OFF',
                ];
            });
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(ProviderOverrides::class);
        $this->app->singleton(ReaderCache::class);
        $this->app->singleton(GeolocationManager::class);
        $this->app->alias(GeolocationManager::class, 'geolocation');
    }

    public function boot(): void
    {
        parent::boot();

        ValidationRules::register();
        RequestMacro::register();
    }
}
