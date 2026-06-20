<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Support;

use Illuminate\Http\Request;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;
use RoundlyConsulting\Geolocation\GeolocationManager;

/**
 * Registers a $request->location() macro that resolves the client's location from its IP
 * address through the geolocation manager.
 */
final class RequestMacro
{
    public static function register(): void
    {
        if (Request::hasMacro('location')) {
            return;
        }

        Request::macro('location', function (): ?Location {
            /** @var Request $this */
            return app(GeolocationManager::class)->locateRequest($this);
        });
    }
}
