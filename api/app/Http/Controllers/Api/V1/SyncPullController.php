<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Sync\CatalogPullService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SyncPullController extends Controller
{
    public function __invoke(Request $request, CatalogPullService $service): JsonResponse
    {
        $request->validate([
            // Absent means "everything": a freshly registered till has no local catalog.
            'since' => ['nullable', 'date'],
        ]);

        /** @var Device $device */
        $device = $request->user();

        $since = $request->filled('since') ? Carbon::parse($request->string('since')) : null;

        $device->forceFill(['last_seen_at' => Carbon::now()])->save();

        return response()->json($service->pull($device, $since));
    }
}
