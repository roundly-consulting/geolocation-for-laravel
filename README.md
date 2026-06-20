# Geolocation for Laravel

Resolve a client's location (from an IP address or coordinates) and the travel distance
between two points through a pluggable, provider-based pipeline. Ships with IPinfo and
Google providers plus a configurable default fallback, and lets you register your own.

## Requirements

- PHP `^8.3`
- Laravel `^12.0` or `^13.0`

## Installation

```bash
composer require roundly-consulting/geolocation-for-laravel
```

The service provider is auto-discovered. Optionally publish the config file:

```bash
php artisan vendor:publish --tag="geolocation-config"
```

## How it works

The package resolves a result by walking an **ordered list of providers** and returning the
first non-null answer:

- A **`GeolocationProvider`** turns a `GeolocationQuery` (IP address or latitude/longitude)
  into a `Location`.
- A **`DistanceProvider`** turns a `DistanceQuery` (two coordinate pairs + a travel mode)
  into a `Distance`.

Providers are tried top-to-bottom; reorder them in config to change precedence.

### Bundled providers

| Provider | Resolves location | Resolves distance | Source |
|---|---|---|---|
| `IpInfoProvider` | yes (by IP) | no | [ipinfo.io](https://ipinfo.io) HTTP API |
| `GoogleProvider` | yes (by coordinates) | yes | Google Geocoding + Distance Matrix APIs |
| `DefaultLocationProvider` | yes (static fallback) | no | config values |

## Configuration

Published to `config/geolocation.php`:

```php
return [
    'providers' => [
        IpInfoProvider::class,
        GoogleProvider::class,
        DefaultLocationProvider::class,
    ],

    'default' => [
        'humanReadable' => env('GEOLOCATION_DEFAULT_HUMAN_READABLE', ''),
        'street' => env('GEOLOCATION_DEFAULT_STREET', ''),
        'city' => env('GEOLOCATION_DEFAULT_CITY', ''),
        'country' => env('GEOLOCATION_DEFAULT_COUNTRY_ISO_CODE', ''),
        'latitude' => env('GEOLOCATION_DEFAULT_LATITUDE', 0.0),
        'longitude' => env('GEOLOCATION_DEFAULT_LONGITUDE', 0.0),
    ],

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
```

| Key | Type | Default | Purpose |
|---|---|---|---|
| `providers` | `list<class-string>` | IPinfo, Google, Default | Ordered provider pipeline. |
| `default.*` | `string`/`float` | empty | Static location returned by `DefaultLocationProvider`. |
| `services.ipinfo.url` | `string` | `https://ipinfo.io/` | IPinfo base URL (`IPINFO_URL`). |
| `services.ipinfo.token` | `?string` | `null` | IPinfo API token (`IPINFO_TOKEN`). |
| `services.ipinfo.retry` | `int` | `3` | Retry attempts (`IPINFO_RETRY_TIMES`). |
| `services.ipinfo.retry_delay` | `int` | `100` | Retry delay in ms (`IPINFO_RETRY_DELAY_MS`). |
| `services.google.url` | `string` | Google Maps API base | Google base URL (`GOOGLE_MAPS_URL`). |
| `services.google.key` | `?string` | `null` | Google Maps API key (`GOOGLE_MAPS_API_KEY`). |
| `services.google.retry` | `int` | `3` | Retry attempts (`GOOGLE_MAPS_RETRY_TIMES`). |
| `services.google.retry_delay` | `int` | `100` | Retry delay in ms (`GOOGLE_MAPS_RETRY_DELAY_MS`). |

### Environment

```dotenv
IPINFO_TOKEN=your-ipinfo-token
GOOGLE_MAPS_API_KEY=your-google-maps-key
```

## Usage

Resolve the `Geolocation` service from the container.

### Locate by IP address

```php
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\Geolocation;

$location = app(Geolocation::class)->locate(
    new GeolocationQuery(ipAddress: $request->ip()),
);

// $location instanceof RoundlyConsulting\Geolocation\DataTransferObjects\Location
$location?->city;           // "Bratislava"
$location?->countryIsoCode; // "SK"
$location?->latitude;       // 48.1482
$location?->type;           // GeolocationType::Ip
```

### Reverse geocode coordinates

```php
$location = app(Geolocation::class)->locate(
    new GeolocationQuery(latitude: 48.1482, longitude: 17.1067),
);

$location?->humanReadable; // "Námestie SNP, Bratislava, SK"
$location?->street;        // "Námestie SNP"
```

`locate()` returns `null` when no provider can resolve the query.

### Calculate travel distance

```php
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\Enum\DistanceType;
use RoundlyConsulting\Geolocation\Geolocation;

$distance = app(Geolocation::class)->distance(
    new DistanceQuery(
        fromLatitude: 48.1482,
        fromLongitude: 17.1067,
        toLatitude: 49.2000,
        toLongitude: 16.6068,
        type: DistanceType::Driving,
    ),
);

$distance?->humanReadableDistance; // "133 km"
$distance?->distanceInMeters;      // 133000
$distance?->humanReadableDuration; // "1 hour 30 mins"
$distance?->durationInSeconds;     // 5400
```

### Writing your own provider

Implement `GeolocationProvider` and/or `DistanceProvider`, then add the class to the
`providers` array in `config/geolocation.php`:

```php
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\GeolocationProvider;

final class MyProvider implements GeolocationProvider
{
    public function locate(GeolocationQuery $query): ?Location
    {
        // Return a Location, or null to defer to the next provider.
    }
}
```

Providers are resolved from the container, so constructor dependencies are injected.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
