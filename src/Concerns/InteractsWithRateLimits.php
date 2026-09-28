<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Concerns;

use Closure;
use Illuminate\Http\Client\Response;
use RoundlyConsulting\Geolocation\Exceptions\RateLimitExceededException;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\RateLimitExceededException as HttpRateLimitExceededException;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;

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

        if (($config['enabled'] ?? true) === false) {
            return null;
        }

        $timespan = Timespan::tryFrom((string) ($config['per'] ?? 'second')) ?? Timespan::Second;
        $owner = (string) ($config['owner'] ?? 'app');

        $rateLimit = RateLimits::make(new Limit(
            maxAttempts: (int) ($config['limit'] ?? 60),
            timespan: $timespan,
        ))->by("geolocation:{$provider}:{$owner}");

        if (($config['adaptive'] ?? true) === true) {
            $rateLimit->adaptive();
        }

        if (isset($config['max_wait']) && is_numeric($config['max_wait'])) {
            $rateLimit->maxWait((int) $config['max_wait']);
        }

        if (isset($config['jitter']) && is_numeric($config['jitter'])) {
            $rateLimit->jitter((int) $config['jitter']);
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
