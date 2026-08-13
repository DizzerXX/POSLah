<?php

namespace App\Models;

use App\Enums\StockMovementReason;
use App\Models\Concerns\HasUuidV7Key;
use App\Models\Concerns\IsAppendOnly;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only, mirroring the ledger. Every stock fact is a row and stock_levels is their
 * sum; a correction is a new row with the opposite sign, never an edit.
 *
 * Two lattes on one order write two rows on purpose — per-line provenance is what makes
 * reconciliation debuggable months later, and merging them into one -36g movement would
 * destroy it.
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    use HasUuidV7Key;
    use IsAppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id', 'store_id', 'stockable_type', 'stockable_id', 'delta', 'reason',
        'source_order_id', 'source_order_line_id', 'source_device_id', 'created_by_user_id',
        'note', 'occurred_at', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'delta' => 'decimal:3',
            'reason' => StockMovementReason::class,
            'occurred_at' => 'datetime',  // client clock: an offline sale happens long before it is recorded
            'recorded_at' => 'datetime',  // server clock
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    public function sourceOrderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'source_order_line_id');
    }

    public function sourceDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'source_device_id');
    }
}
