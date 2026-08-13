<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Models\Concerns\HasUuidV7Key;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable item in either vertical.
 *
 * tracks_stock and "has recipe lines" are separate concepts on purpose. Three real cases:
 * a retail SKU decrements its variant; an F&B menu item decrements its ingredients and has
 * no stock of its own; a corkage fee touches inventory not at all. One boolean cannot
 * express the third.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasUuidV7Key;

    protected $fillable = [
        'id', 'account_id', 'category_id', 'name', 'sku', 'barcode', 'price',
        'fulfillment_type', 'tracks_stock', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            // Minor units. Cast to int so nothing downstream is tempted to treat it as money-as-float.
            'price' => 'integer',
            'fulfillment_type' => FulfillmentType::class,
            'tracks_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function isRouted(): bool
    {
        return $this->fulfillment_type === FulfillmentType::Routed;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class);
    }

    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeLine::class);
    }
}
