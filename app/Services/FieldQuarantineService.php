<?php

namespace App\Services;

use App\Models\FieldQuarantine;
use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reversible removal of bad restaurant data.
 *
 * Integrity checks never null/delete a value in place: the raw column value is
 * copied into field_quarantine (with the reason and the detector that flagged
 * it), then cleared on the row. restore() puts it back — but only into an
 * empty column, so a newer good value is never clobbered. Raw values are read
 * and written with casts bypassed so JSON/array columns round-trip exactly.
 */
class FieldQuarantineService
{
    /** Pseudo-field used for quarantined restaurant_social_links rows. */
    public const SOCIAL_LINK_FIELD = 'social_link';

    /**
     * Columns this service may quarantine. `is_active` is how a closure is
     * recorded: quarantining it deactivates the restaurant (stored value '1'),
     * and a restore reactivates it.
     */
    private const FIELDS = [
        'website_url', 'phone', 'address', 'price_range', 'description',
        'photo_url', 'photos', 'opening_hours', 'menu_url',
        'google_rating', 'google_review_count', 'is_active',
    ];

    /** Quarantined together: a rating without its review count is meaningless. */
    private const RATING_FIELDS = ['google_rating', 'google_review_count'];

    /** NOT NULL columns are cleared to their schema default instead of NULL. */
    private const CLEARED_VALUES = ['google_review_count' => 0, 'is_active' => 0];

    /**
     * Move the given columns aside and clear them on the row. Empty values are
     * skipped. Returns how many columns were actually quarantined.
     *
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $details
     */
    public function quarantineFields(Restaurant $restaurant, array $fields, string $reason, string $detector, array $details = []): int
    {
        $fields = array_values(array_intersect($fields, self::FIELDS));
        if ($fields === []) {
            return 0;
        }

        return DB::transaction(function () use ($restaurant, $fields, $reason, $detector, $details): int {
            $cleared = [];

            foreach ($fields as $field) {
                $raw = $restaurant->getRawOriginal($field);
                if ($this->isEmptyValue($field, $raw)) {
                    continue;
                }

                FieldQuarantine::create([
                    'restaurant_id' => $restaurant->id,
                    'field' => $field,
                    'old_value' => (string) $raw,
                    'reason' => $reason,
                    'detector' => $detector,
                    'details' => $details === [] ? null : $details,
                    'quarantined_at' => now(),
                ]);
                $cleared[$field] = self::CLEARED_VALUES[$field] ?? null;
            }

            if ($cleared === []) {
                return 0;
            }

            if (array_key_exists('website_url', $cleared)) {
                $cleared['website_identity'] = null;
                $cleared['website_verified_at'] = now();
            }

            // Bypass casts/events: write NULLs straight to the row, then sync the
            // in-memory model so callers see the cleared state.
            Restaurant::query()->whereKey($restaurant->id)->update($cleared);
            $restaurant->forceFill($cleared)->syncOriginal();

            Log::channel('enrichment')->info('Restaurant fields quarantined', [
                'restaurant_id' => $restaurant->id,
                'fields' => array_keys(array_intersect_key($cleared, array_flip(self::FIELDS))),
                'reason' => $reason,
                'detector' => $detector,
            ]);

            return count(array_intersect_key($cleared, array_flip(self::FIELDS)));
        });
    }

    /**
     * Quarantine a google rating + review count pair together.
     *
     * @param  array<string, mixed>  $details
     */
    public function quarantineRating(Restaurant $restaurant, string $reason, string $detector, array $details = []): int
    {
        return $this->quarantineFields($restaurant, self::RATING_FIELDS, $reason, $detector, $details);
    }

    /**
     * Remove a social-link row, keeping a restorable copy. The caller is
     * responsible for re-counting the restaurant's social_links_count.
     */
    public function quarantineSocialLink(RestaurantSocialLink $link, string $reason, string $detector): void
    {
        DB::transaction(function () use ($link, $reason, $detector): void {
            FieldQuarantine::create([
                'restaurant_id' => $link->restaurant_id,
                'field' => self::SOCIAL_LINK_FIELD,
                'old_value' => (string) json_encode([
                    'platform' => $link->platform,
                    'url' => $link->url,
                    'scope' => $link->scope,
                    'followers' => $link->followers,
                    'verified_at' => $link->verified_at?->toISOString(),
                ]),
                'reason' => $reason,
                'detector' => $detector,
                'quarantined_at' => now(),
            ]);

            $link->delete();
        });
    }

    /**
     * Whether this exact value was quarantined for this restaurant and never
     * restored — backfills use it so a rejected URL is never re-saved.
     */
    public function isQuarantined(int $restaurantId, string $field, string $value): bool
    {
        return FieldQuarantine::query()
            ->where('restaurant_id', $restaurantId)
            ->where('field', $field)
            ->where('old_value', $value)
            ->whereNull('restored_at')
            ->exists();
    }

    /**
     * Put a quarantined value back. Returns false (and leaves the entry
     * un-restored) when the target column has since been filled, or the social
     * link platform slot is taken — a newer value always wins.
     */
    public function restore(FieldQuarantine $entry): bool
    {
        if ($entry->restored_at !== null) {
            return false;
        }

        return DB::transaction(function () use ($entry): bool {
            if ($entry->field === self::SOCIAL_LINK_FIELD) {
                /** @var array<string, mixed> $row */
                $row = json_decode((string) $entry->old_value, true) ?: [];
                $platform = (string) ($row['platform'] ?? '');
                $taken = RestaurantSocialLink::query()
                    ->where('restaurant_id', $entry->restaurant_id)
                    ->where('platform', $platform)
                    ->exists();
                if ($platform === '' || $taken) {
                    return false;
                }

                RestaurantSocialLink::create([
                    'restaurant_id' => $entry->restaurant_id,
                    'platform' => $platform,
                    'url' => (string) ($row['url'] ?? ''),
                    'scope' => (string) ($row['scope'] ?? RestaurantSocialLink::SCOPE_LOCATION),
                    'followers' => $row['followers'] ?? null,
                    'verified_at' => $row['verified_at'] ?? null,
                ]);
            } else {
                // Raw column value (toBase skips casts: a cast is_active reads
                // as boolean false, which is not the stored "cleared" 0).
                $current = Restaurant::query()->whereKey($entry->restaurant_id)->toBase()->value($entry->field);
                if (! $this->isEmptyValue($entry->field, $current)) {
                    return false;
                }

                Restaurant::query()->whereKey($entry->restaurant_id)->update([$entry->field => $entry->old_value]);
            }

            $entry->forceFill(['restored_at' => now()])->save();

            return true;
        });
    }

    private function isEmptyValue(string $field, mixed $raw): bool
    {
        if ($raw === null || $raw === '' || $raw === '[]') {
            return true;
        }

        return array_key_exists($field, self::CLEARED_VALUES)
            && is_scalar($raw)
            && (string) $raw === (string) self::CLEARED_VALUES[$field];
    }
}
