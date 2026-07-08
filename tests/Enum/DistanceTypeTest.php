<?php

declare(strict_types=1);

use RoundlyConsulting\Enums\DataTransferObjects\EnumOption;
use RoundlyConsulting\Geolocation\Enum\DistanceType;

it('exposes its backed values through the Helpers trait', function (): void {
    expect(DistanceType::values()->all())->toBe(['Walking', 'Driving'])
        ->and(DistanceType::names()->all())->toBe(['Walking', 'Driving']);
});

it('builds a validation rule from its values', function (): void {
    expect(DistanceType::validationRule())->toBe('in:Walking,Driving');
});

it('produces readable labels', function (): void {
    expect(DistanceType::Driving->readable())->toBe('Driving')
        ->and(DistanceType::Walking->label())->toBe('Walking')
        ->and(DistanceType::labels()->all())->toBe(['Walking', 'Driving']);
});

it('maps to select options', function (): void {
    expect(DistanceType::toOptions()->all())->toBe([
        'Walking' => 'Walking',
        'Driving' => 'Driving',
    ]);

    $options = DistanceType::options();

    expect($options)->toHaveCount(2)
        ->and($options->first())->toBeInstanceOf(EnumOption::class)
        ->and($options->first()->value)->toBe('Walking')
        ->and($options->first()->label)->toBe('Walking')
        ->and($options->first()->name)->toBe('Walking');
});

it('resolves cases by name and value', function (): void {
    expect(DistanceType::tryFromName('Driving'))->toBe(DistanceType::Driving)
        ->and(DistanceType::tryFromName('Nope'))->toBeNull()
        ->and(DistanceType::hasValue('Walking'))->toBeTrue()
        ->and(DistanceType::hasValue('Flying'))->toBeFalse();
});

it('answers identity questions', function (): void {
    expect(DistanceType::Driving->is(DistanceType::Driving))->toBeTrue()
        ->and(DistanceType::Driving->isNot(DistanceType::Walking))->toBeTrue()
        ->and(DistanceType::Walking->isIn([DistanceType::Walking, DistanceType::Driving]))->toBeTrue()
        ->and(DistanceType::Walking->isNotIn([DistanceType::Driving]))->toBeTrue();
});
