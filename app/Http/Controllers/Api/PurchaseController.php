<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\ProductUnitConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $query = Purchase::query()
            ->select([
                'id', 'reference_no', 'supplier_id', 'supplier_name',
                'warehouse_id', 'warehouse_name', 'date', 'status',
                'tax_rate', 'tax_amount', 'discount', 'shipping',
                'grand_total', 'paid_amount', 'due_amount',
                'payment_status', 'payment_type', 'notes',
                'created_by', 'user_id', 'user_name', 'user_role',
                'created_at', 'updated_at',
            ])
            ->with([
                'items:id,purchase_id,product_id,product_variant_id,product_code,product_name,unit_id,unit_name,conversion_rate,unit_cost,quantity,base_quantity,base_unit_cost,tax,discount,subtotal',
            ]);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $query->where('warehouse_name', $request->input('warehouse'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('payment_status') && $request->input('payment_status') !== 'All') {
            $query->where('payment_status', $request->input('payment_status'));
        }

        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate = $request->input('end_date') ?? $request->input('to_date');
        if (!empty($startDate) && !empty($endDate)) {
            $query->whereBetween('date', [$startDate, $endDate]);
        } elseif (!empty($startDate)) {
            $query->where('date', '>=', $startDate);
        } elseif (!empty($endDate)) {
            $query->where('date', '<=', $endDate);
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $digits = preg_replace('/[^0-9]/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('supplier_name', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%");
                if (!empty($digits)) {
                    $q->orWhere('reference_no', 'like', "%{$digits}%");
                }
            });
        }

        $purchases = $query->orderBy('id', 'desc')->paginate((int)$request->input('per_page', 25));

        return response()->json([
            'status' => 'success',
            'data' => $purchases->items(),
            'pagination' => [
                'current_page' => $purchases->currentPage(),
                'per_page' => $purchases->perPage(),
                'total' => $purchases->total(),
                'last_page' => $purchases->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no'              => 'nullable|string|max:100',
            'supplier_id'               => 'nullable|integer',
            'supplier_name'             => 'required|string|max:255',
            'warehouse_name'            => 'required|string|max:255',
            'warehouse_id'              => 'nullable|integer',
            'date'                      => 'required|string',
            'status'                    => 'nullable|string|in:Received,Pending,Ordered',
            'payment_status'            => 'nullable|string|in:Paid,Unpaid,Partial',
            'tax_rate'                  => 'nullable|numeric|min:0',
            'tax_amount'                => 'nullable|numeric|min:0',
            'discount'                  => 'nullable|numeric|min:0',
            'shipping'                  => 'nullable|numeric|min:0',
            'grand_total'               => 'required|numeric|min:0',
            'paid_amount'               => 'nullable|numeric|min:0',
            'payment_type'              => 'nullable|string',
            'notes'                     => 'nullable|string',
            'created_by'                => 'nullable|string|max:255',
            'user_id'                   => 'nullable|integer',
            'user_name'                 => 'nullable|string|max:255',
            'user_role'                 => 'nullable|string|max:255',
            'items'                     => 'required|array|min:1',
            'items.*.product_id'        => 'nullable|integer',
            'items.*.product_variant_id'=> 'nullable|integer',
            'items.*.unit_id'           => 'nullable|integer',
            'items.*.unit_name'         => 'nullable|string|max:100',
            'items.*.conversion_rate'   => 'nullable|numeric|gt:0',
            'items.*.base_quantity'     => 'nullable|numeric|min:0',
            'items.*.base_unit_cost'    => 'nullable|numeric|min:0',
            'items.*.product_code'      => 'required|string',
            'items.*.product_name'      => 'required|string',
            'items.*.unit_cost'         => 'required|numeric|min:0',
            'items.*.quantity'          => 'required|numeric|min:0.01',
            'items.*.tax'               => 'nullable|numeric|min:0',
            'items.*.discount'          => 'nullable|numeric|min:0',
            'items.*.subtotal'          => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $rawRef = trim((string)($validated['reference_no'] ?? ''));
            $digitsOnly = preg_replace('/[^0-9]/', '', $rawRef);
            $refNo = !empty($digitsOnly)
                ? $digitsOnly
                : Purchase::generateNextNumericReference();
            $grandTotal = (float)$validated['grand_total'];
            $paidAmount = (float)($validated['paid_amount'] ?? 0);
            $dueAmount  = max(0, $grandTotal - $paidAmount);

            // Honour explicit payment_status from frontend; fall back to amount-based logic
            if (!empty($validated['payment_status'])) {
                $paymentStatus = $validated['payment_status'];
            } elseif ($paidAmount >= $grandTotal && $grandTotal > 0) {
                $paymentStatus = 'Paid';
            } elseif ($paidAmount > 0) {
                $paymentStatus = 'Partial';
            } else {
                $paymentStatus = 'Unpaid';
            }

            $token = $request->bearerToken();
            $authUser = null;
            if ($token) {
                $authUser = \App\Models\User::where('api_token', $token)->first();
            }
            if (!$authUser && !empty($validated['user_id'])) {
                $authUser = \App\Models\User::find($validated['user_id']);
            }

            $userName = !empty($validated['user_name'])
                ? $validated['user_name']
                : ($authUser?->name ?? ($request->user()?->name ?? null));
            $userRole = !empty($validated['user_role'])
                ? $validated['user_role']
                : ($authUser?->role ?? ($request->user()?->role ?? null));

            if (empty($userName) && !empty($validated['created_by'])) {
                if (preg_match('/^(.*?)\s*\((.*?)\)$/', $validated['created_by'], $m)) {
                    $userName = trim($m[1]);
                    $userRole = $userRole ?: trim($m[2]);
                } else {
                    $userName = trim($validated['created_by']);
                }
            }
            if (empty($userName)) {
                $userName = $authUser?->name ?: 'Super Admin';
            }
            if (empty($userRole)) {
                $userRole = $authUser?->role ?: 'Super Admin';
            }
            $createdBy = !empty($validated['created_by'])
                ? $validated['created_by']
                : "$userName ($userRole)";

            $txDate = trim($validated['date']);
            if (!str_contains($txDate, ':')) {
                $txDate .= ' ' . now()->format('H:i:s');
            }

            $purchase = Purchase::create([
                'reference_no'   => $refNo,
                'supplier_id'    => $validated['supplier_id'] ?? null,
                'supplier_name'  => $validated['supplier_name'],
                'warehouse_id'   => $validated['warehouse_id'] ?? null,
                'warehouse_name' => $validated['warehouse_name'],
                'date'           => $txDate,
                'status'         => $validated['status'] ?? 'Received',
                'tax_rate'       => (float)($validated['tax_rate'] ?? 0),
                'tax_amount'     => (float)($validated['tax_amount'] ?? 0),
                'discount'       => (float)($validated['discount'] ?? 0),
                'shipping'       => (float)($validated['shipping'] ?? 0),
                'grand_total'    => $grandTotal,
                'paid_amount'    => $paidAmount,
                'due_amount'     => $dueAmount,
                'payment_status' => $paymentStatus,
                'payment_type'   => $validated['payment_type'] ?? ($paymentStatus === 'Unpaid' ? 'N/A' : 'Cash'),
                'notes'          => $validated['notes'] ?? null,
                'created_by'     => $createdBy,
                'user_id'        => $validated['user_id'] ?? ($authUser?->id ?? $request->user()?->id),
                'user_name'      => $userName,
                'user_role'      => $userRole,
            ]);

            $unitService = app(ProductUnitConversionService::class);
            $warehouseId = $unitService->warehouseId($validated['warehouse_id'] ?? null, $purchase->warehouse_name);

            foreach ($validated['items'] as $item) {
                $product = !empty($item['product_id'])
                    ? Product::find($item['product_id'])
                    : Product::where('code', $item['product_code'])->first();
                $variantId = !empty($item['product_variant_id']) ? (int)$item['product_variant_id'] : null;
                if ($product && $variantId && !$product->variants()->whereKey($variantId)->exists()) {
                    throw new \RuntimeException('Selected variant does not belong to the product.');
                }

                $linkedUnit = $product
                    ? $unitService->resolve(
                        $product,
                        'purchase',
                        isset($item['unit_id']) ? (int)$item['unit_id'] : null,
                        $item['unit_name'] ?? null,
                        $variantId
                    )
                    : [
                        'unit_id' => $item['unit_id'] ?? null,
                        'unit_name' => $item['unit_name'] ?? '',
                        'conversion_rate' => (float)($item['conversion_rate'] ?? 1),
                    ];
                $quantity = (float)$item['quantity'];
                $baseQuantity = $unitService->baseQuantity($quantity, (float)$linkedUnit['conversion_rate']);
                $baseUnitCost = $unitService->basePrice((float)$item['unit_cost'], (float)$linkedUnit['conversion_rate']);

                $purchaseItem = PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $product?->id,
                    'product_variant_id' => $variantId,
                    'unit_id' => $linkedUnit['unit_id'],
                    'unit_name' => $linkedUnit['unit_name'],
                    'conversion_rate' => $linkedUnit['conversion_rate'],
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'unit_cost' => (float)$item['unit_cost'],
                    'base_unit_cost' => $baseUnitCost,
                    'quantity' => $quantity,
                    'base_quantity' => $baseQuantity,
                    'tax' => (float)($item['tax'] ?? 0),
                    'discount' => (float)($item['discount'] ?? 0),
                    'subtotal' => (float)$item['subtotal'],
                ]);

                // Update product stock if Received
                if ($purchase->status === 'Received') {
                    if ($product) {
                        $unitService->applyStock(
                            $product,
                            $warehouseId,
                            $variantId,
                            $baseQuantity,
                            'in',
                            'purchase',
                            'purchase',
                            (string)$purchase->id,
                            'Purchase received in ' . $linkedUnit['unit_name']
                        );
                        $product->update(['purchase_price' => (float)$item['unit_cost']]);

                        // Register into FIFO Inventory Batches
                        app(\App\Services\FifoInventoryService::class)->recordBatch(
                            warehouseId: (int)$warehouseId,
                            productId: (int)$product->id,
                            variantId: $variantId,
                            baseQuantity: (float)$baseQuantity,
                            baseUnitCost: (float)$baseUnitCost,
                            sourceType: 'purchase',
                            sourceReference: (string)$purchase->reference_no,
                            receivedDate: (string)$purchase->date,
                            purchaseItemId: (int)$purchaseItem->id,
                            notes: "Purchase #{$purchase->reference_no} received"
                        );
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Purchase order created successfully',
                'data' => $purchase->load('items'),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $purchase = Purchase::where('reference_no', (string)$id)->first() ?? Purchase::find($id);
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }

        $validated = $request->validate([
            'supplier_id' => 'nullable|integer',
            'supplier_name' => 'nullable|string|max:255',
            'warehouse_id' => 'nullable|integer',
            'warehouse_name' => 'nullable|string|max:255',
            'date' => 'nullable|string',
            'status' => 'nullable|string|in:Received,Pending,Ordered',
            'grand_total' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|string',
            'payment_type' => 'nullable|string',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'user_id' => 'nullable|integer',
            'user_name' => 'nullable|string|max:255',
            'user_role' => 'nullable|string|max:255',
        ]);

        $grandTotal = isset($validated['grand_total']) ? (float)$validated['grand_total'] : (float)$purchase->grand_total;
        $paidAmount = isset($validated['paid_amount']) ? (float)$validated['paid_amount'] : (float)$purchase->paid_amount;
        $dueAmount = max(0, $grandTotal - $paidAmount);

        $paymentStatus = $validated['payment_status'] ?? ($paidAmount >= $grandTotal && $grandTotal > 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid'));

        $purchase->update(array_merge($validated, [
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_status' => $paymentStatus,
        ]));

        return response()->json([
            'status' => 'success',
            'message' => 'Purchase updated successfully',
            'data' => $purchase->load('items'),
        ]);
    }

    public function show($id)
    {
        $purchase = Purchase::with(['items', 'supplier'])->where('reference_no', (string)$id)->first() ?? Purchase::with(['items', 'supplier'])->find($id);
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $purchase]);
    }

    public function destroy($id)
    {
        $purchase = Purchase::with('items')->where('reference_no', (string)$id)->first() ?? Purchase::with('items')->find($id);
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }

        DB::beginTransaction();
        try {
            if ($purchase->status === 'Received') {
                $unitService = app(ProductUnitConversionService::class);
                $warehouseId = $unitService->warehouseId($purchase->warehouse_id, $purchase->warehouse_name);

                foreach ($purchase->items as $item) {
                    $product = !empty($item->product_id)
                        ? Product::find($item->product_id)
                        : Product::where('code', $item->product_code)->first();

                    if ($product) {
                        $baseQty = $item->base_quantity > 0 ? (float)$item->base_quantity : (float)$item->quantity;
                        try {
                            $unitService->applyStock(
                                $product,
                                $warehouseId,
                                $item->product_variant_id,
                                $baseQty,
                                'out',
                                'purchase_deletion',
                                'purchase',
                                (string)$purchase->id,
                                'Stock reduced due to purchase deletion'
                            );
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::warning("Stock deduction on purchase delete skipped: " . $e->getMessage());
                        }
                    }
                }
            }

            // Clean up related FIFO inventory batches
            \App\Models\InventoryBatch::whereIn('purchase_item_id', $purchase->items->pluck('id'))->delete();

            $purchase->items()->delete();
            $purchase->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Purchase deleted and stock reverted successfully',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function addPayment(Request $request, $id)
    {
        $purchase = Purchase::with('items')->find($id) ?? Purchase::with('items')->where('reference_no', $id)->first();
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_type' => 'required|string',
            'payment_date' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $payingAmount = (float)$validated['amount'];
        $newPaid = (float)$purchase->paid_amount + $payingAmount;
        $newDue = max(0, (float)$purchase->grand_total - $newPaid);
        $newStatus = $newDue <= 0 ? 'Paid' : 'Partial';

        DB::beginTransaction();
        try {
            $purchase->update([
                'paid_amount' => $newPaid,
                'due_amount' => $newDue,
                'payment_status' => $newStatus,
                'payment_type' => $validated['payment_type'],
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment added successfully',
                'data' => $purchase->fresh()->load('items'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
