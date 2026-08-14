<?php

namespace App\Http\Controllers\Api\V1\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customers. Shared, and deliberately ungated.
 *
 * There is no module middleware on this route and there must not be one. A customer is a
 * customer in both verticals — switching the backoffice to F&B filters *inventory and
 * reporting*, not this. If a RetailCustomerController and an FnbCustomerController ever
 * appear beside each other, the architecture has been lost.
 */
class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $search = trim((string) $request->query('search', ''));

        return response()->json([
            'data' => DB::table('customers')
                ->where('account_id', $user->account_id)
                ->when($search !== '', function ($query) use ($search) {
                    $pattern = '%'.strtolower($search).'%';

                    $query->where(function ($inner) use ($pattern) {
                        $inner->whereRaw('lower(name) like ?', [$pattern])
                            ->orWhereRaw('lower(coalesce(phone, \'\')) like ?', [$pattern]);
                    });
                })
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name', 'phone', 'email', 'created_at'])
                ->map(fn ($row) => (array) $row)
                ->all(),
        ]);
    }
}
