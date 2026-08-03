<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spec-104: MySQL enforces column length/JSON validity; SQLite does not.
 * Live data exceeds the original limits:
 *  - restaurants.photo_url          max 439 chars  (varchar 255)
 *  - external_api_cache.data        max ~105KB     (TEXT 64KB)
 * And restaurants.score_breakdown was `json()` in MySQL but 447 live rows
 * hold non-JSON strings (SQLite never validated), which MySQL rejects at
 * insert time. It becomes `text()` — the Eloquent `array` cast already
 * encodes/decodes, so round-trips exactly (same fix as the earlier
 * external_api_cache.data jsonb->text).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('photo_url', 500)->nullable()->change();
        });

        // JSON column -> text: MySQL validates JSON at insert; 447 live rows
        // hold non-JSON strings. Eloquent cast handles encode/decode.
        Schema::table('restaurants', function (Blueprint $table) {
            $table->text('score_breakdown')->nullable()->change();
        });

        Schema::table('external_api_cache', function (Blueprint $table) {
            $table->mediumText('data')->change();
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('photo_url', 255)->nullable()->change();
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->json('score_breakdown')->nullable()->change();
        });

        Schema::table('external_api_cache', function (Blueprint $table) {
            $table->text('data')->change();
        });
    }
};
