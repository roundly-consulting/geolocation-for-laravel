<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\Enum\GeolocationType;

it('exposes its backed values through the Helpers trait', function (): void {
    expect(GeolocationType::values()->all())->toBe(['Default', 'IP', 'Geolocation'])
        ->and(GeolocationType::names()->all())->toBe(['Default', 'Ip', 'Geolocation']);
});

it('keeps the IP wire value unchanged', function (): void {
    expect(GeolocationType::Ip->value)->toBe('IP');
});

it('builds a validation rule from its values', function (): void {
    expect(GeolocationType::validationRule())->toBe('in:Default,IP,Geolocation');
});

it('derives readable labels, preserving the IP acronym', function (): void {
    // Ip overrides readable() to keep the "IP" acronym (the trait would headline
    // the backed value "IP" to "I P"); other cases use the trait's headline.
    expect(GeolocationType::Ip->readable())->toBe('IP')
        ->and(GeolocationType::Default->readable())->toBe('Default')
        ->and(GeolocationType::Geolocation->readable())->toBe('Geolocation');
});

it('resolves cases by name and value', function (): void {
    expect(GeolocationType::tryFromName('Geolocation'))->toBe(GeolocationType::Geolocation)
        ->and(GeolocationType::tryFromName('Nope'))->toBeNull()
        ->and(GeolocationType::hasValue('IP'))->toBeTrue()
        ->and(GeolocationType::hasValue('nope'))->toBeFalse();
});

it('answers identity questions', function (): void {
    expect(GeolocationType::Ip->is(GeolocationType::Ip))->toBeTrue()
        ->and(GeolocationType::Ip->isNot(GeolocationType::Default))->toBeTrue()
        ->and(GeolocationType::Ip->isIn([GeolocationType::Ip, GeolocationType::Geolocation]))->toBeTrue()
        ->and(GeolocationType::Default->isNotIn([GeolocationType::Ip]))->toBeTrue();
});
