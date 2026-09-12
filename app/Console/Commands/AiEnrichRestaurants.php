<?php

namespace App\Console\Commands;

use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Restaurant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Backfill command to dispatch AI enrichment jobs for eligible restaurants.
 *
 * With no AI key configured, this command exits cleanly (no-op).
 * With a key, it dispatches a bounded batch per run (services.ai.enrich_per_run),
 * spread evenly over the 6 hours until the next scheduled run. Groq's free
 * tier answers ~350 enrichments a day, so queueing the whole backlog each run
 * (it once dispatched ~40k jobs every 6 hours) only produced 429s.
 *
 * Rows the AI has never tried go first, neediest (most missing AI-fillable
 * fields: description, website_url, address) then highest popularity_score.
 * A row the AI already tried (see Restaurant::lastAiAttemptAt) is eligible
 * again after services.ai.enrich_retry_days.
 */
class AiEnrichRestaurants extends Command
{
    /**
     * Seconds the batch is spread over: the gap between the everySixHours
     * runs scheduled in routes/console.php.
     */
    private const DISPATCH_WINDOW_SECONDS = 6 * 3600;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'restaurants:ai-enrich
                            {--all : Ignore the retry window (rows tried recently are eligible too)}
                            {--id=* : Specific restaurant IDs to enrich}
                            {--limit= : Most jobs to dispatch this run (default: services.ai.enrich_per_run)}
                            {--dry-run : Show what would be processed without dispatching jobs}';

    /**
     * The console command description.
     */
    protected $description = 'Dispatch AI enrichment jobs for restaurants (backfill)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $apiKey = config('services.ai.api_key');

        if (empty($apiKey)) {
            $this->info('No AI key configured (services.ai.api_key). Exiting (no-op).');

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $specificIds = $this->option('id');
        $processAll = $this->option('all');

        if ($dryRun) {
            $this->info('Dry-run mode: showing what would be processed...');
        }

        if (! empty($specificIds)) {
            $restaurants = Restaurant::active()->whereIn('id', $specificIds)->get(['id', 'name']);
            $this->info('Processing specific restaurant IDs: '.implode(', ', $specificIds));
        } else {
            $restaurants = $this->eligibleRestaurants((bool) $processAll);
        }

        if ($restaurants->isEmpty()) {
            $this->warn('No restaurants found matching the criteria.');

            return self::SUCCESS;
        }

        $this->info('Found '.$restaurants->count().' restaurant(s) to process.');

        // Hand-picked IDs run now; the scheduled batch is spread out.
        $spacing = empty($specificIds) ? intdiv(self::DISPATCH_WINDOW_SECONDS, $restaurants->count()) : 0;
        $dispatched = 0;

        foreach ($restaurants->values() as $i => $restaurant) {
            if ($dryRun) {
                $this->line("  Would dispatch: Restaurant #{$restaurant->id} - {$restaurant->name}");
            } else {
                EnrichRestaurantWithAi::dispatch($restaurant->id)
                    ->delay(now()->addSeconds($i * $spacing));
                $dispatched++;
            }
        }

        if ($dryRun) {
            $this->info('Dry-run complete. No jobs were dispatched.');
        } else {
            $this->info("Dispatched {$dispatched} AI enrichment job(s), one every {$spacing}s.");
            $this->info('Make sure a queue worker is running: php artisan queue:work');
        }

        return self::SUCCESS;
    }

    /**
     * This run's batch: never-tried rows first, then neediest, then highest
     * popularity_score, capped at --limit (default services.ai.enrich_per_run).
     *
     * @return Collection<int, Restaurant>
     */
    private function eligibleRestaurants(bool $ignoreRetryWindow): Collection
    {
        $limit = $this->option('limit') !== null
            ? max(1, (int) $this->option('limit'))
            : max(1, (int) config('services.ai.enrich_per_run', 75));
        $retryCutoff = now()->subDays((int) config('services.ai.enrich_retry_days', 30));

        $this->info($ignoreRetryWindow
            ? 'Processing all active restaurants...'
            : 'Processing restaurants the AI has not tried in the last '.config('services.ai.enrich_retry_days', 30).' days...');

        return Restaurant::active()
            ->get(['id', 'name', 'description', 'website_url', 'address', 'popularity_score', 'ai_metadata'])
            ->filter(function (Restaurant $restaurant) use ($ignoreRetryWindow, $retryCutoff): bool {
                $lastAttempt = $restaurant->lastAiAttemptAt();

                return $ignoreRetryWindow || $lastAttempt === null || $lastAttempt->lt($retryCutoff);
            })
            // Among equally needy rows, the higher search impact
            // (popularity_score) row goes first: the free AI quota is spent
            // on the most-visible rows first.
            ->sortByDesc(fn (Restaurant $restaurant) => [
                $restaurant->lastAiAttemptAt() === null ? 1 : 0,
                $this->missingFieldCount($restaurant),
                (float) ($restaurant->popularity_score ?? 0),
            ])
            ->take($limit)
            ->values();
    }

    /**
     * Count AI-fillable fields that are missing on a restaurant.
     * Higher = more urgent; used to dispatch neediest rows first. Phone and
     * price_range are not AI-fillable (EnrichRestaurantWithAi keeps them as
     * inference only), so they don't count.
     */
    private function missingFieldCount(Restaurant $restaurant): int
    {
        $count = 0;
        foreach (['description', 'website_url', 'address'] as $field) {
            if (empty($restaurant->{$field})) {
                $count++;
            }
        }

        return $count;
    }
}
