<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Product;
use App\Services\ProductUnitConversionService;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $includeFull = $request->boolean('full', false);

        $query = Sale::select([
            'id', 'reference_no', 'customer_id', 'customer_name', 'warehouse_id', 'warehouse_name',
            'date', 'status', 'tax_rate', 'tax_amount', 'discount', 'shipping', 'subtotal',
            'grand_total', 'paid_amount', 'due_amount', 'change_amount', 'payment_status',
            'payment_type', 'notes', 'pos_flag', 'created_by'
        ]);

        if ($includeFull) {
            $query->with([
                'items:id,sale_id,product_id,product_variant_id,unit_id,unit_name,conversion_rate,product_code,product_name,unit_price,quantity,tax,discount,subtotal',
                'payments:id,sale_id,reference_no,payment_date,payment_type,amount,received_amount,change_amount',
                'customer:id,name',
            ]);
        } else {
            $query->with([
                'items:id,sale_id,product_id,product_code,product_name,unit_name,unit_price,quantity,subtotal',
                'payments:id,sale_id,reference_no,payment_date,payment_type,amount,received_amount,change_amount',
                'customer:id,name',
            ]);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
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
                  ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        $perPage = min(500, max(1, (int)$request->input('per_page', 25)));
        $sales = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $sales->items(),
            'pagination' => [
                'current_page' => $sales->currentPage(),
                'per_page' => $sales->perPage(),
                'total' => $sales->total(),
                'last_page' => $sales->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:100',
            'customer_id' => 'nullable|integer',
            'customer_name' => 'required|string|max:255',
            'warehouse_name' => 'required|string|max:255',
            'warehouse_id' => 'nullable|integer',
            'date' => 'required|string',
            'status' => 'nullable|string|in:Completed,Pending,Draft,Ordered',
            'tax_rate' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'subtotal' => 'required|numeric|min:0',
            'grand_total' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'received_amount' => 'nullable|numeric|min:0',
            'change_amount' => 'nullable|numeric|min:0',
            'payment_type' => 'nullable|string',
            'notes' => 'nullable|string',
            'pos_flag' => 'nullable|boolean',
            'created_by' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.unit_name' => 'nullable|string|max:100',
            'items.*.conversion_rate' => 'nullable|numeric|gt:0',
            'items.*.base_quantity' => 'nullable|numeric|min:0',
            'items.*.base_unit_price' => 'nullable|numeric|min:0',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.tax' => 'nullable|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'required|numeric|min:0',
            'payments' => 'nullable|array',
            'payments.*.payment_type' => 'nullable|string',
            'payments.*.amount' => 'nullable|numeric|min:0',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount' => 'nullable|numeric|min:0',
            'payments.*.notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $prefix = !empty($validated['pos_flag'])
                ? Setting::get('pos_prefix', Setting::get('sale_prefix', 'SA_'))
                : Setting::get('sale_prefix', 'SA_');
            $refNo = !empty($validated['reference_no'])
                ? $validated['reference_no']
                : ($prefix . rand(1000000, 9999999));
            $grandTotal = (float)$validated['grand_total'];
            $paidAmount = (float)($validated['paid_amount'] ?? $grandTotal);
            $dueAmount = max(0, $grandTotal - $paidAmount);
            $received = (float)($validated['received_amount'] ?? $paidAmount);
            $change = (float)($validated['change_amount'] ?? max(0, $received - $grandTotal));

            $paymentStatus = 'Unpaid';
            if ($paidAmount >= $grandTotal && $grandTotal > 0) {
                $paymentStatus = 'Paid';
            } elseif ($paidAmount > 0) {
                $paymentStatus = 'Partial';
            }

            $sale = Sale::create([
                'reference_no' => $refNo,
                'customer_id' => $validated['customer_id'] ?? null,
                'customer_name' => $validated['customer_name'],
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $validated['date'],
                'status' => $validated['status'] ?? 'Completed',
                'tax_rate' => (float)($validated['tax_rate'] ?? 0),
                'tax_amount' => (float)($validated['tax_amount'] ?? 0),
                'discount' => (float)($validated['discount'] ?? 0),
                'shipping' => (float)($validated['shipping'] ?? 0),
                'subtotal' => (float)$validated['subtotal'],
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'change_amount' => $change,
                'payment_status' => $paymentStatus,
                'payment_type' => $validated['payment_type'] ?? 'Cash',
                'notes' => $validated['notes'] ?? null,
                'pos_flag' => $validated['pos_flag'] ?? true,
                'created_by' => $validated['created_by'] ?? 'Cashier',
            ]);

            $unitService = app(ProductUnitConversionService::class);
            $warehouseId = $unitService->warehouseId($validated['warehouse_id'] ?? null, $sale->warehouse_name);

            // Pre-fetch all products & variants in a single bulk query to eliminate N+1 queries
            $itemProductIds = array_values(array_filter(array_column($validated['items'], 'product_id')));
            $itemProductCodes = array_values(array_filter(array_column($validated['items'], 'product_code')));

            $productsMap = Product::with(['variants', 'unitLinks'])
                ->where(function ($q) use ($itemProductIds, $itemProductCodes) {
                    if (!empty($itemProductIds)) {
                        $q->whereIn('id', $itemProductIds);
                    }
                    if (!empty($itemProductCodes)) {
                        $q->orWhereIn('code', $itemProductCodes);
                    }
                })
                ->get();

            $productsById = $productsMap->keyBy('id');
            $productsByCode = $productsMap->keyBy('code');

            foreach ($validated['items'] as $item) {
                $product = (!empty($item['product_id']) && isset($productsById[$item['product_id']]))
                    ? $productsById[$item['product_id']]
                    : ($productsByCode[$item['product_code']] ?? null);

                $variantId = !empty($item['product_variant_id']) ? (int)$item['product_variant_id'] : null;
                if ($product && $variantId && !$product->variants->contains('id', $variantId)) {
                    throw new \RuntimeException('Selected variant does not belong to the product.');
                }

                $linkedUnit = $product
                    ? $unitService->resolve(
                        $product,
                        'sale',
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
                $baseUnitPrice = $unitService->basePrice((float)$item['unit_price'], (float)$linkedUnit['conversion_rate']);

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product?->id,
                    'product_variant_id' => $variantId,
                    'unit_id' => $linkedUnit['unit_id'],
                    'unit_name' => $linkedUnit['unit_name'],
                    'conversion_rate' => $linkedUnit['conversion_rate'],
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'unit_price' => (float)$item['unit_price'],
                    'base_unit_price' => $baseUnitPrice,
                    'quantity' => $quantity,
                    'base_quantity' => $baseQuantity,
                    'tax' => (float)($item['tax'] ?? 0),
                    'discount' => (float)($item['discount'] ?? 0),
                    'subtotal' => (float)$item['subtotal'],
                ]);

                // Reduce inventory stock if completed
                if ($sale->status === 'Completed') {
                    if ($product) {
                        $unitService->applyStock(
                            $product,
                            $warehouseId,
                            $variantId,
                            $baseQuantity,
                            'out',
                            'sale',
                            'sale',
                            (string)$sale->id,
                            'Sale completed in ' . $linkedUnit['unit_name']
                        );
                    }
                }
            }

            // Record payment transaction(s) - supporting Split Payments & multi-tenders
            if (!empty($validated['payments']) && is_array($validated['payments'])) {
                foreach ($validated['payments'] as $idx => $p) {
                    $pAmount = (float)($p['amount'] ?? 0);
                    if ($pAmount <= 0) continue;
                    SalePayment::create([
                        'sale_id' => $sale->id,
                        'reference_no' => 'PAY-' . date('Ymd') . '-' . rand(1000, 9999) . '-' . ($idx + 1),
                        'payment_date' => $p['payment_date'] ?? $sale->date,
                        'payment_type' => $p['payment_type'] ?? $sale->payment_type,
                        'amount' => $pAmount,
                        'received_amount' => (float)($p['received_amount'] ?? $pAmount),
                        'change_amount' => (float)($p['change_amount'] ?? 0),
                        'notes' => $p['notes'] ?? ($sale->payment_type === 'Split Payment' ? 'Split payment line' : 'Payment recorded at POS checkout'),
                        'created_by' => $sale->created_by,
                    ]);
                }
            } elseif ($paidAmount > 0) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'reference_no' => 'PAY-' . date('Ymd') . '-' . rand(1000, 9999),
                    'payment_date' => $sale->date,
                    'payment_type' => $sale->payment_type,
                    'amount' => $paidAmount,
                    'received_amount' => $received,
                    'change_amount' => $change,
                    'notes' => 'Payment recorded at POS checkout',
                    'created_by' => $sale->created_by,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Sale completed successfully',
                'data' => $sale->load(['items', 'payments']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $sale = Sale::with(['items', 'payments', 'customer'])->find($id);
        if (!$sale) {
            return response()->json(['status' => 'error', 'message' => 'Sale not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $sale]);
    }

    public function addPayment(Request $request, $id)
    {
        $sale = Sale::find($id);
        if (!$sale) {
            return response()->json(['status' => 'error', 'message' => 'Sale not found'], 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_type' => 'required|string',
            'payment_date' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $payingAmount = (float)$validated['amount'];
        $newPaid = $sale->paid_amount + $payingAmount;
        $newDue = max(0, $sale->grand_total - $newPaid);
        $newStatus = $newDue <= 0 ? 'Paid' : 'Partial';

        DB::beginTransaction();
        try {
            $payment = SalePayment::create([
                'sale_id' => $sale->id,
                'reference_no' => 'PAY-' . date('Ymd') . '-' . rand(1000, 9999),
                'payment_date' => $validated['payment_date'] ?? date('Y-m-d H:i:s'),
                'payment_type' => $validated['payment_type'],
                'amount' => $payingAmount,
                'received_amount' => $payingAmount,
                'change_amount' => 0,
                'notes' => $validated['notes'] ?? null,
                'created_by' => 'Cashier',
            ]);

            $sale->update([
                'paid_amount' => $newPaid,
                'due_amount' => $newDue,
                'payment_status' => $newStatus,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment added successfully',
                'data' => $sale->load(['items', 'payments']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $sale = Sale::find($id) ?? Sale::where('reference_no', $id)->first();
        if (!$sale) {
            return response()->json(['status' => 'error', 'message' => 'Sale not found'], 404);
        }

        $validated = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'warehouse_name' => 'nullable|string|max:255',
            'date' => 'nullable|string',
            'status' => 'nullable|string|in:Completed,Pending,Draft,Ordered',
            'grand_total' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|string',
            'payment_type' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $grandTotal = isset($validated['grand_total']) ? (float)$validated['grand_total'] : (float)$sale->grand_total;
        $paidAmount = isset($validated['paid_amount']) ? (float)$validated['paid_amount'] : (float)$sale->paid_amount;
        $dueAmount = max(0, $grandTotal - $paidAmount);

        $paymentStatus = $validated['payment_status'] ?? ($paidAmount >= $grandTotal && $grandTotal > 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid'));

        $sale->update(array_merge($validated, [
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_status' => $paymentStatus,
        ]));

        return response()->json([
            'status' => 'success',
            'message' => 'Sale updated successfully',
            'data' => $sale->load(['items', 'payments']),
        ]);
    }

    public function destroy($id)
    {
        $sale = Sale::with('items')->find($id) ?? Sale::with('items')->where('reference_no', $id)->first();
        if (!$sale) {
            return response()->json(['status' => 'error', 'message' => 'Sale not found'], 404);
        }

        DB::beginTransaction();
        try {
            // If sale was completed, revert inventory stock back ('in')
            if ($sale->status === 'Completed') {
                $unitService = app(ProductUnitConversionService::class);
                $warehouseId = $unitService->warehouseId($sale->warehouse_id ?? null, $sale->warehouse_name);

                foreach ($sale->items as $item) {
                    $product = Product::find($item->product_id);
                    if ($product) {
                        $baseQuantity = (float)($item->base_quantity > 0 ? $item->base_quantity : ($item->quantity * ($item->conversion_rate ?: 1)));
                        $unitService->applyStock(
                            $product,
                            $warehouseId,
                            $item->product_variant_id,
                            $baseQuantity,
                            'in', // Restores stock back to inventory on sale deletion
                            'sale_delete',
                            'sale',
                            (string)$sale->id,
                            'Sale #' . $sale->reference_no . ' deleted - Stock restored'
                        );
                    }
                }
            }

            $sale->items()->delete();
            $sale->payments()->delete();
            $sale->delete();

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Sale deleted successfully and stock restored']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
