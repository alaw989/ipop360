<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            // Every public listing filters on status + published_at and sorts by
            // published_at (BlogPost::scopePublished, BlogController, HomeService).
            // The admin index also filters on status.
            $table->index(['status', 'published_at']);
            // The category filter and category facet both group/filter on this.
            $table->index('category');
            // HomeService orders the homepage blog teaser by is_featured desc.
            $table->index('is_featured');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'published_at']);
            $table->dropIndex(['category']);
            $table->dropIndex(['is_featured']);
        });
    }
};
