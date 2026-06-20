<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/geolocation-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=geolocation-for-laravel">
    <img src="art/hero.png" alt="Geolocation for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

# Geolocation for Laravel

Resolve a client's location (from an IP address, coordinates, or a street address) and the
travel distance between two points through a pluggable, provider-based pipeline. Ships with
**IPinfo**, **IP2Location**, **Google**, and **MaxMind** providers — including a fully
**native `.mmdb` reader** (no third-party MaxMind SDK) and a `geolocation:db:update` command
that downloads the database for you — plus geofencing helpers, an Eloquent coordinates cast,
a validation rule, a request macro, batch and matrix lookups, a test fake, caching, events,
and a configurable default fallback.

## Requirements

- PHP `^8.4`
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
| `ip2location` | `IP2LocationProvider` | yes (by IP) | no | [ip2location.io](https://www.ip2location.io) HTTP API |
| `ipinfo` | `IpInfoProvider` | yes (by IP) | no | [ipinfo.io](https://ipinfo.io) HTTP API |
| `google` | `GoogleProvider` | yes (by coordinates **or** address) | yes | Google Geocoding + Distance Matrix |
| `default` | `DefaultLocationProvider` | yes (static fallback) | no | config values |

Both MaxMind providers are **disabled by default** and return `null` immediately until you
enable them and supply credentials / a database path, so the package works out of the box with
just IPinfo, Google, and the default fallback.

### Running a single provider

You don't need to configure every provider. To run exactly one, either set the `pipeline`
(and `providers`) to a single name in config, or pin it per call:

```php
Geolocation::provider('ipinfo')->locateIp($request->ip());
Geolocation::using('maxmind_database')->locateIp($request->ip());
```

`provider()` / `using()` restrict that one resolution to the named provider(s) only — no
other provider needs to be configured.

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

### Batch lookups

Resolve many IPs at once. Failures are isolated per item — the batch never aborts:

```php
$results = Geolocation::batch(['8.8.8.8', '1.1.1.1', '203.0.113.7']);

$results['8.8.8.8']?->city;   // a Location or null per IP
```

### Distance matrix

Resolve a grid of distances between several origins and destinations in one call (Google
Distance Matrix). Unavailable legs degrade to `null`:

```php
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;

$matrix = Geolocation::distanceMatrix(
    origins: [new Coordinates(48.14, 17.10)],
    destinations: [new Coordinates(49.20, 16.60), new Coordinates(50.07, 14.43)],
);

$matrix->get(0, 1)?->distanceInMeters;
```

### Geofencing helpers on `Coordinates`

```php
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;

$here = new Coordinates(48.1486, 17.1077);

$here->near(new Coordinates(48.21, 16.37), radiusKm: 100);  // bool
$here->within([$a, $b, $c, $d]);                            // point-in-polygon, bool
$here->bearingTo($there);                                   // initial bearing, degrees
$here->midpointTo($there);                                  // Coordinates
$box = $here->boundingBox(radiusKm: 10);                    // BoundingBox
$box->contains($point);                                     // bool
```

### Storing coordinates on a model

Cast latitude/longitude columns to a `Coordinates` value object with the `HasLocation` trait,
which also adds a `withinRadius()` query scope (a cheap bounding-box pre-filter):

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Geolocation\Concerns\HasLocation;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;

final class Store extends Model
{
    use HasLocation; // casts a `coordinates` attribute over `latitude`/`longitude` columns
}

$store = new Store;
$store->coordinates = new Coordinates(48.1486, 17.1077);
$store->save();

Store::query()->withinRadius(new Coordinates(48.15, 17.11), radiusKm: 5)->get();
```

You can also apply the cast directly: `protected $casts = ['coordinates' => CoordinatesCast::class];`.

### Validation rule & request macro

```php
use Illuminate\Validation\Rule;

$request->validate([
    'point' => [Rule::coordinates()],   // "lat,lng", [lat, lng], or ['latitude'=>…, 'longitude'=>…]
]);

$location = $request->location();        // resolves the client's Location from its IP
```

### Per-call provider overrides

Tweak a provider's token or timeout for one resolution without touching global config:

```php
Geolocation::withToken('runtime-token')->locateIp('8.8.8.8');
Geolocation::withTimeout(10)->locateIp('8.8.8.8');
Geolocation::withConfig(['token' => '…', 'timeout' => 3])->locateIp('8.8.8.8');
```

The `GeolocationManager` is also `Macroable`, so host apps can add their own methods.

### Testing with the fake

Swap the manager for a recording fake in your host-app tests — no network, canned results:

```php
use RoundlyConsulting\Geolocation\Facades\Geolocation;

$fake = Geolocation::fake(['8.8.8.8' => $expectedLocation]);

$this->get('/checkout');

$fake->assertLocated('8.8.8.8');
$fake->assertProviderUsed('ipinfo');
$fake->assertNothingLocated();
```

Seed more results fluently with `$fake->seed($key, $location)`, `seedDefault()`, and
`seedDistance()`.

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

Download or refresh the MaxMind database (see below):

```bash
php artisan geolocation:db:update
php artisan geolocation:db:update --edition=GeoLite2-Country --path=/var/data/geo.mmdb
```

## MaxMind local database (native `.mmdb` reader)

The `maxmind_database` provider reads a local MaxMind `.mmdb` file (GeoLite2 / GeoIP2) using a
**native binary reader** built into this package — there is **no** `geoip2/geoip2` or
`maxmind-db/reader` runtime dependency. Supply the path to a database you have downloaded under
your own MaxMind licence:

```dotenv
MAXMIND_DB_ENABLED=true
MAXMIND_DB_PATH=/var/data/GeoLite2-City.mmdb   # defaults to storage_path('app/geolocation/GeoLite2-City.mmdb')
```

A corrupt file throws `InvalidDatabaseException`. A lookup that simply isn't in the database
returns `null`. When the provider is enabled but the database file is missing, it throws a
`DatabaseNotFoundException` whose message tells you to run `php artisan geolocation:db:update`.

### Downloading the database

`geolocation:db:update` fetches the GeoLite2/GeoIP2 `.mmdb` from MaxMind and writes it to the
configured path, unpacking the gzipped tarball natively (no extra dependency). Configure a
MaxMind account and license key:

```dotenv
MAXMIND_LICENSE_KEY=your-license-key
MAXMIND_DB_EDITION=GeoLite2-City   # GeoLite2-City | GeoLite2-Country | a GeoIP2 edition
```

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
| `pipeline` | `list<string>` | all bundled provider names | Ordered provider names to consult. |
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
| `services.ip2location.url` | `string` | `https://api.ip2location.io` | IP2Location.io base URL (`IP2LOCATION_URL`). |
| `services.ip2location.key` | `?string` | `null` | IP2Location.io API key (`IP2LOCATION_API_KEY`). |
| `services.ip2location.retry` | `int` | `2` | Retry attempts (`IP2LOCATION_RETRY_TIMES`). |
| `services.ip2location.retry_delay` | `int` | `100` | Retry delay in ms (`IP2LOCATION_RETRY_DELAY_MS`). |
| `services.maxmind.web.enabled` | `bool` | `false` | Enable the web-service provider (`MAXMIND_WEB_ENABLED`). |
| `services.maxmind.web.base_url` | `string` | GeoIP2 base | Web-service base URL (`MAXMIND_WEB_URL`). |
| `services.maxmind.web.account_id` | `?string` | `null` | MaxMind account ID (`MAXMIND_ACCOUNT_ID`). |
| `services.maxmind.web.license_key` | `?string` | `null` | MaxMind license key (`MAXMIND_LICENSE_KEY`). |
| `services.maxmind.web.service` | `string` | `city` | `city`, `country`, or `insights` (`MAXMIND_WEB_SERVICE`). |
| `services.maxmind.web.retry` | `int` | `2` | Retry attempts (`MAXMIND_WEB_RETRY_TIMES`). |
| `services.maxmind.web.retry_delay` | `int` | `100` | Retry delay in ms (`MAXMIND_WEB_RETRY_DELAY_MS`). |
| `services.maxmind.database.enabled` | `bool` | `false` | Enable the local `.mmdb` provider (`MAXMIND_DB_ENABLED`). |
| `services.maxmind.database.path` | `string` | `storage_path('app/geolocation/GeoLite2-City.mmdb')` | Path to the `.mmdb` file (`MAXMIND_DB_PATH`). |
| `services.maxmind.database.cache_metadata` | `bool` | `true` | Reuse parsed metadata within a request (`MAXMIND_DB_CACHE_METADATA`). |
| `services.maxmind.database.account_id` | `?string` | `null` | MaxMind account ID for downloads (`MAXMIND_ACCOUNT_ID`). |
| `services.maxmind.database.license_key` | `?string` | `null` | MaxMind license key for downloads (`MAXMIND_LICENSE_KEY`). |
| `services.maxmind.database.edition` | `string` | `GeoLite2-City` | Edition the update command downloads (`MAXMIND_DB_EDITION`). |
| `services.maxmind.database.download_url` | `string` | MaxMind download endpoint | Download URL base (`MAXMIND_DB_DOWNLOAD_URL`). |

## Notes

- Prefer the `RoundlyConsulting\Geolocation\Facades\Geolocation` facade (or resolving
  `GeolocationManager` from the container). `RoundlyConsulting\Geolocation\Geolocation` is a
  thin alias kept for convenience.
- The `providers` config accepts either a **named map** (name → class) or a flat list of
  class-strings.
- `GeolocationQuery`/`DistanceQuery` provide named constructors
  (`forIp`/`forAddress`/`forCoordinates`, `between`).
- `Location` exposes optional `region`, `postalCode`, and `timezone` fields (default `''`).

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
