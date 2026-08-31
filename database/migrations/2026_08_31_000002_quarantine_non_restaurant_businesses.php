<?php

use App\Models\Restaurant;
use App\Services\VenuePipeline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * One-time data cleanup: quarantine non-restaurant businesses already
 * persisted before the write-path filter fix (see VenuePipeline::
 * filterNonRestaurants(), now called from RestaurantEnrichmentService).
 *
 * Triggered by "Southern Bail Bonds Dallas" (a bail bonds office) being
 * enriched and cuisine-tagged "Southern". This runs the same
 * VenuePipeline::looksNonRestaurant() check the restaurants:audit-classification
 * command uses, over every currently-active row, and quarantines
 * (is_active = false) anything flagged — never a hard delete, since a
 * name/description heuristic on free text can false-positive. Run locally
 * against a full prod-synced DB (40,670 active rows) found exactly one
 * match with zero false positives before this was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        $venuePipeline = app(VenuePipeline::class);
        $flagged = [];

        Restaurant::active()->orderBy('id')->chunkById(500, function ($restaurants) use ($venuePipeline, &$flagged) {
            foreach ($restaurants as $restaurant) {
                $reason = $venuePipeline->looksNonRestaurant([
                    'name' => $restaurant->name,
                    'description' => $restaurant->description,
                    'place_types' => $restaurant->place_types,
                ]);

                if ($reason !== null) {
                    $flagged[] = $restaurant->id;
                }
            }
        });

        if (! empty($flagged)) {
            Restaurant::whereIn('id', $flagged)->update(['is_active' => false]);

            Log::info('quarantine_non_restaurant_businesses migration flagged rows', [
                'count' => count($flagged),
                'ids' => $flagged,
            ]);
        }
    }

    public function down(): void
    {
        // No-op: re-activating quarantined rows would require re-verifying
        // each one is actually a restaurant, which this migration cannot do.
    }
};
