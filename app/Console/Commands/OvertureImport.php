<?php

namespace App\Console\Commands;

use App\Services\Overture\OvertureImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Monthly Overture Maps corroboration + gap fill (data-integrity phase 3).
 *
 * Report-only by default (matches are counted, nothing is written); --apply
 * records corroboration on each matched restaurant, fills empty
 * phone/address/website/social fields, replaces an address copied from
 * another location, and deactivates restaurants Overture marks permanently
 * closed (both reversibly, via field_quarantine). See OvertureImporter for
 * the matching and fill rules.
 */
class OvertureImport extends Command
{
    protected $signature = 'overture:import
        {--release= : Overture release to use (default: the newest in the public bucket)}
        {--apply : Write corroboration + fills (default: report only)}
        {--skip-socials : Skip adding social profiles (each is reachability-checked inline, the slow part of a first run)}';

    protected $description = 'Match restaurants to Overture Maps places: corroboration, empty-field fills, closures';

    public function handle(OvertureImporter $importer): int
    {
        if (! config('restaurant-finder.overture.enabled', true)) {
            $this->info('Overture import is disabled (OVERTURE_ENABLED=false).');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $release = (string) ($this->option('release') ?: $importer->latestRelease());
        $this->info("Overture release {$release} — ".($apply ? 'APPLY mode.' : 'report only (use --apply to write).'));

        $started = microtime(true);
        $parquet = $importer->extract($release);
        $this->line(sprintf('  Extract ready: %s (%.1f MB, %.0fs)', $parquet, filesize($parquet) / 1048576, microtime(true) - $started));

        $done = 0;
        $stats = $importer->run($parquet, $release, $apply, function (string $block, int $restaurants, int $places) use (&$done): void {
            $done++;
            if ($done % 10 === 0) {
                $this->line("  … {$done} blocks (last {$block}: {$restaurants} restaurants vs {$places} places)");
            }
        }, ! $this->option('skip-socials'));

        $this->pruneOldExtracts($release);

        $this->table(['Metric', 'Count'], array_map(fn ($k, $v) => [$k, $v], array_keys($stats), $stats));
        $this->info(sprintf('Done in %.0fs.', microtime(true) - $started));
        if ($apply && $stats['closed'] > 0) {
            $this->info("{$stats['closed']} restaurant(s) marked permanently closed were deactivated — undo with: php artisan restaurants:integrity --restore=closed_per_overture");
        }
        if ($apply && $stats['address_corrected'] > 0) {
            $this->info("{$stats['address_corrected']} address(es) copied from another location were replaced — the originals are in field_quarantine (reason address_far_from_location).");
        }

        return self::SUCCESS;
    }

    /** Keep only the current release's extract on disk. */
    private function pruneOldExtracts(string $current): void
    {
        foreach (File::directories(storage_path('app/overture')) as $dir) {
            if (basename($dir) !== $current) {
                File::deleteDirectory($dir);
            }
        }
    }
}
