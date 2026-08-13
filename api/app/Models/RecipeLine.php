<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7Key;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a bill of materials. Flat: a recipe line points at an ingredient, never at
 * another product, so there is no recursion and no cycle detection. Documented limitation.
 */
class RecipeLine extends Model
{
    /** @use HasFactory<\Database\Factories\RecipeLineFactory> */
    use HasFactory;
    use HasUuidV7Key;

    protected $fillable = ['id', 'product_id', 'ingredient_id', 'quantity'];

    protected function casts(): array
    {
        // In the ingredient's own unit. The one family of quantities that is not an integer:
        // 150 g of patty is not a count.
        return ['quantity' => 'decimal:3'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
