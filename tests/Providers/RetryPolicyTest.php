<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\Exceptions\ProviderUnavailableException;
use RoundlyConsulting\Geolocation\Providers\GoogleProvider;
use RoundlyConsulting\Geolocation\Providers\IP2LocationProvider;
use RoundlyConsulting\Geolocation\Providers\IpInfoProvider;
use RoundlyConsulting\Geolocation\Providers\MaxMindWebServiceProvider;

beforeEach(function (): void {
    Sleep::fake();

    config()->set('geolocation.services.google.key', 'google-key');
    config()->set('geolocation.services.maxmind_web.enabled', true);
    config()->set('geolocation.services.maxmind_web.account_id', '42');
    config()->set('geolocation.services.maxmind_web.license_key', 'mm-key');
});

/**
 * Each bundled HTTP provider under its shipped retry count, with the lookup it answers.
 *
 * @return array<string, array{0: Closure(): object, 1: Closure(): GeolocationQuery, 2: string, 3: int}>
 */
dataset('http providers', [
    'ipinfo' => [fn (): object => new IpInfoProvider, fn (): GeolocationQuery => GeolocationQuery::forIp('8.8.8.8'), 'ipinfo.io/*', 3],
    'ip2location' => [fn (): object => new IP2LocationProvider, fn (): GeolocationQuery => GeolocationQuery::forIp('8.8.8.8'), 'api.ip2location.io/*', 2],
    'maxmind_web' => [fn (): object => new MaxMindWebServiceProvider, fn (): GeolocationQuery => GeolocationQuery::forIp('8.8.8.8'), 'geoip.maxmind.com/*', 2],
    'google' => [fn (): object => new GoogleProvider, fn (): GeolocationQuery => GeolocationQuery::forAddress('1 Main St'), '*/geocode/json*', 3],
]);

it('never retries a client error', function (Closure $provider, Closure $query, string $url, int $tries, int $status): void {
    Http::fake([$url => Http::response(['error' => 'no'], $status, ['Retry-After' => '60'])]);

    expect($provider()->locate($query()))->toBeNull();

    Http::assertSentCount(1);
})->with('http providers')->with([400, 401, 403, 404, 429]);

it('retries a server error up to the configured attempts', function (Closure $provider, Closure $query, string $url, int $tries): void {
    Http::fake([$url => Http::response(status: 503)]);

    expect($provider()->locate($query()))->toBeNull();

    Http::assertSentCount($tries);
})->with('http providers');

it('retries a connection failure up to the configured attempts', function (Closure $provider, Closure $query, string $url, int $tries): void {
    Http::fake([$url => failedConnection()]);

    expect(fn () => $provider()->locate($query()))->toThrow(ProviderUnavailableException::class);

    Http::assertSentCount($tries);
})->with('http providers');
