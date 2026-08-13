<?php

namespace App\Models;

use App\Enums\Module;
use App\Models\Concerns\HasUuidV7Key;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registered till.
 *
 * Exists primarily for provenance: when stock lands at -1 after two offline batches, the
 * first useful question is which device sold what and when.
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    use HasUuidV7Key;

    protected $fillable = [
        'id', 'account_id', 'store_id', 'registered_by_user_id', 'device_name',
        'number_prefix', 'last_seen_sequence', 'assigned_module', 'last_seen_at',
        'last_sync_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'assigned_module' => Module::class,
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
