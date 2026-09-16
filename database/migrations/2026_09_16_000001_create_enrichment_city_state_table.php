<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-city rotation state for throttled enrichment.
 *
 * The throttled grid caps at combos_per_run (60) of a ~1,485-combo grid, so
 * ordering by unrated count alone let the same big metros consume every night
 * and starved the rest. Stamping each swept city here lets the grid order by
 * last_processed_at first (need as tiebreak), guaranteeing every configured
 * city is visited on a bounded cycle. A table (not the cache) because the
 * deploy runs `artisan cache:clear` on every push.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_city_state', function (Blueprint $table) {
            $table->string('city')->primary();
            $table->timestamp('last_processed_at')->nullable();
            $table->unsignedInteger('runs')->default(0);
            $table->unsignedInteger('combos_total')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_city_state');
    }
};
