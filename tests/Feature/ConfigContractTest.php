<?php

declare(strict_types=1);

it('ships exactly the config keys it reads', function (): void {
    expect(realpath(__DIR__.'/../../config/geolocation.php'))->toSatisfyConfigContract([
        realpath(__DIR__.'/../../src'),
    ], [
        'sectionVariables' => [
            // The one driver-keyed read in the package —
            // `config("geolocation.services.{$provider}.rate_limits")` — hands the section
            // to a local that is then indexed with literal offsets. The base path carries a
            // `*` for the provider, so each `rate_limits` leaf is proven while the provider
            // never is. The `*` stands for exactly ONE segment, which is why the config had
            // to stop nesting MaxMind's web service at `services.maxmind.web`.
            'InteractsWithRateLimits.php' => ['$config' => 'geolocation.services.*.rate_limits'],

            // `DefaultLocationProvider` reads `config('geolocation.default')` wholesale and
            // hands the array straight to `Location::createFromDefaults()`, which is where
            // the six leaves are actually named.
            'Location.php' => ['$config' => 'geolocation.default'],
        ],

        // A host-authored name => class-string map, read wholesale by the manager and looked
        // up by a name that comes from `pipeline`, not from any code. These six are shipped
        // defaults — data, not a schema this package reads — so no code names them by leaf
        // and none should: the whole point is that a host can register its own provider
        // without a change here. The claim "nothing reads this leaf, and that is correct" is
        // true, which is what separates an honest allowUnread from muting a real finding.
        'allowUnread' => [
            'geolocation.providers.maxmind_database',
            'geolocation.providers.maxmind_web',
            'geolocation.providers.ip2location',
            'geolocation.providers.ipinfo',
            'geolocation.providers.google',
            'geolocation.providers.default',
        ],
    ]);
});
