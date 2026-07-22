<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->integer('pageviews_count')->default(0)->after('total_engagement');
            $table->integer('social_link_clicks_count')->default(0)->after('pageviews_count');
            $table->integer('menu_click_count')->default(0)->after('social_link_clicks_count');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['pageviews_count', 'social_link_clicks_count', 'menu_click_count']);
        });
    }
};
