<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track the last time a restaurant's website_url was HTTP-verified.
 *
 * restaurants:verify-websites previously had no way to tell "never checked"
 * from "checked last week" — it just took the first N rows by id every run,
 * so anything past row ~200 never got dead-link-checked. This stamp lets the
 * command order by staleness (never-checked first) and respect --max-age-days
 * instead of silently starving the tail of the corpus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->timestamp('website_verified_at')->nullable()->after('website_url');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('website_verified_at');
        });
    }
};
