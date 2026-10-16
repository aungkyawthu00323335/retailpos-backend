<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductBatchSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSyncController extends Controller
{
    public function __construct(
        protected ProductBatchSyncService $syncService
    ) {}

    /**
     * Batch synchronize products created offline with idempotency and conflict disambiguation.
     */
    public function syncBatch(Request $request): JsonResponse
    {
        $products = $request->input('products', []);
        $branchCode = $request->input('branch_code') ?? $request->header('X-Branch-Code');
        $warehouseId = $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null;

        $response = $this->syncService->syncBatch($products, $branchCode, $warehouseId);

        $count = count($response['results']);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'message' => "{$count} products processed",
            'results' => $response['results'],
            'id_map' => $response['id_map'],
        ], 200);
    }
}
