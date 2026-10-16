<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Services\ProductUnitConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuotationController extends Controller
{
    /**
     * Display a paginated listing of quotations with optional filters and sorting.
     */
    public function index(Request $request)
    {
        $query = Quotation::with(['items', 'customer']);

        // Filter: Warehouse / Outlet
        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $warehouse = $request->input('warehouse');
            $query->where(function ($q) use ($warehouse) {
                $q->where('warehouse_name', $warehouse);
                if (is_numeric($warehouse)) {
                    $q->orWhere('warehouse_id', (int)$warehouse);
                }
            });
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int)$request->input('warehouse_id'));
        }

        // Filter: Status
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        // Filter: Search (Reference #, Customer, Staff Name, Role)
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%");
            });
        }

        // Filter: Created By / Staff
        if ($request->filled('created_by') && $request->input('created_by') !== 'All') {
            $filterUser = $request->input('created_by');
            $query->where(function ($q) use ($filterUser) {
                $q->where('created_by', $filterUser)
                  ->orWhere('user_name', $filterUser);
            });
        }
        if ($request->filled('user_role') && $request->input('user_role') !== 'All') {
            $query->where('user_role', $request->input('user_role'));
        }

        // Filter: Date Range
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->input('date_to'));
        }

        // Sorting
        $allowedSortCols = [
            'id' => 'id',
            'reference' => 'reference_no',
            'reference_no' => 'reference_no',
            'customer' => 'customer_name',
            'customer_name' => 'customer_name',
            'warehouse' => 'warehouse_name',
            'warehouse_name' => 'warehouse_name',
            'date' => 'date',
            'status' => 'status',
            'grand_total' => 'grand_total',
            'total' => 'grand_total',
            'user_name' => 'user_name',
            'user_role' => 'user_role',
            'created_by' => 'created_by',
        ];

        $sortByInput = strtolower(trim((string)$request->input('sort_by', 'id')));
        $sortBy = $allowedSortCols[$sortByInput] ?? 'id';
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        if ($isAll) {
            $totalCount = (clone $query)->count();
            $limit = min(500, max(1, (int)$request->input('limit', 500)));
            $items = $query->orderBy($sortBy, $sortOrder)->take($limit)->get();
            return response()->json([
                'status' => 'success',
                'data' => $items,
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $items->count(),
                    'total' => $totalCount,
                    'last_page' => (int)ceil($totalCount / max(1, $limit)),
                ],
            ])
            ->header('Cache-Control', 'private, max-age=60');
        }

        $perPage = min(1000, max(1, (int)$request->input('per_page', 25)));
        $page = (int)$request->input('page', 1);

        $quotations = $query->orderBy($sortBy, $sortOrder)->paginate($perPage, ['*'], 'page', $page);

        // HTTP ETag & Cache-Control for fast conditional responses
        $total = $quotations->total();
        $etag = md5("quo|{$page}|{$perPage}|{$total}|{$sortBy}|{$sortOrder}");

        return response()->json([
            'status' => 'success',
            'data' => $quotations->items(),
            'pagination' => [
                'current_page' => $quotations->currentPage(),
                'per_page' => $quotations->perPage(),
                'total' => $quotations->total(),
                'last_page' => $quotations->lastPage(),
            ],
        ])
        ->header('Cache-Control', 'private, max-age=60')
        ->header('ETag', '"' . $etag . '"');
    }

    /**
     * Store a newly created quotation with its line items.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id'        => 'nullable|integer',
            'customer_name'      => 'required|string|max:255',
            'warehouse_id'       => 'nullable|integer',
            'warehouse_name'     => 'required|string|max:255',
            'user_id'            => 'nullable|integer',
            'user_name'          => 'nullable|string|max:255',
            'user_role'          => 'nullable|string|max:255',
            'date'               => 'required|string',
            'expiry_date'        => 'nullable|date',
            'tax_rate'           => 'nullable|numeric|min:0',
            'tax_amount'         => 'nullable|numeric|min:0',
            'discount'           => 'nullable|numeric|min:0',
            'shipping'           => 'nullable|numeric|min:0',
            'grand_total'        => 'required|numeric|min:0',
            'status'             => 'nullable|string|in:Sent,Pending,Ordered,Expired',
            'notes'              => 'nullable|string',
            'created_by'         => 'nullable|string|max:255',
            'items'              => 'required|array|min:1',
            'items.*.product_id'         => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.unit_id'            => 'nullable|integer',
            'items.*.unit_name'          => 'nullable|string|max:100',
            'items.*.conversion_rate'    => 'nullable|numeric|min:0',
            'items.*.base_unit_price'    => 'nullable|numeric|min:0',
            'items.*.base_quantity'      => 'nullable|numeric|min:0',
            'items.*.product_code'       => 'required|string',
            'items.*.product_name'       => 'required|string',
            'items.*.variant_name'       => 'nullable|string',
            'items.*.unit_price'         => 'required|numeric|min:0',
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.tax'                => 'nullable|numeric|min:0',
            'items.*.discount'           => 'nullable|numeric|min:0',
            'items.*.subtotal'           => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $refNo = $request->input('reference_no');
            if (empty($refNo)) {
                $prefix = Setting::get('quotation_prefix', 'QUO_');
                $refNo  = $prefix . date('Ymd') . '-' . rand(1000, 9999);
            }

            $user = $request->user();
            $userId = $validated['user_id'] ?? $user?->id;
            $userName = $validated['user_name'] ?? $user?->name;
            $userRole = $validated['user_role'] ?? $user?->role ?? 'Sales Staff';
            $creator = $validated['created_by'] ?? ($userName ?: ($user?->name ?: 'Sales Staff'));

            $txDate = trim($validated['date']);
            if (!str_contains($txDate, ':')) {
                $txDate .= ' ' . now()->format('H:i:s');
            }

            // Determine status — if expiry_date is in the past, set to Expired automatically
            $expiryDate  = $validated['expiry_date'] ?? null;
            $autoStatus  = $validated['status'] ?? 'Sent';
            if ($expiryDate && \Carbon\Carbon::parse($expiryDate)->isPast()) {
                $autoStatus = 'Expired';
            }

            $quotation = Quotation::create([
                'reference_no' => $refNo,
                'customer_id'  => $validated['customer_id'] ?? null,
                'customer_name'=> $validated['customer_name'],
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'warehouse_name'=> $validated['warehouse_name'],
                'date'         => $txDate,
                'expiry_date'  => $expiryDate,
                'tax_rate'     => (float)($validated['tax_rate'] ?? 0),
                'tax_amount'   => (float)($validated['tax_amount'] ?? 0),
                'discount'     => (float)($validated['discount'] ?? 0),
                'shipping'     => (float)($validated['shipping'] ?? 0),
                'grand_total'  => (float)$validated['grand_total'],
                'status'       => $autoStatus,
                'notes'        => $validated['notes'] ?? null,
                'created_by'   => $creator,
                'user_id'      => $userId,
                'user_name'    => $userName,
                'user_role'    => $userRole,
            ]);


            $itemsData = [];
            $now = now();
            foreach ($validated['items'] as $item) {
                $convRate = (float)($item['conversion_rate'] ?? 1.0);
                if ($convRate <= 0) $convRate = 1.0;
                $qty = (float)$item['quantity'];
                $unitPrice = (float)$item['unit_price'];
                $baseQty = isset($item['base_quantity']) ? (float)$item['base_quantity'] : ($qty * $convRate);
                $basePrice = isset($item['base_unit_price']) ? (float)$item['base_unit_price'] : ($unitPrice / $convRate);

                $itemsData[] = [
                    'quotation_id' => $quotation->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'unit_id' => $item['unit_id'] ?? null,
                    'unit_name' => $item['unit_name'] ?? null,
                    'conversion_rate' => $convRate,
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'variant_name' => $item['variant_name'] ?? null,
                    'unit_price' => $unitPrice,
                    'base_unit_price' => $basePrice,
                    'quantity' => $qty,
                    'base_quantity' => $baseQty,
                    'tax' => (float)($item['tax'] ?? 0),
                    'discount' => (float)($item['discount'] ?? 0),
                    'subtotal' => (float)$item['subtotal'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            QuotationItem::insert($itemsData);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation created successfully',
                'data' => $quotation->load(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'customer']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified quotation with all child items.
     */
    public function show($id)
    {
        $quotation = Quotation::with(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'customer'])->find($id);
        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $quotation]);
    }

    /**
     * Update the specified quotation and sync its items in a single transaction.
     */
    public function update(Request $request, $id)
    {
        $quotation = Quotation::find($id);
        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        $validated = $request->validate([
            'reference_no'               => 'nullable|string|max:255',
            'customer_id'                => 'nullable|integer',
            'customer_name'              => 'required|string|max:255',
            'warehouse_id'               => 'nullable|integer',
            'warehouse_name'             => 'required|string|max:255',
            'user_id'                    => 'nullable|integer',
            'user_name'                  => 'nullable|string|max:255',
            'user_role'                  => 'nullable|string|max:255',
            'date'                       => 'required|string',
            'expiry_date'                => 'nullable|date',
            'tax_rate'                   => 'nullable|numeric|min:0',
            'tax_amount'                 => 'nullable|numeric|min:0',
            'discount'                   => 'nullable|numeric|min:0',
            'shipping'                   => 'nullable|numeric|min:0',
            'grand_total'                => 'required|numeric|min:0',
            'status'                     => 'nullable|string|in:Sent,Pending,Ordered,Expired',
            'notes'                      => 'nullable|string',
            'created_by'                 => 'nullable|string|max:255',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.unit_id'            => 'nullable|integer',
            'items.*.unit_name'          => 'nullable|string|max:100',
            'items.*.conversion_rate'    => 'nullable|numeric|min:0',
            'items.*.base_unit_price'    => 'nullable|numeric|min:0',
            'items.*.base_quantity'      => 'nullable|numeric|min:0',
            'items.*.product_code'       => 'required|string',
            'items.*.product_name'       => 'required|string',
            'items.*.variant_name'       => 'nullable|string',
            'items.*.unit_price'         => 'required|numeric|min:0',
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.tax'                => 'nullable|numeric|min:0',
            'items.*.discount'           => 'nullable|numeric|min:0',
            'items.*.subtotal'           => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $expiryDate = $validated['expiry_date'] ?? $quotation->expiry_date;
            $newStatus  = $validated['status'] ?? $quotation->status;
            // Auto-expire if expiry_date is in the past (and not already Ordered)
            if ($expiryDate && $newStatus !== 'Ordered' && \Carbon\Carbon::parse($expiryDate)->isPast()) {
                $newStatus = 'Expired';
            }

            $updateData = [
                'customer_id'   => $validated['customer_id'] ?? null,
                'customer_name' => $validated['customer_name'],
                'warehouse_id'  => $validated['warehouse_id'] ?? $quotation->warehouse_id,
                'warehouse_name'=> $validated['warehouse_name'],
                'date'          => $validated['date'],
                'expiry_date'   => $expiryDate,
                'tax_rate'      => (float)($validated['tax_rate'] ?? 0),
                'tax_amount'    => (float)($validated['tax_amount'] ?? 0),
                'discount'      => (float)($validated['discount'] ?? 0),
                'shipping'      => (float)($validated['shipping'] ?? 0),
                'grand_total'   => (float)$validated['grand_total'],
                'status'        => $newStatus,
                'notes'         => $validated['notes'] ?? null,
            ];


            $user = $request->user();
            if (!empty($validated['reference_no'])) {
                $updateData['reference_no'] = $validated['reference_no'];
            }
            if (!empty($validated['created_by'])) {
                $updateData['created_by'] = $validated['created_by'];
            } elseif (empty($quotation->created_by) && $user?->name) {
                $updateData['created_by'] = $user->name;
            }
            if (!empty($validated['user_id'])) {
                $updateData['user_id'] = $validated['user_id'];
            } elseif (empty($quotation->user_id) && $user?->id) {
                $updateData['user_id'] = $user->id;
            }
            if (!empty($validated['user_name'])) {
                $updateData['user_name'] = $validated['user_name'];
            } elseif (empty($quotation->user_name) && $user?->name) {
                $updateData['user_name'] = $user->name;
            }
            if (!empty($validated['user_role'])) {
                $updateData['user_role'] = $validated['user_role'];
            } elseif (empty($quotation->user_role) && $user?->role) {
                $updateData['user_role'] = $user->role;
            }

            $quotation->update($updateData);

            // Replace items in batch
            $quotation->items()->delete();

            $itemsData = [];
            $now = now();
            foreach ($validated['items'] as $item) {
                $convRate = (float)($item['conversion_rate'] ?? 1.0);
                if ($convRate <= 0) $convRate = 1.0;
                $qty = (float)$item['quantity'];
                $unitPrice = (float)$item['unit_price'];
                $baseQty = isset($item['base_quantity']) ? (float)$item['base_quantity'] : ($qty * $convRate);
                $basePrice = isset($item['base_unit_price']) ? (float)$item['base_unit_price'] : ($unitPrice / $convRate);

                $itemsData[] = [
                    'quotation_id' => $quotation->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'unit_id' => $item['unit_id'] ?? null,
                    'unit_name' => $item['unit_name'] ?? null,
                    'conversion_rate' => $convRate,
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'variant_name' => $item['variant_name'] ?? null,
                    'unit_price' => $unitPrice,
                    'base_unit_price' => $basePrice,
                    'quantity' => $qty,
                    'base_quantity' => $baseQty,
                    'tax' => (float)($item['tax'] ?? 0),
                    'discount' => (float)($item['discount'] ?? 0),
                    'subtotal' => (float)$item['subtotal'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            QuotationItem::insert($itemsData);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation updated successfully',
                'data' => $quotation->fresh(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'customer']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified quotation and all its child items.
     */
    public function destroy($id)
    {
        $quotation = Quotation::find($id);
        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        DB::beginTransaction();
        try {
            $quotation->items()->delete();
            $quotation->delete();
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation deleted successfully',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Convert the specified quotation into a completed sale, and mark quotation as Ordered.
     *
     * Accounting integrity:
     * 1. Duplicate guard — status === 'Ordered' → 422 (prevents double-conversion fraud)
     * 2. FIFO depletion per item  — accurate COGS tracking in P&L
     * 3. SalePayment record       — Cash Register and payment reports are correct
     * 4. Cash Register increment  — Cash payment increments open till balance
     */
    public function convertToSale(Request $request, $id)
    {
        $quotation = Quotation::with('items')->find($id);
        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        // ── Q1: Duplicate Convert Guard ───────────────────────────────────────
        // Prevent the same quotation from being converted to a Sale more than once.
        if ($quotation->status === 'Ordered') {
            return response()->json([
                'status'  => 'error',
                'message' => 'This quotation has already been converted to a sale and cannot be converted again.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $prefix     = Setting::get('sale_prefix', 'SA_');
            $refNo      = $prefix . rand(1000000, 9999999);
            $paymentType = $request->input('payment_type', 'Cash');

            $grandTotal    = (float)$quotation->grand_total;
            $paidAmount    = (float)($request->input('paid_amount', $grandTotal));
            $dueAmount     = max(0, $grandTotal - $paidAmount);
            $paymentStatus = $paidAmount >= $grandTotal ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid');

            $subtotal = 0;
            foreach ($quotation->items as $item) {
                $subtotal += (float)$item->subtotal;
            }

            $sale = Sale::create([
                'reference_no'   => $refNo,
                'customer_id'    => $quotation->customer_id,
                'customer_name'  => $quotation->customer_name,
                'warehouse_id'   => $quotation->warehouse_id,
                'warehouse_name' => $quotation->warehouse_name,
                'date'           => date('Y-m-d H:i:s'),
                'status'         => 'Completed',
                'tax_rate'       => (float)$quotation->tax_rate,
                'tax_amount'     => (float)$quotation->tax_amount,
                'discount'       => (float)$quotation->discount,
                'shipping'       => (float)$quotation->shipping,
                'subtotal'       => $subtotal > 0 ? $subtotal : $grandTotal,
                'grand_total'    => $grandTotal,
                'paid_amount'    => $paidAmount,
                'due_amount'     => $dueAmount,
                'change_amount'  => 0,
                'payment_status' => $paymentStatus,
                'payment_type'   => $paymentType,
                'notes'          => 'Converted from Quotation: ' . $quotation->reference_no . ($quotation->notes ? ' | ' . $quotation->notes : ''),
                'created_by'     => $request->input('created_by', $quotation->user_name ?? $quotation->created_by ?? 'Sales Staff'),
            ]);

            $unitService = app(ProductUnitConversionService::class);
            $fifoService = app(\App\Services\FifoInventoryService::class);
            $warehouseId = $unitService->warehouseId($quotation->warehouse_id, $sale->warehouse_name);

            foreach ($quotation->items as $item) {
                $convRate = (float)($item->conversion_rate ?? 1.0);
                if ($convRate <= 0) $convRate = 1.0;
                $qty      = (float)$item->quantity;
                $unitPrice = (float)$item->unit_price;
                $baseQty  = $item->base_quantity !== null && (float)$item->base_quantity > 0
                    ? (float)$item->base_quantity
                    : ($qty * $convRate);
                $basePrice = $item->base_unit_price !== null && (float)$item->base_unit_price > 0
                    ? (float)$item->base_unit_price
                    : ($unitPrice / $convRate);

                $saleItem = SaleItem::create([
                    'sale_id'           => $sale->id,
                    'product_id'        => $item->product_id,
                    'product_variant_id'=> $item->product_variant_id,
                    'unit_id'           => $item->unit_id,
                    'unit_name'         => $item->unit_name ?? 'pcs',
                    'conversion_rate'   => $convRate,
                    'base_quantity'     => $baseQty,
                    'base_unit_price'   => $basePrice,
                    'product_code'      => $item->product_code,
                    'product_name'      => $item->product_name,
                    'variant_name'      => $item->variant_name,
                    'unit_price'        => $unitPrice,
                    'quantity'          => $qty,
                    'tax'               => (float)$item->tax,
                    'discount'          => (float)$item->discount,
                    'subtotal'          => (float)$item->subtotal,
                ]);

                // Reduce inventory stock + FIFO tracking for completed sale
                if ($item->product_id) {
                    $product = Product::find($item->product_id);
                    if ($product) {
                        $unitService->applyStock(
                            $product,
                            $warehouseId,
                            $item->product_variant_id,
                            $baseQty,
                            'out',
                            'sale',
                            'sale',
                            (string)$sale->id,
                            'Converted from Quotation ' . $quotation->reference_no
                        );

                        // ── Q1: FIFO Cost Depletion ───────────────────────────────────
                        // Consume FIFO cost batches oldest-first so COGS is correctly
                        // calculated in the P&L. Without this, Gross Profit is overstated.
                        if ($warehouseId && $baseQty > 0) {
                            try {
                                $fifoService->depleteFifo(
                                    warehouseId:   (int)$warehouseId,
                                    productId:     (int)$product->id,
                                    variantId:     $item->product_variant_id,
                                    neededBaseQty: (float)$baseQty,
                                    saleId:        (int)$sale->id,
                                    saleItemId:    (int)$saleItem->id,
                                    saleDate:      $sale->date,
                                    fallbackCost:  (float)($product->cost_price ?? $product->purchase_price ?? 0)
                                );
                            } catch (\Throwable $fifoEx) {
                                \Illuminate\Support\Facades\Log::warning('FIFO depletion on quotation convert failed: ' . $fifoEx->getMessage());
                            }
                        }
                    }
                }
            }

            // ── Q1: Record Sale Payment ───────────────────────────────────────
            // Creates a SalePayment row so payment reports and Cash Register are correct.
            if ($paidAmount > 0) {
                \App\Models\SalePayment::create([
                    'sale_id'       => $sale->id,
                    'payment_type'  => $paymentType,
                    'amount'        => $paidAmount,
                    'change_amount' => 0,
                    'reference'     => 'Quotation conversion: ' . $quotation->reference_no,
                    'date'          => date('Y-m-d H:i:s'),
                ]);

                // Increment Cash Register for Cash payments only
                if (strtolower(trim($paymentType)) === 'cash' && $warehouseId) {
                    $register = \App\Models\CashRegister::where('status', 'open')
                        ->where('warehouse_id', $warehouseId)
                        ->latest()
                        ->first();
                    if ($register) {
                        $register->increment('expected_cash', $paidAmount);
                    }
                }
            }

            // Mark quotation as Ordered (prevents future re-conversion)
            $quotation->update(['status' => 'Ordered']);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Quotation successfully converted to Sale ' . $sale->reference_no,
                'data'    => [
                    'sale'      => $sale->load('items'),
                    'quotation' => $quotation->fresh('items'),
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
