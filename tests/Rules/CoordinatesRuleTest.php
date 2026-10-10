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

it('names the attribute by its display name', function () {
    $validator = Validator::make(
        ['home_location' => 'x'],
        ['home_location' => [new CoordinatesRule]],
    );

    expect($validator->errors()->first('home_location'))
        ->toBe('The home location must be a valid latitude/longitude coordinate.');
});

it('honours custom attribute names', function () {
    $validator = Validator::make(
        ['point' => '120,200'],
        ['point' => [new CoordinatesRule]],
        [],
        ['point' => 'map pin'],
    );

    expect($validator->errors()->first('point'))
        ->toBe('The map pin must be a valid latitude/longitude coordinate.');
});

it('answers in slovak under the sk locale', function (mixed $value) {
    app()->setLocale('sk');

    $validator = Validator::make(
        ['home_location' => $value],
        ['home_location' => [new CoordinatesRule]],
    );

    expect($validator->errors()->first('home_location'))
        ->toBe('Pole home location musí obsahovať platné súradnice (zemepisnú šírku a dĺžku).');
})->with([
    'non-numeric' => 'north,west',
    'out of range' => '120,200',
]);

it('hands a translated message to a closure that returns nothing', function (mixed $value) {
    $messages = [];

    (new CoordinatesRule)->validate('point', $value, function (string $message) use (&$messages): void {
        $messages[] = $message;
    });

    expect($messages)->toBe(['The :attribute must be a valid latitude/longitude coordinate.']);
})->with([
    'non-numeric' => 'north,west',
    'out of range' => '120,200',
]);

it('lets the host override the message', function () {
    app('translator')->addLines(['validation.coordinates' => 'X :attribute'], 'en', 'geolocation');

    $validator = Validator::make(
        ['point' => 'north,west'],
        ['point' => [new CoordinatesRule]],
    );

    expect($validator->errors()->first('point'))->toBe('X point');
});
