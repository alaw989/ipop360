<?php

namespace App\Support;

/**
 * Trust tier for a restaurant's photo_source (see
 * RestaurantWebsiteScraperService::searchImageForRestaurant and the
 * add_photo_source_to_restaurants_table migration).
 *
 * HIGH sources are anchored to the specific venue: its own website/social
 * profile, an OSM tag someone attached to this exact node, Wikidata's
 * coordinate-verified P18, or SerpApi's per-place Google Maps thumbnail.
 *
 * LOW sources are keyword searches (name + city/state) guarded only by a
 * textual name-relevance check, not visual content — Wikimedia Commons,
 * Wikipedia, and Google CSE can and do return a photo of the wrong place.
 * `unknown`/null (pre-photo_source legacy rows) are conservatively LOW: we
 * can't positively confirm they're venue-anchored.
 */
class PhotoSourceTier
{
    public const HIGH = ['website', 'social', 'osm', 'wikidata', 'google_thumbnail'];

    public const LOW = ['wikimedia', 'wikipedia', 'google_cse', 'unknown'];

    public static function isHighTrust(?string $source): bool
    {
        return $source !== null && in_array($source, self::HIGH, true);
    }
}
