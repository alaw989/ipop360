<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_social_links', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('followers');
            $table->timestamp('last_check_failed_at')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_social_links', function (Blueprint $table) {
            $table->dropColumn(['verified_at', 'last_check_failed_at']);
        });
    }
};
