<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\DataTransferObjects;

/**
 * The result of a multi-point distance lookup: a grid of {@see Distance} results indexed by
 * the origin and destination position (null where a leg could not be resolved).
 */
final readonly class DistanceMatrix
{
    /**
     * @param  list<Coordinates>  $origins
     * @param  list<Coordinates>  $destinations
     * @param  array<int, array<int, Distance|null>>  $rows
     */
    public function __construct(
        public array $origins,
        public array $destinations,
        public array $rows,
    ) {}

    public function get(int $originIndex, int $destinationIndex): ?Distance
    {
        return $this->rows[$originIndex][$destinationIndex] ?? null;
    }
}
