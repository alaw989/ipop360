<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Driver-agnostic SQL fragments so raw queries run on SQLite (dev/test)
 * and MySQL (prod). Functions with different semantics/names per driver
 * live here; the model/controller builds the rest of the expression.
 */
class SqlDialect
{
    /**
     * Clamp a scalar to [-1, 1]: SQLite uses MIN/MAX scalars, MySQL LEAST/GREATEST.
     */
    public static function clampToOne(string $expr): string
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return "LEAST(1.0, GREATEST(-1.0, {$expr}))";
        }

        return "MIN(1.0, MAX(-1.0, {$expr}))";
    }

    /**
     * Days elapsed since the row's updated_at, as a fractional number.
     */
    public static function daysSinceUpdated(): string
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return 'TIMESTAMPDIFF(SECOND, updated_at, NOW()) / 86400.0';
        }

        return "julianday('now') - julianday(updated_at)";
    }

    /**
     * Scalar MAX(): SQLite MAX(a,b), MySQL GREATEST(a,b).
     */
    public static function scalarMax(string $left, string $right): string
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return "GREATEST({$left}, {$right})";
        }

        return "MAX({$left}, {$right})";
    }

    /**
     * Cast a bound value to a float-comparable type.
     */
    public static function castToFloat(string $expr): string
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return "CAST({$expr} AS DOUBLE)";
        }

        return "CAST({$expr} AS REAL)";
    }
}
