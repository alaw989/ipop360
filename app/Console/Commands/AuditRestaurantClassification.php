<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\VenuePipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retroactive audit for non-restaurant businesses already persisted in the
 * `restaurants` table.
 *
 * Triggered by a real leak: "Southern Bail Bonds Dallas" was enriched and
 * cuisine-tagged "Southern" because the non-restaurant filter that already
 * existed for live search (spec-042/046) never ran on the write path
 * (RestaurantEnrichmentService). That gap is fixed, but rows written before
 * the fix — and rows from before `place_types`/`source` were persisted at
 * all — are unaudited. This command re-checks every active row against the
 * same VenuePipeline heuristic (structural place_types when present, a
 * conservative name/description pattern check otherwise).
 *
 * Default is a read-only dry run; pass --apply to persist. Flagged rows are
 * QUARANTINED (is_active = false), never hard-deleted — a name/description
 * heuristic on free text can false-positive (e.g. a converted-bank-building
 * restaurant), so this surfaces candidates for human review rather than
 * auto-purging.
 */
class AuditRestaurantClassification extends Command
{
    protected $signature = 'restaurants:audit-classification
        {--apply : Quarantine (is_active=false) flagged rows (default is a read-only dry run)}
        {--limit=0 : Max restaurants to scan (0 = all)}';

    protected $description = 'Audit persisted restaurants for likely non-restaurant businesses';

    public function handle(VenuePipeline $venuePipeline): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        $query = Restaurant::active()->orderBy('id');
        $total = $limit > 0 ? min($limit, $query->count()) : $query->count();

        if ($total === 0) {
            $this->warn('No active restaurants to audit.');

            return self::SUCCESS;
        }

        $this->info("Auditing {$total} active restaurants...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $scanned = 0;
        $flagged = [];

        $query->chunkById(500, function ($restaurants) use ($venuePipeline, $bar, &$scanned, &$flagged, $limit, $total) {
            foreach ($restaurants as $restaurant) {
                if ($limit > 0 && $scanned >= $limit) {
                    return false;
                }

                $scanned++;

                $reason = $venuePipeline->looksNonRestaurant([
                    'name' => $restaurant->name,
                    'description' => $restaurant->description,
                    'place_types' => $restaurant->place_types,
                ]);

                if ($reason !== null) {
                    $flagged[] = [
                        'id' => $restaurant->id,
                        'name' => $restaurant->name,
                        'slug' => $restaurant->slug,
                        'source' => $restaurant->source,
                        'reason' => $reason,
                    ];
                }

                $bar->advance();

                if ($scanned >= $total) {
                    return false;
                }
            }

            return true;
        });

        $bar->finish();
        $this->newLine(2);

        $this->line("Scanned: {$scanned}  Flagged: ".count($flagged));
        $this->line('Mode: '.($apply ? '<fg=green>APPLIED (flagged rows quarantined)</>' : '<fg=yellow>DRY RUN (no changes persisted)</>'));

        if (! empty($flagged)) {
            $this->newLine();
            $this->table(
                ['id', 'name', 'slug', 'source', 'reason'],
                array_map(fn ($f) => [$f['id'], $f['name'], $f['slug'], $f['source'] ?? '(unknown)', $f['reason']], $flagged),
            );

            Log::info('restaurants:audit-classification flagged rows', [
                'count' => count($flagged),
                'applied' => $apply,
                'flagged' => $flagged,
            ]);

            if ($apply) {
                Restaurant::whereIn('id', array_column($flagged, 'id'))->update(['is_active' => false]);
                $this->newLine();
                $this->info(count($flagged).' row(s) quarantined (is_active=false).');
            } else {
                $this->newLine();
                $this->line('Run with --apply to quarantine these rows.');
            }
        }

        return self::SUCCESS;
    }
}
