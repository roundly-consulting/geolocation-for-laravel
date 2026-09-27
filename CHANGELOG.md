# Changelog

All notable changes to `geolocation-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

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
- Per-call provider selection (`using()`, `provider()`) and overrides (`withToken()`,
  `withTimeout()`, `withConfig()`), plus custom providers via `Geolocation::extend()`.
- Result caching and events (`LocationResolved`, `DistanceResolved`, `LocationResolutionFailed`).
- Outbound provider calls paced by http-client-rate-limits-for-laravel, with `Retry-After`
  backoff.
- The `geolocation:locate` command for smoke-testing credentials, and `Geolocation::fake()` with
  assertions for your tests.
