<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only log of real outbound SerpApi call attempts (success or
 * failure). Unlike ExternalApiCache (which upserts one row per cache key and
 * therefore undercounts repeat calls against the same key), every real call
 * writes a NEW row here — this is the trustworthy source for quota decisions.
 */
class SerpApiCallLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'serpapi_call_log';

    public static function record(): void
    {
        static::create([]);
    }

    public static function countLast30Days(): int
    {
        return static::where('created_at', '>=', Carbon::now()->subDays(30))->count();
    }
}
