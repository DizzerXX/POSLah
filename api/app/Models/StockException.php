<?php

namespace App\Models;

use App\Enums\StockExceptionStatus;
use App\Models\Concerns\HasUuidV7Key;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The output of "accept both, allow negative, flag": raised by reconciliation instead of
 * rejecting a sale that has already happened.
 *
 * Mutable, unlike movements — this is a workflow item, not a fact. Resolution is a human
 * stocktake, which is itself just another append.
 */
class StockException extends Model
{
    /** @use HasFactory<\Database\Factories\StockExceptionFactory> */
    use HasFactory;
    use HasUuidV7Key;

    protected $fillable = [
        'id', 'store_id', 'stockable_type', 'stockable_id', 'resulting_quantity',
        'contributing_order_ids', 'status', 'resolved_by_user_id', 'resolution_note',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resulting_quantity' => 'decimal:3',
            'contributing_order_ids' => 'array',
            'status' => StockExceptionStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', StockExceptionStatus::Open);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
