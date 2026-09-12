<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * restaurants.website_scraped_at: when the backfill-websites scrape phase last
 * read the restaurant's own website for its menu link, hours and description,
 * whether or not it found anything. The phase used to take the same top 200
 * rows by popularity every day, because a site with no hours on it stays
 * "missing hours" forever, so it never reached the other ~36,000 websites. It
 * now goes to never-scraped sites first and comes back to a site after 30 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->timestamp('website_scraped_at')->nullable()->after('website_identity');
            $table->index('website_scraped_at');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropIndex(['website_scraped_at']);
            $table->dropColumn('website_scraped_at');
        });
    }
};
