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
    use \App\Traits\GeneratesSequentialCode;
    /**
     * Generate next sequential purchase code and formatted reference number.
     */
    public function generateCode()
    {
        return response()->json($this->peekNextSequentialCode('purchase'));
    }

    /**
     * Build filtered purchase query based on request parameters
     */
    protected function buildFilteredQuery(Request $request, bool $includeItems = false)
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
            ]);

        $relations = [
            'supplier:id,name',
        ];

        if ($includeItems) {
            $relations[] = 'items:id,purchase_id,product_id,product_variant_id,variant_name,product_code,product_name,unit_id,unit_name,conversion_rate,unit_cost,quantity,base_quantity,base_unit_cost,tax,discount,subtotal';
        }

        $query->with($relations);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        if ($request->filled('supplier') && $request->input('supplier') !== 'All') {
            $supplier = $request->input('supplier');
            $query->where(function ($q) use ($supplier) {
                $q->where('supplier_name', $supplier);
                if (is_numeric($supplier)) {
                    $q->orWhere('supplier_id', (int)$supplier);
                }
            });
        }

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

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('payment_status') && $request->input('payment_status') !== 'All') {
            $query->where('payment_status', $request->input('payment_status'));
        }

        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate = $request->input('end_date') ?? $request->input('to_date');
        if (!empty($startDate) && !empty($endDate)) {
            $s = trim($startDate);
            $e = trim($endDate);
            $startVal = str_contains($s, ':') ? $s : $s . ' 00:00:00';
            $endVal = str_contains($e, ':') ? $e : $e . ' 23:59:59';
            $query->whereBetween('date', [$startVal, $endVal]);
        } elseif (!empty($startDate)) {
            $s = trim($startDate);
            $query->where('date', '>=', str_contains($s, ':') ? $s : $s . ' 00:00:00');
        } elseif (!empty($endDate)) {
            $e = trim($endDate);
            $query->where('date', '<=', str_contains($e, ':') ? $e : $e . ' 23:59:59');
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $digits = preg_replace('/[^0-9]/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('supplier_name', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
                if (!empty($digits)) {
                    $q->orWhere('reference_no', 'like', "%{$digits}%");
                }
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        $includeItems = !$request->boolean('no_items', false);
        $query = $this->buildFilteredQuery($request, $includeItems);

        // Sorting
        $allowedSortCols = [
            'id' => 'id',
            'reference' => 'reference_no',
            'reference_no' => 'reference_no',
            'supplier' => 'supplier_name',
            'supplier_name' => 'supplier_name',
            'warehouse' => 'warehouse_name',
            'warehouse_name' => 'warehouse_name',
            'date' => 'date',
            'status' => 'status',
            'payment_status' => 'payment_status',
            'grand_total' => 'grand_total',
            'total' => 'grand_total',
            'paid_amount' => 'paid_amount',
            'due_amount' => 'due_amount',
            'created_by' => 'created_by',
            'user_name' => 'user_name',
        ];

        $sortByInput = strtolower(trim((string)$request->input('sort_by', 'id')));
        $sortBy = $allowedSortCols[$sortByInput] ?? 'id';
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $totalCount = (clone $query)->count();
        $summaryGrandTotal = (float)(clone $query)->sum('grand_total');
        $summaryPaid = (float)(clone $query)->sum('paid_amount');
        $summaryDue = (float)(clone $query)->sum('due_amount');

        $summary = [
            'total_records' => $totalCount,
            'total_grand_total' => $summaryGrandTotal,
            'total_paid' => $summaryPaid,
            'total_due' => $summaryDue,
        ];

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        if ($isAll) {
            $limit = min(1000, max(1, (int)$request->input('limit', 500)));
            $purchases = $query->orderBy($sortBy, $sortOrder)->take($limit)->get();
            return response()->json([
                'status' => 'success',
                'data' => $purchases,
                'total' => $totalCount,
                'summary' => $summary,
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $purchases->count(),
                    'total' => $totalCount,
                    'last_page' => (int)ceil($totalCount / max(1, $limit)),
                ],
            ]);
        }

        $perPage = min(1000, max(1, (int)$request->input('per_page', 50)));
        $purchases = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $purchases->items(),
            'total' => $totalCount,
            'summary' => $summary,
            'pagination' => [
                'current_page' => $purchases->currentPage(),
                'per_page' => $purchases->perPage(),
                'total' => $totalCount,
                'last_page' => $purchases->lastPage(),
            ],
        ]);
    }

    /**
     * Streamed UTF-8 BOM CSV Export for Purchases matching active filters
     */
    public function export(Request $request)
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $query = $this->buildFilteredQuery($request, false);

        // Sorting
        $allowedSortCols = [
            'id' => 'purchases.id',
            'reference' => 'purchases.reference_no',
            'reference_no' => 'purchases.reference_no',
            'supplier' => 'purchases.supplier_name',
            'supplier_name' => 'purchases.supplier_name',
            'warehouse' => 'purchases.warehouse_name',
            'warehouse_name' => 'purchases.warehouse_name',
            'date' => 'purchases.date',
            'status' => 'purchases.status',
            'grand_total' => 'purchases.grand_total',
            'total' => 'purchases.grand_total',
            'paid' => 'purchases.paid_amount',
            'paid_amount' => 'purchases.paid_amount',
            'due' => 'purchases.due_amount',
            'due_amount' => 'purchases.due_amount',
            'payment_status' => 'purchases.payment_status',
            'user_name' => 'purchases.user_name',
            'created_by' => 'purchases.created_by',
        ];

        $sortByInput = strtolower(trim((string)$request->input('sort_by', 'id')));
        $sortBy = $allowedSortCols[$sortByInput] ?? 'purchases.id';
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="purchases_export_' . date('Ymd_His') . '.csv"',
            'X-Accel-Buffering' => 'no',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Expires' => '0',
        ];

        $warehouseFilter = $request->input('warehouse', 'All');
        $statusFilter = $request->input('status', 'All');
        $paymentFilter = $request->input('payment_status') ?: $request->input('payment');
        $supplierFilter = $request->input('supplier');
        $fromDate = $request->input('from_date') ?: $request->input('date_from');
        $toDate = $request->input('to_date') ?: $request->input('date_to');
        $searchQuery = $request->input('search');

        // Pre-aggregate purchase items via high-speed group-by join to eliminate 10,000 N+1 queries
        $query->leftJoin(
            \DB::raw('(SELECT purchase_id, COUNT(*) as item_lines, SUM(quantity) as total_qty FROM purchase_items GROUP BY purchase_id) pi'),
            'purchases.id',
            '=',
            'pi.purchase_id'
        )->addSelect([
            \DB::raw('COALESCE(pi.item_lines, 0) as item_lines'),
            \DB::raw('COALESCE(pi.total_qty, 0) as total_qty')
        ]);

        $callback = function () use (
            $query,
            $sortBy,
            $sortOrder,
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

            // 1. Metadata Header
            fputcsv($file, ['Purchases List Export']);
            fputcsv($file, ['Generated At', date('c')]);
            fputcsv($file, ['Warehouse Filter', $warehouseFilter ?: 'All']);
            fputcsv($file, ['Status Filter', $statusFilter ?: 'All']);
            if (!empty($paymentFilter) && $paymentFilter !== 'All') {
                fputcsv($file, ['Payment Status Filter', $paymentFilter]);
            }
            if (!empty($supplierFilter) && $supplierFilter !== 'All') {
                fputcsv($file, ['Supplier Filter', $supplierFilter]);
            }
            if (!empty($fromDate) || !empty($toDate)) {
                $fromStr = !empty($fromDate) ? explode(' ', $fromDate)[0] : 'Beginning';
                $toStr = !empty($toDate) ? explode(' ', $toDate)[0] : 'Present';
                fputcsv($file, ['Date Range', "$fromStr to $toStr"]);
            }
            if (!empty($searchQuery)) {
                fputcsv($file, ['Search Filter', $searchQuery]);
            }
            fwrite($file, "\r\n");

            // 2. Column Headers
            $columns = [
                'SN',
                'Purchase Reference',
                'Purchase Date',
                'Supplier Name',
                'Warehouse / Outlet',
                'Status',
                'Items Count',
                'Total Units',
                'Tax Amount (MMK)',
                'Discount (MMK)',
                'Shipping (MMK)',
                'Subtotal (MMK)',
                'Grand Total (MMK)',
                'Paid Amount (MMK)',
                'Due Amount (MMK)',
                'Payment Status',
                'Created By',
                'User Role',
                'Notes',
            ];
            fputcsv($file, $columns);

            $sn = 1;
            $formatNum = function ($num) {
                $floatVal = (float)$num;
                return (fmod($floatVal, 1.0) == 0.0) ? (string)((int)$floatVal) : (string)number_format($floatVal, 2, '.', '');
            };

            $totalCount = 0;
            $totalTax = 0.0;
            $totalDiscount = 0.0;
            $totalShipping = 0.0;
            $totalGrandTotal = 0.0;
            $totalPaid = 0.0;
            $totalDue = 0.0;
            $totalUnitsCount = 0.0;
            $subtotalSum = 0.0;

            $flushCount = 0;
            foreach ($query->orderBy($sortBy, $sortOrder)->toBase()->cursor() as $p) {
                $totalCount++;
                $totalTax += (float)$p->tax_amount;
                $totalDiscount += (float)$p->discount;
                $totalShipping += (float)$p->shipping;
                $totalGrandTotal += (float)$p->grand_total;
                $totalPaid += (float)$p->paid_amount;
                $totalDue += (float)$p->due_amount;

                $itemLines = (int)$p->item_lines;
                $totalQty = (float)$p->total_qty;
                $subtotal = (float)$p->grand_total - (float)$p->tax_amount - (float)$p->shipping + (float)$p->discount;

                $totalUnitsCount += $totalQty;
                $subtotalSum += $subtotal;

                $createdBy = trim((string)($p->created_by ?: ($p->user_name ?: 'Admin')));
                if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $createdBy, $m)) {
                    $createdBy = trim($m[1]);
                }

                $row = [
                    $sn++,
                    $p->reference_no,
                    $p->date,
                    $p->supplier_name,
                    $p->warehouse_name,
                    $p->status,
                    $itemLines,
                    $formatNum($totalQty),
                    $formatNum($p->tax_amount),
                    $formatNum($p->discount),
                    $formatNum($p->shipping),
                    $formatNum($subtotal),
                    $formatNum($p->grand_total),
                    $formatNum($p->paid_amount),
                    $formatNum($p->due_amount),
                    $p->payment_status ?: 'Unpaid',
                    $createdBy,
                    $p->user_role ?: 'Admin',
                    $p->notes ?? '',
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
                "$totalCount Purchases",
                '',
                '',
                '',
                '',
                '',
                $formatNum($totalUnitsCount),
                $formatNum($totalTax),
                $formatNum($totalDiscount),
                $formatNum($totalShipping),
                $formatNum($subtotalSum),
                $formatNum($totalGrandTotal),
                $formatNum($totalPaid),
                $formatNum($totalDue),
                '',
                '',
                '',
                '',
            ]);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
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
            'grand_total'               => 'nullable|numeric|min:0',
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
            'items.*.variant_name'      => 'nullable|string',
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
            'payments'                  => 'nullable|array',
            'payments.*.amount'         => 'nullable|numeric|min:0.01',
            'payments.*.payment_type'   => 'nullable|string',
            'payments.*.received_amount'=> 'nullable|numeric|min:0',
            'payments.*.change_amount'  => 'nullable|numeric|min:0',
            'payments.*.payment_date'   => 'nullable|string',
            'payments.*.notes'          => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $refNo = $this->acquireUniqueReference('purchase', $validated['reference_no'] ?? null);
            // Calculate financial totals on server side
            $itemsSubtotal = 0.0;
            foreach ($validated['items'] as $item) {
                $itemsSubtotal += (float)$item['subtotal'];
            }
            $discount = (float)($validated['discount'] ?? 0);
            $shipping = (float)($validated['shipping'] ?? 0);
            $taxRate  = (float)($validated['tax_rate'] ?? 0);
            $netSubtotal = max(0.0, $itemsSubtotal - $discount);
            $calculatedTax = round(($netSubtotal * $taxRate) / 100.0, 2);
            $taxAmount = isset($validated['tax_amount']) && (float)$validated['tax_amount'] > 0
                ? (float)$validated['tax_amount']
                : $calculatedTax;
            $grandTotal = round(max(0.0, $itemsSubtotal - $discount + $taxAmount + $shipping), 2);
            $paidAmount = max(0.0, (float)($validated['paid_amount'] ?? 0));
            $dueAmount  = max(0.0, $grandTotal - $paidAmount);
            $paymentStatus = $dueAmount <= 0.001 ? 'Paid' : ($paidAmount > 0.001 ? 'Partial' : 'Unpaid');

            $authUser = \App\Models\User::resolveFromRequest($request) ?? $request->user();
            if (!$authUser && !empty($validated['user_id'])) {
                $authUser = \App\Models\User::find($validated['user_id']);
            }

            $userName = !empty($validated['user_name'])
                ? $validated['user_name']
                : ($authUser?->name ?? 'Super Admin');
            $userRole = !empty($validated['user_role'])
                ? $validated['user_role']
                : ($authUser?->role ?? 'Super Admin');

            if (empty($userName) && !empty($validated['created_by'])) {
                if (preg_match('/^(.*?)\s*\((.*?)\)$/', $validated['created_by'], $m)) {
                    $userName = trim($m[1]);
                    $userRole = $userRole ?: trim($m[2]);
                } else {
                    $userName = trim($validated['created_by']);
                }
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
                'tax_rate'       => $taxRate,
                'tax_amount'     => $taxAmount,
                'discount'       => $discount,
                'shipping'       => $shipping,
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
            $fifoService = app(\App\Services\FifoInventoryService::class);
            $warehouseId = $unitService->warehouseId($validated['warehouse_id'] ?? null, $purchase->warehouse_name);

            // Pre-load all products in bulk to eliminate N+1 queries
            $itemProductIds = collect($validated['items'])->pluck('product_id')->filter()->unique()->values()->all();
            $itemProductCodes = collect($validated['items'])->pluck('product_code')->filter()->unique()->values()->all();
            $productsMap = Product::with(['variants', 'unitLinks'])
                ->where(function ($q) use ($itemProductIds, $itemProductCodes) {
                    if (!empty($itemProductIds)) {
                        $q->whereIn('id', $itemProductIds);
                    }
                    if (!empty($itemProductCodes)) {
                        $q->orWhereIn('code', $itemProductCodes);
                    }
                })
                ->lockForUpdate()
                ->get();
            $productsById = $productsMap->keyBy('id');
            $productsByCode = $productsMap->keyBy('code');

            $netAdjustment = $shipping + $taxAmount - $discount;

            foreach ($validated['items'] as $item) {
                $product = (!empty($item['product_id']) && isset($productsById[$item['product_id']]))
                    ? $productsById[$item['product_id']]
                    : ($productsByCode[$item['product_code']] ?? null);
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
                    'variant_name' => $item['variant_name'] ?? null,
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
                    $itemSubtotal = (float)$item['subtotal'];
                    $ratio = $itemsSubtotal > 0 ? ($itemSubtotal / $itemsSubtotal) : (1.0 / count($validated['items']));
                    $allocatedExtra = $netAdjustment * $ratio;
                    $landedTotal = max(0.0, $itemSubtotal + $allocatedExtra);
                    $landedBaseUnitCost = $baseQuantity > 0 ? round($landedTotal / $baseQuantity, 4) : $baseUnitCost;

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
                        // Guard against zero-cost overwrites (e.g. FOC items)
                        if ($baseUnitCost > 0.0001) {
                            $product->update([
                                'purchase_price' => (float)$baseUnitCost,
                                'cost_price'     => (float)$baseUnitCost,
                            ]);
                            if ($variantId) {
                                \App\Models\ProductVariant::where('id', $variantId)->update([
                                    'cost_price' => (float)$baseUnitCost,
                                ]);
                            }
                        }

                        // Register into FIFO Inventory Batches with Landed Cost
                        $fifoService->recordBatch(
                            warehouseId: (int)$warehouseId,
                            productId: (int)$product->id,
                            variantId: $variantId,
                            baseQuantity: (float)$baseQuantity,
                            baseUnitCost: (float)$landedBaseUnitCost,
                            sourceType: 'purchase',
                            sourceReference: (string)$purchase->reference_no,
                            receivedDate: (string)$purchase->date,
                            purchaseItemId: (int)$purchaseItem->id,
                            notes: "Purchase #{$purchase->reference_no} received"
                        );
                    }
                }
            }

            // Update Supplier balance_due (Accounts Payable tracking)
            if (!empty($purchase->supplier_id) && $dueAmount > 0) {
                \App\Models\Supplier::where('id', $purchase->supplier_id)->increment('balance_due', $dueAmount);
            }

            // Initialize payment transaction record(s) in payments_payload - supporting Split Payments & multi-tenders
            if (!empty($validated['payments']) && is_array($validated['payments'])) {
                $savedPayments = [];
                $tenderIdx = 1;
                foreach ($validated['payments'] as $p) {
                    $pAmount = (float)($p['amount'] ?? 0);
                    if ($pAmount <= 0) continue;
                    $savedPayments[] = [
                        'id'              => $tenderIdx,
                        'reference_no'    => 'PAY-' . ($purchase->reference_no ?: $purchase->id) . '-' . str_pad($tenderIdx, 2, '0', STR_PAD_LEFT),
                        'payment_date'    => !empty($p['payment_date']) ? $p['payment_date'] : (!empty($validated['date']) ? $validated['date'] : now()->toDateTimeString()),
                        'payment_type'    => $p['payment_type'] ?? ($purchase->payment_type ?: 'Cash'),
                        'amount'          => $pAmount,
                        'received_amount' => (float)($p['received_amount'] ?? $pAmount),
                        'change_amount'   => (float)($p['change_amount'] ?? 0),
                        'user_name'       => $validated['user_name'] ?? ($purchase->user_name ?: $userName),
                        'user_role'       => $validated['user_role'] ?? ($purchase->user_role ?: $userRole),
                        'notes'           => $p['notes'] ?? ($purchase->payment_type === 'Split Payment' ? 'Split payment line' : ($validated['notes'] ?? '')),
                    ];
                    $tenderIdx++;
                }
                if (!empty($savedPayments)) {
                    $purchase->update(['payments_payload' => json_encode($savedPayments)]);
                }
            } elseif (!empty($validated['paid_amount']) && (float)$validated['paid_amount'] > 0) {
                $initialPayment = [
                    'id'              => 1,
                    'reference_no'    => 'PAY-' . ($purchase->reference_no ?: $purchase->id) . '-01',
                    'payment_date'    => !empty($validated['date']) ? $validated['date'] : now()->toDateTimeString(),
                    'payment_type'    => $validated['payment_type'] ?? 'Cash',
                    'amount'          => (float)$validated['paid_amount'],
                    'received_amount' => (float)$validated['paid_amount'],
                    'change_amount'   => 0.0,
                    'user_name'       => $validated['user_name'] ?? ($validated['created_by'] ?? 'Super Admin'),
                    'user_role'       => $validated['user_role'] ?? 'Super Admin',
                    'notes'           => $validated['notes'] ?? '',
                ];
                $purchase->update(['payments_payload' => json_encode([$initialPayment])]);
            }

            DB::commit();
            $this->clearPurchaseRelatedCaches();

            return response()->json([
                'status' => 'success',
                'message' => 'Purchase order created successfully',
                'data' => $purchase->load(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'supplier']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $purchase = Purchase::with(['items', 'supplier'])->where('reference_no', (string)$id)->first() ?? Purchase::with(['items', 'supplier'])->find($id);
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }

        $validated = $request->validate([
            'supplier_id'               => 'nullable|integer',
            'supplier_name'             => 'nullable|string|max:255',
            'warehouse_id'              => 'nullable|integer',
            'warehouse_name'            => 'nullable|string|max:255',
            'date'                      => 'nullable|string',
            'status'                    => 'nullable|string|in:Received,Pending,Ordered',
            'tax_rate'                  => 'nullable|numeric|min:0',
            'tax_amount'                => 'nullable|numeric|min:0',
            'discount'                  => 'nullable|numeric|min:0',
            'shipping'                  => 'nullable|numeric|min:0',
            'subtotal'                  => 'nullable|numeric|min:0',
            'grand_total'               => 'nullable|numeric|min:0',
            'paid_amount'               => 'nullable|numeric|min:0',
            'payment_status'            => 'nullable|string',
            'payment_type'              => 'nullable|string',
            'notes'                     => 'nullable|string',
            'created_by'                => 'nullable|string|max:255',
            'user_id'                   => 'nullable|integer',
            'user_name'                 => 'nullable|string|max:255',
            'user_role'                 => 'nullable|string|max:255',
            'items'                     => 'nullable|array|min:1',
            'items.*.product_id'        => 'nullable|integer',
            'items.*.product_variant_id'=> 'nullable|integer',
            'items.*.variant_name'      => 'nullable|string',
            'items.*.unit_id'           => 'nullable|integer',
            'items.*.unit_name'         => 'nullable|string|max:100',
            'items.*.conversion_rate'   => 'nullable|numeric|gt:0',
            'items.*.base_quantity'     => 'nullable|numeric|min:0',
            'items.*.base_unit_cost'    => 'nullable|numeric|min:0',
            'items.*.product_code'      => 'required_with:items|string',
            'items.*.product_name'      => 'required_with:items|string',
            'items.*.unit_cost'         => 'required_with:items|numeric|min:0',
            'items.*.quantity'          => 'required_with:items|numeric|min:0.01',
            'items.*.tax'               => 'nullable|numeric|min:0',
            'items.*.discount'          => 'nullable|numeric|min:0',
            'items.*.subtotal'          => 'required_with:items|numeric|min:0',
        ]);

        $oldStatus = $purchase->status;
        $newStatus = $validated['status'] ?? $oldStatus;
        $oldDue = (float)$purchase->due_amount;
        $oldSupplierId = $purchase->supplier_id;
        $newSupplierId = array_key_exists('supplier_id', $validated) ? $validated['supplier_id'] : $oldSupplierId;

        $unitService = app(ProductUnitConversionService::class);
        $fifoService = app(\App\Services\FifoInventoryService::class);
        $warehouseId = $unitService->warehouseId($validated['warehouse_id'] ?? $purchase->warehouse_id, $validated['warehouse_name'] ?? $purchase->warehouse_name);

        // Guard 1: If old status was Received, and new status is Pending or Ordered:
        // Must verify that no batches have been depleted in sales!
        if ($oldStatus === 'Received' && in_array($newStatus, ['Pending', 'Ordered'])) {
            $batchQuery = \App\Models\InventoryBatch::whereIn('purchase_item_id', $purchase->items->pluck('id'))->get();
            if ($batchQuery->some(fn($b) => (float)$b->remaining_quantity < (float)$b->initial_quantity)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot change status from Received to ' . $newStatus . ' because some items from this purchase have already been sold or depleted from stock in sales.',
                ], 422);
            }
        }

        // Guard 2: If old status was Received and items are being replaced:
        if (!empty($validated['items']) && is_array($validated['items']) && $oldStatus === 'Received') {
            $batchQuery = \App\Models\InventoryBatch::whereIn('purchase_item_id', $purchase->items->pluck('id'))->get();
            if ($batchQuery->some(fn($b) => (float)$b->remaining_quantity < (float)$b->initial_quantity)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot modify items of this purchase because items have already been sold or depleted from stock in sales.',
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            // If old status was Received and new status is Pending or Ordered, revert stock out & clean batches
            if ($oldStatus === 'Received' && in_array($newStatus, ['Pending', 'Ordered'])) {
                foreach ($purchase->items as $item) {
                    $prod = Product::find($item->product_id) ?? Product::where('code', $item->product_code)->first();
                    if ($prod) {
                        $baseQty = (float)($item->base_quantity ?: $item->quantity);
                        $unitService->applyStock($prod, $warehouseId, $item->product_variant_id, $baseQty, 'out', 'purchase_reversion', 'purchase', (string)$purchase->id, 'Revert stock: status changed to ' . $newStatus);
                    }
                }
                \App\Models\InventoryBatch::whereIn('purchase_item_id', $purchase->items->pluck('id'))->delete();
            }

            // Case A: Items are provided in the update request
            if (!empty($validated['items']) && is_array($validated['items'])) {
                if ($oldStatus === 'Received') {
                    // Revert old stock out
                    foreach ($purchase->items as $item) {
                        $prod = Product::find($item->product_id) ?? Product::where('code', $item->product_code)->first();
                        if ($prod) {
                            $baseQty = (float)($item->base_quantity ?: $item->quantity);
                            $unitService->applyStock($prod, $warehouseId, $item->product_variant_id, $baseQty, 'out', 'purchase_edit', 'purchase', (string)$purchase->id, 'Revert stock for purchase edit');
                        }
                    }
                    \App\Models\InventoryBatch::whereIn('purchase_item_id', $purchase->items->pluck('id'))->delete();
                }

                // Delete old items
                $purchase->items()->delete();

                // Preload new products in bulk to eliminate N+1 queries
                $itemProductIds = collect($validated['items'])->pluck('product_id')->filter()->unique()->values()->all();
                $itemProductCodes = collect($validated['items'])->pluck('product_code')->filter()->unique()->values()->all();
                $productsMap = Product::with(['variants', 'unitLinks'])
                    ->where(function ($q) use ($itemProductIds, $itemProductCodes) {
                        if (!empty($itemProductIds)) $q->whereIn('id', $itemProductIds);
                        if (!empty($itemProductCodes)) $q->orWhereIn('code', $itemProductCodes);
                    })
                    ->lockForUpdate()->get();
                $productsById = $productsMap->keyBy('id');
                $productsByCode = $productsMap->keyBy('code');

                $itemsSubtotal = 0.0;
                foreach ($validated['items'] as $it) {
                    $itemsSubtotal += (float)$it['subtotal'];
                }
                $discount = isset($validated['discount']) ? (float)$validated['discount'] : (float)$purchase->discount;
                $shipping = isset($validated['shipping']) ? (float)$validated['shipping'] : (float)$purchase->shipping;
                $taxRate  = isset($validated['tax_rate']) ? (float)$validated['tax_rate'] : (float)$purchase->tax_rate;
                $netSubtotal = max(0.0, $itemsSubtotal - $discount);
                $calculatedTax = round(($netSubtotal * $taxRate) / 100.0, 2);
                $taxAmount = isset($validated['tax_amount']) ? (float)$validated['tax_amount'] : $calculatedTax;
                $netAdjustment = $shipping + $taxAmount - $discount;

                foreach ($validated['items'] as $item) {
                    $product = (!empty($item['product_id']) && isset($productsById[$item['product_id']]))
                        ? $productsById[$item['product_id']]
                        : ($productsByCode[$item['product_code']] ?? null);
                    $variantId = !empty($item['product_variant_id']) ? (int)$item['product_variant_id'] : null;

                    $linkedUnit = $product
                        ? $unitService->resolve($product, 'purchase', isset($item['unit_id']) ? (int)$item['unit_id'] : null, $item['unit_name'] ?? null, $variantId)
                        : [
                            'unit_id' => $item['unit_id'] ?? null,
                            'unit_name' => $item['unit_name'] ?? '',
                            'conversion_rate' => (float)($item['conversion_rate'] ?? 1),
                        ];
                    $quantity = (float)$item['quantity'];
                    $baseQuantity = $unitService->baseQuantity($quantity, (float)$linkedUnit['conversion_rate']);
                    $baseUnitCost = $unitService->basePrice((float)$item['unit_cost'], (float)$linkedUnit['conversion_rate']);

                    $purchaseItem = PurchaseItem::create([
                        'purchase_id'        => $purchase->id,
                        'product_id'         => $product?->id,
                        'product_variant_id' => $variantId,
                        'variant_name'       => $item['variant_name'] ?? null,
                        'unit_id'            => $linkedUnit['unit_id'],
                        'unit_name'          => $linkedUnit['unit_name'],
                        'conversion_rate'    => $linkedUnit['conversion_rate'],
                        'product_code'       => $item['product_code'],
                        'product_name'       => $item['product_name'],
                        'unit_cost'          => (float)$item['unit_cost'],
                        'base_unit_cost'     => $baseUnitCost,
                        'quantity'           => $quantity,
                        'base_quantity'      => $baseQuantity,
                        'tax'                => (float)($item['tax'] ?? 0),
                        'discount'           => (float)($item['discount'] ?? 0),
                        'subtotal'           => (float)$item['subtotal'],
                    ]);

                    // If newStatus is Received, add stock and record batch with Landed Cost
                    if ($newStatus === 'Received') {
                        $itemSubtotal = (float)$item['subtotal'];
                        $ratio = $itemsSubtotal > 0 ? ($itemSubtotal / $itemsSubtotal) : (1.0 / count($validated['items']));
                        $extra = $netAdjustment * $ratio;
                        $landedTotal = max(0.0, $itemSubtotal + $extra);
                        $landedBaseUnitCost = $baseQuantity > 0 ? round($landedTotal / $baseQuantity, 4) : $baseUnitCost;

                        if ($product) {
                            $unitService->applyStock($product, $warehouseId, $variantId, $baseQuantity, 'in', 'purchase', 'purchase', (string)$purchase->id, 'Purchase received in ' . $linkedUnit['unit_name']);
                            if ($baseUnitCost > 0.0001) {
                                $product->update(['purchase_price' => (float)$baseUnitCost, 'cost_price' => (float)$baseUnitCost]);
                                if ($variantId) {
                                    ProductVariant::where('id', $variantId)->update(['cost_price' => (float)$baseUnitCost]);
                                }
                            }
                            $fifoService->recordBatch(
                                warehouseId: (int)$warehouseId,
                                productId: (int)$product->id,
                                variantId: $variantId,
                                baseQuantity: (float)$baseQuantity,
                                baseUnitCost: (float)$landedBaseUnitCost,
                                sourceType: 'purchase',
                                sourceReference: (string)$purchase->reference_no,
                                receivedDate: (string)($validated['date'] ?? $purchase->date),
                                purchaseItemId: (int)$purchaseItem->id,
                                notes: "Purchase #{$purchase->reference_no} received"
                            );
                        }
                    }
                }
            } elseif ($oldStatus !== 'Received' && $newStatus === 'Received') {
                // Case B: No items sent, but status changed from Pending/Ordered to Received!
                // Add stock and FIFO batches for existing items with Landed Cost!
                $purchase->loadMissing('items');
                $itemsSubtotal = 0.0;
                foreach ($purchase->items as $it) {
                    $itemsSubtotal += (float)$it->subtotal;
                }
                $discount = isset($validated['discount']) ? (float)$validated['discount'] : (float)$purchase->discount;
                $shipping = isset($validated['shipping']) ? (float)$validated['shipping'] : (float)$purchase->shipping;
                $taxAmount = isset($validated['tax_amount']) ? (float)$validated['tax_amount'] : (float)$purchase->tax_amount;
                $netAdjustment = $shipping + $taxAmount - $discount;

                foreach ($purchase->items as $item) {
                    $product = Product::find($item->product_id) ?? Product::where('code', $item->product_code)->first();
                    $variantId = $item->product_variant_id;
                    $baseQty = (float)($item->base_quantity ?: $item->quantity);
                    $baseUnitCost = (float)$item->base_unit_cost;

                    $itemSubtotal = (float)$item->subtotal;
                    $ratio = $itemsSubtotal > 0 ? ($itemSubtotal / $itemsSubtotal) : (1.0 / max(1, $purchase->items->count()));
                    $extra = $netAdjustment * $ratio;
                    $landedTotal = max(0.0, $itemSubtotal + $extra);
                    $landedBaseUnitCost = $baseQty > 0 ? round($landedTotal / $baseQty, 4) : $baseUnitCost;

                    if ($product) {
                        $unitService->applyStock($product, $warehouseId, $variantId, $baseQty, 'in', 'purchase', 'purchase', (string)$purchase->id, 'Purchase status received');
                        if ($baseUnitCost > 0.0001) {
                            $product->update(['purchase_price' => (float)$baseUnitCost, 'cost_price' => (float)$baseUnitCost]);
                            if ($variantId) {
                                ProductVariant::where('id', $variantId)->update(['cost_price' => (float)$baseUnitCost]);
                            }
                        }
                        $fifoService->recordBatch(
                            warehouseId: (int)$warehouseId,
                            productId: (int)$product->id,
                            variantId: $variantId,
                            baseQuantity: (float)$baseQty,
                            baseUnitCost: (float)$landedBaseUnitCost,
                            sourceType: 'purchase',
                            sourceReference: (string)$purchase->reference_no,
                            receivedDate: (string)($validated['date'] ?? $purchase->date),
                            purchaseItemId: (int)$item->id,
                            notes: "Purchase #{$purchase->reference_no} status changed to Received"
                        );
                    }
                }
            }

            // Recalculate financial figures
            $subtotal = isset($itemsSubtotal) ? $itemsSubtotal : (isset($validated['subtotal']) ? (float)$validated['subtotal'] : (float)$purchase->subtotal);
            $discount = isset($validated['discount']) ? (float)$validated['discount'] : (float)$purchase->discount;
            $shipping = isset($validated['shipping']) ? (float)$validated['shipping'] : (float)$purchase->shipping;
            $taxRate  = isset($validated['tax_rate']) ? (float)$validated['tax_rate'] : (float)$purchase->tax_rate;
            $taxAmount = isset($taxAmount) ? $taxAmount : (isset($validated['tax_amount']) ? (float)$validated['tax_amount'] : (float)$purchase->tax_amount);

            $grandTotal = isset($validated['grand_total'])
                ? (float)$validated['grand_total']
                : round(max(0.0, $subtotal - $discount + $taxAmount + $shipping), 2);
            $paidAmount = isset($validated['paid_amount']) ? (float)$validated['paid_amount'] : (float)$purchase->paid_amount;
            $dueAmount  = max(0.0, $grandTotal - $paidAmount);
            $paymentStatus = $dueAmount <= 0.001 ? 'Paid' : ($paidAmount > 0.001 ? 'Partial' : 'Unpaid');

            $purchase->update(array_filter([
                'supplier_id'    => $newSupplierId,
                'supplier_name'  => $validated['supplier_name'] ?? $purchase->supplier_name,
                'warehouse_id'   => $warehouseId,
                'warehouse_name' => $validated['warehouse_name'] ?? $purchase->warehouse_name,
                'date'           => $validated['date'] ?? $purchase->date,
                'status'         => $newStatus,
                'tax_rate'       => $taxRate,
                'tax_amount'     => $taxAmount,
                'discount'       => $discount,
                'shipping'       => $shipping,
                'grand_total'    => $grandTotal,
                'paid_amount'    => $paidAmount,
                'due_amount'     => $dueAmount,
                'payment_status' => $paymentStatus,
                'payment_type'   => $validated['payment_type'] ?? $purchase->payment_type,
                'notes'          => $validated['notes'] ?? $purchase->notes,
                'user_name'      => $validated['user_name'] ?? $purchase->user_name,
                'user_role'      => $validated['user_role'] ?? $purchase->user_role,
            ], fn($v) => !is_null($v)));

            // Reconcile Supplier balance_due
            if ($oldSupplierId && $newSupplierId && $oldSupplierId === $newSupplierId) {
                $diff = $dueAmount - $oldDue;
                if (abs($diff) > 0.001) {
                    \App\Models\Supplier::where('id', $oldSupplierId)->increment('balance_due', $diff);
                }
            } else {
                if ($oldSupplierId && $oldDue > 0) {
                    \App\Models\Supplier::where('id', $oldSupplierId)->decrement('balance_due', $oldDue);
                }
                if ($newSupplierId && $dueAmount > 0) {
                    \App\Models\Supplier::where('id', $newSupplierId)->increment('balance_due', $dueAmount);
                }
            }

            DB::commit();
            $this->clearPurchaseRelatedCaches();

            return response()->json([
                'status'  => 'success',
                'message' => 'Purchase updated successfully',
                'data'    => $purchase->fresh()->load(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'supplier']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $purchase = Purchase::with(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'supplier'])->where('reference_no', (string)$id)->first() ?? Purchase::with(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'supplier'])->find($id);
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $purchase]);
    }

    public function destroy($id)
    {
        $purchase = Purchase::with(['items', 'supplier'])->where('reference_no', (string)$id)->first() ?? Purchase::with(['items', 'supplier'])->find($id);
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }

        // Guard: Check if any FIFO batch from this purchase has already been consumed in sales
        $batchIds = \App\Models\InventoryBatch::whereIn('purchase_item_id', $purchase->items->pluck('id'))->get();
        $depletedBatches = $batchIds->filter(fn($b) => (float)$b->remaining_quantity < (float)$b->initial_quantity);
        if ($depletedBatches->isNotEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete Purchase #' . $purchase->reference_no . ' because items from this purchase have already been sold or depleted from stock in POS sales or adjustments. Please use Purchase Return instead.',
            ], 422);
        }

        DB::beginTransaction();
        try {

            $affectedProducts = [];
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

                        $freshProduct = Product::with('stockBalances')->find($product->id);
                        if ($freshProduct) {
                            $affectedProducts[] = [
                                'id' => $freshProduct->id,
                                'stock_quantity' => (float)$freshProduct->stock_quantity,
                                'warehouse_stocks' => $freshProduct->stockBalances->map(fn($sb) => [
                                    'warehouse_id' => $sb->warehouse_id,
                                    'warehouse_name' => $sb->warehouse ? $sb->warehouse->name : 'Warehouse ' . $sb->warehouse_id,
                                    'product_variant_id' => $sb->product_variant_id,
                                    'quantity' => (float)$sb->quantity,
                                ])->values(),
                            ];
                        }
                    }
                }
            }

            // Clean up related FIFO inventory batches
            \App\Models\InventoryBatch::whereIn('purchase_item_id', $purchase->items->pluck('id'))->delete();

            // Reconcile Supplier balance_due
            if (!empty($purchase->supplier_id) && (float)$purchase->due_amount > 0) {
                \App\Models\Supplier::where('id', $purchase->supplier_id)->decrement('balance_due', (float)$purchase->due_amount);
            }

            $purchase->items()->delete();
            $purchase->delete();

            DB::commit();
            $this->clearPurchaseRelatedCaches();

            return response()->json([
                'status' => 'success',
                'message' => 'Purchase deleted and stock reverted successfully',
                'affected_products' => $affectedProducts,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function addPayment(Request $request, $id)
    {
        $purchase = Purchase::with(['items', 'supplier'])->find($id) ?? Purchase::with(['items', 'supplier'])->where('reference_no', $id)->first();
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }

        $validated = $request->validate([
            'amount' => 'required_without:payments|nullable|numeric|min:0.01',
            'payment_type' => 'required_without:payments|nullable|string',
            'payment_date' => 'nullable|string',
            'notes' => 'nullable|string',
            'payments' => 'nullable|array|min:1',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.payment_type' => 'required|string',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount' => 'nullable|numeric|min:0',
            'payments.*.payment_date' => 'nullable|string',
            'payments.*.notes' => 'nullable|string',
            'user_name' => 'nullable|string|max:255',
            'user_role' => 'nullable|string|max:255',
        ]);

        $existingPayments = $purchase->payments;
        $updatedPayments = array_values($existingPayments);
        $authUser = $request->user();
        $globalUserName = $validated['user_name'] ?? ($authUser?->name ?? ($purchase->user_name ?: 'Super Admin'));
        $globalUserRole = $validated['user_role'] ?? ($authUser?->role ?? ($purchase->user_role ?: 'Super Admin'));

        $priorDue = (float)$purchase->due_amount;
        $payingAmount = !empty($validated['payments'])
            ? (float)collect($validated['payments'])->sum('amount')
            : (float)$validated['amount'];

        if ($payingAmount > $priorDue + 0.01) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Payment amount ($' . number_format($payingAmount, 2) . ') exceeds outstanding due balance ($' . number_format($priorDue, 2) . ').',
            ], 422);
        }

        if (!empty($validated['payments']) && is_array($validated['payments'])) {
            foreach ($validated['payments'] as $tender) {
                $pAmt = (float)$tender['amount'];
                $newPaymentId = count($updatedPayments) + 1;
                $cleanRef = preg_replace('/^#\s*/', '', trim((string)$purchase->reference_no));
                $paymentRef = 'PAY-' . ($cleanRef ?: $purchase->id) . '-' . time() . '-' . rand(100, 999);
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
            $newPaymentId = count($updatedPayments) + 1;
            $cleanRef = preg_replace('/^#\s*/', '', trim((string)$purchase->reference_no));
            $paymentRef = 'PAY-' . ($cleanRef ?: $purchase->id) . '-' . time() . '-' . rand(100, 999);
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
        $newDue = max(0, (float)$purchase->grand_total - $newPaid);
        $newStatus = $newDue <= 0.001 ? 'Paid' : ($newPaid > 0 ? 'Partial' : 'Unpaid');

        $uniqueTypes = collect($updatedPayments)->pluck('payment_type')->filter()->unique();
        if ($uniqueTypes->count() > 1) {
            $newPaymentType = 'Split Payment';
        } elseif ($uniqueTypes->count() === 1) {
            $newPaymentType = $uniqueTypes->first();
        } else {
            $newPaymentType = $validated['payment_type'] ?: 'Cash';
        }

        DB::beginTransaction();
        try {
            $purchase->update([
                'payments_payload' => json_encode($updatedPayments),
                'paid_amount'      => $newPaid,
                'due_amount'       => $newDue,
                'payment_status'   => $newStatus,
                'payment_type'     => $newPaymentType,
            ]);

            // Decrement Supplier balance_due
            if (!empty($purchase->supplier_id) && $payingAmount > 0) {
                \App\Models\Supplier::where('id', $purchase->supplier_id)->decrement('balance_due', min($priorDue, $payingAmount));
            }

            DB::commit();
            $this->clearPurchaseRelatedCaches();

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment added successfully',
                'data'    => $purchase->fresh()->load(['items', 'supplier']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function deletePayment(Request $request, $purchaseId, $paymentId = null)
    {
        $purchase = Purchase::with(['items', 'supplier'])->find($purchaseId) ?? Purchase::with(['items', 'supplier'])->where('reference_no', $purchaseId)->first();
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }

        $existingPayments = $purchase->payments;
        if (empty($existingPayments)) {
            $purchase->update([
                'payments_payload' => json_encode([]),
                'paid_amount'      => 0.0,
                'due_amount'       => (float)$purchase->grand_total,
                'payment_status'   => 'Unpaid',
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment deleted successfully. Due balance restored.',
                'data'    => $purchase->fresh()->load(['items', 'supplier']),
            ]);
        }

        $targetIdentifier = trim((string)($paymentId ?? $purchaseId));
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
                $deletedAmount = (float)($p['amount'] ?? 0);
                continue;
            }
            $remainingPayments[] = $p;
        }

        $newPaid = (float)collect($remainingPayments)->sum('amount');
        $grandTotal = (float)$purchase->grand_total;
        $newDue = max(0.0, $grandTotal - $newPaid);
        $newStatus = $newPaid <= 0.001 ? 'Unpaid' : ($newDue <= 0.001 ? 'Paid' : 'Partial');

        $uniqueTypes = collect($remainingPayments)->pluck('payment_type')->filter()->unique();
        if ($uniqueTypes->count() > 1) {
            $newPaymentType = 'Split Payment';
        } elseif ($uniqueTypes->count() === 1) {
            $newPaymentType = $uniqueTypes->first();
        } else {
            $newPaymentType = $purchase->payment_type ?: 'Cash';
        }

        DB::beginTransaction();
        try {
            $purchase->update([
                'payments_payload' => json_encode(array_values($remainingPayments)),
                'paid_amount'      => $newPaid,
                'due_amount'       => $newDue,
                'payment_status'   => $newStatus,
                'payment_type'     => $newPaymentType,
            ]);

            // Restore Supplier balance_due
            if (!empty($purchase->supplier_id) && $deletedAmount > 0) {
                \App\Models\Supplier::where('id', $purchase->supplier_id)->increment('balance_due', $deletedAmount);
            }

            DB::commit();
            $this->clearPurchaseRelatedCaches();

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment deleted successfully. Due balance restored to ' . number_format($newDue, 2),
                'data'    => $purchase->fresh()->load(['items', 'supplier']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    protected function clearPurchaseRelatedCaches(): void
    {
        try {
            \Illuminate\Support\Facades\Cache::store('file')->flush();
            \Illuminate\Support\Facades\Cache::forget('products_cache_all');
            \Illuminate\Support\Facades\Cache::increment('products_cache_version');
            \Illuminate\Support\Facades\Cache::forget('pos_stock_summary');
            \Illuminate\Support\Facades\Cache::forget('inventory_valuation_report');
            \Illuminate\Support\Facades\Cache::forget('stock_alert_report');
            ReportController::invalidateDashboardCache();
        } catch (\Throwable $e) {}
    }
}
