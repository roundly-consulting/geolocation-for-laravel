<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Geolocation\GeolocationServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\HttpClientRateLimitsServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    private string $storageSandbox = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Every lookup provider is driven through a faked HTTP client; a stray request
        // means a test is reaching a real geolocation API.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->storageSandbox !== '') {
            File::deleteDirectory($this->storageSandbox);
            $this->storageSandbox = '';
        }

        parent::tearDown();
    }

    /**
     * Point storage_path() at a throwaway directory for the rest of this test.
     *
     * The MaxMind database refresh writes into storage/app/geolocation. In the testbench
     * skeleton that directory is shared by every parallel process, so one file's in-flight
     * `.tmp` file could fail another file's "nothing temporary left behind" glob — and the
     * directory outlived the suite. Configuration is already resolved by now, so this moves
     * only the storage_path() calls the test makes from here on.
     */
    protected function sandboxStorage(): void
    {
        $this->storageSandbox = sys_get_temp_dir().'/geolocation-storage-'.bin2hex(random_bytes(6));

        app()->useStoragePath($this->storageSandbox);
    }

    /**
     * Every provider geolocation hard-requires, in registration order — the rate
     * limiter a host auto-discovers first, then the package itself.
     *
     * HttpClientRateLimitsServiceProvider was missing before this row and is
     * load-bearing: `Concerns\InteractsWithRateLimits` builds a `RateLimit` from that
     * package for every HTTP-backed provider, so a suite that never registered it was
     * testing an environment no host runs.
     *
     * This package ships no migrations and opens no database connection: it is an HTTP
     * client plus a cast and a trait a HOST mixes into its own model.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [
            HttpClientRateLimitsServiceProvider::class,
            GeolocationServiceProvider::class,
        ];
    }
}
