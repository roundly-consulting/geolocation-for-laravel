<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\Enum\GeolocationType;
use RoundlyConsulting\Geolocation\Facades\Geolocation;

it('resolves the client location from the request macro', function () {
    $location = new Location(
        humanReadable: 'Somewhere',
        street: '',
        city: 'Somewhere',
        countryIsoCode: 'SK',
        latitude: 1.0,
        longitude: 2.0,
        type: GeolocationType::Ip,
    );

    Geolocation::fake()->seed('1.2.3.4', $location);

    $request = Request::create('/', server: ['REMOTE_ADDR' => '1.2.3.4']);

    expect($request->location())->toBe($location);
});

it('returns null when the request has no ip', function () {
    Geolocation::fake();

    $request = Request::create('/');
    $request->server->remove('REMOTE_ADDR');

    expect($request->location())->toBeNull();
});
