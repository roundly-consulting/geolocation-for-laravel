<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Geolocation\Casts\CoordinatesCast;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;

/**
 * Gives an Eloquent model a transparent `coordinates` value object (backed by latitude and
 * longitude columns) plus a bounding-box scope for cheap "near a point" filtering.
 *
 * The host model should expose `latitude` and `longitude` columns and may add:
 *
 *   protected $casts = ['coordinates' => CoordinatesCast::class];
 *
 * @phpstan-require-extends Model
 */
trait HasLocation
{
    public function initializeHasLocation(): void
    {
        if (! array_key_exists('coordinates', $this->casts)) {
            $this->casts['coordinates'] = CoordinatesCast::class;
        }
    }

    /**
     * Restrict the query to rows whose latitude/longitude fall inside the bounding box of
     * the given radius (in kilometres) around a point — wrapping across the antimeridian and
     * spanning every longitude when the circle reaches a pole. A cheap pre-filter: combine
     * with an exact Haversine check in PHP for precise results.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithinRadius(Builder $query, Coordinates $center, float $radiusKm): Builder
    {
        $box = $center->boundingBox($radiusKm);

        $query->whereBetween('latitude', [$box->southWest->latitude, $box->northEast->latitude]);

        if ($box->crossesAntimeridian()) {
            return $query->where(static fn (Builder $wrapped): Builder => $wrapped
                ->where('longitude', '>=', $box->southWest->longitude)
                ->orWhere('longitude', '<=', $box->northEast->longitude));
        }

        return $query->whereBetween('longitude', [$box->southWest->longitude, $box->northEast->longitude]);
    }
}
