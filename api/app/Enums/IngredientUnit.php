<?php

namespace App\Enums;

/**
 * No cross-unit conversion in v1: a recipe line is expressed in its ingredient's own unit.
 */
enum IngredientUnit: string
{
    case Gram = 'g';
    case Millilitre = 'ml';
    case Unit = 'unit';
}
