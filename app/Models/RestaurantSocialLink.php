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
    ];

    protected $casts = [
        'followers' => 'integer',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
