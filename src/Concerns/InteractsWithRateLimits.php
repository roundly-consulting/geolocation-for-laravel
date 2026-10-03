<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Concerns;

use Closure;
use Illuminate\Http\Client\Response;
use RoundlyConsulting\Geolocation\Exceptions\RateLimitExceededException;
use RoundlyConsulting\Geolocation\Support\GeolocationConfig;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\RateLimitExceededException as HttpRateLimitExceededException;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Client-side outbound rate limiting for the HTTP-backed geolocation providers.
 *
 * Each provider funnels its sends through a per-provider budget keyed
 * `geolocation:{provider}:{owner}`, read from its own
 * `geolocation.services.{provider}.rate_limits` config block — one name, used by the
 * `providers` map, the `pipeline`, the budget key, and the config section alike.
 */
trait InteractsWithRateLimits
{
    /**
     * Build the client-side rate limiter for a provider from its config, or
     * null when the host has disabled throttling for it.
     *
     * Requests are paced (the limiter waits until the window frees up) rather
     * than hard-failing; set a `max_wait` to fail fast instead. With `adaptive`
     * on (the default) the limiter also honours the provider's own
     * `Retry-After` / `X-RateLimit-*` headers on a 429.
     */
    protected function rateLimiter(string $provider): ?RateLimit
    {
        /** @var array<string, mixed> $config */
        $config = config("geolocation.services.{$provider}.rate_limits", []);

        if (! Config::boolean("geolocation.services.{$provider}.rate_limits.enabled", true)) {
            return null;
        }

        // Every value is read strictly: a typo'd window, a junk limit or a junk max_wait /
        // jitter throws naming its key instead of pacing per second, at 0, or not at all.
        $section = "geolocation.services.{$provider}.rate_limits";

        $timespan = GeolocationConfig::enum("{$section}.per", $config['per'] ?? null, Timespan::class, Timespan::Second);
        $owner = GeolocationConfig::string("{$section}.owner", $config['owner'] ?? null, 'app');
        $limit = GeolocationConfig::integer("{$section}.limit", $config['limit'] ?? null, 60, min: 1);
        $maxWait = GeolocationConfig::optionalInteger("{$section}.max_wait", $config['max_wait'] ?? null, min: 0);
        $jitter = GeolocationConfig::optionalInteger("{$section}.jitter", $config['jitter'] ?? null, min: 0);

        $rateLimit = RateLimits::make(new Limit(maxAttempts: $limit, timespan: $timespan))
            ->by("geolocation:{$provider}:{$owner}");

        if (Config::boolean("geolocation.services.{$provider}.rate_limits.adaptive", true)) {
            $rateLimit->adaptive();
        }

        if ($maxWait !== null) {
            $rateLimit->maxWait($maxWait);
        }

        if ($jitter !== null) {
            $rateLimit->jitter($jitter);
        }

        return $rateLimit;
    }

    /**
     * Send a provider request through its rate limiter, translating hcrl's own
     * exhaustion exception into the package's typed, geolocation-native
     * exception so hosts catch a meaningful `RateLimitExceededException`
     * (itself a `GeolocationException`).
     *
     * @param  Closure(): Response  $send
     */
    protected function throttled(string $provider, Closure $send): Response
    {
        $rateLimit = $this->rateLimiter($provider);

        if ($rateLimit === null) {
            return $send();
        }

        try {
            /** @var Response $response */
            $response = $rateLimit->handle($send);

            return $response;
        } catch (HttpRateLimitExceededException $exception) {
            throw RateLimitExceededException::for(
                provider: $provider,
                retryAfterSeconds: (int) ceil($exception->delayMs / 1000),
            );
        }
    }
}
