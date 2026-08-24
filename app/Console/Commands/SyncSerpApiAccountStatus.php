<?php

namespace App\Console\Commands;

use App\Services\SerpApiService;
use Illuminate\Console\Command;

/**
 * Sync SerpApi's own /account.json into the local exhausted-flag + dashboard
 * snapshot (spec-107). Zero quota cost — account.json is an account-info
 * call, not the metered /search endpoint.
 */
class SyncSerpApiAccountStatus extends Command
{
    protected $signature = 'serpapi:sync-account-status';

    protected $description = 'Sync SerpApi account status (searches remaining) from the provider';

    public function handle(SerpApiService $serpApi): int
    {
        if (empty(config('services.serpapi.api_key'))) {
            $this->info('No SerpApi API key configured — skipping.');

            return self::SUCCESS;
        }

        $snapshot = $serpApi->syncAccountStatus();

        if ($snapshot === null) {
            $this->error('SerpApi account-status fetch failed.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'SerpApi account status synced: %s / %s searches left (%s)',
            $snapshot['total_searches_left'] ?? '?',
            $snapshot['searches_per_month'] ?? '?',
            $snapshot['account_status'] ?? 'unknown status',
        ));

        return self::SUCCESS;
    }
}
