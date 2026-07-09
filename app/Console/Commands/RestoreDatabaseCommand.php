<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * spec-087: restore the SQLite DB from a pre-migrate snapshot — the data side of
 * the opt-in deploy rollback (`DEPLOY_AUTO_ROLLBACK`). Pairs with `db:backup`
 * (spec-077), which snapshots via VACUUM INTO before every migration.
 *
 * Destructive: overwrites the live DB file with the chosen (default: newest)
 * snapshot. Requires --force. The matching code side is restored by the deploy
 * workflow's rsync of the pre-deploy `releases/<ts>/` hardlink snapshot; together
 * they form a consistent pre-deploy restore point. Clears any WAL sidecars after
 * the copy so the restored file isn't paired with a stale `-wal`/`-shm` (a no-op
 * under the project's default DELETE journal — `DB_JOURNAL_MODE` is unset).
 *
 * This command issues no queries, so Laravel's lazy default connection is never
 * opened and the live file isn't held during the copy; the deploy's fpm restart
 * drops any external (web-request) connections immediately after.
 */
class RestoreDatabaseCommand extends Command
{
    protected $signature = 'db:restore
        {--path= : Specific snapshot file to restore (default: newest in --backup-dir)}
        {--backup-dir= : Backup directory (default: storage/backups)}
        {--force : Required — restore overwrites the live DB}';

    protected $description = 'Restore the SQLite DB from a pre-migrate snapshot (spec-087 rollback).';

    public function handle(): int
    {
        $dbPath = Config::get('database.connections.sqlite.database');

        if (! is_string($dbPath) || $dbPath === ':memory:' || ! file_exists($dbPath)) {
            $this->warn('SQLite DB is in-memory or not found; nothing to restore.');

            return CommandAlias::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->error('db:restore overwrites the live DB — pass --force to confirm.');

            return CommandAlias::FAILURE;
        }

        $dir = rtrim((string) ($this->option('backup-dir') ?: storage_path('backups')), '/');

        $explicitPath = $this->option('path');
        $backup = (is_string($explicitPath) && $explicitPath !== '') ? $explicitPath : $this->latestBackup($dir);

        if (! $backup || ! file_exists($backup)) {
            $this->error("No snapshot to restore in {$dir}.");

            return CommandAlias::FAILURE;
        }

        if (! @copy($backup, $dbPath)) {
            $this->error("Restore failed: cannot copy {$backup} over the live DB.");

            return CommandAlias::FAILURE;
        }

        @chmod($dbPath, 0664);

        // Clear WAL sidecars so the restored file stands alone (no-op under DELETE journal).
        foreach (['-wal', '-shm'] as $suffix) {
            @unlink($dbPath.$suffix);
        }

        $this->info("DB restored from {$backup}");

        return CommandAlias::SUCCESS;
    }

    /**
     * The newest pre-migrate snapshot (filenames carry a unix timestamp, so a
     * lexical sort of the equal-length numeric names is a chronological sort).
     */
    private function latestBackup(string $dir): ?string
    {
        $existing = glob("{$dir}/pre-migrate-*.sqlite") ?: [];
        sort($existing); // oldest first

        return $existing ? (string) end($existing) : null;
    }
}
