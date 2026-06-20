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
    | order them from most to least specific. Each name must map to an entry in
    | the "providers" map below.
    |
    */

    'pipeline' => ['maxmind_database', 'maxmind_web', 'ip2location', 'ipinfo', 'google', 'default'],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | A name => class-string map of the available providers. The package also
    | accepts the legacy flat-list form (a list of class-strings) for backward
    | compatibility — each class-string then doubles as its own name.
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

    'timeout' => (int) env('GEOLOCATION_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Successful (non-null) lookups can be cached so repeated resolutions are
    | cheap and rate-limit friendly. Failures are never cached.
    |
    */

    'cache' => [
        'enabled' => (bool) env('GEOLOCATION_CACHE', false),
        'store' => env('GEOLOCATION_CACHE_STORE'),
        'ttl' => (int) env('GEOLOCATION_CACHE_TTL', 86400),
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
        'enabled' => (bool) env('GEOLOCATION_EVENTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Location
    |--------------------------------------------------------------------------
    |
    | Returned by the DefaultLocationProvider as a last-resort fallback when no
    | upstream provider could resolve a location.
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

        'ipinfo' => [
            'url' => env('IPINFO_URL', 'https://ipinfo.io/'),
            'token' => env('IPINFO_TOKEN'),
            'retry' => env('IPINFO_RETRY_TIMES', 3),
            'retry_delay' => env('IPINFO_RETRY_DELAY_MS', 100),
        ],

        'google' => [
            'url' => env('GOOGLE_MAPS_URL', 'https://maps.googleapis.com/maps/api'),
            'key' => env('GOOGLE_MAPS_API_KEY'),
            'retry' => env('GOOGLE_MAPS_RETRY_TIMES', 3),
            'retry_delay' => env('GOOGLE_MAPS_RETRY_DELAY_MS', 100),
        ],

        'ip2location' => [
            'url' => env('IP2LOCATION_URL', 'https://api.ip2location.io'),
            'key' => env('IP2LOCATION_API_KEY'),
            'retry' => (int) env('IP2LOCATION_RETRY_TIMES', 2),
            'retry_delay' => (int) env('IP2LOCATION_RETRY_DELAY_MS', 100),
        ],

        'maxmind' => [

            'web' => [
                'enabled' => (bool) env('MAXMIND_WEB_ENABLED', false),
                'base_url' => env('MAXMIND_WEB_URL', 'https://geoip.maxmind.com/geoip/v2.1'),
                'account_id' => env('MAXMIND_ACCOUNT_ID'),
                'license_key' => env('MAXMIND_LICENSE_KEY'),
                'service' => env('MAXMIND_WEB_SERVICE', 'city'), // city|country|insights
                'retry' => (int) env('MAXMIND_WEB_RETRY_TIMES', 2),
                'retry_delay' => (int) env('MAXMIND_WEB_RETRY_DELAY_MS', 100),
            ],

            'database' => [
                'enabled' => (bool) env('MAXMIND_DB_ENABLED', false),
                'path' => env('MAXMIND_DB_PATH', storage_path('app/geolocation/GeoLite2-City.mmdb')),
                'cache_metadata' => (bool) env('MAXMIND_DB_CACHE_METADATA', true),

                // Used by the geolocation:db:update command to download the .mmdb file.
                'account_id' => env('MAXMIND_ACCOUNT_ID'),
                'license_key' => env('MAXMIND_LICENSE_KEY'),
                'edition' => env('MAXMIND_DB_EDITION', 'GeoLite2-City'),
                'download_url' => env('MAXMIND_DB_DOWNLOAD_URL', 'https://download.maxmind.com/app/geoip_download'),
            ],

        ],

    ],

];
