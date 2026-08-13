<?php

namespace App\Enums;

/**
 * What stock is held against, and the clearest expression of the retail/F&B divergence:
 * retail holds stock against a sellable variant, F&B against ingredients that are never
 * themselves sold.
 *
 * These string values are the morph map registered in AppServiceProvider; they are stored
 * in stock_levels.stockable_type and stock_movements.stockable_type, so they are part of
 * the on-disk schema and must not be renamed casually.
 */
enum StockableType: string
{
    case ProductVariant = 'product_variant';
    case Ingredient = 'ingredient';
}
