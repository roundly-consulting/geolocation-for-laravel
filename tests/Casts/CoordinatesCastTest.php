<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Geolocation\Casts\CoordinatesCast;
use RoundlyConsulting\Geolocation\Concerns\HasLocation;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Geolocation\Exceptions\InvalidCoordinatesException;

/**
 * @property Coordinates|null $coordinates
 */
final class Place extends Model
{
    use HasLocation;

    protected $table = 'places';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    Schema::create('places', function ($table) {
        $table->id();
        $table->string('name');
        $table->float('latitude')->nullable();
        $table->float('longitude')->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists('places');
});

it('stores and restores coordinates transparently', function () {
    $place = new Place(['name' => 'HQ']);
    $place->coordinates = new Coordinates(48.1486, 17.1077);
    $place->save();

    $fresh = Place::query()->find($place->id);

    expect($fresh->coordinates)->toBeInstanceOf(Coordinates::class)
        ->latitude->toBe(48.1486)
        ->longitude->toBe(17.1077)
        ->and($fresh->getAttribute('latitude'))->toEqual(48.1486);
});

it('returns null coordinates when columns are empty', function () {
    $place = Place::query()->create(['name' => 'Nowhere']);

    expect(Place::query()->find($place->id)->coordinates)->toBeNull();
});

it('clears the columns when set to null', function () {
    $place = new Place(['name' => 'HQ']);
    $place->coordinates = new Coordinates(1.0, 2.0);
    $place->save();

    $place->coordinates = null;
    $place->save();

    expect($place->fresh()->coordinates)->toBeNull();
});

it('rejects a non-coordinates value', function () {
    $place = new Place(['name' => 'HQ']);

    expect(fn () => $place->coordinates = 'not-a-coordinate')
        ->toThrow(InvalidCoordinatesException::class);
});

it('supports a custom-column cast instance', function () {
    $cast = new CoordinatesCast('lat', 'lng');
    $model = new Place;

    $result = $cast->set($model, 'coordinates', new Coordinates(3.0, 4.0), []);

    expect($result)->toBe(['lat' => 3.0, 'lng' => 4.0]);
});

it('filters models within a radius scope', function () {
    Place::query()->create(['name' => 'Near', 'latitude' => 48.15, 'longitude' => 17.11]);
    Place::query()->create(['name' => 'Far', 'latitude' => 10.0, 'longitude' => 10.0]);

    $results = Place::query()
        ->withinRadius(new Coordinates(48.1486, 17.1077), 10)
        ->pluck('name')
        ->all();

    expect($results)->toBe(['Near']);
});

it('finds rows across the antimeridian with the radius scope', function () {
    Place::query()->create(['name' => 'Over the line', 'latitude' => 0.0, 'longitude' => -179.9]);
    Place::query()->create(['name' => 'Far', 'latitude' => 0.0, 'longitude' => 0.0]);

    $names = Place::query()->withinRadius(new Coordinates(0.0, 179.9), 50)->pluck('name')->all();

    expect($names)->toBe(['Over the line']);
});

it('finds rows on the far side of a pole with the radius scope', function () {
    Place::query()->create(['name' => 'Across the pole', 'latitude' => 89.9, 'longitude' => 180.0]);
    Place::query()->create(['name' => 'Far', 'latitude' => 80.0, 'longitude' => 0.0]);

    $names = Place::query()->withinRadius(new Coordinates(89.5, 0.0), 100)->pluck('name')->all();

    expect($names)->toBe(['Across the pole']);
});
