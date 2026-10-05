<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaleReturn;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleReturnController extends Controller
{
    /** Return reason options */
    public const RETURN_REASONS = [
        'Defective / Damaged Product',
        'Wrong Item Delivered',
        'Customer Changed Mind',
        'Expired Product',
        'Duplicate Order',
        'Other',
    ];

    public function index(Request $request)
    {
        $query = SaleReturn::query();

        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $query->where('warehouse_name', $request->input('warehouse'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('return_reason') && $request->input('return_reason') !== 'All') {
            $query->where('return_reason', $request->input('return_reason'));
        }

        if ($request->filled('refund_tender') && $request->input('refund_tender') !== 'All') {
            $query->where('refund_tender', $request->input('refund_tender'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('sale_reference_no', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('return_reason', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        if ($isAll) {
            $returns = $query->orderBy('id', 'desc')->get();
            return response()->json([
                'status' => 'success',
                'data'   => $returns,
                'pagination' => [
                    'current_page' => 1,
                    'per_page'     => $returns->count(),
                    'total'        => $returns->count(),
                    'last_page'    => 1,
                ],
            ]);
        }

        $perPage = min(max((int)$request->input('per_page', 25), 1), 1000);
        $returns = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $returns->items(),
            'pagination' => [
                'current_page' => $returns->currentPage(),
                'per_page'     => $returns->perPage(),
                'total'        => $returns->total(),
                'last_page'    => $returns->lastPage(),
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
            'data'   => $return,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no'     => 'nullable|string|max:100',
            'sale_reference_no' => 'nullable|string|max:100',
            'customer_id'      => 'nullable|integer',
            'customer_name'    => 'required|string|max:255',
            'warehouse_name'   => 'required|string|max:255',
            'date'             => 'required|string',
            'tax_rate'         => 'nullable|numeric|min:0',
            'tax_amount'       => 'nullable|numeric|min:0',
            'discount'         => 'nullable|numeric|min:0',
            'shipping'         => 'nullable|numeric|min:0',
            'subtotal'         => 'nullable|numeric|min:0',
            'grand_total'      => 'required|numeric|min:0',
            'paid_amount'      => 'nullable|numeric|min:0',
            'due_amount'       => 'nullable|numeric|min:0',
            'payment_status'   => 'nullable|string|in:Paid,Partial,Unpaid,Pending',
            'payment_type'     => 'nullable|string|max:100',
            'status'           => 'nullable|string|in:Completed,Received,Pending',
            'notes'            => 'nullable|string',
            'return_reason'    => 'nullable|string|max:255',
            'refund_tender'    => 'nullable|string|max:100',
            'items'            => 'nullable|array',
            'payments'         => 'nullable|array',
            'payments.*.amount' => 'nullable|numeric|min:0.01',
            'payments.*.payment_type' => 'nullable|string',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount' => 'nullable|numeric|min:0',
            'payments.*.payment_date' => 'nullable|string',
            'payments.*.notes' => 'nullable|string',
            'created_by'       => 'nullable|string|max:255',
            'user_id'          => 'nullable|integer',
            'user_name'        => 'nullable|string|max:255',
            'user_role'        => 'nullable|string|max:100',
        ]);

        // ── Business Rule: Duplicate Return Check ─────────────────────────────
        // A Completed return for the same sale_reference_no must not exist
        if (!empty($validated['sale_reference_no'])) {
            $existingReturn = SaleReturn::where('sale_reference_no', $validated['sale_reference_no'])
                ->where('status', 'Completed')
                ->first();
            if ($existingReturn) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'A completed return already exists for sale reference: ' . $validated['sale_reference_no'] . ' (Return #' . $existingReturn->reference_no . ')',
                ], 422);
            }
        }

        // ── Business Rule: Return Window Check ───────────────────────────────
        // Default 30 days; configurable via Setting
        if (!empty($validated['sale_reference_no'])) {
            $linkedSale = \App\Models\Sale::where('reference_no', $validated['sale_reference_no'])->first();
            if ($linkedSale && !empty($linkedSale->date)) {
                $saleDate       = \Carbon\Carbon::parse($linkedSale->date);
                $returnWindowDays = (int) Setting::get('return_window_days', 30);
                if ($returnWindowDays > 0 && $saleDate->diffInDays(now()) > $returnWindowDays) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => "Return window exceeded. Returns are only accepted within {$returnWindowDays} days of the sale date (Sale date: {$saleDate->toDateString()}).",
                    ], 422);
                }
            }
        }

        $prefix      = Setting::get('sale_return_prefix', 'SR_');
        $refNo       = !empty($validated['reference_no'])
            ? $validated['reference_no']
            : ($prefix . date('Ymd') . '-' . rand(1000, 9999));

        $grandTotal    = (float)$validated['grand_total'];
        $paidAmount    = isset($validated['paid_amount']) ? (float)$validated['paid_amount'] : $grandTotal;
        $dueAmount     = isset($validated['due_amount']) ? (float)$validated['due_amount'] : max(0, $grandTotal - $paidAmount);
        $paymentStatus = $validated['payment_status'] ?? ($dueAmount == 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid'));
        $refundTender  = $validated['refund_tender'] ?? $validated['payment_type'] ?? 'Cash';

        $txDate = trim($validated['date']);
        if (!str_contains($txDate, ':')) {
            $txDate .= ' ' . now()->format('H:i:s');
        }

        $unitService = app(\App\Services\ProductUnitConversionService::class);
        $fifoService = app(\App\Services\FifoInventoryService::class);
        $warehouseId = $unitService->warehouseId(null, $validated['warehouse_name'] ?? null);

        // Build items and payments payload
        $itemsPayload    = !empty($validated['items']) ? json_encode($validated['items']) : null;
        $paymentsPayload = !empty($validated['payments']) ? json_encode($validated['payments']) : null;

        DB::beginTransaction();
        try {
            $userId    = $validated['user_id'] ?? ($request->user()?->id);
            $userName  = !empty($validated['user_name']) ? $validated['user_name'] : ($request->user()?->name ?? 'Super Admin');
            $userRole  = !empty($validated['user_role']) ? $validated['user_role'] : ($request->user()?->role ?? 'Super Admin');
            $createdBy = !empty($validated['created_by']) ? $validated['created_by'] : ($userName . ' (' . $userRole . ')');

            $return = SaleReturn::create([
                'reference_no'     => $refNo,
                'sale_reference_no' => $validated['sale_reference_no'] ?? null,
                'customer_id'      => $validated['customer_id'] ?? null,
                'customer_name'    => $validated['customer_name'],
                'warehouse_id'     => $warehouseId,
                'warehouse_name'   => $validated['warehouse_name'],
                'date'             => $txDate,
                'status'           => $validated['status'] ?? 'Completed',
                'tax_rate'         => $validated['tax_rate'] ?? 0.0,
                'tax_amount'       => $validated['tax_amount'] ?? 0.0,
                'discount'         => $validated['discount'] ?? 0.0,
                'shipping'         => $validated['shipping'] ?? 0.0,
                'subtotal'         => $validated['subtotal'] ?? 0.0,
                'grand_total'      => $grandTotal,
                'paid_amount'      => $paidAmount,
                'due_amount'       => $dueAmount,
                'payment_status'   => $paymentStatus,
                'payment_type'     => $validated['payment_type'] ?? 'Cash',
                'notes'            => $validated['notes'] ?? null,
                'return_reason'    => $validated['return_reason'] ?? null,
                'refund_tender'    => $refundTender,
                'items_payload'    => $itemsPayload,
                'payments_payload' => $paymentsPayload,
                'created_by'       => $createdBy,
                'user_id'          => $userId,
                'user_name'        => $userName,
                'user_role'        => $userRole,
            ]);

            // ── Stock Replenishment ───────────────────────────────────────────
            if (!empty($validated['items']) && is_array($validated['items']) && $return->status === 'Completed') {
                foreach ($validated['items'] as $it) {
                    $productId = $it['product_id'] ?? null;
                    $variantId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
                    $product   = $productId
                        ? \App\Models\Product::find($productId)
                        : \App\Models\Product::where('code', $it['product_code'] ?? '')->first();

                    if ($product) {
                        $conversionRate = (float)($it['conversion_rate'] ?? 1.0);
                        $qty            = (float)($it['quantity'] ?? 0);
                        $baseQty        = (float)($it['base_quantity'] ?? ($qty * $conversionRate));
                        // Use stored unit_cost from the original sale item payload — not current product cost
                        $unitCost       = (float)($it['unit_cost'] ?? $it['unit_price'] ?? $product->cost_price ?? $product->purchase_price ?? 0);
                        $baseUnitCost   = $conversionRate > 0 ? ($unitCost / $conversionRate) : $unitCost;

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
                                    warehouseId:     (int)$warehouseId,
                                    productId:       (int)$product->id,
                                    variantId:       $variantId,
                                    baseQuantity:    (float)$baseQty,
                                    baseUnitCost:    (float)$baseUnitCost,
                                    sourceType:      'sale_return',
                                    sourceReference: (string)$return->reference_no,
                                    receivedDate:    (string)$return->date,
                                    notes:           'Sale return replenished batch'
                                );
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::warning('Stock replenish on sale return failed: ' . $e->getMessage());
                            }
                        }
                    }
                }
            }

            // Record refund payment transaction(s) - supporting Split Payments & multi-tenders
            $cashRefundPortion = 0.0;
            if (!empty($validated['payments']) && is_array($validated['payments'])) {
                $savedPayments = [];
                $tenderIdx = 1;
                $cleanRef = preg_replace('/^#\s*/', '', trim((string)$return->reference_no));
                foreach ($validated['payments'] as $p) {
                    $pAmount = (float)($p['amount'] ?? 0);
                    if ($pAmount <= 0) continue;
                    $pType = $p['payment_type'] ?? ($return->payment_type ?: 'Cash');
                    if (strtolower(trim($pType)) === 'cash') {
                        $cashRefundPortion += $pAmount;
                    }
                    $paymentRef = 'PAY-' . ($cleanRef ?: $return->id) . '-' . time() . '-' . rand(100, 999);
                    $savedPayments[] = [
                        'id'              => $tenderIdx,
                        'reference_no'    => $paymentRef,
                        'payment_date'    => !empty($p['payment_date']) ? $p['payment_date'] : (!empty($validated['date']) ? $validated['date'] : now()->toDateTimeString()),
                        'payment_type'    => $pType,
                        'amount'          => $pAmount,
                        'received_amount' => !empty($p['received_amount']) ? (float)$p['received_amount'] : $pAmount,
                        'change_amount'   => !empty($p['change_amount']) ? (float)$p['change_amount'] : 0.0,
                        'user_name'       => $p['user_name'] ?? $userName,
                        'user_role'       => $p['user_role'] ?? $userRole,
                        'notes'           => $p['notes'] ?? ($return->payment_type === 'Split Payment' ? 'Split refund line' : ($validated['notes'] ?? '')),
                    ];
                    $tenderIdx++;
                }
                if (!empty($savedPayments)) {
                    $return->update(['payments_payload' => json_encode($savedPayments)]);
                }
            } elseif ($paidAmount > 0) {
                if (strtolower(trim($refundTender)) === 'cash') {
                    $cashRefundPortion = $paidAmount;
                }
                $cleanRef = preg_replace('/^#\s*/', '', trim((string)$return->reference_no));
                $paymentRef = 'PAY-' . ($cleanRef ?: $return->id) . '-' . time() . '-' . rand(100, 999);
                $initialPayment = [
                    'id'              => 1,
                    'reference_no'    => $paymentRef,
                    'payment_date'    => $txDate,
                    'payment_type'    => $refundTender,
                    'amount'          => $paidAmount,
                    'received_amount' => $paidAmount,
                    'change_amount'   => 0.0,
                    'user_name'       => $userName,
                    'user_role'       => $userRole,
                    'notes'           => $validated['notes'] ?? '',
                ];
                $return->update(['payments_payload' => json_encode([$initialPayment])]);
            }

            // ── Cash Register Deduction (only for Cash refunds) ───────────────
            // If refund includes Cash tender, deduct only the Cash portion from open drawer.
            if ($return->status === 'Completed' && $cashRefundPortion > 0) {
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
                    $activeReg->decrement('expected_cash', $cashRefundPortion);
                    if ($activeReg->closing_cash !== null) {
                        $activeReg->decrement('closing_cash', $cashRefundPortion);
                    }
                }
            }

            // ── Customer Total Spent Reversal (Points decommissioned) ─────────
            if ($return->status === 'Completed' && !empty($validated['customer_id'])) {
                $cust = \App\Models\Customer::find($validated['customer_id']);
                if ($cust) {
                    $cust->update([
                        'total_spent'   => max(0, (float)$cust->total_spent - $grandTotal),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Sale return recorded successfully',
                'data'    => $return,
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
            'reference_no'     => 'nullable|string|max:100',
            'sale_reference_no' => 'nullable|string|max:100',
            'customer_id'      => 'nullable|integer',
            'customer_name'    => 'nullable|string|max:255',
            'warehouse_name'   => 'nullable|string|max:255',
            'date'             => 'nullable|string',
            'tax_rate'         => 'nullable|numeric|min:0',
            'tax_amount'       => 'nullable|numeric|min:0',
            'discount'         => 'nullable|numeric|min:0',
            'shipping'         => 'nullable|numeric|min:0',
            'subtotal'         => 'nullable|numeric|min:0',
            'grand_total'      => 'nullable|numeric|min:0',
            'paid_amount'      => 'nullable|numeric|min:0',
            'due_amount'       => 'nullable|numeric|min:0',
            'payment_status'   => 'nullable|string|in:Paid,Partial,Unpaid,Pending',
            'payment_type'     => 'nullable|string|max:100',
            'status'           => 'nullable|string|in:Completed,Received,Pending',
            'notes'            => 'nullable|string',
            'return_reason'    => 'nullable|string|max:255',
            'refund_tender'    => 'nullable|string|max:100',
            'items'            => 'nullable|array',
            'payments'         => 'nullable|array',
            'created_by'       => 'nullable|string|max:255',
            'user_id'          => 'nullable|integer',
            'user_name'        => 'nullable|string|max:255',
            'user_role'        => 'nullable|string|max:100',
        ]);

        if (isset($validated['grand_total'])) {
            $grandTotal    = (float)$validated['grand_total'];
            $paidAmount    = isset($validated['paid_amount']) ? (float)$validated['paid_amount'] : (float)$return->paid_amount;
            $dueAmount     = max(0, $grandTotal - $paidAmount);
            $validated['due_amount'] = $dueAmount;
            if (!isset($validated['payment_status'])) {
                $validated['payment_status'] = $dueAmount == 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid');
            }
        }

        if (isset($validated['items'])) {
            $validated['items_payload'] = json_encode($validated['items']);
            unset($validated['items']);
        }

        if (isset($validated['payments'])) {
            $validated['payments_payload'] = json_encode($validated['payments']);
            unset($validated['payments']);
        }

        $return->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Sale return updated successfully',
            'data'    => $return,
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

        // ── Reverse Stock on Delete ───────────────────────────────────────────
        // If the return was Completed, stock was added back to warehouse.
        // Deleting means we remove it again (the goods are leaving the warehouse).
        if ($return->status === 'Completed') {
            $unitService = app(\App\Services\ProductUnitConversionService::class);
            $warehouseId = $return->warehouse_id;
            if (!$warehouseId && !empty($return->warehouse_name)) {
                $warehouseId = $unitService->warehouseId(null, $return->warehouse_name);
            }

            $items = $return->items;
            if (!empty($items) && is_array($items) && $warehouseId) {
                foreach ($items as $it) {
                    $productId = $it['product_id'] ?? null;
                    $variantId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
                    $product   = $productId ? \App\Models\Product::find($productId) : null;
                    if ($product) {
                        $conversionRate = (float)($it['conversion_rate'] ?? 1.0);
                        $qty            = (float)($it['quantity'] ?? 0);
                        $baseQty        = (float)($it['base_quantity'] ?? ($qty * $conversionRate));
                        if ($baseQty > 0) {
                            try {
                                $unitService->applyStock(
                                    $product,
                                    $warehouseId,
                                    $variantId,
                                    $baseQty,
                                    'out',
                                    'sale_return_void',
                                    'sale_return',
                                    (string)$return->id,
                                    'Sale return voided #' . $return->reference_no
                                );
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::warning('Stock reversal on sale return delete failed: ' . $e->getMessage());
                            }
                        }
                    }
                }
            }

            // Reverse cash register if it was a cash refund
            $refundTender = strtolower($return->refund_tender ?? $return->payment_type ?? 'cash');
            if (strtolower($refundTender) === 'cash' && $return->paid_amount > 0 && $warehouseId) {
                $activeReg = \App\Models\CashRegister::where('status', 'open')
                    ->where('warehouse_id', $warehouseId)
                    ->latest()
                    ->first();
                if ($activeReg) {
                    $activeReg->increment('expected_cash', $return->paid_amount);
                    if ($activeReg->closing_cash !== null) {
                        $activeReg->increment('closing_cash', $return->paid_amount);
                    }
                }
            }
        }

        $return->delete();
        return response()->json(['status' => 'success', 'message' => 'Sale return deleted']);
    }

    /**
     * Add a refund payment to a sale return
     */
    public function addPayment(Request $request, $id)
    {
        $return = is_numeric($id)
            ? SaleReturn::find($id)
            : SaleReturn::where('reference_no', $id)->first();
        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Sale return record not found'], 404);
        }

        $validated = $request->validate([
            'amount'       => 'required_without:payments|nullable|numeric|min:0.01',
            'payment_type' => 'required_without:payments|nullable|string',
            'payment_date' => 'nullable|string',
            'notes'        => 'nullable|string',
            'payments'     => 'nullable|array|min:1',
            'payments.*.amount'          => 'required|numeric|min:0.01',
            'payments.*.payment_type'    => 'required|string',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount'   => 'nullable|numeric|min:0',
            'payments.*.payment_date'    => 'nullable|string',
            'payments.*.notes'           => 'nullable|string',
            'user_name'    => 'nullable|string|max:255',
            'user_role'    => 'nullable|string|max:255',
        ]);

        $existingPayments = $return->payments;
        $updatedPayments = array_values($existingPayments);
        $authUser = $request->user();
        $globalUserName = $validated['user_name'] ?? ($authUser?->name ?? ($return->user_name ?: 'Super Admin'));
        $globalUserRole = $validated['user_role'] ?? ($authUser?->role ?? ($return->user_role ?: 'Super Admin'));

        $cashRefundAdded = 0.0;

        if (!empty($validated['payments']) && is_array($validated['payments'])) {
            foreach ($validated['payments'] as $tender) {
                $pAmt = (float)$tender['amount'];
                if ($pAmt <= 0) continue;
                $newPaymentId = count($updatedPayments) + 1;
                $cleanRef = preg_replace('/^#\s*/', '', trim((string)$return->reference_no));
                $paymentRef = 'PAY-' . ($cleanRef ?: $return->id) . '-' . time() . '-' . rand(100, 999);
                $pType = $tender['payment_type'] ?? 'Cash';
                if (strtolower(trim($pType)) === 'cash') {
                    $cashRefundAdded += $pAmt;
                }
                $updatedPayments[] = [
                    'id'              => $newPaymentId,
                    'reference_no'    => $paymentRef,
                    'payment_date'    => !empty($tender['payment_date']) ? $tender['payment_date'] : (!empty($validated['payment_date']) ? $validated['payment_date'] : now()->toDateTimeString()),
                    'payment_type'    => $pType,
                    'amount'          => $pAmt,
                    'received_amount' => !empty($tender['received_amount']) ? (float)$tender['received_amount'] : $pAmt,
                    'change_amount'   => !empty($tender['change_amount']) ? (float)$tender['change_amount'] : 0.0,
                    'user_name'       => $tender['user_name'] ?? $globalUserName,
                    'user_role'       => $tender['user_role'] ?? $globalUserRole,
                    'notes'           => $tender['notes'] ?? ($validated['notes'] ?? ''),
                ];
            }
        } else {
            $payingAmount = (float)$validated['amount'];
            $newPaymentId = count($updatedPayments) + 1;
            $cleanRef = preg_replace('/^#\s*/', '', trim((string)$return->reference_no));
            $paymentRef = 'PAY-' . ($cleanRef ?: $return->id) . '-' . time() . '-' . rand(100, 999);
            $pType = $validated['payment_type'] ?? 'Cash';
            if (strtolower(trim($pType)) === 'cash') {
                $cashRefundAdded += $payingAmount;
            }
            $updatedPayments[] = [
                'id'              => $newPaymentId,
                'reference_no'    => $paymentRef,
                'payment_date'    => !empty($validated['payment_date']) ? $validated['payment_date'] : now()->toDateTimeString(),
                'payment_type'    => $pType,
                'amount'          => $payingAmount,
                'received_amount' => $payingAmount,
                'change_amount'   => 0.0,
                'user_name'       => $globalUserName,
                'user_role'       => $globalUserRole,
                'notes'           => $validated['notes'] ?? '',
            ];
        }

        $newPaid = (float)collect($updatedPayments)->sum('amount');
        $newDue = max(0, (float)$return->grand_total - $newPaid);
        $newStatus = $newDue <= 0.001 ? 'Paid' : ($newPaid > 0 ? 'Partial' : 'Unpaid');

        $uniqueTypes = collect($updatedPayments)->pluck('payment_type')->filter()->unique();
        if ($uniqueTypes->count() > 1) {
            $newPaymentType = 'Split Payment';
        } elseif ($uniqueTypes->count() === 1) {
            $newPaymentType = $uniqueTypes->first();
        } else {
            $newPaymentType = $return->payment_type ?: 'Cash';
        }

        DB::beginTransaction();
        try {
            $return->update([
                'payments_payload' => json_encode($updatedPayments),
                'paid_amount'      => $newPaid,
                'due_amount'       => $newDue,
                'payment_status'   => $newStatus,
                'payment_type'     => $newPaymentType,
            ]);

            // Deduct cash from active drawer if Cash refund tender was added
            if ($cashRefundAdded > 0) {
                $warehouseId = $return->warehouse_id;
                $activeReg = \App\Models\CashRegister::where('status', 'open')
                    ->where(function ($q) use ($return, $warehouseId) {
                        if ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId);
                        } elseif (!empty($return->warehouse_name)) {
                            $q->where('warehouse_name', $return->warehouse_name);
                        }
                    })
                    ->latest()
                    ->first();
                if ($activeReg) {
                    $activeReg->decrement('expected_cash', $cashRefundAdded);
                    if ($activeReg->closing_cash !== null) {
                        $activeReg->decrement('closing_cash', $cashRefundAdded);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment added successfully',
                'data'    => $return->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a refund payment transaction from a sale return
     */
    public function deletePayment(Request $request, $returnId, $paymentId = null)
    {
        $return = is_numeric($returnId)
            ? SaleReturn::find($returnId)
            : SaleReturn::where('reference_no', $returnId)->first();

        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Sale return record not found'], 404);
        }

        $existingPayments = $return->payments;
        if (empty($existingPayments)) {
            $return->update([
                'payments_payload' => json_encode([]),
                'paid_amount'      => 0.0,
                'due_amount'       => (float)$return->grand_total,
                'payment_status'   => 'Unpaid',
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment deleted successfully. Due balance restored.',
                'data'    => $return->fresh(),
            ]);
        }

        $targetIdentifier = trim((string)($paymentId ?? $returnId));

        $remainingPayments = [];
        $deletedPayment = null;

        foreach ($existingPayments as $p) {
            $pId  = isset($p['id']) ? trim((string)$p['id']) : '';
            $pRef = isset($p['reference_no']) ? trim((string)$p['reference_no']) : '';

            $matchById = ($pId !== '' && $pId === $targetIdentifier);
            $matchByRef = ($pRef !== '' && ($pRef === $targetIdentifier || str_contains($pRef, $targetIdentifier) || str_contains($targetIdentifier, $pRef)));

            if ($deletedPayment === null && ($matchById || $matchByRef)) {
                $deletedPayment = $p;
                continue;
            }
            $remainingPayments[] = $p;
        }

        if ($deletedPayment === null && count($existingPayments) === 1) {
            $deletedPayment = $existingPayments[0];
            $remainingPayments = [];
        }

        $newPaid = (float)collect($remainingPayments)->sum('amount');
        $newDue = max(0, (float)$return->grand_total - $newPaid);
        $newStatus = $newPaid <= 0.001 ? 'Unpaid' : ($newDue <= 0.001 ? 'Paid' : 'Partial');

        $uniqueTypes = collect($remainingPayments)->pluck('payment_type')->filter()->unique();
        if ($uniqueTypes->count() > 1) {
            $newPaymentType = 'Split Payment';
        } elseif ($uniqueTypes->count() === 1) {
            $newPaymentType = $uniqueTypes->first();
        } else {
            $newPaymentType = $return->payment_type ?: 'Cash';
        }

        DB::beginTransaction();
        try {
            $return->update([
                'payments_payload' => json_encode(array_values($remainingPayments)),
                'paid_amount'      => $newPaid,
                'due_amount'       => $newDue,
                'payment_status'   => $newStatus,
                'payment_type'     => $newPaymentType,
            ]);

            // If deleted payment was Cash, restore drawer cash
            if ($deletedPayment && strtolower(trim($deletedPayment['payment_type'] ?? '')) === 'cash') {
                $delAmount = (float)($deletedPayment['amount'] ?? 0);
                if ($delAmount > 0) {
                    $warehouseId = $return->warehouse_id;
                    $activeReg = \App\Models\CashRegister::where('status', 'open')
                        ->where(function ($q) use ($return, $warehouseId) {
                            if ($warehouseId) {
                                $q->where('warehouse_id', $warehouseId);
                            } elseif (!empty($return->warehouse_name)) {
                                $q->where('warehouse_name', $return->warehouse_name);
                            }
                        })
                        ->latest()
                        ->first();
                    if ($activeReg) {
                        $activeReg->increment('expected_cash', $delAmount);
                        if ($activeReg->closing_cash !== null) {
                            $activeReg->increment('closing_cash', $delAmount);
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment deleted successfully. Due balance restored.',
                'data'    => $return->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Failed to delete payment: ' . $e->getMessage()], 500);
        }
    }
}
