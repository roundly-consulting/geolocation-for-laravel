<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\Exceptions\GeolocationException;
use RoundlyConsulting\Geolocation\Exceptions\InvalidDatabaseException;
use RoundlyConsulting\Geolocation\Facades\Geolocation;
use RoundlyConsulting\Geolocation\MaxMind\Reader;
use RoundlyConsulting\Geolocation\MaxMind\ReaderCache;

beforeEach(function (): void {
    $this->database = sys_get_temp_dir().'/'.uniqid('city_', true).'.mmdb';
    copy(__DIR__.'/../Fixtures/test-data/GeoIP2-City-Test.mmdb', $this->database);

    config()->set('geolocation.pipeline', ['maxmind_database']);
    config()->set('geolocation.services.maxmind_database.enabled', true);
    config()->set('geolocation.services.maxmind_database.path', $this->database);
});

afterEach(function (): void {
    @unlink($this->database);
});

it('opens the database once per process, not once per lookup', function (): void {
    expect(Geolocation::locateIp('2.125.160.216')?->city)->toBe('Boxford');

    // Blank the file IN PLACE, keeping its inode, size and mtime: a provider that re-reads
    // the file per lookup now decodes zeros; one that kept its reader never notices.
    $mtime = filemtime($this->database);
    file_put_contents($this->database, str_repeat("\0", (int) filesize($this->database)));
    touch($this->database, (int) $mtime);
    clearstatcache();

    expect(Geolocation::locateIp('2.125.160.216')?->city)->toBe('Boxford')
        ->and(Geolocation::batch(['2.125.160.216'])['2.125.160.216']?->city)->toBe('Boxford')
        ->and(app(ReaderCache::class))->toBe(app(ReaderCache::class));
});

it('reopens the database once it is replaced on disk', function (): void {
    $cache = app(ReaderCache::class);
    $before = $cache->get($this->database);

    // What geolocation:db:update does: a new file renamed over the old one.
    $replacement = $this->database.'.new';
    copy(__DIR__.'/../Fixtures/test-data/GeoIP2-City-Test.mmdb', $replacement);
    rename($replacement, $this->database);

    $after = $cache->get($this->database);

    expect($after)->not->toBe($before)
        ->and($after)->toBeInstanceOf(Reader::class)
        ->and($cache->get($this->database))->toBe($after);
});

it('keeps refusing a corrupt replacement instead of falling back to the old reader', function (): void {
    $cache = app(ReaderCache::class);
    $cache->get($this->database);

    $corrupt = $this->database.'.new';
    file_put_contents($corrupt, random_bytes(4096));
    rename($corrupt, $this->database);

    expect(fn () => $cache->get($this->database))->toThrow(InvalidDatabaseException::class)
        ->and(fn () => $cache->get($this->database))->toThrow(InvalidDatabaseException::class);

    // Once a valid file is back, the cache opens it.
    $valid = $this->database.'.new';
    copy(__DIR__.'/../Fixtures/test-data/GeoIP2-City-Test.mmdb', $valid);
    rename($valid, $this->database);

    expect($cache->get($this->database))->toBeInstanceOf(Reader::class);
});

it('keeps refusing a deleted database instead of serving the old reader', function (): void {
    $cache = app(ReaderCache::class);
    $cache->get($this->database);

    unlink($this->database);

    expect(fn () => $cache->get($this->database))->toThrow(GeolocationException::class)
        ->and(fn () => $cache->get($this->database))->toThrow(GeolocationException::class);
});
