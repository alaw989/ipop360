<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An admin's pick for the home page spotlight, optionally with a blog story.
 *
 * @property int $id
 * @property int $restaurant_id
 * @property int|null $blog_post_id
 * @property string|null $image_url
 * @property string|null $image_credit
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property-read Restaurant $restaurant
 * @property-read BlogPost|null $blogPost
 *
 * @method static Builder<FeaturedRestaurant> current()
 */
class FeaturedRestaurant extends Model
{
    protected $fillable = [
        'restaurant_id',
        'blog_post_id',
        'image_url',
        'image_credit',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsTo<BlogPost, $this> */
    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class);
    }

    /** End every pick running now (a new pick, or "stop featuring"). */
    public static function endCurrent(): void
    {
        static::query()
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->update(['ends_at' => now()]);
    }

    /**
     * Picks running now, newest first.
     *
     * @param  Builder<FeaturedRestaurant>  $query
     * @return Builder<FeaturedRestaurant>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderByDesc('starts_at')
            ->orderByDesc('id');
    }
}
