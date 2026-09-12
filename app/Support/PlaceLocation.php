<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Is a city name where the restaurant actually is?
 *
 * Rows carry cities that aren't where their pin is: the search grid's name on
 * every venue it found ("Ann Arbor" on a Novi restaurant 32 km away), and
 * cities copied between same-named venues by the old name-only backfill
 * ("Anchorage, AK" on Farzi NYC). A city counts as far from the pin when the
 * nearest place with that name in the row's state is more than
 * max(city_place_far_km, city_place_radius_factor × the place's radius) away.
 *
 * Places: US Census 2024 Gazetteer place file (database/data/place_centroids.php,
 * public domain), built by scripts/build-place-centroids.php. Census internal
 * points can sit off-center for places with islands or large water areas (San
 * Francisco's is on the Farallon Islands, 55 km out), so distance alone never
 * decides a close call: callers need corroborating evidence below
 * city_unmatched_far_km. A name the state has no place for (a neighborhood,
 * an unincorporated area) answers null — no judgement.
 */
class PlaceLocation
{
    /** @var array<string, array<string, list<array{0: float, 1: float, 2: float}>>>|null */
    private static ?array $places = null;

    /**
     * The lookup form of a place name: ASCII, lowercase, punctuation-free, with
     * Saint/Mount/Fort shortened ("St. Louis" and "Saint Louis" both read
     * "st louis").
     */
    public static function normalize(string $name): string
    {
        $name = strtolower(Str::ascii($name));
        $name = (string) preg_replace(['/\bsaint\b/', '/\bmount\b/', '/\bfort\b/'], ['st', 'mt', 'ft'], $name);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name));
    }

    /**
     * A search-grid label with its state glued on ("Washington Dc",
     * "portland me") read as the place it names, or null when the name has no
     * such suffix. The full label must not itself be a place.
     */
    public static function withoutStateSuffix(string $city): ?string
    {
        if (preg_match('/^(.+?)\s+([A-Za-z]{2})$/', trim($city), $m) !== 1
            || ! in_array(strtoupper($m[2]), StateAbbreviations::MAP, true)
            || self::named($city) !== []) {
            return null;
        }

        return self::named($m[1]) === [] ? null : $m[1];
    }

    /**
     * Every place with this name, by state.
     *
     * @return array<string, list<array{0: float, 1: float, 2: float}>>
     */
    public static function named(string $name): array
    {
        self::$places ??= require database_path('data/place_centroids.php');

        return self::$places[self::normalize($name)] ?? [];
    }

    /**
     * Distance from the point to the nearest place with this name in the
     * state, and how far counts as "far" for that place; null when the state
     * has no such place.
     *
     * @return array{km: float, far_km: float}|null
     */
    public static function nearest(string $name, string $state, float $lat, float $lng): ?array
    {
        $best = null;
        foreach (self::named($name)[strtoupper($state)] ?? [] as [$pLat, $pLng, $radius]) {
            $km = self::haversineKm($pLat, $pLng, $lat, $lng);
            if ($best === null || $km < $best['km']) {
                $best = ['km' => $km, 'far_km' => self::farKm($radius)];
            }
        }

        return $best;
    }

    /**
     * Is the named place clearly somewhere else than the point? null when the
     * state has no such place.
     */
    public static function isFarFrom(string $name, string $state, float $lat, float $lng): ?bool
    {
        $nearest = self::nearest($name, $state, $lat, $lng);

        return $nearest === null ? null : $nearest['km'] > $nearest['far_km'];
    }

    /**
     * The states in which a place with this name sits at the point.
     *
     * @return list<string>
     */
    public static function statesAt(string $name, float $lat, float $lng): array
    {
        $states = [];
        foreach (array_keys(self::named($name)) as $state) {
            if (self::isFarFrom($name, (string) $state, $lat, $lng) === false) {
                $states[] = (string) $state;
            }
        }

        return $states;
    }

    private static function farKm(float $radiusKm): float
    {
        return max(
            (float) config('restaurant-finder.data_integrity.city_place_far_km', 25),
            (float) config('restaurant-finder.data_integrity.city_place_radius_factor', 3) * $radiusKm,
        );
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 12742 * asin(min(1.0, sqrt($a)));
    }
}
