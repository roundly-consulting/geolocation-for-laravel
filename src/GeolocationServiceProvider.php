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
            ->hasTranslations()
            ->hasCommands([
                LocateCommand::class,
                UpdateDatabaseCommand::class,
            ])
            ->contributesToAbout(static function (): array {
                $pipeline = config('geolocation.pipeline', []);

                // An empty pipeline consults the "providers" map in its own order, so that
                // is what renders. A broken value renders as such; the lookup itself throws.
                if ($pipeline === [] || $pipeline === null) {
                    $providers = config('geolocation.providers', []);
                    $pipeline = is_array($providers) ? array_keys($providers) : $providers;
                }

                return [
                    'Pipeline' => match (true) {
                        ! is_array($pipeline) => 'INVALID',
                        $pipeline === [] => 'NONE',
                        default => implode(', ', array_map(
                            static fn (mixed $name): string => is_scalar($name) ? (string) $name : get_debug_type($name),
                            $pipeline,
                        )),
                    },
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
