<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Models\Concerns\HasUuidV7Key;
use App\Models\Concerns\IsAppendOnly;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Append-only. There is no update path and no delete path: a refund or void is a new row
 * with a negative amount and reverses_entry_id pointing at the original, which stays
 * byte-identical. Enforced by database trigger as well as by IsAppendOnly.
 */
class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    use HasUuidV7Key;
    use IsAppendOnly;

    /** The row is immutable, so it has a creation time and nothing else. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'id', 'account_id', 'store_id', 'order_id', 'entry_type', 'amount',
        'reverses_entry_id', 'payment_method', 'authorized_by_user_id', 'metadata',
        'occurred_at', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => LedgerEntryType::class,
            'payment_method' => PaymentMethod::class,
            // Signed minor units: reversals are negative, so takings are a plain SUM.
            'amount' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * Financial ordering uses the server clock. occurred_at comes from an untrusted device
     * clock and is not monotonic across devices, so it is for display only.
     */
    public function scopeFinancialOrder(Builder $query): Builder
    {
        return $query->orderBy('recorded_at')->orderBy('id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_entry_id');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }
}
