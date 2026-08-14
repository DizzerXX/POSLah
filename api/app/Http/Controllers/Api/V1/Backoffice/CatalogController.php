<?php

namespace App\Http\Controllers\Api\V1\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The two vertical-specific catalog reads.
 *
 * These exist to be *gated*: ingredients are meaningless to a retail-only shop and variant
 * stock is meaningless to a café. The route definitions carry `module:` middleware, and that
 * middleware — not this class, and certainly not the client — is what decides.
 */
class CatalogController extends Controller
{
    /** F&B. The stockable a menu item decrements; never sold directly. */
    public function ingredients(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => DB::table('ingredients')
                ->where('account_id', $user->account_id)
                ->orderBy('name')
                ->get(['id', 'name', 'unit', 'low_stock_threshold', 'cost_per_unit', 'is_active'])
                ->map(fn ($row) => (array) $row)
                ->all(),
        ]);
    }

    /** Retail. The stockable a SKU decrements. */
    public function variants(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => DB::table('product_variants')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('products.account_id', $user->account_id)
                ->orderBy('products.name')
                ->selectRaw("product_variants.id, products.name || ' — ' || product_variants.name as name, product_variants.sku, product_variants.barcode, product_variants.is_active")
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all(),
        ]);
    }
}
