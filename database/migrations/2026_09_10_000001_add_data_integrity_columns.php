<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data-integrity provenance columns.
 *
 * - restaurants.website_identity: outcome of WebsiteIdentityVerifier for the
 *   stored website_url — 'verified' (the page names this restaurant AND shows
 *   its phone/street/city), 'brand' (a chain/brand homepage that names it but
 *   not this location), 'rejected' (reference/parked/unrelated page). NULL =
 *   not yet identity-checked. website_verified_at stays the "last checked"
 *   timestamp.
 * - restaurant_social_links.scope: 'location' (this venue's own profile) or
 *   'brand' (a corporate account shared across many locations). Only verified
 *   location-scoped links count toward social_links_count. The url index backs
 *   the shared-URL (brand) detection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('website_identity', 16)->nullable()->after('website_verified_at');
        });

        Schema::table('restaurant_social_links', function (Blueprint $table) {
            $table->string('scope', 16)->default('location')->after('url');
            $table->index('url');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_social_links', function (Blueprint $table) {
            $table->dropIndex(['url']);
            $table->dropColumn('scope');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('website_identity');
        });
    }
};
