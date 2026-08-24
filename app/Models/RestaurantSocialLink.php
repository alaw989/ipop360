<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantSocialLink extends Model
{
    protected $fillable = [
        'restaurant_id',
        'platform',
        'url',
        'followers',
        'verified_at',
        'last_check_failed_at',
    ];

    protected $casts = [
        'followers' => 'integer',
        'verified_at' => 'datetime',
        'last_check_failed_at' => 'datetime',
    ];

    /**
     * A link counts toward social_links_count only once its reachability has
     * been confirmed (spec-109) — presence/absence of verified_at is the sole
     * signal (no separate boolean that could drift from it).
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
