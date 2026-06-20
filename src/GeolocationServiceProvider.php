<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation;

use Illuminate\Support\ServiceProvider;

final class GeolocationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/geolocation.php', 'geolocation');

        $this->app->singleton(Geolocation::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/geolocation.php' => config_path('geolocation.php'),
            ], 'geolocation-config');
        }
    }
}
