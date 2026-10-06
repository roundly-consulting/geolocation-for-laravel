<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Testing;

use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;

/**
 * Everything a GeolocationFake seeds and records, held in one object that the facade's fake
 * shares with every scoped copy it hands out (`provider()`, `using()`), so assertions on the
 * fake see calls made through a copy and seeds reach a copy made before them.
 *
 * @internal
 */
final class GeolocationFakeState
{
    public ?Location $default = null;

    public ?Distance $distance = null;

    /**
     * @var list<string>
     */
    public array $located = [];

    /**
     * @var list<string>
     */
    public array $providersUsed = [];

    /**
     * @var list<DistanceQuery>
     */
    public array $distances = [];

    /**
     * @var list<array{edition: string, path: string}>
     */
    public array $databaseUpdates = [];

    /**
     * @var list<GeolocationQuery|DistanceQuery>
     */
    public array $forgotten = [];

    public int $flushes = 0;

    /**
     * @param  array<string, Location>  $results
     */
    public function __construct(
        public array $results = [],
    ) {}
}
