<?php

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;
use PDO;
use PDOStatement;
use Tests\TestCase;

/**
 * spec-077/spec-103: the pre-migration DB backup command. `VACUUM INTO`
 * snapshots a file-based SQLite DB (live-consistent); the MySQL connection is
 * dumped with `mysqldump --single-transaction` and gzipped. Losing
 * external_api_cache is a multi-month rebuild gated by the SerpApi quota, so a
 * failed/empty backup must fail loudly (the deploy gates `migrate` on it).
 */
class BackupDatabaseCommandTest extends TestCase
{
    private string $fileDb;

    /** Point the app at a fake MySQL connection. */
    private function useMysql(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'ipop360',
            'username' => 'ipop360',
            'password' => 'secret',
        ]);
    }

    /**
     * Fake mysqldump: write $bytes to the --result-file target and exit 0.
     */
    private function fakeMysqlDump(int $bytes = 4096): void
    {
        Process::fake(function (PendingProcess $process) use ($bytes): int {
            $command = $process->command;
            if (is_array($command)) {
                foreach ($command as $arg) {
                    if (str_starts_with($arg, '--result-file=')) {
                        file_put_contents(substr($arg, strlen('--result-file=')), str_repeat('INSERT INTO t VALUES (1);'.PHP_EOL, max(1, intdiv($bytes, 29))));
                    }
                }
            }

            return 0;
        });
    }

    private function makeFileDb(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ip360_');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO t VALUES (1), (2), (3)');

        return $path;
    }

    public function test_creates_valid_snapshot_for_file_based_sqlite(): void
    {
        $this->fileDb = $this->makeFileDb();
        Config::set('database.connections.sqlite.database', $this->fileDb);
        $dir = sys_get_temp_dir().'/ip360-backup-'.uniqid();

        /** @var PendingCommand $command */
        $command = $this->artisan('db:backup', ['--path' => $dir, '--keep' => 5]);
        $command->assertSuccessful();
        $command->run();

        $backups = glob($dir.'/pre-migrate-*.sqlite') ?: [];
        $this->assertCount(1, $backups, 'one snapshot created');

        $snapshot = new PDO('sqlite:'.$backups[0]);
        /** @var PDOStatement $stmt */
        $stmt = $snapshot->query('SELECT COUNT(*) FROM t');
        $this->assertSame(3, (int) $stmt->fetchColumn());

        @unlink($this->fileDb);
        array_map('unlink', $backups);
    }

    public function test_skips_gracefully_for_in_memory_db(): void
    {
        Config::set('database.connections.sqlite.database', ':memory:');
        $dir = sys_get_temp_dir().'/ip360-skip-'.uniqid();

        /** @var PendingCommand $command */
        $command = $this->artisan('db:backup', ['--path' => $dir]);
        $command->assertSuccessful();
        $command->run();

        $this->assertFileDoesNotExist($dir, 'no backup created for an in-memory DB');
    }

    public function test_rotates_keeping_only_n_newest(): void
    {
        $this->fileDb = $this->makeFileDb();
        Config::set('database.connections.sqlite.database', $this->fileDb);
        $dir = sys_get_temp_dir().'/ip360-rot-'.uniqid();
        @mkdir($dir, 0775, true);

        // Four pre-existing snapshots with ascending PAST timestamps.
        for ($i = 0; $i < 4; $i++) {
            file_put_contents("{$dir}/pre-migrate-".(time() - (100 - $i)).'.sqlite', 'old');
        }

        /** @var PendingCommand $command */
        $command = $this->artisan('db:backup', ['--path' => $dir, '--keep' => 2]);
        $command->assertSuccessful();
        $command->run();

        $remaining = glob($dir.'/pre-migrate-*.sqlite') ?: [];
        $this->assertCount(2, $remaining, 'only the 2 newest snapshots are retained');

        @unlink($this->fileDb);
        array_map('unlink', $remaining);
    }

    public function test_mysql_backup_runs_mysqldump_and_writes_a_gzipped_dump(): void
    {
        $this->useMysql();
        $this->fakeMysqlDump();
        $dir = sys_get_temp_dir().'/ip360-mysql-'.uniqid();

        /** @var PendingCommand $command */
        $command = $this->artisan('db:backup', ['--path' => $dir, '--keep' => 5]);
        $command->assertSuccessful();
        $command->run();

        $files = glob($dir.'/pre-migrate-*.sql.gz') ?: [];
        $this->assertCount(1, $files, 'one gzipped MySQL dump created');
        $this->assertGreaterThan(0, (int) filesize($files[0]), 'dump must be non-empty');

        $decoded = gzdecode((string) file_get_contents($files[0]));
        $this->assertIsString($decoded);
        $this->assertStringContainsString('INSERT INTO t', $decoded);

        // The uncompressed intermediate is removed.
        $this->assertSame([], glob($dir.'/pre-migrate-*.sql') ?: []);

        array_map('unlink', $files);
        @rmdir($dir);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && in_array('--single-transaction', $process->command, true)
            && in_array('--set-gtid-purged=OFF', $process->command, true));
    }

    public function test_mysql_backup_fails_when_mysqldump_fails(): void
    {
        $this->useMysql();
        Process::fake(fn (): int => 1);
        $dir = sys_get_temp_dir().'/ip360-mysql-fail-'.uniqid();

        /** @var PendingCommand $command */
        $command = $this->artisan('db:backup', ['--path' => $dir]);
        $command->assertFailed();
        $command->run();

        $this->assertSame([], glob($dir.'/pre-migrate-*') ?: [], 'no partial backup left behind');
        @rmdir($dir);
    }

    public function test_mysql_backup_fails_when_the_dump_is_empty(): void
    {
        $this->useMysql();
        // Exit 0 but produce nothing — a truncated/empty dump must not count as
        // a safety net.
        Process::fake(function (PendingProcess $process): int {
            $command = $process->command;
            if (is_array($command)) {
                foreach ($command as $arg) {
                    if (str_starts_with($arg, '--result-file=')) {
                        file_put_contents(substr($arg, strlen('--result-file=')), '');
                    }
                }
            }

            return 0;
        });
        $dir = sys_get_temp_dir().'/ip360-mysql-empty-'.uniqid();

        /** @var PendingCommand $command */
        $command = $this->artisan('db:backup', ['--path' => $dir]);
        $command->assertFailed();
        $command->run();

        $this->assertSame([], glob($dir.'/pre-migrate-*') ?: []);
        @rmdir($dir);
    }

    public function test_mysql_rotation_keeps_only_n_newest_gz(): void
    {
        $this->useMysql();
        $this->fakeMysqlDump();
        $dir = sys_get_temp_dir().'/ip360-mysql-rot-'.uniqid();
        @mkdir($dir, 0775, true);

        for ($i = 0; $i < 4; $i++) {
            file_put_contents("{$dir}/pre-migrate-".(time() - (100 - $i)).'.sql.gz', 'old');
        }

        /** @var PendingCommand $command */
        $command = $this->artisan('db:backup', ['--path' => $dir, '--keep' => 2]);
        $command->assertSuccessful();
        $command->run();

        $this->assertCount(2, glob($dir.'/pre-migrate-*.sql.gz') ?: []);
        array_map('unlink', glob($dir.'/pre-migrate-*') ?: []);
        @rmdir($dir);
    }
}
