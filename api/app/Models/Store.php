<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7Key;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    /** @use HasFactory<\Database\Factories\StoreFactory> */
    use HasFactory;
    use HasUuidV7Key;

    protected $fillable = ['id', 'account_id', 'name', 'address', 'tax_rate'];

    protected function casts(): array
    {
        return [
            // Fraction, not percent: 0.0600 is 6%. Snapshotted onto each order at checkout.
            'tax_rate' => 'decimal:4',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockExceptions(): HasMany
    {
        return $this->hasMany(StockException::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
