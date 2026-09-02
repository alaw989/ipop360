<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuisines', function (Blueprint $table) {
            $table->index('slug');
        });

        Schema::table('external_api_cache', function (Blueprint $table) {
            $table->index('expires_at');
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('cuisines', function (Blueprint $table) {
            $table->dropIndex(['slug']);
        });

        Schema::table('external_api_cache', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropIndex(['fetched_at']);
        });
    }
};
