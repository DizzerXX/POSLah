<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7Key;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F&B. "Extra shot" — priced, and optionally consuming an ingredient on top of the base
 * recipe.
 */
class Modifier extends Model
{
    /** @use HasFactory<\Database\Factories\ModifierFactory> */
    use HasFactory;
    use HasUuidV7Key;

    protected $fillable = [
        'id', 'product_id', 'name', 'price_delta', 'consumes_ingredient_id',
        'consumes_quantity', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_delta' => 'integer',
            'consumes_quantity' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function consumesIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'consumes_ingredient_id');
    }
}
