<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\FifoInventoryService;
use App\Services\ProductUnitConversionService;
use App\Services\PurchaseAccountingService;
use App\Support\ListCursorHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    use \App\Traits\GeneratesSequentialCode;

    protected function accounting(): PurchaseAccountingService
    {
        return app(PurchaseAccountingService::class);
    }
    /**
     * Generate next sequential purchase code and formatted reference number.
     */
    public function generateCode()
    {
        return response()->json($this->peekNextSequentialCode('purchase'));
    }

    /**
     * Resolve validated sort column (qualified with purchases.) for index & export.
     */
    protected function resolveSortColumn(Request $request): string
    {
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
            'payment_status' => 'purchases.payment_status',
            'grand_total' => 'purchases.grand_total',
            'total' => 'purchases.grand_total',
            'paid' => 'purchases.paid_amount',
            'paid_amount' => 'purchases.paid_amount',
            'due' => 'purchases.due_amount',
            'due_amount' => 'purchases.due_amount',
            'created_by' => 'purchases.created_by',
            'user_name' => 'purchases.user_name',
        ];

        $sortByInput = strtolower(trim((string)$request->input('sort_by', 'id')));
        return $allowedSortCols[$sortByInput] ?? 'purchases.id';
    }

    protected function normalizeBoundaryDate(string $raw, bool $isEnd): string
    {
        $trimmed = trim($raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            return $trimmed . ($isEnd ? ' 23:59:59' : ' 00:00:00');
        }
        if (str_contains($trimmed, 'T')) {
            try {
                return \Carbon\Carbon::parse($trimmed)
                    ->setTimezone(config('app.timezone'))
                    ->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return str_replace('T', ' ', $trimmed);
            }
        }
        return $trimmed;
    }

    /**
     * Parse start/end date filters safely for both space-separated datetimes and ISO-8601 "T" strings.
     */
    protected function applyDateRangeFilter($query, ?string $startDate, ?string $endDate): void
    {
        if (!empty($startDate)) {
            $query->where('purchases.date', '>=', $this->normalizeBoundaryDate($startDate, false));
        }

        if (!empty($endDate)) {
            $e = trim($endDate);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) {
                try {
                    $nextDay = \Carbon\Carbon::parse($e)->addDay()->format('Y-m-d');
                    $query->where('purchases.date', '<', $nextDay);
                    return;
                } catch (\Throwable $ex) {
                    // Fallback below
                }
            }
            $query->where('purchases.date', '<=', $this->normalizeBoundaryDate($e, true));
        }
    }

    /**
     * Build filtered purchase query based on request parameters
     */
    protected function buildFilteredQuery(Request $request, bool $includeItems = false)
    {
        $selectCols = [
            'purchases.id',
            'purchases.reference_no',
            'purchases.supplier_id',
            'purchases.supplier_name',
            'purchases.warehouse_id',
            'purchases.warehouse_name',
            'purchases.date',
            'purchases.status',
            'purchases.tax_rate',
            'purchases.tax_amount',
            'purchases.discount',
            'purchases.shipping',
            'purchases.is_tax_recoverable',
            'purchases.grand_total',
            'purchases.paid_amount',
            'purchases.due_amount',
            'purchases.returned_credit_amount',
            'purchases.payment_status',
            'purchases.payment_type',
            'purchases.cash_register_id',
            'purchases.notes',
            'purchases.created_by',
            'purchases.user_id',
            'purchases.user_name',
            'purchases.user_role',
            'purchases.created_at',
            'purchases.updated_at',
        ];
        if ($includeItems) {
            $selectCols[] = 'purchases.payments_payload';
        }

        $query = Purchase::query()->select($selectCols);

        $relations = [];

        if ($includeItems) {
            $relations[] = 'supplier:id,name';
            $relations[] = 'items:id,purchase_id,product_id,product_variant_id,variant_name,product_code,product_name,unit_id,unit_name,conversion_rate,unit_cost,quantity,base_quantity,base_unit_cost,tax,discount,subtotal';
        }

        if (!empty($relations)) {
            $query->with($relations);
        }

        $accounting = $this->accounting();
        $accounting->applyOutletScope($query, $accounting->extractAllowedOutletIds($request), 'purchases.warehouse_id');

        if ($request->filled('supplier_id')) {
            $query->where('purchases.supplier_id', $request->input('supplier_id'));
        }

        if ($request->filled('supplier') && $request->input('supplier') !== 'All') {
            $supplier = $request->input('supplier');
            $query->where(function ($q) use ($supplier) {
                $q->where('purchases.supplier_name', $supplier);
                if (is_numeric($supplier)) {
                    $q->orWhere('purchases.supplier_id', (int)$supplier);
                }
            });
        }

        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $warehouse = $request->input('warehouse');
            $query->where(function ($q) use ($warehouse) {
                $q->where('purchases.warehouse_name', $warehouse);
                if (is_numeric($warehouse)) {
                    $q->orWhere('purchases.warehouse_id', (int)$warehouse);
                }
            });
        }
        if ($request->filled('warehouse_id')) {
            $query->where('purchases.warehouse_id', (int)$request->input('warehouse_id'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('purchases.status', $request->input('status'));
        }

        $paymentFilter = $request->input('payment_status') ?? $request->input('payment');
        if (!empty($paymentFilter) && $paymentFilter !== 'All') {
            $query->where('purchases.payment_status', $paymentFilter);
        }

        $creatorFilter = $request->input('created_by') ?? $request->input('creator');
        if (!empty($creatorFilter) && $creatorFilter !== 'All') {
            $creatorTrim = trim((string)$creatorFilter);
            $query->where(function ($q) use ($creatorTrim) {
                $q->where('purchases.created_by', $creatorTrim)
                  ->orWhere('purchases.user_name', $creatorTrim)
                  ->orWhere('purchases.created_by', 'like', $creatorTrim . ' (%');
            });
        }

        $startDate = $request->input('start_date') ?? $request->input('from_date') ?? $request->input('date_from');
        $endDate = $request->input('end_date') ?? $request->input('to_date') ?? $request->input('date_to');
        $this->applyDateRangeFilter($query, $startDate, $endDate);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $altHyphen = str_replace('_', '-', $search);
            $altUnderscore = str_replace('-', '_', $search);
            if (preg_match('/^(PU|PO|PUR)[-_]/i', $search)) {
                $query->where(function ($q) use ($search, $altHyphen, $altUnderscore) {
                    $q->where('purchases.reference_no', 'like', "{$search}%");
                    if ($altHyphen !== $search) {
                        $q->orWhere('purchases.reference_no', 'like', "{$altHyphen}%");
                    }
                    if ($altUnderscore !== $search) {
                        $q->orWhere('purchases.reference_no', 'like', "{$altUnderscore}%");
                    }
                });
            } else {
                $digits = preg_replace('/[^0-9]/', '', $search);
                $query->where(function ($q) use ($search, $digits, $altHyphen, $altUnderscore) {
                    $q->where('purchases.reference_no', 'like', "%{$search}%")
                      ->orWhere('purchases.supplier_name', 'like', "%{$search}%")
                      ->orWhere('purchases.created_by', 'like', "%{$search}%")
                      ->orWhere('purchases.user_name', 'like', "%{$search}%")
                      ->orWhere('purchases.user_role', 'like', "%{$search}%")
                      ->orWhere('purchases.notes', 'like', "%{$search}%")
                      ->orWhereHas('items', function ($iq) use ($search) {
                          $iq->where('product_name', 'like', "%{$search}%")
                             ->orWhere('product_code', 'like', "%{$search}%");
                      });
                    if ($altHyphen !== $search) {
                        $q->orWhere('purchases.reference_no', 'like', "%{$altHyphen}%");
                    }
                    if ($altUnderscore !== $search) {
                        $q->orWhere('purchases.reference_no', 'like', "%{$altUnderscore}%");
                    }
                    if (preg_match('/^#?(\d+)$/', $search, $m)) {
                        $q->orWhere('purchases.id', (int)$m[1]);
                    }
                    if (!empty($digits)) {
                        $q->orWhere('purchases.reference_no', 'like', "%{$digits}%");
                    }
                });
            }
        }

        if ($request->filled('payment_type') && $request->input('payment_type') !== 'All') {
            $accounting->applyPaymentTypeFilter($query, (string)$request->input('payment_type'), 'purchases.payment_type', null, 'purchases.payments_payload');
            if (!$request->boolean('include_opening_stock')) {
                $accounting->excludeOpeningStockPurchases($query, 'purchases.payment_type');
            }
        } elseif (!$request->boolean('include_opening_stock')) {
            $accounting->excludeOpeningStockPurchases($query, 'purchases.payment_type');
        }

        return $query;
    }

    public function index(Request $request)
    {
        $includeItems = !$request->boolean('no_items', false);
        $skipSummary = $request->boolean('skip_summary', false);
        $query = $this->buildFilteredQuery($request, $includeItems);

        // Sorting
        $sortBy = $this->resolveSortColumn($request);
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $paymentFilter = $request->input('payment_status') ?? $request->input('payment');
        $creatorFilter = $request->input('created_by') ?? $request->input('creator');
        $hasFilters = $request->filled('supplier_id')
            || ($request->filled('supplier') && $request->input('supplier') !== 'All')
            || ($request->filled('warehouse') && $request->input('warehouse') !== 'All')
            || $request->filled('warehouse_id')
            || ($request->filled('status') && $request->input('status') !== 'All')
            || (!empty($paymentFilter) && $paymentFilter !== 'All')
            || ($request->filled('payment_type') && $request->input('payment_type') !== 'All')
            || $request->boolean('include_opening_stock')
            || (!empty($creatorFilter) && $creatorFilter !== 'All')
            || !empty($request->input('start_date') ?? $request->input('from_date') ?? $request->input('date_from'))
            || !empty($request->input('end_date') ?? $request->input('to_date') ?? $request->input('date_to'))
            || $request->filled('search');

        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $knownTotal = $request->filled('known_total') ? max(0, (int)$request->input('known_total')) : null;
        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        $page = max(1, (int)$request->input('page', 1));
        $perPage = min(1000, max(1, (int)$request->input('per_page', 50)));
        $cursorAllowed = [
            'purchases.id', 'purchases.reference_no', 'purchases.supplier_name',
            'purchases.warehouse_name', 'purchases.date', 'purchases.status',
            'purchases.payment_status', 'purchases.grand_total', 'purchases.paid_amount',
            'purchases.due_amount', 'purchases.created_by', 'purchases.user_name',
        ];
        $cursorData = !$isAll
            ? ListCursorHelper::decode(
                (string)$request->input('cursor', ''),
                $sortBy,
                $sortOrder,
                $cursorAllowed,
                ['id', 'purchases.id'],
                ['grand_total', 'paid_amount', 'due_amount', 'purchases.grand_total', 'purchases.paid_amount', 'purchases.due_amount']
            )
            : null;
        $isScrollPage = ($page > 1 || $cursorData !== null);

        if ($skipSummary && $knownTotal !== null) {
            $totalCount = $knownTotal;
            $summaryGrandTotal = 0.0;
            $summaryPaid = 0.0;
            $summaryDue = 0.0;
        } else {
            $aggQuery = (clone $query)->setEagerLoads([])->toBase();
            $aggQuery->columns = null;
            $aggQuery->orders = null;
            if ($skipSummary) {
                $totalCount = (int)$aggQuery->count();
                $summaryGrandTotal = 0.0;
                $summaryPaid = 0.0;
                $summaryDue = 0.0;
            } else {
                $agg = $aggQuery->selectRaw("
                    COUNT(*) as total_records,
                    COALESCE(SUM(CASE WHEN purchases.status IN ('Received', 'Completed') THEN purchases.grand_total ELSE 0 END), 0) as total_grand_total,
                    COALESCE(SUM(CASE WHEN purchases.status IN ('Received', 'Completed') THEN purchases.paid_amount ELSE 0 END), 0) as total_paid,
                    COALESCE(SUM(CASE WHEN purchases.status IN ('Received', 'Completed') THEN purchases.due_amount ELSE 0 END), 0) as total_due
                ")->first();
                $totalCount = (int)($agg->total_records ?? 0);
                $summaryGrandTotal = (float)($agg->total_grand_total ?? 0);
                $summaryPaid = (float)($agg->total_paid ?? 0);
                $summaryDue = (float)($agg->total_due ?? 0);
            }
        }

        $summary = $accounting->maskFinancialSummary([
            'total_records' => $totalCount,
            'total_grand_total' => $summaryGrandTotal,
            'total_paid' => $summaryPaid,
            'total_due' => $summaryDue,
        ], $canViewCost);

        if ($cursorData !== null) {
            ListCursorHelper::apply($query, $sortBy, $sortOrder, $cursorData, 'purchases.id');
        }
        $query->orderBy($sortBy, $sortOrder);
        if ($sortBy !== 'purchases.id') {
            $query->orderBy('purchases.id', $sortOrder);
        }

        $cacheControl = 'no-store, no-cache, must-revalidate, private';
        if ($isAll) {
            $limit = min(1000, max(1, (int)$request->input('limit', 500)));
            $purchases = $query->take($limit)->get();
            if (!$includeItems) {
                $purchases->each->setAppends([]);
            }
            $purchases->each(fn($p) => $accounting->maskPurchaseCostFields($p, $canViewCost));
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
            ])->header('Cache-Control', $cacheControl);
        }

        $offset = $cursorData !== null ? 0 : (($page - 1) * $perPage);
        $fetched = $query->offset($offset)->limit($perPage + 1)->get();
        $hasMore = $fetched->count() > $perPage;
        $purchases = $hasMore ? $fetched->slice(0, $perPage)->values() : $fetched;
        $lastRowObj = $purchases->last();
        $nextCursor = ($hasMore && $lastRowObj !== null)
            ? ListCursorHelper::encode((object)$lastRowObj, $sortBy, $sortOrder, $cursorAllowed, 'purchases.id')
            : null;
        if (!$includeItems) {
            $purchases->each->setAppends([]);
        }
        $purchases->each(fn($p) => $accounting->maskPurchaseCostFields($p, $canViewCost));
        $lastPage = max(1, (int)ceil($totalCount / max(1, $perPage)));

        $pagination = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $totalCount,
            'last_page' => $lastPage,
            'has_more' => $hasMore,
        ];
        if ($nextCursor !== null) {
            $pagination['next_cursor'] = $nextCursor;
        }

        return response()->json([
            'status' => 'success',
            'data' => $purchases->values(),
            'total' => $totalCount,
            'summary' => $summary,
            'pagination' => $pagination,
        ])->header('Cache-Control', $cacheControl);
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
        $sortBy = $this->resolveSortColumn($request);
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
        $paymentFilter = $request->input('payment_status') ?? $request->input('payment');
        $paymentTypeFilter = $request->input('payment_type');
        $supplierFilter = $request->input('supplier');
        $creatorFilter = $request->input('created_by') ?? $request->input('creator');
        $fromDate = $request->input('start_date') ?? $request->input('from_date') ?? $request->input('date_from');
        $toDate = $request->input('end_date') ?? $request->input('to_date') ?? $request->input('date_to');
        $searchQuery = $request->input('search');

        // Pre-aggregate purchase items via high-speed group-by join to eliminate 10,000 N+1 queries
        $query->leftJoin(
            \DB::raw('(SELECT purchase_id, COUNT(*) as item_lines, SUM(quantity) as total_qty, SUM(subtotal) as items_subtotal FROM purchase_items GROUP BY purchase_id) pi'),
            'purchases.id',
            '=',
            'pi.purchase_id'
        )->addSelect([
            \DB::raw('COALESCE(pi.item_lines, 0) as item_lines'),
            \DB::raw('COALESCE(pi.total_qty, 0) as total_qty'),
            \DB::raw('pi.items_subtotal as items_subtotal')
        ]);

        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $currencyCode = $accounting->resolveDefaultCurrencyCode();

        $callback = function () use (
            $query,
            $sortBy,
            $sortOrder,
            $warehouseFilter,
            $statusFilter,
            $paymentFilter,
            $paymentTypeFilter,
            $supplierFilter,
            $creatorFilter,
            $fromDate,
            $toDate,
            $searchQuery,
            $canViewCost,
            $currencyCode
        ) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Microsoft Excel on Windows
            fwrite($file, "\xEF\xBB\xBF");

            $sanitizeCsv = static function ($val): string {
                if ($val === null) {
                    return '';
                }
                $str = (string)$val;
                if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    return "'" . $str;
                }
                return $str;
            };

            // 1. Metadata Header
            fputcsv($file, ['Purchases List Export']);
            fputcsv($file, ['Generated At', date('c')]);
            fputcsv($file, ['Warehouse Filter', $sanitizeCsv($warehouseFilter ?: 'All')]);
            fputcsv($file, ['Status Filter', $sanitizeCsv($statusFilter ?: 'All')]);
            if (!empty($paymentFilter) && $paymentFilter !== 'All') {
                fputcsv($file, ['Payment Status Filter', $sanitizeCsv($paymentFilter)]);
            }
            if (!empty($paymentTypeFilter) && $paymentTypeFilter !== 'All') {
                fputcsv($file, ['Payment Type Filter', $sanitizeCsv($paymentTypeFilter)]);
            }
            if (!empty($supplierFilter) && $supplierFilter !== 'All') {
                fputcsv($file, ['Supplier Filter', $sanitizeCsv($supplierFilter)]);
            }
            if (!empty($creatorFilter) && $creatorFilter !== 'All') {
                fputcsv($file, ['Created By Filter', $sanitizeCsv($creatorFilter)]);
            }
            if (!empty($fromDate) || !empty($toDate)) {
                $fromStr = !empty($fromDate) ? explode(' ', $fromDate)[0] : 'Beginning';
                $toStr = !empty($toDate) ? explode(' ', $toDate)[0] : 'Present';
                fputcsv($file, ['Date Range', $sanitizeCsv("$fromStr to $toStr")]);
            }
            if (!empty($searchQuery)) {
                fputcsv($file, ['Search Filter', $sanitizeCsv($searchQuery)]);
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
                "Tax Amount ({$currencyCode})",
                "Discount ({$currencyCode})",
                "Shipping ({$currencyCode})",
                "Subtotal ({$currencyCode})",
                "Grand Total ({$currencyCode})",
                "Paid Amount ({$currencyCode})",
                "Due Amount ({$currencyCode})",
                'Payment Status',
                'Created By',
                'User Role',
            'Notes',
            "Returned Credit Offset ({$currencyCode})",
            'Tax Recoverable',
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
        $totalReturnedCredit = 0.0;
        $totalUnitsCount = 0.0;
        $subtotalSum = 0.0;

        $flushCount = 0;
        $query->orderBy($sortBy, $sortOrder);
        if ($sortBy !== 'purchases.id') {
            $query->orderBy('purchases.id', $sortOrder);
        }
        foreach ($query->toBase()->cursor() as $p) {
            $totalCount++;
            $isPosted = in_array((string)$p->status, ['Received', 'Completed'], true);
            $itemLines = (int)$p->item_lines;
            $totalQty = (float)$p->total_qty;
            $subtotal = $p->items_subtotal !== null
                ? (float)$p->items_subtotal
                : max(0.0, (float)$p->grand_total - (float)$p->tax_amount - (float)$p->shipping + (float)$p->discount);

            if ($isPosted) {
                $totalUnitsCount += $totalQty;
                $totalTax += (float)$p->tax_amount;
                $totalDiscount += (float)$p->discount;
                $totalShipping += (float)$p->shipping;
                $totalGrandTotal += (float)$p->grand_total;
                $totalPaid += (float)$p->paid_amount;
                $totalDue += (float)$p->due_amount;
                $totalReturnedCredit += (float)($p->returned_credit_amount ?? 0);
                $subtotalSum += $subtotal;
            }

            $createdBy = trim((string)($p->created_by ?: ($p->user_name ?: 'Admin')));
            if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $createdBy, $m)) {
                $createdBy = trim($m[1]);
            }

            $row = [
                $sn++,
                $sanitizeCsv($p->reference_no),
                $p->date,
                $sanitizeCsv($p->supplier_name),
                $sanitizeCsv($p->warehouse_name),
                $p->status,
                $itemLines,
                $formatNum($totalQty),
                $canViewCost ? $formatNum($p->tax_amount) : 'Protected',
                $canViewCost ? $formatNum($p->discount) : 'Protected',
                $canViewCost ? $formatNum($p->shipping) : 'Protected',
                $canViewCost ? $formatNum($subtotal) : 'Protected',
                $canViewCost ? $formatNum($p->grand_total) : 'Protected',
                $canViewCost ? $formatNum($p->paid_amount) : 'Protected',
                $canViewCost ? $formatNum($p->due_amount) : 'Protected',
                $p->payment_status ?: 'Unpaid',
                $sanitizeCsv($createdBy),
                $sanitizeCsv($p->user_role ?: 'Admin'),
                $sanitizeCsv($p->notes ?? ''),
                $canViewCost ? $formatNum($p->returned_credit_amount ?? 0) : 'Protected',
                !empty($p->is_tax_recoverable) ? 'Yes' : 'No',
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
            "$totalCount Purchases",
            '',
            '',
            '',
            '',
            '',
            $formatNum($totalUnitsCount),
            $canViewCost ? $formatNum($totalTax) : 'Protected',
            $canViewCost ? $formatNum($totalDiscount) : 'Protected',
            $canViewCost ? $formatNum($totalShipping) : 'Protected',
            $canViewCost ? $formatNum($subtotalSum) : 'Protected',
            $canViewCost ? $formatNum($totalGrandTotal) : 'Protected',
            $canViewCost ? $formatNum($totalPaid) : 'Protected',
            $canViewCost ? $formatNum($totalDue) : 'Protected',
            '',
            '',
            '',
            '',
            $canViewCost ? $formatNum($totalReturnedCredit) : 'Protected',
            '',
        ]);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Clamp paid amount to grand total and derive due amount and payment status.
     *
     * @return array{0: float, 1: float, 2: string}
     */
    protected function applyPurchasePaymentSummary(
        float $grandTotal,
        float $rawPaidAmount,
        float $returnedCreditAmount = 0.0
    ): array {
        $creditApplied = round(max(0.0, min($grandTotal, $returnedCreditAmount)), 2);
        $maxPayable = round(max(0.0, $grandTotal - $creditApplied), 2);
        $paidAmount = round(min($maxPayable, max(0.0, $rawPaidAmount)), 2);
        $dueAmount = round(max(0.0, $grandTotal - $paidAmount - $creditApplied), 2);
        $paymentStatus = $this->accounting()->derivePurchasePaymentStatus(
            $grandTotal,
            $paidAmount,
            $creditApplied,
            $dueAmount
        );
        return [$paidAmount, $dueAmount, $paymentStatus];
    }

    /**
     * Derive payment_type from a list of payment tender rows.
     */
    protected function resolveDerivedPaymentType(array $payments, ?string $fallbackType = 'Cash'): string
    {
        $uniqueTypes = collect($payments)->pluck('payment_type')->filter(fn($t) => trim((string)$t) !== '')->unique();
        if ($uniqueTypes->count() > 1) {
            return 'Split Payment';
        }
        if ($uniqueTypes->count() === 1) {
            return (string)$uniqueTypes->first();
        }
        return $fallbackType ?: 'Cash';
    }

    /**
     * Build normalized payment ledger rows capped so total applied never exceeds payable grand_total.
     */
    protected function buildNormalizedPaymentsList(
        array $tenders,
        float $grandTotal,
        string $refSeed,
        string $fallbackDate,
        string $fallbackType,
        string $userName,
        string $userRole,
        string $defaultNotes,
        ?int $defaultCashRegisterId = null
    ): array {
        $accounting = $this->accounting();
        $savedPayments = [];
        $remaining = round(max(0.0, $grandTotal), 2);
        $tenderIdx = 1;
        $cleanRef = preg_replace('/^#\s*/', '', trim($refSeed)) ?: $refSeed;

        foreach ($tenders as $p) {
            $rawAmount = max(0.0, (float)($p['amount'] ?? 0));
            if ($rawAmount <= 0.0001 || $remaining <= 0.0001) {
                continue;
            }
            $applied = round(min($remaining, $rawAmount), 2);
            $received = round(max($applied, (float)($p['received_amount'] ?? $rawAmount)), 2);
            $change = round(max($received - $applied, (float)($p['change_amount'] ?? 0.0)), 2);
            $tenderType = !empty($p['payment_type']) ? (string)$p['payment_type'] : $fallbackType;
            $tenderRegisterId = !empty($p['cash_register_id'])
                ? (int)$p['cash_register_id']
                : ($accounting->isCashPaymentType($tenderType) ? $defaultCashRegisterId : null);

            $row = [
                'id' => $tenderIdx,
                'reference_no' => !empty($p['reference_no'])
                    ? (string)$p['reference_no']
                    : ('PAY-' . $cleanRef . '-' . str_pad($tenderIdx, 2, '0', STR_PAD_LEFT)),
                'payment_date' => $accounting->normalizeTransactionDate(
                    !empty($p['payment_date']) ? (string)$p['payment_date'] : null,
                    $fallbackDate
                ),
                'payment_type' => $tenderType,
                'amount' => $applied,
                'received_amount' => $received,
                'change_amount' => $change,
                'user_name' => $p['user_name'] ?? $userName,
                'user_role' => $p['user_role'] ?? $userRole,
                'notes' => $p['notes'] ?? ($fallbackType === 'Split Payment' ? 'Split payment line' : $defaultNotes),
            ];
            if ($tenderRegisterId !== null) {
                $row['cash_register_id'] = $tenderRegisterId;
            }
            $savedPayments[] = $row;
            $remaining = round(max(0.0, $remaining - $applied), 2);
            $tenderIdx++;
        }

        return $savedPayments;
    }

    /**
     * Revert stock and delete FIFO batches for an existing purchase from the specified warehouse.
     */
    protected function revertPurchaseStockAndBatches(
        Purchase $purchase,
        ?int $warehouseId,
        ProductUnitConversionService $unitService,
        string $reason,
        string $logNotes
    ): void {
        $affectedKeys = [];
        $purchase->loadMissing('items');
        $batchesByItem = \App\Models\InventoryBatch::whereIn('purchase_item_id', $purchase->items->pluck('id'))
            ->get()
            ->keyBy('purchase_item_id');
        foreach ($purchase->items as $item) {
            $prod = !empty($item->product_id)
                ? Product::lockForUpdate()->find($item->product_id)
                : Product::lockForUpdate()->where('code', $item->product_code)->first();
            if ($prod) {
                $vId = !empty($item->product_variant_id) ? (int)$item->product_variant_id : null;
                $affectedKeys[$prod->id . '|' . ($vId ?? 0)] = [(int)$prod->id, $vId];
                $baseQty = (float)($item->base_quantity ?: $item->quantity);
                $itemBatch = $batchesByItem->get($item->id);
                $revUnitCost = $itemBatch && (float)$itemBatch->unit_cost > 0
                    ? (float)$itemBatch->unit_cost
                    : (float)($item->base_unit_cost ?: $item->unit_cost);
                $unitService->applyStock(
                    $prod,
                    $warehouseId,
                    $item->product_variant_id,
                    $baseQty,
                    'out',
                    $reason,
                    'purchase',
                    (string)$purchase->id,
                    $logNotes,
                    true,
                    false,
                    true,
                    $revUnitCost > 0 ? $revUnitCost : null,
                    (string)$purchase->date
                );
            }
        }
        \App\Models\InventoryBatch::whereIn('purchase_item_id', $purchase->items->pluck('id'))->delete();

        foreach ($affectedKeys as [$pId, $vId]) {
            $latestBatch = \App\Models\InventoryBatch::where('product_id', $pId)
                ->when(
                    $vId !== null,
                    fn($q) => $q->where('product_variant_id', $vId),
                    fn($q) => $q->whereNull('product_variant_id')
                )
                ->orderByDesc('received_date')
                ->orderByDesc('id')
                ->first();
            if ($latestBatch && (float)$latestBatch->unit_cost > 0.0001) {
                $this->syncMasterProductCost($pId, $vId, (float)$latestBatch->unit_cost);
            }
        }
    }

    /**
     * Persist PurchaseItem rows and, when Received, increment warehouse stock, update product costs, and record FIFO batches.
     */
    protected function persistAndApplyPurchaseItems(
        Purchase $purchase,
        array $normalizedItems,
        ?int $warehouseId,
        string $status,
        float $costRatioPool,
        float $netAdjustment,
        string $receivedDate,
        ProductUnitConversionService $unitService,
        FifoInventoryService $fifoService,
        string $receivedLogPrefix = 'Purchase received in ',
        bool $isTaxRecoverable = false
    ): void {
        $itemProductIds = collect($normalizedItems)->pluck('product_id')->filter()->unique()->values()->all();
        $itemProductCodes = collect($normalizedItems)->pluck('product_code')->filter()->unique()->values()->all();
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

        $lineBases = [];
        foreach ($normalizedItems as $idx => $item) {
            $qty = (float)$item['quantity'];
            $taxExcl = (float)($item['tax_exclusive_subtotal'] ?? max(0.0, ((float)$item['unit_cost'] * $qty) - (float)$item['discount']));
            $lineBases[$idx] = $isTaxRecoverable ? $taxExcl : (float)$item['subtotal'];
        }
        $landedMap = $this->accounting()->allocateLineLandedCosts($lineBases, $costRatioPool, $netAdjustment);

        foreach ($normalizedItems as $idx => $item) {
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
                'tax' => (float)$item['tax'],
                'discount' => (float)$item['discount'],
                'subtotal' => (float)$item['subtotal'],
            ]);

            if ($status === 'Received' && $product) {
                $precomputedLandedTotal = (float)($landedMap[$idx]['landed_total'] ?? $lineBases[$idx]);
                $this->applyReceivedItemStockCostAndBatch(
                    $purchase,
                    $product,
                    $warehouseId,
                    $variantId,
                    (float)$baseQuantity,
                    (float)$baseUnitCost,
                    (float)$precomputedLandedTotal,
                    $receivedDate,
                    (int)$purchaseItem->id,
                    $receivedLogPrefix . $linkedUnit['unit_name'],
                    "Purchase #{$purchase->reference_no} received",
                    $unitService,
                    $fifoService
                );
            }
        }
    }

    /**
     * Update master Product or ProductVariant cost fields from a landed base unit cost.
     */
    protected function syncMasterProductCost(?int $productId, ?int $variantId, float $effectiveMasterCost): void
    {
        if ($effectiveMasterCost <= 0.0001) {
            return;
        }
        if ($variantId) {
            ProductVariant::where('id', $variantId)->update([
                'cost_price' => $effectiveMasterCost,
            ]);
        } elseif ($productId) {
            Product::where('id', $productId)->update([
                'purchase_price' => $effectiveMasterCost,
                'cost_price' => $effectiveMasterCost,
            ]);
        }
    }

    /**
     * Reconcile Supplier balance_due atomically using row locks inside an active DB transaction.
     */
    protected function syncSupplierBalanceDue(?int $oldSupplierId, float $oldDue, ?int $newSupplierId, float $newDue): void
    {
        if ($oldSupplierId && $newSupplierId && $oldSupplierId === $newSupplierId) {
            $diff = round($newDue - $oldDue, 2);
            if (abs($diff) > 0.001) {
                $supplier = \App\Models\Supplier::where('id', $oldSupplierId)->lockForUpdate()->first();
                if ($supplier) {
                    $supplier->update(['balance_due' => round((float)$supplier->balance_due + $diff, 2)]);
                }
            }
            return;
        }

        if ($oldSupplierId && abs($oldDue) > 0.001) {
            $oldSupplier = \App\Models\Supplier::where('id', $oldSupplierId)->lockForUpdate()->first();
            if ($oldSupplier) {
                $oldSupplier->update(['balance_due' => round((float)$oldSupplier->balance_due - $oldDue, 2)]);
            }
        }
        if ($newSupplierId && abs($newDue) > 0.001) {
            $newSupplier = \App\Models\Supplier::where('id', $newSupplierId)->lockForUpdate()->first();
            if ($newSupplier) {
                $newSupplier->update(['balance_due' => round((float)$newSupplier->balance_due + $newDue, 2)]);
            }
        }
    }

    public function store(Request $request)
    {
        $accounting = $this->accounting();
        $validated = $request->validate([
            'reference_no'              => 'nullable|string|max:100',
            'supplier_id'               => 'nullable|integer',
            'supplier_name'             => 'required|string|max:255',
            'warehouse_name'            => 'required|string|max:255',
            'warehouse_id'              => 'nullable|integer',
            'date'                      => 'required|string',
            'status'                    => 'nullable|string|in:Received,Pending,Ordered',
            'payment_status'            => 'nullable|string|in:Paid,Unpaid,Partial,Credit Settled',
            'tax_rate'                  => 'nullable|numeric|min:0|max:100',
            'tax_amount'                => 'nullable|numeric|min:0',
            'is_tax_recoverable'        => 'nullable|boolean',
            'discount'                  => 'nullable|numeric|min:0',
            'shipping'                  => 'nullable|numeric|min:0',
            'grand_total'               => 'nullable|numeric|min:0',
            'paid_amount'               => 'nullable|numeric|min:0',
            'payment_type'              => 'nullable|string',
            'cash_register_id'          => 'nullable|integer',
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
            'payments.*.cash_register_id' => 'nullable|integer',
        ]);

        $actor = $accounting->resolveActor($request, $validated);
        if ($accounting->isOpeningStockPaymentType($validated['payment_type'] ?? null)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Opening Stock payment type is reserved for system opening stock records. Use Stock Adjustments for manual opening stock.',
            ], 422);
        }
        $unitService = app(ProductUnitConversionService::class);
        $fifoService = app(FifoInventoryService::class);
        $warehouseId = $unitService->warehouseId($validated['warehouse_id'] ?? null, $validated['warehouse_name']);
        $accounting->assertOutletAccess($request, $warehouseId, $validated['warehouse_name']);

        $canViewCost = $accounting->canViewCost($request);
        $canPay = $accounting->canRecordPayment($request, 'purchases.pay');
        $incomingItems = $validated['items'];
        if (!$canViewCost) {
            $costCheck = $accounting->enforceCostBlindStoreItems($incomingItems);
            if ($costCheck['error'] !== null) {
                return response()->json(['status' => 'error', 'message' => $costCheck['error']], 422);
            }
            $incomingItems = $costCheck['items'];
        }

        $isTaxRecoverable = $accounting->resolveTaxRecoverability($canViewCost ? $validated : [], null);
        $totals = $accounting->calculateDocumentTotals(
            $incomingItems,
            $canViewCost ? (float)($validated['discount'] ?? 0) : 0.0,
            $canViewCost ? (float)($validated['tax_rate'] ?? 0) : 0.0,
            ($canViewCost && array_key_exists('tax_amount', $validated) && $validated['tax_amount'] !== null) ? (float)$validated['tax_amount'] : null,
            $canViewCost ? (float)($validated['shipping'] ?? 0) : 0.0,
            $isTaxRecoverable
        );

        $discount = $totals['discount'];
        $shipping = $totals['shipping'];
        $taxRate = $totals['tax_rate'];
        $taxAmount = $totals['tax_amount'];
        $grandTotal = $totals['grand_total'];

        $status = $validated['status'] ?? 'Received';
        $isReceivedStatus = ($status === 'Received');

        // Draft/Ordered POs (not yet Received) and users without pay permission must never record cash movements
        $hasPaymentsArray = $isReceivedStatus && $canPay && !empty($validated['payments']) && is_array($validated['payments']);
        $rawPaidInput = (!$isReceivedStatus || !$canPay)
            ? 0.0
            : ($hasPaymentsArray
                ? (float)collect($validated['payments'])->sum('amount')
                : (float)($validated['paid_amount'] ?? 0));

        [$paidAmount, $dueAmount, $paymentStatus] = $this->applyPurchasePaymentSummary($grandTotal, $rawPaidInput, 0.0);

        $resolvedPaymentType = !$isReceivedStatus
            ? 'N/A'
            : ($hasPaymentsArray
                ? $this->resolveDerivedPaymentType($validated['payments'], $validated['payment_type'] ?? 'Cash')
                : ($validated['payment_type'] ?? ($paymentStatus === 'Unpaid' ? 'N/A' : 'Cash')));

        $cashRegisterId = null;
        if ($isReceivedStatus) {
            if ($hasPaymentsArray) {
                $splitRegErr = $accounting->validateSplitTenderCashRegisters($validated['payments']);
                if ($splitRegErr !== null) {
                    return response()->json(['status' => 'error', 'message' => $splitRegErr], 422);
                }
            }
            $probePayType = $hasPaymentsArray
                ? (collect($validated['payments'])->contains(fn($t) => $accounting->isCashPaymentType($t['payment_type'] ?? null))
                    ? 'Cash'
                    : (string)($validated['payments'][0]['payment_type'] ?? 'Bank Transfer'))
                : $resolvedPaymentType;
            $explicitRegisterId = !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null;
            $registerCheck = $accounting->resolveCashRegisterId(
                $explicitRegisterId,
                $actor['user'],
                $warehouseId,
                $probePayType,
                $paidAmount
            );
            if ($registerCheck['error']) {
                return response()->json(['status' => 'error', 'message' => $registerCheck['error']], 422);
            }
            $cashRegisterId = $registerCheck['cash_register_id'];
        }

        DB::beginTransaction();
        try {
            $refNo = $this->acquireUniqueReference('purchase', $validated['reference_no'] ?? null);
            $txDate = $accounting->normalizeTransactionDate($validated['date'] ?? null);
            $resolvedSupplierId = !empty($validated['supplier_id'])
                ? (int)$validated['supplier_id']
                : Supplier::where('name', trim((string)$validated['supplier_name']))->value('id');

            $purchase = Purchase::create([
                'reference_no'           => $refNo,
                'supplier_id'            => $resolvedSupplierId ? (int)$resolvedSupplierId : null,
                'supplier_name'          => $validated['supplier_name'],
                'warehouse_id'           => $validated['warehouse_id'] ?? $warehouseId,
                'warehouse_name'         => $validated['warehouse_name'],
                'date'                   => $txDate,
                'status'                 => $status,
                'tax_rate'               => $taxRate,
                'tax_amount'             => $taxAmount,
                'is_tax_recoverable'     => $isTaxRecoverable,
                'discount'               => $discount,
                'shipping'               => $shipping,
                'grand_total'            => $grandTotal,
                'paid_amount'            => $paidAmount,
                'due_amount'             => $dueAmount,
                'returned_credit_amount' => 0.0,
                'payment_status'         => $paymentStatus,
                'payment_type'           => $resolvedPaymentType,
                'cash_register_id'       => $cashRegisterId,
                'notes'                  => $validated['notes'] ?? null,
                'created_by'             => $actor['created_by'],
                'user_id'                => $actor['user_id'],
                'user_name'              => $actor['user_name'],
                'user_role'              => $actor['user_role'],
            ]);

            $netAdjustment = $isTaxRecoverable
                ? ($shipping - $discount)
                : ($shipping + $taxAmount - $discount);
            $costRatioPool = $isTaxRecoverable
                ? (float)$totals['tax_exclusive_base']
                : (float)$totals['subtotal'];

            $this->persistAndApplyPurchaseItems(
                $purchase,
                $totals['items'],
                $warehouseId,
                $purchase->status,
                $costRatioPool,
                $netAdjustment,
                (string)$purchase->date,
                $unitService,
                $fifoService,
                'Purchase received in ',
                $isTaxRecoverable
            );

            // Accounts Payable recognition occurs strictly when goods are Received (not on Pending/Ordered POs)
            if ($purchase->status === 'Received') {
                $this->syncSupplierBalanceDue(
                    null,
                    0.0,
                    !empty($purchase->supplier_id) ? (int)$purchase->supplier_id : null,
                    $dueAmount
                );
            }

            // Initialize payment transaction record(s) in payments_payload - supporting Split Payments & multi-tenders
            if ($hasPaymentsArray) {
                $savedPayments = $this->buildNormalizedPaymentsList(
                    $validated['payments'],
                    $grandTotal,
                    (string)($purchase->reference_no ?: $purchase->id),
                    $txDate,
                    $resolvedPaymentType,
                    $actor['user_name'],
                    $actor['user_role'],
                    (string)($validated['notes'] ?? ''),
                    $cashRegisterId
                );
                if (!empty($savedPayments)) {
                    $purchase->update(['payments_payload' => json_encode($savedPayments)]);
                }
            } elseif ($paidAmount > 0.001) {
                $receivedRaw = round(max($paidAmount, (float)($validated['paid_amount'] ?? $paidAmount)), 2);
                $initialPayment = [
                    'id'              => 1,
                    'reference_no'    => 'PAY-' . ($purchase->reference_no ?: $purchase->id) . '-01',
                    'payment_date'    => $txDate,
                    'payment_type'    => $resolvedPaymentType !== 'N/A' ? $resolvedPaymentType : 'Cash',
                    'amount'          => $paidAmount,
                    'received_amount' => $receivedRaw,
                    'change_amount'   => round(max(0.0, $receivedRaw - $paidAmount), 2),
                    'user_name'       => $actor['user_name'],
                    'user_role'       => $actor['user_role'],
                    'notes'           => $validated['notes'] ?? '',
                ];
                if ($cashRegisterId !== null && $accounting->isCashPaymentType($initialPayment['payment_type'] ?? null)) {
                    $initialPayment['cash_register_id'] = $cashRegisterId;
                }
                $purchase->update(['payments_payload' => json_encode([$initialPayment])]);
            }

            DB::commit();
            $this->clearPurchaseRelatedCaches();

            $loaded = $purchase->fresh()->load(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'supplier']);
            $accounting->maskPurchaseCostFields($loaded, $accounting->canViewCost($request));

            return response()->json([
                'status' => 'success',
                'message' => 'Purchase order created successfully',
                'data' => $loaded,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Apply stock + FIFO batches for already-persisted purchase items (used on status Pending/Ordered -> Received or warehouse transfer).
     */
    protected function applyExistingItemsToWarehouse(
        Purchase $purchase,
        ?int $warehouseId,
        float $netAdjustment,
        string $receivedDate,
        ProductUnitConversionService $unitService,
        FifoInventoryService $fifoService,
        string $stockNote,
        string $batchNote,
        bool $isTaxRecoverable = false
    ): void {
        $purchase->loadMissing('items');
        $costRatioPool = $isTaxRecoverable
            ? round((float)$purchase->items->sum(fn($it) => max(0.0, ((float)$it->unit_cost * (float)$it->quantity) - (float)$it->discount)), 2)
            : round((float)$purchase->items->sum('subtotal'), 2);
        $lineBases = [];
        foreach ($purchase->items as $idx => $item) {
            $taxExclLine = max(0.0, ((float)$item->unit_cost * (float)$item->quantity) - (float)$item->discount);
            $lineBases[$idx] = $isTaxRecoverable ? $taxExclLine : (float)$item->subtotal;
        }
        $landedMap = $this->accounting()->allocateLineLandedCosts($lineBases, $costRatioPool, $netAdjustment);

        foreach ($purchase->items as $idx => $item) {
            $product = !empty($item->product_id)
                ? Product::lockForUpdate()->find($item->product_id)
                : Product::lockForUpdate()->where('code', $item->product_code)->first();
            $variantId = $item->product_variant_id;
            $baseQty = (float)($item->base_quantity ?: $item->quantity);
            $baseUnitCost = (float)$item->base_unit_cost;

            if ($product) {
                $precomputedLandedTotal = (float)($landedMap[$idx]['landed_total'] ?? $lineBases[$idx]);
                $this->applyReceivedItemStockCostAndBatch(
                    $purchase,
                    $product,
                    $warehouseId,
                    $variantId,
                    (float)$baseQty,
                    (float)$baseUnitCost,
                    $precomputedLandedTotal,
                    $receivedDate,
                    (int)$item->id,
                    $stockNote,
                    $batchNote,
                    $unitService,
                    $fifoService
                );
            }
        }
    }

    /**
     * Reconcile line items, stock balances, and FIFO InventoryBatches on an existing Received Purchase
     * without changing warehouse. Preserves consumed FIFO batches (and cost layer FKs) when keeping or
     * increasing quantities, while blocking qty reductions below consumed units or cost edits on consumed batches.
     *
     * @return string|null Error message if an edit violates FIFO batch integrity (422), or null on success.
     */
    protected function reconcileUpdatedReceivedPurchaseItems(
        Purchase $purchase,
        array $normalizedItems,
        ?int $warehouseId,
        float $costRatioPool,
        float $netAdjustment,
        string $receivedDate,
        ProductUnitConversionService $unitService,
        FifoInventoryService $fifoService,
        bool $isTaxRecoverable,
        bool $canViewCost
    ): ?string {
        $purchase->loadMissing('items');
        $existingItems = $purchase->items;
        $existingBatches = \App\Models\InventoryBatch::whereIn('purchase_item_id', $existingItems->pluck('id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('purchase_item_id');

        $anyConsumed = false;
        $batchByItemId = [];
        $consumedByItemId = [];
        $hasLayersByItemId = [];
        foreach ($existingItems as $exItem) {
            $batch = $existingBatches->get($exItem->id);
            if (!$batch && $warehouseId) {
                $bq = \App\Models\InventoryBatch::where('warehouse_id', (int)$warehouseId)
                    ->where('product_id', $exItem->product_id)
                    ->where('source_type', 'purchase')
                    ->where('source_reference', $purchase->reference_no);
                if (!empty($exItem->product_variant_id)) {
                    $bq->where('product_variant_id', (int)$exItem->product_variant_id);
                } else {
                    $bq->whereNull('product_variant_id');
                }
                $batch = $bq->lockForUpdate()->first();
            }
            if ($batch) {
                $batchByItemId[$exItem->id] = $batch;
                $consumed = max(0.0, round((float)$batch->initial_quantity - (float)$batch->remaining_quantity, 4));
                $hasLayers = \App\Models\SaleItemCostLayer::where('inventory_batch_id', $batch->id)->exists()
                    || \App\Models\AdjustmentItemCostLayer::where('inventory_batch_id', $batch->id)->exists()
                    || \App\Models\PurchaseReturnCostLayer::where('inventory_batch_id', $batch->id)->exists();
                $consumedByItemId[$exItem->id] = $consumed;
                $hasLayersByItemId[$exItem->id] = $hasLayers;
                if ($consumed > 0.0001 || $hasLayers) {
                    $anyConsumed = true;
                }
            } else {
                $consumedByItemId[$exItem->id] = 0.0;
                $hasLayersByItemId[$exItem->id] = false;
            }
        }

        if (!$anyConsumed) {
            $this->revertPurchaseStockAndBatches(
                $purchase,
                $warehouseId,
                $unitService,
                'purchase_edit',
                'Revert stock for purchase edit'
            );
            $purchase->items()->delete();
            $this->persistAndApplyPurchaseItems(
                $purchase,
                $normalizedItems,
                $warehouseId,
                'Received',
                $costRatioPool,
                $netAdjustment,
                $receivedDate,
                $unitService,
                $fifoService,
                'Purchase edited/received in ',
                $isTaxRecoverable
            );
            return null;
        }

        $itemProductIds = collect($normalizedItems)->pluck('product_id')->filter()->unique()->values()->all();
        $itemProductCodes = collect($normalizedItems)->pluck('product_code')->filter()->unique()->values()->all();
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

        $lineBases = [];
        foreach ($normalizedItems as $idx => $item) {
            $qty = (float)$item['quantity'];
            $taxExcl = (float)($item['tax_exclusive_subtotal'] ?? max(0.0, ((float)$item['unit_cost'] * $qty) - (float)$item['discount']));
            $lineBases[$idx] = $isTaxRecoverable ? $taxExcl : (float)$item['subtotal'];
        }
        $landedMap = $this->accounting()->allocateLineLandedCosts($lineBases, $costRatioPool, $netAdjustment);
        $resolvedIncoming = [];
        $incomingBaseQtyByKey = [];

        foreach ($normalizedItems as $idx => $item) {
            $product = (!empty($item['product_id']) && isset($productsById[$item['product_id']]))
                ? $productsById[$item['product_id']]
                : ($productsByCode[$item['product_code']] ?? null);
            $variantId = !empty($item['product_variant_id']) ? (int)$item['product_variant_id'] : null;
            if ($product && $variantId && !$product->variants()->whereKey($variantId)->exists()) {
                return 'Selected variant does not belong to the product.';
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
            $landedTotal = (float)($landedMap[$idx]['landed_total'] ?? $lineBases[$idx]);
            $landedBaseUnitCost = $baseQuantity > 0.0001 ? round($landedTotal / $baseQuantity, 8) : $baseUnitCost;

            $pidResolved = $product?->id ?? (!empty($item['product_id']) ? (int)$item['product_id'] : 0);
            $skuKey = $pidResolved . '_' . ($variantId ?: 0);
            $incomingBaseQtyByKey[$skuKey] = ($incomingBaseQtyByKey[$skuKey] ?? 0.0) + $baseQuantity;

            $resolvedIncoming[] = [
                'raw' => $item,
                'product' => $product,
                'variant_id' => $variantId,
                'linked_unit' => $linkedUnit,
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'base_unit_cost' => $baseUnitCost,
                'landed_base_unit_cost' => $landedBaseUnitCost,
                'sku_key' => $skuKey,
            ];
        }

        $blockMsg = $fifoService->assertPurchaseBatchesCanModifyOrDelete($purchase, $incomingBaseQtyByKey, false);
        if ($blockMsg !== null) {
            return $blockMsg;
        }

        $sortedExisting = $existingItems->sortByDesc(fn($ex) => $consumedByItemId[$ex->id] ?? 0.0)->values();
        $unmatchedIncoming = $resolvedIncoming;
        $pairedPairs = [];
        $removedOldItems = [];

        foreach ($sortedExisting as $exItem) {
            $exSkuKey = ((int)$exItem->product_id) . '_' . (!empty($exItem->product_variant_id) ? (int)$exItem->product_variant_id : 0);
            $matchedIdx = null;
            foreach ($unmatchedIncoming as $idx => $inc) {
                if ($inc['sku_key'] === $exSkuKey) {
                    $matchedIdx = $idx;
                    break;
                }
            }
            if ($matchedIdx !== null) {
                $pairedPairs[] = ['old' => $exItem, 'inc' => $unmatchedIncoming[$matchedIdx]];
                unset($unmatchedIncoming[$matchedIdx]);
            } else {
                if (($consumedByItemId[$exItem->id] ?? 0.0) > 0.0001 || ($hasLayersByItemId[$exItem->id] ?? false)) {
                    $pName = $exItem->product_name ?: ('Product #' . $exItem->product_id);
                    return "Cannot remove '{$pName}' from Purchase #{$purchase->reference_no} because its FIFO batch has already been consumed.";
                }
                $removedOldItems[] = $exItem;
            }
        }

        foreach ($pairedPairs as $pair) {
            $oldItem = $pair['old'];
            $inc = $pair['inc'];
            $batch = $batchByItemId[$oldItem->id] ?? null;
            $consumed = $consumedByItemId[$oldItem->id] ?? 0.0;
            $hasLayers = $hasLayersByItemId[$oldItem->id] ?? false;

            if ($consumed > 0.0001 || $hasLayers) {
                if ($inc['base_quantity'] < $consumed - 0.0001) {
                    $pName = $oldItem->product_name ?: ('Product #' . $oldItem->product_id);
                    return "Cannot reduce quantity of '{$pName}' below {$consumed} base units because that quantity has already been sold, adjusted, or returned from this FIFO batch.";
                }
                if ($canViewCost && $batch) {
                    $oldUnitCost = (float)$oldItem->unit_cost;
                    $newUnitCost = (float)$inc['raw']['unit_cost'];
                    if (abs($newUnitCost - $oldUnitCost) > 0.01) {
                        $pName = $oldItem->product_name ?: ('Product #' . $oldItem->product_id);
                        return "Cannot modify unit cost of '{$pName}' on Purchase #{$purchase->reference_no} because FIFO layers from this batch have already been consumed.";
                    }
                }
            }
        }

        $affectedKeys = [];

        foreach ($pairedPairs as $pair) {
            $oldItem = $pair['old'];
            $inc = $pair['inc'];
            $item = $inc['raw'];
            $product = $inc['product'];
            $variantId = $inc['variant_id'];
            $linkedUnit = $inc['linked_unit'];
            $baseQty = (float)$inc['base_quantity'];
            $baseUnitCost = (float)$inc['base_unit_cost'];
            $landedCost = (float)$inc['landed_base_unit_cost'];
            $oldBaseQty = (float)($oldItem->base_quantity ?: $oldItem->quantity);
            $qtyDelta = round($baseQty - $oldBaseQty, 4);

            $oldItem->update([
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
                'quantity' => (float)$inc['quantity'],
                'base_quantity' => $baseQty,
                'tax' => (float)$item['tax'],
                'discount' => (float)$item['discount'],
                'subtotal' => (float)$item['subtotal'],
            ]);

            if ($product) {
                $affectedKeys[$product->id . '|' . ($variantId ?? 0)] = [(int)$product->id, $variantId];
                $effectiveMovementCost = $landedCost > 0.0001 ? $landedCost : $baseUnitCost;
                if ($qtyDelta > 0.0001) {
                    $unitService->applyStock(
                        $product,
                        $warehouseId,
                        $variantId,
                        $qtyDelta,
                        'in',
                        'purchase_edit',
                        'purchase',
                        (string)$purchase->id,
                        'Purchase edited (+qty) in ' . $linkedUnit['unit_name'],
                        true,
                        false,
                        true,
                        $effectiveMovementCost > 0 ? $effectiveMovementCost : null,
                        $receivedDate
                    );
                } elseif ($qtyDelta < -0.0001) {
                    $unitService->applyStock(
                        $product,
                        $warehouseId,
                        $variantId,
                        abs($qtyDelta),
                        'out',
                        'purchase_edit',
                        'purchase',
                        (string)$purchase->id,
                        'Purchase edited (-qty) in ' . $linkedUnit['unit_name'],
                        true,
                        false,
                        true,
                        $effectiveMovementCost > 0 ? $effectiveMovementCost : null,
                        $receivedDate
                    );
                }

                $batch = $batchByItemId[$oldItem->id] ?? null;
                $consumed = $consumedByItemId[$oldItem->id] ?? 0.0;
                if ($batch) {
                    $newRemaining = max(0.0, round($baseQty - $consumed, 4));
                    $batchUpdates = [
                        'initial_quantity' => $baseQty,
                        'remaining_quantity' => $newRemaining,
                        'status' => $newRemaining > 0.0001 ? 'active' : 'exhausted',
                        'received_date' => $receivedDate,
                    ];
                    if ($consumed <= 0.0001) {
                        $batchUpdates['unit_cost'] = $landedCost;
                    }
                    $batch->update($batchUpdates);
                } else {
                    $fifoService->recordBatch(
                        warehouseId: (int)$warehouseId,
                        productId: (int)$product->id,
                        variantId: $variantId,
                        baseQuantity: $baseQty,
                        baseUnitCost: $landedCost,
                        sourceType: 'purchase',
                        sourceReference: (string)$purchase->reference_no,
                        receivedDate: $receivedDate,
                        purchaseItemId: (int)$oldItem->id,
                        notes: "Purchase #{$purchase->reference_no} updated"
                    );
                }
            }
        }

        foreach ($removedOldItems as $remItem) {
            $remProd = !empty($remItem->product_id)
                ? ($productsById[$remItem->product_id] ?? Product::lockForUpdate()->find($remItem->product_id))
                : Product::lockForUpdate()->where('code', $remItem->product_code)->first();
            $remVid = !empty($remItem->product_variant_id) ? (int)$remItem->product_variant_id : null;
            if ($remProd) {
                $affectedKeys[$remProd->id . '|' . ($remVid ?? 0)] = [(int)$remProd->id, $remVid];
                $remBaseQty = (float)($remItem->base_quantity ?: $remItem->quantity);
                $remBatch = $batchByItemId[$remItem->id] ?? null;
                $remUnitCost = ($remBatch && (float)$remBatch->unit_cost > 0)
                    ? (float)$remBatch->unit_cost
                    : (float)($remItem->base_unit_cost ?: $remItem->unit_cost);
                $unitService->applyStock(
                    $remProd,
                    $warehouseId,
                    $remVid,
                    $remBaseQty,
                    'out',
                    'purchase_edit',
                    'purchase',
                    (string)$purchase->id,
                    'Removed line item during purchase edit',
                    true,
                    false,
                    true,
                    $remUnitCost > 0 ? $remUnitCost : null,
                    $receivedDate
                );
            }
            if (isset($batchByItemId[$remItem->id])) {
                $batchByItemId[$remItem->id]->delete();
            }
            $remItem->delete();
        }

        foreach ($unmatchedIncoming as $inc) {
            $item = $inc['raw'];
            $product = $inc['product'];
            $variantId = $inc['variant_id'];
            $linkedUnit = $inc['linked_unit'];
            $baseQty = (float)$inc['base_quantity'];
            $baseUnitCost = (float)$inc['base_unit_cost'];
            $landedCost = (float)$inc['landed_base_unit_cost'];

            $newItem = PurchaseItem::create([
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
                'quantity' => (float)$inc['quantity'],
                'base_quantity' => $baseQty,
                'tax' => (float)$item['tax'],
                'discount' => (float)$item['discount'],
                'subtotal' => (float)$item['subtotal'],
            ]);

            if ($product) {
                $affectedKeys[$product->id . '|' . ($variantId ?? 0)] = [(int)$product->id, $variantId];
                $newLineCost = $landedCost > 0.0001 ? $landedCost : $baseUnitCost;
                $unitService->applyStock(
                    $product,
                    $warehouseId,
                    $variantId,
                    $baseQty,
                    'in',
                    'purchase_edit',
                    'purchase',
                    (string)$purchase->id,
                    'Purchase edited (+new line) in ' . $linkedUnit['unit_name'],
                    true,
                    false,
                    true,
                    $newLineCost > 0 ? $newLineCost : null,
                    $receivedDate
                );
                $fifoService->recordBatch(
                    warehouseId: (int)$warehouseId,
                    productId: (int)$product->id,
                    variantId: $variantId,
                    baseQuantity: $baseQty,
                    baseUnitCost: $landedCost,
                    sourceType: 'purchase',
                    sourceReference: (string)$purchase->reference_no,
                    receivedDate: $receivedDate,
                    purchaseItemId: (int)$newItem->id,
                    notes: "Purchase #{$purchase->reference_no} added line"
                );
            }
        }

        foreach ($affectedKeys as [$pId, $vId]) {
            $latestBatch = \App\Models\InventoryBatch::where('product_id', $pId)
                ->when(
                    $vId !== null,
                    fn($q) => $q->where('product_variant_id', $vId),
                    fn($q) => $q->whereNull('product_variant_id')
                )
                ->orderByDesc('received_date')
                ->orderByDesc('id')
                ->first();
            if ($latestBatch && (float)$latestBatch->unit_cost > 0.0001) {
                $this->syncMasterProductCost($pId, $vId, (float)$latestBatch->unit_cost);
            }
        }

        return null;
    }

    /**
     * Shared stock IN, master cost sync (for positive base or allocated landed cost),
     * and FIFO batch recording for a received purchase line item.
     */
    private function applyReceivedItemStockCostAndBatch(
        Purchase $purchase,
        Product $product,
        ?int $warehouseId,
        ?int $variantId,
        float $baseQty,
        float $baseUnitCost,
        float $landedTotal,
        string $receivedDate,
        int $purchaseItemId,
        string $stockNote,
        string $batchNote,
        ProductUnitConversionService $unitService,
        FifoInventoryService $fifoService
    ): void {
        $landedBaseUnitCost = $baseQty > 0.0001 ? round(max(0.0, $landedTotal) / $baseQty, 8) : $baseUnitCost;

        $unitService->applyStock(
            $product,
            $warehouseId,
            $variantId,
            $baseQty,
            'in',
            'purchase',
            'purchase',
            (string)$purchase->id,
            $stockNote,
            true,
            false,
            true,
            $landedBaseUnitCost > 0.0001 ? $landedBaseUnitCost : (float)$baseUnitCost,
            $receivedDate
        );

        if ($landedBaseUnitCost > 0.0001 || $baseUnitCost > 0.0001) {
            $hasNewerBatch = \App\Models\InventoryBatch::where('product_id', $product->id)
                ->when(
                    !empty($variantId),
                    fn($q) => $q->where('product_variant_id', (int)$variantId),
                    fn($q) => $q->whereNull('product_variant_id')
                )
                ->where('received_date', '>', $receivedDate)
                ->exists();
            if (!$hasNewerBatch) {
                $effectiveMasterCost = $landedBaseUnitCost > 0.0001 ? $landedBaseUnitCost : (float)$baseUnitCost;
                $this->syncMasterProductCost($product->id, $variantId, $effectiveMasterCost);
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
            receivedDate: $receivedDate,
            purchaseItemId: $purchaseItemId,
            notes: $batchNote
        );
    }

    /**
     * Synchronize paid_amount, due_amount, payment_status, payment_type, and payments_payload on purchase update.
     *
     * @return array{paid_amount: float, due_amount: float, payment_status: string, payment_type: ?string, payments_payload: string}
     */
    protected function resolveUpdatedPayments(
        Purchase $purchase,
        array $validated,
        float $grandTotal,
        string $txDate,
        string $userName,
        string $userRole,
        ?int $cashRegisterId = null,
        ?int $newCashRegisterId = null
    ): array {
        $accounting = $this->accounting();
        $existingPayments = $purchase->payments;
        $notesFallback = (string)($validated['notes'] ?? $purchase->notes ?? '');
        $creditApplied = round(max(0.0, (float)$purchase->returned_credit_amount), 2);
        $maxPayablePool = round(max(0.0, $grandTotal - $creditApplied), 2);
        $effectiveNewCashRegId = $newCashRegisterId ?? $cashRegisterId;

        if (array_key_exists('payments', $validated) && is_array($validated['payments'])) {
            $derivedType = $this->resolveDerivedPaymentType(
                $validated['payments'],
                $validated['payment_type'] ?? ($purchase->payment_type ?: 'Cash')
            );
            $savedPayments = $this->buildNormalizedPaymentsList(
                $validated['payments'],
                $maxPayablePool,
                (string)($purchase->reference_no ?: $purchase->id),
                $txDate,
                $derivedType,
                $userName,
                $userRole,
                $notesFallback,
                $effectiveNewCashRegId
            );
            $rawPaid = (float)collect($savedPayments)->sum('amount');
            [$paidAmount, $dueAmount, $paymentStatus] = $this->applyPurchasePaymentSummary($grandTotal, $rawPaid, $creditApplied);
            $finalType = $paidAmount <= 0.001
                ? ($validated['payment_type'] ?? 'N/A')
                : $this->resolveDerivedPaymentType($savedPayments, $derivedType);

            return [
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
                'payment_type' => $finalType,
                'payments_payload' => json_encode($savedPayments),
            ];
        }

        if (array_key_exists('paid_amount', $validated) && $validated['paid_amount'] !== null) {
            $requestedPaid = (float)$validated['paid_amount'];
            [$paidAmount, $dueAmount, $paymentStatus] = $this->applyPurchasePaymentSummary($grandTotal, $requestedPaid, $creditApplied);
            $preferredType = $validated['payment_type'] ?? ($purchase->payment_type ?: 'Cash');
            $cleanRef = preg_replace('/^#\s*/', '', trim((string)($purchase->reference_no ?: $purchase->id)));

            if ($paidAmount <= 0.001) {
                $savedPayments = [];
                $finalType = $validated['payment_type'] ?? 'N/A';
            } else {
                $existingSum = round((float)collect($existingPayments)->sum('amount'), 2);
                if (!empty($existingPayments) && abs($existingSum - $paidAmount) <= 0.001) {
                    $savedPayments = array_values($existingPayments);
                } elseif (!empty($existingPayments) && $paidAmount > $existingSum) {
                    $savedPayments = array_map(function ($row) use ($purchase) {
                        if (is_array($row) && !array_key_exists('cash_register_id', $row) && !empty($purchase->cash_register_id)) {
                            $row['cash_register_id'] = (int)$purchase->cash_register_id;
                        }
                        return $row;
                    }, array_values($existingPayments));
                    $delta = round($paidAmount - $existingSum, 2);
                    $nextId = $accounting->nextPaymentId($savedPayments);
                    $lineType = $preferredType !== 'N/A' && $preferredType !== 'Split Payment' ? $preferredType : 'Cash';
                    $newRow = [
                        'id' => $nextId,
                        'reference_no' => 'PAY-' . ($cleanRef ?: $purchase->id) . '-' . str_pad((string)$nextId, 2, '0', STR_PAD_LEFT) . '-' . time(),
                        'payment_date' => $txDate,
                        'payment_type' => $lineType,
                        'amount' => $delta,
                        'received_amount' => $delta,
                        'change_amount' => 0.0,
                        'user_name' => $userName,
                        'user_role' => $userRole,
                        'notes' => $notesFallback,
                    ];
                    if ($effectiveNewCashRegId !== null && $accounting->isCashPaymentType($lineType)) {
                        $newRow['cash_register_id'] = $effectiveNewCashRegId;
                    }
                    $savedPayments[] = $newRow;
                } else {
                    if (!empty($existingPayments)) {
                        $normalizedOld = array_values(array_map(function ($row) use ($purchase, $accounting) {
                            if (is_array($row) && !array_key_exists('cash_register_id', $row) && !empty($purchase->cash_register_id)
                                && $accounting->isCashPaymentType($row['payment_type'] ?? null)) {
                                $row['cash_register_id'] = (int)$purchase->cash_register_id;
                            }
                            return $row;
                        }, $existingPayments));
                        $openRegIds = [];
                        $regIdsToCheck = collect($normalizedOld)->pluck('cash_register_id')->filter()->unique()->values()->all();
                        if (!empty($regIdsToCheck) && \Illuminate\Support\Facades\Schema::hasTable('cash_registers')) {
                            $openRegIds = \App\Models\CashRegister::whereIn('id', $regIdsToCheck)
                                ->whereRaw("LOWER(COALESCE(status, '')) = 'open'")
                                ->pluck('id')
                                ->flip()
                                ->all();
                        }
                        $excess = round($existingSum - $paidAmount, 2);
                        foreach ([false, true] as $includeClosedRegs) {
                            for ($i = count($normalizedOld) - 1; $i >= 0 && $excess > 0.001; $i--) {
                                if (!isset($normalizedOld[$i]) || !is_array($normalizedOld[$i])) {
                                    continue;
                                }
                                $legAmt = round((float)($normalizedOld[$i]['amount'] ?? 0.0), 2);
                                if ($legAmt <= 0.001) {
                                    unset($normalizedOld[$i]);
                                    continue;
                                }
                                $legRegId = !empty($normalizedOld[$i]['cash_register_id']) ? (int)$normalizedOld[$i]['cash_register_id'] : null;
                                $isCashLeg = $accounting->isCashPaymentType($normalizedOld[$i]['payment_type'] ?? null);
                                $isClosedCash = $isCashLeg && $legRegId !== null && !isset($openRegIds[$legRegId]);
                                if (!$includeClosedRegs && $isClosedCash) {
                                    continue;
                                }
                                $cut = min($legAmt, $excess);
                                $rem = round($legAmt - $cut, 2);
                                $excess = round($excess - $cut, 2);
                                if ($rem <= 0.001) {
                                    unset($normalizedOld[$i]);
                                } else {
                                    $normalizedOld[$i]['amount'] = $rem;
                                    if (isset($normalizedOld[$i]['received_amount'])) {
                                        $normalizedOld[$i]['received_amount'] = $rem;
                                    }
                                    $normalizedOld[$i]['change_amount'] = 0.0;
                                }
                            }
                        }
                        $savedPayments = array_values($normalizedOld);
                    } else {
                        $receivedRaw = round(max($paidAmount, $requestedPaid), 2);
                        $singleType = ($preferredType !== 'N/A' && $preferredType !== 'Split Payment') ? $preferredType : 'Cash';
                        $singleRow = [
                            'id' => 1,
                            'reference_no' => 'PAY-' . ($cleanRef ?: $purchase->id) . '-01',
                            'payment_date' => $txDate,
                            'payment_type' => $singleType,
                            'amount' => $paidAmount,
                            'received_amount' => $receivedRaw,
                            'change_amount' => round(max(0.0, $receivedRaw - $paidAmount), 2),
                            'user_name' => $userName,
                            'user_role' => $userRole,
                            'notes' => $notesFallback,
                        ];
                        if ($effectiveNewCashRegId !== null && $accounting->isCashPaymentType($singleType)) {
                            $singleRow['cash_register_id'] = $effectiveNewCashRegId;
                        }
                        $savedPayments = [$singleRow];
                    }
                }
                $finalType = $this->resolveDerivedPaymentType($savedPayments, $preferredType);
            }

            return [
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
                'payment_type' => $finalType,
                'payments_payload' => json_encode($savedPayments),
            ];
        }

        // Neither payments nor paid_amount specified: preserve existing payments & returned credits
        $existingPaid = (float)$purchase->paid_amount;
        [$paidAmount, $dueAmount, $paymentStatus] = $this->applyPurchasePaymentSummary($grandTotal, $existingPaid, $creditApplied);
        if (!empty($existingPayments) && $paidAmount < $existingPaid - 0.001) {
            $savedPayments = $this->buildNormalizedPaymentsList(
                $existingPayments,
                $maxPayablePool,
                (string)($purchase->reference_no ?: $purchase->id),
                $txDate,
                $purchase->payment_type ?: 'Cash',
                $userName,
                $userRole,
                $notesFallback,
                $cashRegisterId
            );
        } else {
            $savedPayments = $existingPayments;
        }

        return [
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_status' => $paymentStatus,
            'payment_type' => $validated['payment_type'] ?? $purchase->payment_type,
            'payments_payload' => json_encode(array_values($savedPayments)),
        ];
    }

    public function update(Request $request, $id)
    {
        $accounting = $this->accounting();
        $validated = $request->validate([
            'supplier_id'               => 'nullable|integer',
            'supplier_name'             => 'nullable|string|max:255',
            'warehouse_id'              => 'nullable|integer',
            'warehouse_name'            => 'nullable|string|max:255',
            'date'                      => 'nullable|string',
            'status'                    => 'nullable|string|in:Received,Pending,Ordered',
            'tax_rate'                  => 'nullable|numeric|min:0|max:100',
            'tax_amount'                => 'nullable|numeric|min:0',
            'is_tax_recoverable'        => 'nullable|boolean',
            'discount'                  => 'nullable|numeric|min:0',
            'shipping'                  => 'nullable|numeric|min:0',
            'subtotal'                  => 'nullable|numeric|min:0',
            'grand_total'               => 'nullable|numeric|min:0',
            'paid_amount'               => 'nullable|numeric|min:0',
            'payment_status'            => 'nullable|string',
            'payment_type'              => 'nullable|string',
            'cash_register_id'          => 'nullable|integer',
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
            'payments'                  => 'nullable|array',
            'payments.*.amount'         => 'nullable|numeric|min:0.01',
            'payments.*.payment_type'   => 'nullable|string',
            'payments.*.received_amount'=> 'nullable|numeric|min:0',
            'payments.*.change_amount'  => 'nullable|numeric|min:0',
            'payments.*.payment_date'   => 'nullable|string',
            'payments.*.notes'          => 'nullable|string',
            'payments.*.cash_register_id' => 'nullable|integer',
        ]);

        $unitService = app(ProductUnitConversionService::class);
        $fifoService = app(FifoInventoryService::class);
        $canViewCost = $accounting->canViewCost($request);

        DB::beginTransaction();
        try {
            $purchase = Purchase::with(['items', 'supplier'])
                ->where('reference_no', (string)$id)
                ->lockForUpdate()
                ->first()
                ?? Purchase::with(['items', 'supplier'])->lockForUpdate()->find($id);

            if (!$purchase) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
            }
            $accounting->assertOutletAccess(
                $request,
                $purchase->warehouse_id ? (int)$purchase->warehouse_id : null,
                $purchase->warehouse_name
            );

            if ($accounting->isOpeningStockPaymentType($purchase->payment_type)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Synthetic Opening Stock vouchers cannot be modified directly. Use Stock Adjustments to adjust opening stock.',
                ], 422);
            }
            if ($accounting->isOpeningStockPaymentType($validated['payment_type'] ?? null)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Opening Stock payment type is reserved for system opening stock records. Use Stock Adjustments for manual opening stock.',
                ], 422);
            }

            $oldStatus = $purchase->status;
            $newStatus = $validated['status'] ?? $oldStatus;
            $oldDue = (float)$purchase->due_amount;
            $oldApDue = in_array($oldStatus, ['Received', 'Completed'], true) ? $oldDue : 0.0;

            $oldSupplierId = $purchase->supplier_id ? (int)$purchase->supplier_id : null;
            $newSupplierId = array_key_exists('supplier_id', $validated)
                ? (!empty($validated['supplier_id']) ? (int)$validated['supplier_id'] : null)
                : $oldSupplierId;

            $oldWarehouseId = $unitService->warehouseId($purchase->warehouse_id, $purchase->warehouse_name);
            $newWarehouseId = $unitService->warehouseId(
                $validated['warehouse_id'] ?? $purchase->warehouse_id,
                $validated['warehouse_name'] ?? $purchase->warehouse_name
            );
            $warehouseChanged = ($oldWarehouseId !== $newWarehouseId);
            if ($warehouseChanged) {
                $accounting->assertOutletAccess(
                    $request,
                    $newWarehouseId,
                    $validated['warehouse_name'] ?? $purchase->warehouse_name
                );
            }
            $hasItemsPayload = !empty($validated['items']) && is_array($validated['items']);

            // Return / credit-note & depletion guards under lock
            if ($this->hasLinkedActiveReturnsOrAllocations($purchase)) {
                if ($oldSupplierId !== $newSupplierId) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cannot reassign supplier on Purchase #' . $purchase->reference_no . ' because active Purchase Return(s) or credit note offsets are linked to it. Void or delete those Purchase Returns first.',
                    ], 422);
                }
                if ($oldStatus === 'Received' && (in_array($newStatus, ['Pending', 'Ordered'], true) || $warehouseChanged)) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cannot revert status or change warehouse on Purchase #' . $purchase->reference_no . ' because active Purchase Return(s) or credit note offsets are linked to it. Void or delete those Purchase Returns first.',
                    ], 422);
                }
            }

            if ($oldStatus === 'Received' && (in_array($newStatus, ['Pending', 'Ordered']) || ($newStatus === 'Received' && $warehouseChanged))) {
                $batchBlockMsg = $fifoService->assertPurchaseBatchesCanModifyOrDelete($purchase, null, true);
                if ($batchBlockMsg !== null) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $batchBlockMsg], 422);
                }
            }

            // Preserve historical item costs, taxes, discounts & block new unpriced items for cost-blind editors
            $incomingItems = $hasItemsPayload ? $validated['items'] : [];
            if ($hasItemsPayload && !$canViewCost) {
                $preserved = $accounting->applyCostBlindItemPreservation($incomingItems, $purchase->items, false);
                if ($preserved['error'] !== null) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => $preserved['error'],
                    ], 422);
                }
                $incomingItems = $preserved['items'];
            }

            $isTaxRecoverable = $canViewCost
                ? $accounting->resolveTaxRecoverability($validated, $purchase)
                : (bool)$purchase->is_tax_recoverable;
            $discountInput = ($canViewCost && array_key_exists('discount', $validated) && $validated['discount'] !== null)
                ? (float)$validated['discount']
                : (float)$purchase->discount;
            $shippingInput = ($canViewCost && array_key_exists('shipping', $validated) && $validated['shipping'] !== null)
                ? (float)$validated['shipping']
                : (float)$purchase->shipping;
            $oldTaxRate = (float)$purchase->tax_rate;
            $taxRateInput = ($canViewCost && array_key_exists('tax_rate', $validated) && $validated['tax_rate'] !== null)
                ? (float)$validated['tax_rate']
                : $oldTaxRate;

            if ($canViewCost && array_key_exists('tax_amount', $validated) && $validated['tax_amount'] !== null) {
                $explicitTaxAmount = (float)$validated['tax_amount'];
            } elseif ($oldTaxRate <= 0.0001 && $taxRateInput <= 0.0001) {
                $explicitTaxAmount = (float)$purchase->tax_amount;
            } else {
                $explicitTaxAmount = null;
            }

            if ($hasItemsPayload) {
                $totals = $accounting->calculateDocumentTotals(
                    $incomingItems,
                    $discountInput,
                    $taxRateInput,
                    $explicitTaxAmount,
                    $shippingInput,
                    $isTaxRecoverable
                );
            } else {
                $purchase->loadMissing('items');
                $itemsForCalc = $purchase->items->map(fn($it) => [
                    'product_id' => $it->product_id,
                    'product_variant_id' => $it->product_variant_id,
                    'unit_cost' => (float)$it->unit_cost,
                    'quantity' => (float)$it->quantity,
                    'conversion_rate' => (float)($it->conversion_rate ?: 1.0),
                    'tax' => (float)$it->tax,
                    'discount' => (float)$it->discount,
                    'subtotal' => (float)$it->subtotal,
                ])->toArray();
                $fallbackSub = isset($validated['subtotal'])
                    ? (float)$validated['subtotal']
                    : max(0.0, round((float)$purchase->grand_total - (float)$purchase->tax_amount - (float)$purchase->shipping + (float)$purchase->discount, 2));

                $totals = $accounting->calculateDocumentTotals(
                    $itemsForCalc,
                    $discountInput,
                    $taxRateInput,
                    $explicitTaxAmount,
                    $shippingInput,
                    $isTaxRecoverable,
                    false,
                    empty($itemsForCalc) ? $fallbackSub : null
                );
            }

            $discount = $totals['discount'];
            $shipping = $totals['shipping'];
            $taxRate = $totals['tax_rate'];
            $taxAmount = $totals['tax_amount'];
            $grandTotal = $totals['grand_total'];

            $creditApplied = round(max(0.0, (float)$purchase->returned_credit_amount), 2);
            if ($creditApplied > 0.001 && $grandTotal < $creditApplied - 0.01) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => "Cannot reduce purchase grand total below applied Purchase Return credit notes ({$creditApplied}). Void or update linked Purchase Returns first.",
                ], 422);
            }

            $beforeCashByReg = $accounting->extractDocumentCashByRegister($purchase, ['Received']);
            $actor = $accounting->resolveActorForUpdate($request, $validated, $purchase);
            $isNewStatusReceived = ($newStatus === 'Received');

            $hasPaymentsInput = $isNewStatusReceived && $canViewCost && !empty($validated['payments']) && is_array($validated['payments']);
            if ($hasPaymentsInput) {
                $splitRegErr = $accounting->validateSplitTenderCashRegisters(
                    $validated['payments'],
                    is_array($purchase->payments) ? $purchase->payments : []
                );
                if ($splitRegErr !== null) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $splitRegErr], 422);
                }
            }

            $effectivePayType = !$isNewStatusReceived
                ? 'N/A'
                : ($hasPaymentsInput
                    ? $this->resolveDerivedPaymentType(
                        $validated['payments'],
                        $validated['payment_type'] ?? ($purchase->payment_type ?: 'Cash')
                    )
                    : ($canViewCost
                        ? ($validated['payment_type'] ?? ($purchase->payment_type ?: 'Cash'))
                        : ($purchase->payment_type ?: 'N/A')));
            $probeRegAmount = ($isNewStatusReceived && !$canViewCost) ? 0.0 : null;

            $cashRegisterId = null;
            $newCashRegisterId = null;
            if ($isNewStatusReceived) {
                $explicitRegInput = !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null;
                $probePayType = $hasPaymentsInput
                    ? (collect($validated['payments'])->contains(fn($t) => $accounting->isCashPaymentType($t['payment_type'] ?? null))
                        ? 'Cash'
                        : (string)($validated['payments'][0]['payment_type'] ?? 'Bank Transfer'))
                    : $effectivePayType;
                $registerCheck = $accounting->resolveCashRegisterId(
                    $explicitRegInput,
                    $actor['user'],
                    $newWarehouseId,
                    $probePayType,
                    $probeRegAmount
                );
                if ($registerCheck['error']) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $registerCheck['error']], 422);
                }

                $existingHeaderRegOpen = false;
                if (!empty($purchase->cash_register_id) && \Illuminate\Support\Facades\Schema::hasTable('cash_registers')) {
                    $existingReg = \App\Models\CashRegister::find((int)$purchase->cash_register_id);
                    $existingHeaderRegOpen = $existingReg && strtolower((string)$existingReg->status) === 'open';
                }

                if ($explicitRegInput !== null) {
                    $cashRegisterId = $registerCheck['cash_register_id'];
                    $newCashRegisterId = $registerCheck['cash_register_id'];
                } else {
                    $newCashRegisterId = $existingHeaderRegOpen
                        ? (int)$purchase->cash_register_id
                        : $registerCheck['cash_register_id'];
                    $cashRegisterId = !empty($beforeCashByReg)
                        ? ($purchase->cash_register_id ? (int)$purchase->cash_register_id : $newCashRegisterId)
                        : $newCashRegisterId;
                }
            }

            $txDate = !empty($validated['date'])
                ? $accounting->normalizeTransactionDate($validated['date'], (string)$purchase->date)
                : (string)$purchase->date;

            $oldNetAdjustment = ((bool)$purchase->is_tax_recoverable)
                ? ((float)$purchase->shipping - (float)$purchase->discount)
                : ((float)$purchase->shipping + (float)$purchase->tax_amount - (float)$purchase->discount);
            $newNetAdjustment = $isTaxRecoverable
                ? ($shipping - $discount)
                : ($shipping + $taxAmount - $discount);
            $oldDateDay = substr((string)$purchase->date, 0, 10);
            $newDateDay = substr((string)$txDate, 0, 10);
            $dateChanged = !empty($validated['date'])
                && ($newDateDay !== $oldDateDay || (str_contains((string)$validated['date'], ':') && $txDate !== (string)$purchase->date));

            $headerCostOrDateChanged = abs($newNetAdjustment - $oldNetAdjustment) > 0.01
                || $isTaxRecoverable !== (bool)$purchase->is_tax_recoverable
                || abs((float)$purchase->shipping - (float)$shipping) > 0.01
                || abs((float)$purchase->discount - (float)$discount) > 0.01
                || (!$isTaxRecoverable && abs((float)$purchase->tax_amount - (float)$taxAmount) > 0.01)
                || $dateChanged;

            if ($oldStatus === 'Received' && $newStatus === 'Received' && !$hasItemsPayload && !$warehouseChanged && $headerCostOrDateChanged) {
                $batchBlockMsg = $fifoService->assertPurchaseBatchesCanModifyOrDelete($purchase, null, true);
                if ($batchBlockMsg !== null) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $batchBlockMsg], 422);
                }
            }

            if (!$isNewStatusReceived) {
                $paymentSync = [
                    'paid_amount' => 0.0,
                    'due_amount' => round($grandTotal, 2),
                    'payment_status' => 'Unpaid',
                    'payment_type' => 'N/A',
                    'payments_payload' => json_encode([]),
                ];
            } else {
                $paymentInputForSync = $canViewCost
                    ? $validated
                    : collect($validated)->except(['payments', 'paid_amount', 'payment_type'])->all();
                $paymentSync = $this->resolveUpdatedPayments(
                    $purchase,
                    $paymentInputForSync,
                    $grandTotal,
                    $txDate,
                    $actor['user_name'],
                    $actor['user_role'],
                    $cashRegisterId,
                    $newCashRegisterId
                );
            }

            $afterCashByReg = $accounting->extractDocumentCashByRegister((object)[
                'status' => $newStatus,
                'cash_register_id' => $cashRegisterId,
                'payment_type' => $paymentSync['payment_type'],
                'paid_amount' => $paymentSync['paid_amount'],
                'payments_payload' => $paymentSync['payments_payload'],
            ], ['Received']);
            $regDeltaErr = $accounting->assertCashRegisterDeltaMutable($beforeCashByReg, $afterCashByReg);
            if ($regDeltaErr !== null) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => $regDeltaErr], 422);
            }

            $netAdjustment = $isTaxRecoverable
                ? ($shipping - $discount)
                : ($shipping + $taxAmount - $discount);
            $costRatioPool = $isTaxRecoverable
                ? (float)$totals['tax_exclusive_base']
                : (float)$totals['subtotal'];

            // Stock and FIFO inventory reconciliation
            if ($hasItemsPayload) {
                if ($oldStatus === 'Received' && $newStatus === 'Received' && !$warehouseChanged) {
                    $reconcileErr = $this->reconcileUpdatedReceivedPurchaseItems(
                        $purchase,
                        $totals['items'],
                        $newWarehouseId,
                        $costRatioPool,
                        $netAdjustment,
                        $txDate,
                        $unitService,
                        $fifoService,
                        $isTaxRecoverable,
                        $canViewCost
                    );
                    if ($reconcileErr !== null) {
                        DB::rollBack();
                        return response()->json(['status' => 'error', 'message' => $reconcileErr], 422);
                    }
                } else {
                    if ($oldStatus === 'Received') {
                        $this->revertPurchaseStockAndBatches(
                            $purchase,
                            $oldWarehouseId,
                            $unitService,
                            'purchase_edit',
                            'Revert stock for purchase edit'
                        );
                    }
                    $purchase->items()->delete();
                    $this->persistAndApplyPurchaseItems(
                        $purchase,
                        $totals['items'],
                        $newWarehouseId,
                        $newStatus,
                        $costRatioPool,
                        $netAdjustment,
                        $txDate,
                        $unitService,
                        $fifoService,
                        'Purchase edited/received in ',
                        $isTaxRecoverable
                    );
                }
            } elseif ($oldStatus === 'Received' && in_array($newStatus, ['Pending', 'Ordered'])) {
                $this->revertPurchaseStockAndBatches(
                    $purchase,
                    $oldWarehouseId,
                    $unitService,
                    'purchase_reversion',
                    'Revert stock: status changed to ' . $newStatus
                );
            } elseif ($oldStatus !== 'Received' && $newStatus === 'Received') {
                $this->applyExistingItemsToWarehouse(
                    $purchase,
                    $newWarehouseId,
                    $netAdjustment,
                    $txDate,
                    $unitService,
                    $fifoService,
                    'Purchase status received',
                    "Purchase #{$purchase->reference_no} status changed to Received",
                    $isTaxRecoverable
                );
            } elseif ($oldStatus === 'Received' && $newStatus === 'Received') {
                if ($warehouseChanged) {
                    $this->revertPurchaseStockAndBatches(
                        $purchase,
                        $oldWarehouseId,
                        $unitService,
                        'purchase_edit',
                        'Revert stock for warehouse change'
                    );
                    $this->applyExistingItemsToWarehouse(
                        $purchase,
                        $newWarehouseId,
                        $netAdjustment,
                        $txDate,
                        $unitService,
                        $fifoService,
                        'Purchase moved to warehouse',
                        "Purchase #{$purchase->reference_no} moved to warehouse",
                        $isTaxRecoverable
                    );
                } elseif (
                    abs($taxAmount - (float)$purchase->tax_amount) > 0.001
                    || abs($discount - (float)$purchase->discount) > 0.001
                    || abs($shipping - (float)$purchase->shipping) > 0.001
                    || $isTaxRecoverable !== (bool)$purchase->is_tax_recoverable
                    || !empty($validated['date'])
                ) {
                    $purchase->loadMissing('items');
                    $lineBases = [];
                    foreach ($purchase->items as $idx => $item) {
                        $taxExclLine = max(0.0, ((float)$item->unit_cost * (float)$item->quantity) - (float)$item->discount);
                        $lineBases[$idx] = $isTaxRecoverable ? $taxExclLine : (float)$item->subtotal;
                    }
                    $landedMap = $accounting->allocateLineLandedCosts($lineBases, $costRatioPool, $netAdjustment);
                    $batchesByItem = \App\Models\InventoryBatch::whereIn('purchase_item_id', $purchase->items->pluck('id'))
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('purchase_item_id');

                    foreach ($purchase->items as $idx => $item) {
                        $batch = $batchesByItem->get($item->id);
                        if (!$batch) {
                            continue;
                        }
                        $updateBatch = ['received_date' => $txDate];
                        if ((float)$batch->remaining_quantity >= (float)$batch->initial_quantity - 0.0001) {
                            $baseQty = (float)($item->base_quantity ?: $item->quantity);
                            $landedTotal = (float)($landedMap[$idx]['landed_total'] ?? $lineBases[$idx]);
                            $updateBatch['unit_cost'] = $baseQty > 0.0001 ? round($landedTotal / $baseQty, 8) : (float)$item->base_unit_cost;
                        }
                        $batch->update($updateBatch);
                        if (isset($updateBatch['unit_cost']) && (float)$updateBatch['unit_cost'] > 0.0001 && !empty($item->product_id)) {
                            $hasNewerBatch = \App\Models\InventoryBatch::where('product_id', $item->product_id)
                                ->when(
                                    !empty($item->product_variant_id),
                                    fn($q) => $q->where('product_variant_id', $item->product_variant_id),
                                    fn($q) => $q->whereNull('product_variant_id')
                                )
                                ->where('id', '>', $batch->id)
                                ->exists();
                            if (!$hasNewerBatch) {
                                $this->syncMasterProductCost(
                                    (int)$item->product_id,
                                    !empty($item->product_variant_id) ? (int)$item->product_variant_id : null,
                                    (float)$updateBatch['unit_cost']
                                );
                            }
                        }
                    }
                    if ($dateChanged) {
                        \App\Models\StockMovement::where('reference_type', 'purchase')
                            ->where('reference_id', (string)$purchase->id)
                            ->where('movement_type', 'purchase')
                            ->update(['created_at' => $txDate]);
                    }
                }
            }

            $paidAmount = $paymentSync['paid_amount'];
            $dueAmount = $paymentSync['due_amount'];
            $paymentStatus = $paymentSync['payment_status'];

            $updateFields = [
                'supplier_id'        => $newSupplierId,
                'supplier_name'      => $validated['supplier_name'] ?? $purchase->supplier_name,
                'warehouse_id'       => $newWarehouseId,
                'warehouse_name'     => $validated['warehouse_name'] ?? $purchase->warehouse_name,
                'date'               => $txDate,
                'status'             => $newStatus,
                'tax_rate'           => $taxRate,
                'tax_amount'         => $taxAmount,
                'is_tax_recoverable' => $isTaxRecoverable,
                'discount'           => $discount,
                'shipping'           => $shipping,
                'grand_total'        => $grandTotal,
                'paid_amount'        => $paidAmount,
                'due_amount'         => $dueAmount,
                'payment_status'     => $paymentStatus,
                'payment_type'       => $paymentSync['payment_type'],
                'cash_register_id'   => $cashRegisterId,
                'payments_payload'   => $paymentSync['payments_payload'],
                'notes'              => array_key_exists('notes', $validated) ? $validated['notes'] : $purchase->notes,
                'created_by'         => $actor['created_by'],
                'user_id'            => $actor['user_id'],
                'user_name'          => $actor['user_name'],
                'user_role'          => $actor['user_role'],
            ];
            $purchase->update($updateFields);

            // Reconcile Supplier balance_due atomically under lock (only Received POs carry AP)
            $newApDue = ($newStatus === 'Received') ? $dueAmount : 0.0;
            $this->syncSupplierBalanceDue($oldSupplierId, $oldApDue, $newSupplierId, $newApDue);

            DB::commit();
            $this->clearPurchaseRelatedCaches();

            $freshLoaded = $purchase->fresh()->load(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'supplier']);
            $accounting->maskPurchaseCostFields($freshLoaded, $canViewCost);

            return response()->json([
                'status'  => 'success',
                'message' => 'Purchase updated successfully',
                'data'    => $freshLoaded,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $purchase = Purchase::with(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'supplier'])->where('reference_no', (string)$id)->first() ?? Purchase::with(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'supplier'])->find($id);
        if (!$purchase) {
            return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
        }
        $accounting = $this->accounting();
        $accounting->assertOutletAccess(
            $request,
            $purchase->warehouse_id ? (int)$purchase->warehouse_id : null,
            $purchase->warehouse_name
        );
        $accounting->maskPurchaseCostFields($purchase, $accounting->canViewCost($request));
        return response()->json(['status' => 'success', 'data' => $purchase]);
    }

    /**
     * Check whether any active (Completed/Received) Purchase Returns or credit allocations
     * depend on this purchase order.
     */
    private function hasLinkedActiveReturnsOrAllocations(Purchase $purchase): bool
    {
        $allocQuery = PurchaseReturnAllocation::where('purchase_id', $purchase->id);
        if (\Illuminate\Support\Facades\Schema::hasColumn('purchase_return_allocations', 'released_at')) {
            $allocQuery->whereNull('released_at');
        }
        return $allocQuery->exists()
            || PurchaseReturn::where('purchase_reference_no', $purchase->reference_no)
                ->whereIn('status', ['Completed', 'Received'])
                ->exists()
            || (float)$purchase->returned_credit_amount > 0.001;
    }

    public function destroy(Request $request, $id)
    {
        $accounting = $this->accounting();
        $fifoService = app(FifoInventoryService::class);

        DB::beginTransaction();
        try {
            $purchase = Purchase::with(['items', 'supplier'])
                ->where('reference_no', (string)$id)
                ->lockForUpdate()
                ->first()
                ?? Purchase::with(['items', 'supplier'])->lockForUpdate()->find($id);

            if (!$purchase) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
            }
            $accounting->assertOutletAccess(
                $request,
                $purchase->warehouse_id ? (int)$purchase->warehouse_id : null,
                $purchase->warehouse_name
            );

            if ($accounting->isOpeningStockPaymentType($purchase->payment_type)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Synthetic Opening Stock vouchers cannot be modified directly. Use Stock Adjustments to adjust opening stock.',
                ], 422);
            }

            // Guard 1: Block deletion when linked completed Purchase Returns or credit allocations exist
            if ($this->hasLinkedActiveReturnsOrAllocations($purchase)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete Purchase #' . $purchase->reference_no . ' because active Purchase Return(s) or credit note offsets are linked to it. Void or delete those Purchase Returns first.',
                ], 422);
            }

            // Guard 2: Check if any FIFO batch from this purchase has already been consumed
            $batchBlockMsg = $fifoService->assertPurchaseBatchesCanModifyOrDelete($purchase, null, true);
            if ($batchBlockMsg !== null) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => $batchBlockMsg,
                ], 422);
            }

            // Guard 3: Closed CashRegister audit immutability
            $beforeCashByReg = $accounting->extractDocumentCashByRegister($purchase, ['Received']);
            $regErr = $accounting->assertCashRegisterDeltaMutable($beforeCashByReg, []);
            if ($regErr !== null) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => $regErr], 422);
            }

            $affectedProducts = [];
            $affectedCostKeys = [];
            if ($purchase->status === 'Received') {
                $unitService = app(ProductUnitConversionService::class);
                $warehouseId = $unitService->warehouseId($purchase->warehouse_id, $purchase->warehouse_name);
                $batchesByItem = \App\Models\InventoryBatch::whereIn('purchase_item_id', $purchase->items->pluck('id'))
                    ->get()
                    ->keyBy('purchase_item_id');

                foreach ($purchase->items as $item) {
                    $product = !empty($item->product_id)
                        ? Product::lockForUpdate()->find($item->product_id)
                        : Product::lockForUpdate()->where('code', $item->product_code)->first();

                    if ($product) {
                        $vId = !empty($item->product_variant_id) ? (int)$item->product_variant_id : null;
                        $affectedCostKeys[$product->id . '|' . ($vId ?? 0)] = [(int)$product->id, $vId];
                        $baseQty = $item->base_quantity > 0 ? (float)$item->base_quantity : (float)$item->quantity;
                        $itemBatch = $batchesByItem->get($item->id);
                        $revUnitCost = ($itemBatch && (float)$itemBatch->unit_cost > 0)
                            ? (float)$itemBatch->unit_cost
                            : (float)($item->base_unit_cost ?: $item->unit_cost);
                        $unitService->applyStock(
                            $product,
                            $warehouseId,
                            $item->product_variant_id,
                            $baseQty,
                            'out',
                            'purchase_deletion',
                            'purchase',
                            (string)$purchase->id,
                            'Stock reduced due to purchase deletion',
                            true,
                            false,
                            true,
                            $revUnitCost > 0 ? $revUnitCost : null,
                            (string)$purchase->date
                        );

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

            // Clean up related FIFO inventory batches and resync master cost to latest remaining batch
            \App\Models\InventoryBatch::whereIn('purchase_item_id', $purchase->items->pluck('id'))->delete();

            foreach ($affectedCostKeys as [$pId, $vId]) {
                $latestBatch = \App\Models\InventoryBatch::where('product_id', $pId)
                    ->when(
                        $vId !== null,
                        fn($q) => $q->where('product_variant_id', $vId),
                        fn($q) => $q->whereNull('product_variant_id')
                    )
                    ->orderByDesc('received_date')
                    ->orderByDesc('id')
                    ->first();
                if ($latestBatch && (float)$latestBatch->unit_cost > 0.0001) {
                    $this->syncMasterProductCost($pId, $vId, (float)$latestBatch->unit_cost);
                }
            }

            // Reconcile Supplier balance_due atomically under lock (Received/Completed POs carried AP)
            if (in_array($purchase->status, ['Received', 'Completed'], true)) {
                $this->syncSupplierBalanceDue(
                    !empty($purchase->supplier_id) ? (int)$purchase->supplier_id : null,
                    (float)$purchase->due_amount,
                    null,
                    0.0
                );
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function addPayment(Request $request, $id)
    {
        $accounting = $this->accounting();
        $validated = $request->validate([
            'amount' => 'required_without:payments|nullable|numeric|min:0.01',
            'payment_type' => 'required_without:payments|nullable|string',
            'payment_date' => 'nullable|string',
            'cash_register_id' => 'nullable|integer',
            'notes' => 'nullable|string',
            'payments' => 'nullable|array|min:1',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.payment_type' => 'required|string',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount' => 'nullable|numeric|min:0',
            'payments.*.payment_date' => 'nullable|string',
            'payments.*.cash_register_id' => 'nullable|integer',
            'payments.*.notes' => 'nullable|string',
            'user_name' => 'nullable|string|max:255',
            'user_role' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $purchase = Purchase::with(['items', 'supplier'])->lockForUpdate()->find($id)
                ?? Purchase::with(['items', 'supplier'])->where('reference_no', (string)$id)->lockForUpdate()->first();
            if (!$purchase) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
            }
            $accounting->assertOutletAccess(
                $request,
                $purchase->warehouse_id ? (int)$purchase->warehouse_id : null,
                $purchase->warehouse_name
            );

            if ($accounting->isOpeningStockPaymentType($purchase->payment_type)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Synthetic Opening Stock vouchers cannot be modified directly. Use Stock Adjustments to adjust opening stock.',
                ], 422);
            }

            if ($purchase->status !== 'Received') {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot record payment on a Pending or Ordered purchase order. Mark it Received first.',
                ], 422);
            }

            $priorDue = (float)$purchase->due_amount;
            $payingAmount = !empty($validated['payments'])
                ? (float)collect($validated['payments'])->sum('amount')
                : (float)$validated['amount'];

            if ($payingAmount > $priorDue + 0.01) {
                DB::rollBack();
                $canViewCostMsg = $accounting->canViewCost($request);
                $cur = $accounting->resolveDefaultCurrencyCode();
                $errMsg = $canViewCostMsg
                    ? ('Payment amount (' . number_format($payingAmount, 2) . ' ' . $cur . ') exceeds outstanding due balance (' . number_format($priorDue, 2) . ' ' . $cur . ').')
                    : 'Payment amount exceeds the outstanding due balance for this purchase.';
                return response()->json([
                    'status'  => 'error',
                    'message' => $errMsg,
                ], 422);
            }

            $actor = $accounting->resolveActor($request, $validated, $purchase);
            $effectivePayType = !empty($validated['payments'])
                ? $this->resolveDerivedPaymentType($validated['payments'], 'Cash')
                : ($validated['payment_type'] ?? 'Cash');
            $probePayType = !empty($validated['payments'])
                ? (collect($validated['payments'])->contains(fn($t) => $accounting->isCashPaymentType($t['payment_type'] ?? null))
                    ? 'Cash'
                    : (string)($validated['payments'][0]['payment_type'] ?? 'Bank Transfer'))
                : $effectivePayType;
            $explicitRegId = !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null;
            $registerCheck = $accounting->resolveCashRegisterId(
                $explicitRegId,
                $actor['user'],
                $purchase->warehouse_id ? (int)$purchase->warehouse_id : null,
                $probePayType,
                $payingAmount
            );
            if ($registerCheck['error']) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => $registerCheck['error']], 422);
            }
            $defaultRegisterId = $registerCheck['cash_register_id'];

            if (!empty($validated['payments']) && is_array($validated['payments'])) {
                $splitRegErr = $accounting->validateSplitTenderCashRegisters($validated['payments']);
                if ($splitRegErr !== null) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $splitRegErr], 422);
                }
            }

            $existingPayments = $purchase->payments;
            $updatedPayments = array_values($existingPayments);
            $nextPaymentId = $accounting->nextPaymentId($updatedPayments);
            $cleanRef = preg_replace('/^#\s*/', '', trim((string)$purchase->reference_no));

            if (!empty($validated['payments']) && is_array($validated['payments'])) {
                foreach ($validated['payments'] as $tender) {
                    $pAmt = round((float)$tender['amount'], 2);
                    $newPaymentId = $nextPaymentId++;
                    $paymentRef = 'PAY-' . ($cleanRef ?: $purchase->id) . '-' . time() . '-' . rand(100, 999);
                    $receivedAmt = !empty($tender['received_amount']) ? round((float)$tender['received_amount'], 2) : $pAmt;
                    $changeAmt = !empty($tender['change_amount']) ? round((float)$tender['change_amount'], 2) : round(max(0.0, $receivedAmt - $pAmt), 2);
                    $rawPayDate = !empty($tender['payment_date']) ? $tender['payment_date'] : ($validated['payment_date'] ?? null);
                    $row = [
                        'id'              => $newPaymentId,
                        'reference_no'    => $paymentRef,
                        'payment_date'    => $accounting->normalizeTransactionDate($rawPayDate),
                        'payment_type'    => $tender['payment_type'],
                        'amount'          => $pAmt,
                        'received_amount' => $receivedAmt,
                        'change_amount'   => $changeAmt,
                        'user_name'       => $actor['user'] ? $actor['user_name'] : ($tender['user_name'] ?? $actor['user_name']),
                        'user_role'       => $actor['user'] ? $actor['user_role'] : ($tender['user_role'] ?? $actor['user_role']),
                        'notes'           => $tender['notes'] ?? ($validated['notes'] ?? ''),
                    ];
                    $tenderRegId = !empty($tender['cash_register_id']) ? (int)$tender['cash_register_id'] : ($accounting->isCashPaymentType($tender['payment_type'] ?? null) ? $defaultRegisterId : null);
                    if ($tenderRegId !== null) {
                        $row['cash_register_id'] = $tenderRegId;
                    }
                    $updatedPayments[] = $row;
                }
            } else {
                $newPaymentId = $nextPaymentId++;
                $paymentRef = 'PAY-' . ($cleanRef ?: $purchase->id) . '-' . time() . '-' . rand(100, 999);
                $pAmt = round($payingAmount, 2);
                $row = [
                    'id'              => $newPaymentId,
                    'reference_no'    => $paymentRef,
                    'payment_date'    => $accounting->normalizeTransactionDate($validated['payment_date'] ?? null),
                    'payment_type'    => $validated['payment_type'],
                    'amount'          => $pAmt,
                    'received_amount' => $pAmt,
                    'change_amount'   => 0.0,
                    'user_name'       => $actor['user_name'],
                    'user_role'       => $actor['user_role'],
                    'notes'           => $validated['notes'] ?? '',
                ];
                if ($defaultRegisterId !== null && $accounting->isCashPaymentType($validated['payment_type'] ?? null)) {
                    $row['cash_register_id'] = $defaultRegisterId;
                }
                $updatedPayments[] = $row;
            }

            $rawNewPaid = (float)collect($updatedPayments)->sum('amount');
            $creditApplied = round(max(0.0, (float)$purchase->returned_credit_amount), 2);
            [$newPaid, $newDue, $newStatus] = $this->applyPurchasePaymentSummary((float)$purchase->grand_total, $rawNewPaid, $creditApplied);
            $newPaymentType = $this->resolveDerivedPaymentType($updatedPayments, $validated['payment_type'] ?? 'Cash');

            $purchaseUpdate = [
                'payments_payload' => json_encode($updatedPayments),
                'paid_amount'      => $newPaid,
                'due_amount'       => $newDue,
                'payment_status'   => $newStatus,
                'payment_type'     => $newPaymentType,
            ];
            if ($purchase->cash_register_id === null && $defaultRegisterId !== null) {
                $purchaseUpdate['cash_register_id'] = $defaultRegisterId;
            }
            $purchase->update($purchaseUpdate);

            // Reconcile Supplier balance_due under lock (only Received POs carry AP)
            if ($purchase->status === 'Received') {
                $this->syncSupplierBalanceDue(
                    !empty($purchase->supplier_id) ? (int)$purchase->supplier_id : null,
                    $priorDue,
                    !empty($purchase->supplier_id) ? (int)$purchase->supplier_id : null,
                    $newDue
                );
            }

            DB::commit();
            $this->clearPurchaseRelatedCaches();

            $freshLoaded = $purchase->fresh()->load(['items', 'supplier']);
            $accounting->maskPurchaseCostFields($freshLoaded, $accounting->canViewCost($request));

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment added successfully',
                'data'    => $freshLoaded,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function deletePayment(Request $request, $purchaseId, $paymentId = null)
    {
        $accounting = $this->accounting();
        DB::beginTransaction();
        try {
            if ($paymentId !== null) {
                $purchase = Purchase::with(['items', 'supplier'])->lockForUpdate()->find($purchaseId)
                    ?? Purchase::with(['items', 'supplier'])->where('reference_no', (string)$purchaseId)->lockForUpdate()->first();
            } else {
                $targetStr = trim((string)$purchaseId);
                $purchase = null;
                $refMatchedPurchases = [];
                $idMatchedPurchases = [];
                $candidates = Purchase::with(['items', 'supplier'])
                    ->where(function ($q) use ($targetStr) {
                        $q->where(function ($sq) use ($targetStr) {
                            $sq->whereNotNull('payments_payload')
                               ->where('payments_payload', 'like', '%' . $targetStr . '%');
                        })->orWhere(function ($lq) {
                            $lq->whereNull('payments_payload')
                               ->where('paid_amount', '>', 0.05);
                        });
                    })
                    ->lockForUpdate()
                    ->get();
                foreach ($candidates as $cand) {
                    foreach ($cand->payments as $p) {
                        if (isset($p['reference_no']) && trim((string)$p['reference_no']) === $targetStr) {
                            $refMatchedPurchases[$cand->id] = $cand;
                        } elseif (isset($p['id']) && (string)$p['id'] === $targetStr) {
                            $idMatchedPurchases[$cand->id] = $cand;
                        }
                    }
                }
                if (count($refMatchedPurchases) === 1) {
                    $purchase = reset($refMatchedPurchases);
                } elseif (count($refMatchedPurchases) > 1) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => "Payment reference '{$targetStr}' matches multiple Purchases. Use DELETE /api/purchases/{purchaseId}/payments/{paymentId} instead.",
                    ], 422);
                } elseif (count($idMatchedPurchases) > 1) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => "Payment identifier '{$targetStr}' is ambiguous across multiple Purchases. Pass the unique payment reference_no or use DELETE /api/purchases/{purchaseId}/payments/{paymentId}.",
                    ], 422);
                } elseif (count($idMatchedPurchases) === 1) {
                    $purchase = reset($idMatchedPurchases);
                }
                if (!$purchase) {
                    $purchase = Purchase::with(['items', 'supplier'])->lockForUpdate()->find($purchaseId)
                        ?? Purchase::with(['items', 'supplier'])->where('reference_no', (string)$purchaseId)->lockForUpdate()->first();
                }
            }

            if (!$purchase) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Purchase not found'], 404);
            }
            $accounting->assertOutletAccess(
                $request,
                $purchase->warehouse_id ? (int)$purchase->warehouse_id : null,
                $purchase->warehouse_name
            );

            if ($accounting->isOpeningStockPaymentType($purchase->payment_type)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Synthetic Opening Stock vouchers cannot be modified directly. Use Stock Adjustments to adjust opening stock.',
                ], 422);
            }

            $existingPayments = $purchase->payments;
            if (empty($existingPayments)) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Payment record not found'], 404);
            }

            $targetIdentifier = trim((string)($paymentId ?? $purchaseId));
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
                return response()->json(['status' => 'error', 'message' => 'Payment record not found'], 404);
            }

            if ($purchase->status === 'Received' && is_array($deletedPayment)) {
                $legRegId = isset($deletedPayment['cash_register_id']) ? (int)$deletedPayment['cash_register_id'] : null;
                $docRegId = $purchase->cash_register_id ? (int)$purchase->cash_register_id : null;
                $legErr = $accounting->assertPaymentLegRegisterMutable(
                    $legRegId,
                    $docRegId,
                    (string)($deletedPayment['payment_type'] ?? $purchase->payment_type)
                );
                if ($legErr !== null) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $legErr], 422);
                }
            }

            $oldDue = (float)$purchase->due_amount;
            $rawNewPaid = (float)collect($remainingPayments)->sum('amount');
            $grandTotal = (float)$purchase->grand_total;
            $creditApplied = round(max(0.0, (float)$purchase->returned_credit_amount), 2);
            [$newPaid, $newDue, $newStatus] = $this->applyPurchasePaymentSummary($grandTotal, $rawNewPaid, $creditApplied);
            $newPaymentType = empty($remainingPayments)
                ? 'N/A'
                : $this->resolveDerivedPaymentType($remainingPayments, $purchase->payment_type ?: 'Cash');

            $purchase->update([
                'payments_payload' => json_encode(array_values($remainingPayments)),
                'paid_amount'      => $newPaid,
                'due_amount'       => $newDue,
                'payment_status'   => $newStatus,
                'payment_type'     => $newPaymentType,
            ]);

            // Restore Supplier balance_due atomically under lock (only Received POs carry AP)
            if ($purchase->status === 'Received') {
                $this->syncSupplierBalanceDue(
                    !empty($purchase->supplier_id) ? (int)$purchase->supplier_id : null,
                    $oldDue,
                    !empty($purchase->supplier_id) ? (int)$purchase->supplier_id : null,
                    $newDue
                );
            }

            DB::commit();
            $this->clearPurchaseRelatedCaches();

            $canViewCostMsg = $accounting->canViewCost($request);
            $freshLoaded = $purchase->fresh()->load(['items', 'supplier']);
            $accounting->maskPurchaseCostFields($freshLoaded, $canViewCostMsg);

            return response()->json([
                'status'  => 'success',
                'message' => $canViewCostMsg
                    ? ('Payment deleted successfully. Due balance restored to ' . number_format($newDue, 2))
                    : 'Payment deleted successfully.',
                'data'    => $freshLoaded,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    protected function clearPurchaseRelatedCaches(): void
    {
        // Zero-cache mode: purchases, products, and reports always query live DB.
    }
}
