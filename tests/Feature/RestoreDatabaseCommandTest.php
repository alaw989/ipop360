<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use PDO;
use Tests\TestCase;

/**
 * spec-087: the rollback restore command (pairs with spec-077's `db:backup`).
 * Restores a VACUUM INTO snapshot over the live SQLite file — the data side of
 * the opt-in `DEPLOY_AUTO_ROLLBACK` gate. Verifies a "bad migration" (a row
 * inserted after the snapshot) is undone, the --force guard is enforced, and the
 * in-memory / no-snapshot paths degrade gracefully.
 */
class RestoreDatabaseCommandTest extends TestCase
{
    private string $fileDb;

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
        $this->artisan('db:backup', ['--path' => $dir, '--keep' => 5])
            ->assertSuccessful();
        $backups = glob($dir.'/pre-migrate-*.sqlite');
        $this->assertCount(1, $backups, 'snapshot created');

        // "Bad migration": mutate the live DB after the snapshot.
        $live = new PDO('sqlite:'.$this->fileDb);
        $live->exec('INSERT INTO t VALUES (99)');
        $live = null; // release the handle before the restore copies over the file

        $this->artisan('db:restore', ['--backup-dir' => $dir, '--force' => true])
            ->assertSuccessful();

        $restored = new PDO('sqlite:'.$this->fileDb);
        $count = (int) $restored->query('SELECT COUNT(*) FROM t')->fetchColumn();
        $this->assertSame(3, $count, 'post-snapshot mutation (row 99) is gone after restore');

        @unlink($this->fileDb);
        array_map('unlink', $backups);
    }

    public function test_refuses_to_restore_without_force(): void
    {
        $this->fileDb = $this->makeFileDb();
        Config::set('database.connections.sqlite.database', $this->fileDb);
        $dir = sys_get_temp_dir().'/ip360-noforce-'.uniqid();

        $this->artisan('db:backup', ['--path' => $dir])->assertSuccessful();
        $this->artisan('db:restore', ['--backup-dir' => $dir])
            ->assertFailed();

        // The live DB is untouched.
        $live = new PDO('sqlite:'.$this->fileDb);
        $this->assertSame(3, (int) $live->query('SELECT COUNT(*) FROM t')->fetchColumn());

        @unlink($this->fileDb);
        array_map('unlink', glob($dir.'/pre-migrate-*.sqlite') ?: []);
    }

    public function test_skips_gracefully_for_in_memory_db(): void
    {
        Config::set('database.connections.sqlite.database', ':memory:');
        $dir = sys_get_temp_dir().'/ip360-restore-mem-'.uniqid();

        $this->artisan('db:restore', ['--backup-dir' => $dir, '--force' => true])
            ->assertSuccessful();
    }

    public function test_fails_when_no_snapshot_exists(): void
    {
        $this->fileDb = $this->makeFileDb();
        Config::set('database.connections.sqlite.database', $this->fileDb);
        $dir = sys_get_temp_dir().'/ip360-empty-'.uniqid();

        $this->artisan('db:restore', ['--backup-dir' => $dir, '--force' => true])
            ->assertFailed();

        @unlink($this->fileDb);
    }
}
