<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseReturnController extends Controller
{
    /** Return reason options for purchase returns */
    public const RETURN_REASONS = [
        'Defective / Damaged Product',
        'Wrong Item Received',
        'Expired Product',
        'Quality Not Meeting Standard',
        'Excess Stock Returned',
        'Price Dispute',
        'Other',
    ];

    public function index(Request $request)
    {
        $query = PurchaseReturn::query();

        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $query->where('warehouse_name', $request->input('warehouse'));
        }

        if ($request->filled('outlet_id')) {
            $outletId = $request->input('outlet_id');
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)
                  ->orWhere('warehouse_id', $outletId);
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('return_reason') && $request->input('return_reason') !== 'All') {
            $query->where('return_reason', $request->input('return_reason'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('purchase_reference_no', 'like', "%{$search}%")
                  ->orWhere('supplier_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('return_reason', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        if ($isAll) {
            $totalCount = (clone $query)->count();
            $limit = min(500, max(1, (int)$request->input('limit', 500)));
            $returns = $query->orderBy('id', 'desc')->take($limit)->get();
            return response()->json([
                'status' => 'success',
                'data'   => $returns,
                'pagination' => [
                    'current_page' => 1,
                    'per_page'     => $returns->count(),
                    'total'        => $totalCount,
                    'last_page'    => (int)ceil($totalCount / max(1, $limit)),
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
        $return = PurchaseReturn::find($id);
        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $return,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no'          => 'nullable|string|max:100',
            'purchase_reference_no' => 'nullable|string|max:100',
            'supplier_id'           => 'nullable|integer',
            'supplier_name'         => 'required|string|max:255',
            'warehouse_id'          => 'nullable|integer',
            'warehouse_name'        => 'required|string|max:255',
            'outlet_id'             => 'nullable|integer',
            'date'                  => 'required|string',
            'grand_total'           => 'required|numeric|min:0',
            'paid_amount'           => 'nullable|numeric|min:0',
            'status'                => 'nullable|string|in:Completed,Pending,Received',
            'payment_status'        => 'nullable|string',
            'payment_type'          => 'nullable|string|max:100',
            'subtotal'              => 'nullable|numeric|min:0',
            'tax_rate'              => 'nullable|numeric|min:0',
            'tax_amount'            => 'nullable|numeric|min:0',
            'discount'              => 'nullable|numeric|min:0',
            'shipping'              => 'nullable|numeric|min:0',
            'notes'                 => 'nullable|string',
            'return_reason'         => 'nullable|string|max:255',
            'items'                 => 'nullable|array',
            'payments'              => 'nullable|array',
            'payments.*.amount'     => 'nullable|numeric|min:0.01',
            'payments.*.payment_type' => 'nullable|string',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount' => 'nullable|numeric|min:0',
            'payments.*.payment_date' => 'nullable|string',
            'payments.*.notes'      => 'nullable|string',
            'created_by'            => 'nullable|string|max:255',
            'user_id'               => 'nullable|integer',
            'user_name'             => 'nullable|string|max:255',
            'user_role'             => 'nullable|string|max:255',
        ]);

        $prefix     = Setting::get('purchase_return_prefix', 'PR_');
        $refNo      = !empty($validated['reference_no'])
            ? $validated['reference_no']
            : ($prefix . date('Ymd') . '-' . rand(1000, 9999));

        $grandTotal    = (float)$validated['grand_total'];
        $paidAmount    = (float)($validated['paid_amount'] ?? $grandTotal);
        $dueAmount     = max(0, $grandTotal - $paidAmount);
        $paymentStatus = $validated['payment_status'] ?? ($dueAmount == 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid'));
        $returnStatus  = $validated['status'] ?? 'Completed';

        $userName  = !empty($validated['user_name'])
            ? $validated['user_name']
            : ($request->user()?->name ?? 'Admin User');
        $userRole  = !empty($validated['user_role'])
            ? $validated['user_role']
            : ($request->user()?->role ?? 'Super Admin');
        $createdBy = !empty($validated['created_by'])
            ? $validated['created_by']
            : (!empty($userName) ? "{$userName} ({$userRole})" : 'Admin User');
        $userId       = $validated['user_id'] ?? $request->user()?->id;
        $warehouseId  = $validated['warehouse_id'] ?? $validated['outlet_id'] ?? null;
        $outletId     = $validated['outlet_id'] ?? $validated['warehouse_id'] ?? null;

        $txDate = trim($validated['date']);
        if (!str_contains($txDate, ':')) {
            $txDate .= ' ' . now()->format('H:i:s');
        }

        // Auto-resolve warehouseId by name if not provided
        if (!$warehouseId && !empty($validated['warehouse_name'])) {
            $unitService = app(\App\Services\ProductUnitConversionService::class);
            $warehouseId = $unitService->warehouseId(null, $validated['warehouse_name']);
            $outletId    = $outletId ?? $warehouseId;
        }

        // Build items payload
        $itemsPayload = !empty($validated['items']) ? json_encode($validated['items']) : null;

        // Credit note notes
        $creditNoteNote = "Credit Note: Returned to {$validated['supplier_name']} on " . now()->toDateString();
        $combinedNotes  = trim(($validated['notes'] ?? '') . "\n" . $creditNoteNote);

        DB::beginTransaction();
        try {
            $return = PurchaseReturn::create([
                'reference_no'          => $refNo,
                'purchase_reference_no' => $validated['purchase_reference_no'] ?? null,
                'supplier_id'           => $validated['supplier_id'] ?? null,
                'supplier_name'         => $validated['supplier_name'],
                'warehouse_id'          => $warehouseId,
                'warehouse_name'        => $validated['warehouse_name'],
                'outlet_id'             => $outletId,
                'date'                  => $txDate,
                'status'                => $returnStatus,
                'grand_total'           => $grandTotal,
                'paid_amount'           => $paidAmount,
                'due_amount'            => $dueAmount,
                'payment_status'        => $paymentStatus,
                'payment_type'          => $validated['payment_type'] ?? 'Cash',
                'subtotal'              => $validated['subtotal'] ?? 0.0,
                'tax_rate'              => $validated['tax_rate'] ?? 0.0,
                'tax_amount'            => $validated['tax_amount'] ?? 0.0,
                'discount'              => $validated['discount'] ?? 0.0,
                'shipping'              => $validated['shipping'] ?? 0.0,
                'notes'                 => $combinedNotes,
                'return_reason'         => $validated['return_reason'] ?? null,
                'items_payload'         => $itemsPayload,
                'created_by'            => $createdBy,
                'user_id'               => $userId,
                'user_name'             => $userName,
                'user_role'             => $userRole,
            ]);

            // ── Stock Deduction (Goods leaving warehouse to return to supplier) ──
            // Only on Completed status
            if ($returnStatus === 'Completed' && !empty($validated['items']) && is_array($validated['items']) && $warehouseId) {
                $unitService = app(\App\Services\ProductUnitConversionService::class);
                $fifoService = app(\App\Services\FifoInventoryService::class);

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
                        $unitCost       = (float)($it['unit_cost'] ?? $it['unit_price'] ?? $product->cost_price ?? $product->purchase_price ?? 0);
                        $fallbackCost   = $conversionRate > 0 ? ($unitCost / $conversionRate) : $unitCost;

                        if ($baseQty > 0) {
                            try {
                                // Reduce stock in warehouse
                                $unitService->applyStock(
                                    $product,
                                    (int)$warehouseId,
                                    $variantId,
                                    $baseQty,
                                    'out',
                                    'purchase_return',
                                    'purchase_return',
                                    (string)$return->id,
                                    'Purchase return to supplier #' . $return->reference_no
                                );

                                // Consume FIFO batches (oldest cost layers first)
                                $fifoService->depleteFifo(
                                    warehouseId:   (int)$warehouseId,
                                    productId:     (int)$product->id,
                                    variantId:     $variantId,
                                    neededBaseQty: (float)$baseQty,
                                    saleId:        (int)$return->id,
                                    saleItemId:    0,
                                    saleDate:      $txDate,
                                    fallbackCost:  (float)$fallbackCost
                                );
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::warning('Stock deduction on purchase return failed: ' . $e->getMessage());
                            }
                        }
                    }
                }
            }

            // Record initial refund payment transaction(s) - supporting Split Payments & multi-tenders
            if (!empty($validated['payments']) && is_array($validated['payments'])) {
                $savedPayments = [];
                $tenderIdx = 1;
                $cleanRef = preg_replace('/^#\s*/', '', trim((string)$return->reference_no));
                foreach ($validated['payments'] as $p) {
                    $pAmount = (float)($p['amount'] ?? 0);
                    if ($pAmount <= 0) continue;
                    $paymentRef = 'PAY-' . ($cleanRef ?: $return->id) . '-' . time() . '-' . rand(100, 999);
                    $savedPayments[] = [
                        'id'              => $tenderIdx,
                        'reference_no'    => $paymentRef,
                        'payment_date'    => !empty($p['payment_date']) ? $p['payment_date'] : (!empty($validated['date']) ? $validated['date'] : now()->toDateTimeString()),
                        'payment_type'    => $p['payment_type'] ?? ($return->payment_type ?: 'Cash'),
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
                $cleanRef = preg_replace('/^#\s*/', '', trim((string)$return->reference_no));
                $paymentRef = 'PAY-' . ($cleanRef ?: $return->id) . '-' . time() . '-' . rand(100, 999);
                $initialPayment = [
                    'id'              => 1,
                    'reference_no'    => $paymentRef,
                    'payment_date'    => $txDate,
                    'payment_type'    => $validated['payment_type'] ?? 'Cash',
                    'amount'          => $paidAmount,
                    'received_amount' => $paidAmount,
                    'change_amount'   => 0.0,
                    'user_name'       => $userName,
                    'user_role'       => $userRole,
                    'notes'           => $validated['notes'] ?? '',
                ];
                $return->update(['payments_payload' => json_encode([$initialPayment])]);
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Purchase return recorded successfully',
                'data'    => $return,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $return = PurchaseReturn::find($id);
        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }

        $validated = $request->validate([
            'reference_no'          => 'nullable|string|max:100',
            'purchase_reference_no' => 'nullable|string|max:100',
            'supplier_id'           => 'nullable|integer',
            'supplier_name'         => 'sometimes|required|string|max:255',
            'warehouse_id'          => 'nullable|integer',
            'warehouse_name'        => 'sometimes|required|string|max:255',
            'outlet_id'             => 'nullable|integer',
            'date'                  => 'sometimes|required|string',
            'grand_total'           => 'sometimes|required|numeric|min:0',
            'paid_amount'           => 'nullable|numeric|min:0',
            'status'                => 'nullable|string|in:Completed,Pending,Received',
            'payment_status'        => 'nullable|string',
            'payment_type'          => 'nullable|string|max:100',
            'subtotal'              => 'nullable|numeric|min:0',
            'tax_rate'              => 'nullable|numeric|min:0',
            'tax_amount'            => 'nullable|numeric|min:0',
            'discount'              => 'nullable|numeric|min:0',
            'shipping'              => 'nullable|numeric|min:0',
            'notes'                 => 'nullable|string',
            'return_reason'         => 'nullable|string|max:255',
            'items'                 => 'nullable|array',
            'created_by'            => 'nullable|string|max:255',
            'user_id'               => 'nullable|integer',
            'user_name'             => 'nullable|string|max:255',
            'user_role'             => 'nullable|string|max:255',
        ]);

        $grandTotal    = isset($validated['grand_total']) ? (float)$validated['grand_total'] : (float)$return->grand_total;
        $paidAmount    = isset($validated['paid_amount']) ? (float)$validated['paid_amount'] : (float)$return->paid_amount;
        $dueAmount     = isset($validated['due_amount']) ? (float)$validated['due_amount'] : max(0, $grandTotal - $paidAmount);
        $paymentStatus = $validated['payment_status'] ?? ($dueAmount == 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid'));

        $userName  = !empty($validated['user_name'])
            ? $validated['user_name']
            : ($return->user_name ?? $request->user()?->name ?? 'Admin User');
        $userRole  = !empty($validated['user_role'])
            ? $validated['user_role']
            : ($return->user_role ?? $request->user()?->role ?? 'Super Admin');
        $createdBy = !empty($validated['created_by'])
            ? $validated['created_by']
            : ($return->created_by ?? (!empty($userName) ? "{$userName} ({$userRole})" : 'Admin User'));

        $updateData = [
            'reference_no'          => $validated['reference_no'] ?? $return->reference_no,
            'purchase_reference_no' => $validated['purchase_reference_no'] ?? $return->purchase_reference_no,
            'supplier_id'           => $validated['supplier_id'] ?? $return->supplier_id,
            'supplier_name'         => $validated['supplier_name'] ?? $return->supplier_name,
            'warehouse_id'          => $validated['warehouse_id'] ?? $validated['outlet_id'] ?? $return->warehouse_id,
            'warehouse_name'        => $validated['warehouse_name'] ?? $return->warehouse_name,
            'outlet_id'             => $validated['outlet_id'] ?? $validated['warehouse_id'] ?? $return->outlet_id,
            'date'                  => $validated['date'] ?? $return->date,
            'status'                => $validated['status'] ?? $return->status,
            'grand_total'           => $grandTotal,
            'paid_amount'           => $paidAmount,
            'due_amount'            => $dueAmount,
            'payment_status'        => $paymentStatus,
            'payment_type'          => $validated['payment_type'] ?? $return->payment_type,
            'subtotal'              => $validated['subtotal'] ?? $return->subtotal,
            'tax_rate'              => $validated['tax_rate'] ?? $return->tax_rate,
            'tax_amount'            => $validated['tax_amount'] ?? $return->tax_amount,
            'discount'              => $validated['discount'] ?? $return->discount,
            'shipping'              => $validated['shipping'] ?? $return->shipping,
            'notes'                 => $validated['notes'] ?? $return->notes,
            'return_reason'         => $validated['return_reason'] ?? $return->return_reason,
            'created_by'            => $createdBy,
            'user_id'               => $validated['user_id'] ?? $return->user_id ?? $request->user()?->id,
            'user_name'             => $userName,
            'user_role'             => $userRole,
        ];

        if (isset($validated['items'])) {
            $updateData['items_payload'] = json_encode($validated['items']);
        }

        $return->update($updateData);

        return response()->json([
            'status'  => 'success',
            'message' => 'Purchase return updated successfully',
            'data'    => $return->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $return = PurchaseReturn::find($id);
        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }

        // ── Reverse Stock on Delete ───────────────────────────────────────────
        // If Completed, goods were removed from warehouse. Deleting reverses that:
        // goods come back into warehouse (re-add stock).
        if ($return->status === 'Completed') {
            $warehouseId = $return->warehouse_id ?? $return->outlet_id;

            if (!$warehouseId && !empty($return->warehouse_name)) {
                $unitService = app(\App\Services\ProductUnitConversionService::class);
                $warehouseId = $unitService->warehouseId(null, $return->warehouse_name);
            }

            $items = $return->items;
            if (!empty($items) && is_array($items) && $warehouseId) {
                $unitService = app(\App\Services\ProductUnitConversionService::class);
                $fifoService = app(\App\Services\FifoInventoryService::class);

                foreach ($items as $it) {
                    $productId = $it['product_id'] ?? null;
                    $variantId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
                    $product   = $productId ? \App\Models\Product::find($productId) : null;

                    if ($product) {
                        $conversionRate = (float)($it['conversion_rate'] ?? 1.0);
                        $qty            = (float)($it['quantity'] ?? 0);
                        $baseQty        = (float)($it['base_quantity'] ?? ($qty * $conversionRate));
                        $unitCost       = (float)($it['unit_cost'] ?? $it['unit_price'] ?? $product->cost_price ?? $product->purchase_price ?? 0);
                        $baseUnitCost   = $conversionRate > 0 ? ($unitCost / $conversionRate) : $unitCost;

                        if ($baseQty > 0) {
                            try {
                                // Stock comes back into warehouse (return was voided)
                                $unitService->applyStock(
                                    $product,
                                    (int)$warehouseId,
                                    $variantId,
                                    $baseQty,
                                    'in',
                                    'purchase_return_void',
                                    'purchase_return',
                                    (string)$return->id,
                                    'Purchase return voided #' . $return->reference_no
                                );

                                // Re-add FIFO cost layer
                                $fifoService->recordBatch(
                                    warehouseId:     (int)$warehouseId,
                                    productId:       (int)$product->id,
                                    variantId:       $variantId,
                                    baseQuantity:    (float)$baseQty,
                                    baseUnitCost:    (float)$baseUnitCost,
                                    sourceType:      'purchase_return_void',
                                    sourceReference: (string)$return->reference_no,
                                    receivedDate:    (string)$return->date,
                                    notes:           'Purchase return voided — stock restored'
                                );
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::warning('Stock reversal on purchase return delete failed: ' . $e->getMessage());
                            }
                        }
                    }
                }
            }
        }

        $return->delete();
        return response()->json(['status' => 'success', 'message' => 'Purchase return deleted']);
    }

    /**
     * Add a refund payment to a purchase return
     */
    public function addPayment(Request $request, $id)
    {
        $return = PurchaseReturn::find($id) ?? PurchaseReturn::where('reference_no', $id)->first();
        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Purchase return not found'], 404);
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
        $globalUserName = $validated['user_name'] ?? ($authUser?->name ?? ($return->user_name ?: 'Admin User'));
        $globalUserRole = $validated['user_role'] ?? ($authUser?->role ?? ($return->user_role ?: 'Super Admin'));

        if (!empty($validated['payments']) && is_array($validated['payments'])) {
            foreach ($validated['payments'] as $tender) {
                $pAmt = (float)$tender['amount'];
                $newPaymentId = count($updatedPayments) + 1;
                $cleanRef = preg_replace('/^#\s*/', '', trim((string)$return->reference_no));
                $paymentRef = 'PAY-' . ($cleanRef ?: $return->id) . '-' . time() . '-' . rand(100, 999);
                $updatedPayments[] = [
                    'id'              => $newPaymentId,
                    'reference_no'    => $paymentRef,
                    'payment_date'    => !empty($tender['payment_date']) ? $tender['payment_date'] : (!empty($validated['payment_date']) ? $validated['payment_date'] : now()->toDateTimeString()),
                    'payment_type'    => $tender['payment_type'],
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
            $updatedPayments[] = [
                'id'              => $newPaymentId,
                'reference_no'    => $paymentRef,
                'payment_date'    => !empty($validated['payment_date']) ? $validated['payment_date'] : now()->toDateTimeString(),
                'payment_type'    => $validated['payment_type'],
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
     * Delete a refund payment transaction from a purchase return
     */
    public function deletePayment(Request $request, $returnId, $paymentId = null)
    {
        if ($paymentId === null) {
            $return = PurchaseReturn::find($returnId) ?? PurchaseReturn::where('reference_no', $returnId)->first();
        } else {
            $return = PurchaseReturn::find($returnId) ?? PurchaseReturn::where('reference_no', $returnId)->first();
        }

        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Purchase return not found'], 404);
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

        // Target payment identifier (can be id or reference_no)
        $targetIdentifier = trim((string)($paymentId ?? $returnId));

        $remainingPayments = [];
        $deletedFound = false;

        foreach ($existingPayments as $p) {
            $pId  = isset($p['id']) ? trim((string)$p['id']) : '';
            $pRef = isset($p['reference_no']) ? trim((string)$p['reference_no']) : '';

            $matchById = ($pId !== '' && $pId === $targetIdentifier);
            $matchByRef = ($pRef !== '' && ($pRef === $targetIdentifier || str_contains($pRef, $targetIdentifier) || str_contains($targetIdentifier, $pRef)));

            if (!$deletedFound && ($matchById || $matchByRef)) {
                $deletedFound = true;
                continue;
            }
            $remainingPayments[] = $p;
        }

        // If target wasn't found by identifier and there was only 1 payment, delete that payment
        if (!$deletedFound && count($existingPayments) === 1) {
            $deletedFound = true;
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

