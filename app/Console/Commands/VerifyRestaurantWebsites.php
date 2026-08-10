<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VerifyRestaurantWebsites extends Command
{
    protected $signature = 'restaurants:verify-websites
        {--dry-run : Show what would be cleared without making changes}
        {--limit=0 : Max restaurants to check (0 = unlimited)}
        {--max-age-days=30 : Max days since last verification}';

    protected $description = 'HEAD-check existing website URLs and clear dead links';

    private int $verified = 0;

    private int $dead = 0;

    private int $skipped = 0;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $maxAgeDays = (int) $this->option('max-age-days');

        $query = Restaurant::query()
            ->active()
            ->whereNotNull('website_url')
            ->where('website_url', '!=', '');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->warn('No restaurants with website URLs to verify.');

            return self::SUCCESS;
        }

        $this->info("Verifying {$total} website URLs...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($restaurants) use ($dryRun, $bar) {
            foreach ($restaurants as $restaurant) {
                try {
                    $response = Http::timeout(8)
                        ->withUserAgent('Mozilla/5.0 (compatible; iPop360-Verify/1.0)')
                        ->head((string) $restaurant->website_url);

                    if ($response->successful()) {
                        $this->verified++;
                    } elseif (in_array($response->status(), [404, 410], true)) {
                        $this->dead++;
                        $this->warn("  Dead link: {$restaurant->website_url} ({$restaurant->name}) — HTTP {$response->status()}");

                        if (! $dryRun) {
                            $restaurant->update(['website_url' => null]);
                        }
                    } else {
                        $this->skipped++;
                        $this->warn("  Transient error: {$restaurant->website_url} ({$restaurant->name}) — HTTP {$response->status()}, keeping URL");
                    }
                } catch (\Throwable $e) {
                    $this->skipped++;
                    $this->warn("  Request failed: {$restaurant->website_url} ({$restaurant->name}) — {$e->getMessage()}, keeping URL");
                }

                $bar->advance();

                // Small delay to avoid hammering servers
                if (! $dryRun) {
                    usleep(100_000);
                }
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done. {$this->verified} alive, {$this->dead} dead, {$this->skipped} skipped (transient).");

        Log::info('Website URL verification complete', [
            'total' => $total,
            'verified' => $this->verified,
            'dead' => $this->dead,
            'skipped' => $this->skipped,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
