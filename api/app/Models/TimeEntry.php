<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7Key;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Identical for both verticals. No fork.
 */
class TimeEntry extends Model
{
    /** @use HasFactory<\Database\Factories\TimeEntryFactory> */
    use HasFactory;
    use HasUuidV7Key;

    protected $fillable = [
        'id', 'account_id', 'store_id', 'user_id', 'clocked_in_at', 'clocked_out_at',
        'source_device_id', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'clocked_in_at' => 'datetime',
            'clocked_out_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('clocked_out_at');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'source_device_id');
    }
}
