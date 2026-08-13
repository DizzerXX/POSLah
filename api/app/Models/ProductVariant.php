<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7Key;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Retail's stockable. Stock is held against "Large / Blue", not against the product.
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    use HasUuidV7Key;

    protected $fillable = [
        'id', 'product_id', 'name', 'sku', 'barcode', 'price_delta', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_delta' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Minor units, integer arithmetic throughout. */
    public function effectivePrice(): int
    {
        return $this->product->price + $this->price_delta;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
