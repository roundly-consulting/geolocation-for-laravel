<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Support;

use Illuminate\Validation\Rule;
use RoundlyConsulting\Geolocation\Rules\CoordinatesRule;

/**
 * Registers the package's validation rules onto Laravel's Rule facade, exposing
 * Rule::coordinates() to host applications.
 */
final class ValidationRules
{
    public static function register(): void
    {
        if (! Rule::hasMacro('coordinates')) {
            Rule::macro('coordinates', fn (): CoordinatesRule => new CoordinatesRule);
        }
    }
}
