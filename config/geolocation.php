<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\Providers\DefaultLocationProvider;
use RoundlyConsulting\Geolocation\Providers\GoogleProvider;
use RoundlyConsulting\Geolocation\Providers\IpInfoProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | The ordered list of provider classes consulted when resolving a location
    | or a distance. The first provider that returns a non-null result wins,
    | so order them from most to least specific. Each entry must implement
    | GeolocationProvider and/or DistanceProvider.
    |
    */

    'providers' => [
        IpInfoProvider::class,
        GoogleProvider::class,
        DefaultLocationProvider::class,
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

    ],

];
