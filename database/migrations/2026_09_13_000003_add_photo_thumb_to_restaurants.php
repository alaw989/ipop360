<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generated card-sized thumbnail for a restaurant's photo.
 *
 * Holds just the file name ({id}-{sha1(photo_url):10}.webp), not a URL: the
 * embedded hash lets the API serve a thumbnail only while it still matches the
 * row's current photo_url, so a replaced photo can never serve a stale image.
 * Null = no thumbnail generated yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('photo_thumb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('photo_thumb');
        });
    }
};
