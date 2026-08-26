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
     * Drivers with dedicated SQL variants below. Anything else means the raw
     * SQL fragments would silently take a wrong dialect, so fail loudly
     * instead of producing corrupt queries.
     */
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];

    private static function driver(): string
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new \RuntimeException(
                "SqlDialect has no SQL variant for driver '{$driver}'"
            );
        }

        return $driver;
    }

    private static function isMysql(): bool
    {
        return self::driver() === 'mysql';
    }

    /**
     * Clamp a scalar to [-1, 1]: SQLite uses MIN/MAX scalars, MySQL LEAST/GREATEST.
     *
     * @param  literal-string  $expr
     * @return literal-string
     */
    public static function clampToOne(string $expr): string
    {
        if (self::isMysql()) {
            return "LEAST(1.0, GREATEST(-1.0, {$expr}))";
        }

        return "MIN(1.0, MAX(-1.0, {$expr}))";
    }

    /**
     * Days elapsed since the row's updated_at, as a fractional number.
     *
     * Parenthesised so the caller's `1.0 - (%s / %d)` groups correctly:
     * `/` binds tighter than `-`, so without wrapping parens SQLite computes
     * `julianday('now') - (julianday(updated_at) / days)` and the decay factor
     * collapses to the floor. MySQL's TIMESTAMPDIFF form is a single division
     * already, but the parens keep both branches identical in shape.
     *
     * @return literal-string
     */
    public static function daysSinceUpdated(): string
    {
        if (self::isMysql()) {
            return '(TIMESTAMPDIFF(SECOND, updated_at, NOW()) / 86400.0)';
        }

        return "(julianday('now') - julianday(updated_at))";
    }

    /**
     * Scalar MAX(): SQLite MAX(a,b), MySQL GREATEST(a,b).
     *
     * @param  literal-string  $left
     * @param  literal-string  $right
     * @return literal-string
     */
    public static function scalarMax(string $left, string $right): string
    {
        if (self::isMysql()) {
            return "GREATEST({$left}, {$right})";
        }

        return "MAX({$left}, {$right})";
    }

    /**
     * Cast a bound value to a float-comparable type.
     *
     * @param  literal-string  $expr
     * @return literal-string
     */
    public static function castToFloat(string $expr): string
    {
        if (self::isMysql()) {
            return "CAST({$expr} AS DOUBLE)";
        }

        return "CAST({$expr} AS REAL)";
    }

    /**
     * JSON array length of a column: MySQL `JSON_LENGTH`, SQLite
     * `json_array_length`. Used for gallery arrays (photos JSON column).
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function jsonArrayLength(string $column): string
    {
        if (self::isMysql()) {
            return "JSON_LENGTH({$column})";
        }

        return "json_array_length({$column})";
    }

    /**
     * Correlated subquery counting a restaurant's engagement rows created
     * within the trailing N days. Referenced against the `restaurants` table
     * alias so it can be dropped straight into an ORDER BY expression.
     * `$windowDays` is an integer config value (never user input), so the
     * returned string is non-empty but not a compile-time literal.
     *
     * @return non-empty-string
     */
    public static function recentEngagementCountSubquery(int $windowDays): string
    {
        if (self::isMysql()) {
            return "(SELECT COUNT(*) FROM restaurant_engagement e WHERE e.restaurant_id = restaurants.id AND e.created_at >= (NOW() - INTERVAL {$windowDays} DAY))";
        }

        return "(SELECT COUNT(*) FROM restaurant_engagement e WHERE e.restaurant_id = restaurants.id AND e.created_at >= datetime('now', '-{$windowDays} days'))";
    }
}
