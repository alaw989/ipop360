<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * spec-104: the pivot FKs were originally declared inline in
 * create_cuisine_restaurant_table (2026_06_06_171950), which shares a
 * timestamp with create_restaurants_table / create_cuisines_table and runs
 * BEFORE them alphabetically. SQLite tolerates the forward reference
 * (lazy FK validation); MySQL rejects it at DDL time. Fresh DBs therefore
 * failed to migrate on MySQL.
 *
 * Fix: the create migration no longer declares the FKs; they are added here,
 * after all referenced tables exist. Guarded so pre-existing DBs (where the
 * original inline FKs are already present) are no-ops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuisine_restaurant', function (Blueprint $table) {
            if (! $this->hasForeignKey('restaurant_id')) {
                $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
            }
            if (! $this->hasForeignKey('cuisine_id')) {
                $table->foreign('cuisine_id')->references('id')->on('cuisines')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('cuisine_restaurant', function (Blueprint $table) {
            $table->dropForeign(['restaurant_id']);
            $table->dropForeign(['cuisine_id']);
        });
    }

    private function hasForeignKey(string $column): bool
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'cuisine_restaurant')
                ->where('COLUMN_NAME', $column)
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->exists();
        }

        $fks = DB::select("PRAGMA foreign_key_list('cuisine_restaurant')");

        return collect($fks)->contains(fn (object $fk) => $fk->from === $column);
    }
};
