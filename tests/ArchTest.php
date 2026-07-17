<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\Exceptions\GeolocationException;
use RoundlyConsulting\Geolocation\GeolocationManager;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * Geolocation shipped a single hand-written rule (dd/dump/ray). Everything here except
 * `noDebuggingLeftovers` is a new guard.
 */
ArchPresets::strictTypes('RoundlyConsulting\Geolocation');

/**
 * Two deliberate extension points: `GeolocationManager`, the manager hosts resolve
 * providers through, and `GeolocationException`, the abstract base every geolocation
 * error extends so a host can catch them uniformly.
 *
 * The six classes behind `geolocation.providers.*` are NOT exempted here — they are
 * already final, and that is correct. This is worth stating because the row's spec
 * scored this package as having 5 swappable models: it has none. Those six entries are
 * lookup DRIVERS implementing `GeolocationProvider::locate()`, resolved by name from a
 * driver map; a host adds its own by writing a new class and naming it in the map,
 * never by subclassing `GoogleProvider`. There is no Eloquent model anywhere in this
 * package (the only `Model` references are a Cast and a trait a HOST mixes into its own
 * model), and no `*_model` key.
 *
 * So `swappableModelsAreNotFinal` and `modelsResolveThroughSeam` are both rejected: the
 * first has no model to pin, and the second's stray-literal half has no `*_model`-shaped
 * key to police while its late-static-binding ban would have nothing to say either.
 * Both halves are structurally inert here.
 */
ArchPresets::finalByDefault('RoundlyConsulting\Geolocation', [
    GeolocationManager::class,
    GeolocationException::class,
]);

/**
 * Geolocation does no cryptography. The ban is a standing guard against an API
 * signature, a cache-key digest, or a token scheme being hand-rolled here rather than
 * sourced from crypto-for-laravel — a real risk for a package that holds a MaxMind
 * license key and a Google Maps API key.
 */
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Geolocation');

/**
 * The Dependency Policy as a test — the assertion that caught bug #6 fleet-wide, where
 * CI installed testbench into `require` before the suite ran. No `alsoAllow`: this
 * package's `require` ships only php/ext/illuminate/roundly, and the workflow installs
 * test tooling with `--dev`. If it goes red the graph is wrong; never widen it.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

/**
 * Replaces the hand-written `['dd', 'dump', 'ray']` rule, which had a hole exactly where
 * it mattered: Pest's arch layer only sees a symbol that EXISTS, and `acme/ray` is not
 * in the dependency graph by policy, so `ray` was filtered out before the ban ran and
 * could never fail. The preset reads source tokens instead, and adds `var_dump`/
 * `print_r`, which this package never banned.
 */
ArchPresets::noDebuggingLeftovers([], __DIR__.'/../src');
