<?php

namespace App\Support;

/**
 * Is an address's ZIP code where the restaurant actually is?
 *
 * The old name-only cache backfill copied addresses between same-named
 * venues: three Bahama Breezes in Tampa, Charleston and Jacksonville all
 * showed the Virginia Beach address. Those addresses end in a ZIP but name no
 * state, so the state check (address_other_state) can't see them; the ZIP's
 * location can. A ZIP counts as far from the pin when its centroid is more
 * than max(address_zip_far_km, address_zip_radius_factor × the ZIP's radius)
 * away, so big rural ZIPs get proportionally more room.
 *
 * Centroids: US Census 2024 ZCTA Gazetteer (database/data/zip_centroids.php,
 * public domain). States: the Census 2020 ZCTA-to-county relationship file
 * (database/data/zip_states.php). ZCTAs approximate USPS ZIPs; a ZIP without
 * a ZCTA (PO boxes, single-building ZIPs) answers null — no judgement.
 */
class ZipLocation
{
    /** @var array<int|string, array{0: float, 1: float, 2: float}>|null */
    private static ?array $centroids = null;

    /** @var array<int|string, string>|null */
    private static ?array $states = null;

    /**
     * The address's ZIP: its final token ("…, Austin, TX 78703", "…, 600,
     * Austin, 78703"), else the postal_code column. In BizData's "Street,
     * Number, City" order a lone 5-digit number right after a street with no
     * house number is that house number ("Research Boulevard, 13376"), not a ZIP.
     */
    public static function zipOf(?string $address, ?string $postalCode = null): ?string
    {
        $address = (string) preg_replace('/(?:,\s*|\s+)(?:USA|US|United States)\.?$/i', '', trim((string) $address));
        $address = rtrim(trim($address), ',');
        $parts = array_map('trim', explode(',', $address));
        $houseNumber = count($parts) === 2
            && preg_match('/^\d{5}(-\d{4})?$/', $parts[1]) === 1
            && preg_match('/^\d/', $parts[0]) !== 1;

        if (! $houseNumber && preg_match('/(\d{5})(?:-\d{4})?$/', $address, $m) === 1) {
            return $m[1];
        }

        return preg_match('/^(\d{5})(?:-\d{4})?$/', trim((string) $postalCode), $m) === 1 ? $m[1] : null;
    }

    /**
     * @return array{lat: float, lng: float, radius_km: float}|null
     */
    public static function centroid(string $zip): ?array
    {
        self::$centroids ??= require database_path('data/zip_centroids.php');
        $row = self::$centroids[$zip] ?? null;

        return $row === null ? null : ['lat' => $row[0], 'lng' => $row[1], 'radius_km' => $row[2]];
    }

    /** The ZIP's state ("20910" → "MD"), or null for an unknown ZIP. */
    public static function state(string $zip): ?string
    {
        self::$states ??= require database_path('data/zip_states.php');

        return self::$states[$zip] ?? null;
    }

    /** Distance from the ZIP's centroid to the point, or null for an unknown ZIP. */
    public static function distanceKm(string $zip, float $lat, float $lng): ?float
    {
        $centroid = self::centroid($zip);

        return $centroid === null ? null : self::haversineKm($centroid['lat'], $centroid['lng'], $lat, $lng);
    }

    /**
     * Is the ZIP clearly somewhere else than the point? null when the ZIP is
     * unknown. $minKm overrides the minimum distance (address_zip_far_km).
     */
    public static function isFarFrom(string $zip, float $lat, float $lng, ?float $minKm = null): ?bool
    {
        $centroid = self::centroid($zip);
        if ($centroid === null) {
            return null;
        }

        $threshold = max(
            $minKm ?? (float) config('restaurant-finder.data_integrity.address_zip_far_km', 10),
            (float) config('restaurant-finder.data_integrity.address_zip_radius_factor', 3) * $centroid['radius_km'],
        );

        return self::haversineKm($centroid['lat'], $centroid['lng'], $lat, $lng) > $threshold;
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 12742 * asin(min(1.0, sqrt($a)));
    }
}
