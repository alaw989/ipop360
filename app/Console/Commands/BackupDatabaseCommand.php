<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use PDO;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * spec-077/spec-103: snapshot the DB before a migration (run from the deploy
 * step). A bad migration is the project's biggest data-loss risk — losing
 * external_api_cache + restaurants is a multi-month rebuild gated by the
 * ~250/mo SerpApi quota, not a re-fetch.
 *
 * SQLite: `VACUUM INTO` produces a transactionally-consistent snapshot while
 * the DB is live. MySQL (prod since spec-104): `mysqldump --single-transaction`
 * to a temp file, gzipped. Either way the produced file is verified non-trivial
 * or the command FAILS, so the deploy can gate `migrate` on a real backup (a
 * missing/empty safety net must never pass silently).
 */
class BackupDatabaseCommand extends Command
{
    private const MYSQL_DUMP_TIMEOUT = 900;

    /** A backup smaller than this is treated as missing/empty. */
    private const MIN_BACKUP_BYTES = 1024;

    protected $signature = 'db:backup
        {--keep=10 : Number of recent snapshots to retain}
        {--path= : Backup directory (default: storage/backups)}';

    protected $description = 'Snapshot the DB (SQLite VACUUM INTO / MySQL mysqldump) with rotation — run before migrations.';

    public function handle(): int
    {
        $connection = Config::get('database.default');
        $driver = is_string($connection) ? Config::get("database.connections.{$connection}.driver") : null;

        $dir = rtrim((string) ($this->option('path') ?: storage_path('backups')), '/');
        $keep = (int) $this->option('keep');

        return match ($driver) {
            'sqlite' => $this->backupSqlite($dir, $keep),
            'mysql', 'mariadb' => $this->backupMysql($dir, $keep),
            default => $this->unsupportedDriver(is_string($driver) ? $driver : 'unknown'),
        };
    }

    private function unsupportedDriver(string $driver): int
    {
        $this->error("db:backup does not know how to back up the '{$driver}' driver.");

        return CommandAlias::FAILURE;
    }

    /**
     * SQLite: VACUUM INTO a fresh file (live-safe). Skips gracefully for the
     * in-memory DB used by tests.
     */
    private function backupSqlite(string $dir, int $keep): int
    {
        $dbPath = Config::get('database.connections.sqlite.database');

        if (! is_string($dbPath) || $dbPath === ':memory:' || ! file_exists($dbPath)) {
            $this->warn('SQLite DB is in-memory or not found; nothing to back up.');

            return CommandAlias::SUCCESS;
        }

        $dir = $this->resolveDir($dir);
        if ($dir === null) {
            return CommandAlias::FAILURE;
        }

        $backup = "{$dir}/pre-migrate-".time().'.sqlite';

        try {
            $pdo = new PDO('sqlite:'.$dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('VACUUM INTO '.$pdo->quote($backup));
        } catch (\Throwable $e) {
            $this->error('DB backup failed: '.$e->getMessage());
            @unlink($backup);

            return CommandAlias::FAILURE;
        }

        if (! $this->isNonTrivial($backup)) {
            $this->error("DB backup is missing or empty: {$backup}");
            @unlink($backup);

            return CommandAlias::FAILURE;
        }

        $this->info("DB backup created: {$backup}");
        $this->rotate($dir, $keep);

        return CommandAlias::SUCCESS;
    }

    /**
     * MySQL: mysqldump --single-transaction to a temp .sql, gzip it, delete the
     * intermediate. The password is passed via MYSQL_PWD (never argv).
     */
    private function backupMysql(string $path, int $keep): int
    {
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
        $driver = $this->str($conn['driver'] ?? null, 'mysql');

        if ($database === '' || $username === '') {
            $this->error('MySQL database/username is not configured.');

            return CommandAlias::FAILURE;
        }

        $dir = $this->resolveDir($path);
        if ($dir === null) {
            return CommandAlias::FAILURE;
        }

        $ts = time();
        $raw = "{$dir}/pre-migrate-{$ts}.sql";
        $gz = "{$dir}/pre-migrate-{$ts}.sql.gz";

        $binary = $this->str(config('database.connections.mysql.dump_binary'), 'mysqldump');

        $args = [
            $binary,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--host='.$host,
            '--port='.$port,
            '--user='.$username,
            '--result-file='.$raw,
            $database,
        ];

        // MySQL 8 only; MariaDB rejects the flag.
        if ($driver === 'mysql') {
            array_splice($args, 6, 0, ['--set-gtid-purged=OFF']);
        }

        try {
            $result = Process::timeout(self::MYSQL_DUMP_TIMEOUT)
                ->env(['MYSQL_PWD' => $password])
                ->run($args);
        } catch (\Throwable $e) {
            $this->error('mysqldump could not run: '.$e->getMessage());
            @unlink($raw);
            @unlink($gz);

            return CommandAlias::FAILURE;
        }

        if (! $result->successful()) {
            $this->error('mysqldump failed: '.trim(substr($result->errorOutput() ?: $result->output(), 0, 500)));
            @unlink($raw);
            @unlink($gz);

            return CommandAlias::FAILURE;
        }

        if (! $this->isNonTrivial($raw) || ! $this->gzipFile($raw, $gz) || ! $this->isNonEmpty($gz)) {
            $this->error('MySQL dump is missing or empty — refusing to trust it.');
            @unlink($raw);
            @unlink($gz);

            return CommandAlias::FAILURE;
        }

        @unlink($raw);

        $this->info("DB backup created: {$gz}");
        $this->rotate($dir, $keep);

        return CommandAlias::SUCCESS;
    }

    private function resolveDir(string $dir): ?string
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create backup directory: {$dir}");

            return null;
        }

        return $dir;
    }

    private function isNonTrivial(string $file): bool
    {
        if (! is_file($file)) {
            return false;
        }

        $size = filesize($file);

        return $size !== false && $size >= self::MIN_BACKUP_BYTES;
    }

    private function isNonEmpty(string $file): bool
    {
        return is_file($file) && filesize($file) > 0;
    }

    /** Stream-gzip $source into $dest (no full-file buffering). */
    private function gzipFile(string $source, string $dest): bool
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            return false;
        }

        $out = @gzopen($dest, 'wb9');
        if ($out === false) {
            fclose($in);

            return false;
        }

        while (! feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            gzwrite($out, $chunk);
        }

        fclose($in);
        gzclose($out);

        return true;
    }

    private function str(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Keep only the N newest pre-migrate snapshots (both engines).
     */
    private function rotate(string $dir, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $existing = array_merge(
            glob("{$dir}/pre-migrate-*.sqlite") ?: [],
            glob("{$dir}/pre-migrate-*.sql.gz") ?: [],
        );
        sort($existing); // oldest first (numeric filenames sort chronologically)

        $delete = array_slice($existing, 0, max(0, count($existing) - $keep));
        foreach ($delete as $old) {
            @unlink($old);
        }
    }
}
