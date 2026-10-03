<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\Providers\DefaultLocationProvider;
use RoundlyConsulting\Geolocation\Providers\GoogleProvider;
use RoundlyConsulting\Geolocation\Providers\IP2LocationProvider;
use RoundlyConsulting\Geolocation\Providers\IpInfoProvider;
use RoundlyConsulting\Geolocation\Providers\MaxMindDatabaseProvider;
use RoundlyConsulting\Geolocation\Providers\MaxMindWebServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Pipeline
    |--------------------------------------------------------------------------
    |
    | The ordered list of provider names consulted when resolving a location or
    | a distance. The first provider that returns a non-null result wins, so
    | order them from most to least specific; one whose API is unreachable is
    | skipped. Each name must map to an entry in the "providers" map below.
    |
    | With these defaults an IP lookup calls api.ip2location.io and ipinfo.io
    | (unauthenticated when no key/token is set); address and coordinate
    | lookups call Google. The MaxMind database and default never call out.
    |
    */

    'pipeline' => ['maxmind_database', 'maxmind_web', 'ip2location', 'ipinfo', 'google', 'default'],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | A name => class-string map of the available providers. Every entry needs
    | a name: `pipeline` and `Geolocation::provider()` refer to providers by it.
    |
    */

    'providers' => [
        'maxmind_database' => MaxMindDatabaseProvider::class,
        'maxmind_web' => MaxMindWebServiceProvider::class,
        'ip2location' => IP2LocationProvider::class,
        'ipinfo' => IpInfoProvider::class,
        'google' => GoogleProvider::class,
        'default' => DefaultLocationProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | The request timeout (in seconds) applied to every HTTP-backed provider.
    |
    */

    'timeout' => env('GEOLOCATION_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Successful (non-null) lookups can be cached so repeated resolutions are
    | cheap and rate-limit friendly. Failures are never cached, and neither is
    | the default fallback location.
    |
    */

    'cache' => [
        'enabled' => env('GEOLOCATION_CACHE', false),
        'store' => env('GEOLOCATION_CACHE_STORE'),
        'ttl' => env('GEOLOCATION_CACHE_TTL', 86400),
        'prefix' => env('GEOLOCATION_CACHE_PREFIX', 'geolocation'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | When enabled, the manager dispatches LocationResolved, DistanceResolved
    | and LocationResolutionFailed events so host apps can react to lookups.
    |
    */

    'events' => [
        'enabled' => env('GEOLOCATION_EVENTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Location
    |--------------------------------------------------------------------------
    |
    | Returned by the DefaultLocationProvider as a last-resort fallback when no
    | upstream provider could resolve a location. While every value is empty
    | or zero (the shipped values) it answers null instead, so an unresolved
    | lookup stays null.
    |
    */

    'default' => [
        'humanReadable' => env('GEOLOCATION_DEFAULT_HUMAN_READABLE', ''),
        'street' => env('GEOLOCATION_DEFAULT_STREET', ''),
        'city' => env('GEOLOCATION_DEFAULT_CITY', ''),
        'country' => env('GEOLOCATION_DEFAULT_COUNTRY_ISO_CODE', ''),
        'latitude' => env('GEOLOCATION_DEFAULT_LATITUDE', 0.0),
        'longitude' => env('GEOLOCATION_DEFAULT_LONGITUDE', 0.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    |
    | Per-provider connection settings. Credentials are read from the host
    | application's environment so they never live in the package.
    |
    */

    'services' => [

        /*
        | Each HTTP-backed provider carries a "rate_limits" block that paces its
        | outbound calls through the http-client-rate-limits package, keyed
        | "geolocation:{provider}:{owner}". Requests wait until the window frees
        | (pace) by default; set "max_wait" (ms) to fail fast with a
        | RateLimitExceededException instead. With "adaptive" on, a provider's
        | 429 "Retry-After" self-tunes the limiter. Set "enabled" => false to
        | send with a plain client. The offline MaxMind database and default
        | providers make no network calls and are never throttled.
        |
        | Each key here is a provider name from the "providers" map above.
        */

        'ipinfo' => [
            'url' => env('IPINFO_URL', 'https://ipinfo.io/'),
            'token' => env('IPINFO_TOKEN'),
            'retry' => env('IPINFO_RETRY_TIMES', 3),
            'retry_delay' => env('IPINFO_RETRY_DELAY_MS', 100),
            'rate_limits' => [
                'enabled' => env('GEOLOCATION_IPINFO_RATELIMIT_ENABLED', true),
                'owner' => env('GEOLOCATION_RATELIMIT_OWNER', 'app'),
                'limit' => env('GEOLOCATION_IPINFO_RATELIMIT', 60),
                'per' => env('GEOLOCATION_IPINFO_RATELIMIT_PER', 'minute'), // second|minute|hour|day
                'adaptive' => env('GEOLOCATION_IPINFO_RATELIMIT_ADAPTIVE', true),
                'max_wait' => env('GEOLOCATION_IPINFO_RATELIMIT_MAX_WAIT'), // ms; null = pace, set = fail fast
                'jitter' => env('GEOLOCATION_IPINFO_RATELIMIT_JITTER'), // ms; null = none
            ],
        ],

        'google' => [
            'url' => env('GOOGLE_MAPS_URL', 'https://maps.googleapis.com/maps/api'),
            'key' => env('GOOGLE_MAPS_API_KEY'),
            'retry' => env('GOOGLE_MAPS_RETRY_TIMES', 3),
            'retry_delay' => env('GOOGLE_MAPS_RETRY_DELAY_MS', 100),
            'rate_limits' => [
                'enabled' => env('GEOLOCATION_GOOGLE_RATELIMIT_ENABLED', true),
                'owner' => env('GEOLOCATION_RATELIMIT_OWNER', 'app'),
                'limit' => env('GEOLOCATION_GOOGLE_RATELIMIT', 50),
                'per' => env('GEOLOCATION_GOOGLE_RATELIMIT_PER', 'second'), // second|minute|hour|day
                'adaptive' => env('GEOLOCATION_GOOGLE_RATELIMIT_ADAPTIVE', true),
                'max_wait' => env('GEOLOCATION_GOOGLE_RATELIMIT_MAX_WAIT'), // ms; null = pace, set = fail fast
                'jitter' => env('GEOLOCATION_GOOGLE_RATELIMIT_JITTER'), // ms; null = none
            ],
        ],

        'ip2location' => [
            'url' => env('IP2LOCATION_URL', 'https://api.ip2location.io'),
            'key' => env('IP2LOCATION_API_KEY'),
            'retry' => env('IP2LOCATION_RETRY_TIMES', 2),
            'retry_delay' => env('IP2LOCATION_RETRY_DELAY_MS', 100),
            'rate_limits' => [
                'enabled' => env('GEOLOCATION_IP2LOCATION_RATELIMIT_ENABLED', true),
                'owner' => env('GEOLOCATION_RATELIMIT_OWNER', 'app'),
                'limit' => env('GEOLOCATION_IP2LOCATION_RATELIMIT', 60),
                'per' => env('GEOLOCATION_IP2LOCATION_RATELIMIT_PER', 'minute'), // second|minute|hour|day
                'adaptive' => env('GEOLOCATION_IP2LOCATION_RATELIMIT_ADAPTIVE', true),
                'max_wait' => env('GEOLOCATION_IP2LOCATION_RATELIMIT_MAX_WAIT'), // ms; null = pace, set = fail fast
                'jitter' => env('GEOLOCATION_IP2LOCATION_RATELIMIT_JITTER'), // ms; null = none
            ],
        ],

        'maxmind_web' => [
            'enabled' => env('MAXMIND_WEB_ENABLED', false),
            'base_url' => env('MAXMIND_WEB_URL', 'https://geoip.maxmind.com/geoip/v2.1'),
            'account_id' => env('MAXMIND_ACCOUNT_ID'),
            'license_key' => env('MAXMIND_LICENSE_KEY'),
            'service' => env('MAXMIND_WEB_SERVICE', 'city'), // city|country|insights
            'retry' => env('MAXMIND_WEB_RETRY_TIMES', 2),
            'retry_delay' => env('MAXMIND_WEB_RETRY_DELAY_MS', 100),
            'rate_limits' => [
                'enabled' => env('GEOLOCATION_MAXMIND_WEB_RATELIMIT_ENABLED', true),
                'owner' => env('GEOLOCATION_RATELIMIT_OWNER', 'app'),
                'limit' => env('GEOLOCATION_MAXMIND_WEB_RATELIMIT', 60),
                'per' => env('GEOLOCATION_MAXMIND_WEB_RATELIMIT_PER', 'minute'), // second|minute|hour|day
                'adaptive' => env('GEOLOCATION_MAXMIND_WEB_RATELIMIT_ADAPTIVE', true),
                'max_wait' => env('GEOLOCATION_MAXMIND_WEB_RATELIMIT_MAX_WAIT'), // ms; null = pace, set = fail fast
                'jitter' => env('GEOLOCATION_MAXMIND_WEB_RATELIMIT_JITTER'), // ms; null = none
            ],
        ],

        'maxmind_database' => [
            'enabled' => env('MAXMIND_DB_ENABLED', false),
            'path' => env('MAXMIND_DB_PATH', storage_path('app/geolocation/GeoLite2-City.mmdb')),

            // Used by the geolocation:db:update command to download the .mmdb file.
            'license_key' => env('MAXMIND_LICENSE_KEY'),
            'edition' => env('MAXMIND_DB_EDITION', 'GeoLite2-City'),
            'download_url' => env('MAXMIND_DB_DOWNLOAD_URL', 'https://download.maxmind.com/app/geoip_download'),
        ],

    ],

];
