<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Enums\Module;
use App\Models\Concerns\HasUuidV7Key;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant root.
 *
 * enabled_modules is the entitlement record and the only authority on it. Anything that
 * authorises against a module reads this row per request; the copy in a login response is
 * for rendering the UI and must never be used to decide access.
 */
class Account extends Model
{
    /** @use HasFactory<\Database\Factories\AccountFactory> */
    use HasFactory;
    use HasUuidV7Key;

    protected $fillable = [
        'id', 'name', 'timezone', 'currency', 'enabled_modules', 'default_fulfillment_type',
    ];

    protected function casts(): array
    {
        return [
            'enabled_modules' => 'array',
            'default_fulfillment_type' => FulfillmentType::class,
        ];
    }

    /**
     * The entitlement check. Deliberately a method on the account row rather than a helper
     * that could be handed a cached array from somewhere else.
     */
    public function hasModule(Module $module): bool
    {
        return in_array($module->value, $this->enabled_modules ?? [], strict: true);
    }

    /** @return list<Module> */
    public function modules(): array
    {
        return array_map(Module::from(...), $this->enabled_modules ?? []);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
