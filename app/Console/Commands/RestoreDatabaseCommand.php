<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * spec-087/spec-103: restore the DB from a pre-migrate snapshot — the data side
 * of the opt-in deploy rollback (`DEPLOY_AUTO_ROLLBACK`). Pairs with `db:backup`.
 *
 * SQLite: overwrites the live DB file with the newest (or chosen) `VACUUM INTO`
 * snapshot. MySQL (prod since spec-104): pipes the newest gzipped dump into the
 * mysql client. Destructive in both cases — requires --force.
 *
 * The code side of a rollback is restored by the deploy workflow's rsync of the
 * pre-deploy `releases/<ts>/` hardlink snapshot; together they form a consistent
 * pre-deploy restore point.
 */
class RestoreDatabaseCommand extends Command
{
    private const MYSQL_RESTORE_TIMEOUT = 1800;

    protected $signature = 'db:restore
        {--path= : Specific snapshot file to restore (default: newest in --backup-dir)}
        {--backup-dir= : Backup directory (default: storage/backups)}
        {--force : Required — restore overwrites the live DB}';

    protected $description = 'Restore the DB from a pre-migrate snapshot (spec-087 rollback).';

    public function handle(): int
    {
        $connection = Config::get('database.default');
        $driver = is_string($connection) ? Config::get("database.connections.{$connection}.driver") : null;

        $dir = rtrim((string) ($this->option('backup-dir') ?: storage_path('backups')), '/');
        $explicit = $this->option('path');
        $explicit = is_string($explicit) && $explicit !== '' ? $explicit : null;

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (! $this->option('force')) {
                $this->error('db:restore overwrites the live DB — pass --force to confirm.');

                return CommandAlias::FAILURE;
            }

            return $this->restoreMysql($dir, $explicit);
        }

        return $this->restoreSqlite($dir, $explicit);
    }

    private function restoreSqlite(string $dir, ?string $explicit): int
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

        $backup = $explicit ?? $this->latestBackup($dir, ['sqlite']);
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
     * MySQL: `gunzip -c <dump> | mysql` (pipefail so a gunzip error fails too).
     * The password is passed via MYSQL_PWD (never argv).
     */
    private function restoreMysql(string $dir, ?string $explicit): int
    {
        $backup = $explicit ?? $this->latestBackup($dir, ['sql.gz']);
        if (! $backup || ! file_exists($backup)) {
            $this->error("No snapshot to restore in {$dir}.");

            return CommandAlias::FAILURE;
        }

        $conn = Config::get('database.connections.mysql');
        if (! is_array($conn)) {
            $this->error('MySQL connection is not configured.');

            return CommandAlias::FAILURE;
        }

        $host = $this->str($conn['host'] ?? null, '127.0.0.1');
        $port = $this->str($conn['port'] ?? null, '3306');
        $database = $this->str($conn['database'] ?? null);
        $username = $this->str($conn['username'] ?? null);
        $password = $this->str($conn['password'] ?? null);

        if ($database === '' || $username === '') {
            $this->error('MySQL database/username is not configured.');

            return CommandAlias::FAILURE;
        }

        $binary = $this->str(config('database.connections.mysql.restore_binary'), 'mysql');

        $cmd = sprintf(
            'gunzip -c %s | %s --host=%s --port=%s --user=%s %s',
            escapeshellarg($backup),
            escapeshellarg($binary),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
        );

        try {
            $result = Process::timeout(self::MYSQL_RESTORE_TIMEOUT)
                ->env(['MYSQL_PWD' => $password])
                ->run(['bash', '-o', 'pipefail', '-c', $cmd]);
        } catch (\Throwable $e) {
            $this->error('mysql restore could not run: '.$e->getMessage());

            return CommandAlias::FAILURE;
        }

        if (! $result->successful()) {
            $this->error('mysql restore failed: '.trim(substr($result->errorOutput() ?: $result->output(), 0, 500)));

            return CommandAlias::FAILURE;
        }

        $this->info("DB restored from {$backup}");

        return CommandAlias::SUCCESS;
    }

    /**
     * The newest pre-migrate snapshot for the given extensions (filenames carry
     * a unix timestamp, so a lexical sort is a chronological sort).
     *
     * @param  list<string>  $extensions
     */
    private function latestBackup(string $dir, array $extensions): ?string
    {
        $existing = [];
        foreach ($extensions as $ext) {
            $existing = array_merge($existing, glob("{$dir}/pre-migrate-*.{$ext}") ?: []);
        }
        sort($existing); // oldest first

        return $existing ? (string) end($existing) : null;
    }

    private function str(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
