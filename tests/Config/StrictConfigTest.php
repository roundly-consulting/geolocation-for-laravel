<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\Actions\UpdateDatabaseAction;
use RoundlyConsulting\Geolocation\Concerns\InteractsWithRateLimits;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Exceptions\UnknownProviderException;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Geolocation\Providers\DefaultLocationProvider;
use RoundlyConsulting\Geolocation\Providers\GoogleProvider;
use RoundlyConsulting\Geolocation\Providers\IP2LocationProvider;
use RoundlyConsulting\Geolocation\Providers\IpInfoProvider;
use RoundlyConsulting\Geolocation\Providers\MaxMindWebServiceProvider;
use RoundlyConsulting\Geolocation\Support\GeolocationConfig;
use RoundlyConsulting\Geolocation\Tests\FakeProviders\FakeGeolocationProvider;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * A typo in the host's geolocation config fails loudly. The ones that mattered: a timeout
 * of `five` used to be cast to 0 — no timeout at all — and a `per` typo silently became a
 * per-second budget. A blank value (a host's `KEY=`) is not set: it takes the default, or
 * leaves an optional setting off.
 */
function strictLimiterFor(string $provider): ?RateLimit
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

function ipinfoAnswers(): void
{
    Http::fake(['ipinfo.io/*' => Http::response(['city' => 'Mountain View', 'country' => 'US', 'loc' => '37.4,-122.07'])]);
}

dataset('junk integers', [
    'word' => 'five',
    'decimal' => '5.5',
    'trailing junk' => '5abc',
    'exponent' => '1e3',
    'bool' => true,
]);

it('refuses a junk http timeout instead of disabling it (strict config)', function (mixed $junk): void {
    config()->set('geolocation.timeout', $junk);
    ipinfoAnswers();

    expect(fn () => (new IpInfoProvider)->locate(new GeolocationQuery('8.8.8.8')))
        ->toThrow(InvalidConfigurationException::class, 'geolocation.timeout');

    Http::assertNothingSent();
})->with('junk integers');

it('refuses a zero or negative http timeout (strict config)', function (mixed $timeout): void {
    config()->set('geolocation.timeout', $timeout);
    ipinfoAnswers();

    expect(fn () => (new IpInfoProvider)->locate(new GeolocationQuery('8.8.8.8')))
        ->toThrow(InvalidConfigurationException::class, 'at least 1');
})->with([0, '0', '-5']);

it('refuses a junk per-call timeout override (strict config)', function (mixed $timeout): void {
    config()->set('geolocation.pipeline', ['ipinfo']);
    ipinfoAnswers();

    expect(fn () => Geolocation::withConfig('ipinfo', ['timeout' => $timeout])->locateIp('8.8.8.8'))
        ->toThrow(InvalidConfigurationException::class, 'timeout');

    Http::assertNothingSent();
})->with(['five', 0, '0']);

it('reads a canonical timeout string and defaults an absent or blank one (strict config)', function (mixed $timeout): void {
    config()->set('geolocation.timeout', $timeout);
    ipinfoAnswers();

    expect((new IpInfoProvider)->locate(new GeolocationQuery('8.8.8.8')))->toBeInstanceOf(Location::class);
})->with([' 7 ', 7, null, '', '  ']);

it('reads a blank timeout, or a blank per-call override, as not set (strict config)', function (): void {
    config()->set('geolocation.timeout', '');

    expect(GeolocationConfig::timeout())->toBe(5);

    config()->set('geolocation.timeout', 9);

    expect(GeolocationConfig::timeout(''))->toBe(9)
        ->and(GeolocationConfig::timeout(' '))->toBe(9)
        ->and(GeolocationConfig::timeout('3'))->toBe(3);
});

it('refuses a junk or negative retry on every http provider (strict config)', function (string $provider, mixed $value): void {
    config()->set("geolocation.services.{$provider}.retry", $value);
    config()->set('geolocation.services.maxmind_web.enabled', true);
    Http::fake();

    $instance = match ($provider) {
        'ipinfo' => new IpInfoProvider,
        'google' => new GoogleProvider,
        'ip2location' => new IP2LocationProvider,
        'maxmind_web' => new MaxMindWebServiceProvider,
    };
    $query = $provider === 'google' ? GeolocationQuery::forAddress('1 Main St') : new GeolocationQuery('8.8.8.8');

    expect(fn () => $instance->locate($query))
        ->toThrow(InvalidConfigurationException::class, "geolocation.services.{$provider}.retry");

    Http::assertNothingSent();
})->with(['ipinfo', 'google', 'ip2location', 'maxmind_web'])->with(['three', '-1']);

it('refuses a junk retry delay (strict config)', function (): void {
    config()->set('geolocation.services.ipinfo.retry_delay', '100ms');
    Http::fake();

    expect(fn () => (new IpInfoProvider)->locate(new GeolocationQuery('8.8.8.8')))
        ->toThrow(InvalidConfigurationException::class, 'geolocation.services.ipinfo.retry_delay');
});

it('refuses a non-string provider base url (strict config)', function (string $provider, mixed $value): void {
    $key = $provider === 'maxmind_web' ? 'base_url' : 'url';
    config()->set("geolocation.services.{$provider}.{$key}", $value);
    config()->set('geolocation.services.maxmind_web.enabled', true);
    Http::fake();

    $instance = match ($provider) {
        'ipinfo' => new IpInfoProvider,
        'google' => new GoogleProvider,
        'ip2location' => new IP2LocationProvider,
        'maxmind_web' => new MaxMindWebServiceProvider,
    };
    $query = $provider === 'google' ? GeolocationQuery::forAddress('1 Main St') : new GeolocationQuery('8.8.8.8');

    expect(fn () => $instance->locate($query))
        ->toThrow(InvalidConfigurationException::class, "geolocation.services.{$provider}.{$key}");
})->with(['ipinfo', 'google', 'ip2location', 'maxmind_web'])->with(['array' => [['https://ipinfo.io']], 'int' => 443]);

it('reads a blank provider base url as not set, calling the default host (strict config)', function (string $blank): void {
    config()->set('geolocation.services.ipinfo.url', $blank);
    ipinfoAnswers();

    expect((new IpInfoProvider)->locate(new GeolocationQuery('8.8.8.8')))->toBeInstanceOf(Location::class);

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://ipinfo.io/'));
})->with(['empty' => '', 'whitespace' => '  ']);

it('refuses a typo in the rate-limit window instead of pacing per second (strict config)', function (mixed $per): void {
    config()->set('geolocation.services.ipinfo.rate_limits.per', $per);

    expect(fn () => strictLimiterFor('ipinfo'))
        ->toThrow(InvalidConfigurationException::class, 'geolocation.services.ipinfo.rate_limits.per');
})->with(['minuet', 'Minute', 60]);

it('reads the rate-limit window and limit strictly, defaulting when absent (strict config)', function (): void {
    config()->set('geolocation.services.google.rate_limits.per', 'hour');
    config()->set('geolocation.services.google.rate_limits.limit', '120');

    $limiter = strictLimiterFor('google');

    expect($limiter?->getTimespan())->toBe('hour')
        ->and($limiter?->getMaxAttempts())->toBe(120);

    config()->set('geolocation.services.google.rate_limits', ['enabled' => true]);

    $limiter = strictLimiterFor('google');

    expect($limiter?->getTimespan())->toBe('second')
        ->and($limiter?->getMaxAttempts())->toBe(60)
        ->and($limiter?->getKey())->toBe('geolocation:google:app');

    config()->set('geolocation.services.google.rate_limits', ['enabled' => true, 'per' => '', 'limit' => ' ', 'owner' => '']);

    $limiter = strictLimiterFor('google');

    expect($limiter?->getTimespan())->toBe('second')
        ->and($limiter?->getMaxAttempts())->toBe(60)
        ->and($limiter?->getKey())->toBe('geolocation:google:app');
});

it('refuses a junk or non-positive rate limit (strict config)', function (mixed $limit): void {
    config()->set('geolocation.services.ipinfo.rate_limits.limit', $limit);

    expect(fn () => strictLimiterFor('ipinfo'))
        ->toThrow(InvalidConfigurationException::class, 'geolocation.services.ipinfo.rate_limits.limit');
})->with(['sixty', '6.5', 0, '-1']);

it('refuses a junk max_wait or jitter instead of ignoring it (strict config)', function (string $leaf, mixed $value): void {
    config()->set("geolocation.services.ipinfo.rate_limits.{$leaf}", $value);

    expect(fn () => strictLimiterFor('ipinfo'))
        ->toThrow(InvalidConfigurationException::class, "geolocation.services.ipinfo.rate_limits.{$leaf}");
})->with(['max_wait', 'jitter'])->with(['soon', '1.5', '-10']);

it('reads a blank max_wait or jitter as not set, leaving it off (strict config)', function (string $blank): void {
    config()->set('geolocation.services.ipinfo.rate_limits.max_wait', $blank);
    config()->set('geolocation.services.ipinfo.rate_limits.jitter', $blank);

    expect(GeolocationConfig::optionalInteger('geolocation.services.ipinfo.rate_limits.max_wait', $blank, min: 0))->toBeNull()
        ->and(strictLimiterFor('ipinfo'))->toBeInstanceOf(RateLimit::class);
})->with(['empty' => '', 'whitespace' => '  ']);

it('accepts a canonical max_wait and jitter (strict config)', function (): void {
    config()->set('geolocation.services.ipinfo.rate_limits.max_wait', '250');
    config()->set('geolocation.services.ipinfo.rate_limits.jitter', 0);

    expect(strictLimiterFor('ipinfo'))->toBeInstanceOf(RateLimit::class);
});

it('refuses a non-string rate-limit owner (strict config)', function (mixed $owner): void {
    config()->set('geolocation.services.ipinfo.rate_limits.owner', $owner);

    expect(fn () => strictLimiterFor('ipinfo'))
        ->toThrow(InvalidConfigurationException::class, 'geolocation.services.ipinfo.rate_limits.owner');
})->with(['array' => [['app']], 'int' => 7]);

it('refuses an unknown maxmind web service instead of using city (strict config)', function (mixed $service): void {
    config()->set('geolocation.services.maxmind_web.enabled', true);
    config()->set('geolocation.services.maxmind_web.service', $service);
    Http::fake();

    expect(fn () => (new MaxMindWebServiceProvider)->locate(new GeolocationQuery('8.8.8.8')))
        ->toThrow(InvalidConfigurationException::class, 'geolocation.services.maxmind_web.service');

    Http::assertNothingSent();
})->with(['cty', 'City']);

it('defaults an absent or blank maxmind web service to city (strict config)', function (?string $unset): void {
    config()->set('geolocation.services.maxmind_web.enabled', true);
    config()->set('geolocation.services.maxmind_web.service', $unset);
    Http::fake(['geoip.maxmind.com/*' => Http::response(['country' => ['iso_code' => 'US']])]);

    (new MaxMindWebServiceProvider)->locate(new GeolocationQuery('8.8.8.8'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/city/8.8.8.8'));
})->with(['absent' => null, 'empty' => '', 'whitespace' => ' ']);

it('refuses a junk or zero cache ttl (strict config)', function (mixed $ttl): void {
    config()->set('geolocation.providers', ['fake' => FakeGeolocationProvider::class]);
    config()->set('geolocation.pipeline', ['fake']);
    config()->set('geolocation.cache.enabled', true);
    config()->set('geolocation.cache.ttl', $ttl);

    expect(fn () => app(GeolocationManager::class)->locate(new GeolocationQuery('127.0.0.1')))
        ->toThrow(InvalidConfigurationException::class, 'geolocation.cache.ttl');
})->with(['a day', '0', 0, '-60']);

it('refuses a non-string cache store or prefix (strict config)', function (string $key, mixed $value): void {
    config()->set('geolocation.providers', ['fake' => FakeGeolocationProvider::class]);
    config()->set('geolocation.pipeline', ['fake']);
    config()->set('geolocation.cache.enabled', true);
    config()->set($key, $value);

    expect(fn () => app(GeolocationManager::class)->locate(new GeolocationQuery('127.0.0.1')))
        ->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'array store' => ['geolocation.cache.store', ['array']],
    'int prefix' => ['geolocation.cache.prefix', 5],
]);

it('caches under the default store and prefix when both are absent or blank (strict config)', function (?string $unset): void {
    config()->set('geolocation.providers', ['fake' => FakeGeolocationProvider::class]);
    config()->set('geolocation.pipeline', ['fake']);
    config()->set('geolocation.cache.enabled', true);
    config()->set('geolocation.cache.ttl', $unset);
    config()->set('geolocation.cache.store', $unset);
    config()->set('geolocation.cache.prefix', $unset);

    app(GeolocationManager::class)->locate(new GeolocationQuery('127.0.0.1'));

    expect(Cache::has('geolocation:v0:locate:'.(new GeolocationQuery('127.0.0.1'))->cacheKey()))->toBeTrue();
})->with(['absent' => null, 'empty' => '', 'whitespace' => ' ']);

it('refuses a pipeline name that names no provider (strict config)', function (mixed $name): void {
    config()->set('geolocation.providers', ['fake' => FakeGeolocationProvider::class]);
    config()->set('geolocation.pipeline', [$name, 'fake']);

    expect(fn () => app(GeolocationManager::class)->locate(new GeolocationQuery('127.0.0.1')))
        ->toThrow(UnknownProviderException::class);
})->with(['typo' => 'fkae', 'int' => 3]);

it('refuses a pipeline that is not a list (strict config)', function (mixed $pipeline): void {
    config()->set('geolocation.pipeline', $pipeline);

    expect(fn () => app(GeolocationManager::class)->locate(new GeolocationQuery('127.0.0.1')))
        ->toThrow(InvalidConfigurationException::class, 'geolocation.pipeline');
})->with(['a string' => 'maxmind_database', 'an int' => 1]);

it('still skips a bundled provider the host trimmed from the providers map (strict config)', function (): void {
    config()->set('geolocation.providers', ['fake' => FakeGeolocationProvider::class]);
    config()->set('geolocation.pipeline', ['ipinfo', 'fake']);
    Http::fake();

    expect(app(GeolocationManager::class)->locate(new GeolocationQuery('127.0.0.1')))->toBeInstanceOf(Location::class);

    Http::assertNothingSent();
});

it('reports the providers that actually run for an empty pipeline in about (strict config)', function (): void {
    config()->set('geolocation.pipeline', []);
    config()->set('geolocation.providers', ['fake' => FakeGeolocationProvider::class, 'default' => DefaultLocationProvider::class]);

    Artisan::call('about', ['--only' => 'geolocation']);

    expect(Artisan::output())->toContain('fake, default');
});

it('refuses a junk or out-of-range default coordinate (strict config)', function (string $key, mixed $value): void {
    config()->set('geolocation.default.city', 'Bratislava');
    config()->set("geolocation.default.{$key}", $value);

    expect(fn () => (new DefaultLocationProvider)->locate(new GeolocationQuery('127.0.0.1')))
        ->toThrow(InvalidConfigurationException::class, "geolocation.default.{$key}");
})->with([
    'word latitude' => ['latitude', 'north'],
    'latitude past the pole' => ['latitude', '91'],
    'longitude past the antimeridian' => ['longitude', -180.5],
    'hex longitude' => ['longitude', '0x1A'],
    'bool longitude' => ['longitude', true],
]);

it('reads a blank default coordinate as not set, the shipped 0.0 (strict config)', function (string $blank): void {
    config()->set('geolocation.default.city', 'Bratislava');
    config()->set('geolocation.default.latitude', $blank);
    config()->set('geolocation.default.longitude', $blank);

    $location = (new DefaultLocationProvider)->locate(new GeolocationQuery('127.0.0.1'));

    expect($location?->latitude)->toBe(0.0)
        ->and($location?->longitude)->toBe(0.0);
})->with(['empty' => '', 'whitespace' => '  ']);

it('reads numeric default coordinates from env strings (strict config)', function (): void {
    config()->set('geolocation.default.city', 'Bratislava');
    config()->set('geolocation.default.latitude', '48.1486');
    config()->set('geolocation.default.longitude', ' 17.1077 ');

    $location = (new DefaultLocationProvider)->locate(new GeolocationQuery('127.0.0.1'));

    expect($location?->latitude)->toBe(48.1486)
        ->and($location?->longitude)->toBe(17.1077);
});

it('refuses a junk timeout before downloading the database (strict config)', function (): void {
    config()->set('geolocation.services.maxmind_database.license_key', 'key');
    config()->set('geolocation.services.maxmind_database.path', storage_path('app/geolocation/Strict-City.mmdb'));
    config()->set('geolocation.timeout', 'five');
    Http::fake();

    expect(fn () => app(UpdateDatabaseAction::class)->execute())
        ->toThrow(InvalidConfigurationException::class, 'geolocation.timeout');

    Http::assertNothingSent();
});

it('refuses a non-string database edition or download url (strict config)', function (string $key): void {
    config()->set('geolocation.services.maxmind_database.license_key', 'key');
    config()->set('geolocation.services.maxmind_database.path', storage_path('app/geolocation/Strict-City.mmdb'));
    config()->set("geolocation.services.maxmind_database.{$key}", ['GeoLite2-City']);
    Http::fake();

    expect(fn () => app(UpdateDatabaseAction::class)->execute())
        ->toThrow(InvalidConfigurationException::class, "geolocation.services.maxmind_database.{$key}");

    Http::assertNothingSent();
})->with(['edition', 'download_url']);
