<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row cursor for restaurants:seed-places.
 *
 * The Census catalog is ~32k place points; a run processes a bounded slice and
 * records the last "name|state" here so the next run resumes. A table (not the
 * cache) because the deploy runs `artisan cache:clear` on every push.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_place_cursor', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('cursor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_place_cursor');
    }
};
