<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UptimeCanary extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'uptime:canary';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check application health and log uptime status';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $status = 'ok';
        $checks = [];

        // Database connectivity check
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
            $this->info('✓ Database: connected');
        } catch (\Exception $e) {
            $status = 'critical';
            $checks['database'] = 'failed: '.$e->getMessage();
            $this->error('✗ Database: disconnected');
        }

        // External API health checks (optional - best-effort)
        $apiEndpoints = [
            'BizData' => config('services.bizdata.url'),
            'Overpass' => config('services.overpass.url'),
        ];

        foreach ($apiEndpoints as $name => $url) {
            if (! $url) {
                $checks["api_{$name}"] = 'skipped (no url)';

                continue;
            }

            try {
                $response = Http::timeout(5)->get($url);
                if ($response->successful()) {
                    $checks["api_{$name}"] = 'ok';
                    $this->info("✓ {$name} API: reachable");
                } else {
                    $status = $status === 'ok' ? 'degraded' : $status;
                    $checks["api_{$name}"] = "failed: {$response->status()}";
                    $this->warn("⚠ {$name} API: returned {$response->status()}");
                }
            } catch (\Exception $e) {
                $status = $status === 'ok' ? 'degraded' : $status;
                $checks["api_{$name}"] = "failed: {$e->getMessage()}";
                $this->warn("⚠ {$name} API: unreachable");
            }
        }

        // Social scrape health check
        try {
            $totalWebsites = DB::table('restaurants')
                ->where('is_active', true)
                ->whereNotNull('website_url')
                ->where('website_url', '!=', '')
                ->count();

            $withSocial = DB::table('restaurants')
                ->where('is_active', true)
                ->where('social_links_count', '>', 0)
                ->count();

            $lastScrape = DB::table('restaurant_social_links')
                ->max('updated_at');

            $hoursSinceLastScrape = $lastScrape
                ? now()->diffInHours($lastScrape)
                : null;

            $checks['social_scrape'] = 'ok';
            $this->info("✓ Social: {$withSocial}/{$totalWebsites} websites have social links");

            if ($totalWebsites > 0 && $withSocial === 0) {
                $status = $status === 'ok' ? 'degraded' : $status;
                $checks['social_scrape'] = 'warning: no social links found';
                $this->warn('⚠ Social scrape: no social links found for any restaurant');
            }

            if ($hoursSinceLastScrape !== null && $hoursSinceLastScrape > 48) {
                $status = $status === 'ok' ? 'degraded' : $status;
                $checks['social_scrape'] = "warning: last scrape {$hoursSinceLastScrape}h ago";
                $this->warn("⚠ Social scrape: not run in {$hoursSinceLastScrape} hours");
            }
        } catch (\Exception $e) {
            $checks['social_scrape'] = 'failed: '.$e->getMessage();
            $this->warn('⚠ Social scrape check failed');
        }

        // Log the overall status
        Log::info('Uptime canary check', [
            'status' => $status,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->info("Overall status: {$status}");

        return $status === 'ok' ? Command::SUCCESS : Command::FAILURE;
    }
}
