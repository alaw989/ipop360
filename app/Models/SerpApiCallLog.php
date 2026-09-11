<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only log of real outbound SerpApi call attempts (success or
 * failure). Unlike ExternalApiCache (which upserts one row per cache key and
 * therefore undercounts repeat calls against the same key), every real call
 * writes a NEW row here — this is the trustworthy source for quota decisions.
 *
 * Each row also carries the call's yield (see the add_yield_columns migration)
 * so ratings-per-call is measured, not guessed.
 *
 * @property int $id
 * @property string|null $context
 * @property string|null $status
 * @property int|null $results
 * @property int|null $rated_results
 * @property int|null $matched
 * @property int|null $newly_rated
 * @property int|null $created_rows
 */
class SerpApiCallLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'serpapi_call_log';

    protected $fillable = [
        'context', 'status', 'query', 'lat', 'lng',
        'results', 'rated_results', 'matched', 'newly_rated', 'created_rows',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'results' => 'integer',
        'rated_results' => 'integer',
        'matched' => 'integer',
        'newly_rated' => 'integer',
        'created_rows' => 'integer',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function record(array $attributes = []): self
    {
        return static::create($attributes);
    }

    public static function countLast30Days(): int
    {
        return static::where('created_at', '>=', Carbon::now()->subDays(30))->count();
    }

    /**
     * Yield of successful enrichment calls in the last 30 days.
     *
     * @return array{calls: int, results: int, rated_results: int, matched: int, newly_rated: int, created_rows: int}
     */
    public static function enrichmentYieldLast30Days(): array
    {
        $row = static::query()
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->where('context', 'enrichment')
            ->where('status', 'ok')
            ->selectRaw('COUNT(*) AS calls, COALESCE(SUM(results), 0) AS results, COALESCE(SUM(rated_results), 0) AS rated_results, COALESCE(SUM(matched), 0) AS matched, COALESCE(SUM(newly_rated), 0) AS newly_rated, COALESCE(SUM(created_rows), 0) AS created_rows')
            ->toBase()
            ->first();

        return [
            'calls' => (int) ($row->calls ?? 0),
            'results' => (int) ($row->results ?? 0),
            'rated_results' => (int) ($row->rated_results ?? 0),
            'matched' => (int) ($row->matched ?? 0),
            'newly_rated' => (int) ($row->newly_rated ?? 0),
            'created_rows' => (int) ($row->created_rows ?? 0),
        ];
    }
}
