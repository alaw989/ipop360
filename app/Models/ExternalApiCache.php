<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ExternalApiCache extends Model
{
    protected $table = 'external_api_cache';

    protected $fillable = [
        'source',
        'external_id',
        'data',
        'fetched_at',
        'expires_at',
    ];

    protected $casts = [
        'data' => 'array',
        'fetched_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * @param  Builder<ExternalApiCache>  $query
     * @return Builder<ExternalApiCache>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', Carbon::now());
    }

    /**
     * @param  Builder<ExternalApiCache>  $query
     * @return Builder<ExternalApiCache>
     */
    public function scopeFresh(Builder $query): Builder
    {
        return $query->where('expires_at', '>=', Carbon::now());
    }

    /**
     * @return array<mixed>|null
     */
    public static function findByKey(string $key): ?array
    {
        $record = static::where('external_id', $key)->fresh()->first();

        return $record?->data;
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function storeByKey(string $key, array $data, Carbon $expiresAt): self
    {
        [$source] = explode(':', $key, 2);

        // Empty results are cached briefly (not at the caller's long TTL) so a
        // transient empty/failed source response self-heals on the next request
        // instead of persisting as a 0-result search for weeks. A 200-with-empty
        // was previously cached at the full source TTL, which produced the
        // no-category "no results" bug (cache key stayed empty for 30 days).
        if (empty($data)) {
            $expiresAt = Carbon::now()->addHours(
                (int) config('restaurant-finder.cache.empty_retry_hours', 2)
            );
        }

        return static::updateOrCreate(
            ['source' => $source ?: 'unknown', 'external_id' => $key],
            [
                'data' => $data,
                'fetched_at' => Carbon::now(),
                'expires_at' => $expiresAt,
            ]
        );
    }

    /**
     * Get cache statistics including quota usage.
     *
     * @param  int  $expiringDays  Number of days to look ahead for expiring entries
     * @return array{total_rows: int, by_source: array<string, int>, expiring_within: int, serpapi_calls_last_30d: int}
     */
    public static function stats(int $expiringDays = 7): array
    {
        $now = Carbon::now();
        $expiringCutoff = $now->copy()->addDays($expiringDays);
        $thirtyDaysAgo = $now->copy()->subDays(30);

        // Total rows
        $totalRows = static::count();

        // By source (all entries have non-null source per schema)
        $bySource = static::query()
            ->selectRaw('source as source_name, COUNT(*) as count')
            ->groupBy('source_name')
            ->pluck('count', 'source_name')
            ->map(fn ($count) => (int) $count)
            ->toArray();

        // Expiring within N days (schema ensures expires_at is not null)
        $expiringWithin = static::where('expires_at', '<=', $expiringCutoff)
            ->where('expires_at', '>=', $now)
            ->count();

        // Distinct SerpApi cache entries touched in the last 30 days. This is
        // a cache-inventory stat, NOT a call-attempt count: storeByKey()/
        // recordFailedCall() upsert by cache key, so a key re-fetched after
        // its TTL expires bumps this same row's fetched_at instead of adding
        // a new one — real quota decisions must use SerpApiCallLog::
        // countLast30Days() (an append-only log of actual outbound attempts)
        // instead of this field. See spec-106.
        $serpapiCallsLast30d = static::where('source', 'serpapi')
            ->where('fetched_at', '>=', $thirtyDaysAgo)
            ->count();

        return [
            'total_rows' => $totalRows,
            'by_source' => $bySource,
            'expiring_within' => $expiringWithin,
            'serpapi_calls_last_30d' => $serpapiCallsLast30d,
        ];
    }
}
