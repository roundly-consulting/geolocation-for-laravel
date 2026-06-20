<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\Enum\DistanceType;

it('builds a geolocation query for an ip', function (): void {
    expect(GeolocationQuery::forIp('8.8.8.8')->ipAddress)->toBe('8.8.8.8');
});

it('builds a geolocation query for an address', function (): void {
    expect(GeolocationQuery::forAddress('Main St')->address)->toBe('Main St');
});

it('builds a geolocation query for coordinates', function (): void {
    $query = GeolocationQuery::forCoordinates(new Coordinates(1.0, 2.0));

    expect($query->latitude)->toBe(1.0)->and($query->longitude)->toBe(2.0)
        ->and($query->coordinates())->toBeInstanceOf(Coordinates::class);
});

it('returns null coordinates when none are set', function (): void {
    expect(GeolocationQuery::forIp('8.8.8.8')->coordinates())->toBeNull();
});

it('produces a stable cache key for the same query', function (): void {
    expect(GeolocationQuery::forIp('8.8.8.8')->cacheKey())
        ->toBe(GeolocationQuery::forIp('8.8.8.8')->cacheKey());
});

it('builds a distance query between two coordinates', function (): void {
    $query = DistanceQuery::between(
        from: new Coordinates(48.14, 17.10),
        to: new Coordinates(50.08, 14.43),
        type: DistanceType::Walking,
    );

    expect($query->fromLatitude)->toBe(48.14)
        ->and($query->toLongitude)->toBe(14.43)
        ->and($query->type)->toBe(DistanceType::Walking)
        ->and($query->from())->toBeInstanceOf(Coordinates::class)
        ->and($query->to())->toBeInstanceOf(Coordinates::class)
        ->and($query->cacheKey())->toBeString();
});

it('defaults the distance query type to driving', function (): void {
    $query = DistanceQuery::between(new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0));

    expect($query->type)->toBe(DistanceType::Driving);
});
