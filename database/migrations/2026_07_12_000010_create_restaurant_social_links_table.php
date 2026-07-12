<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_social_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 50);
            $table->string('url', 500);
            $table->unsignedInteger('followers')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_social_links');
    }
};
