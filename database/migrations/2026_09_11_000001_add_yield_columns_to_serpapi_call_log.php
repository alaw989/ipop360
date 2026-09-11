<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-call yield for SerpApi, the only rating source and the binding quota
 * (~250/mo). Each call-log row now records where the call came from, what it
 * asked for, and what it produced, so ratings-per-call can be measured instead
 * of guessed:
 * - context: enrichment | live | search
 * - status: ok | failed
 * - results / rated_results: local results returned, and how many carry a rating
 * - matched / newly_rated / created_rows: enrichment only, filled after the results
 *   are persisted (rated results that matched an existing restaurant, matched
 *   rows that had no rating before, and new restaurants created with one)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serpapi_call_log', function (Blueprint $table) {
            $table->string('context', 16)->nullable()->after('created_at');
            $table->string('status', 16)->nullable()->after('context');
            $table->string('query', 120)->nullable()->after('status');
            $table->decimal('lat', 9, 6)->nullable()->after('query');
            $table->decimal('lng', 9, 6)->nullable()->after('lat');
            $table->unsignedSmallInteger('results')->nullable()->after('lng');
            $table->unsignedSmallInteger('rated_results')->nullable()->after('results');
            $table->unsignedSmallInteger('matched')->nullable()->after('rated_results');
            $table->unsignedSmallInteger('newly_rated')->nullable()->after('matched');
            $table->unsignedSmallInteger('created_rows')->nullable()->after('newly_rated');
        });
    }

    public function down(): void
    {
        Schema::table('serpapi_call_log', function (Blueprint $table) {
            $table->dropColumn(['context', 'status', 'query', 'lat', 'lng', 'results', 'rated_results', 'matched', 'newly_rated', 'created_rows']);
        });
    }
};
