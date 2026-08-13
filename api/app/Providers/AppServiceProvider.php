<?php

namespace App\Providers;

use App\Enums\StockableType;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\ProductVariant;
use App\Models\User;
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
        // Morph map. Without it, polymorphic type columns would hold fully-qualified class
        // names, so moving or renaming a PHP class would silently orphan historical rows.
        // These strings are part of the on-disk schema.
        //
        // enforceMorphMap (rather than morphMap) means an unmapped model throws instead of
        // quietly writing a class name — which is why the token holders appear here too:
        // personal_access_tokens.tokenable_type is polymorphic as well.
        Relation::enforceMorphMap([
            StockableType::ProductVariant->value => ProductVariant::class,
            StockableType::Ingredient->value => Ingredient::class,
            'device' => Device::class,
            'user' => User::class,
        ]);

        // Fail loudly in development on the mistakes that are expensive to find later:
        // lazy loading (N+1 across a catalog render), assigning attributes that do not
        // exist, and silently truncated writes.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Postgres has no equivalent of MySQL's lax mode; make the intent explicit anyway.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
