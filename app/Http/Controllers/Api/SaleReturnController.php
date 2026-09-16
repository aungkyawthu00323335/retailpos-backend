<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaleReturn;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;

class SaleReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = SaleReturn::query();

        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $query->where('warehouse_name', $request->input('warehouse'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('sale_reference_no', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $returns = $query->orderBy('id', 'desc')->paginate((int)$request->input('per_page', 25));

        return response()->json([
            'status' => 'success',
            'data' => $returns->items(),
            'pagination' => [
                'current_page' => $returns->currentPage(),
                'per_page' => $returns->perPage(),
                'total' => $returns->total(),
                'last_page' => $returns->lastPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $return = is_numeric($id)
            ? SaleReturn::find($id)
            : SaleReturn::where('reference_no', $id)->first();

        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Sale return record not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $return,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:100',
            'sale_reference_no' => 'nullable|string|max:100',
            'customer_id' => 'nullable|integer',
            'customer_name' => 'required|string|max:255',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'tax_rate' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'subtotal' => 'nullable|numeric|min:0',
            'grand_total' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'due_amount' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|string|in:Paid,Partial,Unpaid,Pending',
            'payment_type' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:Completed,Received,Pending',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'created_by' => 'nullable|string|max:255',
        ]);

        $prefix = Setting::get('sale_return_prefix', 'SR_');
        $refNo = !empty($validated['reference_no'])
            ? $validated['reference_no']
            : ($prefix . date('Ymd') . '-' . rand(1000, 9999));

        $grandTotal = (float)$validated['grand_total'];
        $paidAmount = isset($validated['paid_amount']) ? (float)$validated['paid_amount'] : $grandTotal;
        $dueAmount = isset($validated['due_amount']) ? (float)$validated['due_amount'] : max(0, $grandTotal - $paidAmount);
        $paymentStatus = $validated['payment_status'] ?? ($dueAmount == 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid'));

        $txDate = trim($validated['date']);
        if (!str_contains($txDate, ':')) {
            $txDate .= ' ' . now()->format('H:i:s');
        }

        $unitService = app(\App\Services\ProductUnitConversionService::class);
        $fifoService = app(\App\Services\FifoInventoryService::class);
        $warehouseId = $unitService->warehouseId(null, $validated['warehouse_name'] ?? null);

        DB::beginTransaction();
        try {
            $itemsPayload = !empty($validated['items']) ? json_encode($validated['items']) : null;

            $return = SaleReturn::create([
                'reference_no' => $refNo,
                'sale_reference_no' => $validated['sale_reference_no'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'customer_name' => $validated['customer_name'],
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $txDate,
                'status' => $validated['status'] ?? 'Completed',
                'tax_rate' => $validated['tax_rate'] ?? 0.0,
                'tax_amount' => $validated['tax_amount'] ?? 0.0,
                'discount' => $validated['discount'] ?? 0.0,
                'shipping' => $validated['shipping'] ?? 0.0,
                'subtotal' => $validated['subtotal'] ?? 0.0,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
                'payment_type' => $validated['payment_type'] ?? 'Cash',
                'notes' => $validated['notes'] ?? null,
                'items_payload' => $itemsPayload,
                'created_by' => $validated['created_by'] ?? 'Cashier',
            ]);

            if (!empty($validated['items']) && is_array($validated['items']) && $return->status === 'Completed') {
                foreach ($validated['items'] as $it) {
                    $productId = $it['product_id'] ?? null;
                    $variantId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
                    $product = $productId ? \App\Models\Product::find($productId) : \App\Models\Product::where('code', $it['product_code'] ?? '')->first();
                    if ($product) {
                        $conversionRate = (float)($it['conversion_rate'] ?? 1.0);
                        $qty = (float)($it['quantity'] ?? 0);
                        $baseQty = (float)($it['base_quantity'] ?? ($qty * $conversionRate));
                        $unitCost = (float)($it['unit_cost'] ?? ($product->cost_price ?: $product->purchase_price));
                        $baseUnitCost = $conversionRate > 0 ? ($unitCost / $conversionRate) : $unitCost;

                        if ($baseQty > 0 && $warehouseId) {
                            try {
                                $unitService->applyStock(
                                    $product,
                                    $warehouseId,
                                    $variantId,
                                    $baseQty,
                                    'in',
                                    'sale_return',
                                    'sale_return',
                                    (string)$return->id,
                                    'Sale return #' . $return->reference_no
                                );

                                $fifoService->recordBatch(
                                    warehouseId: (int)$warehouseId,
                                    productId: (int)$product->id,
                                    variantId: $variantId,
                                    baseQuantity: (float)$baseQty,
                                    baseUnitCost: (float)$baseUnitCost,
                                    sourceType: 'sale_return',
                                    sourceReference: (string)$return->reference_no,
                                    receivedDate: (string)$return->date,
                                    notes: 'Sale return replenished batch'
                                );
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::warning('Stock replenish on sale return failed: ' . $e->getMessage());
                            }
                        }
                    }
                }
            }

            // Deduct cash refund from active open Cash Register drawer session
            if ($return->status === 'Completed' && $paidAmount > 0 && strtolower($return->payment_type ?? 'cash') === 'cash') {
                $activeReg = \App\Models\CashRegister::where('status', 'open')
                    ->where(function ($q) use ($validated, $warehouseId) {
                        if ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId);
                        } elseif (!empty($validated['warehouse_name'])) {
                            $q->where('warehouse_name', $validated['warehouse_name']);
                        }
                    })
                    ->latest()
                    ->first();

                if ($activeReg) {
                    $activeReg->decrement('expected_cash', $paidAmount);
                    if ($activeReg->closing_cash !== null) {
                        $activeReg->decrement('closing_cash', $paidAmount);
                    }
                }
            }

            // Deduct loyalty points and total spent from Customer if linked
            if ($return->status === 'Completed' && !empty($validated['customer_id'])) {
                $cust = \App\Models\Customer::find($validated['customer_id']);
                if ($cust) {
                    $lostPoints = (int) floor($grandTotal / 1000);
                    $cust->update([
                        'total_spent' => max(0, (float)$cust->total_spent - $grandTotal),
                        'reward_points' => max(0, (int)$cust->reward_points - $lostPoints),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Sale return recorded successfully',
                'data' => $return,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $return = is_numeric($id)
            ? SaleReturn::find($id)
            : SaleReturn::where('reference_no', $id)->first();

        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }

        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:100',
            'sale_reference_no' => 'nullable|string|max:100',
            'customer_id' => 'nullable|integer',
            'customer_name' => 'nullable|string|max:255',
            'warehouse_name' => 'nullable|string|max:255',
            'date' => 'nullable|string',
            'tax_rate' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'subtotal' => 'nullable|numeric|min:0',
            'grand_total' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'due_amount' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|string|in:Paid,Partial,Unpaid,Pending',
            'payment_type' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:Completed,Received,Pending',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
        ]);

        if (isset($validated['grand_total'])) {
            $grandTotal = (float)$validated['grand_total'];
            $paidAmount = isset($validated['paid_amount']) ? (float)$validated['paid_amount'] : (float)$return->paid_amount;
            $dueAmount = max(0, $grandTotal - $paidAmount);
            $validated['due_amount'] = $dueAmount;
            if (!isset($validated['payment_status'])) {
                $validated['payment_status'] = $dueAmount == 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid');
            }
        }

        if (isset($validated['items'])) {
            $validated['items_payload'] = json_encode($validated['items']);
            unset($validated['items']);
        }

        $return->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Sale return updated successfully',
            'data' => $return,
        ]);
    }


    public function destroy($id)
    {
        $return = is_numeric($id)
            ? SaleReturn::find($id)
            : SaleReturn::where('reference_no', $id)->first();

        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }
        $return->delete();
        return response()->json(['status' => 'success', 'message' => 'Sale return deleted']);
    }
}
