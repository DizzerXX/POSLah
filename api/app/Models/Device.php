<?php

namespace App\Models;

use App\Enums\Module;
use App\Models\Concerns\HasUuidV7Key;
use Database\Factories\DeviceFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * A registered till.
 *
 * Exists primarily for provenance: when stock lands at -1 after two offline batches, the
 * first useful question is which device sold what and when.
 *
 * The device is also the authenticated party on the sync endpoints. A till holds a device
 * token rather than a user session: it drains its outbox in the background across shift
 * changes, and which cashier happened to be logged in when a batch went out has no bearing
 * on whether that batch may land. Who made each sale travels on the rows themselves
 * (orders.cashier_user_id), which is where it belongs.
 */
class Device extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;

    /** @use HasFactory<DeviceFactory> */
    use HasApiTokens;

    use HasFactory;
    use HasUuidV7Key;

    /** Token abilities. Pull is read-only; push writes financial rows. */
    public const ABILITY_PUSH = 'sync:push';

    public const ABILITY_PULL = 'sync:pull';

    /**
     * A device has no password and no remember-me cookie: it authenticates by bearer token
     * and nothing else. Returning an empty name short-circuits the trait's remember-token
     * accessors rather than letting them reach for a column that does not exist.
     */
    public function getAuthPasswordName(): string
    {
        return '';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

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
