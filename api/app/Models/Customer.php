<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7Key;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identical for both verticals. If a RetailCustomer and an FnbCustomer ever appear, the
 * architecture has been lost.
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use HasUuidV7Key;

    protected $fillable = [
        'id', 'account_id', 'name', 'phone', 'email', 'notes',
        'occurred_at', 'recorded_at', 'source_device_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
