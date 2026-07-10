<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->json('features')->nullable()->after('ai_metadata');
            $table->integer('website_clicks_count')->default(0)->after('features');
            $table->integer('directions_clicks_count')->default(0)->after('website_clicks_count');
            $table->integer('call_clicks_count')->default(0)->after('directions_clicks_count');
            $table->integer('total_engagement')->default(0)->after('call_clicks_count');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['features', 'website_clicks_count', 'directions_clicks_count', 'call_clicks_count', 'total_engagement']);
        });
    }
};
