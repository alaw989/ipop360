<?php

namespace App\Models;

use App\Support\SqlDialect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property-read Collection<int, Cuisine> $cuisines
 * @property-read Collection<int, RestaurantSocialLink> $socialLinks
 *
 * @method static Builder|Restaurant nearby(float $lat, float $lng, float $radiusKm = 25)
 * @method static Builder|Restaurant active()
 */
class Restaurant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'phone',
        'website_url',
        'price_range',
        'photo_url',
        'photos',
        'google_place_id',
        'yelp_business_id',
        'google_rating',
        'google_review_count',
        'yelp_rating',
        'yelp_review_count',
        'popular_times_avg_busyness',
        'has_award',
        'popularity_score',
        'score_breakdown',
        'is_active',
        'opening_hours',
        'ai_metadata',
        'features',
        'website_clicks_count',
        'directions_clicks_count',
        'call_clicks_count',
        'total_engagement',
        'social_links_count',
        'pageviews_count',
        'social_link_clicks_count',
        'menu_click_count',
        'menu_url',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'google_rating' => 'float',
        'google_review_count' => 'integer',
        'yelp_rating' => 'float',
        'yelp_review_count' => 'integer',
        'popular_times_avg_busyness' => 'float',
        'popularity_score' => 'float',
        'photos' => 'array',
        'score_breakdown' => 'array',
        'has_award' => 'boolean',
        'is_active' => 'boolean',
        'opening_hours' => 'array',
        'ai_metadata' => 'array',
        'features' => 'array',
        'website_clicks_count' => 'integer',
        'directions_clicks_count' => 'integer',
        'call_clicks_count' => 'integer',
        'total_engagement' => 'integer',
        'social_links_count' => 'integer',
        'pageviews_count' => 'integer',
        'social_link_clicks_count' => 'integer',
        'menu_click_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Restaurant $restaurant) {
            if (empty($restaurant->slug)) {
                $restaurant->slug = Str::slug($restaurant->name).'-'.Str::random(6);
            }
        });
    }

    public function cuisines(): BelongsToMany
    {
        return $this->belongsToMany(Cuisine::class, 'cuisine_restaurant');
    }

    /**
     * The users who have favorited this restaurant.
     */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorite_restaurant_user')
            ->withTimestamps();
    }

    public function socialLinks(): HasMany
    {
        return $this->hasMany(RestaurantSocialLink::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeNearby(Builder $query, float $lat, float $lng, ?float $radiusKm = null): Builder
    {
        $radiusKm ??= (float) config('restaurant-finder.live_search.nearby_radius_km', 25);

        $haversine = '(
            6371 * acos(
                '.SqlDialect::clampToOne('cos(radians(?))
                * cos(radians(latitude))
                * cos(radians(longitude) - radians(?))
                + sin(radians(?))
                * sin(radians(latitude))').'
            )
        )';

        // Bounding box prefilter to narrow candidates before the haversine calculation.
        // Uses ~111 km per degree latitude; longitude varies by cosine of latitude.
        // Pad by 10% to avoid excluding valid results at the radius edge.
        $latDelta = ($radiusKm * 1.1) / 111.0;
        $lngDelta = ($radiusKm * 1.1) / (111.0 * cos(deg2rad($lat)));

        $minLat = $lat - $latDelta;
        $maxLat = $lat + $latDelta;
        $minLng = $lng - $lngDelta;
        $maxLng = $lng + $lngDelta;

        return $query
            ->selectRaw("*, {$haversine} AS distance", [$lat, $lng, $lat])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$minLat, $maxLat])
            ->whereBetween('longitude', [$minLng, $maxLng])
            ->whereRaw("{$haversine} <= ".SqlDialect::castToFloat('?'), [$lat, $lng, $lat, $radiusKm]);
    }

    public function scopeByPopularity(Builder $query): Builder
    {
        return $query->orderByRaw(self::decayedPopularityScoreExpression().' DESC');
    }

    /**
     * SQL expression that applies a linear freshness decay to popularity_score
     * based on how long ago the restaurant's data was last updated.
     * See spec-104.
     */
    public static function decayedPopularityScoreExpression(): string
    {
        $decayDays = (int) config('restaurant-finder.ranking.score_decay_days', 90);
        $decayFloor = (float) config('restaurant-finder.ranking.score_decay_floor', 0.5);

        return sprintf(
            'popularity_score * '.SqlDialect::scalarMax('%F', '1.0 - (%s / %d)'),
            $decayFloor,
            SqlDialect::daysSinceUpdated(),
            $decayDays
        );
    }
}
