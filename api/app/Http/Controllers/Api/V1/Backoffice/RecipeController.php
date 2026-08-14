<?php

namespace App\Http\Controllers\Api\V1\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\RecipeLine;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Recipe management. F&B-gated at the route.
 *
 * Flat, one level deep: a recipe line points at an ingredient, never at another product.
 * Nesting — a sauce that is itself a recipe — needs recursive expansion, cycle detection and
 * cross-level unit conversion, and is a documented v1 limitation rather than an oversight.
 */
class RecipeController extends Controller
{
    /** Menu items and what each is made from. */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $products = Product::query()
            ->where('account_id', $user->account_id)
            // A menu item holds no stock of its own; its recipe does. That is what makes it
            // a thing with a recipe rather than a thing with a stock level.
            ->where('tracks_stock', false)
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'fulfillment_type', 'is_active']);

        $lines = DB::table('recipe_lines')
            ->join('ingredients', 'ingredients.id', '=', 'recipe_lines.ingredient_id')
            ->whereIn('recipe_lines.product_id', $products->pluck('id'))
            ->select(
                'recipe_lines.id',
                'recipe_lines.product_id',
                'recipe_lines.ingredient_id',
                'recipe_lines.quantity',
                'ingredients.name as ingredient_name',
                'ingredients.unit',
                'ingredients.cost_per_unit',
            )
            ->orderBy('ingredients.name')
            ->get();

        return response()->json([
            'data' => $products->map(function (Product $product) use ($lines) {
                $recipe = $lines->where('product_id', $product->id)->values();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'is_active' => $product->is_active,
                    'lines' => $recipe->map(fn ($line) => (array) $line)->all(),
                    // Naive and indicative only. Real COGS needs purchase orders, weighted
                    // average cost and wastage accounting — a subsystem, not a field — and is
                    // explicitly out of scope for v1.
                    'indicative_cost' => $recipe->reduce(
                        fn ($carry, $line) => $carry + (int) round(
                            ((float) $line->quantity) * (int) ($line->cost_per_unit ?? 0),
                        ),
                        0,
                    ),
                ];
            })->all(),
        ]);
    }

    /** Replaces a product's recipe wholesale. */
    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['present', 'array'],
            'lines.*.ingredient_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        /** @var User $user */
        $user = $request->user();

        abort_unless($product->account_id === $user->account_id, 403, 'That product belongs to another account.');

        $ingredientIds = collect($validated['lines'])->pluck('ingredient_id')->unique();

        abort_if(
            $ingredientIds->count() !== count($validated['lines']),
            422,
            'An ingredient may appear at most once in a recipe.',
        );

        $known = DB::table('ingredients')
            ->where('account_id', $user->account_id)
            ->whereIn('id', $ingredientIds)
            ->count();

        abort_if($known !== $ingredientIds->count(), 422, 'Unknown ingredient.');

        DB::transaction(function () use ($product, $validated) {
            // Recipes are catalog, not ledger: they are edited in place and flow downward.
            // Sales already made are unaffected, because expansion happened on the till
            // against the recipe as it stood at the time.
            RecipeLine::query()->where('product_id', $product->id)->delete();

            foreach ($validated['lines'] as $line) {
                RecipeLine::query()->create([
                    'product_id' => $product->id,
                    'ingredient_id' => $line['ingredient_id'],
                    'quantity' => $line['quantity'],
                ]);
            }
        });

        return $this->index($request);
    }
}
