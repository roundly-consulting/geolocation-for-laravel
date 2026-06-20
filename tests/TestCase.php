<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use RoundlyConsulting\Geolocation\GeolocationServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            GeolocationServiceProvider::class,
        ];
    }
}
