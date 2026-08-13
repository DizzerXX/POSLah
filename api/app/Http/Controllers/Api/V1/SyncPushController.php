<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncPushRequest;
use App\Models\Device;
use App\Sync\PushBatchProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class SyncPushController extends Controller
{
    public function __invoke(SyncPushRequest $request, PushBatchProcessor $processor): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        // The body carries device_id because the plan's payload does, but the token is what
        // decides. A device may only ever push as itself.
        if ($request->validated('device_id') !== $device->id) {
            abort(403, 'The batch device_id does not match the authenticated device.');
        }

        $results = $processor->process($device, $request->validated('entries'));

        $device->forceFill([
            'last_seen_at' => Carbon::now(),
            'last_sync_at' => Carbon::now(),
        ])->save();

        return response()->json([
            'batch_id' => $request->validated('batch_id'),
            'results' => array_map(static fn ($result) => $result->toArray(), $results),
            // The till stores this as its pull cursor rather than reading its own clock,
            // which is what stops clock skew from silently skipping records.
            'server_time' => Carbon::now()->toIso8601String(),
        ]);
    }
}
