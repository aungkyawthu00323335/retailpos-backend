<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\PurchaseReturnCostLayer;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Setting;
use App\Models\User;
use App\Services\ProductUnitConversionService;
use App\Services\FifoInventoryService;
use App\Services\PurchaseAccountingService;
use App\Support\ListCursorHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseReturnController extends Controller
{
    use \App\Traits\GeneratesSequentialCode;

    protected function accounting(): PurchaseAccountingService
    {
        return app(PurchaseAccountingService::class);
    }
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
        $accounting = $this->accounting();
        $query = PurchaseReturn::query();
        $accounting->applyOutletScope($query, $accounting->extractAllowedOutletIds($request), 'warehouse_id');

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

        // 3. Return Status filtering (Completed / Received both mean stock/ledger returned, vs Pending)
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $statusFilter = $request->input('status');
            if (in_array($statusFilter, ['Completed', 'Received'], true)) {
                $query->whereIn('status', ['Completed', 'Received']);
            } else {
                $query->where('status', $statusFilter);
            }
        }

        // 4. Payment Status filtering (Paid, Partial, Unpaid)
        $paymentStatus = $request->input('payment_status') ?? $request->input('payment');
        if (!empty($paymentStatus) && $paymentStatus !== 'All') {
            $query->where('payment_status', $paymentStatus);
        }

        // 5. Payment Type / Refund Tender filtering (Cash, Bank Transfer, Credit Note, Cheque, Split Payment)
        $paymentType = $request->input('payment_type') ?? $request->input('payment_method');
        if (!empty($paymentType) && $paymentType !== 'All') {
            $accounting->applyPaymentTypeFilter($query, (string)$paymentType, 'payment_type', null, 'payments_payload');
        }

        // 6. Return Reason filtering
        if ($request->filled('return_reason') && $request->input('return_reason') !== 'All') {
            $query->where('return_reason', $request->input('return_reason'));
        }

        // 6b. Linked Purchase Order Reference filtering (used for fast cumulative return checks)
        if ($request->filled('purchase_reference_no')) {
            $poRef = trim((string)$request->input('purchase_reference_no'));
            $altHyphen = str_replace('_', '-', $poRef);
            $altUnderscore = str_replace('-', '_', $poRef);
            $query->where(function ($q) use ($poRef, $altHyphen, $altUnderscore) {
                $q->where('purchase_reference_no', $poRef)
                  ->orWhere('purchase_reference_no', $altHyphen)
                  ->orWhere('purchase_reference_no', $altUnderscore);
            });
        }

        // 7. Date range filtering
        $fromDate = $request->input('from_date') ?? $request->input('date_from') ?? $request->input('start_date');
        $toDate = $request->input('to_date') ?? $request->input('date_to') ?? $request->input('end_date');

        if (!empty($fromDate)) {
            $query->where('date', '>=', $this->normalizeFilterBoundaryDate((string)$fromDate, false));
        }

        if (!empty($toDate)) {
            $toStr = trim((string)$toDate);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $toStr)) {
                try {
                    $nextDay = \Carbon\Carbon::parse($toStr)->addDay()->format('Y-m-d');
                    $query->where('date', '<', $nextDay);
                } catch (\Throwable $e) {
                    $query->where('date', '<=', $toStr . ' 23:59:59');
                }
            } else {
                $query->where('date', '<=', $this->normalizeFilterBoundaryDate($toStr, true));
            }
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
            $altHyphen = str_replace('_', '-', $search);
            $altUnderscore = str_replace('-', '_', $search);
            if (preg_match('/^(PR|PRN)[-_]/i', $search)) {
                $query->where(function ($q) use ($search, $altHyphen, $altUnderscore) {
                    $q->where('reference_no', 'like', "{$search}%");
                    if ($altHyphen !== $search) {
                        $q->orWhere('reference_no', 'like', "{$altHyphen}%");
                    }
                    if ($altUnderscore !== $search) {
                        $q->orWhere('reference_no', 'like', "{$altUnderscore}%");
                    }
                });
            } else {
                $query->where(function ($q) use ($search, $altHyphen, $altUnderscore) {
                    if (preg_match('/^#?(\d+)$/', $search, $m)) {
                        $q->where('id', (int)$m[1])
                          ->orWhere('reference_no', 'like', "%{$search}%");
                    } else {
                        $q->where('reference_no', 'like', "%{$search}%");
                    }
                    if ($altHyphen !== $search) {
                        $q->orWhere('reference_no', 'like', "%{$altHyphen}%");
                    }
                    if ($altUnderscore !== $search) {
                        $q->orWhere('reference_no', 'like', "%{$altUnderscore}%");
                    }
                    $q->orWhere('purchase_reference_no', 'like', "%{$search}%")
                      ->orWhere('supplier_name', 'like', "%{$search}%")
                      ->orWhere('warehouse_name', 'like', "%{$search}%")
                      ->orWhere('user_name', 'like', "%{$search}%")
                      ->orWhere('created_by', 'like', "%{$search}%")
                      ->orWhere('return_reason', 'like', "%{$search}%")
                      ->orWhere('payment_type', 'like', "%{$search}%")
                      ->orWhere('payment_status', 'like', "%{$search}%")
                      ->orWhere('notes', 'like', "%{$search}%")
                      ->orWhere('items_payload', 'like', "%{$search}%");
                });
            }
        }

        return $query;
    }

    private function clearPurchaseReturnCache(): void
    {
        // Zero-cache mode: purchase returns and reports always query live DB.
    }

    private function attachAllocatedCreditAmounts($returns): void
    {
        $accounting = $this->accounting();
        if ($returns->isEmpty() || !$accounting->schemaHasTable('purchase_return_allocations')) {
            return;
        }
        $allocQuery = PurchaseReturnAllocation::whereIn('purchase_return_id', $returns->pluck('id')->all());
        if ($accounting->schemaHasColumn('purchase_return_allocations', 'released_at')) {
            $allocQuery->whereNull('released_at');
        }
        $allocMap = $allocQuery
            ->selectRaw('purchase_return_id, COALESCE(SUM(amount), 0) as allocated')
            ->groupBy('purchase_return_id')
            ->pluck('allocated', 'purchase_return_id')
            ->all();

        foreach ($returns as $ret) {
            $ret->setAttribute('allocated_credit_amount', round((float)($allocMap[$ret->id] ?? 0.0), 2));
        }
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

        $accounting = $this->accounting();
        $allowedOutlets = $accounting->extractAllowedOutletIds($request);
        $warehouseNameFilter = $request->input('warehouse') ?? $request->input('warehouse_name');
        $supplierFilter = $request->input('supplier') ?? $request->input('supplier_name');
        $paymentStatusFilter = $request->input('payment_status') ?? $request->input('payment');
        $paymentTypeFilter = $request->input('payment_type') ?? $request->input('payment_method');
        $creatorFilter = $request->input('created_by') ?? $request->input('user') ?? $request->input('user_name');
        $hasFilters = $request->filled('warehouse_id')
            || $request->filled('outlet_id')
            || (!empty($warehouseNameFilter) && $warehouseNameFilter !== 'All')
            || $request->filled('supplier_id')
            || (!empty($supplierFilter) && $supplierFilter !== 'All')
            || ($request->filled('status') && $request->input('status') !== 'All')
            || (!empty($paymentStatusFilter) && $paymentStatusFilter !== 'All')
            || (!empty($paymentTypeFilter) && $paymentTypeFilter !== 'All')
            || ($request->filled('return_reason') && $request->input('return_reason') !== 'All')
            || $request->filled('purchase_reference_no')
            || !empty($request->input('from_date') ?? $request->input('date_from') ?? $request->input('start_date'))
            || !empty($request->input('to_date') ?? $request->input('date_to') ?? $request->input('end_date'))
            || (!empty($creatorFilter) && $creatorFilter !== 'All')
            || $request->filled('search');

        $noItems = $request->boolean('no_items', false);
        $skipSummary = $request->boolean('skip_summary', false);
        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        $page = max(1, (int)$request->input('page', 1));
        $perPage = min(max((int)$request->input('per_page', 50), 1), 500);
        $cursorData = !$isAll
            ? ListCursorHelper::decode(
                (string)$request->input('cursor', ''),
                $sortColumn,
                $sortDirection,
                $allowedSorts,
                ['id'],
                ['grand_total', 'paid_amount', 'due_amount']
            )
            : null;
        $isScrollPage = ($page > 1 || $cursorData !== null);

        $computeSummary = static function () use ($query): array {
            // Aggregate summary in a single fast DB query without model hydration and strict mode safe
            $aggregatesQuery = (clone $query)->toBase();
            $aggregatesQuery->columns = null;
            $aggregatesQuery->orders = null;
            $totalsAgg = $aggregatesQuery->selectRaw("
                COUNT(*) as total_records,
                COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN grand_total ELSE 0 END), 0) as total_grand_total,
                COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN paid_amount ELSE 0 END), 0) as total_paid_amount,
                COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN due_amount ELSE 0 END), 0) as total_due_amount,
                COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN subtotal ELSE 0 END), 0) as total_subtotal,
                COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN tax_amount ELSE 0 END), 0) as total_tax_amount,
                COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN discount ELSE 0 END), 0) as total_discount,
                COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN shipping ELSE 0 END), 0) as total_shipping
            ")->first();

            return [
                'total_records'     => (int)($totalsAgg->total_records ?? 0),
                'total_grand_total' => (float)($totalsAgg->total_grand_total ?? 0),
                'total_paid_amount' => (float)($totalsAgg->total_paid_amount ?? 0),
                'total_due_amount'  => (float)($totalsAgg->total_due_amount ?? 0),
                'total_subtotal'    => (float)($totalsAgg->total_subtotal ?? 0),
                'total_tax_amount'  => (float)($totalsAgg->total_tax_amount ?? 0),
                'total_discount'    => (float)($totalsAgg->total_discount ?? 0),
                'total_shipping'    => (float)($totalsAgg->total_shipping ?? 0),
            ];
        };

        if ($skipSummary && $request->filled('known_total')) {
            $summaryData = [
                'total_records'     => max(0, (int)$request->input('known_total')),
                'total_grand_total' => 0.0,
                'total_paid_amount' => 0.0,
                'total_due_amount'  => 0.0,
                'total_subtotal'    => 0.0,
                'total_tax_amount'  => 0.0,
                'total_discount'    => 0.0,
                'total_shipping'    => 0.0,
            ];
        } elseif ($skipSummary) {
            $countQuery = (clone $query)->setEagerLoads([])->toBase();
            $countQuery->columns = null;
            $countQuery->orders = null;
            $summaryData = [
                'total_records'     => (int)$countQuery->count(),
                'total_grand_total' => 0.0,
                'total_paid_amount' => 0.0,
                'total_due_amount'  => 0.0,
                'total_subtotal'    => 0.0,
                'total_tax_amount'  => 0.0,
                'total_discount'    => 0.0,
                'total_shipping'    => 0.0,
            ];
        } else {
            $summaryData = $computeSummary();
        }

        $canViewCost = $accounting->canViewCost($request);
        $summaryData = $accounting->maskFinancialSummary($summaryData, $canViewCost);

        $totalCount = (int)$summaryData['total_records'];
        $cacheControl = 'no-store, no-cache, must-revalidate, private';

        if ($noItems) {
            $query->select([
                'id', 'reference_no', 'purchase_reference_no', 'supplier_id', 'supplier_name',
                'warehouse_id', 'outlet_id', 'warehouse_name', 'date', 'status',
                'grand_total', 'paid_amount', 'due_amount', 'return_cost_total', 'cost_variance',
                'payment_status', 'payment_type', 'cash_register_id',
                'subtotal', 'tax_rate', 'tax_amount', 'is_tax_recoverable', 'discount', 'shipping', 'notes',
                'return_reason', 'created_by', 'user_id', 'user_name', 'user_role',
                'created_at', 'updated_at',
            ]);
        }

        if ($cursorData !== null) {
            ListCursorHelper::apply($query, $sortColumn, $sortDirection, $cursorData);
        }
        // Apply deterministic ordering ONLY to the actual paginated records query
        $query->orderBy($sortColumn, $sortDirection);
        if ($sortColumn !== 'id') {
            $query->orderBy('id', $sortDirection);
        }

        if ($isAll) {
            $limit = min(1000, max(1, (int)$request->input('limit', 500)));
            $returns = $query->take($limit)->get();

            if ($noItems) {
                $returns->each->setAppends([]);
            }
            $this->attachAllocatedCreditAmounts($returns);
            $returns->each(fn($r) => $accounting->maskPurchaseReturnCostFields($r, $canViewCost));

            return response()->json([
                'status' => 'success',
                'data'   => $returns,
                'pagination' => [
                    'current_page' => 1,
                    'per_page'     => $returns->count(),
                    'total'        => $totalCount,
                    'last_page'    => (int)ceil($totalCount / max(1, $returns->count())),
                ],
                'summary' => $summaryData,
                'meta' => [
                    'total' => $totalCount,
                ],
            ])
                ->header('Cache-Control', $cacheControl);
        }

        $offset = $cursorData !== null ? 0 : (($page - 1) * $perPage);
        $fetched = $query->offset($offset)->limit($perPage + 1)->get();
        $hasMore = $fetched->count() > $perPage;
        $returns = $hasMore ? $fetched->slice(0, $perPage)->values() : $fetched;
        $lastRowObj = $returns->last();
        $nextCursor = ($hasMore && $lastRowObj !== null)
            ? ListCursorHelper::encode((object)$lastRowObj, $sortColumn, $sortDirection, $allowedSorts)
            : null;
        if ($noItems) {
            $returns->each->setAppends([]);
        }
        $this->attachAllocatedCreditAmounts($returns);
        $returns->each(fn($r) => $accounting->maskPurchaseReturnCostFields($r, $canViewCost));
        $lastPage = max(1, (int)ceil($totalCount / max(1, $perPage)));

        $pagination = [
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $totalCount,
            'last_page'    => $lastPage,
            'has_more'     => $hasMore,
        ];
        if ($nextCursor !== null) {
            $pagination['next_cursor'] = $nextCursor;
        }

        return response()->json([
            'status' => 'success',
            'data'   => $returns->values(),
            'pagination' => $pagination,
            'summary' => $summaryData,
            'meta' => [
                'total' => $totalCount,
            ],
        ])
            ->header('Cache-Control', $cacheControl);
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
        $paymentTypeFilter = $request->input('payment_type') ?: ($request->input('payment_method') ?: 'All');
        $creatorFilter = $request->input('created_by') ?: ($request->input('user') ?: ($request->input('user_name') ?: 'All'));
        $supplierFilter = $request->input('supplier') ?: ($request->input('supplier_name') ?: 'All');
        $fromDate = $request->input('from_date') ?: ($request->input('date_from') ?: $request->input('start_date'));
        $toDate = $request->input('to_date') ?: ($request->input('date_to') ?: $request->input('end_date'));
        $searchQuery = $request->input('search');

        $query->orderBy($sortColumn, $sortDirection);
        if ($sortColumn !== 'id') {
            $query->orderBy('id', $sortDirection);
        }

        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $currencyCode = $accounting->resolveDefaultCurrencyCode();

        $callback = function () use (
            $query,
            $warehouseFilter,
            $statusFilter,
            $paymentFilter,
            $paymentTypeFilter,
            $creatorFilter,
            $supplierFilter,
            $fromDate,
            $toDate,
            $searchQuery,
            $canViewCost,
            $currencyCode
        ) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Microsoft Excel on Windows
            fwrite($file, "\xEF\xBB\xBF");

            // CSV injection escape helper
            $sanitize = function ($val) {
                if ($val === null) return '';
                $str = (string)$val;
                if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    return "'" . $str;
                }
                return $str;
            };

            // 1. Metadata Block
            fputcsv($file, ['Purchase Returns List Export']);
            fputcsv($file, ['Generated At', date('c')]);
            fputcsv($file, ['Warehouse Filter', $sanitize($warehouseFilter)]);
            fputcsv($file, ['Status Filter', $sanitize($statusFilter)]);
            if (!empty($paymentFilter) && $paymentFilter !== 'All') {
                fputcsv($file, ['Payment Filter', $sanitize($paymentFilter)]);
            }
            if (!empty($paymentTypeFilter) && $paymentTypeFilter !== 'All') {
                fputcsv($file, ['Payment Type Filter', $sanitize($paymentTypeFilter)]);
            }
            if (!empty($supplierFilter) && $supplierFilter !== 'All') {
                fputcsv($file, ['Supplier Filter', $sanitize($supplierFilter)]);
            }
            if (!empty($creatorFilter) && $creatorFilter !== 'All') {
                fputcsv($file, ['Created By Filter', $sanitize($creatorFilter)]);
            }
            if (!empty($fromDate) || !empty($toDate)) {
                $fStr = !empty($fromDate) ? explode(' ', $fromDate)[0] : 'Beginning';
                $tStr = !empty($toDate) ? explode(' ', $toDate)[0] : 'Present';
                fputcsv($file, ['Date Range', $sanitize("$fStr to $tStr")]);
            }
            if (!empty($searchQuery)) {
                fputcsv($file, ['Search Filter', $sanitize($searchQuery)]);
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
                "Subtotal ({$currencyCode})",
                'Tax Rate (%)',
                "Tax Amount ({$currencyCode})",
                "Discount ({$currencyCode})",
                "Shipping ({$currencyCode})",
                "Grand Total ({$currencyCode})",
                "Paid ({$currencyCode})",
                "Due ({$currencyCode})",
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

                $grandTotal = (float)$ret->grand_total;
                $taxAmount = (float)$ret->tax_amount;
                $discount = (float)$ret->discount;
                $shipping = (float)$ret->shipping;
                $subtotal = (float)$ret->subtotal > 0
                    ? (float)$ret->subtotal
                    : max(0.0, $grandTotal - $taxAmount - $shipping + $discount);

                if (in_array((string)$ret->status, ['Completed', 'Received'], true)) {
                    $runningTotalReturnUnits += $returnQty;
                    $totalGrandTotal += $grandTotal;
                    $totalPaid += (float)$ret->paid_amount;
                    $totalDue += (float)$ret->due_amount;
                    $totalSubtotal += $subtotal;
                    $totalTax += $taxAmount;
                    $totalDiscount += $discount;
                    $totalShipping += $shipping;
                }

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
                    $canViewCost ? number_format($subtotal, 2, '.', '') : 'Protected',
                    number_format((float)$ret->tax_rate, 1, '.', ''),
                    $canViewCost ? number_format($taxAmount, 2, '.', '') : 'Protected',
                    $canViewCost ? number_format($discount, 2, '.', '') : 'Protected',
                    $canViewCost ? number_format($shipping, 2, '.', '') : 'Protected',
                    $canViewCost ? number_format($grandTotal, 2, '.', '') : 'Protected',
                    $canViewCost ? number_format((float)$ret->paid_amount, 2, '.', '') : 'Protected',
                    $canViewCost ? number_format((float)$ret->due_amount, 2, '.', '') : 'Protected',
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
                'TOTAL',
                "$totalCount Returns",
                '',
                '',
                '',
                '',
                '',
                '',
                number_format($runningTotalReturnUnits, 2, '.', ''),
                $canViewCost ? number_format($totalSubtotal, 2, '.', '') : 'Protected',
                '',
                $canViewCost ? number_format($totalTax, 2, '.', '') : 'Protected',
                $canViewCost ? number_format($totalDiscount, 2, '.', '') : 'Protected',
                $canViewCost ? number_format($totalShipping, 2, '.', '') : 'Protected',
                $canViewCost ? number_format($totalGrandTotal, 2, '.', '') : 'Protected',
                $canViewCost ? number_format($totalPaid, 2, '.', '') : 'Protected',
                $canViewCost ? number_format($totalDue, 2, '.', '') : 'Protected',
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

    public function show(Request $request, $id)
    {
        $return = PurchaseReturn::find($id) ?? PurchaseReturn::where('reference_no', (string)$id)->first();
        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }

        $accounting = $this->accounting();
        $accounting->assertOutletAccess(
            $request,
            $return->warehouse_id ? (int)$return->warehouse_id : ($return->outlet_id ? (int)$return->outlet_id : null),
            $return->warehouse_name
        );
        $this->attachAllocatedCreditAmounts(collect([$return]));
        $accounting->maskPurchaseReturnCostFields($return, $accounting->canViewCost($request));

        return response()->json([
            'status' => 'success',
            'data'   => $return,
        ]);
    }

    /**
     * Validate linked PO integrity and cumulative over-return quantities inside active DB lock.
     */
    private function validateOverReturn(
        ?string $purchaseRef,
        array $items,
        ?int $ignoreReturnId = null,
        ?int $supplierId = null,
        ?int $warehouseId = null
    ): ?string {
        if (empty($purchaseRef) || trim($purchaseRef) === '') {
            return null;
        }

        $clean = trim($purchaseRef);
        $altHyphen = str_replace('_', '-', $clean);
        $altUnderscore = str_replace('-', '_', $clean);

        $linkedPurchase = Purchase::where(function ($q) use ($clean, $altHyphen, $altUnderscore) {
                $q->where('reference_no', $clean)
                  ->orWhere('reference_no', $altHyphen)
                  ->orWhere('reference_no', $altUnderscore);
            })
            ->with('items')
            ->lockForUpdate()
            ->first();

        if (!$linkedPurchase) {
            return "Linked Purchase Order '{$clean}' was not found.";
        }

        if ($linkedPurchase->status !== 'Received') {
            return "Cannot return items for Purchase #{$linkedPurchase->reference_no} while status is '{$linkedPurchase->status}'. Goods must be Received first.";
        }

        if ($supplierId !== null && $supplierId > 0 && !empty($linkedPurchase->supplier_id) && (int)$linkedPurchase->supplier_id !== $supplierId) {
            return "Selected supplier does not match the supplier on Purchase #{$linkedPurchase->reference_no}.";
        }

        if ($warehouseId !== null && $warehouseId > 0 && !empty($linkedPurchase->warehouse_id) && (int)$linkedPurchase->warehouse_id !== $warehouseId) {
            return "Selected warehouse does not match the receiving warehouse of Purchase #{$linkedPurchase->reference_no}.";
        }

        if (!$linkedPurchase->items || $linkedPurchase->items->isEmpty()) {
            return null;
        }

        // 1. Calculate total purchased base quantities by product + variant (and fallback product_code map)
        $purchasedBaseTotals = [];
        $purchasedByCode = [];
        $productNames = [];
        foreach ($linkedPurchase->items as $poItem) {
            $pId = (int)$poItem->product_id;
            $vId = $poItem->product_variant_id ? (int)$poItem->product_variant_id : null;
            $key = "{$pId}_" . ($vId ?? '0');
            $poConv = (float)($poItem->conversion_rate ?? 1.0);
            if ($poConv <= 0) {
                $poConv = 1.0;
            }
            $poBaseQty = (float)$poItem->base_quantity > 0.0001
                ? (float)$poItem->base_quantity
                : max(0.0, (float)$poItem->quantity * $poConv);
            $purchasedBaseTotals[$key] = ($purchasedBaseTotals[$key] ?? 0.0) + $poBaseQty;
            $productNames[$key] = $poItem->product_name;
            if (!empty($poItem->product_code)) {
                $purchasedByCode[trim((string)$poItem->product_code)] = ($purchasedByCode[trim((string)$poItem->product_code)] ?? 0.0) + $poBaseQty;
            }
        }

        // 2. Lock other completed/received returns first, then resolve product IDs for any historical/incoming items omitting product_id
        $existingReturns = PurchaseReturn::where(function ($q) use ($linkedPurchase, $clean, $altHyphen, $altUnderscore) {
                $q->where('purchase_reference_no', $linkedPurchase->reference_no)
                  ->orWhere('purchase_reference_no', $clean)
                  ->orWhere('purchase_reference_no', $altHyphen)
                  ->orWhere('purchase_reference_no', $altUnderscore);
            })
            ->when($ignoreReturnId !== null, fn($q) => $q->where('id', '!=', $ignoreReturnId))
            ->whereIn('status', ['Completed', 'Received', 'Pending'])
            ->lockForUpdate()
            ->get();

        $unkeyedCodes = [];
        foreach ($existingReturns as $exReturn) {
            foreach ((array)$exReturn->items as $exIt) {
                if ((int)($exIt['product_id'] ?? 0) <= 0 && !empty($exIt['product_code'])) {
                    $unkeyedCodes[] = trim((string)$exIt['product_code']);
                }
            }
        }
        foreach ($items as $it) {
            if ((int)($it['product_id'] ?? 0) <= 0 && !empty($it['product_code'])) {
                $unkeyedCodes[] = trim((string)$it['product_code']);
            }
        }
        $codeToProductId = [];
        foreach ($linkedPurchase->items as $poItem) {
            $poCode = !empty($poItem->product_code) ? trim((string)$poItem->product_code) : '';
            if ($poCode !== '' && (int)$poItem->product_id > 0) {
                $codeToProductId[$poCode] = (int)$poItem->product_id;
            }
        }
        $unkeyedCodes = array_values(array_unique(array_filter($unkeyedCodes)));
        if (!empty($unkeyedCodes)) {
            $dbMatchedProducts = Product::withTrashed()
                ->where(function ($q) use ($unkeyedCodes) {
                    $q->whereIn('code', $unkeyedCodes)->orWhereIn('sku', $unkeyedCodes);
                })
                ->get(['id', 'code', 'sku']);
            foreach ($dbMatchedProducts as $mp) {
                if (!empty($mp->code)) {
                    $codeToProductId[trim((string)$mp->code)] ??= (int)$mp->id;
                }
                if (!empty($mp->sku)) {
                    $codeToProductId[trim((string)$mp->sku)] ??= (int)$mp->id;
                }
            }
        }

        $alreadyReturnedBaseTotals = [];
        $alreadyReturnedByCode = [];
        foreach ($existingReturns as $exReturn) {
            foreach ($exReturn->items as $exIt) {
                $exCode = !empty($exIt['product_code']) ? trim((string)$exIt['product_code']) : null;
                $pId = (int)($exIt['product_id'] ?? 0);
                if ($pId <= 0 && $exCode !== null && isset($codeToProductId[$exCode])) {
                    $pId = $codeToProductId[$exCode];
                }
                $vId = !empty($exIt['product_variant_id']) ? (int)$exIt['product_variant_id'] : null;
                $key = "{$pId}_" . ($vId ?? '0');
                $conv = (float)($exIt['conversion_rate'] ?? 1.0);
                if ($conv <= 0) $conv = 1.0;
                $qty = (float)($exIt['quantity'] ?? 0.0);
                $bQty = (float)($exIt['base_quantity'] ?? 0) > 0.0001
                    ? (float)$exIt['base_quantity']
                    : ($qty * $conv);
                $alreadyReturnedBaseTotals[$key] = ($alreadyReturnedBaseTotals[$key] ?? 0.0) + $bQty;
                if ($exCode !== null && $vId === null) {
                    $alreadyReturnedByCode[$exCode] = ($alreadyReturnedByCode[$exCode] ?? 0.0) + $bQty;
                }
            }
        }

        // 3. Sum requested return base quantities in this request
        $incomingBaseTotals = [];
        $incomingCodes = [];
        foreach ($items as $it) {
            $inCode = !empty($it['product_code']) ? trim((string)$it['product_code']) : null;
            $pId = (int)($it['product_id'] ?? 0);
            if ($pId <= 0 && $inCode !== null && isset($codeToProductId[$inCode])) {
                $pId = $codeToProductId[$inCode];
            }
            $vId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
            $key = "{$pId}_" . ($vId ?? '0');
            $conv = (float)($it['conversion_rate'] ?? 1.0);
            if ($conv <= 0) $conv = 1.0;
            $qty = (float)($it['quantity'] ?? 0.0);
            $bQty = (float)($it['base_quantity'] ?? 0) > 0.0001
                ? (float)$it['base_quantity']
                : ($qty * $conv);
            $incomingBaseTotals[$key] = ($incomingBaseTotals[$key] ?? 0.0) + $bQty;
            $incomingCodes[$key] = $inCode;
            if (empty($productNames[$key]) && !empty($it['product_name'])) {
                $productNames[$key] = $it['product_name'];
            }
        }

        // 4. Verify every item was purchased on this PO and does not exceed remaining quantity
        foreach ($incomingBaseTotals as $key => $incomingQty) {
            $isSimpleItemKey = str_ends_with((string)$key, '_0');
            $purchasedQty = $purchasedBaseTotals[$key] ?? 0.0;
            $code = $incomingCodes[$key] ?? null;
            // Only use product_code fallback for non-variant (simple) items so same-code variants stay strictly isolated
            if ($isSimpleItemKey && $purchasedQty <= 0.0001 && $code && isset($purchasedByCode[$code])) {
                $purchasedQty = $purchasedByCode[$code];
            }
            $pName = $productNames[$key] ?? 'Item';

            if ($purchasedQty <= 0.0001) {
                return "Item '{$pName}' was not purchased on Purchase #{$linkedPurchase->reference_no} and cannot be returned against it.";
            }

            $alreadyReturned = $alreadyReturnedBaseTotals[$key] ?? 0.0;
            if ($isSimpleItemKey && $alreadyReturned <= 0.0001 && $code && isset($alreadyReturnedByCode[$code])) {
                $alreadyReturned = $alreadyReturnedByCode[$code];
            }
            $remaining = max(0.0, $purchasedQty - $alreadyReturned);

            if ($incomingQty > $remaining + 0.001) {
                return "Cannot return {$incomingQty} base units for '{$pName}'. Original purchased: {$purchasedQty}, already returned: {$alreadyReturned}, remaining available: {$remaining}.";
            }
        }

        return null;
    }

    /**
     * Validate product/variant ownership & enforce authoritative multi-unit conversion rates on purchase return items.
     *
     * @return array{items: array, error: ?string}
     */
    private function normalizeAndValidateReturnItems(array $items): array
    {
        if (empty($items)) {
            return ['items' => [], 'error' => null];
        }

        $productIds = [];
        $productCodes = [];
        foreach ($items as $it) {
            if (!empty($it['product_id'])) {
                $productIds[] = (int)$it['product_id'];
            }
            if (!empty($it['product_code'])) {
                $productCodes[] = trim((string)$it['product_code']);
            }
        }
        $productIds = array_values(array_unique(array_filter($productIds)));
        $productCodes = array_values(array_unique(array_filter($productCodes)));

        $products = (!empty($productIds) || !empty($productCodes))
            ? Product::with(['variants:id,product_id', 'unitLinks'])
                ->where(function ($q) use ($productIds, $productCodes) {
                    if (!empty($productIds)) {
                        $q->whereIn('id', $productIds);
                    }
                    if (!empty($productCodes)) {
                        $q->orWhereIn('code', $productCodes)
                          ->orWhereIn('sku', $productCodes);
                    }
                })
                ->get()
            : collect();

        $byId = $products->keyBy('id');
        $byCode = [];
        foreach ($products as $p) {
            if (!empty($p->code)) {
                $byCode[trim((string)$p->code)] ??= $p;
            }
            if (!empty($p->sku)) {
                $byCode[trim((string)$p->sku)] ??= $p;
            }
        }

        $normalized = [];
        foreach ($items as $it) {
            $explicitPid = !empty($it['product_id']) ? (int)$it['product_id'] : null;
            $code = !empty($it['product_code']) ? trim((string)$it['product_code']) : null;

            $product = null;
            if ($explicitPid !== null) {
                $product = $byId->get($explicitPid);
                if (!$product) {
                    return ['items' => [], 'error' => "Product #{$explicitPid} was not found."];
                }
            } elseif ($code !== null && isset($byCode[$code])) {
                $product = $byCode[$code];
                $it['product_id'] = (int)$product->id;
            }

            $variantId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
            if ($variantId !== null && !$product) {
                return ['items' => [], 'error' => 'Selected variation requires a valid product.'];
            }

            if ($product) {
                if ($variantId !== null && !$product->variants->contains('id', $variantId)) {
                    $label = $it['product_name'] ?? $product->name ?? "Product #{$product->id}";
                    return ['items' => [], 'error' => "Selected variation does not belong to '{$label}'."];
                }
                if ($variantId === null && $product->has_variants && $product->variants->isNotEmpty()) {
                    $label = $it['product_name'] ?? $product->name ?? "Product #{$product->id}";
                    return ['items' => [], 'error' => "Please select a specific variation for '{$label}'."];
                }

                // Authoritative unit conversion rate resolution (ProductUnitLink -> base unit -> positive caller rate)
                $unitId = (isset($it['unit_id']) && $it['unit_id'] !== '' && $it['unit_id'] !== null) ? (int)$it['unit_id'] : null;
                $unitName = trim((string)($it['unit_name'] ?? ''));

                $resolvedRate = null;
                if ($unitId !== null || $unitName !== '') {
                    $matchingLink = $product->unitLinks
                        ->filter(function ($l) use ($variantId, $unitId, $unitName) {
                            if (!$l->is_active || (float)$l->conversion_rate <= 0) {
                                return false;
                            }
                            if ($variantId === null && $l->product_variant_id !== null) {
                                return false;
                            }
                            if ($variantId !== null && $l->product_variant_id !== null && (int)$l->product_variant_id !== $variantId) {
                                return false;
                            }
                            if ($unitId !== null && $l->unit_id !== null && (int)$l->unit_id === $unitId) {
                                return true;
                            }
                            if ($unitName !== '' && strcasecmp(trim((string)$l->unit_name), $unitName) === 0) {
                                return true;
                            }
                            return false;
                        })
                        ->sortBy(function ($l) use ($variantId) {
                            $variantRank = ($variantId !== null && (int)$l->product_variant_id === $variantId) ? 0 : 1;
                            $typeRank = strtolower((string)$l->unit_type) === 'purchase' ? 0 : 1;
                            return ($variantRank * 10) + $typeRank;
                        })
                        ->first();

                    if ($matchingLink) {
                        $resolvedRate = (float)$matchingLink->conversion_rate;
                        $it['unit_id'] = $matchingLink->unit_id;
                        if ($matchingLink->unit_name) {
                            $it['unit_name'] = $matchingLink->unit_name;
                        }
                    } elseif (
                        ($unitName !== '' && strcasecmp(trim((string)$product->unit_name), $unitName) === 0)
                        || ($unitId !== null && $product->base_unit_id !== null && $unitId === (int)$product->base_unit_id)
                    ) {
                        $resolvedRate = 1.0;
                    }
                }

                if ($resolvedRate === null) {
                    $callerRate = (float)($it['conversion_rate'] ?? 1.0);
                    if ($unitId === null && $unitName === '' && abs($callerRate - 1.0) <= 0.0001) {
                        $resolvedRate = 1.0;
                    } else {
                        $hasLinks = $product->relationLoaded('unitLinks')
                            ? $product->unitLinks->isNotEmpty()
                            : $product->unitLinks()->exists();
                        if ($hasLinks && abs($callerRate - 1.0) > 0.0001) {
                            return [
                                'items' => [],
                                'error' => "Unrecognized unit for product '{$product->name}'; arbitrary conversion_rate is not allowed.",
                            ];
                        }
                        $resolvedRate = $callerRate > 0.0001 ? $callerRate : 1.0;
                    }
                }
                $it['conversion_rate'] = $resolvedRate;
            } else {
                $callerRate = (float)($it['conversion_rate'] ?? 1.0);
                $it['conversion_rate'] = $callerRate > 0.0001 ? $callerRate : 1.0;
            }

            $qty = max(0.0, (float)($it['quantity'] ?? 0.0));
            $it['base_quantity'] = round($qty * (float)$it['conversion_rate'], 4);
            $normalized[] = $it;
        }

        return ['items' => $normalized, 'error' => null];
    }

    /**
     * Check if warehouse has sufficient stock for all return items before proceeding (aggregates duplicate lines & supports row locking).
     */
    private function checkWarehouseStock(int $warehouseId, array $items, bool $forUpdate = false): ?string
    {
        $requestedByKey = [];
        foreach ($items as $it) {
            $productId = (int)($it['product_id'] ?? 0);
            if ($productId <= 0 && !empty($it['product_code'])) {
                $productId = (int)(Product::where('code', trim((string)$it['product_code']))->value('id') ?? 0);
            }
            $variantId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
            $conversionRate = (float)($it['conversion_rate'] ?? 1.0);
            if ($conversionRate <= 0) {
                $conversionRate = 1.0;
            }
            $qty = (float)($it['quantity'] ?? 0.0);
            $baseQty = (float)($it['base_quantity'] ?? ($qty * $conversionRate));

            if ($productId <= 0 || $baseQty <= 0.0001) {
                continue;
            }

            $key = $productId . ':' . ($variantId ?? 0);
            if (!isset($requestedByKey[$key])) {
                $requestedByKey[$key] = [
                    'product_id'   => $productId,
                    'variant_id'   => $variantId,
                    'product_name' => $it['product_name'] ?? 'Product #' . $productId,
                    'base_qty'     => 0.0,
                ];
            }
            $requestedByKey[$key]['base_qty'] += $baseQty;
        }

        foreach ($requestedByKey as $entry) {
            $stockQuery = StockBalance::where('warehouse_id', $warehouseId)
                ->where('product_id', $entry['product_id']);
            if ($entry['variant_id'] !== null && $entry['variant_id'] > 0) {
                $stockQuery->where('product_variant_id', $entry['variant_id']);
            } else {
                $stockQuery->whereNull('product_variant_id');
            }
            if ($forUpdate) {
                $stockQuery->lockForUpdate();
            }
            $availableStock = (float)($stockQuery->value('quantity') ?? 0.0);

            if ($availableStock < ($entry['base_qty'] - 0.0001)) {
                return "Insufficient stock in warehouse for '{$entry['product_name']}'. Available: {$availableStock}, requested return: {$entry['base_qty']}.";
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
            $product   = $productId
                ? Product::lockForUpdate()->find($productId)
                : (!empty($it['product_code']) ? Product::lockForUpdate()->where('code', $it['product_code'])->first() : null);

            if ($product) {
                $conversionRate = (float)($it['conversion_rate'] ?? 1.0);
                $qty            = (float)($it['quantity'] ?? 0);
                $baseQty        = (float)($it['base_quantity'] ?? ($qty * $conversionRate));
                $unitCost       = (float)($it['unit_cost'] ?? $it['unit_price'] ?? $product->cost_price ?? $product->purchase_price ?? 0);
                $baseUnitCost   = $conversionRate > 0 ? ($unitCost / $conversionRate) : $unitCost;

                if ($baseQty > 0.0001) {
                    $layerCostRow = PurchaseReturnCostLayer::where('purchase_return_id', $return->id)
                        ->where('product_id', (int)$product->id)
                        ->when(
                            $variantId !== null && $variantId > 0,
                            fn($q) => $q->where('product_variant_id', $variantId),
                            fn($q) => $q->whereNull('product_variant_id')
                        )
                        ->selectRaw('COALESCE(SUM(total_cost), 0) as total_cost, COALESCE(SUM(base_quantity), 0) as base_qty')
                        ->first();
                    $voidUnitCost = ($layerCostRow && (float)$layerCostRow->base_qty > 0.0001)
                        ? round((float)$layerCostRow->total_cost / (float)$layerCostRow->base_qty, 4)
                        : round((float)$baseUnitCost, 4);

                    $unitService->applyStock(
                        $product,
                        (int)$warehouseId,
                        $variantId,
                        $baseQty,
                        'in',
                        'purchase_return_void',
                        'purchase_return',
                        (string)$return->id,
                        'Purchase return voided #' . $return->reference_no,
                        true,
                        false,
                        true,
                        $voidUnitCost,
                        (string)$return->date
                    );

                    $fifoService->restoreForPurchaseReturn(
                        warehouseId:       (int)$warehouseId,
                        productId:         (int)$product->id,
                        variantId:         $variantId,
                        baseQty:           (float)$baseQty,
                        returnReference:   (string)$return->reference_no,
                        purchaseReference: $return->purchase_reference_no,
                        fallbackCost:      (float)$baseUnitCost,
                        receivedDate:      (string)$return->date,
                        purchaseReturnId:  (int)$return->id
                    );
                }
            }
        }
        PurchaseReturnCostLayer::where('purchase_return_id', $return->id)->delete();
    }

    /**
     * Deduct stock and FIFO batches for a purchase return, recording exact return cost & variance.
     */
    private function deductReturnStockAndBatches(PurchaseReturn $return, array $items, int $warehouseId, ?string $purchaseRef): void
    {
        $unitService = app(ProductUnitConversionService::class);
        $fifoService = app(FifoInventoryService::class);
        $totalFifoReturnCost = 0.0;
        $lineTaxTotal = 0.0;

        foreach ($items as $it) {
            $lineTaxTotal += max(0.0, (float)($it['tax'] ?? 0));
            $productId = $it['product_id'] ?? null;
            $variantId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
            $product   = $productId ? Product::find($productId) : Product::where('code', $it['product_code'] ?? '')->first();

            if ($product) {
                $conversionRate = (float)($it['conversion_rate'] ?? 1.0);
                if ($conversionRate <= 0) $conversionRate = 1.0;
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
                        'Purchase return to supplier #' . $return->reference_no,
                        true,
                        false,
                        true,
                        null,
                        (string)$return->date
                    );

                    $layers = [];
                    $itemFifoCost = $fifoService->depleteForPurchaseReturn(
                        warehouseId:       (int)$warehouseId,
                        productId:         (int)$product->id,
                        variantId:         $variantId,
                        baseQty:           (float)$baseQty,
                        returnReference:   (string)$return->reference_no,
                        purchaseReference: $purchaseRef,
                        fallbackCost:      (float)$fallbackCost,
                        purchaseReturnId:  (int)$return->id,
                        recordedLayers:    $layers
                    );
                    if (\Illuminate\Support\Facades\Schema::hasColumn('stock_movements', 'unit_cost')) {
                        \App\Models\StockMovement::where('warehouse_id', (int)$warehouseId)
                            ->where('product_id', (int)$product->id)
                            ->when(
                                $variantId !== null && $variantId > 0,
                                fn($q) => $q->where('product_variant_id', $variantId),
                                fn($q) => $q->whereNull('product_variant_id')
                            )
                            ->where('movement_type', 'purchase_return')
                            ->where('reference_id', (string)$return->id)
                            ->orderByDesc('id')
                            ->limit(1)
                            ->update(['unit_cost' => round($itemFifoCost / $baseQty, 4)]);
                    }
                    $totalFifoReturnCost += $itemFifoCost;
                }
            }
        }

        $isRecoverable = $return->is_tax_recoverable !== null ? (bool)$return->is_tax_recoverable : false;
        $supplierCreditExclTax = $isRecoverable
            ? max(0.0, (float)$return->subtotal - $lineTaxTotal - (float)$return->discount + (float)$return->shipping)
            : (float)$return->grand_total;

        $return->update([
            'return_cost_total' => round($totalFifoReturnCost, 4),
            'cost_variance'     => round($supplierCreditExclTax - $totalFifoReturnCost, 4),
        ]);
    }

    /**
     * Apply linked purchase due amount offset and supplier balance due decrement.
     */
    private function normalizeFilterBoundaryDate(string $raw, bool $isEnd): string
    {
        return $this->accounting()->normalizeFilterBoundaryDate($raw, $isEnd);
    }

    private function normalizeTransactionDate(?string $raw, ?string $fallback = null): string
    {
        return $this->accounting()->normalizeTransactionDate($raw, $fallback);
    }

    private function isCreditNoteType(?string $paymentType): bool
    {
        return strtolower(trim((string)$paymentType)) === 'credit note';
    }

    /**
     * Authoritatively recompute return line subtotals, tax-exclusive order tax, discount, grand total, and payment status.
     */
    private function computeReturnFinancials(
        array $validated,
        array $items,
        ?PurchaseReturn $existing = null,
        string $effectiveStatus = 'Completed'
    ): array {
        $accounting = $this->accounting();
        $isTaxRecoverable = $accounting->resolveTaxRecoverability($validated, $existing);
        $discountAmount = (float)($validated['discount'] ?? ($existing ? $existing->discount : 0.0));
        $shipping = (float)($validated['shipping'] ?? ($existing ? $existing->shipping : 0.0));
        $oldTaxRate = $existing ? (float)$existing->tax_rate : 0.0;
        $taxRate = (float)($validated['tax_rate'] ?? $oldTaxRate);

        $explicitTax = null;
        if (array_key_exists('tax_amount', $validated) && $validated['tax_amount'] !== null) {
            $explicitTax = (float)$validated['tax_amount'];
        } elseif ($existing !== null && !array_key_exists('tax_rate', $validated) && $oldTaxRate <= 0.0001) {
            $explicitTax = (float)$existing->tax_amount;
        }

        $fallbackSubtotal = isset($validated['subtotal'])
            ? (float)$validated['subtotal']
            : ($existing ? (float)$existing->subtotal : null);
        $fallbackGrand = empty($items) && isset($validated['grand_total']) && (float)$validated['grand_total'] > 0
            ? (float)$validated['grand_total']
            : null;

        $calc = $accounting->calculateDocumentTotals(
            $items,
            $discountAmount,
            $taxRate,
            $explicitTax,
            $shipping,
            $isTaxRecoverable,
            allowSubtotalUnitCostFallback: true,
            fallbackHeaderSubtotal: $fallbackSubtotal,
            fallbackHeaderGrandTotal: $fallbackGrand
        );

        $grandTotal = $calc['grand_total'];

        $hasPaymentsArray = !empty($validated['payments']) && is_array($validated['payments']);
        $cashTenders = $hasPaymentsArray
            ? array_values(array_filter(
                $validated['payments'],
                fn($p) => !$this->isCreditNoteType($p['payment_type'] ?? null)
            ))
            : [];
        $hasCashTenders = !empty($cashTenders);
        $allTendersCreditNote = $hasPaymentsArray && !$hasCashTenders;

        $explicitPaidProvided = array_key_exists('paid_amount', $validated) && $validated['paid_amount'] !== null;
        $isPostedStatus = in_array($effectiveStatus, ['Completed', 'Received'], true);

        // Pending/draft returns and Credit Note offsets must NEVER record cash refund receipts.
        if (!$isPostedStatus || $allTendersCreditNote || (!$hasCashTenders && $this->isCreditNoteType($validated['payment_type'] ?? null))) {
            $rawPaid = 0.0;
        } elseif ($hasCashTenders) {
            $rawPaid = (float)collect($cashTenders)->sum(fn($p) => max(0.0, (float)($p['amount'] ?? 0)));
        } elseif ($explicitPaidProvided) {
            $rawPaid = (float)$validated['paid_amount'];
        } elseif (($validated['payment_status'] ?? null) === 'Unpaid') {
            $rawPaid = 0.0;
        } elseif ($existing !== null) {
            $rawPaid = (float)$existing->paid_amount;
        } else {
            $rawPaid = $grandTotal;
        }

        $paidAmount = round(min($grandTotal, max(0.0, $rawPaid)), 2);
        $dueAmount = round(max(0.0, $grandTotal - $paidAmount), 2);
        $statusHint = !$isPostedStatus
            ? 'Unpaid'
            : ($validated['payment_status'] ?? ($existing?->payment_status));
        $paymentStatus = $accounting->deriveReturnPaymentStatus(
            $grandTotal,
            $paidAmount,
            $dueAmount,
            $statusHint
        );

        return [
            'items' => $calc['items'],
            'subtotal' => $calc['subtotal'],
            'discount' => $calc['discount'],
            'tax_rate' => $calc['tax_rate'],
            'tax_amount' => $calc['tax_amount'],
            'is_tax_recoverable' => $isTaxRecoverable,
            'shipping' => $calc['shipping'],
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_status' => $paymentStatus,
        ];
    }

    /**
     * Legacy fallback & delta PO offset helper using PurchaseReturnAllocation aware calculation.
     */
    private function adjustLinkedPurchaseDueOffset(?string $purchaseRef, float $delta): void
    {
        if (empty($purchaseRef) || abs($delta) <= 0.001) {
            return;
        }

        $clean = trim($purchaseRef);
        $altHyphen = str_replace('_', '-', $clean);
        $altUnderscore = str_replace('-', '_', $clean);
        $linkedPurchase = Purchase::lockForUpdate()
            ->where(function ($q) use ($clean, $altHyphen, $altUnderscore) {
                $q->where('reference_no', $clean)
                  ->orWhere('reference_no', $altHyphen)
                  ->orWhere('reference_no', $altUnderscore);
            })
            ->first();

        if (!$linkedPurchase) {
            return;
        }

        $accounting = $this->accounting();
        $currentCredit = (float)$linkedPurchase->returned_credit_amount;
        $currentDue = (float)$linkedPurchase->due_amount;

        if ($delta < 0) {
            $offset = min($currentDue, abs($delta));
            if ($offset <= 0.001) {
                return;
            }
            $newCredit = round($currentCredit + $offset, 2);
            $newDue = round(max(0.0, (float)$linkedPurchase->grand_total - (float)$linkedPurchase->paid_amount - $newCredit), 2);
        } else {
            $activeAllocFloor = \Illuminate\Support\Facades\Schema::hasTable('purchase_return_allocations')
                ? round((float)PurchaseReturnAllocation::where('purchase_id', $linkedPurchase->id)
                    ->when(
                        \Illuminate\Support\Facades\Schema::hasColumn('purchase_return_allocations', 'released_at'),
                        fn($q) => $q->whereNull('released_at')
                    )
                    ->sum('amount'), 2)
                : 0.0;
            $newCredit = round(max($activeAllocFloor, max(0.0, $currentCredit - $delta)), 2);
            $maxDue = max(0.0, (float)$linkedPurchase->grand_total - (float)$linkedPurchase->paid_amount - $newCredit);
            $newDue = round(min($maxDue, $currentDue + $delta), 2);
            if (abs($newDue - $currentDue) <= 0.001 && abs($newCredit - $currentCredit) <= 0.001) {
                return;
            }
        }

        $newStatus = $accounting->derivePurchasePaymentStatus(
            (float)$linkedPurchase->grand_total,
            (float)$linkedPurchase->paid_amount,
            $newCredit,
            $newDue
        );

        $linkedPurchase->update([
            'returned_credit_amount' => $newCredit,
            'due_amount'             => $newDue,
            'payment_status'         => $newStatus,
        ]);
    }

    /**
     * Apply linked purchase due amount offset (tracked in purchase_return_allocations) and decrement Supplier AP.
     */
    private function applyLedgerAndPurchaseOffset(PurchaseReturn $return, float $grandTotal, float $dueAmount, ?string $purchaseRef, ?int $supplierId): void
    {
        if ($dueAmount > 0.001 && !empty($purchaseRef)) {
            $clean = trim($purchaseRef);
            $altHyphen = str_replace('_', '-', $clean);
            $altUnderscore = str_replace('-', '_', $clean);
            $linkedPurchase = Purchase::lockForUpdate()
                ->where(function ($q) use ($clean, $altHyphen, $altUnderscore) {
                    $q->where('reference_no', $clean)
                      ->orWhere('reference_no', $altHyphen)
                      ->orWhere('reference_no', $altUnderscore);
                })
                ->first();

            if ($linkedPurchase) {
                $offsetAmt = round(min((float)$linkedPurchase->due_amount, $dueAmount), 2);
                if ($offsetAmt > 0.001) {
                    $hasReleasedCol = \Illuminate\Support\Facades\Schema::hasColumn('purchase_return_allocations', 'released_at');
                    $activeAllocQ = PurchaseReturnAllocation::where('purchase_return_id', $return->id)
                        ->where('purchase_id', $linkedPurchase->id)
                        ->where('allocation_type', 'credit_note_offset')
                        ->lockForUpdate();
                    if ($hasReleasedCol) {
                        $activeAllocQ->whereNull('released_at');
                    }
                    $activeAlloc = $activeAllocQ->first();
                    if ($activeAlloc) {
                        $activeAlloc->update([
                            'supplier_id' => $supplierId ?? $linkedPurchase->supplier_id,
                            'amount'      => $offsetAmt,
                        ]);
                    } else {
                        PurchaseReturnAllocation::create([
                            'purchase_return_id' => $return->id,
                            'purchase_id'        => $linkedPurchase->id,
                            'allocation_type'    => 'credit_note_offset',
                            'supplier_id'        => $supplierId ?? $linkedPurchase->supplier_id,
                            'amount'             => $offsetAmt,
                        ]);
                    }

                    $newCredit = round((float)$linkedPurchase->returned_credit_amount + $offsetAmt, 2);
                    $newDue = round(max(0.0, (float)$linkedPurchase->grand_total - (float)$linkedPurchase->paid_amount - $newCredit), 2);
                    $newStatus = $this->accounting()->derivePurchasePaymentStatus(
                        (float)$linkedPurchase->grand_total,
                        (float)$linkedPurchase->paid_amount,
                        $newCredit,
                        $newDue
                    );
                    $linkedPurchase->update([
                        'returned_credit_amount' => $newCredit,
                        'due_amount'             => $newDue,
                        'payment_status'         => $newStatus,
                    ]);
                }
            }
        }

        if ($supplierId && $dueAmount > 0.001) {
            $supplier = Supplier::where('id', $supplierId)->lockForUpdate()->first();
            if ($supplier) {
                $supplier->update(['balance_due' => round((float)$supplier->balance_due - $dueAmount, 2)]);
            }
        }
    }

    /**
     * Revert linked purchase credit-note allocation and restore Supplier balance_due atomically.
     */
    private function revertLedgerAndPurchaseOffset(PurchaseReturn $return): void
    {
        $creditNoteDue = (float)$return->due_amount;
        $hasReleasedCol = \Illuminate\Support\Facades\Schema::hasColumn('purchase_return_allocations', 'released_at');
        $allocQ = PurchaseReturnAllocation::where('purchase_return_id', $return->id)
            ->lockForUpdate();
        if ($hasReleasedCol) {
            $allocQ->whereNull('released_at');
        }
        $allocations = $allocQ->get();

        if ($allocations->isNotEmpty()) {
            foreach ($allocations as $alloc) {
                $linkedPurchase = Purchase::lockForUpdate()->find($alloc->purchase_id);
                if ($linkedPurchase) {
                    $newCredit = round(max(0.0, (float)$linkedPurchase->returned_credit_amount - (float)$alloc->amount), 2);
                    $newDue = round(max(0.0, (float)$linkedPurchase->grand_total - (float)$linkedPurchase->paid_amount - $newCredit), 2);
                    $newStatus = $this->accounting()->derivePurchasePaymentStatus(
                        (float)$linkedPurchase->grand_total,
                        (float)$linkedPurchase->paid_amount,
                        $newCredit,
                        $newDue
                    );
                    $linkedPurchase->update([
                        'returned_credit_amount' => $newCredit,
                        'due_amount'             => $newDue,
                        'payment_status'         => $newStatus,
                    ]);
                }
                $alloc->delete();
            }
        } elseif ($creditNoteDue > 0.001) {
            $hadAnyAllocations = PurchaseReturnAllocation::where('purchase_return_id', $return->id)->exists();
            if (!$hadAnyAllocations) {
                $this->adjustLinkedPurchaseDueOffset($return->purchase_reference_no, $creditNoteDue);
            }
        }

        if ($return->supplier_id && $creditNoteDue > 0.001) {
            $supplier = Supplier::where('id', $return->supplier_id)->lockForUpdate()->first();
            if ($supplier) {
                $supplier->update(['balance_due' => round((float)$supplier->balance_due + $creditNoteDue, 2)]);
            }
        }
    }

    /**
     * Build a normalized payments_payload array synchronized with $paidAmount.
     * Returns null when existing payments_payload can remain untouched on update.
     */
    private function buildPaymentsPayload(
        array $validated,
        PurchaseReturn $return,
        float $paidAmount,
        string $txDate,
        string $userName,
        string $userRole,
        bool $isUpdate = false,
        ?float $priorPaidAmount = null,
        ?int $cashRegisterId = null
    ): ?array {
        $accounting = $this->accounting();
        $cleanRef = preg_replace('/^#\s*/', '', trim((string)$return->reference_no));
        $defaultPayType = $validated['payment_type'] ?? ($return->payment_type ?: 'Cash');

        if ($paidAmount <= 0.001) {
            return $isUpdate ? [] : null;
        }

        if (!empty($validated['payments']) && is_array($validated['payments'])) {
            $savedPayments = [];
            $remainingBudget = round(max(0.0, $paidAmount), 2);
            $tenderIdx = 1;
            foreach ($validated['payments'] as $p) {
                $tenderType = $p['payment_type'] ?? $defaultPayType;
                if ($this->isCreditNoteType((string)$tenderType)) {
                    continue;
                }
                $pAmount = round((float)($p['amount'] ?? 0), 2);
                if ($pAmount <= 0.0001 || $remainingBudget <= 0.0001) {
                    continue;
                }
                $appliedAmt = round(min($remainingBudget, $pAmount), 2);
                $receivedAmt = isset($p['received_amount']) && (float)$p['received_amount'] > 0
                    ? round(max($appliedAmt, (float)$p['received_amount']), 2)
                    : round(max($appliedAmt, $pAmount), 2);
                $changeAmt = isset($p['change_amount'])
                    ? round(max($receivedAmt - $appliedAmt, max(0.0, (float)$p['change_amount'])), 2)
                    : round(max(0.0, $receivedAmt - $appliedAmt), 2);
                $paymentRef = !empty($p['reference_no'])
                    ? (string)$p['reference_no']
                    : ('PAY-' . ($cleanRef ?: $return->id) . '-' . time() . '-' . $tenderIdx . '-' . rand(100, 999));
                $rawPayDate = !empty($p['payment_date']) ? $p['payment_date'] : (!empty($validated['date']) ? $validated['date'] : $txDate);
                $isCashTender = $accounting->isCashPaymentType($tenderType);
                $tenderRegisterId = !empty($p['cash_register_id'])
                    ? (int)$p['cash_register_id']
                    : ($isCashTender ? $cashRegisterId : null);

                $savedPayments[] = [
                    'id'               => $tenderIdx,
                    'reference_no'     => $paymentRef,
                    'payment_date'     => $this->normalizeTransactionDate($rawPayDate, $txDate),
                    'payment_type'     => $tenderType,
                    'amount'           => $appliedAmt,
                    'received_amount'  => $receivedAmt,
                    'change_amount'    => $changeAmt,
                    'cash_register_id' => $tenderRegisterId,
                    'user_name'        => $userName,
                    'user_role'        => $userRole,
                    'notes'            => $p['notes'] ?? ($defaultPayType === 'Split Payment' ? 'Split refund line' : ($validated['notes'] ?? '')),
                ];
                $remainingBudget = round(max(0.0, $remainingBudget - $appliedAmt), 2);
                $tenderIdx++;
            }
            return $savedPayments;
        }

        if ($isUpdate) {
            if (array_key_exists('payments', $validated) || $paidAmount <= 0.001) {
                return [];
            }

            $existingPayments = $return->payments;
            $existingSum = round((float)collect($existingPayments)->sum('amount'), 2);
            $paidChanged = $priorPaidAmount !== null && abs($paidAmount - $priorPaidAmount) > 0.005;
            $payloadOutOfSync = abs($existingSum - $paidAmount) > 0.01;
            $payTypeChanged = isset($validated['payment_type']) && $validated['payment_type'] !== $return->payment_type;

            if (!empty($existingPayments) && !$paidChanged && !$payloadOutOfSync && !$payTypeChanged) {
                return null;
            }

            if (!empty($existingPayments)) {
                $scaledPayments = [];
                $remainingBudget = round(max(0.0, $paidAmount), 2);
                $tenderIdx = 1;
                foreach ($existingPayments as $exPay) {
                    if ($remainingBudget <= 0.001) {
                        break;
                    }
                    $origAmt = round(max(0.0, (float)($exPay['amount'] ?? 0.0)), 2);
                    if ($origAmt <= 0.0001) {
                        continue;
                    }
                    $appliedAmt = round(min($remainingBudget, $origAmt), 2);
                    $exPay['id'] = $exPay['id'] ?? $tenderIdx;
                    $exPay['amount'] = $appliedAmt;
                    if (isset($exPay['received_amount']) && (float)$exPay['received_amount'] < $appliedAmt) {
                        $exPay['received_amount'] = $appliedAmt;
                    }
                    $scaledPayments[] = $exPay;
                    $remainingBudget = round(max(0.0, $remainingBudget - $appliedAmt), 2);
                    $tenderIdx++;
                }
                if ($remainingBudget > 0.005) {
                    $fallbackMethod = ($defaultPayType === 'Split Payment' || $this->isCreditNoteType((string)$defaultPayType))
                        ? 'Cash'
                        : $defaultPayType;
                    $isCashFallback = $accounting->isCashPaymentType($fallbackMethod);
                    $scaledPayments[] = [
                        'id'               => $accounting->nextPaymentId($scaledPayments),
                        'reference_no'     => 'PAY-' . ($cleanRef ?: $return->id) . '-' . time() . '-' . rand(100, 999),
                        'payment_date'     => $txDate,
                        'payment_type'     => $fallbackMethod,
                        'amount'           => $remainingBudget,
                        'received_amount'  => $remainingBudget,
                        'change_amount'    => 0.0,
                        'cash_register_id' => $isCashFallback ? $cashRegisterId : null,
                        'user_name'        => $userName,
                        'user_role'        => $userRole,
                        'notes'            => $validated['notes'] ?? ($return->notes ?? ''),
                    ];
                }
                return $scaledPayments;
            }
        }

        $singlePayType = ($defaultPayType === 'Split Payment' || $this->isCreditNoteType((string)$defaultPayType))
            ? 'Cash'
            : $defaultPayType;
        $isCashSingle = $accounting->isCashPaymentType($singlePayType);
        $paymentRef = 'PAY-' . ($cleanRef ?: $return->id) . '-' . time() . '-' . rand(100, 999);
        return [[
            'id'               => $accounting->nextPaymentId($isUpdate ? $return->payments : []),
            'reference_no'     => $paymentRef,
            'payment_date'     => $txDate,
            'payment_type'     => $singlePayType,
            'amount'           => $paidAmount,
            'received_amount'  => $paidAmount,
            'change_amount'    => 0.0,
            'cash_register_id' => $isCashSingle ? $cashRegisterId : null,
            'user_name'        => $userName,
            'user_role'        => $userRole,
            'notes'            => $validated['notes'] ?? ($return->notes ?? ''),
        ]];
    }

    public function store(Request $request)
    {
        $accounting = $this->accounting();

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
            'tax_rate'              => 'nullable|numeric|min:0|max:100',
            'tax_amount'            => 'nullable|numeric|min:0',
            'is_tax_recoverable'    => 'nullable|boolean',
            'discount'              => 'nullable|numeric|min:0',
            'shipping'              => 'nullable|numeric|min:0',
            'cash_register_id'      => 'nullable|integer',
            'notes'                 => 'nullable|string',
            'return_reason'         => 'nullable|string|max:255',
            'items'                 => 'nullable|array|min:1',
            'payments'              => 'nullable|array',
            'payments.*.amount'     => 'nullable|numeric|min:0.01',
            'payments.*.payment_type' => 'nullable|string',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount' => 'nullable|numeric|min:0',
            'payments.*.payment_date' => 'nullable|string',
            'payments.*.notes'      => 'nullable|string',
            'payments.*.cash_register_id' => 'nullable|integer',
            'created_by'            => 'nullable|string|max:255',
            'user_id'               => 'nullable|integer',
            'user_name'             => 'nullable|string|max:255',
            'user_role'             => 'nullable|string|max:255',
        ]);

        // Normalize status: Completed and Received both mean goods are returned
        $statusInput = $validated['status'] ?? 'Completed';
        $returnStatus = ($statusInput === 'Received' || $statusInput === 'Completed') ? 'Completed' : 'Pending';
        $items = $validated['items'] ?? [];
        if ($returnStatus === 'Completed' && (empty($items) || !is_array($items))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot finalize a Completed purchase return without at least one item.',
                'errors'  => ['items' => ['At least one return item is required for Completed purchase returns.']],
            ], 422);
        }

        if (!empty($items) && is_array($items)) {
            $normalizedResult = $this->normalizeAndValidateReturnItems($items);
            if ($normalizedResult['error'] !== null) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $normalizedResult['error'],
                ], 422);
            }
            $items = $normalizedResult['items'];
        }

        $warehouseId = $validated['warehouse_id'] ?? $validated['outlet_id'] ?? null;
        $outletId    = $validated['outlet_id'] ?? $validated['warehouse_id'] ?? null;
        if (!$warehouseId && !empty($validated['warehouse_name'])) {
            $unitService = app(ProductUnitConversionService::class);
            $warehouseId = $unitService->warehouseId(null, $validated['warehouse_name']);
            $outletId    = $outletId ?? $warehouseId;
        }
        $accounting->assertOutletAccess(
            $request,
            $warehouseId ? (int)$warehouseId : null,
            $validated['warehouse_name']
        );

        $canViewCost = $accounting->canViewCost($request);
        $canPay = $accounting->canRecordPayment($request, 'purchase_returns.pay');
        $financialValidated = $validated;
        if (!$canViewCost) {
            $linkedPurchaseMeta = null;
            if (!empty($items) && is_array($items)) {
                $costCheck = $accounting->enforceCostBlindStoreItems($items, $validated['purchase_reference_no'] ?? null);
                if ($costCheck['error'] !== null) {
                    return response()->json(['status' => 'error', 'message' => $costCheck['error']], 422);
                }
                $items = $costCheck['items'];
                $linkedPurchaseMeta = $costCheck['linked_purchase'] ?? null;
            }
            unset($financialValidated['discount'], $financialValidated['shipping'], $financialValidated['tax_rate'], $financialValidated['tax_amount'], $financialValidated['is_tax_recoverable'], $financialValidated['subtotal'], $financialValidated['grand_total']);
            if (is_array($linkedPurchaseMeta)) {
                $financialValidated['is_tax_recoverable'] = $linkedPurchaseMeta['is_tax_recoverable'];
                $financialValidated['tax_rate'] = $linkedPurchaseMeta['tax_rate'];
                if ($linkedPurchaseMeta['tax_amount'] !== null) {
                    $financialValidated['tax_amount'] = $linkedPurchaseMeta['tax_amount'];
                }
                if ($linkedPurchaseMeta['discount'] > 0) {
                    $financialValidated['discount'] = $linkedPurchaseMeta['discount'];
                }
                if (!empty($linkedPurchaseMeta['shipping']) && $linkedPurchaseMeta['shipping'] > 0) {
                    $financialValidated['shipping'] = $linkedPurchaseMeta['shipping'];
                }
            }
            if (!$canPay) {
                $financialValidated['paid_amount'] = 0.0;
                $financialValidated['payments'] = [];
            }
        }

        // Compute authoritative financial totals (tax-exclusive tax base, Pending/Credit Note default 0 paid)
        $financials = $this->computeReturnFinancials($financialValidated, $items, null, $returnStatus);
        $items = $financials['items'];
        $itemsSubtotal = $financials['subtotal'];
        $discountAmount = $financials['discount'];
        $taxRate = $financials['tax_rate'];
        $taxAmount = $financials['tax_amount'];
        $isTaxRecoverable = $financials['is_tax_recoverable'];
        $shipping = $financials['shipping'];
        $grandTotal = $financials['grand_total'];
        $paidAmount = $financials['paid_amount'];
        $dueAmount = $financials['due_amount'];
        $paymentStatus = $financials['payment_status'];

        $actor = $accounting->resolveActor($request, $validated);
        $userName = $actor['user_name'];
        $userRole = $actor['user_role'];
        $createdBy = $actor['created_by'];
        $userId = $actor['user_id'];

        $isPostedReturn = in_array($returnStatus, ['Completed', 'Received'], true);

        if (!$isPostedReturn) {
            $effectivePayType = 'N/A';
            $cashRegisterId = null;
        } else {
            if ($paidAmount <= 0.001) {
                $rawReqPayType = $validated['payment_type'] ?? null;
                $effectivePayType = (!$canViewCost || empty($rawReqPayType) || strcasecmp(trim((string)$rawReqPayType), 'Cash') === 0)
                    ? ((!empty($validated['purchase_reference_no']) || !empty($validated['supplier_id']) || !empty($validated['supplier_name'])) ? 'Credit Note' : ($rawReqPayType ?: 'Credit Note'))
                    : $rawReqPayType;
            } else {
                $effectivePayType = $validated['payment_type'] ?? 'Cash';
            }
            if (!empty($financialValidated['payments']) && is_array($financialValidated['payments'])) {
                $nonCreditLegs = array_values(array_filter(
                    $financialValidated['payments'],
                    fn($p) => is_array($p) && !$this->isCreditNoteType((string)($p['payment_type'] ?? ''))
                ));
                if (!empty($nonCreditLegs)) {
                    $splitRegErr = $accounting->validateSplitTenderCashRegisters($nonCreditLegs);
                    if ($splitRegErr !== null) {
                        return response()->json(['status' => 'error', 'message' => $splitRegErr], 422);
                    }
                }
                $cashTypes = collect($financialValidated['payments'])
                    ->filter(fn($p) => !$this->isCreditNoteType($p['payment_type'] ?? null) && (float)($p['amount'] ?? 0) > 0)
                    ->pluck('payment_type')
                    ->filter()
                    ->unique();
                $allTypes = collect($financialValidated['payments'])->pluck('payment_type')->filter()->unique();
                if ($paidAmount > 0.001 && $cashTypes->isNotEmpty()) {
                    $effectivePayType = $cashTypes->count() > 1 ? 'Split Payment' : (string)$cashTypes->first();
                } elseif ($allTypes->count() === 1) {
                    $effectivePayType = (string)$allTypes->first();
                }
            }

            $probePayType = (!empty($financialValidated['payments']) && is_array($financialValidated['payments']))
                ? (collect($financialValidated['payments'])->contains(fn($p) => !$this->isCreditNoteType($p['payment_type'] ?? null) && $accounting->isCashPaymentType($p['payment_type'] ?? null))
                    ? 'Cash'
                    : (string)($financialValidated['payments'][0]['payment_type'] ?? 'Bank Transfer'))
                : $effectivePayType;

            $explicitRegisterId = !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null;
            $registerCheck = $accounting->resolveCashRegisterId(
                $explicitRegisterId,
                $actor['user'],
                $warehouseId ? (int)$warehouseId : null,
                $probePayType,
                $paidAmount
            );
            if ($registerCheck['error']) {
                return response()->json(['status' => 'error', 'message' => $registerCheck['error']], 422);
            }
            $cashRegisterId = $registerCheck['cash_register_id'];
        }

        $purchaseRef = $validated['purchase_reference_no'] ?? null;
        $txDate = $this->normalizeTransactionDate($validated['date'] ?? null);

        // Notes: Only append Credit Note if payment_type is Credit Note or due_amount > 0
        $combinedNotes = trim($validated['notes'] ?? '');
        if ($effectivePayType === 'Credit Note' || $dueAmount > 0.01) {
            $creditNoteNote = "Credit Note: Returned to {$validated['supplier_name']} on " . now()->toDateString();
            $combinedNotes  = trim($combinedNotes . "\n" . $creditNoteNote);
        }

        $itemsPayload = !empty($items) ? json_encode($items) : null;

        DB::beginTransaction();
        try {
            $resolvedSupplierId = !empty($validated['supplier_id']) ? (int)$validated['supplier_id'] : null;
            if (!$resolvedSupplierId && !empty($purchaseRef)) {
                $cleanPo = trim((string)$purchaseRef);
                $linkedPo = Purchase::lockForUpdate()
                    ->where(function ($q) use ($cleanPo) {
                        $q->where('reference_no', $cleanPo)
                          ->orWhere('reference_no', str_replace('_', '-', $cleanPo))
                          ->orWhere('reference_no', str_replace('-', '_', $cleanPo));
                    })
                    ->first();
                if ($linkedPo && $linkedPo->supplier_id) {
                    $resolvedSupplierId = (int)$linkedPo->supplier_id;
                }
            }
            if (!$resolvedSupplierId && !empty($validated['supplier_name'])) {
                $matchedSupplierId = Supplier::where('name', trim((string)$validated['supplier_name']))->value('id');
                if ($matchedSupplierId) {
                    $resolvedSupplierId = (int)$matchedSupplierId;
                }
            }

            // 1. Atomic cumulative Over-Return & PO Status/Supplier/Warehouse/SKU validation inside transaction lock
            if (!empty($items) && is_array($items)) {
                $overReturnError = $this->validateOverReturn(
                    $purchaseRef,
                    $items,
                    null,
                    $resolvedSupplierId,
                    $warehouseId ? (int)$warehouseId : null
                );
                if ($overReturnError) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $overReturnError], 422);
                }
            }

            // 2. Atomic locked warehouse stock check if status is Completed
            if ($returnStatus === 'Completed' && !empty($items) && is_array($items) && $warehouseId) {
                $stockError = $this->checkWarehouseStock((int)$warehouseId, $items, true);
                if ($stockError) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $stockError], 422);
                }
            }

            // Acquire sequential voucher number atomically inside transaction after validation/stock checks pass
            $refNo = $this->acquireUniqueReference('purchase_return', $validated['reference_no'] ?? null);

            $return = PurchaseReturn::create([
                'reference_no'          => $refNo,
                'purchase_reference_no' => $purchaseRef,
                'supplier_id'           => $resolvedSupplierId,
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
                'payment_type'          => $effectivePayType,
                'subtotal'              => $itemsSubtotal,
                'tax_rate'              => $taxRate,
                'tax_amount'            => $taxAmount,
                'is_tax_recoverable'    => $isTaxRecoverable,
                'discount'              => $discountAmount,
                'shipping'              => $shipping,
                'cash_register_id'      => $cashRegisterId,
                'notes'                 => $combinedNotes,
                'return_reason'         => $validated['return_reason'] ?? null,
                'items_payload'         => $itemsPayload,
                'created_by'            => $createdBy,
                'user_id'               => $userId,
                'user_name'             => $userName,
                'user_role'             => $userRole,
            ]);

            // Deduct stock and FIFO batches & apply tracked PO offset/AP ledger if Completed
            if ($returnStatus === 'Completed') {
                if (!empty($items) && $warehouseId) {
                    $this->deductReturnStockAndBatches($return, $items, (int)$warehouseId, $purchaseRef);
                }
                $this->applyLedgerAndPurchaseOffset($return, $grandTotal, $dueAmount, $purchaseRef, $resolvedSupplierId);
            }

            // Record initial refund payment transaction(s) strictly for posted (Completed/Received) returns
            if ($isPostedReturn && ($paidAmount > 0.001 || !empty($financialValidated['payments']))) {
                $savedPayments = $this->buildPaymentsPayload(
                    $financialValidated,
                    $return,
                    $paidAmount,
                    $txDate,
                    $userName,
                    $userRole,
                    isUpdate: false,
                    priorPaidAmount: null,
                    cashRegisterId: $cashRegisterId
                );
                if ($savedPayments !== null && !empty($savedPayments)) {
                    $return->update(['payments_payload' => json_encode($savedPayments)]);
                }
            }

            DB::commit();
            $this->clearPurchaseReturnCache();

            $fresh = $return->fresh();
            $accounting->maskPurchaseReturnCostFields($fresh, $accounting->canViewCost($request));

            return response()->json([
                'status'  => 'success',
                'message' => 'Purchase return recorded successfully',
                'data'    => $fresh,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('PurchaseReturn store error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $accounting = $this->accounting();

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
            'tax_rate'              => 'nullable|numeric|min:0|max:100',
            'tax_amount'            => 'nullable|numeric|min:0',
            'is_tax_recoverable'    => 'nullable|boolean',
            'discount'              => 'nullable|numeric|min:0',
            'shipping'              => 'nullable|numeric|min:0',
            'cash_register_id'      => 'nullable|integer',
            'notes'                 => 'nullable|string',
            'return_reason'         => 'nullable|string|max:255',
            'items'                 => 'nullable|array|min:1',
            'payments'              => 'nullable|array',
            'payments.*.amount'     => 'nullable|numeric|min:0.01',
            'payments.*.payment_type' => 'nullable|string',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount' => 'nullable|numeric|min:0',
            'payments.*.payment_date' => 'nullable|string',
            'payments.*.notes'      => 'nullable|string',
            'payments.*.cash_register_id' => 'nullable|integer',
            'created_by'            => 'nullable|string|max:255',
            'user_id'               => 'nullable|integer',
            'user_name'             => 'nullable|string|max:255',
            'user_role'             => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            // Lock record inside transaction to prevent concurrent double-revert of stock/AP/PO offsets
            $return = $this->findReturnForUpdate($id);
            if (!$return) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
            }
            $accounting->assertOutletAccess(
                $request,
                $return->warehouse_id ? (int)$return->warehouse_id : ($return->outlet_id ? (int)$return->outlet_id : null),
                $return->warehouse_name
            );

            $newPurchaseRef = array_key_exists('purchase_reference_no', $validated)
                ? $validated['purchase_reference_no']
                : $return->purchase_reference_no;
            $newWarehouseId = $validated['warehouse_id'] ?? $validated['outlet_id'] ?? $return->warehouse_id;
            if ((!$newWarehouseId || isset($validated['warehouse_name'])) && empty($validated['warehouse_id']) && empty($validated['outlet_id']) && !empty($validated['warehouse_name'])) {
                $unitService = app(ProductUnitConversionService::class);
                $newWarehouseId = $unitService->warehouseId(null, $validated['warehouse_name']) ?? $newWarehouseId;
            }
            $accounting->assertOutletAccess(
                $request,
                $newWarehouseId ? (int)$newWarehouseId : null,
                $validated['warehouse_name'] ?? $return->warehouse_name
            );
            $newSupplierId = array_key_exists('supplier_id', $validated)
                ? (!empty($validated['supplier_id']) ? (int)$validated['supplier_id'] : null)
                : ($return->supplier_id ? (int)$return->supplier_id : null);

            if (!$newSupplierId && !empty($newPurchaseRef)) {
                $cleanPo = trim((string)$newPurchaseRef);
                $linkedPo = Purchase::lockForUpdate()
                    ->where(function ($q) use ($cleanPo) {
                        $q->where('reference_no', $cleanPo)
                          ->orWhere('reference_no', str_replace('_', '-', $cleanPo))
                          ->orWhere('reference_no', str_replace('-', '_', $cleanPo));
                    })
                    ->first();
                if ($linkedPo && $linkedPo->supplier_id) {
                    $newSupplierId = (int)$linkedPo->supplier_id;
                }
            }
            if (!$newSupplierId && !empty($validated['supplier_name'])) {
                $matchedSupplierId = Supplier::where('name', trim((string)$validated['supplier_name']))->value('id');
                if ($matchedSupplierId) {
                    $newSupplierId = (int)$matchedSupplierId;
                }
            }

            $oldItemsSig = collect(is_array($return->items) ? $return->items : [])->map(function ($it) {
                $qty = round((float)($it['quantity'] ?? 0), 4);
                $rate = round((float)($it['conversion_rate'] ?? 1.0), 6);
                $baseQty = round((float)($it['base_quantity'] ?? ($qty * $rate)), 4);
                return ((int)($it['product_id'] ?? 0)) . '_' . ((int)($it['product_variant_id'] ?? 0)) . '_' . strtolower(trim((string)($it['product_code'] ?? ''))) . '_' . $baseQty;
            })->values()->all();
            $oldWarehouseIdForDiff = $return->warehouse_id ? (int)$return->warehouse_id : null;
            $oldPurchaseRefForDiff = trim((string)($return->purchase_reference_no ?? ''));
            $oldDueForDiff = (float)$return->due_amount;
            $oldSupplierIdForDiff = $return->supplier_id ? (int)$return->supplier_id : null;

            $statusInput = $validated['status'] ?? $return->status;
            $newStatus = ($statusInput === 'Received' || $statusInput === 'Completed') ? 'Completed' : 'Pending';
            $oldWasCompleted = in_array($return->status, ['Completed', 'Received'], true);

            $newItems = array_key_exists('items', $validated) ? ($validated['items'] ?? []) : $return->items;
            if ((!$oldWasCompleted || array_key_exists('items', $validated)) && $newStatus === 'Completed' && (empty($newItems) || !is_array($newItems))) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot finalize a Completed purchase return without at least one item.',
                    'errors'  => ['items' => ['At least one return item is required for Completed purchase returns.']],
                ], 422);
            }

            if ((array_key_exists('items', $validated) || (!$oldWasCompleted && $newStatus === 'Completed')) && !empty($newItems) && is_array($newItems)) {
                $normalizedResult = $this->normalizeAndValidateReturnItems($newItems);
                if ($normalizedResult['error'] !== null) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => $normalizedResult['error'],
                    ], 422);
                }
                $newItems = $normalizedResult['items'];
            }

            // Preserve historical item costs, taxes & discounts if caller lacks products.view_cost & block new unpriced items
            $canViewCost = $accounting->canViewCost($request);
            if (array_key_exists('items', $validated) && !empty($newItems) && is_array($newItems) && !$canViewCost) {
                $preserved = $accounting->applyCostBlindItemPreservation($newItems, (array)$return->items, true);
                if ($preserved['error'] !== null) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => $preserved['error'],
                    ], 422);
                }
                $newItems = $preserved['items'];
            }

            // Check cumulative over-return & PO constraints (ignoring this return's own prior items) inside lock
            if (!empty($newItems) && is_array($newItems)) {
                $overReturnError = $this->validateOverReturn(
                    $newPurchaseRef,
                    $newItems,
                    (int)$return->id,
                    $newSupplierId,
                    $newWarehouseId ? (int)$newWarehouseId : null
                );
                if ($overReturnError) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $overReturnError], 422);
                }
            }

            // Calculate updated financial totals (preserve masked header charges & refund payments when caller is cost-blind)
            $financialValidated = $canViewCost
                ? $validated
                : collect($validated)->except([
                    'discount', 'shipping', 'tax_rate', 'tax_amount', 'is_tax_recoverable',
                    'subtotal', 'grand_total', 'paid_amount', 'payment_status', 'payment_type', 'payments',
                ])->all();
            $financials = $this->computeReturnFinancials($financialValidated, is_array($newItems) ? $newItems : [], $return, $newStatus);
            $newItems = !empty($financials['items']) ? $financials['items'] : $newItems;
            $itemsSubtotal = $financials['subtotal'];
            $discountAmount = $financials['discount'];
            $taxRate = $financials['tax_rate'];
            $taxAmount = $financials['tax_amount'];
            $isTaxRecoverable = $financials['is_tax_recoverable'];
            $shipping = $financials['shipping'];
            $grandTotal = $financials['grand_total'];
            $paidAmount = $financials['paid_amount'];
            $dueAmount = $financials['due_amount'];
            $paymentStatus = $financials['payment_status'];

            $actor = $accounting->resolveActorForUpdate($request, $validated, $return);
            $userName = $actor['user_name'];
            $userRole = $actor['user_role'];
            $createdBy = $actor['created_by'];

            $beforeCashByReg = $accounting->extractDocumentCashByRegister($return, ['Completed', 'Received']);
            $isPostedNewStatus = in_array($newStatus, ['Completed', 'Received'], true);
            $cashRegisterId = null;
            $newCashRegisterId = null;
            if ($isPostedNewStatus) {
                if (!empty($financialValidated['payments']) && is_array($financialValidated['payments'])) {
                    $nonCreditLegs = array_values(array_filter(
                        $financialValidated['payments'],
                        fn($p) => is_array($p) && !$this->isCreditNoteType((string)($p['payment_type'] ?? ''))
                    ));
                    if (!empty($nonCreditLegs)) {
                        $splitRegErr = $accounting->validateSplitTenderCashRegisters(
                            $nonCreditLegs,
                            is_array($return->payments) ? $return->payments : []
                        );
                        if ($splitRegErr !== null) {
                            DB::rollBack();
                            return response()->json(['status' => 'error', 'message' => $splitRegErr], 422);
                        }
                    }
                }

                $existingHeaderRegOpen = false;
                if (!empty($return->cash_register_id) && \Illuminate\Support\Facades\Schema::hasTable('cash_registers')) {
                    $existingReg = \App\Models\CashRegister::find((int)$return->cash_register_id);
                    $existingHeaderRegOpen = $existingReg && in_array(strtolower((string)$existingReg->status), ['open', 'opened'], true);
                }

                $rawExplicitRegId = !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null;
                $isEchoedClosedHeader = $rawExplicitRegId !== null
                    && !empty($return->cash_register_id)
                    && $rawExplicitRegId === (int)$return->cash_register_id
                    && !$existingHeaderRegOpen;
                $explicitRegisterId = $isEchoedClosedHeader ? null : $rawExplicitRegId;

                $probePayType = (!empty($financialValidated['payments']) && is_array($financialValidated['payments']))
                    ? (collect($financialValidated['payments'])->contains(fn($p) => !$this->isCreditNoteType($p['payment_type'] ?? null) && $accounting->isCashPaymentType($p['payment_type'] ?? null))
                        ? 'Cash'
                        : (string)($financialValidated['payments'][0]['payment_type'] ?? 'Bank Transfer'))
                    : ($financialValidated['payment_type'] ?? $return->payment_type);
                $registerCheck = $accounting->resolveCashRegisterId(
                    $explicitRegisterId,
                    $actor['user'],
                    $newWarehouseId ? (int)$newWarehouseId : null,
                    $probePayType,
                    $paidAmount
                );
                if ($registerCheck['error']) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $registerCheck['error']], 422);
                }

                if ($explicitRegisterId !== null) {
                    $cashRegisterId = $registerCheck['cash_register_id'];
                    $newCashRegisterId = $registerCheck['cash_register_id'];
                } else {
                    $newCashRegisterId = $existingHeaderRegOpen
                        ? (int)$return->cash_register_id
                        : $registerCheck['cash_register_id'];
                    $cashRegisterId = !empty($beforeCashByReg)
                        ? ($return->cash_register_id ? (int)$return->cash_register_id : $newCashRegisterId)
                        : $newCashRegisterId;
                }
            }

            $txDate = isset($validated['date'])
                ? $this->normalizeTransactionDate($validated['date'], $return->date)
                : $return->date;

            $priorPaidAmount = (float)$return->paid_amount;
            $syncedPayments = $isPostedNewStatus
                ? $this->buildPaymentsPayload(
                    $financialValidated,
                    $return,
                    $paidAmount,
                    $txDate,
                    $userName,
                    $userRole,
                    isUpdate: true,
                    priorPaidAmount: $priorPaidAmount,
                    cashRegisterId: $newCashRegisterId ? (int)$newCashRegisterId : null
                )
                : [];

            if (!$isPostedNewStatus) {
                $effectivePaymentType = 'N/A';
            } else {
                $effectivePaymentType = $financialValidated['payment_type'] ?? ($paidAmount <= 0.001 && $this->isCreditNoteType($return->payment_type) ? 'Credit Note' : $return->payment_type);
                if ($paidAmount <= 0.001) {
                    if (!empty($financialValidated['payments']) && is_array($financialValidated['payments'])) {
                        $allTypes = collect($financialValidated['payments'])->pluck('payment_type')->filter()->unique();
                        if ($allTypes->contains(fn($t) => $this->isCreditNoteType((string)$t))) {
                            $effectivePaymentType = 'Credit Note';
                        } else {
                            $effectivePaymentType = 'N/A';
                        }
                    } elseif (isset($financialValidated['payment_type']) && $this->isCreditNoteType((string)$financialValidated['payment_type'])) {
                        $effectivePaymentType = 'Credit Note';
                    } elseif (!isset($financialValidated['payment_type']) && !$this->isCreditNoteType($return->payment_type)) {
                        $effectivePaymentType = 'N/A';
                    }
                } elseif (!isset($financialValidated['payment_type']) && is_array($syncedPayments) && !empty($syncedPayments)) {
                    $uniqueTypes = collect($syncedPayments)->pluck('payment_type')->filter()->unique();
                    if ($uniqueTypes->count() > 1) {
                        $effectivePaymentType = 'Split Payment';
                    } elseif ($uniqueTypes->count() === 1) {
                        $effectivePaymentType = $uniqueTypes->first();
                    }
                }
            }

            $candidatePayload = $syncedPayments !== null ? json_encode($syncedPayments) : $return->payments_payload;
            $afterCashByReg = $accounting->extractDocumentCashByRegister((object)[
                'status'           => $newStatus,
                'cash_register_id' => $cashRegisterId,
                'payment_type'     => $effectivePaymentType,
                'paid_amount'      => $paidAmount,
                'payments_payload' => $candidatePayload,
            ], ['Completed', 'Received']);
            $regDeltaErr = $accounting->assertCashRegisterDeltaMutable($beforeCashByReg, $afterCashByReg);
            if ($regDeltaErr !== null) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => $regDeltaErr], 422);
            }

            $newItemsSig = collect(is_array($newItems) ? $newItems : [])->map(function ($it) {
                $qty = round((float)($it['quantity'] ?? 0), 4);
                $rate = round((float)($it['conversion_rate'] ?? 1.0), 6);
                $baseQty = round((float)($it['base_quantity'] ?? ($qty * $rate)), 4);
                return ((int)($it['product_id'] ?? 0)) . '_' . ((int)($it['product_variant_id'] ?? 0)) . '_' . strtolower(trim((string)($it['product_code'] ?? ''))) . '_' . $baseQty;
            })->values()->all();

            $stockAffectingChanged = !$oldWasCompleted
                || $newStatus !== 'Completed'
                || (int)$oldWarehouseIdForDiff !== (int)$newWarehouseId
                || trim((string)$newPurchaseRef) !== $oldPurchaseRefForDiff
                || trim((string)$txDate) !== trim((string)$return->date)
                || $oldItemsSig !== $newItemsSig;

            $ledgerAffectingChanged = !$oldWasCompleted
                || $newStatus !== 'Completed'
                || abs($dueAmount - $oldDueForDiff) > 0.005
                || (int)$newSupplierId !== (int)$oldSupplierIdForDiff
                || trim((string)$newPurchaseRef) !== $oldPurchaseRefForDiff;

            // Revert previous stock & FIFO layers only when stock-affecting attributes changed
            if ($oldWasCompleted && $stockAffectingChanged) {
                $this->revertReturnStockAndBatches($return);
            }
            if ($oldWasCompleted && $ledgerAffectingChanged) {
                $this->revertLedgerAndPurchaseOffset($return);
            }

            // Validate warehouse stock before updating header and depleting FIFO layers
            if ($newStatus === 'Completed' && $stockAffectingChanged && !empty($newItems) && $newWarehouseId) {
                $stockError = $this->checkWarehouseStock((int)$newWarehouseId, $newItems, true);
                if ($stockError) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $stockError], 422);
                }
            }

            $updateData = [
                'reference_no'          => $validated['reference_no'] ?? $return->reference_no,
                'purchase_reference_no' => $newPurchaseRef,
                'supplier_id'           => $newSupplierId,
                'supplier_name'         => $validated['supplier_name'] ?? $return->supplier_name,
                'warehouse_id'          => $newWarehouseId,
                'warehouse_name'        => $validated['warehouse_name'] ?? $return->warehouse_name,
                'outlet_id'             => $validated['outlet_id'] ?? $return->outlet_id,
                'date'                  => $txDate,
                'status'                => $newStatus,
                'grand_total'           => $grandTotal,
                'paid_amount'           => $paidAmount,
                'due_amount'            => $dueAmount,
                'payment_status'        => $paymentStatus,
                'payment_type'          => $effectivePaymentType,
                'subtotal'              => $itemsSubtotal,
                'tax_rate'              => $taxRate,
                'tax_amount'            => $taxAmount,
                'is_tax_recoverable'    => $isTaxRecoverable,
                'discount'              => $discountAmount,
                'shipping'              => $shipping,
                'cash_register_id'      => $cashRegisterId,
                'notes'                 => array_key_exists('notes', $validated) ? $validated['notes'] : $return->notes,
                'return_reason'         => array_key_exists('return_reason', $validated) ? $validated['return_reason'] : $return->return_reason,
                'items_payload'         => json_encode($newItems),
                'created_by'            => $createdBy,
                'user_id'               => $actor['user_id'],
                'user_name'             => $userName,
                'user_role'             => $userRole,
            ];
            if ($oldWasCompleted && $newStatus !== 'Completed') {
                $updateData['return_cost_total'] = 0.0;
                $updateData['cost_variance'] = 0.0;
            } elseif ($newStatus === 'Completed' && !$stockAffectingChanged) {
                $lineTaxTotal = round((float)collect($newItems)->sum(fn($it) => (float)($it['tax'] ?? 0.0)), 2);
                $supplierCreditExclTax = $isTaxRecoverable
                    ? max(0.0, (float)$itemsSubtotal - $lineTaxTotal - (float)$discountAmount + (float)$shipping)
                    : (float)$grandTotal;
                $updateData['cost_variance'] = round($supplierCreditExclTax - (float)$return->return_cost_total, 2);
            }
            if ($syncedPayments !== null) {
                $updateData['payments_payload'] = json_encode($syncedPayments);
            }

            // Persist new header totals BEFORE deductReturnStockAndBatches so cost_variance uses updated financials
            $return->update($updateData);

            // Apply new stock & ledger if new status is Completed
            if ($newStatus === 'Completed') {
                if ($stockAffectingChanged && !empty($newItems) && $newWarehouseId) {
                    $this->deductReturnStockAndBatches($return, $newItems, (int)$newWarehouseId, $newPurchaseRef);
                }
                if ($ledgerAffectingChanged) {
                    $this->applyLedgerAndPurchaseOffset($return, $grandTotal, $dueAmount, $newPurchaseRef, $newSupplierId);
                }
            }

            DB::commit();
            $this->clearPurchaseReturnCache();

            $fresh = $return->fresh();
            $accounting->maskPurchaseReturnCostFields($fresh, $accounting->canViewCost($request));

            return response()->json([
                'status'  => 'success',
                'message' => 'Purchase return updated successfully',
                'data'    => $fresh,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('PurchaseReturn update error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            // Lock inside transaction so concurrent deletes cannot double-restore stock/AP/PO due
            $return = $this->findReturnForUpdate($id);
            if (!$return) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
            }

            $accounting = $this->accounting();
            $accounting->assertOutletAccess(
                $request,
                $return->warehouse_id ? (int)$return->warehouse_id : ($return->outlet_id ? (int)$return->outlet_id : null),
                $return->warehouse_name
            );
            $beforeCashByReg = $accounting->extractDocumentCashByRegister($return, ['Completed', 'Received']);
            $regErr = $accounting->assertCashRegisterDeltaMutable($beforeCashByReg, []);
            if ($regErr !== null) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => $regErr], 422);
            }

            // Reverse Stock & Batches and Ledger on Delete
            if (in_array($return->status, ['Completed', 'Received'], true)) {
                $this->revertReturnStockAndBatches($return);
                $this->revertLedgerAndPurchaseOffset($return);
            }

            $return->delete();

            DB::commit();
            $this->clearPurchaseReturnCache();
            return response()->json(['status' => 'success', 'message' => 'Purchase return deleted']);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('PurchaseReturn destroy error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Add a refund payment to a purchase return
     */
    private function findReturnForUpdate($idOrRef): ?PurchaseReturn
    {
        if (is_numeric($idOrRef)) {
            return PurchaseReturn::lockForUpdate()->find((int)$idOrRef)
                ?? PurchaseReturn::lockForUpdate()->where('reference_no', (string)$idOrRef)->first();
        }
        return PurchaseReturn::lockForUpdate()->where('reference_no', (string)$idOrRef)->first();
    }

    /**
     * Add a refund payment to a purchase return
     */
    public function addPayment(Request $request, $id)
    {
        $accounting = $this->accounting();

        $validated = $request->validate([
            'amount'           => 'required_without:payments|nullable|numeric|min:0.01',
            'payment_type'     => 'required_without:payments|nullable|string',
            'payment_date'     => 'nullable|string',
            'cash_register_id' => 'nullable|integer',
            'notes'            => 'nullable|string',
            'payments'         => 'nullable|array|min:1',
            'payments.*.amount'          => 'required|numeric|min:0.01',
            'payments.*.payment_type'    => 'required|string',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount'   => 'nullable|numeric|min:0',
            'payments.*.payment_date'    => 'nullable|string',
            'payments.*.notes'           => 'nullable|string',
            'payments.*.cash_register_id' => 'nullable|integer',
            'user_name'        => 'nullable|string|max:255',
            'user_role'        => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $return = $this->findReturnForUpdate($id);
            if (!$return) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Purchase return not found'], 404);
            }
            $accounting->assertOutletAccess(
                $request,
                $return->warehouse_id ? (int)$return->warehouse_id : ($return->outlet_id ? (int)$return->outlet_id : null),
                $return->warehouse_name
            );

            if (!in_array($return->status, ['Completed', 'Received'], true)) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot record refund payment on a Pending purchase return. Complete the return first.',
                ], 422);
            }

            $incomingTenders = !empty($validated['payments']) && is_array($validated['payments'])
                ? $validated['payments']
                : [['payment_type' => $validated['payment_type'] ?? 'Cash']];
            foreach ($incomingTenders as $tItem) {
                if ($this->isCreditNoteType($tItem['payment_type'] ?? null)) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Credit Note is a supplier AP offset, not a cash refund receipt. Choose Cash, Bank Transfer, or another payment method.',
                    ], 422);
                }
            }

            $actor = $accounting->resolveActor($request, $validated, $return);
            $globalUserName = $actor['user_name'];
            $globalUserRole = $actor['user_role'];

            $explicitRegisterId = !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null;
            $probePayType = !empty($validated['payments'])
                ? (collect($validated['payments'])->contains(fn($t) => $accounting->isCashPaymentType($t['payment_type'] ?? null))
                    ? 'Cash'
                    : (string)($validated['payments'][0]['payment_type'] ?? 'Bank Transfer'))
                : ($validated['payment_type'] ?? 'Cash');
            $probeAmount = !empty($validated['payments'])
                ? (float)collect($validated['payments'])->sum('amount')
                : (float)($validated['amount'] ?? 0);

            $registerCheck = $accounting->resolveCashRegisterId(
                $explicitRegisterId,
                $actor['user'],
                $return->warehouse_id ? (int)$return->warehouse_id : null,
                $probePayType,
                $probeAmount
            );
            if ($registerCheck['error']) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => $registerCheck['error']], 422);
            }
            $defaultRegisterId = $registerCheck['cash_register_id'];

            $priorDue = (float)$return->due_amount;
            $existingPayments = $return->payments;
            $updatedPayments = array_values($existingPayments);
            $nextPaymentId = $accounting->nextPaymentId($existingPayments);

            $totalIncomingPayment = 0.0;
            $cleanRef = preg_replace('/^#\s*/', '', trim((string)$return->reference_no));
            if (!empty($validated['payments']) && is_array($validated['payments'])) {
                foreach ($validated['payments'] as $idx => $tender) {
                    $pAmt = round((float)$tender['amount'], 2);
                    $totalIncomingPayment += $pAmt;
                    $paymentRef = 'PAY-' . ($cleanRef ?: $return->id) . '-' . time() . '-' . ($idx + 1) . '-' . rand(100, 999);
                    $rawDate = !empty($tender['payment_date']) ? $tender['payment_date'] : ($validated['payment_date'] ?? null);
                    $tenderType = $tender['payment_type'];
                    $tenderIsCash = $accounting->isCashPaymentType($tenderType);
                    $tenderRegId = !empty($tender['cash_register_id'])
                        ? (int)$tender['cash_register_id']
                        : ($tenderIsCash ? $defaultRegisterId : null);

                    if (!empty($tender['cash_register_id'])) {
                        $tCheck = $accounting->resolveCashRegisterId($tenderRegId, $actor['user'], $return->warehouse_id ? (int)$return->warehouse_id : null, $tenderType, $pAmt);
                        if ($tCheck['error']) {
                            DB::rollBack();
                            return response()->json(['status' => 'error', 'message' => $tCheck['error']], 422);
                        }
                    }

                    $updatedPayments[] = [
                        'id'               => $nextPaymentId++,
                        'reference_no'     => $paymentRef,
                        'payment_date'     => $this->normalizeTransactionDate($rawDate),
                        'payment_type'     => $tenderType,
                        'amount'           => $pAmt,
                        'received_amount'  => !empty($tender['received_amount']) ? (float)$tender['received_amount'] : $pAmt,
                        'change_amount'    => !empty($tender['change_amount']) ? (float)$tender['change_amount'] : 0.0,
                        'cash_register_id' => $tenderRegId,
                        'user_name'        => $globalUserName,
                        'user_role'        => $globalUserRole,
                        'notes'            => $tender['notes'] ?? ($validated['notes'] ?? ''),
                    ];
                }
            } else {
                $payingAmount = round((float)$validated['amount'], 2);
                $totalIncomingPayment += $payingAmount;
                $paymentRef = 'PAY-' . ($cleanRef ?: $return->id) . '-' . time() . '-' . rand(100, 999);
                $singleType = $validated['payment_type'];
                $singleIsCash = $accounting->isCashPaymentType($singleType);

                $updatedPayments[] = [
                    'id'               => $nextPaymentId++,
                    'reference_no'     => $paymentRef,
                    'payment_date'     => $this->normalizeTransactionDate($validated['payment_date'] ?? null),
                    'payment_type'     => $singleType,
                    'amount'           => $payingAmount,
                    'received_amount'  => $payingAmount,
                    'change_amount'    => 0.0,
                    'cash_register_id' => $singleIsCash ? $defaultRegisterId : null,
                    'user_name'        => $globalUserName,
                    'user_role'        => $globalUserRole,
                    'notes'            => $validated['notes'] ?? '',
                ];
            }

            // Prevent overpayment (strictly clamp any <= 0.01 rounding tolerance so paid_amount <= grand_total always holds)
            if ($totalIncomingPayment > $priorDue + 0.01) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => "Payment amount ({$totalIncomingPayment}) cannot exceed due balance ({$priorDue}).",
                ], 422);
            }
            if ($totalIncomingPayment > $priorDue && !empty($updatedPayments)) {
                $excess = round($totalIncomingPayment - $priorDue, 2);
                $lastIdx = count($updatedPayments) - 1;
                $updatedPayments[$lastIdx]['amount'] = max(0.0, round((float)$updatedPayments[$lastIdx]['amount'] - $excess, 2));
                $updatedPayments[$lastIdx]['received_amount'] = $updatedPayments[$lastIdx]['amount'];
                $totalIncomingPayment = $priorDue;
            }

            $newPaid = min(round((float)$return->grand_total, 2), round((float)collect($updatedPayments)->sum('amount'), 2));
            $newDue = round(max(0.0, (float)$return->grand_total - $newPaid), 2);
            $newStatus = $accounting->deriveReturnPaymentStatus((float)$return->grand_total, $newPaid, $newDue);

            $uniqueTypes = collect($updatedPayments)->pluck('payment_type')->filter()->unique();
            if ($uniqueTypes->count() > 1) {
                $newPaymentType = 'Split Payment';
            } elseif ($uniqueTypes->count() === 1) {
                $newPaymentType = $uniqueTypes->first();
            } else {
                $newPaymentType = $return->payment_type ?: 'Cash';
            }

            $updateFields = [
                'payments_payload' => json_encode($updatedPayments),
                'paid_amount'      => $newPaid,
                'due_amount'       => $newDue,
                'payment_status'   => $newStatus,
                'payment_type'     => $newPaymentType,
            ];
            if (empty($return->cash_register_id) && $defaultRegisterId !== null) {
                $updateFields['cash_register_id'] = $defaultRegisterId;
            }
            $return->update($updateFields);

            // Restore Supplier AP (locked) and release PurchaseReturnAllocation credit offsets when refund cash arrives
            $applied = round(min($priorDue, $totalIncomingPayment), 2);
            if ($applied > 0.001) {
                if (!empty($return->supplier_id)) {
                    $supplier = Supplier::where('id', $return->supplier_id)->lockForUpdate()->first();
                    if ($supplier) {
                        $supplier->update(['balance_due' => round((float)$supplier->balance_due + $applied, 2)]);
                    }
                }

                $hasReleasedCol = \Illuminate\Support\Facades\Schema::hasColumn('purchase_return_allocations', 'released_at');
                $allocQuery = PurchaseReturnAllocation::where('purchase_return_id', $return->id)
                    ->lockForUpdate()
                    ->orderBy('id');
                if ($hasReleasedCol) {
                    $allocQuery->whereNull('released_at');
                }
                $allocations = $allocQuery->get();

                $rawFirstPayDate = !empty($validated['payments'][0]['payment_date'])
                    ? $validated['payments'][0]['payment_date']
                    : ($validated['payment_date'] ?? null);
                $releaseTimestamp = $accounting->normalizeTransactionDate($rawFirstPayDate);

                if ($allocations->isNotEmpty()) {
                    // Only release credit allocations that exceed remaining unrefunded credit ($newDue).
                    // Preserve historical allocation rows via released_at so prior as_of_date aging remains immutable.
                    $totalAllocated = round((float)$allocations->sum('amount'), 2);
                    $remainingToRelease = round(max(0.0, $totalAllocated - $newDue), 2);
                    foreach ($allocations as $alloc) {
                        if ($remainingToRelease <= 0.001) {
                            break;
                        }
                        $releaseAmt = round(min((float)$alloc->amount, $remainingToRelease), 2);
                        if ($releaseAmt <= 0.001) {
                            continue;
                        }
                        $linkedPurchase = Purchase::lockForUpdate()->find($alloc->purchase_id);
                        if ($linkedPurchase) {
                            $newCredit = round(max(0.0, (float)$linkedPurchase->returned_credit_amount - $releaseAmt), 2);
                            $newPoDue = round(max(0.0, (float)$linkedPurchase->grand_total - (float)$linkedPurchase->paid_amount - $newCredit), 2);
                            $newPoStatus = $accounting->derivePurchasePaymentStatus(
                                (float)$linkedPurchase->grand_total,
                                (float)$linkedPurchase->paid_amount,
                                $newCredit,
                                $newPoDue
                            );
                            $linkedPurchase->update([
                                'returned_credit_amount' => $newCredit,
                                'due_amount'             => $newPoDue,
                                'payment_status'         => $newPoStatus,
                            ]);
                        }
                        $newAllocAmt = round((float)$alloc->amount - $releaseAmt, 2);
                        if ($newAllocAmt <= 0.001) {
                            if ($hasReleasedCol) {
                                $alloc->update(['released_at' => $releaseTimestamp]);
                            } else {
                                $alloc->delete();
                            }
                        } else {
                            $alloc->update(['amount' => $newAllocAmt]);
                            if ($hasReleasedCol) {
                                PurchaseReturnAllocation::create([
                                    'purchase_return_id' => $alloc->purchase_return_id,
                                    'purchase_id'        => $alloc->purchase_id,
                                    'supplier_id'        => $alloc->supplier_id,
                                    'amount'             => $releaseAmt,
                                    'allocation_type'    => $alloc->allocation_type,
                                    'released_at'        => $releaseTimestamp,
                                ]);
                            }
                        }
                        $remainingToRelease = round($remainingToRelease - $releaseAmt, 2);
                    }
                } elseif (!empty($return->purchase_reference_no)) {
                    $clean = trim((string)$return->purchase_reference_no);
                    $linkedPurchase = Purchase::lockForUpdate()
                        ->where(function ($q) use ($clean) {
                            $q->where('reference_no', $clean)
                              ->orWhere('reference_no', str_replace('_', '-', $clean))
                              ->orWhere('reference_no', str_replace('-', '_', $clean));
                        })
                        ->first();
                    if ($linkedPurchase) {
                        $impliedCredit = max(
                            (float)$linkedPurchase->returned_credit_amount,
                            max(0.0, (float)$linkedPurchase->grand_total - (float)$linkedPurchase->paid_amount - (float)$linkedPurchase->due_amount)
                        );
                        $legacyBacked = round(min($priorDue, $impliedCredit), 2);
                        $legacyRelease = round(max(0.0, $legacyBacked - $newDue), 2);
                        if ($legacyRelease > 0.001) {
                            $this->adjustLinkedPurchaseDueOffset($return->purchase_reference_no, $legacyRelease);
                        }
                    }
                }
            }

            DB::commit();
            $this->clearPurchaseReturnCache();

            $fresh = $return->fresh();
            $accounting->maskPurchaseReturnCostFields($fresh, $accounting->canViewCost($request));

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment added successfully',
                'data'    => $fresh,
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a refund payment transaction from a purchase return (supports both 2-param and single-param route forms)
     */
    public function deletePayment(Request $request, $returnId, $paymentId = null)
    {
        $accounting = $this->accounting();
        $hasSeparateReturnParam = $paymentId !== null
            || $request->route('id') !== null
            || $request->route('purchase_return') !== null;

        $targetIdentifier = $hasSeparateReturnParam
            ? trim((string)($paymentId ?? $request->route('paymentId') ?? ''))
            : trim((string)($request->route('paymentId') ?? $returnId ?? ''));

        if (empty($targetIdentifier)) {
            return response()->json(['status' => 'error', 'message' => 'Payment identifier is required'], 422);
        }

        DB::beginTransaction();
        try {
            $return = $hasSeparateReturnParam ? $this->findReturnForUpdate($returnId) : null;
            if ($return === null && !$hasSeparateReturnParam) {
                $candidates = PurchaseReturn::where(function ($q) use ($targetIdentifier) {
                        $q->where(function ($sq) use ($targetIdentifier) {
                            $sq->whereNotNull('payments_payload')
                               ->where('payments_payload', 'like', '%' . $targetIdentifier . '%');
                        })->orWhere(function ($lq) {
                            $lq->whereNull('payments_payload')
                               ->where('paid_amount', '>', 0.05);
                        });
                    })
                    ->lockForUpdate()
                    ->get();
                $refMatched = [];
                $idMatched = [];
                foreach ($candidates as $cand) {
                    foreach ($cand->payments as $p) {
                        $pId = isset($p['id']) ? trim((string)$p['id']) : '';
                        $pRef = isset($p['reference_no']) ? trim((string)$p['reference_no']) : '';
                        if ($pRef !== '' && $pRef === $targetIdentifier) {
                            $refMatched[$cand->id] = $cand;
                        } elseif ($pId !== '' && $pId === $targetIdentifier) {
                            $idMatched[$cand->id] = $cand;
                        }
                    }
                }
                if (count($refMatched) === 1) {
                    $return = reset($refMatched);
                } elseif (count($refMatched) > 1) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => "Payment reference '{$targetIdentifier}' matches multiple Purchase Returns. Use DELETE /api/purchase-returns/{returnId}/payments/{paymentId} instead.",
                    ], 422);
                } elseif (count($idMatched) > 1) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => "Payment identifier '{$targetIdentifier}' is ambiguous across multiple Purchase Returns. Use DELETE /api/purchase-returns/{returnId}/payments/{paymentId}.",
                    ], 422);
                } elseif (count($idMatched) === 1) {
                    $return = reset($idMatched);
                }
            }

            if (!$return) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Purchase return not found'], 404);
            }
            $accounting->assertOutletAccess(
                $request,
                $return->warehouse_id ? (int)$return->warehouse_id : ($return->outlet_id ? (int)$return->outlet_id : null),
                $return->warehouse_name
            );

            $existingPayments = $return->payments;
            if (empty($existingPayments)) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'No payment transactions exist for this return'], 404);
            }

            $remainingPayments = [];
            $deletedFound = false;
            $deletedPayment = null;

            foreach ($existingPayments as $p) {
                $pId  = isset($p['id']) ? trim((string)$p['id']) : '';
                $pRef = isset($p['reference_no']) ? trim((string)$p['reference_no']) : '';

                $matchById = ($pId !== '' && $pId === $targetIdentifier);
                $matchByRef = ($pRef !== '' && $pRef === $targetIdentifier);

                if (!$deletedFound && ($matchById || $matchByRef)) {
                    $deletedFound = true;
                    $deletedPayment = $p;
                    continue;
                }
                $remainingPayments[] = $p;
            }

            if (!$deletedFound) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Payment transaction not found'], 404);
            }

            if (in_array($return->status, ['Completed', 'Received'], true) && is_array($deletedPayment)) {
                $legRegId = isset($deletedPayment['cash_register_id']) ? (int)$deletedPayment['cash_register_id'] : null;
                $docRegId = $return->cash_register_id ? (int)$return->cash_register_id : null;
                $legErr = $accounting->assertPaymentLegRegisterMutable(
                    $legRegId,
                    $docRegId,
                    (string)($deletedPayment['payment_type'] ?? $return->payment_type)
                );
                if ($legErr !== null) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $legErr], 422);
                }
            }

            $priorDue = (float)$return->due_amount;
            $newPaid = round((float)collect($remainingPayments)->sum('amount'), 2);
            $newDue = round(max(0.0, (float)$return->grand_total - $newPaid), 2);
            $newStatus = $accounting->deriveReturnPaymentStatus((float)$return->grand_total, $newPaid, $newDue);

            $uniqueTypes = collect($remainingPayments)->pluck('payment_type')->filter()->unique();
            if ($uniqueTypes->count() > 1) {
                $newPaymentType = 'Split Payment';
            } elseif ($uniqueTypes->count() === 1) {
                $newPaymentType = $uniqueTypes->first();
            } else {
                $hasReleasedCol = \Illuminate\Support\Facades\Schema::hasColumn('purchase_return_allocations', 'released_at');
                $allocExistsQ = PurchaseReturnAllocation::where('purchase_return_id', $return->id);
                if ($hasReleasedCol) {
                    $allocExistsQ->whereNull('released_at');
                }
                $hasCreditAlloc = $allocExistsQ->exists();
                $newPaymentType = ($hasCreditAlloc || $this->isCreditNoteType((string)$return->payment_type))
                    ? 'Credit Note'
                    : 'N/A';
            }

            $return->update([
                'payments_payload' => json_encode(array_values($remainingPayments)),
                'paid_amount'      => $newPaid,
                'due_amount'       => $newDue,
                'payment_status'   => $newStatus,
                'payment_type'     => $newPaymentType,
            ]);

            // When refund payment is deleted on a Completed return, due_amount goes back up, reducing Supplier AP and allocating credit offset to linked PO
            $reversedDue = round(max(0.0, $newDue - $priorDue), 2);
            if (in_array($return->status, ['Completed', 'Received'], true) && $reversedDue > 0.001) {
                if (!empty($return->supplier_id)) {
                    $supplier = Supplier::where('id', $return->supplier_id)->lockForUpdate()->first();
                    if ($supplier) {
                        $supplier->update(['balance_due' => round((float)$supplier->balance_due - $reversedDue, 2)]);
                    }
                }

                if (!empty($return->purchase_reference_no)) {
                    $clean = trim((string)$return->purchase_reference_no);
                    $linkedPurchase = Purchase::lockForUpdate()
                        ->where(function ($q) use ($clean) {
                            $q->where('reference_no', $clean)
                              ->orWhere('reference_no', str_replace('_', '-', $clean))
                              ->orWhere('reference_no', str_replace('-', '_', $clean));
                        })
                        ->first();
                    if ($linkedPurchase) {
                        $offsetAmt = round(min((float)$linkedPurchase->due_amount, $reversedDue), 2);
                        if ($offsetAmt > 0.001) {
                            $hasReleasedCol = \Illuminate\Support\Facades\Schema::hasColumn('purchase_return_allocations', 'released_at');
                            $existingAllocQ = PurchaseReturnAllocation::where('purchase_return_id', $return->id)
                                ->where('purchase_id', $linkedPurchase->id)
                                ->where('allocation_type', 'credit_note_offset')
                                ->lockForUpdate();
                            if ($hasReleasedCol) {
                                $existingAllocQ->whereNull('released_at');
                            }
                            $existingAlloc = $existingAllocQ->first();

                            // Un-release previously stamped released_at allocations up to $offsetAmt so
                            // historical as_of_date queries never double-count released + active rows.
                            $remainingToRestore = $offsetAmt;
                            if ($hasReleasedCol) {
                                $releasedAllocs = PurchaseReturnAllocation::where('purchase_return_id', $return->id)
                                    ->where('purchase_id', $linkedPurchase->id)
                                    ->where('allocation_type', 'credit_note_offset')
                                    ->whereNotNull('released_at')
                                    ->orderByDesc('id')
                                    ->lockForUpdate()
                                    ->get();
                                foreach ($releasedAllocs as $relAlloc) {
                                    if ($remainingToRestore <= 0.001) {
                                        break;
                                    }
                                    $relAmt = (float)$relAlloc->amount;
                                    if ($relAmt <= $remainingToRestore + 0.001) {
                                        if (!$existingAlloc) {
                                            $relAlloc->update(['released_at' => null]);
                                            $existingAlloc = $relAlloc;
                                        } else {
                                            $existingAlloc->update(['amount' => round((float)$existingAlloc->amount + $relAmt, 2)]);
                                            $relAlloc->delete();
                                        }
                                        $remainingToRestore = round(max(0.0, $remainingToRestore - $relAmt), 2);
                                    } else {
                                        $relAlloc->update(['amount' => round($relAmt - $remainingToRestore, 2)]);
                                        if ($existingAlloc) {
                                            $existingAlloc->update(['amount' => round((float)$existingAlloc->amount + $remainingToRestore, 2)]);
                                        } else {
                                            $existingAlloc = PurchaseReturnAllocation::create([
                                                'purchase_return_id' => $return->id,
                                                'purchase_id'        => $linkedPurchase->id,
                                                'supplier_id'        => $return->supplier_id ?? $linkedPurchase->supplier_id,
                                                'allocation_type'    => 'credit_note_offset',
                                                'amount'             => $remainingToRestore,
                                            ]);
                                        }
                                        $remainingToRestore = 0.0;
                                    }
                                }
                            }

                            if ($remainingToRestore > 0.001) {
                                if ($existingAlloc) {
                                    $existingAlloc->update(['amount' => round((float)$existingAlloc->amount + $remainingToRestore, 2)]);
                                } else {
                                    PurchaseReturnAllocation::create([
                                        'purchase_return_id' => $return->id,
                                        'purchase_id'        => $linkedPurchase->id,
                                        'supplier_id'        => $return->supplier_id ?? $linkedPurchase->supplier_id,
                                        'allocation_type'    => 'credit_note_offset',
                                        'amount'             => $remainingToRestore,
                                    ]);
                                }
                            }

                            $newCredit = round((float)$linkedPurchase->returned_credit_amount + $offsetAmt, 2);
                            $newPoDue = round(max(0.0, (float)$linkedPurchase->grand_total - (float)$linkedPurchase->paid_amount - $newCredit), 2);
                            $newPoStatus = $accounting->derivePurchasePaymentStatus(
                                (float)$linkedPurchase->grand_total,
                                (float)$linkedPurchase->paid_amount,
                                $newCredit,
                                $newPoDue
                            );
                            $linkedPurchase->update([
                                'returned_credit_amount' => $newCredit,
                                'due_amount'             => $newPoDue,
                                'payment_status'         => $newPoStatus,
                            ]);
                        }
                    }
                }
            }

            DB::commit();
            $this->clearPurchaseReturnCache();

            $fresh = $return->fresh();
            $accounting->maskPurchaseReturnCostFields($fresh, $accounting->canViewCost($request));

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment deleted successfully. Due balance restored.',
                'data'    => $fresh,
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Failed to delete payment: ' . $e->getMessage()], 500);
        }
    }
}

