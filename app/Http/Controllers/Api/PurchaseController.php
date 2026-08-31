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
                'warehouse_name', 'warehouse_id', 'date', 'status',
                'tax_rate', 'tax_amount', 'discount', 'shipping',
                'grand_total', 'paid_amount', 'due_amount',
                'payment_status', 'payment_type', 'notes', 'created_by',
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

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('supplier_name', 'like', "%{$search}%");
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
            'reference_no' => 'nullable|string|max:100',
            'supplier_id' => 'nullable|integer',
            'supplier_name' => 'required|string|max:255',
            'warehouse_name' => 'required|string|max:255',
            'warehouse_id' => 'nullable|integer',
            'date' => 'required|string',
            'status' => 'nullable|string|in:Received,Pending,Ordered',
            'tax_rate' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'grand_total' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'payment_type' => 'nullable|string',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.unit_name' => 'nullable|string|max:100',
            'items.*.conversion_rate' => 'nullable|numeric|gt:0',
            'items.*.base_quantity' => 'nullable|numeric|min:0',
            'items.*.base_unit_cost' => 'nullable|numeric|min:0',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.tax' => 'nullable|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $prefix = Setting::get('purchase_prefix', 'PO_');
            $refNo = !empty($validated['reference_no'])
                ? $validated['reference_no']
                : ($prefix . date('Ymd') . '-' . rand(1000, 9999));
            $grandTotal = (float)$validated['grand_total'];
            $paidAmount = (float)($validated['paid_amount'] ?? 0);
            $dueAmount = max(0, $grandTotal - $paidAmount);

            $paymentStatus = 'Unpaid';
            if ($paidAmount >= $grandTotal && $grandTotal > 0) {
                $paymentStatus = 'Paid';
            } elseif ($paidAmount > 0) {
                $paymentStatus = 'Partial';
            }

            $purchase = Purchase::create([
                'reference_no' => $refNo,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'supplier_name' => $validated['supplier_name'],
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $validated['date'],
                'status' => $validated['status'] ?? 'Received',
                'tax_rate' => (float)($validated['tax_rate'] ?? 0),
                'tax_amount' => (float)($validated['tax_amount'] ?? 0),
                'discount' => (float)($validated['discount'] ?? 0),
                'shipping' => (float)($validated['shipping'] ?? 0),
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
                'payment_type' => $validated['payment_type'] ?? 'Cash',
                'notes' => $validated['notes'] ?? null,
                'created_by' => $validated['created_by'] ?? 'Admin User',
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

                PurchaseItem::create([
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
        $purchase = Purchase::find($id) ?? Purchase::where('reference_no', $id)->first();
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }

        $validated = $request->validate([
            'supplier_name' => 'nullable|string|max:255',
            'warehouse_name' => 'nullable|string|max:255',
            'date' => 'nullable|string',
            'status' => 'nullable|string|in:Received,Pending,Ordered',
            'grand_total' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|string',
            'payment_type' => 'nullable|string',
            'notes' => 'nullable|string',
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
        $purchase = Purchase::with(['items', 'supplier'])->find($id) ?? Purchase::with(['items', 'supplier'])->where('reference_no', $id)->first();
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $purchase]);
    }

    public function destroy($id)
    {
        $purchase = Purchase::find($id) ?? Purchase::where('reference_no', $id)->first();
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }
        $purchase->items()->delete();
        $purchase->delete();
        return response()->json(['status' => 'success', 'message' => 'Purchase deleted successfully']);
    }
}
