<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A restaurant value moved aside by an integrity check (see
 * FieldQuarantineService). old_value holds the raw column value — JSON-encoded
 * for array/JSON columns and for quarantined social-link rows.
 *
 * @property int $id
 * @property int $restaurant_id
 * @property string $field
 * @property string|null $old_value
 * @property string $reason
 * @property string|null $detector
 * @property array<string, mixed>|null $details
 * @property Carbon $quarantined_at
 * @property Carbon|null $restored_at
 */
class FieldQuarantine extends Model
{
    protected $table = 'field_quarantine';

    public $timestamps = false;

    protected $fillable = [
        'restaurant_id',
        'field',
        'old_value',
        'reason',
        'detector',
        'details',
        'quarantined_at',
        'restored_at',
    ];

    protected $casts = [
        'details' => 'array',
        'quarantined_at' => 'datetime',
        'restored_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
