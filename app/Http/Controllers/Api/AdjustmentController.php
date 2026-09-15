<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdjustmentController extends Controller
{
    /**
     * Generate next numeric adjustment code (numbers only).
     */
    public function generateCode()
    {
        $adjustments = StockAdjustment::select('reference_no')->orderBy('id', 'desc')->take(200)->get();
        $maxNum = 100000;
        foreach ($adjustments as $adj) {
            $digits = preg_replace('/\D/', '', (string)$adj->reference_no);
            if (!empty($digits)) {
                $val = (int)$digits;
                if ($val >= 100000 && $val < 999999999 && $val > $maxNum) {
                    $maxNum = $val;
                }
            }
        }
        $nextCode = (string)($maxNum + 1);

        return response()->json([
            'status' => 'success',
            'data' => [
                'code' => $nextCode,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = StockAdjustment::with([
            'user:id,name,role',
            'items'
        ]);

        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $query->where('warehouse_name', $request->input('warehouse'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int)$request->input('warehouse_id'));
        }

        if ($request->filled('type') && $request->input('type') !== 'All') {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('date_from')) {
            $dateFrom = trim($request->input('date_from'));
            $fromStr = strlen($dateFrom) === 10 ? ($dateFrom . ' 00:00:00') : $dateFrom;
            $query->where(function ($q) use ($dateFrom, $fromStr) {
                $q->where('date', '>=', $dateFrom)
                  ->orWhere('date', '>=', $fromStr);
            });
        }

        if ($request->filled('date_to')) {
            $dateTo = trim($request->input('date_to'));
            $toStr = strlen($dateTo) === 10 ? ($dateTo . ' 23:59:59') : $dateTo;
            $query->where(function ($q) use ($dateTo, $toStr) {
                $q->where('date', '<=', $toStr);
            });
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('role', 'like', "%{$search}%");
                  })
                  ->orWhereHas('items', function ($iq) use ($search) {
                      $iq->where('product_name', 'like', "%{$search}%")
                        ->orWhere('product_code', 'like', "%{$search}%");
                  });
            });
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'reference_no', 'warehouse_name', 'date', 'type', 'total_products', 'total_quantity', 'created_at', 'created_by', 'user_role'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'id';
        }

        $perPage = min(max((int)$request->input('per_page', 25), 1), 100);
        $adjustments = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        $data = collect($adjustments->items())->map(function ($adj) {
            if ($adj->user) {
                $adj->created_by = $adj->user->name;
                if (!empty($adj->user->role)) {
                    $adj->user_role = $adj->user->role;
                }
            }
            return $adj;
        });

        return response()->json([
            'status' => 'success',
            'data' => $data,
            'pagination' => [
                'current_page' => $adjustments->currentPage(),
                'per_page' => $adjustments->perPage(),
                'total' => $adjustments->total(),
                'last_page' => $adjustments->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:255',
            'warehouse_id' => 'nullable|integer',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'type' => 'required|string|in:Addition,Subtraction',
            'notes' => 'nullable|string',
            'user_id' => 'nullable|integer',
            'user_name' => 'nullable|string|max:255',
            'created_by' => 'nullable|string|max:255',
            'user_role' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.variant_name' => 'nullable|string',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.unit_name' => 'nullable|string|max:100',
            'items.*.conversion_rate' => 'nullable|numeric|min:0.0001',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.base_quantity' => 'nullable|numeric|min:0',
            'items.*.type' => 'nullable|string|in:Addition,Subtraction',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.base_unit_cost' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $warehouseId = $this->resolveWarehouseId($validated['warehouse_id'] ?? null, $validated['warehouse_name']);

            $prefix = Setting::get('adjustment_prefix', 'AD_');
            $refNo = !empty($validated['reference_no']) 
                ? $validated['reference_no'] 
                : ($prefix . date('Ymd') . '-' . rand(1000, 9999));
            $totalQty = 0;

            foreach ($validated['items'] as $item) {
                $totalQty += (float)$item['quantity'];
            }

            $targetUser = $request->user();
            if (!$targetUser && !empty($validated['user_id'])) {
                $targetUser = User::find($validated['user_id']);
            }
            if (!$targetUser) {
                $targetUser = User::first();
            }

            $createdByName = !empty($validated['user_name'])
                ? $validated['user_name']
                : (!empty($validated['created_by']) ? $validated['created_by'] : ($targetUser ? $targetUser->name : 'Super Admin'));
            if ($targetUser && (empty($createdByName) || $createdByName === 'Super Admin')) {
                $createdByName = $targetUser->name;
            }

            $userRole = !empty($validated['user_role'])
                ? $validated['user_role']
                : ($targetUser ? ($targetUser->roleModel?->name ?? $targetUser->role ?? 'Super Admin') : 'Super Admin');
            $userId = !empty($validated['user_id'])
                ? $validated['user_id']
                : ($targetUser ? $targetUser->id : null);

            $txDate = trim($validated['date']);
            if (!str_contains($txDate, ':')) {
                $txDate .= ' ' . now()->format('H:i:s');
            }

            $adjustment = StockAdjustment::create([
                'reference_no' => $refNo,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $txDate,
                'total_products' => count($validated['items']),
                'total_quantity' => $totalQty,
                'type' => $validated['type'],
                'notes' => $validated['notes'] ?? null,
                'user_id' => $userId,
                'user_name' => $createdByName,
                'created_by' => $createdByName,
                'user_role' => $userRole,
            ]);

            foreach ($validated['items'] as $itemData) {
                $conversionRate = isset($itemData['conversion_rate']) ? (float)$itemData['conversion_rate'] : 1.0;
                $quantity = (float)$itemData['quantity'];
                $unitCost = (float)($itemData['unit_cost'] ?? 0);
                $baseQty = isset($itemData['base_quantity']) ? (float)$itemData['base_quantity'] : ($quantity * $conversionRate);
                $baseCost = isset($itemData['base_unit_cost']) ? (float)$itemData['base_unit_cost'] : ($conversionRate > 0 ? $unitCost / $conversionRate : $unitCost);
                $subtotal = isset($itemData['subtotal']) ? (float)$itemData['subtotal'] : ($quantity * $unitCost);

                $adjItem = StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $itemData['product_id'] ?? null,
                    'product_variant_id' => $itemData['product_variant_id'] ?? null,
                    'product_code' => $itemData['product_code'],
                    'product_name' => $itemData['product_name'],
                    'variant_name' => $itemData['variant_name'] ?? null,
                    'unit_id' => $itemData['unit_id'] ?? null,
                    'unit_name' => $itemData['unit_name'] ?? 'Pcs',
                    'conversion_rate' => $conversionRate,
                    'quantity' => $quantity,
                    'base_quantity' => $baseQty,
                    'type' => $itemData['type'] ?? $validated['type'],
                    'unit_cost' => $unitCost,
                    'base_unit_cost' => $baseCost,
                    'subtotal' => $subtotal,
                ]);

                $this->applyItemStock(
                    $adjItem,
                    $warehouseId,
                    $adjustment->id,
                    $refNo,
                    $validated['notes'] ?? null,
                    $createdByName,
                    false
                );
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Stock adjustment saved successfully',
                'data' => $adjustment->load('items'),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("AdjustmentController store error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $adjustment = StockAdjustment::with(['user:id,name,role', 'items'])->find($id);
        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }
        if ($adjustment->user) {
            $adjustment->created_by = $adjustment->user->name;
            if (!empty($adjustment->user->role)) {
                $adjustment->user_role = $adjustment->user->role;
            }
        }
        return response()->json(['status' => 'success', 'data' => $adjustment]);
    }

    public function update(Request $request, $id)
    {
        $adjustment = StockAdjustment::with('items')->find($id);
        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }

        $validated = $request->validate([
            'warehouse_id' => 'nullable|integer',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'type' => 'nullable|string|in:Addition,Subtraction',
            'notes' => 'nullable|string',
            'user_id' => 'nullable|integer',
            'user_name' => 'nullable|string|max:255',
            'created_by' => 'nullable|string|max:255',
            'user_role' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.variant_name' => 'nullable|string',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.unit_name' => 'nullable|string|max:100',
            'items.*.conversion_rate' => 'nullable|numeric|min:0.0001',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.base_quantity' => 'nullable|numeric|min:0',
            'items.*.type' => 'nullable|string|in:Addition,Subtraction',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.base_unit_cost' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $oldWarehouseId = $this->resolveWarehouseId(null, $adjustment->warehouse_name);
            $newWarehouseId = $this->resolveWarehouseId($validated['warehouse_id'] ?? null, $validated['warehouse_name']);

            // 1. Rollback previous items' stock balances
            foreach ($adjustment->items as $oldItem) {
                $this->applyItemStock(
                    $oldItem,
                    $oldWarehouseId,
                    $adjustment->id,
                    $adjustment->reference_no,
                    null,
                    null,
                    true // rollback
                );
            }
            $adjustment->items()->delete();

            $totalQty = 0;
            foreach ($validated['items'] as $item) {
                $totalQty += (float)$item['quantity'];
            }

            $updateData = [
                'warehouse_id' => $newWarehouseId,
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $validated['date'],
                'total_products' => count($validated['items']),
                'total_quantity' => $totalQty,
                'type' => $validated['type'] ?? $adjustment->type,
                'notes' => $validated['notes'] ?? $adjustment->notes,
            ];
            if (isset($validated['user_name'])) {
                $updateData['user_name'] = $validated['user_name'];
                $updateData['created_by'] = $validated['user_name'];
            } elseif (isset($validated['created_by'])) {
                $updateData['user_name'] = $validated['created_by'];
                $updateData['created_by'] = $validated['created_by'];
            }
            if (isset($validated['user_role'])) {
                $updateData['user_role'] = $validated['user_role'];
            }
            if (isset($validated['user_id'])) {
                $updateData['user_id'] = $validated['user_id'];
            }

            $adjustment->update($updateData);

            foreach ($validated['items'] as $itemData) {
                $conversionRate = isset($itemData['conversion_rate']) ? (float)$itemData['conversion_rate'] : 1.0;
                $quantity = (float)$itemData['quantity'];
                $unitCost = (float)($itemData['unit_cost'] ?? 0);
                $baseQty = isset($itemData['base_quantity']) ? (float)$itemData['base_quantity'] : ($quantity * $conversionRate);
                $baseCost = isset($itemData['base_unit_cost']) ? (float)$itemData['base_unit_cost'] : ($conversionRate > 0 ? $unitCost / $conversionRate : $unitCost);
                $subtotal = isset($itemData['subtotal']) ? (float)$itemData['subtotal'] : ($quantity * $unitCost);

                $adjItem = StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $itemData['product_id'] ?? null,
                    'product_variant_id' => $itemData['product_variant_id'] ?? null,
                    'product_code' => $itemData['product_code'],
                    'product_name' => $itemData['product_name'],
                    'variant_name' => $itemData['variant_name'] ?? null,
                    'unit_id' => $itemData['unit_id'] ?? null,
                    'unit_name' => $itemData['unit_name'] ?? 'Pcs',
                    'conversion_rate' => $conversionRate,
                    'quantity' => $quantity,
                    'base_quantity' => $baseQty,
                    'type' => $itemData['type'] ?? $adjustment->type,
                    'unit_cost' => $unitCost,
                    'base_unit_cost' => $baseCost,
                    'subtotal' => $subtotal,
                ]);

                $this->applyItemStock(
                    $adjItem,
                    $newWarehouseId,
                    $adjustment->id,
                    $adjustment->reference_no,
                    $validated['notes'] ?? null,
                    $validated['created_by'] ?? $adjustment->created_by,
                    false
                );
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Stock adjustment updated successfully',
                'data' => $adjustment->load('items'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("AdjustmentController update error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $adjustment = StockAdjustment::with('items')->find($id);
        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }

        DB::beginTransaction();
        try {
            $warehouseId = $this->resolveWarehouseId(null, $adjustment->warehouse_name);

            foreach ($adjustment->items as $oldItem) {
                $this->applyItemStock(
                    $oldItem,
                    $warehouseId,
                    $adjustment->id,
                    $adjustment->reference_no,
                    null,
                    null,
                    true // rollback
                );
            }

            $adjustment->items()->delete();
            $adjustment->delete();
            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'Stock adjustment deleted']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("AdjustmentController destroy error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Resolve warehouse ID from warehouse_id or warehouse_name.
     */
    private function resolveWarehouseId(?int $warehouseId, string $warehouseName): int
    {
        if ($warehouseId && $warehouseId > 0) {
            $w = Outlet::find($warehouseId);
            if ($w) return $w->id;
        }

        if (!empty($warehouseName)) {
            $w = Outlet::where('name', $warehouseName)->first();
            if ($w) return $w->id;
        }

        $fallback = Outlet::first();
        return $fallback ? $fallback->id : 1;
    }

    /**
     * Apply or rollback StockBalance, Product stock, and StockMovement.
     */
    private function applyItemStock(
        StockAdjustmentItem $item,
        int $warehouseId,
        int $adjustmentId,
        string $adjustmentRef,
        ?string $notes = null,
        ?string $userName = null,
        bool $isRollback = false
    ): void {
        $productId = $item->product_id;
        $variantId = $item->product_variant_id;
        $qty = (float)$item->quantity;
        $conversionRate = (float)($item->conversion_rate ?: 1.0);
        $baseQty = (float)($item->base_quantity ?: ($qty * $conversionRate));
        $effectiveQty = $baseQty > 0 ? $baseQty : $qty;
        $type = $item->type; // 'Addition' or 'Subtraction'

        if (!$productId) {
            $prod = Product::where('code', $item->product_code)
                ->orWhere('sku', $item->product_code)
                ->first();
            if ($prod) {
                $productId = $prod->id;
                $item->update(['product_id' => $productId]);
            }
        } else {
            $prod = Product::find($productId);
        }

        if (!$prod) {
            return;
        }

        // Determine effective operation direction
        $isAddition = ($type === 'Addition');
        if ($isRollback) {
            $isAddition = !$isAddition;
        }

        // 1. Update StockBalance for the specific warehouse & variant (or single)
        $balanceQuery = StockBalance::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId);

        if ($variantId && $variantId > 0) {
            $balanceQuery->where('product_variant_id', $variantId);
        } else {
            $balanceQuery->whereNull('product_variant_id');
        }

        $balance = $balanceQuery->first();

        if (!$balance) {
            $balance = StockBalance::create([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => ($variantId && $variantId > 0) ? $variantId : null,
                'quantity' => 0,
            ]);
        }

        if ($isAddition) {
            $balance->increment('quantity', $effectiveQty);
        } else {
            $balance->decrement('quantity', $effectiveQty);
        }
        $balance->refresh();

        // 2. Update parent Product global stock_quantity
        if ($isAddition) {
            $prod->increment('stock_quantity', $effectiveQty);
        } else {
            $prod->decrement('stock_quantity', $effectiveQty);
        }

        // 3. Record StockMovement audit entry
        if (!$isRollback) {
            $movementCreatedBy = auth()->id();
            if (!$movementCreatedBy && is_numeric($userName)) {
                $movementCreatedBy = (int)$userName;
            }
            if (!$movementCreatedBy && !empty($userName)) {
                $userObj = User::where('name', $userName)->orWhere('email', $userName)->first();
                if ($userObj) {
                    $movementCreatedBy = $userObj->id;
                }
            }
            if (!$movementCreatedBy) {
                $movementCreatedBy = User::first()?->id;
            }

            StockMovement::create([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => ($variantId && $variantId > 0) ? $variantId : null,
                'movement_type' => 'adjustment',
                'reference_type' => 'stock_adjustment',
                'reference_id' => $adjustmentId,
                'quantity_in' => $isAddition ? $effectiveQty : 0,
                'quantity_out' => !$isAddition ? $effectiveQty : 0,
                'balance_after' => (float)$balance->quantity,
                'created_by' => $movementCreatedBy,
                'note' => $notes ?: "Stock Adjustment ($adjustmentRef)",
                'created_at' => now(),
            ]);
        }
    }
}

