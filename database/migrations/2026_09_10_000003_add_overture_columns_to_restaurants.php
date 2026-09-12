<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overture Maps corroboration (data-integrity phase 3).
 *
 * - overture_id: the matched Overture place (GERS id), null when no place
 *   within the match radius corroborates this restaurant.
 * - overture_confidence: Overture's 0–1 existence confidence for that place.
 * - overture_sources: how many independent datasets (Meta, Microsoft,
 *   Foursquare, AllThePlaces…) Overture merged for it — a corroboration count.
 * - overture_status: operating_status (open / temporarily_closed /
 *   permanently_closed).
 * - overture_checked_at: last import that looked for this restaurant.
 * - field_sources: per-field provenance for values filled by an import,
 *   e.g. {"phone": "overture:2026-08-19.0"}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('overture_id', 64)->nullable()->after('google_place_id');
            $table->decimal('overture_confidence', 4, 3)->nullable()->after('overture_id');
            $table->unsignedTinyInteger('overture_sources')->default(0)->after('overture_confidence');
            $table->string('overture_status', 24)->nullable()->after('overture_sources');
            $table->timestamp('overture_checked_at')->nullable()->after('overture_status');
            $table->json('field_sources')->nullable()->after('ai_metadata');
            $table->index('overture_id');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropIndex(['overture_id']);
            $table->dropColumn(['overture_id', 'overture_confidence', 'overture_sources', 'overture_status', 'overture_checked_at', 'field_sources']);
        });
    }
};
