<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7Key;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A materialised projection of SUM(stock_movements.delta) for one stockable in one store —
 * not a record of fact, which is why this is the one mutable table in the stock family.
 *
 * quantity_on_hand is signed and negatives are expected. Nothing may read this value and
 * refuse a sale.
 */
class StockLevel extends Model
{
    /** @use HasFactory<\Database\Factories\StockLevelFactory> */
    use HasFactory;
    use HasUuidV7Key;

    protected $fillable = ['id', 'store_id', 'stockable_type', 'stockable_id', 'quantity_on_hand'];

    protected function casts(): array
    {
        return ['quantity_on_hand' => 'decimal:3'];
    }

    public function isNegative(): bool
    {
        return bccomp((string) $this->quantity_on_hand, '0', 3) === -1;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** ProductVariant for retail, Ingredient for F&B. */
    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }
}
