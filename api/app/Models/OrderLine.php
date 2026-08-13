<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Enums\LineFulfillmentStatus;
use App\Models\Concerns\HasUuidV7Key;
use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The snapshot columns here are load-bearing. Rendering a receipt reads name_snapshot and
 * price_snapshot and never joins back to products — a receipt from March must not change
 * when the product is renamed or repriced in June. Do not "normalise these away".
 */
class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    use HasUuidV7Key;

    protected $fillable = [
        'id', 'order_id', 'product_id', 'product_variant_id', 'name_snapshot',
        'price_snapshot', 'fulfillment_type', 'quantity', 'line_total', 'fulfillment_status',
    ];

    protected function casts(): array
    {
        return [
            'price_snapshot' => 'integer',
            'line_total' => 'integer',
            'quantity' => 'integer',
            'fulfillment_type' => FulfillmentType::class,
            'fulfillment_status' => LineFulfillmentStatus::class,
        ];
    }

    public function isRouted(): bool
    {
        return $this->fulfillment_type === FulfillmentType::Routed;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderLineModifier::class);
    }
}
