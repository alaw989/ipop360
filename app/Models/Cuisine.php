<?php

namespace App\Models;

use Database\Factories\CuisineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Cuisine extends Model
{
    /** @use HasFactory<CuisineFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'icon',
        'sort_order',
    ];

    /**
     * @return BelongsTo<CuisineCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CuisineCategory::class, 'category_id');
    }

    /**
     * @return BelongsToMany<Restaurant, $this>
     */
    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'cuisine_restaurant');
    }
}
