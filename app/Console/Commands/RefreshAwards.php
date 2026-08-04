<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\WikidataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * spec-104 audit: has_award was only ever set as a side-effect of the
 * throttled enrichment (15 combos/day), so most restaurants were never
 * checked and the signal read 0 everywhere. This standalone command
 * backfills awards for ALL restaurants, grouped into ~city-sized clusters
 * so one cached SPARQL box query covers each cluster.
 *
 * Usage: php artisan restaurants:refresh-awards [--dry-run]
 */
class RefreshAwards extends Command
{
    protected $signature = 'restaurants:refresh-awards {--dry-run : Show what would change without writing}';

    protected $description = 'Backfill Michelin award status for all restaurants from Wikidata';

    /** Degrees per cluster (same half-width as the enrichment award box). */
    private const CLUSTER_DEGREES = 0.25;

    public function handle(WikidataService $wikidata): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $restaurants = Restaurant::active()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'name', 'city', 'latitude', 'longitude', 'has_award']);

        $this->info("Checking {$restaurants->count()} restaurants for awards...");

        // Cluster by rounded coordinates so restaurants near each other share a
        // box query (cached 30d by WikidataService). ~0.25° ≈ a metro area.
        $clusters = $restaurants->groupBy(function (Restaurant $r) {
            return round((float) $r->latitude / self::CLUSTER_DEGREES)
                .':'.round((float) $r->longitude / self::CLUSTER_DEGREES);
        });

        $changed = 0;
        $failed = 0;

        foreach ($clusters as $clusterRestaurants) {
            /** @var Restaurant $first */
            $first = $clusterRestaurants->first();
            $centerLat = (float) $clusterRestaurants->avg('latitude');
            $centerLng = (float) $clusterRestaurants->avg('longitude');

            try {
                $awarded = $wikidata->findAwardedRestaurantsInBox(
                    $centerLat - self::CLUSTER_DEGREES,
                    $centerLng - self::CLUSTER_DEGREES,
                    $centerLat + self::CLUSTER_DEGREES,
                    $centerLng + self::CLUSTER_DEGREES,
                );
            } catch (\Throwable $e) {
                $failed++;
                Log::channel('enrichment')->warning('Award refresh failed for cluster', [
                    'city' => $first->city,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($clusterRestaurants as $restaurant) {
                $hasAward = $wikidata->hasAwardInSet(
                    $restaurant->name ?? '',
                    (float) $restaurant->latitude,
                    (float) $restaurant->longitude,
                    $awarded,
                );

                if ((bool) $restaurant->has_award === $hasAward) {
                    continue;
                }

                $changed++;
                $this->line(sprintf(
                    '  %s (%s): has_award %s -> %s',
                    $restaurant->name,
                    $restaurant->city ?? '?',
                    $restaurant->has_award ? 'true' : 'false',
                    $hasAward ? 'true' : 'false'
                ));

                if (! $dryRun) {
                    $restaurant->update(['has_award' => $hasAward]);
                    Log::channel('enrichment')->info('Award status changed', [
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                        'has_award' => $hasAward,
                    ]);
                }
            }
        }

        $this->newLine();
        $this->info($dryRun ? "Dry run: {$changed} restaurants would change" : "Done. {$changed} restaurants updated, {$failed} clusters failed.");

        return self::SUCCESS;
    }
}
