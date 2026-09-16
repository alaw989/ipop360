<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row cursor (id = 1) marking the last place seeded by
 * restaurants:seed-places, stored as "normalized_name|STATE".
 */
class EnrichmentPlaceCursor extends Model
{
    protected $table = 'enrichment_place_cursor';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'cursor'];

    public static function current(): ?string
    {
        return static::query()->find(1)?->cursor;
    }

    public static function advance(string $cursor): void
    {
        static::query()->updateOrCreate(['id' => 1], ['cursor' => $cursor]);
    }
}
