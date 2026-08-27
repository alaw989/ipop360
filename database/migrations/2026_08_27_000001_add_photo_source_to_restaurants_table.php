<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track which pipeline sourced a restaurant's photo_url, so Trending
 * eligibility and future scoring can distinguish venue-anchored sources
 * (website, social profile, OSM tag, Wikidata, SerpApi's own per-place
 * thumbnail) from keyword-search guesses (Wikimedia/Wikipedia/Google CSE)
 * that are only textually, not visually, verified against the venue.
 * See App\Support\PhotoSourceTier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('photo_source')->nullable()->after('photo_url');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('photo_source');
        });
    }
};
