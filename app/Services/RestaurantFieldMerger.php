<?php

namespace App\Services;

use App\Models\FieldQuarantine;
use App\Models\Restaurant;

/**
 * Decides what an incoming source record may change on an EXISTING restaurant
 * row. Both write paths that upsert source venues (the enrichment grid and
 * live-search persistence) run their attributes through forExisting() before
 * update().
 *
 * Source records are partial: a BizData or OSM venue usually carries a name and
 * coordinates and little else. Writing one straight over a matched row with
 * update($attributes) nulled every field the source lacked. The daily
 * enrichment grid did that to the same ~1,600 rows every run, erasing phones,
 * addresses, websites and photos that Overture and the scrapers had filled,
 * and reactivating restaurants Overture had closed.
 *
 * Rules:
 *  - An empty incoming value never replaces a stored one.
 *  - Descriptive fields only fill blanks. When two sources disagree, the
 *    stored value stays; resolving conflicts is the integrity tooling's job.
 *  - A positive rating replaces the stored rating and review count as a pair
 *    (a fresh provider reading), unless it is the exact pair the integrity
 *    cleanup quarantined for this row.
 *  - A value quarantined for this row is never filled back in.
 *  - is_active, slug and has_award never change on an update.
 *  - A stable photo may replace a transient Google gps-cs-s photo. A gallery
 *    is only replaced by one that keeps every stored photo.
 */
class RestaurantFieldMerger
{
    /** Never written to an existing row by a source record. */
    private const NEVER_ON_UPDATE = ['is_active', 'slug', 'has_award'];

    /** Rating column => its review-count column; the two are written together. */
    private const RATING_PAIRS = [
        'google_rating' => 'google_review_count',
        'yelp_rating' => 'yelp_review_count',
    ];

    /**
     * @param  array<string, mixed>  $incoming  normalized attributes from a source record
     * @return array<string, mixed> the subset safe to update() the existing row with
     */
    public function forExisting(Restaurant $existing, array $incoming): array
    {
        $quarantined = $this->quarantinedValues($existing);
        $merged = [];

        foreach (self::RATING_PAIRS as $ratingField => $countField) {
            $rating = $incoming[$ratingField] ?? null;
            $count = max(0, (int) ($incoming[$countField] ?? 0));
            unset($incoming[$ratingField], $incoming[$countField]);

            if (! is_numeric($rating) || (float) $rating <= 0.0) {
                continue;
            }
            if ($this->isQuarantinedPair($quarantined, $ratingField, $countField, (float) $rating, $count)) {
                continue;
            }

            $merged[$ratingField] = (float) $rating;
            $merged[$countField] = $count;
        }

        // photo_source describes photo_url, so it is written only with it.
        $photoSource = $incoming['photo_source'] ?? null;
        unset($incoming['photo_source']);

        foreach ($incoming as $field => $value) {
            if (in_array($field, self::NEVER_ON_UPDATE, true) || $this->isEmpty($value)) {
                continue;
            }
            if (is_scalar($value) && in_array((string) $value, $quarantined[$field] ?? [], true)) {
                continue;
            }

            $current = $existing->getAttribute($field);

            if ($this->isEmpty($current)
                || ($field === 'photo_url' && $this->replacesTransientPhoto($current, $value))
                || ($field === 'photos' && $this->keepsEveryPhoto($current, $value))) {
                $merged[$field] = $value;
            }
        }

        if (array_key_exists('photo_url', $merged)) {
            $merged['photo_source'] = $photoSource;
        }

        return $merged;
    }

    /**
     * Unrestored quarantined values for the row, field => raw values.
     *
     * @return array<string, list<string>>
     */
    private function quarantinedValues(Restaurant $existing): array
    {
        $values = [];
        FieldQuarantine::query()
            ->where('restaurant_id', $existing->id)
            ->whereNull('restored_at')
            ->where('field', '!=', FieldQuarantineService::SOCIAL_LINK_FIELD)
            ->get(['field', 'old_value'])
            ->each(function (FieldQuarantine $entry) use (&$values): void {
                $values[$entry->field][] = (string) $entry->old_value;
            });

        return $values;
    }

    /**
     * @param  array<string, list<string>>  $quarantined
     */
    private function isQuarantinedPair(array $quarantined, string $ratingField, string $countField, float $rating, int $count): bool
    {
        $ratingMatches = array_filter($quarantined[$ratingField] ?? [], fn (string $old): bool => abs((float) $old - $rating) < 0.001);
        $countMatches = array_filter($quarantined[$countField] ?? [], fn (string $old): bool => (int) $old === $count);

        return $ratingMatches !== [] && $countMatches !== [];
    }

    private function replacesTransientPhoto(mixed $current, mixed $incoming): bool
    {
        return is_string($current) && $this->isTransientPhoto($current)
            && is_string($incoming) && ! $this->isTransientPhoto($incoming);
    }

    private function keepsEveryPhoto(mixed $current, mixed $incoming): bool
    {
        return is_array($current) && is_array($incoming)
            && array_diff(array_filter($current, 'is_string'), array_filter($incoming, 'is_string')) === [];
    }

    /** Google gps-cs-s CDN photo URLs (from SerpApi) decay after about a month. */
    private function isTransientPhoto(string $url): bool
    {
        return str_contains(strtolower($url), 'gps-cs-s');
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) === '')
            || $value === [];
    }
}
