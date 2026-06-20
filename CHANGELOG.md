# Changelog

All notable changes to `geolocation-for-laravel` will be documented in this file.

## Unreleased

### Added

- MaxMind local `.mmdb` provider with a fully **native** binary reader (no third-party
  MaxMind SDK) supporting 24/28/32-bit records, pointers, and IPv4-in-IPv6 lookups.
- MaxMind GeoIP2 Precision **web-service** provider (HTTP Basic auth; city/country/insights).
- Google **forward geocoding** (address → location) alongside the existing reverse geocoding.
- Real `RoundlyConsulting\Geolocation\Facades\Geolocation` facade and a `Geolocation` alias.
- `GeolocationManager` with a pluggable provider registry (`extend`, `using`), one-liner
  helpers (`locateIp`, `locateAddress`, `locateCoordinates`, `locateRequest`), lookup
  caching, and dispatched events.
- `Coordinates` value object with range validation and a Haversine `distanceTo()`.
- Named query constructors (`GeolocationQuery::forIp/forAddress/forCoordinates`,
  `DistanceQuery::between`).
- `LocationResolved`, `DistanceResolved`, `LocationResolutionFailed` events.
- `geolocation:locate` artisan command.
- Typed exceptions under `RoundlyConsulting\Geolocation\Exceptions`.
- `Arrayable` + `JsonSerializable` on `Location` and `Distance`; `region`, `postalCode`, and
  `timezone` fields on `Location`.
- **IP2Location.io** provider (`ip2location`) over Laravel's HTTP client.
- `geolocation:db:update` artisan command that downloads/refreshes the MaxMind GeoLite2/
  GeoIP2 `.mmdb` file natively (gzip + PharData, no new dependency); the local-database
  provider now throws an actionable exception pointing at it when the file is missing.
- Single-provider selection via config or at runtime: `Geolocation::provider('ipinfo')`
  and `Geolocation::using('maxmind_database')`.
- `Geolocation::batch([...ips])` bulk lookups with graceful per-item failure.
- `Geolocation::distanceMatrix($origins, $destinations)` multi-point distance via Google.
- Geofencing helpers on `Coordinates`: `near()`, `within()` (point-in-polygon),
  `bearingTo()`, `midpointTo()`, `boundingBox()`, plus a `BoundingBox` value object.
- `HasLocation` Eloquent trait + `CoordinatesCast` to store/restore `Coordinates` on models,
  with a `withinRadius()` query scope.
- `Rule::coordinates()` validation rule and a `$request->location()` macro.
- `Geolocation::fake()` recording test double with `assertLocated()`, `assertProviderUsed()`,
  and `assertNothingLocated()` helpers.
- Per-resolution provider overrides: `withToken()`, `withTimeout()`, `withConfig()`; the
  manager is now `Macroable`.

### Changed

- `geolocation.providers` now accepts a named map (the legacy flat list still works); a new
  `geolocation.pipeline` controls consultation order.
- IPinfo provider hardened: request timeout, token-less anonymous mode, IP validation, and
  richer field parsing.
