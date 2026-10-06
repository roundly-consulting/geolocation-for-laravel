# Changelog

All notable changes to `geolocation-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- A `DistanceResolutionFailed` event (`query`, `provider`, `error`) fires whenever `distance()`
  or `distanceBetween()` ends without a distance, so a rejected key or an unreachable API is
  observable instead of a silent `null`. It mirrors `LocationResolutionFailed` and is governed
  by `events.enabled`.

### Changed

- **BREAKING:** Google distances (`distance()`, `distanceBetween()`, `distanceMatrix()`) now call
  the Routes API's `computeRouteMatrix` instead of the legacy Distance Matrix API, which Google
  closed to new Cloud projects. Enable the **Routes API** on the key in `GOOGLE_MAPS_API_KEY`
  before you upgrade. The key now travels in the `X-Goog-Api-Key` header, and the new
  `services.google.routes_url` (`GOOGLE_ROUTES_URL`) sets the endpoint. When the Routes API
  rejects a request (API not enabled, quota, invalid argument), the provider throws a redacted
  `ProviderUnavailableException` that names the status, where it used to return a silent `null`.
  The pipeline skips the provider, and `distanceMatrix()` degrades to `null` cells.
  `humanReadableDistance` and `humanReadableDuration` now come from the Routes API's localized
  text, so their wording can differ from Distance Matrix's.

### Fixed

- `CoordinatesCast` (and so every `HasLocation` model) no longer caches the `Coordinates` it
  returned: reading `coordinates` before updating `latitude`/`longitude` used to merge the old
  point back on save and silently drop the update.
- The HTTP providers (`google`, `ipinfo`, `ip2location`, `maxmind_web`) retry only connection
  errors and `5xx` responses. A `4xx` (bad key, unknown IP, `429`) is no longer re-sent up to
  `services.<provider>.retry` times inside the same rate-limit slot.
- A Google geocoding request the API rejects (`REQUEST_DENIED`, `OVER_QUERY_LIMIT`,
  `INVALID_REQUEST`, …) now throws a redacted `ProviderUnavailableException` naming the status,
  so the pipeline moves on and `LocationResolutionFailed` says why, instead of a silent `null`.
- `distanceMatrix()` splits a grid over the Routes API's 625-element cap into requests that fit
  and merges the cells back by their original indices. An oversized grid used to go out as one
  request that Google refused, which left every cell `null`. A tile that fails now leaves only
  its own cells `null`.
- Under `Geolocation::fake()`, `provider()` / `using()` return a scoped copy, as the real manager
  does. An unchained pin no longer leaks into the next facade call or satisfies
  `assertProviderUsed()`. Calls made through the copy still record on the fake.
- The fake refuses provider names that are not registered, as the real manager does.
  `withToken()` / `withConfig()` throw `UnknownProviderException`, and so does a call pinned to an
  unknown name through `using()` / `provider()`. A pinned `batch()` answers `null` per IP, as it
  does for real.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- Location lookup by IP address, coordinates or street address through an ordered, configurable
  pipeline of providers, with a `Geolocation` facade (`locateIp()`, `locateRequest()`,
  `locateAddress()`).
- Bundled providers: IPinfo, IP2Location, Google (geocoding and distances), MaxMind web service,
  and a static default fallback.
- A native reader for MaxMind `.mmdb` databases, and the `geolocation:db:update` command to
  download or refresh the database.
- Travel distances between two points (`Geolocation::distance()`), batch IP lookups and a distance
  matrix for several origins and destinations.
- A `Coordinates` value object with geofencing helpers: `distanceTo()`, `near()`, `within()`
  (point in polygon), `bearingTo()`, `midpointTo()` and `boundingBox()`.
- A `HasLocation` trait and `CoordinatesCast` to store coordinates on a model, with a
  `withinRadius()` query scope.
- A coordinates validation rule (`Rule::coordinates()`) and a `$request->location()` macro.
- Per-call provider selection (`using()`, `provider()`) and overrides (`withToken($provider, $token)`,
  `withTimeout()`, `withConfig($provider, $overrides)`), each returning a scoped copy of the
  manager, plus custom providers via `Geolocation::extend()`.
- Result caching and events (`LocationResolved`, `DistanceResolved`, `LocationResolutionFailed`).
- Outbound provider calls paced by http-client-rate-limits-for-laravel, with `Retry-After`
  backoff.
- The `geolocation:locate` command for smoke-testing credentials.
- `Geolocation::distanceBetween($from, $to, $type)` — a distance without hand-building a
  `DistanceQuery`.
- `Geolocation::updateDatabase(?$edition, ?$path)` refreshes the MaxMind database from code and
  returns the written path; `geolocation:db:update` is a thin wrapper over it, and the logic lives
  in `Actions\UpdateDatabaseAction`. Failures throw `DatabaseUpdateException`.
- `Geolocation::forget($query)` drops one cached lookup or distance; `Geolocation::flushCache()`
  invalidates them all on any cache store (cache keys now carry a generation number).
- `Geolocation::fake()` — a real static on the facade returning `Testing\GeolocationFake`, a
  subtype of `GeolocationManager` installed behind the facade and in the container. Asserts:
  `assertLocated`/`assertNothingLocated`, `assertProviderUsed`/`assertProviderNotUsed`,
  `assertDistanceRequested`/`assertNoDistanceRequested`,
  `assertDatabaseUpdated`/`assertDatabaseNotUpdated`, `assertForgotten`/`assertNothingForgotten`,
  `assertCacheFlushed`/`assertCacheNotFlushed`.
- The `Geolocation` global alias is declared in `composer.json` (`extra.laravel.aliases`).

### Fixed

- An unreachable provider API (timeout, DNS failure, refused connection) no longer aborts a
  lookup: it throws a redacted `ProviderUnavailableException` that the pipeline skips, and
  `LocationResolutionFailed` now carries the failing provider and error.
- API keys and the MaxMind license key never appear in exception messages; `geolocation:db:update`
  turns connection failures into `DatabaseUpdateException` and swaps the new database in with an
  atomic rename.
- `provider()` / `using()` / `with*()` scope a copy of the manager, so a scope or override never
  leaks into later calls (even after a provider throws) and applies to every IP of a `batch()`.
- `withToken()` / `withConfig()` target one provider, so a credential never reaches another
  vendor.
- The `default` provider answers `null` until a default location is configured, and a default
  fallback is never cached.
- MaxMind size-1 and size-2 data pointers decode correctly (operator precedence), and a uint64
  with its top bit clear decodes as an int.
- The `.mmdb` file is read once per process (reloaded when it changes on disk), not per lookup.
- `midpointTo()`, `boundingBox()`, `within()` and `withinRadius()` handle the antimeridian and
  the poles.
- Coordinates are sent to Google as plain decimals, never in scientific notation.
- The fake records every provider named in `using()`, for lookups, batches, distances and
  matrices.
- An empty answer (no place, country or coordinates — what IP2Location returns for a private
  IP) is a miss: the pipeline asks the next provider, and an IP nothing can place is `null`.
  `Location::isEmpty()` tells such a value apart.
- The MaxMind web service honours `withTimeout()` and a `withToken('maxmind_web', …)` license key.

### Changed

- `GeolocationManager::fake()` moved to the facade (`Geolocation::fake()`); the fake class is
  renamed `FakeGeolocationManager` → `GeolocationFake`, and `GeolocationManager` now takes the
  container in its constructor.
- Outbound rate limits are built through the `RateLimits` facade of
  http-client-rate-limits-for-laravel.
