<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7Key;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLineModifier extends Model
{
    /** @use HasFactory<\Database\Factories\OrderLineModifierFactory> */
    use HasFactory;
    use HasUuidV7Key;

    protected $fillable = [
        'id', 'order_line_id', 'modifier_id', 'name_snapshot', 'price_delta_snapshot',
    ];

    protected function casts(): array
    {
        return ['price_delta_snapshot' => 'integer'];
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }
}
