<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The US Census place list (database/data/place_centroids.php) as a flat,
 * deterministically ordered catalog of seedable points.
 *
 * The grid in config/restaurant-finder.php only names ~98 metros, so small
 * towns never reach the scheduler. This catalog lets restaurants:seed-places
 * walk every Census place on a stable cursor instead.
 */
class CensusPlaceCatalog
{
    /** @var list<array{key: string, name: string, state: string, lat: float, lng: float}>|null */
    private ?array $places = null;

    /**
     * Every place point, sorted by normalized name then state so a string
     * cursor can resume a run deterministically.
     *
     * @return list<array{key: string, name: string, state: string, lat: float, lng: float}>
     */
    public function all(): array
    {
        if ($this->places !== null) {
            return $this->places;
        }

        /** @var array<string, array<string, list<array{0: float|string, 1: float|string, 2: float|string}>>> $raw */
        $raw = require database_path('data/place_centroids.php');

        $places = [];
        foreach ($raw as $key => $states) {
            $name = Str::title((string) $key);
            foreach ($states as $state => $points) {
                foreach ($points as $point) {
                    $places[] = [
                        'key' => (string) $key,
                        'name' => $name,
                        'state' => (string) $state,
                        'lat' => (float) $point[0],
                        'lng' => (float) $point[1],
                    ];
                }
            }
        }

        usort($places, fn (array $a, array $b): int => [$a['key'], $a['state']] <=> [$b['key'], $b['state']]);

        return $this->places = $places;
    }
}
