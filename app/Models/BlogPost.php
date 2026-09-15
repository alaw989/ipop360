<?php

namespace App\Models;

use Database\Factories\BlogPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property-read User $author
 *
 * @method static Builder|BlogPost published()
 * @method static Builder|BlogPost featured()
 */
class BlogPost extends Model
{
    /** @use HasFactory<BlogPostFactory> */
    use HasFactory;

    protected $fillable = [
        'author_id',
        'title',
        'slug',
        'excerpt',
        'category',
        'is_featured',
        'body',
        'featured_image',
        'status',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'is_featured' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (BlogPost $post) {
            if (empty($post->slug)) {
                $post->slug = self::uniqueSlug($post->title);
            }
        });

        static::updating(function (BlogPost $post) {
            // Keep a never-published (draft) post's slug in sync with its title,
            // but never change a published post's URL: it may already be linked,
            // indexed, or shared, so link stability wins over title accuracy.
            // A draft has no public URL (BlogController::show aborts unless
            // published), so re-slugging one is safe.
            if ($post->isDirty('title') && $post->published_at === null) {
                $post->slug = self::uniqueSlug($post->title, $post->getKey());
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function publish(): void
    {
        // Publishing is always "now" — there is no scheduling UI, so a draft
        // that is (re)published gets a fresh date rather than a stale one left
        // over from a previous publish. Editing an already-published post does
        // not call this, so its original date is preserved.
        $this->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    private static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'post';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
