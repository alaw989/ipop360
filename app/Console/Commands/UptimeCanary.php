<?php

namespace App\Console\Commands;

use App\Models\ExternalApiCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
                ? now()->diffInHours($lastScrape, true)
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

        // SerpApi quota health check (read-only, no outbound calls)
        try {
            $stats = ExternalApiCache::stats();
            $serpapiCalls = $stats['serpapi_calls_last_30d'];
            $freeQuota = (int) config('restaurant-finder.serpapi.free_quota', 250);
            $circuitBreakerThreshold = (int) ceil($freeQuota * (float) config('restaurant-finder.serpapi.circuit_breaker_fraction', 0.8));
            $enrichBudget = (int) config('restaurant-finder.enrich.monthly_budget', 150);
            $remaining = max(0, $freeQuota - $serpapiCalls);
            $pctUsed = $freeQuota > 0 ? round(($serpapiCalls / $freeQuota) * 100) : 0;

            $checks['serpapi_quota'] = "{$serpapiCalls}/{$freeQuota} ({$pctUsed}%) used, {$remaining} remaining";
            $this->info("✓ SerpApi quota: {$serpapiCalls}/{$freeQuota} ({$pctUsed}%) used, {$remaining} remaining");

            if ($serpapiCalls >= $freeQuota) {
                $status = $status === 'ok' ? 'degraded' : $status;
                $checks['serpapi_quota'] = "exhausted: {$serpapiCalls}/{$freeQuota} used, resets monthly";
                $this->warn("⚠ SerpApi quota: exhausted ({$serpapiCalls}/{$freeQuota})");
            } elseif ($serpapiCalls >= $circuitBreakerThreshold) {
                $status = $status === 'ok' ? 'degraded' : $status;
                $checks['serpapi_quota'] = "near limit: {$serpapiCalls}/{$freeQuota} (circuit breaker at {$circuitBreakerThreshold})";
                $this->warn("⚠ SerpApi quota: near limit ({$serpapiCalls}/{$freeQuota}, circuit breaker at {$circuitBreakerThreshold})");
            } elseif ($serpapiCalls >= $enrichBudget) {
                $checks['serpapi_quota'] = "enrichment budget exhausted: {$serpapiCalls}/{$enrichBudget} used, {$remaining} remaining for live search";
                $this->info("ℹ SerpApi enrichment budget used ({$serpapiCalls}/{$enrichBudget}), {$remaining} remaining for live search");
            }
        } catch (\Exception $e) {
            $checks['serpapi_quota'] = 'failed: '.$e->getMessage();
            $this->warn('⚠ SerpApi quota check failed');
        }

        // Log the overall status
        Log::info('Uptime canary check', [
            'status' => $status,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->info("Overall status: {$status}");

        $this->notifyIfDegraded($status, $checks);

        // Only a genuine outage (critical) fails the scheduled run. A soft
        // "degraded" blip (a 5s external-API timeout, a stale social scrape,
        // crossing the SerpApi circuit-breaker threshold) is real information
        // — still logged and still eligible for the consecutive-failure
        // webhook above — but shouldn't pollute scheduler-health telemetry
        // with a "failed" run every time something transient happens.
        return $status === 'critical' ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Track consecutive degraded/critical runs and fire webhook when threshold is reached.
     */
    /**
     * @param  array<string, string>  $checks
     */
    private function notifyIfDegraded(string $status, array $checks): void
    {
        $webhookUrl = config('services.alerting.webhook_url');

        if ($status === 'ok') {
            Cache::forget('uptime:degraded_count');

            return;
        }

        $threshold = (int) config('services.alerting.consecutive_threshold', 3);
        $count = (int) Cache::get('uptime:degraded_count', 0) + 1;
        Cache::put('uptime:degraded_count', $count, now()->addDay());

        if ($count < $threshold) {
            Log::info("Uptime degraded ({$count}/{$threshold} consecutive), alert pending", [
                'checks' => $checks,
            ]);

            return;
        }

        Log::warning("Uptime degraded for {$count} consecutive checks — threshold reached", [
            'checks' => $checks,
        ]);

        if (empty($webhookUrl)) {
            return;
        }

        try {
            Http::timeout(10)->post($webhookUrl, [
                'text' => '⚠️ *iPop360 Uptime Alert*',
                'attachments' => [[
                    'color' => $status === 'critical' ? 'danger' : 'warning',
                    'fields' => collect($checks)->map(fn ($val, $key) => [
                        'title' => $key,
                        'value' => $val,
                        'short' => true,
                    ])->values()->all(),
                    'footer' => 'uptime:canary',
                    'ts' => now()->timestamp,
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to send uptime alert webhook', ['error' => $e->getMessage()]);
        }
    }
}
