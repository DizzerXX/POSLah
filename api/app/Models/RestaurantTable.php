<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\HasUuidV7Key;
use Database\Factories\RestaurantTableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dining table.
 *
 * It has no `status` column, on purpose. Availability is derived from whether an unfinished
 * order references it — storing it as well would be two sources of truth for one fact, and
 * the one that drifts is always the denormalised one.
 */
class RestaurantTable extends Model
{
    /** @use HasFactory<RestaurantTableFactory> */
    use HasFactory;

    use HasUuidV7Key;

    protected $fillable = ['id', 'account_id', 'store_id', 'label', 'seats', 'is_active'];

    protected function casts(): array
    {
        return ['seats' => 'integer', 'is_active' => 'boolean'];
    }

    /** Statuses that mean "somebody is still sitting here". */
    public const OCCUPYING_STATUSES = [
        OrderStatus::Created,
        OrderStatus::Sent,
        OrderStatus::Preparing,
        OrderStatus::Ready,
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
