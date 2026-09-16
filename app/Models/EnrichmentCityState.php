<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Rotation state for one configured enrichment city.
 *
 * Written by RestaurantEnrichmentService::enrichAllCitiesThrottled() after a
 * city is swept, and read by buildCityCuisineGrid() to order the grid by
 * staleness (least-recently-swept first). Keyed by the config grid key.
 *
 * @property string $city
 * @property Carbon|null $last_processed_at
 * @property int $runs
 * @property int $combos_total
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class EnrichmentCityState extends Model
{
    protected $table = 'enrichment_city_state';

    protected $primaryKey = 'city';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'city',
        'last_processed_at',
        'runs',
        'combos_total',
    ];

    protected $casts = [
        'last_processed_at' => 'datetime',
        'runs' => 'integer',
        'combos_total' => 'integer',
    ];
}
