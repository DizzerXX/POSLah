<?php

namespace App\Models;

use App\Enums\IngredientUnit;
use App\Models\Concerns\HasUuidV7Key;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * F&B's stockable. Never sold directly — a latte tracks beans and milk, not lattes.
 */
class Ingredient extends Model
{
    /** @use HasFactory<\Database\Factories\IngredientFactory> */
    use HasFactory;
    use HasUuidV7Key;

    protected $fillable = [
        'id', 'account_id', 'name', 'unit', 'low_stock_threshold', 'cost_per_unit', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'unit' => IngredientUnit::class,
            'low_stock_threshold' => 'decimal:3',
            // Minor units per one `unit`. Indicative only — real COGS is out of scope for v1.
            'cost_per_unit' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeLine::class);
    }

    public function stockLevels(): MorphMany
    {
        return $this->morphMany(StockLevel::class, 'stockable');
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'stockable');
    }
}
