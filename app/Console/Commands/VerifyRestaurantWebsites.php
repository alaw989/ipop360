<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\FieldQuarantineService;
use App\Services\WebsiteIdentityVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Weekly identity re-check of stored website URLs.
 *
 * Previously a HEAD liveness check: a dictionary page, a parked domain or a
 * different business's site all answer 200, so none of the 2,443 prod rows
 * pointing at merriam-webster/britannica/imdb… were ever cleared. Each URL now
 * goes through WebsiteIdentityVerifier; a REJECTED site (dead link, lapsed
 * domain, blocked/parked/reference domain, a page that never names the venue,
 * a business in another state) is moved to field_quarantine — reversible, and
 * never re-saved by the backfill — while verified/brand/unconfirmed outcomes
 * are recorded in website_identity. Unreachable sites keep their URL.
 *
 * --unreachable re-checks only websites that carry a check date but no
 * identity verdict (unreachable last time, or checked before identity checks
 * existed) without waiting out --max-age-days.
 */
class VerifyRestaurantWebsites extends Command
{
    protected $signature = 'restaurants:verify-websites
        {--dry-run : Show what would be quarantined without making changes}
        {--limit=0 : Max restaurants to check (0 = unlimited)}
        {--max-age-days=30 : Max days since last verification}
        {--unreachable : Re-check only websites checked before without an identity verdict (ignores --max-age-days)}';

    protected $description = 'Identity-check stored website URLs and quarantine ones that are not the restaurant\'s own site';

    /** @var array<string, int> */
    private array $counts = [
        WebsiteIdentityVerifier::VERIFIED => 0,
        WebsiteIdentityVerifier::BRAND => 0,
        WebsiteIdentityVerifier::UNCONFIRMED => 0,
        WebsiteIdentityVerifier::REJECTED => 0,
        WebsiteIdentityVerifier::UNREACHABLE => 0,
    ];

    /** @var array<string, int> */
    private array $rejectReasons = [];

    public function handle(WebsiteIdentityVerifier $verifier, FieldQuarantineService $quarantine): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $maxAgeDays = (int) $this->option('max-age-days');

        $cutoff = now()->subDays($maxAgeDays);

        $query = Restaurant::query()
            ->active()
            ->whereNotNull('website_url')
            ->where('website_url', '!=', '')
            ->when(
                (bool) $this->option('unreachable'),
                fn ($q) => $q->whereNull('website_identity')->whereNotNull('website_verified_at'),
                fn ($q) => $q->where(function ($q) use ($cutoff) {
                    $q->whereNull('website_verified_at')->orWhere('website_verified_at', '<', $cutoff);
                }),
            )
            // Never-checked rows (null) sort first, then oldest-checked —
            // without this every run re-verifies the same first-N-by-id rows
            // forever and later rows never get checked at all.
            ->orderByRaw('website_verified_at IS NOT NULL')
            // Among never-checked rows, websites an import just filled (tagged
            // in field_sources) go first: they are new values already shown on
            // the site, while the untagged backlog has been waiting anyway.
            ->orderByRaw("json_extract(field_sources, '$.website_url') IS NULL")
            ->orderBy('website_verified_at')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $restaurants = $query->get();
        $total = $restaurants->count();

        if ($total === 0) {
            $this->warn('No restaurants with website URLs to verify.');

            return self::SUCCESS;
        }

        $this->info("Identity-checking {$total} website URLs...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($restaurants as $restaurant) {
            $url = (string) $restaurant->website_url;
            $verdict = $verifier->verify($restaurant, $url);
            $this->counts[$verdict->status]++;

            if ($verdict->isRejected()) {
                $this->rejectReasons[$verdict->reason] = ($this->rejectReasons[$verdict->reason] ?? 0) + 1;
                $this->warn("  Rejected ({$verdict->reason}): {$url} ({$restaurant->name})");

                if (! $dryRun) {
                    $quarantine->quarantineFields(
                        $restaurant,
                        ['website_url'],
                        'website_'.$verdict->reason,
                        'restaurants:verify-websites',
                        ['url' => $url, 'evidence' => $verdict->evidence],
                    );
                }
            } elseif (! $dryRun) {
                $updates = ['website_verified_at' => now()];
                if ($verdict->storedIdentity() !== null) {
                    $updates['website_identity'] = $verdict->storedIdentity();
                }
                $restaurant->update($updates);
            }

            $bar->advance();

            // Small delay to avoid hammering servers
            if (! $dryRun) {
                usleep(100_000);
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info(sprintf(
            'Done. %d verified, %d brand, %d unconfirmed, %d rejected, %d skipped (unreachable).',
            $this->counts[WebsiteIdentityVerifier::VERIFIED],
            $this->counts[WebsiteIdentityVerifier::BRAND],
            $this->counts[WebsiteIdentityVerifier::UNCONFIRMED],
            $this->counts[WebsiteIdentityVerifier::REJECTED],
            $this->counts[WebsiteIdentityVerifier::UNREACHABLE],
        ));

        Log::channel('enrichment')->info('Website URL verification complete', [
            'total' => $total,
            'counts' => $this->counts,
            'reject_reasons' => $this->rejectReasons,
            'max_age_days' => $maxAgeDays,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
