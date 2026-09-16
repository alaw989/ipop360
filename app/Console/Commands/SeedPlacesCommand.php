<?php

namespace App\Console\Commands;

use App\Models\EnrichmentPlaceCursor;
use App\Models\Restaurant;
use App\Services\RestaurantEnrichmentService;
use App\Support\CensusPlaceCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Seed restaurants for US Census places on a resumable cursor.
 *
 * The throttled enrichment grid only names ~98 metros, so small towns (Troy,
 * AL) only ever get a DB footprint if a human live-searches them. This walks
 * the Census place catalog instead, one bounded slice per run, seeding each
 * place from the free sources that already answer a live search there. Never
 * spends SerpApi quota; report-first (`--apply` to persist).
 */
class SeedPlacesCommand extends Command
{
    protected $signature = 'restaurants:seed-places
                            {--apply : Persist discovered venues (default: report only)}
                            {--limit=25 : Max places to process this run}
                            {--min-rows=5 : Skip places that already have at least this many restaurants}
                            {--state= : Only process places in this 2-letter state}
                            {--start= : Resume from this "name|STATE" cursor (overrides the stored one)}';

    protected $description = 'Seed restaurants for underserved US Census places (free sources only, report-first)';

    public function __construct(
        private CensusPlaceCatalog $catalog,
        private RestaurantEnrichmentService $enrichment,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $minRows = max(0, (int) $this->option('min-rows'));
        $state = $this->option('state') !== null ? strtoupper((string) $this->option('state')) : null;

        $places = $this->catalog->all();

        if ($state !== null) {
            $places = array_values(array_filter($places, fn (array $p): bool => $p['state'] === $state));
        }

        $cursor = $this->option('start') !== null
            ? (string) $this->option('start')
            : EnrichmentPlaceCursor::current();

        if ($cursor !== null) {
            $places = array_values(array_filter(
                $places,
                fn (array $p): bool => $this->cursorKey($p) > $cursor
            ));
        }

        if ($places === []) {
            $suffix = $state !== null ? " in {$state}" : '';
            $this->info("No Census places left to seed{$suffix}.");

            return self::SUCCESS;
        }

        $batch = array_slice($places, 0, $limit);
        $mode = $apply ? 'apply' : 'report only';
        $this->info('Seeding '.count($batch).' of '.count($places)." pending places ({$mode})");

        $rows = [];
        $seeded = 0;
        // Batch is non-empty here (the empty case returns above), so the last
        // processed place is always the cursor's new position.
        $lastKey = $this->cursorKey($batch[array_key_last($batch)]);

        foreach ($batch as $place) {
            $existing = $this->existingCount($place);
            $result = $existing >= $minRows ? 'skip' : 'pending';

            if ($result === 'pending' && $apply) {
                $count = $this->enrichment->seedAtPlace(
                    $place['lat'],
                    $place['lng'],
                    $place['name'],
                    $place['state'],
                );
                $seeded += $count;
                $result = (string) $count;
            }

            $rows[] = [$place['name'], $place['state'], $existing, $result];
        }

        $this->table(['Place', 'State', 'Existing', $apply ? 'Seeded' : 'Action'], $rows);

        if ($apply) {
            EnrichmentPlaceCursor::advance($lastKey);
            $this->info("Seeded {$seeded} restaurants. Cursor now at {$lastKey}.");
        }

        Log::channel('enrichment')->info('Place seeding run complete', [
            'apply' => $apply,
            'places_processed' => count($batch),
            'places_pending' => count($places),
            'restaurants_seeded' => $seeded,
            'cursor' => $lastKey,
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array{key: string, name: string, state: string, lat: float, lng: float}  $place
     */
    private function cursorKey(array $place): string
    {
        return $place['key'].'|'.$place['state'];
    }

    /**
     * Approximate existing coverage for a place by name (case-insensitive) and
     * state — enough to skip towns that already have a footprint without a
     * per-place geo query.
     *
     * @param  array{key: string, name: string, state: string, lat: float, lng: float}  $place
     */
    private function existingCount(array $place): int
    {
        return Restaurant::query()
            ->whereRaw('LOWER(city) IN (?, ?)', [strtolower($place['key']), strtolower($place['name'])])
            ->where('state', $place['state'])
            ->count();
    }
}
