<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * spec-104: copy the live SQLite data into the provisioned MySQL 8 database,
 * preserving row ids so foreign keys (favorites, engagement, social links,
 * cuisine_restaurant) stay intact. Streams row-by-row via PDO — the 853MB
 * SQLite file never fits in memory, and no intermediate dump file is written.
 *
 * The MySQL schema is expected to already exist (php artisan migrate against
 * the mysql connection). The `migrations` table is skipped because it is
 * populated by that migrate; all other user tables are copied verbatim.
 *
 * Usage: php artisan db:copy-sqlite-to-mysql [--source=sqlite] [--target=mysql]
 */
class CopySqliteToMysql extends Command
{
    protected $signature = 'db:copy-sqlite-to-mysql {--source=sqlite} {--target=mysql} {--source-path=}';

    protected $description = 'Copy core data from the SQLite database into the MySQL database, preserving ids';

    /**
     * Core app data copied verbatim. Transient tables (cache, cache_locks,
     * sessions, jobs, job_batches, failed_jobs, pulse_*) repopulate naturally
     * and some carry binary/invalid-utf8 payloads that MySQL rejects, so they
     * are intentionally NOT copied. The `migrations` table is populated by
     * `php artisan migrate` against the mysql connection.
     *
     * @var string[]
     */
    private const TABLES_TO_COPY = [
        'cuisine_categories',
        'cuisines',
        'restaurants',
        'cuisine_restaurant',
        'users',
        'restaurant_social_links',
        'restaurant_engagement',
        'favorite_restaurant_user',
        'external_api_cache',
    ];

    public function handle(): int
    {
        $sourceName = $this->option('source');
        $targetName = $this->option('target');

        // The sqlite connection reads DB_DATABASE too, so when the target is
        // selected via DB_DATABASE (mysql) the source path must be decoupled.
        // --source-path defaults to database/database.sqlite (config default).
        if ($sourcePath = $this->option('source-path')) {
            config(["database.connections.{$sourceName}.database" => $sourcePath]);
        }

        $source = DB::connection($sourceName);
        $target = DB::connection($targetName);

        $this->info("Source: {$sourceName} ({$source->getDatabaseName()})");
        $this->info("Target: {$targetName} ({$target->getDatabaseName()})");

        $tables = self::TABLES_TO_COPY;
        $this->info('Tables to copy: '.count($tables).' ('.implode(', ', $tables).')');

        // Disable FK checks during the load (foreign keys enforced after).
        $target->statement('SET FOREIGN_KEY_CHECKS = 0');

        $summary = [];
        $failed = 0;

        foreach ($tables as $table) {
            $rows = $this->copyTable($source, $target, $table);
            $summary[$table] = $rows;
            $this->line("  {$table}: {$rows} rows");
            if ($rows < 0) {
                $failed++;
            }
        }

        $target->statement('SET FOREIGN_KEY_CHECKS = 1');

        $this->newLine();
        $this->info('Copy complete:');
        foreach ($summary as $table => $rows) {
            $this->line("  {$table}: {$rows} rows");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Copy every row of one table. Returns row count, or -1 on failure.
     */
    private function copyTable($source, $target, string $table): int
    {
        $this->line("  Copying {$table}...");

        // Column list from the TARGET (MySQL) so the insert matches exactly;
        // both schemas derive from the same migrations so they align.
        $columns = $this->targetColumns($target, $table);

        if (empty($columns)) {
            $this->error("  {$table}: no columns found on target, skipping");

            return -1;
        }

        // Idempotent: wipe prior rows (FK checks are off for this run) so a
        // re-run after a partial failure starts clean instead of duplicating.
        $target->statement('TRUNCATE TABLE `'.$table.'`');

        $columnList = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $target->beginTransaction();

        try {
            $insert = $target->getPdo()->prepare(
                "INSERT INTO `{$table}` ({$columnList}) VALUES ({$placeholders})"
            );

            // Stream from the source so a 90k+ row table (failed_jobs) with
            // large payloads is never fully materialised in memory.
            $cursor = $source->table($table)->select($columns)->cursor();

            $rows = 0;
            foreach ($cursor as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $values[] = $row->{$column};
                }
                $insert->execute($values);
                $rows++;
            }

            $target->commit();

            return $rows;
        } catch (\Throwable $e) {
            $target->rollBack();
            $this->error("  {$table}: failed — {$e->getMessage()}");

            return -1;
        }
    }

    /**
     * @return string[]
     */
    private function targetColumns($target, string $table): array
    {
        // Alias COLUMN_NAME -> column_name: information_schema returns the
        // physical (uppercase) name and pluck('column_name') would otherwise
        // look for a lowercase property that doesn't exist.
        return $target
            ->table('information_schema.columns')
            ->selectRaw('COLUMN_NAME AS column_name')
            ->where('table_schema', $target->getDatabaseName())
            ->where('table_name', $table)
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->all();
    }
}
