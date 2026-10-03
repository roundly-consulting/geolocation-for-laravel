<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\Concerns\InteractsWithRateLimits;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\Events\LocationResolved;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\Providers\MaxMindDatabaseProvider;
use RoundlyConsulting\Geolocation\Providers\MaxMindWebServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * env() only turns 'true'/'false' into booleans: a .env "1"/"on"/"yes" stays a string
 * (so `=== true` reads it as off) and `(bool) 'off'` is true. Every switch must read
 * these the way a host means them — and the about rows must agree with the behaviour.
 */
dataset('env truthy', ['1', 'on', 'yes', 'true']);
dataset('env falsy', ['0', 'off', 'no', 'false']);

function aboutGeolocation(): string
{
    Artisan::call('about', ['--only' => 'geolocation']);

    return Artisan::output();
}

function geolocationConfigWithEnv(string $name, string $value): array
{
    $_SERVER[$name] = $value;

    try {
        return require __DIR__.'/../../config/geolocation.php';
    } finally {
        unset($_SERVER[$name]);
    }
}

function rateLimiterFor(string $provider): ?RateLimit
{
    $probe = new class
    {
        use InteractsWithRateLimits;

        public function limiter(string $provider): ?RateLimit
        {
            return $this->rateLimiter($provider);
        }
    };

    return $probe->limiter($provider);
}

beforeEach(function (): void {
    config()->set('geolocation.pipeline', ['ipinfo']);
    Http::fake(['ipinfo.io/*' => Http::response(['city' => 'London', 'country' => 'GB', 'loc' => '51.5,-0.12'])]);
});

it('caches and dispatches when the switches hold an env-style truthy string', function (string $value): void {
    Event::fake([LocationResolved::class]);
    config()->set('geolocation.cache.enabled', $value);
    config()->set('geolocation.events.enabled', $value);

    Geolocation::locateIp('8.8.8.8');
    Geolocation::locateIp('8.8.8.8');

    Http::assertSentCount(1);
    Event::assertDispatched(LocationResolved::class);
    expect(aboutGeolocation())
        ->toMatch('/Cache\s*\.*\s*ENABLED/')
        ->toMatch('/Events\s*\.*\s*ENABLED/');
})->with('env truthy');

it('neither caches nor dispatches when the switches hold an env-style falsy string', function (string $value): void {
    Event::fake([LocationResolved::class]);
    config()->set('geolocation.cache.enabled', $value);
    config()->set('geolocation.events.enabled', $value);

    Geolocation::locateIp('8.8.8.8');
    Geolocation::locateIp('8.8.8.8');

    Http::assertSentCount(2);
    Event::assertNotDispatched(LocationResolved::class);
    expect(aboutGeolocation())
        ->toMatch('/Cache\s*\.*\s*OFF/')
        ->toMatch('/Events\s*\.*\s*OFF/');
})->with('env falsy');

it('keeps the shipped config from turning an env "off" into true', function (string $value): void {
    config()->set('geolocation', geolocationConfigWithEnv('GEOLOCATION_CACHE', $value));
    config()->set('geolocation.pipeline', ['ipinfo']);
    Cache::flush();

    Geolocation::locateIp('8.8.8.8');
    Geolocation::locateIp('8.8.8.8');

    Http::assertSentCount(2);
    expect(aboutGeolocation())->toMatch('/Cache\s*\.*\s*OFF/');
})->with('env falsy');

it('reads the maxmind enabled switches as booleans', function (): void {
    config()->set('geolocation.services.maxmind_web.enabled', 'off');
    config()->set('geolocation.services.maxmind_database.enabled', 'no');

    expect(app(MaxMindWebServiceProvider::class)->locate(new GeolocationQuery('81.2.69.142')))->toBeNull()
        ->and(app(MaxMindDatabaseProvider::class)->locate(new GeolocationQuery('81.2.69.142')))->toBeNull();

    Http::assertNothingSent();
});

it('reads the rate limit switches as booleans', function (): void {
    config()->set('geolocation.services.ipinfo.rate_limits.enabled', 'off');
    config()->set('geolocation.services.google.rate_limits.enabled', '1');
    config()->set('geolocation.services.google.rate_limits.adaptive', 'off');
    config()->set('geolocation.services.ip2location.rate_limits.adaptive', 'on');

    expect(rateLimiterFor('ipinfo'))->toBeNull()
        ->and(rateLimiterFor('google')?->getLimiter()->getLimit()->isAdaptive())->toBeFalse()
        ->and(rateLimiterFor('ip2location')?->getLimiter()->getLimit()->isAdaptive())->toBeTrue();
});

it('hands a switch typo from .env to the reader raw (strict config)', function (): void {
    expect(geolocationConfigWithEnv('GEOLOCATION_CACHE', 'disabled')['cache']['enabled'])->toBe('disabled');
});

it('throws on a switch typo instead of reading it as the default (strict config)', function (string $key): void {
    config()->set($key, 'disabled');

    expect(fn () => Geolocation::locateIp('8.8.8.8'))->toThrow(
        InvalidConfigurationException::class,
        "Configuration value [{$key}] must be a boolean (true/false, 1/0, on/off or yes/no), [disabled] given.",
    );
})->with([
    'cache' => 'geolocation.cache.enabled',
    'events' => 'geolocation.events.enabled',
    'rate limits' => 'geolocation.services.ipinfo.rate_limits.enabled',
    'adaptive' => 'geolocation.services.ipinfo.rate_limits.adaptive',
]);

it('throws on a provider switch typo rather than failing over past it (strict config)', function (string $provider, string $key): void {
    config()->set('geolocation.pipeline', [$provider, 'ipinfo']);
    config()->set($key, 'disabled');

    expect(fn () => Geolocation::locateIp('81.2.69.142'))->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'maxmind web' => ['maxmind_web', 'geolocation.services.maxmind_web.enabled'],
    'maxmind database' => ['maxmind_database', 'geolocation.services.maxmind_database.enabled'],
]);
