<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The home page's featured restaurant, picked by an admin, optionally with a
 * blog story about it and a credited photo. A pick runs from starts_at until ends_at (open-ended
 * when null); picking a new one ends the current one, so the rows are the
 * history of what was featured. With no current pick, the home page features
 * the top-ranked restaurant near the visitor instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('featured_restaurants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blog_post_id')->nullable()->constrained()->nullOnDelete();
            // An optional photo chosen for the spotlight, and the credit its
            // license asks for ("Photo: …, CC BY-SA 4.0, via Wikimedia Commons").
            $table->string('image_url', 2048)->nullable();
            $table->string('image_credit', 255)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('featured_restaurants');
    }
};
