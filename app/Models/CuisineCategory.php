<?php

namespace App\Models;

use Database\Factories\CuisineCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CuisineCategory extends Model
{
    /** @use HasFactory<CuisineCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'sort_order',
    ];

    /**
     * @return HasMany<Cuisine, $this>
     */
    public function cuisines(): HasMany
    {
        return $this->hasMany(Cuisine::class, 'category_id')->orderBy('sort_order');
    }
}
