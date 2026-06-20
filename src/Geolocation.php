<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation;

use Closure;
use Illuminate\Support\Collection;
use RoundlyConsulting\Geolocation\DataTransferObjects\Distance;
use RoundlyConsulting\Geolocation\DataTransferObjects\DistanceQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\GeolocationQuery;
use RoundlyConsulting\Geolocation\DataTransferObjects\Location;

final class Geolocation
{
    public function locate(GeolocationQuery $query): ?Location
    {
        return $this->byFirstProvider(
            GeolocationProvider::class,
            fn (GeolocationProvider $provider): ?Location => $provider->locate($query),
        );
    }

    public function distance(DistanceQuery $query): ?Distance
    {
        return $this->byFirstProvider(
            DistanceProvider::class,
            fn (DistanceProvider $provider): ?Distance => $provider->distance($query),
        );
    }

    /**
     * @template TProvider of object
     * @template TResult
     *
     * @param  class-string<TProvider>  $interface
     * @param  Closure(TProvider): (TResult|null)  $run
     * @return TResult|null
     */
    private function byFirstProvider(string $interface, Closure $run): mixed
    {
        foreach ($this->providers() as $provider) {
            if (! $provider instanceof $interface) {
                continue;
            }

            $result = $run($provider);

            if (! is_null($result)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, object>
     */
    private function providers(): Collection
    {
        /** @var list<class-string> $providers */
        $providers = config('geolocation.providers', []);

        return collect($providers)
            ->map(fn (string $provider): object => resolve($provider))
            ->values();
    }
}
