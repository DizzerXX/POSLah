<?php

namespace App\Providers;

use App\Enums\StockableType;
use App\Models\Ingredient;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Stockable morph map. Without it, stockable_type would hold fully-qualified class
        // names, so moving or renaming a PHP class would silently orphan historical stock
        // rows. These strings are part of the on-disk schema.
        Relation::enforceMorphMap([
            StockableType::ProductVariant->value => ProductVariant::class,
            StockableType::Ingredient->value => Ingredient::class,
        ]);

        // Fail loudly in development on the mistakes that are expensive to find later:
        // lazy loading (N+1 across a catalog render), assigning attributes that do not
        // exist, and silently truncated writes.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Postgres has no equivalent of MySQL's lax mode; make the intent explicit anyway.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
