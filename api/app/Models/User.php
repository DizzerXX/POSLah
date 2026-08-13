<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\HasUuidV7Key;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuidV7Key;
    use Notifiable;

    protected $fillable = [
        'id', 'account_id', 'name', 'email', 'password', 'role', 'pin_hash', 'is_active',
    ];

    protected $hidden = ['password', 'pin_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Whether this user may authorise a void or refund.
     *
     * The till also prompts for a PIN, but that prompt is UX: a modified build skips it.
     * This check, run server-side against the stored role, is what makes it real.
     */
    public function canAuthorizeReversal(): bool
    {
        return $this->is_active && $this->role->canAuthorizeReversal();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'cashier_user_id');
    }
}
