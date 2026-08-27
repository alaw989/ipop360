<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_engagement', function (Blueprint $table) {
            $table->index(['restaurant_id', 'created_at'], 'restaurant_engagement_restaurant_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_engagement', function (Blueprint $table) {
            $table->dropIndex('restaurant_engagement_restaurant_id_created_at_index');
        });
    }
};
