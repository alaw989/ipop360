<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track the last time a restaurant's photo state was HTTP-verified.
 *
 * Powers the ~28-day photo-verify cadence: a freshly-verified row (alive OR
 * confirmed-dead-unresolvable) is stamped, and both the daily backfill and the
 * weekly verify sweep skip rows whose stamp is within the cooldown window so a
 * dead photo isn't re-checked (or re-sourced) every single run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->timestamp('photo_verified_at')->nullable()->after('photo_url');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('photo_verified_at');
        });
    }
};
