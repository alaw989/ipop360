<?php

namespace App\Console\Commands;

use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Restaurant;
use Illuminate\Console\Command;

/**
 * Backfill command to dispatch AI enrichment jobs for eligible restaurants.
 *
 * With no AI key configured, this command exits cleanly (no-op).
 * With a key, it dispatches jobs for restaurants that haven't been enriched
 * or were enriched more than the freshness window ago. Rows missing core
 * fields (price_range, description, phone, website_url) re-enter eligibility
 * after 1 day; complete rows after 7. Neediest rows dispatch first.
 */
class AiEnrichRestaurants extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'restaurants:ai-enrich
                            {--all : Process all restaurants, not just those needing enrichment}
                            {--id=* : Specific restaurant IDs to enrich}
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

        $query = Restaurant::active();

        // Filter by specific IDs if provided
        if (! empty($specificIds)) {
            $query->whereIn('id', $specificIds);
            $this->info('Processing specific restaurant IDs: '.implode(', ', $specificIds));
        } elseif (! $processAll) {
            // Only process restaurants needing enrichment (null ai_metadata or
            // enriched more than 7 days ago). Filter in PHP because the previous
            // SQL approach (whereJsonDoesntContain) matched an exact microsecond
            // timestamp and was true for virtually every row.
            $this->info('Processing restaurants not enriched in the last 7 days...');
        } else {
            $this->info('Processing all active restaurants...');
        }

        $restaurants = $query->get();

        if (! $processAll && empty($specificIds)) {
            $restaurants = $restaurants->filter(function ($restaurant) {
                $missingCount = $this->missingFieldCount($restaurant);
                $windowDays = $missingCount > 0 ? 1 : 7;

                if (empty($restaurant->ai_metadata['enriched_at'])) {
                    return true;
                }

                $enrichedAt = now()->parse($restaurant->ai_metadata['enriched_at']);

                return $enrichedAt->lt(now()->subDays($windowDays));
            });

            // Neediest first: rows missing the most AI-fillable fields get
            // dispatched before rows that are already mostly complete, so the
            // free AI quota closes the deepest gaps first.
            $restaurants = $restaurants->sortByDesc(fn ($restaurant) => $this->missingFieldCount($restaurant))->values();
        }

        if ($restaurants->isEmpty()) {
            $this->warn('No restaurants found matching the criteria.');

            return self::SUCCESS;
        }

        $this->info('Found '.$restaurants->count().' restaurant(s) to process.');

        $bar = $this->output->createProgressBar($restaurants->count());
        $bar->start();

        $dispatched = 0;
        $i = 0;

        foreach ($restaurants as $restaurant) {
            if ($dryRun) {
                $this->newLine();
                $this->line("  Would dispatch: Restaurant #{$restaurant->id} - {$restaurant->name}");
            } else {
                EnrichRestaurantWithAi::dispatch($restaurant->id)
                    ->delay(now()->addSeconds(min($i * 5, 3600)));
                $dispatched++;
            }

            $i++;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($dryRun) {
            $this->info('Dry-run complete. No jobs were dispatched.');
        } else {
            $this->info("Dispatched {$dispatched} AI enrichment job(s) to the queue.");
            $this->info('Make sure a queue worker is running: php artisan queue:work');
        }

        return self::SUCCESS;
    }

    /**
     * Count AI-fillable fields that are missing on a restaurant.
     * Higher = more urgent; used to dispatch neediest rows first.
     */
    private function missingFieldCount(Restaurant $restaurant): int
    {
        $count = 0;
        foreach (['price_range', 'description', 'phone', 'website_url'] as $field) {
            if (empty($restaurant->{$field})) {
                $count++;
            }
        }

        return $count;
    }
}
