<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\Exceptions\UnknownProviderException;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Geolocation\Support\ProviderOverrides;

beforeEach(function (): void {
    config()->set('geolocation.services.ip2location.key', 'IP2L-CONFIG');
    config()->set('geolocation.services.google.key', 'GOOGLE-CONFIG');
    config()->set('geolocation.pipeline', ['ip2location', 'ipinfo']);

    Http::fake([
        'api.ip2location.io/*' => Http::response(status: 503),
        'ipinfo.io/*' => Http::response(['city' => 'X', 'country' => 'US', 'loc' => '1,2']),
        '*/geocode/json*' => Http::response(['results' => []]),
    ]);
});

it('sends a withToken() credential only to the provider it names', function (): void {
    Geolocation::withToken('ipinfo', 'IPINFO-SECRET')->locateIp('8.8.8.8');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'ipinfo.io')
        && $request->hasHeader('Authorization', 'Bearer IPINFO-SECRET'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.ip2location.io')
        && ($request->data()['key'] ?? null) === 'IP2L-CONFIG');
    Http::assertNotSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'IPINFO-SECRET')
        && ! str_contains($request->url(), 'ipinfo.io'));
});

it('never forwards a token to google unless google is named', function (): void {
    config()->set('geolocation.pipeline', ['google']);

    Geolocation::withToken('ipinfo', 'IPINFO-SECRET')->locateCoordinates(new Coordinates(1, 2));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'key=GOOGLE-CONFIG'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'IPINFO-SECRET'));
});

it('scopes withConfig() overrides to one provider', function (): void {
    Geolocation::withConfig('ip2location', ['token' => 'IP2L-RUNTIME'])->locateIp('8.8.8.8');

    Http::assertSent(fn (Request $request): bool => ($request->data()['key'] ?? null) === 'IP2L-RUNTIME');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'ipinfo.io')
        && ! $request->hasHeader('Authorization'));
});

it('applies withTimeout() to every provider and forgets it afterwards', function (): void {
    Geolocation::withTimeout(3)->locateIp('8.8.8.8');

    expect(app(ProviderOverrides::class)->get('timeout'))->toBeNull();
});

it('sends the withTimeout() value with every http provider\'s request', function (): void {
    config()->set('geolocation.pipeline', ['maxmind_web', 'ip2location', 'ipinfo']);
    config()->set('geolocation.services.maxmind_web.enabled', true);
    $timeouts = [];

    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[parse_url($request->url(), PHP_URL_HOST)] = $options['timeout'] ?? null;

        return Http::response(status: 503);
    });

    Geolocation::withTimeout(9)->locateIp('8.8.8.8');

    expect($timeouts)->toBe(['geoip.maxmind.com' => 9, 'api.ip2location.io' => 9, 'ipinfo.io' => 9]);
});

it('sends a withToken() license key to the maxmind web service only', function (): void {
    config()->set('geolocation.pipeline', ['maxmind_web', 'ip2location']);
    config()->set('geolocation.services.maxmind_web.enabled', true);
    config()->set('geolocation.services.maxmind_web.account_id', '42');
    config()->set('geolocation.services.maxmind_web.license_key', 'MM-CONFIG');
    Http::fake(['geoip.maxmind.com/*' => Http::response(status: 503), 'api.ip2location.io/*' => Http::response(status: 503)]);

    Geolocation::withToken('maxmind_web', 'MM-RUNTIME')->locateIp('8.8.8.8');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'geoip.maxmind.com')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('42:MM-RUNTIME')));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.ip2location.io')
        && ($request->data()['key'] ?? null) === 'IP2L-CONFIG');
});

it('refuses an override for a provider that is not registered', function (): void {
    Geolocation::withToken('ipnfo', 'typo');
})->throws(UnknownProviderException::class);

/**
 * The package's own frames of whatever $call throws, captured with frame arguments on (error
 * trackers collect them; CI's production ini turns them off).
 *
 * @return list<array<string, mixed>>
 */
function geolocationFramesOf(Closure $call): array
{
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $call();
    } catch (Throwable $e) {
        return array_values(array_filter(
            $e->getTrace(),
            fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'RoundlyConsulting\\Geolocation\\'),
        ));
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    throw new LogicException('The call did not throw.');
}

/**
 * Whether $needle sits in a string argument, descending arrays. Objects are skipped: frame
 * serialisers render them by class, and SensitiveParameterValue hides its value anyway.
 */
function geolocationArgsHold(mixed $value, string $needle): bool
{
    return match (true) {
        is_string($value) => str_contains($value, $needle),
        is_array($value) => array_any($value, fn (mixed $item): bool => geolocationArgsHold($item, $needle)),
        default => false,
    };
}

it('keeps a refused credential out of the manager frames', function (string $via): void {
    $manager = app(GeolocationManager::class);

    $frames = geolocationFramesOf(fn () => $via === 'withToken'
        ? $manager->withToken('ipnfo', 'TYPO-SECRET')
        : $manager->withConfig('ipnfo', ['token' => 'TYPO-SECRET']));

    expect(array_column($frames, 'function'))->toContain('withConfig')
        ->and(geolocationArgsHold(array_column($frames, 'args'), 'ipnfo'))->toBeTrue()
        ->and(geolocationArgsHold(array_column($frames, 'args'), 'TYPO-SECRET'))->toBeFalse();
})->with(['withToken', 'withConfig']);

it('keeps a scoped credential out of the frames when a provider throws', function (): void {
    config()->set('geolocation.pipeline', ['ipinfo']);

    $frames = geolocationFramesOf(fn () => Geolocation::withToken('ipinfo', 'RUNTIME-SECRET')
        ->withConfig('ipinfo', ['timeout' => 'five'])
        ->locateIp('8.8.8.8'));

    expect(array_column($frames, 'function'))->toContain('during')
        ->and(geolocationArgsHold(array_column($frames, 'args'), '8.8.8.8'))->toBeTrue()
        ->and(geolocationArgsHold(array_column($frames, 'args'), 'RUNTIME-SECRET'))->toBeFalse();
});

it('accepts an override for a runtime-registered provider', function (): void {
    Geolocation::extend('custom', fn () => new stdClass);

    expect(Geolocation::withConfig('custom', ['a' => 1]))->not->toBeNull();
});

it('resolves overrides for the provider that is running, falling back to shared ones', function (): void {
    $overrides = new ProviderOverrides;

    $seen = $overrides->during(['timeout' => 1], ['ipinfo' => ['token' => 't', 'timeout' => 9]], function () use ($overrides): array {
        $overrides->activate('ip2location');
        $other = [$overrides->get('token'), $overrides->get('timeout')];
        $overrides->activate('ipinfo');

        return [$other, [$overrides->get('token'), $overrides->get('timeout')], $overrides->all()];
    });

    expect($seen)->toBe([[null, 1], ['t', 9], ['timeout' => 9, 'token' => 't']])
        ->and($overrides->all())->toBe([]);
});
