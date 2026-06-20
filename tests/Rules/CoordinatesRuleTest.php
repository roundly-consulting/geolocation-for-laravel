<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Geolocation\Rules\CoordinatesRule;

it('passes for a valid coordinate string', function () {
    $validator = Validator::make(
        ['point' => '48.1486,17.1077'],
        ['point' => [Rule::coordinates()]],
    );

    expect($validator->passes())->toBeTrue();
});

it('passes for a latitude/longitude array', function () {
    $validator = Validator::make(
        ['point' => ['latitude' => 10, 'longitude' => 20]],
        ['point' => [new CoordinatesRule]],
    );

    expect($validator->passes())->toBeTrue();
});

it('passes for an indexed pair array', function () {
    $validator = Validator::make(
        ['point' => [10, 20]],
        ['point' => [new CoordinatesRule]],
    );

    expect($validator->passes())->toBeTrue();
});

it('fails for an out-of-range coordinate', function () {
    $validator = Validator::make(
        ['point' => '120,200'],
        ['point' => [new CoordinatesRule]],
    );

    expect($validator->fails())->toBeTrue();
});

it('fails for a non-numeric coordinate', function () {
    $validator = Validator::make(
        ['point' => 'north,west'],
        ['point' => [new CoordinatesRule]],
    );

    expect($validator->fails())->toBeTrue();
});

it('fails for an unparseable value', function () {
    $validator = Validator::make(
        ['point' => 12345],
        ['point' => [new CoordinatesRule]],
    );

    expect($validator->fails())->toBeTrue();
});
