<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation;

/**
 * Thin alias of {@see GeolocationManager}. Prefer the `Geolocation` facade
 * (or resolving {@see GeolocationManager} from the container) in new code.
 */
final class Geolocation extends GeolocationManager {}
