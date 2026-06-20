# Geolocation for Laravel

Resolve a client's location (from an IP address, coordinates, or a street address) and the
travel distance between two points through a pluggable, provider-based pipeline. Ships with
**IPinfo**, **Google**, and **MaxMind** providers — including a fully **native `.mmdb`
reader** (no third-party MaxMind SDK) — plus a configurable default fallback, a real facade,
caching, events, and an artisan command.

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

The package resolves a result by walking an **ordered pipeline of named providers** and
returning the first non-null answer:

- A **`GeolocationProvider`** turns a `GeolocationQuery` (IP, coordinates, or address) into a
  `Location`.
- A **`DistanceProvider`** turns a `DistanceQuery` (two coordinate pairs + a travel mode) into
  a `Distance`.

Providers are tried in `pipeline` order; reorder them in config to change precedence. A
successful resolution can be cached and dispatches an event.

### Bundled providers

| Name | Class | Location | Distance | Source |
|---|---|---|---|---|
| `maxmind_database` | `MaxMindDatabaseProvider` | yes (by IP) | no | local `.mmdb` file (native reader) |
| `maxmind_web` | `MaxMindWebServiceProvider` | yes (by IP) | no | MaxMind GeoIP2 Precision web service |
| `ipinfo` | `IpInfoProvider` | yes (by IP) | no | [ipinfo.io](https://ipinfo.io) HTTP API |
| `google` | `GoogleProvider` | yes (by coordinates **or** address) | yes | Google Geocoding + Distance Matrix |
| `default` | `DefaultLocationProvider` | yes (static fallback) | no | config values |

Both MaxMind providers are **disabled by default** and return `null` immediately until you
enable them and supply credentials / a database path, so the package works out of the box with
just IPinfo, Google, and the default fallback.

## Usage

Use the `Geolocation` facade (or resolve `GeolocationManager` from the container).

### One-liner helpers

```php
use RoundlyConsulting\Geolocation\Facades\Geolocation;

$location = Geolocation::locateIp('8.8.8.8');
$location = Geolocation::locateRequest();                              // auto-detects the client IP
$location = Geolocation::locateAddress('1600 Amphitheatre Pkwy, Mountain View');

$location?->city;           // "Mountain View"
$location?->region;         // "California"
$location?->postalCode;     // "94043"
$location?->countryIsoCode; // "US"
$location?->timezone;       // "America/Los_Angeles"
$location?->latitude;       // 37.4224
```

`Location` and `Distance` implement `Arrayable` + `JsonSerializable`, so you can return them
straight from a controller:

```php
return response()->json($location);
```

### Coordinates value object & named queries

```php
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;

$here = new Coordinates(48.1486, 17.1077);   // validates lat ∈ [-90,90], lng ∈ [-180,180]

$location = Geolocation::locate(GeolocationQuery::forCoordinates($here));

$metres = $here->distanceTo(new Coordinates(50.0755, 14.4378)); // Haversine, ≈ 290 km
```

### Calculate travel distance

```php
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\Enum\DistanceType;

$distance = Geolocation::distance(
    DistanceQuery::between(
        from: new Coordinates(48.1482, 17.1067),
        to:   new Coordinates(49.2000, 16.6068),
        type: DistanceType::Driving,
    ),
);

$distance?->humanReadableDistance; // "133 km"
$distance?->distanceInMeters;      // 133000
$distance?->durationInSeconds;     // 5400
```

`locate()` / `distance()` return `null` when no provider can resolve the query.

### Scoping a single call to specific providers

```php
$location = Geolocation::using('maxmind_database', 'ipinfo')->locateIp($request->ip());
```

### Registering a custom provider

Register a closure provider at runtime (e.g. in a service provider's `boot()`), then add its
name to the `pipeline`:

```php
use RoundlyConsulting\Geolocation\Facades\Geolocation;

Geolocation::extend('my_provider', fn () => new MyProvider());
```

Or implement `GeolocationProvider` / `DistanceProvider` and add the class to the `providers`
map. Providers are resolved from the container, so constructor dependencies are injected.

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

### Events

When `geolocation.events.enabled` is true (the default), the manager dispatches:

- `RoundlyConsulting\Geolocation\Events\LocationResolved` — `($query, $location, $provider)`
- `RoundlyConsulting\Geolocation\Events\DistanceResolved` — `($query, $distance, $provider)`
- `RoundlyConsulting\Geolocation\Events\LocationResolutionFailed` — `($query, $provider, $error)`

```php
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Geolocation\Events\LocationResolved;

Event::listen(function (LocationResolved $event): void {
    logger()->info("Resolved {$event->location->city} via {$event->provider}");
});
```

### Artisan command

```bash
php artisan geolocation:locate 8.8.8.8
php artisan geolocation:locate "1600 Amphitheatre Pkwy" --address
php artisan geolocation:locate 8.8.8.8 --json
```

The command exits non-zero when nothing resolves — handy for smoke-testing credentials and
your `.mmdb` wiring.

## MaxMind local database (native `.mmdb` reader)

The `maxmind_database` provider reads a local MaxMind `.mmdb` file (GeoLite2 / GeoIP2) using a
**native binary reader** built into this package — there is **no** `geoip2/geoip2` or
`maxmind-db/reader` runtime dependency. Supply the path to a database you have downloaded under
your own MaxMind licence:

```dotenv
MAXMIND_DB_ENABLED=true
MAXMIND_DB_PATH=/var/data/GeoLite2-City.mmdb
```

A missing or unreadable path throws `DatabaseNotFoundException`; a corrupt file throws
`InvalidDatabaseException`. A lookup that simply isn't in the database returns `null`.

## MaxMind web service

The `maxmind_web` provider calls MaxMind's GeoIP2 Precision web service with HTTP Basic auth:

```dotenv
MAXMIND_WEB_ENABLED=true
MAXMIND_ACCOUNT_ID=123456
MAXMIND_LICENSE_KEY=your-license-key
MAXMIND_WEB_SERVICE=city   # city | country | insights
```

## Configuration

Published to `config/geolocation.php`. Every key:

| Key | Type | Default | Purpose |
|---|---|---|---|
| `pipeline` | `list<string>` | all five names | Ordered provider names to consult. |
| `providers` | `array<string, class-string>` | the bundled map | Name → provider class. Legacy flat lists still work. |
| `timeout` | `int` | `5` | HTTP timeout in seconds (`GEOLOCATION_TIMEOUT`). |
| `cache.enabled` | `bool` | `false` | Cache successful lookups (`GEOLOCATION_CACHE`). |
| `cache.store` | `?string` | `null` | Cache store, null = default (`GEOLOCATION_CACHE_STORE`). |
| `cache.ttl` | `int` | `86400` | Cache TTL in seconds (`GEOLOCATION_CACHE_TTL`). |
| `cache.prefix` | `string` | `geolocation` | Cache key prefix (`GEOLOCATION_CACHE_PREFIX`). |
| `events.enabled` | `bool` | `true` | Dispatch resolution events (`GEOLOCATION_EVENTS`). |
| `default.*` | `string`/`float` | empty | Static location returned by `DefaultLocationProvider`. |
| `services.ipinfo.url` | `string` | `https://ipinfo.io/` | IPinfo base URL (`IPINFO_URL`). |
| `services.ipinfo.token` | `?string` | `null` | IPinfo token; omitted when null (`IPINFO_TOKEN`). |
| `services.ipinfo.retry` | `int` | `3` | Retry attempts (`IPINFO_RETRY_TIMES`). |
| `services.ipinfo.retry_delay` | `int` | `100` | Retry delay in ms (`IPINFO_RETRY_DELAY_MS`). |
| `services.google.url` | `string` | Google Maps API base | Google base URL (`GOOGLE_MAPS_URL`). |
| `services.google.key` | `?string` | `null` | Google Maps API key (`GOOGLE_MAPS_API_KEY`). |
| `services.google.retry` | `int` | `3` | Retry attempts (`GOOGLE_MAPS_RETRY_TIMES`). |
| `services.google.retry_delay` | `int` | `100` | Retry delay in ms (`GOOGLE_MAPS_RETRY_DELAY_MS`). |
| `services.maxmind.web.enabled` | `bool` | `false` | Enable the web-service provider (`MAXMIND_WEB_ENABLED`). |
| `services.maxmind.web.base_url` | `string` | GeoIP2 base | Web-service base URL (`MAXMIND_WEB_URL`). |
| `services.maxmind.web.account_id` | `?string` | `null` | MaxMind account ID (`MAXMIND_ACCOUNT_ID`). |
| `services.maxmind.web.license_key` | `?string` | `null` | MaxMind license key (`MAXMIND_LICENSE_KEY`). |
| `services.maxmind.web.service` | `string` | `city` | `city`, `country`, or `insights` (`MAXMIND_WEB_SERVICE`). |
| `services.maxmind.web.retry` | `int` | `2` | Retry attempts (`MAXMIND_WEB_RETRY_TIMES`). |
| `services.maxmind.web.retry_delay` | `int` | `100` | Retry delay in ms (`MAXMIND_WEB_RETRY_DELAY_MS`). |
| `services.maxmind.database.enabled` | `bool` | `false` | Enable the local `.mmdb` provider (`MAXMIND_DB_ENABLED`). |
| `services.maxmind.database.path` | `?string` | `null` | Absolute path to the `.mmdb` file (`MAXMIND_DB_PATH`). |
| `services.maxmind.database.cache_metadata` | `bool` | `true` | Reuse parsed metadata within a request (`MAXMIND_DB_CACHE_METADATA`). |

## Upgrade notes (1.0 → 1.1)

This release is **backward compatible**:

- The old `RoundlyConsulting\Geolocation\Geolocation` service still works but is
  **deprecated** — prefer the `RoundlyConsulting\Geolocation\Facades\Geolocation` facade /
  `GeolocationManager`. It is removed in 2.0.
- The `providers` config now accepts a **named map** (name → class). The previous flat list of
  class-strings keeps working.
- `GeolocationQuery`/`DistanceQuery` gained named constructors and an optional `address`
  parameter; existing positional calls are unchanged.
- `Location` gained optional `region`, `postalCode`, and `timezone` fields (default `''`).

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
