<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\Exceptions\InvalidDatabaseException;
use RoundlyConsulting\Geolocation\MaxMind\Metadata;

it('computes derived sizes', function (): void {
    $metadata = new Metadata(nodeCount: 100, recordSize: 28, ipVersion: 6, databaseType: 'GeoIP2-City');

    expect($metadata->nodeByteSize)->toBe(7)
        ->and($metadata->searchTreeSize)->toBe(700)
        ->and($metadata->dataSectionStart())->toBe(716);
});

it('computes node byte size for each record size', function (int $recordSize, int $expected): void {
    $metadata = new Metadata(nodeCount: 1, recordSize: $recordSize, ipVersion: 4, databaseType: 'Test');

    expect($metadata->nodeByteSize)->toBe($expected);
})->with([
    [24, 6],
    [28, 7],
    [32, 8],
]);

it('rejects an unsupported record size', function (): void {
    new Metadata(nodeCount: 1, recordSize: 30, ipVersion: 4, databaseType: 'Test');
})->throws(InvalidDatabaseException::class);

it('builds from a decoded map', function (): void {
    $metadata = Metadata::fromArray([
        'node_count' => 50,
        'record_size' => 24,
        'ip_version' => 4,
        'database_type' => 'Test',
    ]);

    expect($metadata->nodeCount)->toBe(50)->and($metadata->ipVersion)->toBe(4);
});

it('throws when a required metadata key is missing', function (): void {
    Metadata::fromArray(['record_size' => 24, 'ip_version' => 4, 'database_type' => 'Test']);
})->throws(InvalidDatabaseException::class);
