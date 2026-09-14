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
 * spec-087/spec-103: the rollback restore command (pairs with `db:backup`).
 * Restores a VACUUM INTO snapshot over the live SQLite file (the data side of
 * the opt-in `DEPLOY_AUTO_ROLLBACK` gate) and, on MySQL, pipes the newest
 * gzipped dump into the mysql client. Verifies a "bad migration" (a row
 * inserted after the snapshot) is undone, the --force guard is enforced, and
 * the in-memory / no-snapshot paths degrade gracefully.
 */
class RestoreDatabaseCommandTest extends TestCase
{
    private string $fileDb;

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

    private function makeFileDb(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ip360_');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO t VALUES (1), (2), (3)');

        return $path;
    }

    public function test_restores_the_newest_snapshot_over_a_mutated_db(): void
    {
        $this->fileDb = $this->makeFileDb();
        Config::set('database.connections.sqlite.database', $this->fileDb);
        $dir = sys_get_temp_dir().'/ip360-restore-'.uniqid();

        // Snapshot the clean 3-row state.
        /** @var PendingCommand $command */
        $command = $this->artisan('db:backup', ['--path' => $dir, '--keep' => 5]);
        $command->assertSuccessful();
        $command->run();
        $backups = glob($dir.'/pre-migrate-*.sqlite') ?: [];
        $this->assertCount(1, $backups, 'snapshot created');

        // "Bad migration": mutate the live DB after the snapshot.
        $live = new PDO('sqlite:'.$this->fileDb);
        $live->exec('INSERT INTO t VALUES (99)');
        $live = null; // release the handle before the restore copies over the file

        /** @var PendingCommand $command */
        $command = $this->artisan('db:restore', ['--backup-dir' => $dir, '--force' => true]);
        $command->assertSuccessful();
        $command->run();

        $restored = new PDO('sqlite:'.$this->fileDb);
        /** @var PDOStatement $stmt */
        $stmt = $restored->query('SELECT COUNT(*) FROM t');
        $count = (int) $stmt->fetchColumn();
        $this->assertSame(3, $count, 'post-snapshot mutation (row 99) is gone after restore');

        @unlink($this->fileDb);
        array_map('unlink', $backups);
    }

    public function test_refuses_to_restore_without_force(): void
    {
        $this->fileDb = $this->makeFileDb();
        Config::set('database.connections.sqlite.database', $this->fileDb);
        $dir = sys_get_temp_dir().'/ip360-noforce-'.uniqid();

        /** @var PendingCommand $command */
        $command = $this->artisan('db:backup', ['--path' => $dir]);
        $command->assertSuccessful();
        $command->run();
        /** @var PendingCommand $command */
        $command = $this->artisan('db:restore', ['--backup-dir' => $dir]);
        $command->assertFailed();
        $command->run();

        // The live DB is untouched.
        $live = new PDO('sqlite:'.$this->fileDb);
        /** @var PDOStatement $stmt */
        $stmt = $live->query('SELECT COUNT(*) FROM t');
        $this->assertSame(3, (int) $stmt->fetchColumn());

        @unlink($this->fileDb);
        array_map('unlink', glob($dir.'/pre-migrate-*.sqlite') ?: []);
    }

    public function test_skips_gracefully_for_in_memory_db(): void
    {
        Config::set('database.connections.sqlite.database', ':memory:');
        $dir = sys_get_temp_dir().'/ip360-restore-mem-'.uniqid();

        /** @var PendingCommand $command */
        $command = $this->artisan('db:restore', ['--backup-dir' => $dir, '--force' => true]);
        $command->assertSuccessful();
        $command->run();
    }

    public function test_fails_when_no_snapshot_exists(): void
    {
        $this->fileDb = $this->makeFileDb();
        Config::set('database.connections.sqlite.database', $this->fileDb);
        $dir = sys_get_temp_dir().'/ip360-empty-'.uniqid();

        /** @var PendingCommand $command */
        $command = $this->artisan('db:restore', ['--backup-dir' => $dir, '--force' => true]);
        $command->assertFailed();
        $command->run();

        @unlink($this->fileDb);
    }

    public function test_mysql_restore_pipes_the_newest_gzip_into_mysql(): void
    {
        $this->useMysql();
        $dir = sys_get_temp_dir().'/ip360-mysql-restore-'.uniqid();
        @mkdir($dir, 0775, true);
        file_put_contents($dir.'/pre-migrate-100.sql.gz', gzencode('CREATE TABLE t (id INT);'));
        // An older sibling must not be chosen.
        file_put_contents($dir.'/pre-migrate-200.sql.gz', gzencode('SELECT 1;'));

        $captured = null;
        Process::fake(function (PendingProcess $process) use (&$captured): int {
            $captured = $process->command;

            return 0;
        });

        /** @var PendingCommand $command */
        $command = $this->artisan('db:restore', ['--backup-dir' => $dir, '--force' => true]);
        $command->assertSuccessful();
        $command->run();

        $flat = is_array($captured) ? implode(' ', array_map('strval', $captured)) : (string) $captured;
        $this->assertStringContainsString('pre-migrate-200.sql.gz', $flat, 'newest dump is restored');
        $this->assertStringContainsString('gunzip', $flat);

        array_map('unlink', glob($dir.'/pre-migrate-*') ?: []);
        @rmdir($dir);
    }

    public function test_mysql_restore_fails_loudly_when_mysql_errors(): void
    {
        $this->useMysql();
        $dir = sys_get_temp_dir().'/ip360-mysql-restore-fail-'.uniqid();
        @mkdir($dir, 0775, true);
        file_put_contents($dir.'/pre-migrate-100.sql.gz', gzencode('SELECT 1;'));

        Process::fake(fn (): int => 1);

        /** @var PendingCommand $command */
        $command = $this->artisan('db:restore', ['--backup-dir' => $dir, '--force' => true]);
        $command->assertFailed();
        $command->run();

        array_map('unlink', glob($dir.'/pre-migrate-*') ?: []);
        @rmdir($dir);
    }
}
