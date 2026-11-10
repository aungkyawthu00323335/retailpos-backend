<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Setting;
use App\Models\User;
use App\Services\ProductUnitConversionService;
use App\Services\FifoInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseReturnController extends Controller
{
    use \App\Traits\GeneratesSequentialCode;
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

    /**
     * Generate next sequential purchase return code and formatted reference number.
     */
    public function generateCode()
    {
        return response()->json($this->peekNextSequentialCode('purchase_return'));
    }

    /**
     * Shared filter builder for PurchaseReturn index and export operations.
     */
    protected function buildFilteredQuery(Request $request, bool $forExport = false)
    {
        $query = PurchaseReturn::query();

        // 1. Warehouse / Outlet filtering
        if ($request->filled('warehouse_id')) {
            $wId = (int)$request->input('warehouse_id');
            $query->where(function ($q) use ($wId) {
                $q->where('warehouse_id', $wId)
                  ->orWhere('outlet_id', $wId);
            });
        } elseif ($request->filled('outlet_id')) {
            $outletId = (int)$request->input('outlet_id');
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)
                  ->orWhere('warehouse_id', $outletId);
            });
        }

        $warehouseName = $request->input('warehouse') ?? $request->input('warehouse_name');
        if (!empty($warehouseName) && $warehouseName !== 'All') {
            $query->where(function ($q) use ($warehouseName) {
                $q->where('warehouse_name', $warehouseName);
                if (is_numeric($warehouseName)) {
                    $q->orWhere('warehouse_id', (int)$warehouseName)
                      ->orWhere('outlet_id', (int)$warehouseName);
                }
            });
        }

        // 2. Supplier filtering
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int)$request->input('supplier_id'));
        }
        $supplier = $request->input('supplier') ?? $request->input('supplier_name');
        if (!empty($supplier) && $supplier !== 'All') {
            $query->where(function ($q) use ($supplier) {
                $q->where('supplier_name', $supplier);
                if (is_numeric($supplier)) {
                    $q->orWhere('supplier_id', (int)$supplier);
                }
            });
        }

        // 3. Return Status filtering (Completed, Pending, Received)
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        // 4. Payment Status filtering (Paid, Partial, Unpaid)
        $paymentStatus = $request->input('payment_status') ?? $request->input('payment');
        if (!empty($paymentStatus) && $paymentStatus !== 'All') {
            $query->where('payment_status', $paymentStatus);
        }

        // 5. Payment Type / Refund Tender filtering (Cash, Bank Transfer, Credit Note, Cheque)
        $paymentType = $request->input('payment_type') ?? $request->input('payment_method');
        if (!empty($paymentType) && $paymentType !== 'All') {
            $query->where('payment_type', $paymentType);
        }

        // 6. Return Reason filtering
        if ($request->filled('return_reason') && $request->input('return_reason') !== 'All') {
            $query->where('return_reason', $request->input('return_reason'));
        }

        // 7. Date range filtering
        $fromDate = $request->input('from_date') ?? $request->input('date_from') ?? $request->input('start_date');
        $toDate = $request->input('to_date') ?? $request->input('date_to') ?? $request->input('end_date');

        if (!empty($fromDate)) {
            $fromStr = trim((string)$fromDate);
            if (strlen($fromStr) === 10) {
                $fromStr .= ' 00:00:00';
            }
            $query->where('date', '>=', $fromStr);
        }

        if (!empty($toDate)) {
            $toStr = trim((string)$toDate);
            if (strlen($toStr) === 10) {
                $toStr .= ' 23:59:59';
            }
            $query->where('date', '<=', $toStr);
        }

        // 8. Staff / Created By filtering
        $user = $request->input('created_by') ?? $request->input('user') ?? $request->input('user_name');
        if (!empty($user) && $user !== 'All') {
            $query->where(function ($q) use ($user) {
                $q->where('created_by', 'like', "%{$user}%")
                  ->orWhere('user_name', 'like', "%{$user}%");
            });
        }

        // 9. Global Search
        if ($request->filled('search')) {
            $search = trim((string)$request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('purchase_reference_no', 'like', "%{$search}%")
                  ->orWhere('supplier_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('return_reason', 'like', "%{$search}%")
                  ->orWhere('payment_type', 'like', "%{$search}%")
                  ->orWhere('payment_status', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->buildFilteredQuery($request, false);

        // Sorting
        $sortColumn = $request->input('sort_by', 'id');
        $sortDirection = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortColumnMap = [
            'reference'     => 'reference_no',
            'purchaseRef'   => 'purchase_reference_no',
            'supplier'      => 'supplier_name',
            'warehouse'     => 'warehouse_name',
            'amount'        => 'grand_total',
            'paid'          => 'paid_amount',
            'due'           => 'due_amount',
            'paymentStatus' => 'payment_status',
            'paymentType'   => 'payment_type',
            'createdBy'     => 'created_by',
        ];
        if (isset($sortColumnMap[$sortColumn])) {
            $sortColumn = $sortColumnMap[$sortColumn];
        }
        $allowedSorts = [
            'id', 'date', 'reference_no', 'purchase_reference_no', 'supplier_name',
            'warehouse_name', 'status', 'grand_total', 'paid_amount', 'due_amount',
            'payment_status', 'payment_type', 'created_by', 'user_name'
        ];
        if (!in_array($sortColumn, $allowedSorts, true)) {
            $sortColumn = 'id';
        }
        // Aggregate summary in a single fast DB query without model hydration and strict mode safe
        $aggregatesQuery = (clone $query)->toBase();
        $aggregatesQuery->columns = null;
        $aggregatesQuery->orders = null;
        $totalsAgg = $aggregatesQuery->selectRaw('
            COUNT(*) as total_records,
            COALESCE(SUM(grand_total), 0) as total_grand_total,
            COALESCE(SUM(paid_amount), 0) as total_paid_amount,
            COALESCE(SUM(due_amount), 0) as total_due_amount,
            COALESCE(SUM(subtotal), 0) as total_subtotal,
            COALESCE(SUM(tax_amount), 0) as total_tax_amount,
            COALESCE(SUM(discount), 0) as total_discount,
            COALESCE(SUM(shipping), 0) as total_shipping
        ')->first();

        $totalCount = (int)($totalsAgg->total_records ?? 0);

        // Apply ordering ONLY to the actual paginated records query
        $query->orderBy($sortColumn, $sortDirection);

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        if ($isAll) {
            $limit = max(1, (int)$request->input('limit', 1000));
            if (!$request->has('limit')) {
                $returns = $query->take(500)->get();
            } else {
                $returns = $query->take($limit)->get();
            }

            return response()->json([
                'status' => 'success',
                'data'   => $returns,
                'pagination' => [
                    'current_page' => 1,
                    'per_page'     => $returns->count(),
                    'total'        => $totalCount,
                    'last_page'    => (int)ceil($totalCount / max(1, $returns->count())),
                ],
                'summary' => [
                    'total_records'     => $totalCount,
                    'total_grand_total' => (float)$totalsAgg->total_grand_total,
                    'total_paid_amount' => (float)$totalsAgg->total_paid_amount,
                    'total_due_amount'  => (float)$totalsAgg->total_due_amount,
                    'total_subtotal'    => (float)$totalsAgg->total_subtotal,
                    'total_tax_amount'  => (float)$totalsAgg->total_tax_amount,
                    'total_discount'    => (float)$totalsAgg->total_discount,
                    'total_shipping'    => (float)$totalsAgg->total_shipping,
                ],
                'meta' => [
                    'total' => $totalCount,
                ],
            ]);
        }

        $perPage = min(max((int)$request->input('per_page', 25), 1), 500);
        $returns = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $returns->items(),
            'pagination' => [
                'current_page' => $returns->currentPage(),
                'per_page'     => $returns->perPage(),
                'total'        => $returns->total(),
                'last_page'    => $returns->lastPage(),
            ],
            'summary' => [
                'total_records'     => $totalCount,
                'total_grand_total' => (float)$totalsAgg->total_grand_total,
                'total_paid_amount' => (float)$totalsAgg->total_paid_amount,
                'total_due_amount'  => (float)$totalsAgg->total_due_amount,
                'total_subtotal'    => (float)$totalsAgg->total_subtotal,
                'total_tax_amount'  => (float)$totalsAgg->total_tax_amount,
                'total_discount'    => (float)$totalsAgg->total_discount,
                'total_shipping'    => (float)$totalsAgg->total_shipping,
            ],
            'meta' => [
                'total' => $returns->total(),
            ],
        ]);
    }

    /**
     * High-performance chunked CSV export streaming directly from MySQL database.
     * Guarantees UTF-8 BOM, 22 standard columns, metadata block, and accounting totals summary row.
     */
    public function export(Request $request)
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $query = $this->buildFilteredQuery($request);

        $sortColumn = $request->input('sort_by', 'id');
        $sortDirection = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortColumnMap = [
            'reference'     => 'reference_no',
            'purchaseRef'   => 'purchase_reference_no',
            'supplier'      => 'supplier_name',
            'warehouse'     => 'warehouse_name',
            'amount'        => 'grand_total',
            'paid'          => 'paid_amount',
            'due'           => 'due_amount',
            'paymentStatus' => 'payment_status',
            'paymentType'   => 'payment_type',
            'createdBy'     => 'created_by',
        ];
        if (isset($sortColumnMap[$sortColumn])) {
            $sortColumn = $sortColumnMap[$sortColumn];
        }
        $allowedSorts = [
            'id', 'date', 'reference_no', 'purchase_reference_no', 'supplier_name',
            'warehouse_name', 'status', 'grand_total', 'paid_amount', 'due_amount',
            'payment_status', 'payment_type', 'created_by', 'user_name'
        ];
        if (!in_array($sortColumn, $allowedSorts, true)) {
            $sortColumn = 'id';
        }

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Accel-Buffering' => 'no',
            'Content-Disposition' => 'attachment; filename="purchase_returns_export_' . date('Ymd_His') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Expires' => '0',
        ];

        // Metadata labels
        $warehouseFilter = $request->input('warehouse') ?: ($request->input('warehouse_name') ?: 'All');
        $statusFilter = $request->input('status') ?: 'All';
        $paymentFilter = $request->input('payment_status') ?: ($request->input('payment') ?: 'All');
        $supplierFilter = $request->input('supplier') ?: ($request->input('supplier_name') ?: 'All');
        $fromDate = $request->input('from_date') ?: ($request->input('date_from') ?: $request->input('start_date'));
        $toDate = $request->input('to_date') ?: ($request->input('date_to') ?: $request->input('end_date'));
        $searchQuery = $request->input('search');

        $query->orderBy($sortColumn, $sortDirection);

        $callback = function () use (
            $query,
            $warehouseFilter,
            $statusFilter,
            $paymentFilter,
            $supplierFilter,
            $fromDate,
            $toDate,
            $searchQuery
        ) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Microsoft Excel on Windows
            fwrite($file, "\xEF\xBB\xBF");

            // CSV injection escape helper
            $sanitize = function ($val) {
                if ($val === null) return '';
                $str = (string)$val;
                if ($str !== '' && in_array($str[0], ['=', '+', '-', '@'], true)) {
                    return "'" . $str;
                }
                return $str;
            };

            // 1. Metadata Block
            fputcsv($file, ['Purchase Returns List Export']);
            fputcsv($file, ['Generated At', date('c')]);
            fputcsv($file, ['Warehouse Filter', $warehouseFilter]);
            fputcsv($file, ['Status Filter', $statusFilter]);
            if (!empty($paymentFilter) && $paymentFilter !== 'All') {
                fputcsv($file, ['Payment Filter', $paymentFilter]);
            }
            if (!empty($supplierFilter) && $supplierFilter !== 'All') {
                fputcsv($file, ['Supplier Filter', $supplierFilter]);
            }
            if (!empty($fromDate) || !empty($toDate)) {
                $fStr = !empty($fromDate) ? explode(' ', $fromDate)[0] : 'Beginning';
                $tStr = !empty($toDate) ? explode(' ', $toDate)[0] : 'Present';
                fputcsv($file, ['Date Range', "$fStr to $tStr"]);
            }
            if (!empty($searchQuery)) {
                fputcsv($file, ['Search Filter', $searchQuery]);
            }
            fwrite($file, "\r\n");

            // 2. Column Headers
            $columns = [
                '#',
                'Return Reference',
                'Purchase Reference',
                'Return Date & Time',
                'Supplier',
                'Warehouse',
                'Status',
                'Items Count',
                'Return Quantity',
                'Subtotal (MMK)',
                'Tax Rate (%)',
                'Tax Amount (MMK)',
                'Discount (MMK)',
                'Shipping (MMK)',
                'Grand Total (MMK)',
                'Paid (MMK)',
                'Due (MMK)',
                'Payment Status',
                'Payment Type',
                'Return Reason',
                'Created By',
                'Notes',
            ];
            fputcsv($file, $columns);

            $sn = 1;
            $runningTotalReturnUnits = 0.0;
            $totalCount = 0;
            $totalGrandTotal = 0.0;
            $totalPaid = 0.0;
            $totalDue = 0.0;
            $totalSubtotal = 0.0;
            $totalTax = 0.0;
            $totalDiscount = 0.0;
            $totalShipping = 0.0;

            $flushCount = 0;
            foreach ($query->toBase()->cursor() as $ret) {
                $totalCount++;
                $items = !empty($ret->items_payload) ? json_decode($ret->items_payload, true) : [];
                $items = is_array($items) ? $items : [];
                $itemLines = count($items);
                $returnQty = 0.0;
                foreach ($items as $item) {
                    $returnQty += (float)($item['quantity'] ?? ($item['return_quantity'] ?? 0));
                }
                $runningTotalReturnUnits += $returnQty;

                $grandTotal = (float)$ret->grand_total;
                $taxAmount = (float)$ret->tax_amount;
                $discount = (float)$ret->discount;
                $shipping = (float)$ret->shipping;
                $subtotal = (float)$ret->subtotal > 0
                    ? (float)$ret->subtotal
                    : ($grandTotal - $taxAmount - $shipping + $discount);

                $totalGrandTotal += $grandTotal;
                $totalPaid += (float)$ret->paid_amount;
                $totalDue += (float)$ret->due_amount;
                $totalSubtotal += $subtotal;
                $totalTax += $taxAmount;
                $totalDiscount += $discount;
                $totalShipping += $shipping;

                $creator = !empty($ret->created_by)
                    ? $ret->created_by
                    : (!empty($ret->user_name) ? "{$ret->user_name} ({$ret->user_role})" : 'Admin');

                $row = [
                    $sn++,
                    $sanitize($ret->reference_no),
                    $sanitize($ret->purchase_reference_no),
                    $ret->date,
                    $sanitize($ret->supplier_name),
                    $sanitize($ret->warehouse_name),
                    $ret->status,
                    (string)$itemLines,
                    fmod($returnQty, 1) == 0 ? (string)(int)$returnQty : number_format($returnQty, 2, '.', ''),
                    number_format($subtotal, 2, '.', ''),
                    number_format((float)$ret->tax_rate, 1, '.', ''),
                    number_format($taxAmount, 2, '.', ''),
                    number_format($discount, 2, '.', ''),
                    number_format($shipping, 2, '.', ''),
                    number_format($grandTotal, 2, '.', ''),
                    number_format((float)$ret->paid_amount, 2, '.', ''),
                    number_format((float)$ret->due_amount, 2, '.', ''),
                    $ret->payment_status ?: 'Unpaid',
                    $ret->payment_type ?: 'Cash',
                    $sanitize($ret->return_reason ?: '—'),
                    $sanitize($creator),
                    $sanitize($ret->notes ?: ''),
                ];
                fputcsv($file, $row);

                if (++$flushCount % 500 === 0) {
                    flush();
                }
            }

            // Summary row
            fputcsv($file, []);
            fputcsv($file, [
                'TOTALS',
                "$totalCount Returns",
                '',
                '',
                '',
                '',
                '',
                '',
                number_format($runningTotalReturnUnits, 2, '.', ''),
                number_format($totalSubtotal, 2, '.', ''),
                '',
                number_format($totalTax, 2, '.', ''),
                number_format($totalDiscount, 2, '.', ''),
                number_format($totalShipping, 2, '.', ''),
                number_format($totalGrandTotal, 2, '.', ''),
                number_format($totalPaid, 2, '.', ''),
                number_format($totalDue, 2, '.', ''),
                '',
                '',
                '',
                '',
                '',
            ]);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
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

    /**
     * Validate that return quantities do not exceed remaining purchased quantities on the original PO.
     */
    private function validateOverReturn(?string $purchaseRef, array $items, ?int $ignoreReturnId = null): ?string
    {
        if (empty($purchaseRef)) {
            return null;
        }

        $clean = trim($purchaseRef);
        $linkedPurchase = Purchase::where('reference_no', $clean)
            ->orWhere('reference_no', str_replace('-', '_', $clean))
            ->orWhere('reference_no', str_replace('_', '-', $clean))
            ->with('items')
            ->first();

        if (!$linkedPurchase || !$linkedPurchase->items || $linkedPurchase->items->isEmpty()) {
            return null;
        }

        // 1. Calculate total purchased base quantities by product + variant
        $purchasedBaseTotals = [];
        $productNames = [];
        foreach ($linkedPurchase->items as $poItem) {
            $pId = (int)$poItem->product_id;
            $vId = $poItem->product_variant_id ? (int)$poItem->product_variant_id : null;
            $key = "{$pId}_" . ($vId ?? '0');
            $purchasedBaseTotals[$key] = ($purchasedBaseTotals[$key] ?? 0.0) + (float)$poItem->base_quantity;
            $productNames[$key] = $poItem->product_name;
        }

        // 2. Calculate already returned base quantities across other completed returns
        $existingReturns = PurchaseReturn::where(function ($q) use ($linkedPurchase, $clean) {
                $q->where('purchase_reference_no', $linkedPurchase->reference_no)
                  ->orWhere('purchase_reference_no', $clean);
            })
            ->when($ignoreReturnId !== null, fn($q) => $q->where('id', '!=', $ignoreReturnId))
            ->where('status', 'Completed')
            ->get();

        $alreadyReturnedBaseTotals = [];
        foreach ($existingReturns as $exReturn) {
            foreach ($exReturn->items as $exIt) {
                $pId = (int)($exIt['product_id'] ?? 0);
                $vId = !empty($exIt['product_variant_id']) ? (int)$exIt['product_variant_id'] : null;
                $key = "{$pId}_" . ($vId ?? '0');
                $conv = (float)($exIt['conversion_rate'] ?? 1.0);
                $qty = (float)($exIt['quantity'] ?? 0.0);
                $bQty = (float)($exIt['base_quantity'] ?? ($qty * $conv));
                $alreadyReturnedBaseTotals[$key] = ($alreadyReturnedBaseTotals[$key] ?? 0.0) + $bQty;
            }
        }

        // 3. Sum requested return base quantities in this request
        $incomingBaseTotals = [];
        foreach ($items as $it) {
            $pId = (int)($it['product_id'] ?? 0);
            $vId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
            $key = "{$pId}_" . ($vId ?? '0');
            $conv = (float)($it['conversion_rate'] ?? 1.0);
            $qty = (float)($it['quantity'] ?? 0.0);
            $bQty = (float)($it['base_quantity'] ?? ($qty * $conv));
            $incomingBaseTotals[$key] = ($incomingBaseTotals[$key] ?? 0.0) + $bQty;
            if (empty($productNames[$key]) && !empty($it['product_name'])) {
                $productNames[$key] = $it['product_name'];
            }
        }

        // 4. Verify no item exceeds available purchased base quantity
        foreach ($incomingBaseTotals as $key => $incomingQty) {
            $purchasedQty = $purchasedBaseTotals[$key] ?? 0.0;
            $alreadyReturned = $alreadyReturnedBaseTotals[$key] ?? 0.0;
            $remaining = max(0.0, $purchasedQty - $alreadyReturned);
            $pName = $productNames[$key] ?? 'Item';

            if ($purchasedQty > 0 && ($incomingQty > $remaining + 0.001)) {
                return "Cannot return {$incomingQty} base units for '{$pName}'. Original purchased: {$purchasedQty}, already returned: {$alreadyReturned}, remaining available: {$remaining}.";
            }
        }

        return null;
    }

    /**
     * Check if warehouse has sufficient stock for all return items before proceeding.
     */
    private function checkWarehouseStock(int $warehouseId, array $items): ?string
    {
        foreach ($items as $it) {
            $productId = (int)($it['product_id'] ?? 0);
            $variantId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
            $conversionRate = (float)($it['conversion_rate'] ?? 1.0);
            $qty = (float)($it['quantity'] ?? 0.0);
            $baseQty = (float)($it['base_quantity'] ?? ($qty * $conversionRate));

            if ($productId <= 0 || $baseQty <= 0.0001) continue;

            $stockQuery = StockBalance::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId);
            if ($variantId !== null && $variantId > 0) {
                $stockQuery->where('product_variant_id', $variantId);
            } else {
                $stockQuery->whereNull('product_variant_id');
            }
            $availableStock = (float)($stockQuery->value('quantity') ?? 0.0);

            if ($availableStock < ($baseQty - 0.0001)) {
                $pName = $it['product_name'] ?? 'Product #' . $productId;
                return "Insufficient stock in warehouse for '{$pName}'. Available: {$availableStock}, requested return: {$baseQty}.";
            }
        }
        return null;
    }

    /**
     * Revert stock and FIFO batches for a purchase return.
     */
    private function revertReturnStockAndBatches(PurchaseReturn $return): void
    {
        $warehouseId = $return->warehouse_id ?? $return->outlet_id;
        if (!$warehouseId && !empty($return->warehouse_name)) {
            $unitService = app(ProductUnitConversionService::class);
            $warehouseId = $unitService->warehouseId(null, $return->warehouse_name);
        }

        $items = $return->items;
        if (empty($items) || !is_array($items) || !$warehouseId) {
            return;
        }

        $unitService = app(ProductUnitConversionService::class);
        $fifoService = app(FifoInventoryService::class);

        foreach ($items as $it) {
            $productId = $it['product_id'] ?? null;
            $variantId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
            $product   = $productId ? Product::find($productId) : null;

            if ($product) {
                $conversionRate = (float)($it['conversion_rate'] ?? 1.0);
                $qty            = (float)($it['quantity'] ?? 0);
                $baseQty        = (float)($it['base_quantity'] ?? ($qty * $conversionRate));
                $unitCost       = (float)($it['unit_cost'] ?? $it['unit_price'] ?? $product->cost_price ?? $product->purchase_price ?? 0);
                $baseUnitCost   = $conversionRate > 0 ? ($unitCost / $conversionRate) : $unitCost;

                if ($baseQty > 0.0001) {
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
                }
            }
        }
    }

    /**
     * Deduct stock and FIFO batches for a purchase return.
     */
    private function deductReturnStockAndBatches(PurchaseReturn $return, array $items, int $warehouseId, ?string $purchaseRef): void
    {
        $unitService = app(ProductUnitConversionService::class);
        $fifoService = app(FifoInventoryService::class);

        foreach ($items as $it) {
            $productId = $it['product_id'] ?? null;
            $variantId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
            $product   = $productId ? Product::find($productId) : Product::where('code', $it['product_code'] ?? '')->first();

            if ($product) {
                $conversionRate = (float)($it['conversion_rate'] ?? 1.0);
                $qty            = (float)($it['quantity'] ?? 0);
                $baseQty        = (float)($it['base_quantity'] ?? ($qty * $conversionRate));
                $unitCost       = (float)($it['unit_cost'] ?? $it['unit_price'] ?? $product->cost_price ?? $product->purchase_price ?? 0);
                $fallbackCost   = $conversionRate > 0 ? ($unitCost / $conversionRate) : $unitCost;

                if ($baseQty > 0.0001) {
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

                    $fifoService->depleteForPurchaseReturn(
                        warehouseId:       (int)$warehouseId,
                        productId:         (int)$product->id,
                        variantId:         $variantId,
                        baseQty:           (float)$baseQty,
                        returnReference:   (string)$return->reference_no,
                        purchaseReference: $purchaseRef,
                        fallbackCost:      (float)$fallbackCost
                    );
                }
            }
        }
    }

    /**
     * Apply linked purchase due amount offset and supplier balance due decrement.
     */
    private function applyLedgerAndPurchaseOffset(PurchaseReturn $return, float $grandTotal, float $dueAmount, ?string $purchaseRef, ?int $supplierId): void
    {
        if (!empty($purchaseRef)) {
            $clean = trim($purchaseRef);
            $linkedPurchase = Purchase::where('reference_no', $clean)
                ->orWhere('reference_no', str_replace('-', '_', $clean))
                ->orWhere('reference_no', str_replace('_', '-', $clean))
                ->first();

            if ($linkedPurchase && (float)$linkedPurchase->due_amount > 0) {
                $offset = min((float)$linkedPurchase->due_amount, $grandTotal);
                if ($offset > 0.001) {
                    $newDue = max(0.0, (float)$linkedPurchase->due_amount - $offset);
                    $newStatus = $newDue <= 0.01 ? 'Paid' : ($newDue < (float)$linkedPurchase->grand_total - 0.01 ? 'Partial' : 'Unpaid');
                    $linkedPurchase->update([
                        'due_amount'     => $newDue,
                        'payment_status' => $newStatus,
                    ]);
                }
            }
        }

        if ($supplierId && $dueAmount > 0.001) {
            Supplier::where('id', $supplierId)->decrement('balance_due', $dueAmount);
        }
    }

    /**
     * Revert linked purchase due amount offset and supplier balance due decrement.
     */
    private function revertLedgerAndPurchaseOffset(PurchaseReturn $return): void
    {
        if (!empty($return->purchase_reference_no)) {
            $clean = trim($return->purchase_reference_no);
            $linkedPurchase = Purchase::where('reference_no', $clean)
                ->orWhere('reference_no', str_replace('-', '_', $clean))
                ->orWhere('reference_no', str_replace('_', '-', $clean))
                ->first();

            if ($linkedPurchase) {
                $originalDuePossible = max(0.0, (float)$linkedPurchase->grand_total - (float)$linkedPurchase->paid_amount);
                $restoredDue = min($originalDuePossible, (float)$linkedPurchase->due_amount + (float)$return->grand_total);
                $newStatus = $restoredDue <= 0.01 ? 'Paid' : ($restoredDue < (float)$linkedPurchase->grand_total - 0.01 ? 'Partial' : 'Unpaid');
                $linkedPurchase->update([
                    'due_amount'     => $restoredDue,
                    'payment_status' => $newStatus,
                ]);
            }
        }

        if ($return->supplier_id && (float)$return->due_amount > 0.001) {
            Supplier::where('id', $return->supplier_id)->increment('balance_due', (float)$return->due_amount);
        }
    }

    public function store(Request $request)
    {
        $user = User::resolveFromRequest($request) ?? $request->user();

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

        $refNo = $this->acquireUniqueReference('purchase_return', $validated['reference_no'] ?? null);

        // Normalize status: Completed and Received both mean goods are returned
        $statusInput = $validated['status'] ?? 'Completed';
        $returnStatus = ($statusInput === 'Received' || $statusInput === 'Completed') ? 'Completed' : 'Pending';

        $items = $validated['items'] ?? [];

        // 1. Check Cumulative Over-Return guard before beginning transaction
        $purchaseRef = $validated['purchase_reference_no'] ?? null;
        if (!empty($items) && is_array($items)) {
            $overReturnError = $this->validateOverReturn($purchaseRef, $items);
            if ($overReturnError) {
                return response()->json(['status' => 'error', 'message' => $overReturnError], 422);
            }
        }

        $warehouseId = $validated['warehouse_id'] ?? $validated['outlet_id'] ?? null;
        $outletId    = $validated['outlet_id'] ?? $validated['warehouse_id'] ?? null;
        if (!$warehouseId && !empty($validated['warehouse_name'])) {
            $unitService = app(ProductUnitConversionService::class);
            $warehouseId = $unitService->warehouseId(null, $validated['warehouse_name']);
            $outletId    = $outletId ?? $warehouseId;
        }

        // 2. Pre-check warehouse stock availability if status is Completed
        if ($returnStatus === 'Completed' && !empty($items) && is_array($items) && $warehouseId) {
            $stockError = $this->checkWarehouseStock((int)$warehouseId, $items);
            if ($stockError) {
                return response()->json(['status' => 'error', 'message' => $stockError], 422);
            }
        }

        // 3. Compute accurate financial totals
        $itemsSubtotal = 0.0;
        foreach ($items as $it) {
            $itemsSubtotal += (float)($it['subtotal'] ?? ((float)($it['quantity'] ?? 0) * (float)($it['unit_cost'] ?? 0)));
        }
        if ($itemsSubtotal <= 0.001 && isset($validated['subtotal'])) {
            $itemsSubtotal = (float)$validated['subtotal'];
        }
        $discountAmount = (float)($validated['discount'] ?? 0.0);
        $taxRate = (float)($validated['tax_rate'] ?? 0.0);
        $netSubtotal = max(0.0, $itemsSubtotal - $discountAmount);
        $taxAmount = round($netSubtotal * ($taxRate / 100), 2);
        $shipping = (float)($validated['shipping'] ?? 0.0);
        $grandTotal = round($netSubtotal + $taxAmount + $shipping, 2);
        if ($grandTotal <= 0.001 && (float)$validated['grand_total'] > 0) {
            $grandTotal = (float)$validated['grand_total'];
        }

        $paidAmount    = (float)($validated['paid_amount'] ?? $grandTotal);
        $dueAmount     = max(0.0, round($grandTotal - $paidAmount, 2));
        $paymentStatus = $validated['payment_status'] ?? ($dueAmount <= 0.001 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid'));

        $userName  = !empty($validated['user_name'])
            ? $validated['user_name']
            : ($user?->name ?? 'Admin User');
        $userRole  = !empty($validated['user_role'])
            ? $validated['user_role']
            : ($user?->role ?? 'Super Admin');
        $createdBy = !empty($validated['created_by'])
            ? $validated['created_by']
            : (!empty($userName) ? "{$userName} ({$userRole})" : 'Admin User');
        $userId       = $validated['user_id'] ?? $user?->id;

        $txDate = trim($validated['date']);
        if (!str_contains($txDate, ':')) {
            $txDate .= ' ' . now()->format('H:i:s');
        }

        // Notes: Only append Credit Note if payment_type is Credit Note or due_amount > 0
        $combinedNotes = trim($validated['notes'] ?? '');
        if (($validated['payment_type'] ?? '') === 'Credit Note' || $dueAmount > 0.01) {
            $creditNoteNote = "Credit Note: Returned to {$validated['supplier_name']} on " . now()->toDateString();
            $combinedNotes  = trim($combinedNotes . "\n" . $creditNoteNote);
        }

        $itemsPayload = !empty($items) ? json_encode($items) : null;

        DB::beginTransaction();
        try {
            $return = PurchaseReturn::create([
                'reference_no'          => $refNo,
                'purchase_reference_no' => $purchaseRef,
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
                'subtotal'              => $itemsSubtotal,
                'tax_rate'              => $taxRate,
                'tax_amount'            => $taxAmount,
                'discount'              => $discountAmount,
                'shipping'              => $shipping,
                'notes'                 => $combinedNotes,
                'return_reason'         => $validated['return_reason'] ?? null,
                'items_payload'         => $itemsPayload,
                'created_by'            => $createdBy,
                'user_id'               => $userId,
                'user_name'             => $userName,
                'user_role'             => $userRole,
            ]);

            // Deduct stock and FIFO batches if Completed
            if ($returnStatus === 'Completed' && !empty($items) && $warehouseId) {
                $this->deductReturnStockAndBatches($return, $items, (int)$warehouseId, $purchaseRef);
                $this->applyLedgerAndPurchaseOffset($return, $grandTotal, $dueAmount, $purchaseRef, $validated['supplier_id'] ?? null);
            }

            // Record initial refund payment transaction(s)
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
            ReportController::invalidateDashboardCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'Purchase return recorded successfully',
                'data'    => $return->fresh(),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('PurchaseReturn store error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $return = PurchaseReturn::find($id);
        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }

        $user = User::resolveFromRequest($request) ?? $request->user();

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

        $newPurchaseRef = $validated['purchase_reference_no'] ?? $return->purchase_reference_no;
        $newWarehouseId = $validated['warehouse_id'] ?? $validated['outlet_id'] ?? $return->warehouse_id;
        $newSupplierId  = $validated['supplier_id'] ?? $return->supplier_id;

        $statusInput = $validated['status'] ?? $return->status;
        $newStatus = ($statusInput === 'Received' || $statusInput === 'Completed') ? 'Completed' : 'Pending';
        $oldStatus = $return->status;

        $newItems = isset($validated['items']) ? $validated['items'] : $return->items;

        // Check cumulative over-return (ignoring this return's own prior items)
        if ($newStatus === 'Completed' && !empty($newItems) && is_array($newItems)) {
            $overReturnError = $this->validateOverReturn($newPurchaseRef, $newItems, (int)$return->id);
            if ($overReturnError) {
                return response()->json(['status' => 'error', 'message' => $overReturnError], 422);
            }
        }

        // Calculate updated financial totals
        $itemsSubtotal = 0.0;
        foreach ($newItems as $it) {
            $itemsSubtotal += (float)($it['subtotal'] ?? ((float)($it['quantity'] ?? 0) * (float)($it['unit_cost'] ?? 0)));
        }
        if ($itemsSubtotal <= 0.001 && isset($validated['subtotal'])) {
            $itemsSubtotal = (float)$validated['subtotal'];
        }
        $discountAmount = isset($validated['discount']) ? (float)$validated['discount'] : (float)$return->discount;
        $taxRate = isset($validated['tax_rate']) ? (float)$validated['tax_rate'] : (float)$return->tax_rate;
        $netSubtotal = max(0.0, $itemsSubtotal - $discountAmount);
        $taxAmount = round($netSubtotal * ($taxRate / 100), 2);
        $shipping = isset($validated['shipping']) ? (float)$validated['shipping'] : (float)$return->shipping;
        $grandTotal = round($netSubtotal + $taxAmount + $shipping, 2);
        if ($grandTotal <= 0.001 && isset($validated['grand_total'])) {
            $grandTotal = (float)$validated['grand_total'];
        }

        $paidAmount    = isset($validated['paid_amount']) ? (float)$validated['paid_amount'] : (float)$return->paid_amount;
        $dueAmount     = max(0.0, round($grandTotal - $paidAmount, 2));
        $paymentStatus = $validated['payment_status'] ?? ($dueAmount <= 0.001 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid'));

        $userName  = !empty($validated['user_name'])
            ? $validated['user_name']
            : ($return->user_name ?? $user?->name ?? 'Admin User');
        $userRole  = !empty($validated['user_role'])
            ? $validated['user_role']
            : ($return->user_role ?? $user?->role ?? 'Super Admin');
        $createdBy = !empty($validated['created_by'])
            ? $validated['created_by']
            : ($return->created_by ?? (!empty($userName) ? "{$userName} ({$userRole})" : 'Admin User'));

        DB::beginTransaction();
        try {
            // Revert previous stock & ledger if was Completed
            if ($oldStatus === 'Completed') {
                $this->revertReturnStockAndBatches($return);
                $this->revertLedgerAndPurchaseOffset($return);
            }

            // Apply new stock & ledger if new status is Completed
            if ($newStatus === 'Completed' && !empty($newItems) && $newWarehouseId) {
                $stockError = $this->checkWarehouseStock((int)$newWarehouseId, $newItems);
                if ($stockError) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $stockError], 422);
                }
                $this->deductReturnStockAndBatches($return, $newItems, (int)$newWarehouseId, $newPurchaseRef);
                $this->applyLedgerAndPurchaseOffset($return, $grandTotal, $dueAmount, $newPurchaseRef, $newSupplierId);
            }

            $updateData = [
                'reference_no'          => $validated['reference_no'] ?? $return->reference_no,
                'purchase_reference_no' => $newPurchaseRef,
                'supplier_id'           => $newSupplierId,
                'supplier_name'         => $validated['supplier_name'] ?? $return->supplier_name,
                'warehouse_id'          => $newWarehouseId,
                'warehouse_name'        => $validated['warehouse_name'] ?? $return->warehouse_name,
                'outlet_id'             => $validated['outlet_id'] ?? $return->outlet_id,
                'date'                  => $validated['date'] ?? $return->date,
                'status'                => $newStatus,
                'grand_total'           => $grandTotal,
                'paid_amount'           => $paidAmount,
                'due_amount'            => $dueAmount,
                'payment_status'        => $paymentStatus,
                'payment_type'          => $validated['payment_type'] ?? $return->payment_type,
                'subtotal'              => $itemsSubtotal,
                'tax_rate'              => $taxRate,
                'tax_amount'            => $taxAmount,
                'discount'              => $discountAmount,
                'shipping'              => $shipping,
                'notes'                 => $validated['notes'] ?? $return->notes,
                'return_reason'         => $validated['return_reason'] ?? $return->return_reason,
                'items_payload'         => json_encode($newItems),
                'created_by'            => $createdBy,
                'user_id'               => $validated['user_id'] ?? $return->user_id ?? $user?->id,
                'user_name'             => $userName,
                'user_role'             => $userRole,
            ];

            $return->update($updateData);

            DB::commit();
            ReportController::invalidateDashboardCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'Purchase return updated successfully',
                'data'    => $return->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('PurchaseReturn update error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $return = PurchaseReturn::find($id);
        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }

        DB::beginTransaction();
        try {
            // Reverse Stock & Batches and Ledger on Delete
            if ($return->status === 'Completed') {
                $this->revertReturnStockAndBatches($return);
                $this->revertLedgerAndPurchaseOffset($return);
            }

            $return->delete();

            DB::commit();
            ReportController::invalidateDashboardCache();
            return response()->json(['status' => 'success', 'message' => 'Purchase return deleted']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('PurchaseReturn destroy error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
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

        $priorDue = (float)$return->due_amount;

        $existingPayments = $return->payments;
        $updatedPayments = array_values($existingPayments);
        $authUser = User::resolveFromRequest($request) ?? $request->user();
        $globalUserName = $validated['user_name'] ?? ($authUser?->name ?? ($return->user_name ?: 'Admin User'));
        $globalUserRole = $validated['user_role'] ?? ($authUser?->role ?? ($return->user_role ?: 'Super Admin'));

        $totalIncomingPayment = 0.0;
        if (!empty($validated['payments']) && is_array($validated['payments'])) {
            foreach ($validated['payments'] as $tender) {
                $pAmt = (float)$tender['amount'];
                $totalIncomingPayment += $pAmt;
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
            $totalIncomingPayment += $payingAmount;
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

        // Prevent overpayment
        if ($totalIncomingPayment > $priorDue + 0.01) {
            return response()->json([
                'status' => 'error',
                'message' => "Payment amount ({$totalIncomingPayment}) cannot exceed due balance ({$priorDue}).",
            ], 422);
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

            // When a credit-note return receives a cash refund payment, Accounts Payable restores by the payment amount
            if (!empty($return->supplier_id) && $totalIncomingPayment > 0) {
                Supplier::where('id', $return->supplier_id)->increment('balance_due', min($priorDue, $totalIncomingPayment));
            }

            DB::commit();
            ReportController::invalidateDashboardCache();

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
        $return = PurchaseReturn::find($returnId) ?? PurchaseReturn::where('reference_no', $returnId)->first();
        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Purchase return not found'], 404);
        }

        $existingPayments = $return->payments;
        if (empty($existingPayments)) {
            return response()->json(['status' => 'error', 'message' => 'No payment transactions exist for this return'], 404);
        }

        // Target payment identifier (must match exact id or exact reference_no)
        $targetIdentifier = trim((string)($paymentId ?? $request->route('paymentId') ?? ''));
        if (empty($targetIdentifier)) {
            return response()->json(['status' => 'error', 'message' => 'Payment identifier is required'], 422);
        }

        $remainingPayments = [];
        $deletedFound = false;
        $deletedAmount = 0.0;

        foreach ($existingPayments as $p) {
            $pId  = isset($p['id']) ? trim((string)$p['id']) : '';
            $pRef = isset($p['reference_no']) ? trim((string)$p['reference_no']) : '';

            $matchById = ($pId !== '' && $pId === $targetIdentifier);
            $matchByRef = ($pRef !== '' && $pRef === $targetIdentifier);

            if (!$deletedFound && ($matchById || $matchByRef)) {
                $deletedFound = true;
                $deletedAmount = (float)($p['amount'] ?? 0.0);
                continue;
            }
            $remainingPayments[] = $p;
        }

        if (!$deletedFound) {
            return response()->json(['status' => 'error', 'message' => 'Payment transaction not found'], 404);
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

            // When refund payment is deleted, due_amount goes back up, so Supplier balance_due decreases by deleted amount
            if (!empty($return->supplier_id) && $deletedAmount > 0) {
                Supplier::where('id', $return->supplier_id)->decrement('balance_due', $deletedAmount);
            }

            DB::commit();
            ReportController::invalidateDashboardCache();

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

