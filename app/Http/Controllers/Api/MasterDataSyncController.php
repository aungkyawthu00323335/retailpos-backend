<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MasterDataSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterDataSyncController extends Controller
{
    public function __construct(
        protected MasterDataSyncService $syncService
    ) {}

    /**
     * Ingest batch of offline-created master data.
     */
    public function syncBatch(Request $request): JsonResponse
    {
        $payload = $request->all();
        $branchCode = $request->input('branch_code') ?? $request->header('X-Branch-Code');

        $result = $this->syncService->syncBatch($payload, $branchCode);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'message' => 'Master data synced successfully',
            'id_map' => $result['id_map'],
            'flat_id_map' => $result['flat_id_map'],
        ], 200);
    }
}
