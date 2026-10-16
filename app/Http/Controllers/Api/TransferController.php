<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
            $cleanSearch = preg_replace('/[-_ ]/', '', $search);
            $query->where(function ($q) use ($search, $cleanSearch) {
                $q->where('reference_no', 'like', "%{$search}%");
                if (!empty($cleanSearch)) {
                    $q->orWhereRaw("REPLACE(REPLACE(reference_no, '_', ''), '-', '') LIKE ?", ["%{$cleanSearch}%"]);
                }
                $q->orWhere('from_warehouse', 'like', "%{$search}%")
                  ->orWhere('to_warehouse', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%");
            });
        }

        $attachExpensePayments = function($items) {
            if ($items->isEmpty()) return $items;

            $shippingItems = $items->filter(function($t) {
                return (float)($t->shipping_charge ?? 0) > 0;
            });

            if ($shippingItems->isEmpty()) {
                return $items->map(function($t) {
                    $arr = $t->toArray();
                    $arr['payments'] = [];
                    $arr['shipping_paid'] = 0.0;
                    $arr['shipping_due'] = (float)$t->shipping_charge;
                    $arr['shipping_payment_status'] = $t->shipping_charge <= 0 ? 'Free' : 'Unpaid';
                    $arr['payment_method'] = 'Cash';
                    return $arr;
                });
            }

            $refs = $shippingItems->pluck('reference_no')->filter()->values()->all();
            if (empty($refs)) return $items;

            // Senior Backend Performance: Scoped batch lookups replacing 2,500 LIKE conditions
            $candidateExpenseRefs = [];
            foreach ($refs as $r) {
                $clean = preg_replace('/^(?:TR_|TR-)/', '', $r);
                $candidateExpenseRefs[] = $r;
                $candidateExpenseRefs[] = 'EXP_TRF-' . $r;
                $candidateExpenseRefs[] = 'EXP-TRF-' . $r;
                $candidateExpenseRefs[] = 'TRF-' . $r;
                $candidateExpenseRefs[] = $clean;
                $candidateExpenseRefs[] = 'EXP_TRF-' . $clean;
                $candidateExpenseRefs[] = 'TRF-' . $clean;
            }
            $candidateExpenseRefs = array_values(array_unique(array_filter($candidateExpenseRefs)));

            $expenses = Expense::with('payments')
                ->whereIn('reference_no', $candidateExpenseRefs)
                ->get();

            $expenseMap = [];
            foreach ($expenses as $e) {
                // 1. Match from details e.g. "Auto-created from Stock Transfer #TR_8003399"
                if (preg_match('/#([^\s,→]+)/', $e->details ?? '', $m)) {
                    $expenseMap[$m[1]] = $e;
                    $clean = preg_replace('/^(?:TR_|TR-)/', '', $m[1]);
                    $expenseMap[$clean] = $e;
                }
                // 2. Match from title e.g. "Shipping for Transfer #TR_8003399 → Downtown Bistro Outlet"
                if (preg_match('/#([^\s,→]+)/', $e->title ?? '', $m)) {
                    $expenseMap[$m[1]] = $e;
                    $clean = preg_replace('/^(?:TR_|TR-)/', '', $m[1]);
                    $expenseMap[$clean] = $e;
                }
                // 3. Match from reference_no with various prefixes (EXP_TRF-, EXP-TRF-, TRF-, EXP-, etc.)
                if (preg_match('/(?:TRF-|EXP-|EXP_)(.+)$/', $e->reference_no, $m)) {
                    $expenseMap[$m[1]] = $e;
                    $clean = preg_replace('/^(?:TR_|TR-)/', '', $m[1]);
                    $expenseMap[$clean] = $e;
                }
                $expenseMap[$e->reference_no] = $e;
            }

            return $items->map(function($t) use ($expenseMap) {
                $arr = $t->toArray();
                $ref = $t->reference_no;
                $clean = preg_replace('/^(?:TR_|TR-)/', '', $ref);
                $exp = $expenseMap[$ref] ?? ($expenseMap[$clean] ?? ($expenseMap['EXP_TRF-' . $ref] ?? null));

                $arr['payments'] = $exp ? ($exp->payments ?? []) : [];
                $arr['shipping_paid'] = $exp ? (float)$exp->paid_amount : 0.0;
                $arr['shipping_due'] = $exp ? (float)$exp->due_amount : (float)$t->shipping_charge;
                $arr['shipping_payment_status'] = $exp ? $exp->payment_status : ($t->shipping_charge <= 0 ? 'Free' : 'Unpaid');
                $arr['payment_method'] = $exp ? $exp->payment_method : 'Cash';
                return $arr;
            });
        };

        $sortBy = $request->input('sort_by', 'date');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'reference_no', 'from_warehouse', 'to_warehouse', 'date', 'items_count', 'total_quantity', 'shipping_charge', 'status', 'created_by', 'created_at'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'date';
        }

        $orderedQuery = $query->orderBy($sortBy, $sortOrder);
        if ($sortBy !== 'id') {
            $orderedQuery->orderBy('id', $sortOrder);
        }

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        if ($isAll) {
            $totalCount = (clone $query)->count();
            $limit = min((int)$request->input('limit', 500), 500);
            $items = $orderedQuery->take($limit)->get();
            $dataWithPayments = $attachExpensePayments($items);
            return response()->json([
                'status'     => 'success',
                'data'       => $dataWithPayments,
                'pagination' => [
                    'current_page' => 1,
                    'per_page'     => $items->count(),
                    'total'        => $totalCount,
                    'last_page'    => (int)ceil($totalCount / max(1, $limit)),
                ],
            ]);
        }

        $perPage = min(1000, max(1, (int)$request->input('per_page', 25)));
        $transfers = $orderedQuery->paginate($perPage);
        $dataWithPayments = $attachExpensePayments(collect($transfers->items()));

        return response()->json([
            'status'     => 'success',
            'data'       => $dataWithPayments,
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
        $transfer = StockTransfer::with(['items.variant.values', 'user:id,name,role'])->find($id)
            ?? StockTransfer::with(['items.variant.values', 'user:id,name,role'])->where('reference_no', $id)->first();
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }

        $expense = Expense::with('payments')
            ->where('expense_category_name', 'Transfer Shipping')
            ->where(function($q) use ($transfer) {
                $q->where('reference_no', 'like', "%{$transfer->reference_no}%")
                  ->orWhere('details', 'like', "%#{$transfer->reference_no}%")
                  ->orWhere('title', 'like', "%#{$transfer->reference_no}%");
            })
            ->first();

        $transferData = $transfer->toArray();
        $transferData['payments'] = $expense ? $expense->payments : [];
        $transferData['shipping_paid'] = $expense ? (float)$expense->paid_amount : 0.0;
        $transferData['shipping_due'] = $expense ? (float)$expense->due_amount : (float)$transfer->shipping_charge;
        $transferData['shipping_payment_status'] = $expense ? $expense->payment_status : ($transfer->shipping_charge <= 0 ? 'Free' : 'Unpaid');
        $transferData['payment_method'] = $expense ? $expense->payment_method : 'Cash';

        return response()->json(['status' => 'success', 'data' => $transferData]);
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
            'payment_method'             => 'nullable|string|max:100',
            'payments'                   => 'nullable|array',
            'payments.*.amount'          => 'nullable|numeric|min:0',
            'payments.*.payment_type'    => 'nullable|string|max:100',
            'payments.*.payment_date'    => 'nullable|string',
            'payments.*.notes'           => 'nullable|string',
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

                        $itemUnitCost = round($transferredUnitCost * $convRate, 4);
                        $itemSubtotal = round($qty * $itemUnitCost, 4);
                        $createdItem->update([
                            'base_unit_cost' => $transferredUnitCost,
                            'unit_cost'      => $itemUnitCost,
                            'subtotal'       => $itemSubtotal,
                        ]);

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

            // ── T2: Auto-create Expense for Shipping Charge with Multi-Payment ───
            // Shipping cost is a real operating expense that must appear in the P&L.
            // We auto-create an Expense record and payment transactions so the GL is complete.
            $shippingCharge = (float)($validated['shipping_charge'] ?? 0);
            if ($shippingCharge > 0) {
                $shippingPrefix = Setting::get('expense_prefix', 'EXP_');
                $expenseRef = $shippingPrefix . 'TRF-' . $refNo;

                $paymentsList = $validated['payments'] ?? [];
                $paymentsList = array_values(array_filter($paymentsList, function($p) {
                    return !empty($p['amount']) && (float)$p['amount'] > 0;
                }));

                $totalPaid = 0.0;
                foreach ($paymentsList as $p) {
                    $totalPaid += (float)$p['amount'];
                }

                $dueAmount = max(0, $shippingCharge - $totalPaid);
                $paymentStatus = $dueAmount <= 0.001 ? 'Paid' : ($totalPaid > 0.001 ? 'Partial' : 'Unpaid');

                $uniqueTypes = collect($paymentsList)->pluck('payment_type')->filter()->unique();
                $paymentMethod = $uniqueTypes->count() > 1 ? 'Split Payment' : ($uniqueTypes->first() ?? ($validated['payment_method'] ?? 'Cash'));

                $expense = Expense::create([
                    'reference_no'          => $expenseRef,
                    'warehouse_id'          => $fromWarehouseId,
                    'warehouse_name'        => $validated['from_warehouse'],
                    'expense_category_name' => 'Transfer Shipping',
                    'title'                 => 'Shipping for Transfer #' . $refNo . ' → ' . $validated['to_warehouse'],
                    'amount'                => $shippingCharge,
                    'paid_amount'           => $totalPaid,
                    'due_amount'            => $dueAmount,
                    'payment_status'        => $paymentStatus,
                    'payment_method'        => $paymentMethod,
                    'date'                  => $txDate,
                    'details'               => 'Auto-created from Stock Transfer #' . $refNo,
                    'created_by'            => $validated['created_by'] ?? ($validated['user_name'] ?? 'System'),
                    'user_name'             => $validated['user_name'] ?? null,
                    'user_role'             => $validated['user_role'] ?? 'Super Admin',
                    'user_id'               => $validated['user_id'] ?? null,
                ]);

                // Create ExpensePayment records
                $pIndex = 1;
                foreach ($paymentsList as $p) {
                    $pAmount = (float)$p['amount'];
                    if ($pAmount <= 0) continue;
                    $pType = $p['payment_type'] ?? 'Cash';
                    ExpensePayment::create([
                        'expense_id'      => $expense->id,
                        'reference_no'    => 'PAY-' . $expenseRef . '-' . $pIndex++,
                        'payment_date'    => $p['payment_date'] ?? $txDate,
                        'payment_type'    => $pType,
                        'amount'          => $pAmount,
                        'received_amount' => $pAmount,
                        'change_amount'   => 0.0,
                        'notes'           => $p['notes'] ?? ('Shipping freight payment for #' . $refNo),
                        'created_by'      => $validated['created_by'] ?? ($validated['user_name'] ?? 'System'),
                        'user_id'         => $validated['user_id'] ?? null,
                        'user_name'       => $validated['user_name'] ?? null,
                        'user_role'       => $validated['user_role'] ?? 'Super Admin',
                    ]);
                }
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
            // If the original transfer was Completed, reverse both source 'out' and destination 'in'.
            // If the original transfer was Sent, reverse source 'out' (restore source stock).
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
            } elseif ($transfer->status === 'Sent') {
                $origFromId = $unitService->warehouseId($transfer->from_warehouse_id, $transfer->from_warehouse);
                foreach ($transfer->items as $oldItem) {
                    $oldBaseQty = (float)($oldItem->base_quantity ?? ($oldItem->quantity * ($oldItem->conversion_rate ?? 1.0)));
                    if ($oldBaseQty > 0 && $origFromId) {
                        $product = $oldItem->product_id
                            ? Product::find($oldItem->product_id)
                            : Product::where('code', $oldItem->product_code)->first();
                        if ($product) {
                            $unitService->applyStock(
                                $product, $origFromId, $oldItem->product_variant_id, $oldBaseQty,
                                'in', 'stock_transfer_reversal_in', 'stock_transfer_reversal',
                                (string)$transfer->id, 'Update reversal: restoring dispatched in-transit to ' . $transfer->from_warehouse
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

                // Re-apply stock movements if new status is Completed or Sent
                if (in_array($newStatus, ['Completed', 'Sent']) && $baseQty > 0 && $fromWarehouseId) {
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
                            (string)$transfer->id, 'Updated transfer to ' . $validated['to_warehouse']
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

                        $itemUnitCost = round($transferredUnitCost * $convRate, 4);
                        $itemSubtotal = round($qty * $itemUnitCost, 4);
                        $createdItem->update([
                            'base_unit_cost' => $transferredUnitCost,
                            'unit_cost'      => $itemUnitCost,
                            'subtotal'       => $itemSubtotal,
                        ]);

                        // 2. If Completed, also add stock & batch to destination warehouse
                        if ($newStatus === 'Completed' && $toWarehouseId) {
                            $unitService->applyStock(
                                $product, $toWarehouseId, $variantId, $baseQty,
                                'in', 'stock_transfer_in', 'stock_transfer',
                                (string)$transfer->id, 'Updated transfer from ' . $validated['from_warehouse']
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
                                notes:           'Updated transfer replenishment from ' . $validated['from_warehouse']
                            );
                        }
                    }
                }
            }

            // ── T2: Synchronize Shipping Operating Expense Record ─────────────
            $shippingCharge = (float)($validated['shipping_charge'] ?? 0);
            $refNo = $transfer->reference_no;
            $existingExpense = Expense::where('reference_no', 'like', "%TRF-{$refNo}%")
                ->orWhere('details', 'like', "%Transfer #{$refNo}%")
                ->first();

            if ($shippingCharge > 0) {
                if ($existingExpense) {
                    $totalPaid = (float)$existingExpense->payments()->sum('amount');
                    $dueAmount = max(0, $shippingCharge - $totalPaid);
                    $paymentStatus = $dueAmount <= 0.001 ? 'Paid' : ($totalPaid > 0.001 ? 'Partial' : 'Unpaid');
                    $existingExpense->update([
                        'warehouse_id'   => $fromWarehouseId,
                        'warehouse_name' => $validated['from_warehouse'],
                        'amount'         => $shippingCharge,
                        'paid_amount'    => $totalPaid,
                        'due_amount'     => $dueAmount,
                        'payment_status' => $paymentStatus,
                        'date'           => $validated['date'],
                        'title'          => 'Shipping for Transfer #' . $refNo . ' → ' . $validated['to_warehouse'],
                        'details'        => 'Auto-created from Stock Transfer #' . $refNo,
                    ]);
                } else {
                    $shippingPrefix = Setting::get('expense_prefix', 'EXP_');
                    Expense::create([
                        'reference_no'          => $shippingPrefix . 'TRF-' . $refNo,
                        'warehouse_id'          => $fromWarehouseId,
                        'warehouse_name'        => $validated['from_warehouse'],
                        'expense_category_name' => 'Transfer Shipping',
                        'title'                 => 'Shipping for Transfer #' . $refNo . ' → ' . $validated['to_warehouse'],
                        'amount'                => $shippingCharge,
                        'paid_amount'           => 0,
                        'due_amount'            => $shippingCharge,
                        'payment_status'        => 'Unpaid',
                        'payment_method'        => 'Cash',
                        'date'                  => $validated['date'],
                        'details'               => 'Auto-created from Stock Transfer #' . $refNo,
                        'created_by'            => $effectiveUserName,
                        'user_name'             => $effectiveUserName,
                        'user_role'             => $effectiveUserRole,
                        'user_id'               => $effectiveUserId,
                    ]);
                }
            } else {
                if ($existingExpense) {
                    $existingExpense->payments()->delete();
                    $existingExpense->delete();
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

            // Delete associated shipping expense
            Expense::where('reference_no', 'like', "%TRF-{$transfer->reference_no}%")
                ->orWhere('details', 'like', "%Transfer #{$transfer->reference_no}%")
                ->delete();

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
     * Confirm and receive an In-Transit (Sent) or Pending transfer at the destination branch.
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

        if (!in_array($transfer->status, ['Sent', 'Pending'])) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Only In-Transit (Sent) or Pending transfers can be received into destination inventory.',
            ], 422);
        }

        $validated = $request->validate([
            'received_by'     => 'nullable|string|max:255',
            'receiving_notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $unitService     = app(\App\Services\ProductUnitConversionService::class);
            $fifoService     = app(\App\Services\FifoInventoryService::class);
            $fromWarehouseId = $unitService->warehouseId($transfer->from_warehouse_id, $transfer->from_warehouse);
            $toWarehouseId   = $unitService->warehouseId($transfer->to_warehouse_id, $transfer->to_warehouse);
            $wasPending      = ($transfer->status === 'Pending');

            // Senior Accounting Safeguard: If transfer was Pending, verify source warehouse has sufficient stock before receiving
            if ($wasPending && $fromWarehouseId) {
                foreach ($transfer->items as $checkItem) {
                    $cRate = (float)($checkItem->conversion_rate ?? 1.0);
                    $cQty  = (float)$checkItem->quantity;
                    $cBase = (float)($checkItem->base_quantity ?? ($cQty * $cRate));
                    $pId   = $checkItem->product_id;
                    $vId   = !empty($checkItem->product_variant_id) ? (int)$checkItem->product_variant_id : null;

                    if ($pId && $cBase > 0) {
                        $balQuery = DB::table('stock_balances')
                            ->where('product_id', $pId)
                            ->where('warehouse_id', $fromWarehouseId);
                        if ($vId !== null) {
                            $balQuery->where('product_variant_id', $vId);
                        } else {
                            $balQuery->whereNull('product_variant_id');
                        }
                        $avail = (float)($balQuery->value('quantity') ?? 0);
                        if ($avail < ($cBase - 0.0001)) {
                            DB::rollBack();
                            return response()->json([
                                'status'  => 'error',
                                'message' => "Insufficient stock in source warehouse ({$transfer->from_warehouse}) for {$checkItem->product_name}. Available: {$avail}, Required: {$cBase}",
                            ], 422);
                        }
                    }
                }
            }

            foreach ($transfer->items as $item) {
                $convRate  = (float)($item->conversion_rate ?? 1.0);
                $qty       = (float)$item->quantity;
                $baseQty   = (float)($item->base_quantity ?? ($qty * $convRate));
                $baseCost  = (float)($item->base_unit_cost ?? ($item->unit_cost ?? 0));
                $variantId = !empty($item->product_variant_id) ? (int)$item->product_variant_id : null;

                if ($baseQty > 0) {
                    $product = $item->product_id
                        ? Product::find($item->product_id)
                        : Product::where('code', $item->product_code)->first();

                    if ($product) {
                        // If transfer was in Pending status, source warehouse was never deducted; deduct now.
                        if ($wasPending && $fromWarehouseId) {
                            $unitService->applyStock(
                                $product, $fromWarehouseId, $variantId, $baseQty,
                                'out', 'stock_transfer_out', 'stock_transfer',
                                (string)$transfer->id, 'Transferred to ' . $transfer->to_warehouse
                            );
                            $totalLossCost = $fifoService->depleteForAdjustment(
                                warehouseId:  (int)$fromWarehouseId,
                                productId:    (int)$product->id,
                                variantId:    $variantId,
                                baseQty:      (float)$baseQty,
                                referenceNo:  (string)$transfer->reference_no,
                                fallbackCost: (float)$baseCost
                            );
                            if ($baseQty > 0 && $totalLossCost > 0) {
                                $baseCost = $totalLossCost / $baseQty;
                            }
                        }

                        // Add stock to destination warehouse
                        if ($toWarehouseId) {
                            $unitService->applyStock(
                                $product, $toWarehouseId, $variantId, $baseQty,
                                'in', 'stock_transfer_in', 'stock_transfer',
                                (string)$transfer->id, 'Transfer received from ' . $transfer->from_warehouse
                            );

                            // Record FIFO replenishment batch in destination warehouse
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

    /**
     * Get payment history for a stock transfer's shipping freight.
     */
    public function getPayments($id)
    {
        $transfer = StockTransfer::find($id) ?? StockTransfer::where('reference_no', $id)->first();
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }

        $expense = Expense::with('payments')
            ->where('expense_category_name', 'Transfer Shipping')
            ->where(function($q) use ($transfer) {
                $q->where('reference_no', 'like', "%{$transfer->reference_no}%")
                  ->orWhere('details', 'like', "%#{$transfer->reference_no}%")
                  ->orWhere('title', 'like', "%#{$transfer->reference_no}%");
            })
            ->first();

        $payments = $expense ? $expense->payments : collect();
        $grandTotal = (float)($expense ? $expense->amount : $transfer->shipping_charge);
        $totalPaid = (float)($expense ? $expense->paid_amount : $payments->sum('amount'));
        $dueAmount = (float)($expense ? $expense->due_amount : max(0, $grandTotal - $totalPaid));

        return response()->json([
            'status' => 'success',
            'data'   => [
                'transfer_id'    => $transfer->id,
                'reference_no'   => $transfer->reference_no,
                'grand_total'    => $grandTotal,
                'total_paid'     => $totalPaid,
                'due_amount'     => $dueAmount,
                'payment_status' => $expense ? $expense->payment_status : ($dueAmount <= 0.001 ? 'Paid' : 'Unpaid'),
                'payments'       => $payments,
            ],
        ]);
    }

    /**
     * Add a payment to a stock transfer's shipping freight expense.
     */
    public function addPayment(Request $request, $id)
    {
        $transfer = StockTransfer::where('reference_no', $id)->first();
        if (!$transfer && is_numeric($id)) {
            $transfer = StockTransfer::find($id);
        }
        if (!$transfer && $request->filled('reference_no')) {
            $transfer = StockTransfer::where('reference_no', $request->input('reference_no'))->first();
        }
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }

        $validated = $request->validate([
            'amount'          => 'nullable|numeric|min:0',
            'payment_type'    => 'required|string',
            'payment_date'    => 'nullable|string',
            'payment_time'    => 'nullable|string',
            'notes'           => 'nullable|string',
            'received_amount' => 'nullable|numeric',
            'change_amount'   => 'nullable|numeric',
            'payments'        => 'nullable|array',
            'reference_no'    => 'nullable|string',
        ]);

        $authUser = \App\Models\User::resolveFromRequest($request) ?? $request->user();
        $userName = $request->input('user_name') 
            ?? ($authUser?->name ?? 'Super Admin');
        $userRole = $request->input('user_role') 
            ?? ($authUser?->role ?? ($authUser?->role_model?->name ?? 'Super Admin'));
        $userId   = $request->input('user_id') ?? $authUser?->id;

        // Senior Accounting / Business Analyst: Store financial timestamp in 12-hour format (hh:mm AM/PM) with configured Timezone
        $timezone = Setting::get('timezone', config('app.timezone', 'Asia/Yangon'));
        $nowTz = Carbon::now()->timezone($timezone);
        $defaultDate = $nowTz->format('Y-m-d');
        $defaultTime = $nowTz->format('h:i A');

        $pDate = $request->input('payment_date');
        $pTime = $request->input('payment_time');

        if (empty($pDate)) {
            $pDate = $defaultDate;
        } elseif (strlen(trim($pDate)) > 10 && str_contains($pDate, ' ')) {
            $parts = explode(' ', trim($pDate), 2);
            $pDate = $parts[0];
            if (empty($pTime)) {
                try {
                    $pTime = Carbon::parse($parts[1])->timezone($timezone)->format('h:i A');
                } catch (\Throwable $e) {}
            }
        }

        if (empty($pTime)) {
            $pTime = $defaultTime;
        } else {
            try {
                $pTime = Carbon::parse($pTime)->timezone($timezone)->format('h:i A');
            } catch (\Throwable $e) {
                if (!preg_match('/(AM|PM)/i', $pTime)) {
                    $pTime = $defaultTime;
                }
            }
        }

        $totalPaying = 0.0;
        if (!empty($validated['payments']) && is_array($validated['payments'])) {
            foreach ($validated['payments'] as $p) {
                $pAmt = (float)($p['amount'] ?? 0);
                if ($pAmt > 0) $totalPaying += $pAmt;
            }
        } else {
            $totalPaying = (float)($validated['amount'] ?? 0);
        }

        if ($totalPaying <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Payment amount must be greater than zero.'], 422);
        }

        DB::beginTransaction();
        try {
            $shippingPrefix = Setting::get('expense_prefix', 'EXP_');
            $expenseRef = $shippingPrefix . 'TRF-' . $transfer->reference_no;

            $expense = Expense::with('payments')
                ->where('expense_category_name', 'Transfer Shipping')
                ->where(function($q) use ($transfer) {
                    $q->where('reference_no', 'like', "%{$transfer->reference_no}%")
                      ->orWhere('details', 'like', "%#{$transfer->reference_no}%")
                      ->orWhere('title', 'like', "%#{$transfer->reference_no}%");
                })
                ->lockForUpdate()
                ->first();

            if (!$expense) {
                $expense = Expense::create([
                    'reference_no'          => $expenseRef,
                    'warehouse_id'          => $transfer->from_warehouse_id,
                    'warehouse_name'        => $transfer->from_warehouse,
                    'expense_category_name' => 'Transfer Shipping',
                    'title'                 => 'Shipping for Transfer #' . $transfer->reference_no . ' → ' . $transfer->to_warehouse,
                    'amount'                => (float)$transfer->shipping_charge,
                    'paid_amount'           => 0,
                    'due_amount'            => (float)$transfer->shipping_charge,
                    'payment_status'        => 'Unpaid',
                    'payment_method'        => $validated['payment_type'],
                    'date'                  => $pDate . ' ' . $pTime,
                    'details'               => 'Auto-created from Stock Transfer #' . $transfer->reference_no,
                    'created_by'            => $userName,
                    'user_name'             => $userName,
                    'user_role'             => $userRole,
                    'user_id'               => $userId,
                ]);
            }

            // Senior Accounting Validation: Strictly prevent paying more than the remaining due shipping fee
            $currentPaid = (float)$expense->paid_amount;
            $currentDue = max(0, (float)$expense->amount - $currentPaid);

            if ($totalPaying > $currentDue + 0.01) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Payment amount (' . number_format($totalPaying, 2) . ') cannot exceed the remaining due shipping fee (' . number_format($currentDue, 2) . ')',
                ], 422);
            }

            if (!empty($validated['payments']) && is_array($validated['payments'])) {
                foreach ($validated['payments'] as $p) {
                    $pAmount = (float)($p['amount'] ?? 0);
                    if ($pAmount <= 0) continue;
                    $pType = $p['payment_type'] ?? ($p['type'] ?? $validated['payment_type']);
                    $pCount = ExpensePayment::where('expense_id', $expense->id)->count() + 1;
                    $pRef = 'PAY-' . $expense->reference_no . '-' . $pCount;

                    $itemUserName = !empty($p['user_name']) ? $p['user_name'] : $userName;
                    $itemUserRole = !empty($p['user_role']) ? $p['user_role'] : $userRole;
                    $itemUserId   = !empty($p['user_id']) ? $p['user_id'] : $userId;
                    $itemDate     = !empty($p['payment_date']) ? $p['payment_date'] : $pDate;
                    $itemTime     = !empty($p['payment_time']) ? $p['payment_time'] : $pTime;

                    if (str_contains($itemDate, ' ')) {
                        $parts = explode(' ', trim($itemDate), 2);
                        $itemDate = $parts[0];
                        if (empty($p['payment_time'])) {
                            try { $itemTime = Carbon::parse($parts[1])->timezone($timezone)->format('h:i A'); } catch (\Throwable $e) {}
                        }
                    }
                    try {
                        $itemTime = Carbon::parse($itemTime)->timezone($timezone)->format('h:i A');
                    } catch (\Throwable $e) {
                        if (!preg_match('/(AM|PM)/i', $itemTime)) {
                            $itemTime = $defaultTime;
                        }
                    }

                    ExpensePayment::create([
                        'expense_id'      => $expense->id,
                        'reference_no'    => $pRef,
                        'payment_date'    => $itemDate,
                        'payment_time'    => $itemTime,
                        'payment_type'    => $pType,
                        'amount'          => $pAmount,
                        'received_amount' => (float)($p['received_amount'] ?? $pAmount),
                        'change_amount'   => (float)($p['change_amount'] ?? 0),
                        'notes'           => $p['notes'] ?? ($validated['notes'] ?? ('Shipping payment for #' . $transfer->reference_no)),
                        'created_by'      => $itemUserName,
                        'user_id'         => $itemUserId,
                        'user_name'       => $itemUserName,
                        'user_role'       => $itemUserRole,
                    ]);
                }
            } else {
                $pCount = ExpensePayment::where('expense_id', $expense->id)->count() + 1;
                $pRef = 'PAY-' . $expense->reference_no . '-' . $pCount;
                $pAmount = (float)$validated['amount'];

                ExpensePayment::create([
                    'expense_id'      => $expense->id,
                    'reference_no'    => $pRef,
                    'payment_date'    => $pDate,
                    'payment_time'    => $pTime,
                    'payment_type'    => $validated['payment_type'],
                    'amount'          => $pAmount,
                    'received_amount' => (float)($validated['received_amount'] ?? $pAmount),
                    'change_amount'   => (float)($validated['change_amount'] ?? 0),
                    'notes'           => $validated['notes'] ?? ('Shipping payment for #' . $transfer->reference_no),
                    'created_by'      => $userName,
                    'user_id'         => $userId,
                    'user_name'       => $userName,
                    'user_role'       => $userRole,
                ]);
            }

            // Recalculate expense
            $allPayments = ExpensePayment::where('expense_id', $expense->id)->get();
            $newPaid = (float)$allPayments->sum('amount');
            $newDue  = max(0, (float)$expense->amount - $newPaid);
            $newStatus = $newPaid <= 0.001 ? 'Unpaid' : ($newDue <= 0.001 ? 'Paid' : 'Partial');

            $uniqueTypes = $allPayments->pluck('payment_type')->filter()->unique();
            $newPaymentMethod = $uniqueTypes->count() > 1 ? 'Split Payment' : ($uniqueTypes->first() ?? $expense->payment_method);

            $expense->update([
                'paid_amount'    => $newPaid,
                'due_amount'     => $newDue,
                'payment_status' => $newStatus,
                'payment_method' => $newPaymentMethod,
            ]);

            DB::commit();

            $transferData = $transfer->fresh(['items.variant.values', 'user:id,name,role'])->toArray();
            $expense->refresh();
            $transferData['payments'] = $expense->payments()->orderBy('id', 'asc')->get()->toArray();
            $transferData['shipping_paid'] = (float)$expense->paid_amount;
            $transferData['shipping_due'] = (float)$expense->due_amount;
            $transferData['shipping_payment_status'] = $expense->payment_status;
            $transferData['payment_method'] = $expense->payment_method;

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment added successfully to shipping expense',
                'data'    => $transferData,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a payment from a stock transfer's shipping freight expense.
     */
    public function deletePayment(Request $request, $id, $paymentId)
    {
        $transfer = StockTransfer::where('reference_no', $id)->first();
        if (!$transfer && is_numeric($id)) {
            $transfer = StockTransfer::find($id);
        }
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }

        $expense = Expense::with('payments')
            ->where('expense_category_name', 'Transfer Shipping')
            ->where(function($q) use ($transfer) {
                $q->where('reference_no', 'like', "%{$transfer->reference_no}%")
                  ->orWhere('details', 'like', "%#{$transfer->reference_no}%")
                  ->orWhere('title', 'like', "%#{$transfer->reference_no}%");
            })
            ->first();

        $payment = null;
        if ($expense) {
            $payment = ExpensePayment::where('expense_id', $expense->id)
                ->where(function ($q) use ($paymentId) {
                    $q->where('id', $paymentId)->orWhere('reference_no', $paymentId);
                })->first();
        }

        if (!$payment) {
            $payment = ExpensePayment::find($paymentId) ?? ExpensePayment::where('reference_no', $paymentId)->first();
            if ($payment && !$expense) {
                $expense = Expense::find($payment->expense_id);
            }
        }

        if (!$payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment record not found'], 404);
        }

        if (!$expense) {
            return response()->json(['status' => 'error', 'message' => 'Associated expense not found'], 404);
        }

        DB::beginTransaction();
        try {
            $payment->delete();

            // Recalculate expense
            $allPayments = ExpensePayment::where('expense_id', $expense->id)->orderBy('id', 'asc')->get();
            $newPaid = (float)$allPayments->sum('amount');
            $newDue  = max(0, (float)$expense->amount - $newPaid);
            $newStatus = $newPaid <= 0.001 ? 'Unpaid' : ($newDue <= 0.001 ? 'Paid' : 'Partial');

            $uniqueTypes = $allPayments->pluck('payment_type')->filter()->unique();
            $newPaymentMethod = $uniqueTypes->count() > 1 ? 'Split Payment' : ($uniqueTypes->first() ?? 'Cash');

            $expense->update([
                'paid_amount'    => $newPaid,
                'due_amount'     => $newDue,
                'payment_status' => $newStatus,
                'payment_method' => $newPaymentMethod,
            ]);

            DB::commit();

            $transferData = $transfer->fresh(['items.variant.values', 'user:id,name,role'])->toArray();
            $expense->refresh();
            $transferData['payments'] = $expense->payments()->orderBy('id', 'asc')->get()->toArray();
            $transferData['shipping_paid'] = (float)$expense->paid_amount;
            $transferData['shipping_due'] = (float)$expense->due_amount;
            $transferData['shipping_payment_status'] = $expense->payment_status;
            $transferData['payment_method'] = $expense->payment_method;

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment deleted successfully from shipping expense',
                'data'    => $transferData,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}

