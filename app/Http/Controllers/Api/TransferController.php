<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    /**
     * Generate next transfer reference code (e.g. TR_1234567).
     */
    public function generateCode()
    {
        $prefix = Setting::get('transfer_prefix', 'TR_');
        $transfers = StockTransfer::select('reference_no')->orderBy('id', 'desc')->take(200)->get();
        $existingCodes = $transfers->pluck('reference_no')->filter()->toArray();

        do {
            $num = rand(1000000, 9999999);
            $candidate = $prefix . $num;
        } while (in_array($candidate, $existingCodes) || StockTransfer::where('reference_no', $candidate)->exists());

        return response()->json([
            'status' => 'success',
            'data'   => [
                'code'         => $candidate,
                'reference_no' => $candidate,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = StockTransfer::select([
            'id', 'reference_no', 'from_warehouse_id', 'from_warehouse',
            'to_warehouse_id', 'to_warehouse', 'date', 'transfer_time',
            'items_count', 'total_quantity', 'shipping_charge', 'status',
            'notes', 'created_by', 'user_id', 'user_name', 'user_role',
            'created_at', 'updated_at'
        ])->with([
            'user:id,name,role',
            'items.variant.values',
        ]);

        if ($request->filled('from_warehouse') && $request->input('from_warehouse') !== 'All') {
            $query->where('from_warehouse', $request->input('from_warehouse'));
        }

        if ($request->filled('to_warehouse') && $request->input('to_warehouse') !== 'All') {
            $query->where('to_warehouse', $request->input('to_warehouse'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('created_by') && $request->input('created_by') !== 'All') {
            $query->where('created_by', $request->input('created_by'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('from_warehouse', 'like', "%{$search}%")
                  ->orWhere('to_warehouse', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%");
            });
        }

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        if ($isAll) {
            $limit = min((int)$request->input('limit', 200), 500);
            $items = $query->orderBy('id', 'desc')->take($limit)->get();
            return response()->json([
                'status'     => 'success',
                'data'       => $items,
                'pagination' => [
                    'current_page' => 1,
                    'per_page'     => $items->count(),
                    'total'        => $items->count(),
                    'last_page'    => 1,
                ],
            ]);
        }

        $perPage = min(1000, max(1, (int)$request->input('per_page', 25)));
        $transfers = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'status'     => 'success',
            'data'       => $transfers->items(),
            'pagination' => [
                'current_page' => $transfers->currentPage(),
                'per_page'     => $transfers->perPage(),
                'total'        => $transfers->total(),
                'last_page'    => $transfers->lastPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $transfer = StockTransfer::with(['items.variant.values', 'user:id,name,role'])->find($id);
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $transfer]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no'               => 'nullable|string|max:255|unique:stock_transfers,reference_no',
            'from_warehouse_id'          => 'nullable|integer',
            'from_warehouse'             => 'required|string|max:255',
            'to_warehouse_id'            => 'nullable|integer',
            'to_warehouse'               => 'required|string|max:255|different:from_warehouse',
            'date'                       => 'required|string',
            'transfer_time'              => 'nullable|string|max:50',
            'shipping_charge'            => 'nullable|numeric|min:0',
            'status'                     => 'nullable|string|in:Sent,Pending,Completed',
            'notes'                      => 'nullable|string',
            'created_by'                 => 'nullable|string|max:255',
            'user_id'                    => 'nullable|integer',
            'user_name'                  => 'nullable|string|max:255',
            'user_role'                  => 'nullable|string|max:100',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.product_code'       => 'required|string',
            'items.*.product_name'       => 'required|string',
            'items.*.variant_name'       => 'nullable|string|max:255',
            'items.*.unit_id'            => 'nullable|integer',
            'items.*.unit_name'          => 'nullable|string|max:100',
            'items.*.conversion_rate'    => 'nullable|numeric|min:0.0001',
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.base_quantity'      => 'nullable|numeric|min:0',
            'items.*.unit_cost'          => 'nullable|numeric|min:0',
            'items.*.base_unit_cost'     => 'nullable|numeric|min:0',
            'items.*.subtotal'           => 'nullable|numeric|min:0',
        ]);

        $unitService     = app(\App\Services\ProductUnitConversionService::class);
        $fifoService     = app(\App\Services\FifoInventoryService::class);
        $fromWarehouseId = $unitService->warehouseId($validated['from_warehouse_id'] ?? null, $validated['from_warehouse']);
        $toWarehouseId   = $unitService->warehouseId($validated['to_warehouse_id'] ?? null, $validated['to_warehouse']);
        $transferStatus  = $validated['status'] ?? 'Completed';

        // ── T3: Cumulative Multi-Unit Available Stock Validation ──────────────
        // Before creating the transfer, verify that the cumulative base quantity
        // across ALL packaging unit lines for each product/variant does not exceed
        // available stock in the source warehouse.
        if (in_array($transferStatus, ['Completed', 'Sent']) && $fromWarehouseId) {
            $cumulativeNeeded = [];
            $itemInfo = [];

            foreach ($validated['items'] as $item) {
                $productId = $item['product_id'] ?? null;
                if (!$productId && !empty($item['product_code'])) {
                    $prod = Product::where('code', $item['product_code'])->first();
                    $productId = $prod?->id;
                }
                $variantId = !empty($item['product_variant_id']) ? (int)$item['product_variant_id'] : null;
                $convRate  = (float)($item['conversion_rate'] ?? 1.0);
                $qty       = (float)$item['quantity'];
                $baseQty   = isset($item['base_quantity']) && (float)$item['base_quantity'] > 0
                    ? (float)$item['base_quantity']
                    : ($qty * max($convRate, 0.0001));

                $key = ($productId ?: $item['product_code']) . '_' . ($variantId ?? '0');
                if (!isset($cumulativeNeeded[$key])) {
                    $cumulativeNeeded[$key] = 0.0;
                    $itemInfo[$key] = [
                        'product_id'   => $productId,
                        'variant_id'   => $variantId,
                        'product_name' => $item['product_name'] ?? ($item['product_code'] ?? 'Product'),
                    ];
                }
                $cumulativeNeeded[$key] += $baseQty;
            }

            $insufficientItems = [];
            foreach ($cumulativeNeeded as $key => $neededBaseQty) {
                $info = $itemInfo[$key];
                $productId = $info['product_id'];
                $variantId = $info['variant_id'];

                if ($productId && $neededBaseQty > 0) {
                    $query = DB::table('stock_balances')
                        ->where('product_id', $productId)
                        ->where('warehouse_id', $fromWarehouseId);
                    if ($variantId !== null) {
                        $query->where('product_variant_id', $variantId);
                    } else {
                        $query->whereNull('product_variant_id');
                    }
                    $stockBalance = $query->value('quantity');

                    if ($stockBalance === null) {
                        $stockBalance = DB::table('product_warehouse')
                            ->where('product_id', $productId)
                            ->where('warehouse_id', $fromWarehouseId)
                            ->value('quantity');
                    }

                    if ($stockBalance === null) {
                        $hasBalances = DB::table('stock_balances')->where('product_id', $productId)->exists();
                        $prod = Product::find($productId);
                        $stockBalance = (!$hasBalances && $variantId === null && $prod) ? $prod->stock_quantity : 0;
                    }

                    $available = (float)($stockBalance ?? 0);
                    if ($available < ($neededBaseQty - 0.0001)) {
                        $insufficientItems[] = sprintf(
                            '%s: Total Base Needed %.4f, Available %.4f',
                            $info['product_name'],
                            $neededBaseQty,
                            $available
                        );
                    }
                }
            }

            if (!empty($insufficientItems)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Insufficient stock in source warehouse for: ' . implode('; ', $insufficientItems),
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $prefix   = Setting::get('transfer_prefix', 'TR_');
            $refNo    = !empty($validated['reference_no'])
                ? $validated['reference_no']
                : ($prefix . rand(1000000, 9999999));
            $totalQty = 0;

            foreach ($validated['items'] as $item) {
                $totalQty += (float)$item['quantity'];
            }

            $txDate = trim($validated['date']);
            if (!str_contains($txDate, ':')) {
                $txDate .= ' ' . now()->format('H:i:s');
            }

            $authUserName = $request->user()?->name;
            $authUserRole = $request->user()?->role;
            $authUserId   = $request->user()?->id;

            $effectiveUserName = !empty($validated['user_name'])
                ? $validated['user_name']
                : (!empty($validated['created_by']) ? $validated['created_by'] : ($authUserName ?? 'Admin User'));
            $effectiveUserRole = !empty($validated['user_role'])
                ? $validated['user_role']
                : ($authUserRole ?? 'Super Admin');
            $effectiveUserId = !empty($validated['user_id'])
                ? $validated['user_id']
                : $authUserId;

            $transfer = StockTransfer::create([
                'reference_no'     => $refNo,
                'from_warehouse_id'=> $fromWarehouseId,
                'from_warehouse'   => $validated['from_warehouse'],
                'to_warehouse_id'  => $toWarehouseId,
                'to_warehouse'     => $validated['to_warehouse'],
                'date'             => $txDate,
                'transfer_time'    => $validated['transfer_time'] ?? date('h:i A'),
                'items_count'      => count($validated['items']),
                'total_quantity'   => $totalQty,
                'shipping_charge'  => (float)($validated['shipping_charge'] ?? 0),
                'status'           => $transferStatus,
                'notes'            => $validated['notes'] ?? null,
                'created_by'       => $effectiveUserName,
                'user_id'          => $effectiveUserId,
                'user_name'        => $effectiveUserName,
                'user_role'        => $effectiveUserRole,
            ]);

            foreach ($validated['items'] as $item) {
                $convRate = (float)($item['conversion_rate'] ?? 1.0);
                $qty      = (float)$item['quantity'];
                $baseQty  = isset($item['base_quantity']) && (float)$item['base_quantity'] > 0
                    ? (float)$item['base_quantity']
                    : ($qty * $convRate);
                $unitCost = (float)($item['unit_cost'] ?? 0);
                $baseCost = isset($item['base_unit_cost']) && (float)$item['base_unit_cost'] > 0
                    ? (float)$item['base_unit_cost']
                    : ($convRate > 0 ? $unitCost / $convRate : $unitCost);

                $createdItem = StockTransferItem::create([
                    'stock_transfer_id'  => $transfer->id,
                    'product_id'         => $item['product_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'product_code'       => $item['product_code'],
                    'product_name'       => $item['product_name'],
                    'variant_name'       => $this->resolveVariantName($item),
                    'unit_id'            => $item['unit_id'] ?? null,
                    'unit_name'          => $item['unit_name'] ?? 'Pcs',
                    'conversion_rate'    => $convRate,
                    'quantity'           => $qty,
                    'base_quantity'      => $baseQty,
                    'unit_cost'          => $unitCost,
                    'base_unit_cost'     => $baseCost,
                    'subtotal'           => (float)($item['subtotal'] ?? ($qty * $unitCost)),
                ]);

                // Physically execute stock movement if Completed or Sent
                if (in_array($transferStatus, ['Completed', 'Sent']) && $baseQty > 0 && $fromWarehouseId) {
                    $productId = $item['product_id'] ?? null;
                    $variantId = !empty($item['product_variant_id']) ? (int)$item['product_variant_id'] : null;
                    $product   = $productId
                        ? Product::find($productId)
                        : Product::where('code', $item['product_code'])->first();

                    if ($product) {
                        // 1. Deduct stock from source warehouse
                        $unitService->applyStock(
                            $product, $fromWarehouseId, $variantId, $baseQty,
                            'out', 'stock_transfer_out', 'stock_transfer',
                            (string)$transfer->id, 'Transferred to ' . $validated['to_warehouse']
                        );

                        // 2. Deplete FIFO batch from source warehouse (carry real cost)
                        $totalLossCost       = $fifoService->depleteForAdjustment(
                            warehouseId:  (int)$fromWarehouseId,
                            productId:    (int)$product->id,
                            variantId:    $variantId,
                            baseQty:      (float)$baseQty,
                            referenceNo:  (string)$transfer->reference_no,
                            fallbackCost: (float)$baseCost
                        );
                        $transferredUnitCost = $baseQty > 0 ? ($totalLossCost / $baseQty) : $baseCost;
                        if ($transferredUnitCost <= 0) {
                            $transferredUnitCost = $baseCost;
                        }

                        $createdItem->update(['base_unit_cost' => $transferredUnitCost]);

                        // 3. If Completed immediately (single-step), add stock & batch to destination warehouse
                        if ($transferStatus === 'Completed' && $toWarehouseId) {
                            $unitService->applyStock(
                                $product, $toWarehouseId, $variantId, $baseQty,
                                'in', 'stock_transfer_in', 'stock_transfer',
                                (string)$transfer->id, 'Transferred from ' . $validated['from_warehouse']
                            );

                            $fifoService->recordBatch(
                                warehouseId:     (int)$toWarehouseId,
                                productId:       (int)$product->id,
                                variantId:       $variantId,
                                baseQuantity:    (float)$baseQty,
                                baseUnitCost:    (float)$transferredUnitCost,
                                sourceType:      'stock_transfer',
                                sourceReference: (string)$transfer->reference_no,
                                receivedDate:    (string)$transfer->date,
                                notes:           'Transfer replenishment from ' . $validated['from_warehouse']
                            );
                        }
                    }
                }
            }

            // ── T2: Auto-create Expense for Shipping Charge ───────────────────
            // Shipping cost is a real operating expense that must appear in the P&L.
            // We auto-create an Expense record so the GL is complete.
            $shippingCharge = (float)($validated['shipping_charge'] ?? 0);
            if ($shippingCharge > 0) {
                $shippingPrefix = Setting::get('expense_prefix', 'EXP_');
                Expense::create([
                    'reference_no'          => $shippingPrefix . 'TRF-' . $refNo,
                    'warehouse_id'          => $fromWarehouseId,
                    'warehouse_name'        => $validated['from_warehouse'],
                    'expense_category_name' => 'Transfer Shipping',
                    'title'                 => 'Shipping for Transfer #' . $refNo . ' → ' . $validated['to_warehouse'],
                    'amount'                => $shippingCharge,
                    'payment_method'        => 'Cash',
                    'date'                  => $txDate,
                    'details'               => 'Auto-created from Stock Transfer #' . $refNo,
                    'created_by'            => $validated['created_by'] ?? ($validated['user_name'] ?? 'System'),
                    'user_name'             => $validated['user_name'] ?? null,
                    'user_role'             => $validated['user_role'] ?? 'Super Admin',
                    'user_id'               => $validated['user_id'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Stock transfer recorded successfully',
                'data'    => $transfer->load('items'),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $transfer = StockTransfer::with('items')->find($id);
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }

        $validated = $request->validate([
            'reference_no'               => ['nullable', 'string', 'max:255', Rule::unique('stock_transfers', 'reference_no')->ignore($id)],
            'from_warehouse_id'          => 'nullable|integer',
            'from_warehouse'             => 'required|string|max:255',
            'to_warehouse_id'            => 'nullable|integer',
            'to_warehouse'               => 'required|string|max:255|different:from_warehouse',
            'date'                       => 'required|string',
            'transfer_time'              => 'nullable|string|max:50',
            'shipping_charge'            => 'nullable|numeric|min:0',
            'status'                     => 'nullable|string|in:Sent,Pending,Completed',
            'notes'                      => 'nullable|string',
            'created_by'                 => 'nullable|string|max:255',
            'user_id'                    => 'nullable|integer',
            'user_name'                  => 'nullable|string|max:255',
            'user_role'                  => 'nullable|string|max:100',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.product_code'       => 'required|string',
            'items.*.product_name'       => 'required|string',
            'items.*.variant_name'       => 'nullable|string|max:255',
            'items.*.unit_id'            => 'nullable|integer',
            'items.*.unit_name'          => 'nullable|string|max:100',
            'items.*.conversion_rate'    => 'nullable|numeric|min:0.0001',
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.base_quantity'      => 'nullable|numeric|min:0',
            'items.*.unit_cost'          => 'nullable|numeric|min:0',
            'items.*.base_unit_cost'     => 'nullable|numeric|min:0',
            'items.*.subtotal'           => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $unitService     = app(\App\Services\ProductUnitConversionService::class);
            $fifoService     = app(\App\Services\FifoInventoryService::class);
            $fromWarehouseId = $unitService->warehouseId($validated['from_warehouse_id'] ?? null, $validated['from_warehouse']);
            $toWarehouseId   = $unitService->warehouseId($validated['to_warehouse_id'] ?? null, $validated['to_warehouse']);
            $newStatus       = $validated['status'] ?? $transfer->status;

            // ── T1: Reverse Previous Stock Impact Before Re-applying ──────────
            // If the original transfer was Completed, we must first undo its
            // inventory effect. Without this, stock would be counted twice.
            if ($transfer->status === 'Completed') {
                $origFromId = $unitService->warehouseId($transfer->from_warehouse_id, $transfer->from_warehouse);
                $origToId   = $unitService->warehouseId($transfer->to_warehouse_id, $transfer->to_warehouse);

                foreach ($transfer->items as $oldItem) {
                    $oldBaseQty = (float)($oldItem->base_quantity ?? ($oldItem->quantity * ($oldItem->conversion_rate ?? 1.0)));
                    if ($oldBaseQty > 0 && $origFromId && $origToId) {
                        $product = $oldItem->product_id
                            ? Product::find($oldItem->product_id)
                            : Product::where('code', $oldItem->product_code)->first();
                        if ($product) {
                            // Restore stock to source warehouse (reverse the 'out')
                            $unitService->applyStock(
                                $product, $origFromId, $oldItem->product_variant_id, $oldBaseQty,
                                'in', 'stock_transfer_reversal_in', 'stock_transfer_reversal',
                                (string)$transfer->id, 'Update reversal: restoring to ' . $transfer->from_warehouse
                            );
                            // Remove stock from destination warehouse (reverse the 'in')
                            $unitService->applyStock(
                                $product, $origToId, $oldItem->product_variant_id, $oldBaseQty,
                                'out', 'stock_transfer_reversal_out', 'stock_transfer_reversal',
                                (string)$transfer->id, 'Update reversal: removing from ' . $transfer->to_warehouse
                            );
                        }
                    }
                }
            }

            // ── T3: Available Cumulative Stock Validation ─────────────────────
            if (in_array($newStatus, ['Completed', 'Sent']) && $fromWarehouseId) {
                $cumulativeNeeded = [];
                $itemInfo = [];

                foreach ($validated['items'] as $item) {
                    $productId = $item['product_id'] ?? null;
                    if (!$productId && !empty($item['product_code'])) {
                        $prod = Product::where('code', $item['product_code'])->first();
                        $productId = $prod?->id;
                    }
                    $variantId = !empty($item['product_variant_id']) ? (int)$item['product_variant_id'] : null;
                    $convRate  = (float)($item['conversion_rate'] ?? 1.0);
                    $qty       = (float)$item['quantity'];
                    $baseQty   = isset($item['base_quantity']) && (float)$item['base_quantity'] > 0
                        ? (float)$item['base_quantity']
                        : ($qty * max($convRate, 0.0001));

                    $key = ($productId ?: $item['product_code']) . '_' . ($variantId ?? '0');
                    if (!isset($cumulativeNeeded[$key])) {
                        $cumulativeNeeded[$key] = 0.0;
                        $itemInfo[$key] = [
                            'product_id'   => $productId,
                            'variant_id'   => $variantId,
                            'product_name' => $item['product_name'] ?? ($item['product_code'] ?? 'Product'),
                        ];
                    }
                    $cumulativeNeeded[$key] += $baseQty;
                }

                $insufficientItems = [];
                foreach ($cumulativeNeeded as $key => $neededBaseQty) {
                    $info = $itemInfo[$key];
                    $productId = $info['product_id'];
                    $variantId = $info['variant_id'];

                    if ($productId && $neededBaseQty > 0) {
                        $query = DB::table('stock_balances')
                            ->where('product_id', $productId)
                            ->where('warehouse_id', $fromWarehouseId);
                        if ($variantId !== null) {
                            $query->where('product_variant_id', $variantId);
                        } else {
                            $query->whereNull('product_variant_id');
                        }
                        $stockBalance = $query->value('quantity');

                        if ($stockBalance === null) {
                            $stockBalance = DB::table('product_warehouse')
                                ->where('product_id', $productId)
                                ->where('warehouse_id', $fromWarehouseId)
                                ->value('quantity');
                        }

                        if ($stockBalance === null) {
                            $hasBalances = DB::table('stock_balances')->where('product_id', $productId)->exists();
                            $prod = Product::find($productId);
                            $stockBalance = (!$hasBalances && $variantId === null && $prod) ? $prod->stock_quantity : 0;
                        }

                        $available = (float)($stockBalance ?? 0);
                        if ($available < ($neededBaseQty - 0.0001)) {
                            $insufficientItems[] = sprintf(
                                '%s: Total Base Needed %.4f, Available %.4f',
                                $info['product_name'],
                                $neededBaseQty,
                                $available
                            );
                        }
                    }
                }

                if (!empty($insufficientItems)) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Insufficient stock in source warehouse for: ' . implode('; ', $insufficientItems),
                    ], 422);
                }
            }

            // Remove old items; update header
            $transfer->items()->delete();

            $totalQty = 0;
            foreach ($validated['items'] as $item) {
                $totalQty += (float)$item['quantity'];
            }

            $authUserName = $request->user()?->name;
            $authUserRole = $request->user()?->role;
            $authUserId   = $request->user()?->id;

            $effectiveUserName = !empty($validated['user_name'])
                ? $validated['user_name']
                : (!empty($validated['created_by']) ? $validated['created_by'] : ($transfer->user_name ?? ($transfer->created_by ?? ($authUserName ?? 'Admin User'))));
            $effectiveUserRole = !empty($validated['user_role'])
                ? $validated['user_role']
                : ($transfer->user_role ?? ($authUserRole ?? 'Super Admin'));
            $effectiveUserId = !empty($validated['user_id'])
                ? $validated['user_id']
                : ($transfer->user_id ?? $authUserId);

            $transfer->update([
                'reference_no'      => $validated['reference_no'] ?? $transfer->reference_no,
                'from_warehouse_id' => $fromWarehouseId ?? $transfer->from_warehouse_id,
                'from_warehouse'    => $validated['from_warehouse'],
                'to_warehouse_id'   => $toWarehouseId ?? $transfer->to_warehouse_id,
                'to_warehouse'      => $validated['to_warehouse'],
                'date'              => $validated['date'],
                'transfer_time'     => $validated['transfer_time'] ?? $transfer->transfer_time,
                'items_count'       => count($validated['items']),
                'total_quantity'    => $totalQty,
                'shipping_charge'   => (float)($validated['shipping_charge'] ?? 0),
                'status'            => $newStatus,
                'notes'             => $validated['notes'] ?? $transfer->notes,
                'created_by'        => $effectiveUserName,
                'user_id'           => $effectiveUserId,
                'user_name'         => $effectiveUserName,
                'user_role'         => $effectiveUserRole,
            ]);

            // Re-apply new items + stock movements
            foreach ($validated['items'] as $item) {
                $convRate = (float)($item['conversion_rate'] ?? 1.0);
                $qty      = (float)$item['quantity'];
                $baseQty  = isset($item['base_quantity']) && (float)$item['base_quantity'] > 0
                    ? (float)$item['base_quantity']
                    : ($qty * $convRate);
                $unitCost = (float)($item['unit_cost'] ?? 0);
                $baseCost = isset($item['base_unit_cost']) && (float)$item['base_unit_cost'] > 0
                    ? (float)$item['base_unit_cost']
                    : ($convRate > 0 ? $unitCost / $convRate : $unitCost);

                StockTransferItem::create([
                    'stock_transfer_id'  => $transfer->id,
                    'product_id'         => $item['product_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'product_code'       => $item['product_code'],
                    'product_name'       => $item['product_name'],
                    'variant_name'       => $this->resolveVariantName($item),
                    'unit_id'            => $item['unit_id'] ?? null,
                    'unit_name'          => $item['unit_name'] ?? 'Pcs',
                    'conversion_rate'    => $convRate,
                    'quantity'           => $qty,
                    'base_quantity'      => $baseQty,
                    'unit_cost'          => $unitCost,
                    'base_unit_cost'     => $baseCost,
                    'subtotal'           => (float)($item['subtotal'] ?? ($qty * $unitCost)),
                ]);

                // Re-apply stock movements if new status is Completed
                if ($newStatus === 'Completed' && $baseQty > 0 && $fromWarehouseId && $toWarehouseId) {
                    $productId = $item['product_id'] ?? null;
                    $variantId = !empty($item['product_variant_id']) ? (int)$item['product_variant_id'] : null;
                    $product   = $productId
                        ? Product::find($productId)
                        : Product::where('code', $item['product_code'])->first();

                    if ($product) {
                        $unitService->applyStock(
                            $product, $fromWarehouseId, $variantId, $baseQty,
                            'out', 'stock_transfer_out', 'stock_transfer',
                            (string)$transfer->id, 'Updated transfer to ' . $validated['to_warehouse']
                        );
                        $unitService->applyStock(
                            $product, $toWarehouseId, $variantId, $baseQty,
                            'in', 'stock_transfer_in', 'stock_transfer',
                            (string)$transfer->id, 'Updated transfer from ' . $validated['from_warehouse']
                        );

                        $totalLossCost       = $fifoService->depleteForAdjustment(
                            warehouseId:  (int)$fromWarehouseId,
                            productId:    (int)$product->id,
                            variantId:    $variantId,
                            baseQty:      (float)$baseQty,
                            referenceNo:  (string)$transfer->reference_no,
                            fallbackCost: (float)$baseCost
                        );
                        $transferredUnitCost = $baseQty > 0 ? ($totalLossCost / $baseQty) : $baseCost;
                        if ($transferredUnitCost <= 0) {
                            $transferredUnitCost = $baseCost;
                        }

                        $fifoService->recordBatch(
                            warehouseId:     (int)$toWarehouseId,
                            productId:       (int)$product->id,
                            variantId:       $variantId,
                            baseQuantity:    (float)$baseQty,
                            baseUnitCost:    (float)$transferredUnitCost,
                            sourceType:      'stock_transfer',
                            sourceReference: (string)$transfer->reference_no,
                            receivedDate:    (string)$transfer->date,
                            notes:           'Updated transfer replenishment from ' . $validated['from_warehouse']
                        );
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Stock transfer updated successfully',
                'data'    => $transfer->load('items'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $transfer = StockTransfer::with('items')->find($id);
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }

        DB::beginTransaction();
        try {
            if ($transfer->status === 'Completed') {
                $unitService = app(\App\Services\ProductUnitConversionService::class);
                $fromWarehouseId = $unitService->warehouseId($transfer->from_warehouse_id, $transfer->from_warehouse);
                $toWarehouseId   = $unitService->warehouseId($transfer->to_warehouse_id, $transfer->to_warehouse);

                foreach ($transfer->items as $item) {
                    $baseQty = (float)($item->base_quantity ?? ($item->quantity * ($item->conversion_rate ?? 1.0)));
                    if ($baseQty > 0 && $fromWarehouseId && $toWarehouseId) {
                        $product = $item->product_id
                            ? Product::find($item->product_id)
                            : Product::where('code', $item->product_code)->first();
                        if ($product) {
                            // Revert from_warehouse: add back
                            $unitService->applyStock(
                                $product, $fromWarehouseId, $item->product_variant_id, $baseQty,
                                'in', 'stock_transfer_reversal_in', 'stock_transfer_reversal',
                                (string)$transfer->id, 'Reverted transfer to ' . $transfer->to_warehouse
                            );
                            // Revert to_warehouse: deduct
                            $unitService->applyStock(
                                $product, $toWarehouseId, $item->product_variant_id, $baseQty,
                                'out', 'stock_transfer_reversal_out', 'stock_transfer_reversal',
                                (string)$transfer->id, 'Reverted transfer from ' . $transfer->from_warehouse
                            );
                        }
                    }
                }
            } elseif ($transfer->status === 'Sent') {
                // Was in-transit (source stock was deducted upon dispatch), revert source deduction
                $unitService = app(\App\Services\ProductUnitConversionService::class);
                $fromWarehouseId = $unitService->warehouseId($transfer->from_warehouse_id, $transfer->from_warehouse);
                foreach ($transfer->items as $item) {
                    $baseQty = (float)($item->base_quantity ?? ($item->quantity * ($item->conversion_rate ?? 1.0)));
                    if ($baseQty > 0 && $fromWarehouseId) {
                        $product = $item->product_id
                            ? Product::find($item->product_id)
                            : Product::where('code', $item->product_code)->first();
                        if ($product) {
                            $unitService->applyStock(
                                $product, $fromWarehouseId, $item->product_variant_id, $baseQty,
                                'in', 'stock_transfer_reversal_in', 'stock_transfer_reversal',
                                (string)$transfer->id, 'Cancelled in-transit transfer to ' . $transfer->to_warehouse
                            );
                        }
                    }
                }
            }

            $transfer->items()->delete();
            $transfer->delete();

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Transfer deleted successfully and stock restored']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Confirm and receive an In-Transit (Sent) transfer at the destination branch.
     * Physically replenishes inventory and logs FIFO receipt layer.
     */
    public function receiveTransfer(Request $request, $id)
    {
        $transfer = StockTransfer::with('items')->find($id);
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }

        if ($transfer->status === 'Completed') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Transfer is already marked as Completed.',
            ], 422);
        }

        if ($transfer->status !== 'Sent') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Only In-Transit (Sent) transfers can be received into destination inventory.',
            ], 422);
        }

        $validated = $request->validate([
            'received_by'     => 'nullable|string|max:255',
            'receiving_notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $unitService   = app(\App\Services\ProductUnitConversionService::class);
            $fifoService   = app(\App\Services\FifoInventoryService::class);
            $toWarehouseId = $unitService->warehouseId($transfer->to_warehouse_id, $transfer->to_warehouse);

            foreach ($transfer->items as $item) {
                $baseQty   = (float)($item->base_quantity ?? ($item->quantity * ($item->conversion_rate ?? 1.0)));
                $baseCost  = (float)($item->base_unit_cost ?? ($item->unit_cost ?? 0));
                $variantId = !empty($item->product_variant_id) ? (int)$item->product_variant_id : null;

                if ($baseQty > 0 && $toWarehouseId) {
                    $product = $item->product_id
                        ? Product::find($item->product_id)
                        : Product::where('code', $item->product_code)->first();

                    if ($product) {
                        // 1. Add stock to destination warehouse
                        $unitService->applyStock(
                            $product, $toWarehouseId, $variantId, $baseQty,
                            'in', 'stock_transfer_in', 'stock_transfer',
                            (string)$transfer->id, 'Transfer received from ' . $transfer->from_warehouse
                        );

                        // 2. Record FIFO replenishment batch in destination warehouse
                        $fifoService->recordBatch(
                            warehouseId:     (int)$toWarehouseId,
                            productId:       (int)$product->id,
                            variantId:       $variantId,
                            baseQuantity:    (float)$baseQty,
                            baseUnitCost:    (float)$baseCost,
                            sourceType:      'stock_transfer',
                            sourceReference: (string)$transfer->reference_no,
                            receivedDate:    now()->toDateTimeString(),
                            notes:           'Transfer replenishment from ' . $transfer->from_warehouse . (!empty($validated['receiving_notes']) ? ' (' . $validated['receiving_notes'] . ')' : '')
                        );
                    }
                }
            }

            $receiverName = $validated['received_by'] ?? ($request->user()?->name ?? 'Branch Manager');

            $transfer->update([
                'status'          => 'Completed',
                'received_by'     => $receiverName,
                'received_at'     => now(),
                'receiving_notes' => $validated['receiving_notes'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Stock transfer received and verified successfully. Goods added to destination inventory.',
                'data'    => $transfer->fresh()->load('items'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Resolve rich variation name with attribute type and values.
     * E.g. "Cyan (Color: Cyan, Size: 44)" or "Size: 32, Color: Red"
     */
    protected function resolveVariantName(array $item): ?string
    {
        $vName = isset($item['variant_name']) ? trim((string)$item['variant_name']) : '';
        $pvId  = $item['product_variant_id'] ?? null;

        if (!empty($pvId)) {
            $pv = ProductVariant::with('values')->find($pvId);
            if ($pv) {
                $valParts = [];
                if (!empty($pv->values)) {
                    foreach ($pv->values as $val) {
                        $an = trim($val->attribute_name ?? '');
                        $av = trim($val->attribute_value ?? '');
                        if (!empty($an) && !empty($av)) {
                            $valParts[] = "$an: $av";
                        } elseif (!empty($av)) {
                            $valParts[] = $av;
                        }
                    }
                }
                $attrStr = implode(', ', $valParts);
                $pvName  = trim($pv->name ?? '');

                if ($vName !== '') {
                    if (!empty($attrStr) && !str_contains($vName, ':')) {
                        return "$vName ($attrStr)";
                    }
                    return $vName;
                }

                if (!empty($pvName) && !empty($attrStr) && !str_contains($pvName, ':')) {
                    return "$pvName ($attrStr)";
                } elseif (!empty($attrStr)) {
                    return $attrStr;
                } elseif (!empty($pvName)) {
                    return $pvName;
                }
            }
        }

        return $vName !== '' ? $vName : null;
    }
}

