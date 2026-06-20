<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\Exceptions\DatabaseNotFoundException;
use RoundlyConsulting\Geolocation\Exceptions\InvalidDatabaseException;
use RoundlyConsulting\Geolocation\MaxMind\Reader;

function mmdbFixture(string $name): string
{
    return __DIR__.'/../Fixtures/test-data/'.$name;
}

it('reads metadata from the city database', function (): void {
    $metadata = (new Reader(mmdbFixture('GeoIP2-City-Test.mmdb')))->metadata();

    expect($metadata->databaseType)->toBe('GeoIP2-City')
        ->and($metadata->recordSize)->toBe(28)
        ->and($metadata->ipVersion)->toBe(6)
        ->and($metadata->nodeByteSize)->toBe(7);
});

it('decodes a known city record (28-bit tree, ipv4-in-ipv6)', function (): void {
    $record = (new Reader(mmdbFixture('GeoIP2-City-Test.mmdb')))->get('2.125.160.216');

    expect($record)->toBeArray()
        ->and($record['city']['names']['en'])->toBe('Boxford')
        ->and($record['country']['iso_code'])->toBe('GB')
        ->and($record['postal']['code'])->toBe('OX1')
        ->and($record['subdivisions'][0]['names']['en'])->toBe('England')
        ->and($record['location']['latitude'])->toBe(51.75)
        ->and($record['location']['longitude'])->toBe(-1.25)
        ->and($record['location']['time_zone'])->toBe('Europe/London');
});

it('returns null for an ip outside the tree', function (): void {
    expect((new Reader(mmdbFixture('GeoIP2-City-Test.mmdb')))->get('10.10.10.10'))->toBeNull();
});

it('returns null for a malformed ip', function (): void {
    expect((new Reader(mmdbFixture('GeoIP2-City-Test.mmdb')))->get('not-an-ip'))->toBeNull();
});

it('reads a 24-bit record-size database', function (): void {
    $reader = new Reader(mmdbFixture('MaxMind-DB-test-ipv4-24.mmdb'));

    expect($reader->metadata()->recordSize)->toBe(24)
        ->and($reader->get('1.1.1.1'))->toBe(['ip' => '1.1.1.1']);
});

it('reads a 32-bit record-size database', function (): void {
    $reader = new Reader(mmdbFixture('MaxMind-DB-test-ipv6-32.mmdb'));

    expect($reader->metadata()->recordSize)->toBe(32)
        ->and($reader->get('::2:0:40'))->toBe(['ip' => '::2:0:40']);
});

it('rejects an ipv6 lookup against an ipv4-only database', function (): void {
    expect((new Reader(mmdbFixture('MaxMind-DB-test-ipv4-24.mmdb')))->get('::1'))->toBeNull();
});

it('decodes every data type from the decoder database', function (): void {
    $record = (new Reader(mmdbFixture('MaxMind-DB-test-decoder.mmdb')))->get('::1.1.1.0');

    expect($record['boolean'])->toBeTrue()
        ->and($record['utf8_string'])->toBe('unicode! ☯ - ♫')
        ->and($record['array'])->toBe([1, 2, 3])
        ->and($record['uint16'])->toBe(100)
        ->and($record['uint32'])->toBe(268435456)
        ->and($record['int32'])->toBe(-268435456)
        ->and($record['double'])->toBe(42.123456)
        ->and($record['map'])->toBeArray()
        ->and($record['uint64'])->toBeString()
        ->and($record['uint128'])->toBeString();
});

it('throws when the database path is missing', function (): void {
    new Reader('');
})->throws(DatabaseNotFoundException::class);

it('throws when the database file does not exist', function (): void {
    new Reader('/tmp/does-not-exist-'.uniqid().'.mmdb');
})->throws(DatabaseNotFoundException::class);

it('throws for a file without a metadata marker', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'mmdb').'.mmdb';
    file_put_contents($path, str_repeat('garbage-data', 50));

    try {
        new Reader($path);
    } finally {
        @unlink($path);
    }
})->throws(InvalidDatabaseException::class);
