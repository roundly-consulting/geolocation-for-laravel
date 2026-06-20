<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Geolocation\Commands\LocateCommand;
use RoundlyConsulting\Geolocation\Facades\Geolocation as GeolocationFacade;

final class GeolocationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/geolocation.php', 'geolocation');

        $this->app->singleton(GeolocationManager::class);
        $this->app->alias(GeolocationManager::class, 'geolocation');
    }

    public function boot(): void
    {
        if (class_exists(AliasLoader::class)) {
            AliasLoader::getInstance()->alias('Geolocation', GeolocationFacade::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                LocateCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/geolocation.php' => config_path('geolocation.php'),
            ], 'geolocation-config');
        }
    }
}
