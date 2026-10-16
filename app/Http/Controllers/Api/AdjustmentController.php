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
use Illuminate\Validation\Rule;
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
        $query = StockAdjustment::select([
            'id', 'reference_no', 'warehouse_id', 'warehouse_name', 'date',
            'total_products', 'total_quantity', 'type', 'notes',
            'user_id', 'user_name', 'created_by', 'user_role', 'created_at', 'updated_at'
        ])->with([
            'user:id,name,role',
            'items:id,stock_adjustment_id,product_id,product_variant_id,product_code,product_name,variant_name,unit_id,unit_name,conversion_rate,quantity,base_quantity,type,unit_cost,base_unit_cost,subtotal',
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

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        $orderedQuery = $query->orderBy($sortBy, $sortOrder);
        if ($sortBy !== 'id') {
            $orderedQuery->orderBy('id', $sortOrder);
        }
        if ($isAll) {
            $totalCount = (clone $query)->count();
            $limit = min(500, max(1, (int)$request->input('limit', 500)));
            $adjustments = $orderedQuery->take($limit)->get();
            $data = $adjustments->map(function ($adj) {
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
                    'current_page' => 1,
                    'per_page' => $adjustments->count(),
                    'total' => $totalCount,
                    'last_page' => (int)ceil($totalCount / max(1, $limit)),
                ],
            ]);
        }

        $perPage = min(max((int)$request->input('per_page', 25), 1), 1000);
        $adjustments = $orderedQuery->paginate($perPage);

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
            'reference_no' => 'nullable|string|max:255|unique:stock_adjustments,reference_no',
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

        $warehouseId = $this->resolveWarehouseId($validated['warehouse_id'] ?? null, $validated['warehouse_name']);

        // Senior Accounting Validation: Verify total subtraction deductions per product & variant against warehouse balance
        $subtractionsByItem = [];
        foreach ($validated['items'] as $item) {
            $itemType = $item['type'] ?? $validated['type'];
            if ($itemType === 'Subtraction') {
                $pid = $item['product_id'] ?? null;
                if (!$pid && !empty($item['product_code'])) {
                    $p = Product::where('code', $item['product_code'])->orWhere('sku', $item['product_code'])->first();
                    $pid = $p?->id;
                }
                $vid = $item['product_variant_id'] ?? null;
                $key = ($pid ?? 'p0') . '_' . ($vid ? "var_{$vid}" : 'single');
                $cRate = isset($item['conversion_rate']) ? (float)$item['conversion_rate'] : 1.0;
                $qty = (float)$item['quantity'];
                $baseQty = isset($item['base_quantity']) ? (float)$item['base_quantity'] : ($qty * ($cRate > 0 ? $cRate : 1.0));

                if (!isset($subtractionsByItem[$key])) {
                    $subtractionsByItem[$key] = [
                        'product_id' => $pid,
                        'variant_id' => $vid,
                        'product_name' => $item['product_name'] ?? 'Product',
                        'total_base_qty' => 0.0,
                    ];
                }
                $subtractionsByItem[$key]['total_base_qty'] += $baseQty;
            }
        }

        foreach ($subtractionsByItem as $sub) {
            $pid = $sub['product_id'];
            $vid = $sub['variant_id'];
            $needed = $sub['total_base_qty'];

            if ($pid) {
                $balQuery = StockBalance::where('warehouse_id', $warehouseId)->where('product_id', $pid);
                if ($vid && $vid > 0) {
                    $balQuery->where('product_variant_id', $vid);
                } else {
                    $balQuery->whereNull('product_variant_id');
                }
                $hasExistingBalances = StockBalance::where('product_id', $pid)->exists();
                if (!$hasExistingBalances) {
                    $prod = Product::find($pid);
                    $curBal = ($vid === null && $prod) ? (float)$prod->stock_quantity : 0.0;
                } else {
                    $curBal = (float)($balQuery->value('quantity') ?? 0.0);
                }

                if ($needed > $curBal + 0.0001) {
                    $pName = $sub['product_name'];
                    $outletObj = Outlet::find($warehouseId);
                    $outletName = $outletObj ? $outletObj->name : $validated['warehouse_name'];
                    return response()->json([
                        'status' => 'error',
                        'message' => "လက်ကျန်စတော့ထက် ပိုမိုနုတ်၍မရပါ: {$outletName} တွင် '{$pName}' စုစုပေါင်း လက်ကျန် {$curBal} သာရှိသော်လည်း စုစုပေါင်း {$needed} နုတ်ရန် တောင်းဆိုထားပါသည်။",
                    ], 422);
                }
            }
        }

        DB::beginTransaction();
        try {
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

                $resolvedProductId = $itemData['product_id'] ?? null;
                if (!$resolvedProductId && !empty($itemData['product_code'])) {
                    $prod = Product::where('code', $itemData['product_code'])->first();
                    $resolvedProductId = $prod?->id;
                }

                $resolvedVariantId = $itemData['product_variant_id'] ?? null;
                $variantName = $this->resolveVariantDisplayName($itemData['variant_name'] ?? null, $resolvedVariantId);

                $adjItem = StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $resolvedProductId,
                    'product_variant_id' => $resolvedVariantId,
                    'product_code' => $itemData['product_code'],
                    'product_name' => $itemData['product_name'],
                    'variant_name' => $variantName,
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

                // FIFO Batch integration for additions and subtractions
                if ($resolvedProductId) {
                    $fifoService = app(\App\Services\FifoInventoryService::class);
                    if ($adjItem->type === 'Addition') {
                        $fifoService->recordBatch(
                            warehouseId: (int)$warehouseId,
                            productId: (int)$resolvedProductId,
                            variantId: $adjItem->product_variant_id,
                            baseQuantity: (float)$baseQty,
                            baseUnitCost: (float)$baseCost,
                            sourceType: 'adjustment_addition',
                            sourceReference: (string)$refNo,
                            receivedDate: (string)$adjustment->date,
                            notes: $validated['notes'] ?? 'Stock adjustment addition'
                        );
                    } elseif ($adjItem->type === 'Subtraction') {
                        $fifoService->depleteForAdjustment(
                            warehouseId: (int)$warehouseId,
                            productId: (int)$resolvedProductId,
                            variantId: $adjItem->product_variant_id,
                            baseQty: (float)$baseQty,
                            referenceNo: (string)$refNo,
                            fallbackCost: (float)$baseCost
                        );
                    }
                }
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
        $adjustment = StockAdjustment::with([
            'user:id,name,role',
            'items.variant.values',
            'items.product',
        ])->find($id);

        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }
        if ($adjustment->user) {
            $adjustment->created_by = $adjustment->user->name;
            if (!empty($adjustment->user->role)) {
                $adjustment->user_role = $adjustment->user->role;
            }
        }
        $this->enrichAdjustmentItems($adjustment);

        return response()->json(['status' => 'success', 'data' => $adjustment]);
    }

    public function update(Request $request, $id)
    {
        $adjustment = StockAdjustment::with('items')->find($id);
        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }

        $validated = $request->validate([
            'reference_no' => ['nullable', 'string', 'max:255', Rule::unique('stock_adjustments', 'reference_no')->ignore($id)],
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

            // 2. Senior Accounting Validation: Verify total subtraction deductions against restored balance
            $subtractionsByItem = [];
            foreach ($validated['items'] as $item) {
                $itemType = $item['type'] ?? ($validated['type'] ?? $adjustment->type);
                if ($itemType === 'Subtraction') {
                    $pid = $item['product_id'] ?? null;
                    if (!$pid && !empty($item['product_code'])) {
                        $p = Product::where('code', $item['product_code'])->orWhere('sku', $item['product_code'])->first();
                        $pid = $p?->id;
                    }
                    $vid = $item['product_variant_id'] ?? null;
                    $key = ($pid ?? 'p0') . '_' . ($vid ? "var_{$vid}" : 'single');
                    $cRate = isset($item['conversion_rate']) ? (float)$item['conversion_rate'] : 1.0;
                    $qty = (float)$item['quantity'];
                    $baseQty = isset($item['base_quantity']) ? (float)$item['base_quantity'] : ($qty * ($cRate > 0 ? $cRate : 1.0));

                    if (!isset($subtractionsByItem[$key])) {
                        $subtractionsByItem[$key] = [
                            'product_id' => $pid,
                            'variant_id' => $vid,
                            'product_name' => $item['product_name'] ?? 'Product',
                            'total_base_qty' => 0.0,
                        ];
                    }
                    $subtractionsByItem[$key]['total_base_qty'] += $baseQty;
                }
            }

            foreach ($subtractionsByItem as $sub) {
                $pid = $sub['product_id'];
                $vid = $sub['variant_id'];
                $needed = $sub['total_base_qty'];

                if ($pid) {
                    $balQuery = StockBalance::where('warehouse_id', $newWarehouseId)->where('product_id', $pid);
                    if ($vid && $vid > 0) {
                        $balQuery->where('product_variant_id', $vid);
                    } else {
                        $balQuery->whereNull('product_variant_id');
                    }
                    $hasExistingBalances = StockBalance::where('product_id', $pid)->exists();
                    if (!$hasExistingBalances) {
                        $prod = Product::find($pid);
                        $curBal = ($vid === null && $prod) ? (float)$prod->stock_quantity : 0.0;
                    } else {
                        $curBal = (float)($balQuery->value('quantity') ?? 0.0);
                    }

                    if ($needed > $curBal + 0.0001) {
                        $pName = $sub['product_name'];
                        $outletObj = Outlet::find($newWarehouseId);
                        $outletName = $outletObj ? $outletObj->name : $validated['warehouse_name'];
                        throw new \Exception("လက်ကျန်စတော့ထက် ပိုမိုနုတ်၍မရပါ: {$outletName} တွင် '{$pName}' စုစုပေါင်း လက်ကျန် {$curBal} သာရှိသော်လည်း စုစုပေါင်း {$needed} နုတ်ရန် တောင်းဆိုထားပါသည်။");
                    }
                }
            }

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
            if (!empty($validated['reference_no'])) {
                $updateData['reference_no'] = $validated['reference_no'];
            }
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

                $resolvedVariantId = $itemData['product_variant_id'] ?? null;
                $variantName = $this->resolveVariantDisplayName($itemData['variant_name'] ?? null, $resolvedVariantId);

                $adjItem = StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $itemData['product_id'] ?? null,
                    'product_variant_id' => $resolvedVariantId,
                    'product_code' => $itemData['product_code'],
                    'product_name' => $itemData['product_name'],
                    'variant_name' => $variantName,
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
            $hasExistingBalances = StockBalance::where('product_id', $productId)->exists();
            $initial = ($variantId === null && !$hasExistingBalances) ? (float)$prod->stock_quantity : 0.0;
            $balance = StockBalance::create([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => ($variantId && $variantId > 0) ? $variantId : null,
                'quantity' => $initial,
            ]);
        }

        if ($isAddition) {
            $balance->increment('quantity', $effectiveQty);
        } else {
            if (!$isRollback && (float)$balance->quantity < $effectiveQty - 0.0001) {
                $avail = (float)$balance->quantity;
                $prodName = $prod->name;
                $outletObj = Outlet::find($warehouseId);
                $outletName = $outletObj ? $outletObj->name : "Warehouse #$warehouseId";
                throw new \Exception("လက်ကျန်စတော့ထက် ပိုမိုနုတ်၍မရပါ: {$outletName} တွင် '{$prodName}' လက်ကျန် {$avail} သာရှိသော်လည်း {$effectiveQty} နုတ်ရန် ကြိုးစားနေပါသည်။ Accounting rules strictly prohibit negative outlet inventory balances.");
            }
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

    /**
     * Resolves human-readable variation display string (e.g., 'Size: 32, Color: Red')
     */
    private function resolveVariantDisplayName(?string $providedName, ?int $variantId): ?string
    {
        $name = trim($providedName ?? '');
        if (!empty($name) && str_contains($name, ':')) {
            return $name;
        }

        if (!empty($variantId) && $variantId > 0) {
            $pv = ProductVariant::with('values')->find($variantId);
            if ($pv && $pv->relationLoaded('values') && $pv->values->isNotEmpty()) {
                $parts = [];
                foreach ($pv->values as $val) {
                    $an = trim($val->attribute_name ?? '');
                    $av = trim($val->attribute_value ?? '');
                    if (!empty($an) && !empty($av)) {
                        $parts[] = "{$an}: {$av}";
                    } elseif (!empty($av)) {
                        $parts[] = $av;
                    }
                }
                if (!empty($parts)) {
                    return implode(', ', $parts);
                }
            }
            if ($pv && !empty($pv->name)) {
                return $pv->name;
            }
        }

        return !empty($name) ? $name : null;
    }

    /**
     * Enriches adjustment items with accurate product info, variation name, and attribute display
     */
    private function enrichAdjustmentItems($adj): void
    {
        if (!$adj || !$adj->relationLoaded('items') || !$adj->items) {
            return;
        }

        foreach ($adj->items as $item) {
            // 1. Ensure product name
            if (empty($item->product_name) && $item->product) {
                $item->product_name = $item->product->name;
            }

            // 2. Ensure product code (fallback to variant SKU/barcode or product code/sku)
            if (empty($item->product_code)) {
                if ($item->variant && (!empty($item->variant->sku) || !empty($item->variant->barcode))) {
                    $item->product_code = !empty($item->variant->sku) ? $item->variant->sku : $item->variant->barcode;
                } elseif ($item->product) {
                    $item->product_code = !empty($item->product->code) ? $item->product->code : ($item->product->sku ?? '');
                }
            }

            // 3. Enrich variation type and name
            if ($item->variant) {
                if ($item->variant->relationLoaded('values') && $item->variant->values && $item->variant->values->isNotEmpty()) {
                    $parts = [];
                    foreach ($item->variant->values as $val) {
                        $attrName = trim($val->attribute_name ?? '');
                        $attrVal = trim($val->attribute_value ?? '');
                        if (!empty($attrName) && !empty($attrVal)) {
                            $parts[] = "{$attrName}: {$attrVal}";
                        } elseif (!empty($attrVal)) {
                            $parts[] = $attrVal;
                        } elseif (!empty($attrName)) {
                            $parts[] = $attrName;
                        }
                    }
                    if (!empty($parts)) {
                        $item->variant_name = implode(', ', $parts);
                    }
                } elseif (empty($item->variant_name) && !empty($item->variant->name)) {
                    $item->variant_name = $item->variant->name;
                }
            }
        }
    }
}

