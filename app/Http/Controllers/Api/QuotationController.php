<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CashRegister;
use App\Models\SalePayment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Services\ProductUnitConversionService;
use App\Support\ListCursorHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuotationController extends Controller
{
    use \App\Traits\GeneratesSequentialCode;

    private const CURSOR_SORT_COLUMNS = [
        'id', 'reference_no', 'customer_name', 'warehouse_name', 'date',
        'status', 'grand_total', 'user_name', 'user_role', 'created_by',
    ];
    /**
     * Generate next sequential quotation code and formatted reference number.
     */
    public function generateCode()
    {
        return response()->json($this->peekNextSequentialCode('quotation'));
    }

    /**
     * Build filtered quotation query based on request parameters
     */
    protected function accounting(): \App\Services\QuotationAccountingService
    {
        return app(\App\Services\QuotationAccountingService::class);
    }

    protected function purchaseAccounting(): \App\Services\PurchaseAccountingService
    {
        return app(\App\Services\PurchaseAccountingService::class);
    }

    private function attachQuotationItemAggregates($items): void
    {
        $ids = $items->pluck('id')->filter()->values()->all();
        if (empty($ids)) {
            return;
        }
        $aggs = DB::table('quotation_items')
            ->selectRaw('quotation_id, COUNT(*) as items_count, COALESCE(SUM(quantity), 0) as total_quantity')
            ->whereIn('quotation_id', $ids)
            ->groupBy('quotation_id')
            ->get()
            ->keyBy('quotation_id');

        foreach ($items as $q) {
            $agg = $aggs->get($q->id);
            $q->setAttribute('items_count', (int) ($agg->items_count ?? 0));
            $q->setAttribute('total_quantity', (float) ($agg->total_quantity ?? 0));
        }
    }

    protected function buildFilteredQuery(Request $request, bool $includeItems = false)
    {
        $withRelations = $includeItems
            ? [
                'customer:id,name,phone,email,credit_limit',
                'sale:id,quotation_id,reference_no',
                'items:id,quotation_id,product_id,product_variant_id,unit_id,unit_name,conversion_rate,product_code,product_name,variant_name,unit_price,base_unit_price,quantity,base_quantity,tax,discount,subtotal',
            ]
            : ['customer:id,name', 'sale:id,quotation_id,reference_no'];

        $query = Quotation::select([
            'id', 'reference_no', 'customer_id', 'customer_name', 'warehouse_id', 'warehouse_name',
            'date', 'expiry_date', 'tax_rate', 'tax_amount', 'discount', 'shipping', 'grand_total',
            'status', 'notes', 'created_by', 'user_id', 'user_name', 'user_role', 'created_at', 'updated_at'
        ])
        ->with($withRelations);

        $purchaseAccounting = $this->purchaseAccounting();
        $purchaseAccounting->applyOutletScope(
            $query,
            $purchaseAccounting->extractAllowedOutletIds($request),
            'quotations.warehouse_id'
        );

        // Filter: Warehouse / Outlet
        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $warehouse = $request->input('warehouse');
            $query->where(function ($q) use ($warehouse) {
                $q->where('quotations.warehouse_name', $warehouse);
                if (is_numeric($warehouse)) {
                    $q->orWhere('quotations.warehouse_id', (int)$warehouse);
                }
            });
        }
        if ($request->filled('warehouse_id')) {
            $query->where('quotations.warehouse_id', (int)$request->input('warehouse_id'));
        }

        // Filter: Status (evaluating expiry_date for Expired & active Pipeline statuses)
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $statusParam = trim((string)$request->input('status'));
            $todayDate = now()->toDateString();
            if (strcasecmp($statusParam, 'Expired') === 0) {
                $query->where(function ($q) use ($todayDate) {
                    $q->where('quotations.status', 'Expired')
                      ->orWhere(function ($sub) use ($todayDate) {
                          $sub->whereNotIn('quotations.status', ['Ordered', 'Declined', 'Expired'])
                              ->whereNotNull('quotations.expiry_date')
                              ->where('quotations.expiry_date', '<', $todayDate);
                      });
                });
            } elseif (in_array(strtolower($statusParam), ['pending', 'sent', 'accepted'], true)) {
                $query->where('quotations.status', $statusParam)
                      ->where(function ($q) use ($todayDate) {
                          $q->whereNull('quotations.expiry_date')
                            ->orWhere('quotations.expiry_date', '>=', $todayDate);
                      });
            } else {
                $query->where('quotations.status', $statusParam);
            }
        }

        // Filter: Search (Reference #, Customer, Staff Name, Role, Notes, Product Name/Code)
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            if (preg_match('/^QUO[-_]/i', $search)) {
                $altHyphen = str_replace('_', '-', $search);
                $altUnderscore = str_replace('-', '_', $search);
                $query->where(function ($q) use ($search, $altHyphen, $altUnderscore) {
                    $q->where('quotations.reference_no', 'like', "{$search}%");
                    if ($altHyphen !== $search) {
                        $q->orWhere('quotations.reference_no', 'like', "{$altHyphen}%");
                    }
                    if ($altUnderscore !== $search) {
                        $q->orWhere('quotations.reference_no', 'like', "{$altUnderscore}%");
                    }
                });
            } else {
                $query->where(function ($q) use ($search) {
                    $q->where('quotations.reference_no', 'like', "%{$search}%")
                      ->orWhere('quotations.customer_name', 'like', "%{$search}%")
                      ->orWhere('quotations.user_name', 'like', "%{$search}%")
                      ->orWhere('quotations.user_role', 'like', "%{$search}%")
                      ->orWhere('quotations.created_by', 'like', "%{$search}%")
                      ->orWhere('quotations.notes', 'like', "%{$search}%")
                      ->orWhereHas('items', function ($iq) use ($search) {
                          $iq->where('product_name', 'like', "%{$search}%")
                             ->orWhere('product_code', 'like', "%{$search}%");
                      });
                });
            }
        }

        // Filter: Created By / Staff
        $filterUser = $request->input('created_by');
        if (empty($filterUser) || $filterUser === 'All') {
            $filterUser = $request->input('user');
        }
        if (!empty($filterUser) && $filterUser !== 'All') {
            $query->where(function ($q) use ($filterUser) {
                $q->where('quotations.created_by', $filterUser)
                  ->orWhere('quotations.user_name', $filterUser);
                if (is_numeric($filterUser)) {
                    $q->orWhere('quotations.user_id', (int)$filterUser);
                }
            });
        }
        if ($request->filled('user_id') && $request->input('user_id') !== 'All') {
            $query->where('quotations.user_id', (int)$request->input('user_id'));
        }
        if ($request->filled('user_role') && $request->input('user_role') !== 'All') {
            $query->where('quotations.user_role', $request->input('user_role'));
        }

        // Filter: Customer
        if ($request->filled('customer') && $request->input('customer') !== 'All') {
            $customer = $request->input('customer');
            $query->where(function ($q) use ($customer) {
                $q->where('quotations.customer_name', $customer);
                if (is_numeric($customer)) {
                    $q->orWhere('quotations.customer_id', (int)$customer);
                }
            });
        }
        if ($request->filled('customer_id')) {
            $query->where('quotations.customer_id', (int)$request->input('customer_id'));
        }

        // Filter: Date Range (Optimized for MySQL Index Usage)
        $fromDate = $request->input('from_date') ?? $request->input('date_from');
        if (!empty($fromDate)) {
            $from = trim($fromDate);
            $query->where('quotations.date', '>=', str_contains($from, ':') ? $from : $from . ' 00:00:00');
        }
        $toDate = $request->input('to_date') ?? $request->input('date_to');
        if (!empty($toDate)) {
            $to = trim($toDate);
            if (str_contains($to, ':')) {
                $query->where('quotations.date', '<=', $to);
            } else {
                // Exclusive upper bound handles both 'YYYY-MM-DD HH:MM:SS' and ISO 'YYYY-MM-DDTHH:MM:SS' timestamps.
                try {
                    $nextDay = \Carbon\Carbon::parse($to)->addDay()->format('Y-m-d');
                    $query->where('quotations.date', '<', $nextDay);
                } catch (\Throwable $e) {
                    $query->where('quotations.date', '<=', $to . ' 23:59:59');
                }
            }
        }

        return $query;
    }

    /**
     * Display a paginated listing of quotations with optional filters and sorting.
     */
    public function index(Request $request)
    {
        $includeItems = $request->boolean('include_items') || $request->input('with_items') === '1';
        $query = $this->buildFilteredQuery($request, $includeItems);

        // Sorting
        $allowedSortCols = [
            'id' => 'id',
            'reference' => 'reference_no',
            'reference_no' => 'reference_no',
            'customer' => 'customer_name',
            'customer_name' => 'customer_name',
            'warehouse' => 'warehouse_name',
            'warehouse_name' => 'warehouse_name',
            'date' => 'date',
            'status' => 'status',
            'grand_total' => 'grand_total',
            'total' => 'grand_total',
            'user_name' => 'user_name',
            'user_role' => 'user_role',
            'created_by' => 'created_by',
            'creator' => 'user_name',
        ];

        $sortByInput = strtolower(trim((string)$request->input('sort_by', 'id')));
        $sortBy = $allowedSortCols[$sortByInput] ?? 'id';
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $cacheControl = 'no-store, no-cache, must-revalidate, private';
        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        if ($isAll) {
            $totalCount = (clone $query)->count();
            $limit = min(1000, max(1, (int)$request->input('limit', 500)));
            $items = $query->orderBy($sortBy, $sortOrder)
                ->when($sortBy !== 'quotations.id' && $sortBy !== 'id', fn ($q) => $q->orderBy('id', $sortOrder))
                ->take($limit)
                ->get();
            $accounting = $this->accounting();
            $canViewCost = $accounting->canViewCost($request);
            $this->attachQuotationItemAggregates($items);
            $items->each(fn($q) => $accounting->enrichQuotationResponse($q, $canViewCost));
            $summary = $accounting->computeSummaryStats($query);

            return response()->json([
                'status' => 'success',
                'data' => $items,
                'total' => $totalCount,
                'summary' => $summary,
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $items->count(),
                    'total' => $totalCount,
                    'last_page' => (int)ceil($totalCount / max(1, $limit)),
                ],
            ])
            ->header('Cache-Control', $cacheControl);
        }

        $perPage = min(1000, max(1, (int)$request->input('per_page', 50)));
        $page = max(1, (int)$request->input('page', 1));
        $skipSummary = $request->boolean('skip_summary');
        $cursorData = ListCursorHelper::decode(
            (string)$request->input('cursor', ''),
            $sortBy,
            $sortOrder,
            self::CURSOR_SORT_COLUMNS,
            ['id'],
            ['grand_total']
        );
        $isScrollPage = ($page > 1 || $cursorData !== null);

        $accounting = $this->accounting();
        if ($skipSummary && $request->filled('known_total')) {
            $total = max(0, (int)$request->input('known_total'));
            $summary = [
                'total_records' => $total,
                'pipeline_total' => 0.0,
                'pipeline_count' => 0,
                'converted_total' => 0.0,
                'converted_count' => 0,
                'lost_total' => 0.0,
                'lost_count' => 0,
                'gross_total' => 0.0,
                'total_grand_total' => 0.0,
            ];
        } else {
            $aggQuery = (clone $query)->setEagerLoads([]);
            $aggQuery->getQuery()->columns = null;
            $aggQuery->getQuery()->orders = null;
            if ($skipSummary) {
                $total = (int) $aggQuery->count();
                $summary = [
                    'total_records' => $total,
                    'pipeline_total' => 0.0,
                    'pipeline_count' => 0,
                    'converted_total' => 0.0,
                    'converted_count' => 0,
                    'lost_total' => 0.0,
                    'lost_count' => 0,
                    'gross_total' => 0.0,
                    'total_grand_total' => 0.0,
                ];
            } else {
                $summary = $accounting->computeSummaryStats($aggQuery);
                $total = $summary['total_records'];
            }
        }

        $canViewCost = $accounting->canViewCost($request);

        $items = collect();
        $hasMore = false;
        if ($total > 0 || $cursorData !== null) {
            $listQuery = (clone $query);
            if ($cursorData !== null) {
                ListCursorHelper::apply($listQuery, $sortBy, $sortOrder, $cursorData);
            }
            $listQuery->orderBy($sortBy, $sortOrder);
            if ($sortBy !== 'id') {
                $listQuery->orderBy('id', $sortOrder);
            }
            $offset = $cursorData !== null ? 0 : (($page - 1) * $perPage);
            $fetched = $listQuery->offset($offset)->limit($perPage + 1)->get();
            $hasMore = $fetched->count() > $perPage;
            $items = $hasMore ? $fetched->slice(0, $perPage)->values() : $fetched;
        }

        $lastRowObj = $items->last();
        $nextCursor = ($hasMore && $lastRowObj !== null)
            ? ListCursorHelper::encode((object)$lastRowObj, $sortBy, $sortOrder, self::CURSOR_SORT_COLUMNS)
            : null;

        $this->attachQuotationItemAggregates($items);
        $items->each(fn($q) => $accounting->enrichQuotationResponse($q, $canViewCost));

        $lastPage = max(1, (int)ceil($total / max(1, $perPage)));
        $pagination = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'has_more' => $hasMore,
        ];
        if ($nextCursor !== null) {
            $pagination['next_cursor'] = $nextCursor;
        }

        return response()->json([
            'status' => 'success',
            'data' => $items->values(),
            'total' => $total,
            'summary' => $summary,
            'pagination' => $pagination,
        ])
        ->header('Cache-Control', $cacheControl);
    }

    /**
     * Streamed UTF-8 BOM CSV Export for Quotations matching active filters
     */
    public function export(Request $request)
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $query = $this->buildFilteredQuery($request, false);

        // Sorting
        $allowedSortCols = [
            'id' => 'quotations.id',
            'reference' => 'quotations.reference_no',
            'reference_no' => 'quotations.reference_no',
            'customer' => 'quotations.customer_name',
            'customer_name' => 'quotations.customer_name',
            'warehouse' => 'quotations.warehouse_name',
            'warehouse_name' => 'quotations.warehouse_name',
            'date' => 'quotations.date',
            'status' => 'quotations.status',
            'grand_total' => 'quotations.grand_total',
            'total' => 'quotations.grand_total',
            'user_name' => 'quotations.user_name',
            'creator' => 'quotations.user_name',
            'user_role' => 'quotations.user_role',
            'created_by' => 'quotations.created_by',
        ];

        $sortByInput = strtolower(trim((string)$request->input('sort_by', 'id')));
        $sortBy = $allowedSortCols[$sortByInput] ?? 'quotations.id';
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="quotations_export_' . now()->format('Ymd_His') . '.csv"',
            'X-Accel-Buffering' => 'no',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Expires' => '0',
        ];

        $warehouseFilter = $request->input('warehouse', 'All');
        $statusFilter = $request->input('status', 'All');
        $customerFilter = $request->input('customer');
        $userFilter = $request->input('created_by');
        $fromDate = $request->input('from_date') ?? $request->input('date_from');
        $toDate = $request->input('to_date') ?? $request->input('date_to');
        $searchQuery = $request->input('search');

        // Join customer phone & item count aggregates cleanly
        $query->leftJoin('customers', 'quotations.customer_id', '=', 'customers.id')
              ->leftJoin(
                  DB::raw('(SELECT quotation_id, COUNT(*) as items_count FROM quotation_items GROUP BY quotation_id) qi'),
                  'quotations.id',
                  '=',
                  'qi.quotation_id'
              )
              ->select([
                  'quotations.id', 'quotations.reference_no', 'quotations.customer_name',
                  'quotations.warehouse_name', 'quotations.date', 'quotations.expiry_date',
                  'quotations.tax_rate', 'quotations.tax_amount', 'quotations.discount',
                  'quotations.shipping', 'quotations.grand_total', 'quotations.status',
                  'quotations.notes', 'quotations.created_by', 'quotations.user_name',
                  'quotations.user_role', 'customers.phone as customer_phone',
                  DB::raw('COALESCE(qi.items_count, 0) as items_count'),
              ]);

        $callback = function () use (
            $query,
            $sortBy,
            $sortOrder,
            $warehouseFilter,
            $statusFilter,
            $customerFilter,
            $userFilter,
            $fromDate,
            $toDate,
            $searchQuery
        ) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Microsoft Excel on Windows
            fwrite($file, "\xEF\xBB\xBF");

            $sanitizeCsv = static function ($val): string {
                if ($val === null) {
                    return '';
                }
                $str = (string) $val;
                if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    return "'" . $str;
                }
                return $str;
            };

            // 1. Metadata Header
            fputcsv($file, ['Quotations / Estimates Export']);
            fputcsv($file, ['Generated At', now()->toIso8601String()]);
            fputcsv($file, ['Warehouse Filter', $sanitizeCsv($warehouseFilter ?: 'All')]);
            fputcsv($file, ['Status Filter', $sanitizeCsv($statusFilter ?: 'All')]);
            if (!empty($customerFilter) && $customerFilter !== 'All') {
                fputcsv($file, ['Customer Filter', $sanitizeCsv($customerFilter)]);
            }
            if (!empty($userFilter) && $userFilter !== 'All') {
                fputcsv($file, ['Created By Filter', $sanitizeCsv($userFilter)]);
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

            $accounting = $this->accounting();
            $currency = $accounting->resolveDefaultCurrencyCode();

            // 2. Column Headers (18 columns: 16 core columns + Items Count + Expiry Date)
            $columns = [
                'SN',
                'Quotation Reference',
                'Customer Name',
                'Customer Phone',
                'Warehouse / Outlet',
                'Tax Rate (%)',
                "Tax Amount ({$currency})",
                "Discount ({$currency})",
                "Shipping ({$currency})",
                "Grand Total ({$currency})",
                'Status',
                'Created By',
                'User Role',
                'Date',
                'Time',
                'Notes',
                'Items Count',
                'Expiry Date',
            ];
            fputcsv($file, $columns);

            $sn = 1;
            $formatNum = function ($num) {
                $floatVal = (float)$num;
                return (fmod($floatVal, 1.0) == 0.0) ? (string)((int)$floatVal) : (string)number_format($floatVal, 2, '.', '');
            };

            $formatPhone = function ($phone) use ($sanitizeCsv) {
                $phone = trim((string)$phone);
                if ($phone === '') return '';
                if (!preg_match('/^[0-9+\s().-]+$/', $phone)) {
                    return $sanitizeCsv($phone);
                }
                if (str_starts_with($phone, '0') || str_starts_with($phone, '+') || str_contains($phone, ' ')) {
                    return '="' . $phone . '"';
                }
                return $phone;
            };

            $totalCount = 0;
            $totalItemsCount = 0;
            $totalTax = 0.0;
            $totalDiscount = 0.0;
            $totalShipping = 0.0;
            $totalGrandTotal = 0.0;

            $pipelineCount = 0;
            $pipelineTotal = 0.0;
            $convertedCount = 0;
            $convertedTotal = 0.0;
            $lostCount = 0;
            $lostTotal = 0.0;
            $todayDate = \Carbon\Carbon::today()->toDateString();

            $flushCount = 0;
            $orderedExportQuery = $query->orderBy($sortBy, $sortOrder);
            if ($sortBy !== 'quotations.id') {
                $orderedExportQuery->orderBy('quotations.id', $sortOrder);
            }
            foreach ($orderedExportQuery->toBase()->cursor() as $q) {
                $totalCount++;
                $totalItemsCount += (int)($q->items_count ?? 0);
                $totalTax += (float)$q->tax_amount;
                $totalDiscount += (float)$q->discount;
                $totalShipping += (float)$q->shipping;
                $rowGrandTotal = (float)$q->grand_total;
                $totalGrandTotal += $rowGrandTotal;

                $rowStatus = (string)($q->status ?? 'Sent');
                $rowExpiry = !empty($q->expiry_date) ? substr(trim((string)$q->expiry_date), 0, 10) : '';
                $effectiveRowStatus = $rowStatus;
                if ($rowStatus === 'Ordered') {
                    $convertedCount++;
                    $convertedTotal += $rowGrandTotal;
                } elseif (in_array($rowStatus, ['Declined', 'Expired'], true) || ($rowExpiry !== '' && $rowExpiry < $todayDate)) {
                    $lostCount++;
                    $lostTotal += $rowGrandTotal;
                    if (in_array($rowStatus, \App\Services\QuotationAccountingService::OPEN_PIPELINE_STATUSES, true) && $rowExpiry !== '' && $rowExpiry < $todayDate) {
                        $effectiveRowStatus = 'Expired';
                    }
                } else {
                    $pipelineCount++;
                    $pipelineTotal += $rowGrandTotal;
                }

                $rawDate = trim((string)($q->date ?? ''));
                if ($rawDate !== '' && (str_ends_with($rawDate, 'Z') || str_ends_with($rawDate, 'z') || preg_match('/[+-]\d{2}:?\d{2}$/', $rawDate))) {
                    try {
                        $dt = \Carbon\Carbon::parse($rawDate)->setTimezone(config('app.timezone', 'UTC'));
                        $dateOnly = $dt->format('Y-m-d');
                        $timeStr = $dt->format('H:i:s');
                    } catch (\Throwable $e) {
                        $dateParts = preg_split('/[T ]/', $rawDate, 2);
                        $dateOnly = $dateParts[0] ?? '';
                        $timeStr = isset($dateParts[1]) ? preg_replace('/(?:\.\d+)?Z$/i', '', $dateParts[1]) : '';
                    }
                } else {
                    $dateParts = $rawDate !== '' ? preg_split('/[T ]/', $rawDate, 2) : [];
                    $dateOnly = $dateParts[0] ?? '';
                    $timeStr = isset($dateParts[1]) ? preg_replace('/\.\d+$/', '', $dateParts[1]) : '';
                }

                $phone = $q->customer_phone ?? '';

                // Clean user name / created_by
                $userName = trim((string)($q->user_name ?: $q->created_by));
                if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $userName, $m)) {
                    $userName = trim($m[1]);
                }
                if (empty($userName)) $userName = 'Sales Staff';

                $userRole = trim((string)($q->user_role ?: 'Sales Staff'));

                $row = [
                    $sn++,
                    $sanitizeCsv($q->reference_no),
                    $sanitizeCsv($q->customer_name),
                    $formatPhone($phone),
                    $sanitizeCsv($q->warehouse_name),
                    $formatNum($q->tax_rate),
                    $formatNum($q->tax_amount),
                    $formatNum($q->discount),
                    $formatNum($q->shipping),
                    $formatNum($q->grand_total),
                    $sanitizeCsv($effectiveRowStatus),
                    $sanitizeCsv($userName),
                    $sanitizeCsv($userRole),
                    $dateOnly,
                    $timeStr,
                    $sanitizeCsv($q->notes ?? ''),
                    (string)((int)($q->items_count ?? 0)),
                    $rowExpiry !== '' ? $rowExpiry : '—',
                ];
                fputcsv($file, $row);

                if (++$flushCount % 500 === 0) {
                    flush();
                }
            }

            // Summary rows (18 columns aligned with headers & CsvHelper)
            fputcsv($file, []);
            fputcsv($file, [
                'TOTAL',
                "$totalCount Quotations",
                '—',
                '—',
                '—',
                '—',
                $formatNum($totalTax),
                $formatNum($totalDiscount),
                $formatNum($totalShipping),
                $formatNum($totalGrandTotal),
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                (string)$totalItemsCount,
                '—',
            ]);
            fputcsv($file, [
                'PIPELINE (Active Open)',
                "$pipelineCount Quotations",
                'Sent / Pending / Accepted',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                $formatNum($pipelineTotal),
                'Open',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
            ]);
            fputcsv($file, [
                'ORDERED (Converted)',
                "$convertedCount Quotations",
                'Converted to Sale',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                $formatNum($convertedTotal),
                'Ordered',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
            ]);
            fputcsv($file, [
                'CLOSED LOST',
                "$lostCount Quotations",
                'Declined / Expired',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                $formatNum($lostTotal),
                'Declined / Expired',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
            ]);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Store a newly created quotation with its line items.
     */
    /**
     * Build normalized quotation item rows with Product/Unit resolution & strict product_id validation.
     */
    protected function buildQuotationItemRows(
        int $quotationId,
        array $rawItems,
        \App\Services\QuotationAccountingService $accounting,
        ProductUnitConversionService $unitService
    ): array {
        $productIds = collect($rawItems)->pluck('product_id')->filter(fn($id) => !empty($id))->map(fn($id) => (int) $id)->unique()->values()->all();
        $productCodes = collect($rawItems)->pluck('product_code')->filter(fn($c) => trim((string) $c) !== '')->unique()->values()->all();

        $productsMap = Product::with(['unitLinks.unit', 'variants:id,product_id'])
            ->where(function ($q) use ($productIds, $productCodes) {
                if (!empty($productIds)) {
                    $q->whereIn('id', $productIds);
                    if (!empty($productCodes)) {
                        $q->orWhereIn('code', $productCodes);
                    }
                } elseif (!empty($productCodes)) {
                    $q->whereIn('code', $productCodes);
                }
            })
            ->get();

        $productsById = $productsMap->keyBy('id');
        $productsByCode = $productsMap->keyBy('code');

        $rows = [];
        $now = now();
        foreach ($rawItems as $index => $item) {
            $explicitProductId = !empty($item['product_id']) ? (int) $item['product_id'] : null;
            if ($explicitProductId !== null && !isset($productsById[$explicitProductId])) {
                $val = \Illuminate\Support\Facades\Validator::make([], []);
                $msg = "Selected product (ID {$explicitProductId}) for item '" . ($item['product_name'] ?? 'Unknown') . "' does not exist.";
                $val->errors()->add("items.{$index}.product_id", $msg);
                throw new \Illuminate\Validation\ValidationException(
                    validator: $val,
                    response: response()->json([
                        'status' => 'error',
                        'message' => $msg,
                        'errors' => ["items.{$index}.product_id" => [$msg]],
                    ], 422)
                );
            }

            $product = $explicitProductId !== null
                ? $productsById[$explicitProductId]
                : ($productsByCode[$item['product_code']] ?? null);

            $variantId = !empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;
            if ($product) {
                $hasVariants = (bool) $product->has_variants || ($product->relationLoaded('variants') && $product->variants->isNotEmpty());
                if ($variantId !== null && (!$product->relationLoaded('variants') || !$product->variants->contains('id', $variantId))) {
                    $val = \Illuminate\Support\Facades\Validator::make([], []);
                    $msg = "Selected variant does not belong to product '{$product->name}'.";
                    $val->errors()->add("items.{$index}.product_variant_id", $msg);
                    throw new \Illuminate\Validation\ValidationException(
                        validator: $val,
                        response: response()->json([
                            'status' => 'error',
                            'message' => $msg,
                            'errors' => ["items.{$index}.product_variant_id" => [$msg]],
                        ], 422)
                    );
                }
                if ($hasVariants && $variantId === null) {
                    $val = \Illuminate\Support\Facades\Validator::make([], []);
                    $msg = "Product '{$product->name}' has variations. Please select a concrete variation.";
                    $val->errors()->add("items.{$index}.product_variant_id", $msg);
                    throw new \Illuminate\Validation\ValidationException(
                        validator: $val,
                        response: response()->json([
                            'status' => 'error',
                            'message' => $msg,
                            'errors' => ["items.{$index}.product_variant_id" => [$msg]],
                        ], 422)
                    );
                }
            }

            $unitMeta = $accounting->resolveItemUnitFields($item, $product, $unitService);
            $qty = max(0.0, (float) $item['quantity']);
            $unitPrice = max(0.0, (float) $item['unit_price']);
            $grossLine = max(0.0, round($qty * $unitPrice, 2));
            $itemTax = round(max(0.0, (float) ($item['tax'] ?? 0)), 2);
            $itemDiscount = round(min($grossLine, max(0.0, (float) ($item['discount'] ?? 0))), 2);
            $lineSubtotal = round(max(0.0, $grossLine - $itemDiscount) + $itemTax, 2);

            $rows[] = [
                'quotation_id' => $quotationId,
                'product_id' => $unitMeta['product_id'],
                'product_variant_id' => $unitMeta['product_variant_id'],
                'unit_id' => $unitMeta['unit_id'],
                'unit_name' => $unitMeta['unit_name'],
                'conversion_rate' => $unitMeta['conversion_rate'],
                'product_code' => $item['product_code'],
                'product_name' => $item['product_name'],
                'variant_name' => $item['variant_name'] ?? null,
                'unit_price' => $unitPrice,
                'base_unit_price' => $unitMeta['base_unit_price'],
                'quantity' => $qty,
                'base_quantity' => $unitMeta['base_quantity'],
                'tax' => $itemTax,
                'discount' => $itemDiscount,
                'subtotal' => $lineSubtotal,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * Store a newly created quotation in the database.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            // Allow concurrent requests carrying the same previewed sequential reference_no
            // to be atomically reallocated inside acquireUniqueReference() under DB lock.
            'reference_no'       => 'nullable|string|max:100',
            'customer_id'        => 'nullable|integer',
            'customer_name'      => 'required|string|max:255',
            'warehouse_id'       => 'nullable|integer',
            'warehouse_name'     => 'required|string|max:255',
            'user_id'            => 'nullable|integer',
            'user_name'          => 'nullable|string|max:255',
            'user_role'          => 'nullable|string|max:255',
            'date'               => 'required|string',
            'expiry_date'        => 'nullable|date',
            'tax_rate'           => 'nullable|numeric|min:0|max:100',
            'tax_amount'         => 'nullable|numeric|min:0',
            'discount'           => 'nullable|numeric|min:0',
            'shipping'           => 'nullable|numeric|min:0',
            'grand_total'        => 'required|numeric|min:0',
            // 'Ordered' cannot be spoofed manually via CRUD; it is strictly reserved for atomic Sale conversion.
            'status'             => 'nullable|string|in:Sent,Pending,Accepted,Declined,Expired',
            'notes'              => 'nullable|string',
            'created_by'         => 'nullable|string|max:255',
            'items'              => 'required|array|min:1',
            'items.*.product_id'         => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.unit_id'            => 'nullable|integer',
            'items.*.unit_name'          => 'nullable|string|max:100',
            'items.*.conversion_rate'    => 'nullable|numeric|min:0',
            'items.*.base_unit_price'    => 'nullable|numeric|min:0',
            'items.*.base_quantity'      => 'nullable|numeric|min:0',
            'items.*.product_code'       => 'required|string',
            'items.*.product_name'       => 'required|string',
            'items.*.variant_name'       => 'nullable|string',
            'items.*.unit_price'         => 'required|numeric|min:0',
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.tax'                => 'nullable|numeric|min:0',
            'items.*.discount'           => 'nullable|numeric|min:0',
            'items.*.subtotal'           => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $refNo = $this->acquireUniqueReference('quotation', $request->input('reference_no'));

            $authUser = \App\Models\User::resolveFromRequest($request) ?? $request->user() ?? auth()->user();
            if ($authUser && $authUser->exists) {
                $userId = $authUser->id;
                $userName = $authUser->name;
                $userRole = $authUser->role ?: ($authUser->roleModel?->name ?? 'Sales Staff');
                $creator = $authUser->name ?: 'Sales Staff';
            } else {
                $userId = $validated['user_id'] ?? null;
                $userName = $validated['user_name'] ?? null;
                $userRole = $validated['user_role'] ?? 'Sales Staff';
                $creator = $validated['created_by'] ?? ($userName ?: 'Sales Staff');
            }

            $txDate = app(\App\Services\PurchaseAccountingService::class)
                ->normalizeTransactionDate($validated['date']);

            // Determine status — if expiry_date is in the past, set to Expired automatically
            $expiryDate  = !empty($validated['expiry_date']) ? $validated['expiry_date'] : null;
            $autoStatus  = $validated['status'] ?? 'Sent';
            if ($expiryDate && \Carbon\Carbon::parse($expiryDate)->endOfDay()->isPast()) {
                $autoStatus = 'Expired';
            }

            // Server-side financial integrity calculation (Tax-Exclusive Parity with SaleController)
            $unitService = app(ProductUnitConversionService::class);
            $accounting = $this->accounting();
            $purchaseAccounting = $this->purchaseAccounting();
            $resolvedWarehouseId = $accounting->resolveWarehouseId(
                !empty($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
                $validated['warehouse_name'] ?? null,
                $unitService
            );
            $purchaseAccounting->assertOutletAccess($request, $resolvedWarehouseId, $validated['warehouse_name'] ?? null);

            $discount = (float) ($validated['discount'] ?? 0);
            $taxRate = (float) ($validated['tax_rate'] ?? 0);
            $flatTax = isset($validated['tax_amount']) ? (float) $validated['tax_amount'] : null;
            $shipping = (float) ($validated['shipping'] ?? 0);
            $totals = $accounting->calculateDocumentTotals($validated['items'], $discount, $taxRate, $flatTax, $shipping);
            $itemsSubtotal = $totals['items_subtotal'];
            $discount = $totals['discount'];
            $taxAmount = $totals['tax_amount'];
            $grandTotal = $totals['grand_total'];

            $quotation = Quotation::create([
                'reference_no' => $refNo,
                'customer_id'  => $validated['customer_id'] ?? null,
                'customer_name'=> $validated['customer_name'],
                'warehouse_id' => $resolvedWarehouseId,
                'warehouse_name'=> $validated['warehouse_name'],
                'date'         => $txDate,
                'expiry_date'  => $expiryDate,
                'tax_rate'     => $taxRate,
                'tax_amount'   => $taxAmount,
                'discount'     => $discount,
                'shipping'     => $shipping,
                'grand_total'  => $grandTotal,
                'status'       => $autoStatus,
                'notes'        => $validated['notes'] ?? null,
                'created_by'   => $creator,
                'user_id'      => $userId,
                'user_name'    => $userName,
                'user_role'    => $userRole,
            ]);

            $itemsData = $this->buildQuotationItemRows($quotation->id, $validated['items'], $accounting, $unitService);
            QuotationItem::insert($itemsData);

            DB::commit();
            $this->clearQuotationRelatedCaches();

            $quotation->load(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'customer', 'sale:id,quotation_id,reference_no']);
            $accounting->enrichQuotationResponse($quotation, $accounting->canViewCost($request));

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation created successfully',
                'data' => $quotation,
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
     * Display the specified quotation with all child items.
     */
    public function show(Request $request, $id)
    {
        $quotation = Quotation::with(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'customer', 'sale:id,quotation_id,reference_no'])->find($id);
        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }
        $this->purchaseAccounting()->assertOutletAccess(
            $request,
            $quotation->warehouse_id ? (int)$quotation->warehouse_id : null,
            $quotation->warehouse_name
        );
        $accounting = $this->accounting();
        $accounting->enrichQuotationResponse($quotation, $accounting->canViewCost($request));
        return response()->json(['status' => 'success', 'data' => $quotation]);
    }

    /**
     * Update the specified quotation and sync its items in a single transaction.
     */
    public function update(Request $request, $id)
    {
        $quotation = Quotation::find($id);
        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }
        $this->purchaseAccounting()->assertOutletAccess(
            $request,
            $quotation->warehouse_id ? (int)$quotation->warehouse_id : null,
            $quotation->warehouse_name
        );

        // Commercial Audit Guard: Prevent modifying already converted quotation
        if ($quotation->status === 'Ordered') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot update a quotation that has already been converted to an order/sale.',
            ], 422);
        }

        $validated = $request->validate([
            'reference_no'               => ['nullable', 'string', 'max:100', Rule::unique('quotations', 'reference_no')->ignore($quotation->id)],
            'customer_id'                => 'nullable|integer',
            'customer_name'              => 'required|string|max:255',
            'warehouse_id'               => 'nullable|integer',
            'warehouse_name'             => 'required|string|max:255',
            'user_id'                    => 'nullable|integer',
            'user_name'                  => 'nullable|string|max:255',
            'user_role'                  => 'nullable|string|max:255',
            'date'                       => 'required|string',
            'expiry_date'                => 'nullable|date',
            'tax_rate'                   => 'nullable|numeric|min:0|max:100',
            'tax_amount'                 => 'nullable|numeric|min:0',
            'discount'                   => 'nullable|numeric|min:0',
            'shipping'                   => 'nullable|numeric|min:0',
            'grand_total'                => 'required|numeric|min:0',
            // 'Ordered' cannot be spoofed manually via CRUD; it is strictly reserved for atomic Sale conversion.
            'status'                     => 'nullable|string|in:Sent,Pending,Accepted,Declined,Expired',
            'notes'                      => 'nullable|string',
            'created_by'                 => 'nullable|string|max:255',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.unit_id'            => 'nullable|integer',
            'items.*.unit_name'          => 'nullable|string|max:100',
            'items.*.conversion_rate'    => 'nullable|numeric|min:0',
            'items.*.base_unit_price'    => 'nullable|numeric|min:0',
            'items.*.base_quantity'      => 'nullable|numeric|min:0',
            'items.*.product_code'       => 'required|string',
            'items.*.product_name'       => 'required|string',
            'items.*.variant_name'       => 'nullable|string',
            'items.*.unit_price'         => 'required|numeric|min:0',
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.tax'                => 'nullable|numeric|min:0',
            'items.*.discount'           => 'nullable|numeric|min:0',
            'items.*.subtotal'           => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            // Re-fetch with pessimistic lock inside transaction to prevent TOCTOU race against convertToSale()
            $quotation = Quotation::lockForUpdate()->find($quotation->id);
            if (!$quotation) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
            }
            if ($quotation->status === 'Ordered') {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot update a quotation that has already been converted to an order/sale.',
                ], 422);
            }

            $expiryDate = array_key_exists('expiry_date', $validated)
                ? ($validated['expiry_date'] !== '' ? $validated['expiry_date'] : null)
                : $quotation->expiry_date;
            $newStatus  = $validated['status'] ?? $quotation->status;
            // Auto-expire if expiry_date is in the past
            if ($expiryDate && \Carbon\Carbon::parse($expiryDate)->endOfDay()->isPast()) {
                $newStatus = 'Expired';
            } elseif (array_key_exists('expiry_date', $validated) && $expiryDate === null && $newStatus === 'Expired' && !array_key_exists('status', $validated)) {
                $newStatus = 'Sent';
            }

            // Server-side financial integrity calculation (Tax-Exclusive Parity with SaleController)
            $unitService = app(ProductUnitConversionService::class);
            $accounting = $this->accounting();
            $purchaseAccounting = $this->purchaseAccounting();
            $resolvedWarehouseId = $accounting->resolveWarehouseId(
                !empty($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : ($quotation->warehouse_id ? (int) $quotation->warehouse_id : null),
                $validated['warehouse_name'] ?? $quotation->warehouse_name,
                $unitService
            );
            $purchaseAccounting->assertOutletAccess(
                $request,
                $resolvedWarehouseId,
                $validated['warehouse_name'] ?? $quotation->warehouse_name
            );

            $discount = (float) ($validated['discount'] ?? 0);
            $taxRate = (float) ($validated['tax_rate'] ?? 0);
            $flatTax = isset($validated['tax_amount']) ? (float) $validated['tax_amount'] : null;
            $shipping = (float) ($validated['shipping'] ?? 0);
            $totals = $accounting->calculateDocumentTotals($validated['items'], $discount, $taxRate, $flatTax, $shipping);
            $discount = $totals['discount'];
            $taxAmount = $totals['tax_amount'];
            $grandTotal = $totals['grand_total'];

            $txDate = app(\App\Services\PurchaseAccountingService::class)
                ->normalizeTransactionDate($validated['date'], (string)$quotation->date);

            $updateData = [
                'customer_id'   => $validated['customer_id'] ?? null,
                'customer_name' => $validated['customer_name'],
                'warehouse_id'  => $resolvedWarehouseId,
                'warehouse_name'=> $validated['warehouse_name'],
                'date'          => $txDate,
                'expiry_date'   => $expiryDate,
                'tax_rate'      => $taxRate,
                'tax_amount'    => $taxAmount,
                'discount'      => $discount,
                'shipping'      => $shipping,
                'grand_total'   => $grandTotal,
                'status'        => $newStatus,
                'notes'         => $validated['notes'] ?? null,
            ];


            $authUser = \App\Models\User::resolveFromRequest($request) ?? $request->user() ?? auth()->user();
            if (!empty($validated['reference_no'])) {
                $updateData['reference_no'] = $validated['reference_no'];
            }
            if ($authUser && $authUser->exists) {
                $effectiveRole = $authUser->role ?: ($authUser->roleModel?->name ?? 'Sales Staff');
                if (empty($quotation->created_by) && $authUser->name) {
                    $updateData['created_by'] = $authUser->name;
                }
                if (empty($quotation->user_id)) {
                    $updateData['user_id'] = $authUser->id;
                }
                if (empty($quotation->user_name) && $authUser->name) {
                    $updateData['user_name'] = $authUser->name;
                }
                if (empty($quotation->user_role) && $effectiveRole) {
                    $updateData['user_role'] = $effectiveRole;
                }
            } else {
                if (!empty($validated['created_by'])) {
                    $updateData['created_by'] = $validated['created_by'];
                }
                if (!empty($validated['user_id'])) {
                    $updateData['user_id'] = $validated['user_id'];
                }
                if (!empty($validated['user_name'])) {
                    $updateData['user_name'] = $validated['user_name'];
                }
                if (!empty($validated['user_role'])) {
                    $updateData['user_role'] = $validated['user_role'];
                }
            }

            $quotation->update($updateData);

            // Replace items in batch
            $quotation->items()->delete();
            $itemsData = $this->buildQuotationItemRows($quotation->id, $validated['items'], $accounting, $unitService);
            QuotationItem::insert($itemsData);

            DB::commit();
            $this->clearQuotationRelatedCaches();

            $fresh = $quotation->fresh(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'customer', 'sale:id,quotation_id,reference_no']);
            $accounting->enrichQuotationResponse($fresh, $accounting->canViewCost($request));

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation updated successfully',
                'data' => $fresh,
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

    /**
     * Remove the specified quotation and all its child items.
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            // Pessimistic lock eliminates TOCTOU race with concurrent convertToSale()
            $quotation = Quotation::lockForUpdate()->find($id);
            if (!$quotation) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
            }
            $this->purchaseAccounting()->assertOutletAccess(
                $request,
                $quotation->warehouse_id ? (int)$quotation->warehouse_id : null,
                $quotation->warehouse_name
            );

            // Commercial Audit Guard: Prevent deleting already converted quotation
            if ($quotation->status === 'Ordered') {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot delete a quotation that has already been converted to an order/sale.',
                ], 422);
            }

            $quotation->items()->delete();
            $quotation->delete();
            DB::commit();
            $this->clearQuotationRelatedCaches();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation deleted successfully',
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
     * Convert the specified quotation into a completed sale, and mark quotation as Ordered.
     *
     * Accounting integrity:
     * 1. Duplicate guard — lockForUpdate() & status === 'Ordered' → 422 (prevents double-conversion race condition)
     * 2. Overpayment & change calculation — paid_amount capped at grand_total, accurate change_amount
     * 3. Cash Register increment — only increments actual paid_amount (drawer not inflated by change)
     * 4. Accounting guards — Walk-in credit policy, Customer credit limit, Below-cost authorization
     * 5. Pre-check stock balance & batch sync aggregates
     * 6. FIFO depletion per item with variant cost_price & purchase_price fallbacks
     * 7. Targeted cache eviction (no full file cache flush)
     */
    public function convertToSale(Request $request, $id)
    {
        $request->validate([
            'sale_reference_no'          => 'nullable|string|max:100',
            'payment_type'               => 'nullable|string|max:100',
            'payment_status'             => 'nullable|string|in:Paid,Partial,Unpaid',
            'paid_amount'                => 'nullable|numeric|min:0',
            'received_amount'            => 'nullable|numeric|min:0',
            'change_amount'              => 'nullable|numeric|min:0',
            'cash_register_id'           => 'nullable|integer|min:1',
            'manager_pin'                => 'nullable|string|max:50',
            'payments'                   => 'nullable|array',
            'payments.*.amount'          => 'required_with:payments|numeric|min:0.01',
            'payments.*.payment_type'    => 'nullable|string|max:100',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount'   => 'nullable|numeric|min:0',
            'payments.*.payment_date'    => 'nullable|string',
            'payments.*.cash_register_id' => 'nullable|integer|min:1',
            'payments.*.notes'           => 'nullable|string',
        ]);

        if ($request->has('paid_amount') && $request->input('paid_amount') === null && $request->input('payment_status') !== 'Unpaid') {
            return response()->json([
                'status'  => 'error',
                'message' => 'paid_amount cannot be null when provided. Pass 0 for Unpaid sales or omit the field to pay in full.',
                'errors'  => ['paid_amount' => ['paid_amount cannot be null.']],
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Pessimistic locking prevents race conditions / double conversion fraud
            $quotation = Quotation::with('items')->lockForUpdate()->find($id);
            if (!$quotation) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
            }

            // Guard against empty quotations with zero line items
            if ($quotation->items->isEmpty()) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot convert an empty quotation with no line items.',
                    'errors'  => ['items' => ['At least one quotation line item is required before converting to a Sale.']],
                ], 422);
            }

            // ── Commercial & Accounting Guards ────────────────────────────────────
            $accounting = $this->accounting();
            $purchaseAccounting = $this->purchaseAccounting();
            $purchaseAccounting->assertOutletAccess(
                $request,
                $quotation->warehouse_id ? (int)$quotation->warehouse_id : null,
                $quotation->warehouse_name
            );
            $blockReason = $accounting->conversionBlockReason($quotation);
            if ($blockReason !== null) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => $blockReason,
                ], 422);
            }

            $unitService = app(ProductUnitConversionService::class);
            $fifoService = app(\App\Services\FifoInventoryService::class);
            $warehouseId = $unitService->warehouseId($quotation->warehouse_id, $quotation->warehouse_name);

            // Atomic sequential Sale reference code via shared ReferenceCounter trait
            $refNo = $this->acquireUniqueReference(
                'sale',
                $request->input('sale_reference_no'),
                $warehouseId ? (int)$warehouseId : ($quotation->warehouse_id ? (int)$quotation->warehouse_id : 0)
            );
            $paymentType = (string) $request->input('payment_type', 'Cash');
            $paymentsInput = $request->input('payments');
            $hasPaymentsArray = is_array($paymentsInput) && count($paymentsInput) > 0;

            // Server-side recalculation of quotation totals from stored line items (never trust stale header totals)
            $explicitFlatTax = ((float)$quotation->tax_rate <= 0.0001 && (float)$quotation->tax_amount > 0)
                ? (float)$quotation->tax_amount
                : null;
            $recalcTotals = $accounting->calculateDocumentTotals(
                $quotation->items->map(fn($it) => [
                    'quantity'   => (float)$it->quantity,
                    'unit_price' => (float)$it->unit_price,
                    'discount'   => (float)($it->discount ?? 0),
                    'tax'        => (float)($it->tax ?? 0),
                ])->all(),
                (float)($quotation->discount ?? 0),
                (float)($quotation->tax_rate ?? 0),
                $explicitFlatTax,
                (float)($quotation->shipping ?? 0)
            );
            $subtotal = $recalcTotals['items_subtotal'];
            $recalcTaxAmount = $recalcTotals['tax_amount'];
            $recalcDiscount = $recalcTotals['discount'];
            $grandTotal = $recalcTotals['grand_total'];

            // Financial Integrity: Overpayment handling, Non-Cash Cap, Split Payment reconciliation & non-negative Change Amount
            if ($hasPaymentsArray) {
                $paymentsSum = round(array_sum(array_map(
                    fn ($p) => max(0.0, (float) ($p['amount'] ?? 0)),
                    $paymentsInput
                )), 2);
                $nonCashTenderedSum = 0.0;
                $hasCashTenderLeg = false;
                foreach ($paymentsInput as $p) {
                    $pAmt = max(0.0, (float) ($p['amount'] ?? 0));
                    $pRec = isset($p['received_amount']) ? max($pAmt, (float) $p['received_amount']) : $pAmt;
                    $pTypeLeg = (string) ($p['payment_type'] ?? $paymentType);
                    if ($purchaseAccounting->isCashPaymentType($pTypeLeg)) {
                        $hasCashTenderLeg = true;
                    } else {
                        $nonCashTenderedSum += $pRec;
                    }
                }
                if ($nonCashTenderedSum > $grandTotal + 0.01) {
                    DB::rollBack();
                    $msg = 'Non-cash payment total (' . number_format($nonCashTenderedSum, 2) . ') cannot exceed the invoice grand total (' . number_format($grandTotal, 2) . ').';
                    return response()->json([
                        'status' => 'error',
                        'message' => $msg,
                        'errors' => ['payments' => [$msg]],
                    ], 422);
                }
                if (!$hasCashTenderLeg && $paymentsSum > $grandTotal + 0.01) {
                    DB::rollBack();
                    $msg = 'Non-cash payment total (' . number_format($paymentsSum, 2) . ') cannot exceed the invoice grand total (' . number_format($grandTotal, 2) . ').';
                    return response()->json([
                        'status' => 'error',
                        'message' => $msg,
                        'errors' => ['payments' => [$msg]],
                    ], 422);
                }
                if ($request->filled('paid_amount')) {
                    $explicitPaid = (float) $request->input('paid_amount');
                    $clampedExplicitPaid = min($grandTotal, max(0.0, $explicitPaid));
                    if (abs($paymentsSum - $explicitPaid) > 0.01 && abs($paymentsSum - $clampedExplicitPaid) > 0.01) {
                        DB::rollBack();
                        $msg = "Payments array total ({$paymentsSum}) does not match paid_amount ({$explicitPaid}).";
                        return response()->json([
                            'status' => 'error',
                            'message' => $msg,
                            'errors' => ['payments' => [$msg]],
                        ], 422);
                    }
                }
                $rawPaid = $paymentsSum;
            } elseif ($request->input('payment_status') === 'Unpaid') {
                $rawPaid = 0.0;
            } else {
                $rawPaid = ($request->has('paid_amount') && $request->input('paid_amount') !== null)
                    ? (float) $request->input('paid_amount')
                    : $grandTotal;
                $isSingleCash = $purchaseAccounting->isCashPaymentType($paymentType);
                $explicitReceived = ($request->has('received_amount') && $request->input('received_amount') !== null)
                    ? (float) $request->input('received_amount')
                    : $rawPaid;
                if (!$isSingleCash && ($rawPaid > $grandTotal + 0.01 || $explicitReceived > $grandTotal + 0.01)) {
                    DB::rollBack();
                    $msg = 'Non-cash payment cannot exceed the invoice grand total (' . number_format($grandTotal, 2) . ').';
                    return response()->json([
                        'status' => 'error',
                        'message' => $msg,
                        'errors' => ['paid_amount' => [$msg]],
                    ], 422);
                }
            }
            $paidAmount     = min($grandTotal, max(0.0, $rawPaid));
            $receivedAmount = ($request->has('received_amount') && $request->input('received_amount') !== null)
                ? max($paidAmount, (float) $request->input('received_amount'))
                : max($paidAmount, $rawPaid);
            $changeAmount   = max(0.0, round($receivedAmount - $paidAmount, 2));
            $dueAmount      = max(0.0, round($grandTotal - $paidAmount, 2));
            $paymentStatus  = $dueAmount <= 0.001 ? 'Paid' : ($paidAmount > 0.001 ? 'Partial' : 'Unpaid');

            // User & Cash Register Resolution (prioritize authenticated actor over request-body hints)
            $authUser = \App\Models\User::resolveFromRequest($request) ?? $request->user() ?? auth()->user();
            if ($authUser && $authUser->exists) {
                $currentUserId = $authUser->id;
                $currentUserName = $authUser->name ?: ($quotation->user_name ?? ($quotation->created_by ?? 'Sales Staff'));
                $currentUserRole = $authUser->role ?: ($authUser->roleModel?->name ?? ($quotation->user_role ?? 'Cashier'));
            } else {
                $currentUserId = $request->input('user_id') ?? $quotation->user_id;
                $currentUserName = $request->input('created_by') ?? ($quotation->user_name ?? ($quotation->created_by ?? 'Sales Staff'));
                $currentUserRole = $request->input('user_role') ?? ($quotation->user_role ?? 'Cashier');
            }

            // 🛡️ Guard 1: Walk-in / Anonymous Customer Credit Policy Guard
            $cId = $quotation->customer_id;
            $cName = strtolower(trim((string)($quotation->customer_name ?? '')));
            $isWalkIn = str_contains($cName, 'walk-in') || str_contains($cName, 'walk in') || empty($cId);
            if (!$isWalkIn && $cId) {
                $cObj = Customer::find($cId);
                if ($cObj && (str_contains(strtolower($cObj->name), 'walk-in') || str_contains(strtolower($cObj->name), 'walk in'))) {
                    $isWalkIn = true;
                }
            }
            if ($isWalkIn && ($paymentStatus === 'Unpaid' || $paymentStatus === 'Partial' || $dueAmount > 0.01)) {
                DB::rollBack();
                $msg = 'Walk-in / unregistered customer များအတွက် အကြွေး (Unpaid / Partial) ဖြင့် ရောင်းချခွင့်မပြုပါ။ ငွေအပြည့်အကျေ ပေးချေရပါမည်။';
                return response()->json([
                    'status' => 'error',
                    'message' => $msg,
                    'errors' => ['customer_id' => [$msg]],
                ], 422);
            }

            // 🛡️ Guard 2: Customer Credit Limit Guard (locked; deducts active return credit notes to match AR ledger)
            if (!$isWalkIn && $cId && $dueAmount > 0.01) {
                $custObj = Customer::where('id', $cId)->lockForUpdate()->first();
                if ($custObj && $custObj->credit_limit !== null && (float)$custObj->credit_limit > 0) {
                    $existingDueQuery = Sale::where('customer_id', $custObj->id);
                    Customer::applyReceivableScope($existingDueQuery);
                    $openReturnCreditQuery = \App\Models\SaleReturn::where('customer_id', $custObj->id);
                    Customer::applyActiveReturnCreditScope($openReturnCreditQuery);
                    $currentOutstanding = max(
                        0.0,
                        round((float)$existingDueQuery->sum('due_amount') - (float)$openReturnCreditQuery->sum('due_amount'), 2)
                    );
                    if ($currentOutstanding + $dueAmount > (float)$custObj->credit_limit + 0.001) {
                        $limitStr = number_format((float)$custObj->credit_limit, 2);
                        $currentStr = number_format($currentOutstanding, 2);
                        $newDueStr = number_format($dueAmount, 2);
                        $msg = "Customer '{$custObj->name}' ၏ အကြွေးကန့်သတ်ချက် (Credit Limit: {$limitStr}) ကျော်လွန်နေပါသည်။ လက်ရှိကျန်အကြွေး: {$currentStr}, ယခုအကြွေး: {$newDueStr}.";
                        DB::rollBack();
                        return response()->json([
                            'status' => 'error',
                            'message' => $msg,
                            'errors' => [
                                'customer_id' => [$msg],
                                'credit_limit' => [$msg],
                            ],
                        ], 422);
                    }
                }
            }

            $cashRegisterId = $request->filled('cash_register_id') ? (int) $request->input('cash_register_id') : null;
            if ($cashRegisterId !== null) {
                $explicitRegister = CashRegister::where('id', $cashRegisterId)->lockForUpdate()->first();
                if (!$explicitRegister || !in_array(strtolower(trim((string) $explicitRegister->status)), ['open', 'opened'], true)) {
                    DB::rollBack();
                    $msg = 'Selected cash register is closed or does not exist. Please select an open cash register.';
                    return response()->json([
                        'status' => 'error',
                        'message' => $msg,
                        'errors' => ['cash_register_id' => [$msg]],
                    ], 422);
                }
                if ($warehouseId && $explicitRegister->warehouse_id && (int)$explicitRegister->warehouse_id !== (int)$warehouseId) {
                    DB::rollBack();
                    $msg = 'Selected cash register belongs to a different warehouse/branch than the quotation.';
                    return response()->json([
                        'status' => 'error',
                        'message' => $msg,
                        'errors' => ['cash_register_id' => [$msg]],
                    ], 422);
                }
                $isManagerActor = in_array(strtolower((string)$currentUserRole), ['super admin', 'admin', 'manager'], true)
                    || ($authUser?->isSuperAdmin() ?? false);
                if (!$isManagerActor && $explicitRegister->user_id && $currentUserId && (int)$explicitRegister->user_id !== (int)$currentUserId) {
                    DB::rollBack();
                    $msg = 'You cannot post transactions to another staff member\'s open cash register.';
                    return response()->json([
                        'status' => 'error',
                        'message' => $msg,
                        'errors' => ['cash_register_id' => [$msg]],
                    ], 422);
                }
            } elseif ($warehouseId && $currentUserId) {
                $userReg = CashRegister::whereIn('status', ['open', 'Open', 'opened'])
                    ->where('warehouse_id', $warehouseId)
                    ->where('user_id', $currentUserId)
                    ->latest()
                    ->first();
                if ($userReg) {
                    $cashRegisterId = $userReg->id;
                }
            }

            if ($hasPaymentsArray) {
                $isManagerActorLeg = in_array(strtolower((string)$currentUserRole), ['super admin', 'admin', 'manager'], true)
                    || ($authUser?->isSuperAdmin() ?? false);
                $distinctLegRegIds = collect($paymentsInput)
                    ->pluck('cash_register_id')
                    ->filter(fn($rid) => !empty($rid))
                    ->map(fn($rid) => (int)$rid)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                foreach ($distinctLegRegIds as $legRegId) {
                    $legReg = CashRegister::where('id', $legRegId)->lockForUpdate()->first();
                    if (!$legReg || !in_array(strtolower(trim((string) $legReg->status)), ['open', 'opened'], true)) {
                        DB::rollBack();
                        $msg = "Cash register #{$legRegId} on a split payment line is closed or does not exist.";
                        return response()->json([
                            'status' => 'error',
                            'message' => $msg,
                            'errors' => ['payments' => [$msg]],
                        ], 422);
                    }
                    if ($warehouseId && $legReg->warehouse_id && (int)$legReg->warehouse_id !== (int)$warehouseId) {
                        DB::rollBack();
                        $msg = "Cash register #{$legRegId} belongs to a different warehouse/branch than the quotation.";
                        return response()->json([
                            'status' => 'error',
                            'message' => $msg,
                            'errors' => ['payments' => [$msg]],
                        ], 422);
                    }
                    if (!$isManagerActorLeg && $legReg->user_id && $currentUserId && (int)$legReg->user_id !== (int)$currentUserId) {
                        DB::rollBack();
                        $msg = 'You cannot post split payment lines to another staff member\'s open cash register.';
                        return response()->json([
                            'status' => 'error',
                            'message' => $msg,
                            'errors' => ['payments' => [$msg]],
                        ], 422);
                    }
                }
            }

            // Pre-load all products and variants in bulk to eliminate N+1 queries & deadlocks
            $itemProductIds = $quotation->items->pluck('product_id')->filter()->unique()->values()->all();
            sort($itemProductIds);
            $itemProductCodes = $quotation->items->pluck('product_code')->filter()->unique()->values()->all();
            sort($itemProductCodes);
            $productsMap = Product::with(['variants', 'unitLinks.unit'])
                ->where(function ($q) use ($itemProductIds, $itemProductCodes) {
                    if (!empty($itemProductIds)) {
                        $q->whereIn('id', $itemProductIds);
                    }
                    if (!empty($itemProductCodes)) {
                        $q->orWhereIn('code', $itemProductCodes);
                    }
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $productsById = $productsMap->keyBy('id');
            $productsByCode = $productsMap->keyBy('code');

            // 🛡️ Guard 2b: Strictly require every converted line item to map to a real catalog Product & concrete Variant
            foreach ($quotation->items as $it) {
                $prod = (!empty($it->product_id) && isset($productsById[$it->product_id]))
                    ? $productsById[$it->product_id]
                    : ($productsByCode[$it->product_code] ?? null);
                if (!$prod) {
                    $msg = "Cannot convert quotation item '{$it->product_name}': linked catalog product was not found (would cause missing COGS and un-tracked stock).";
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => $msg,
                        'errors' => ['items' => [$msg]],
                    ], 422);
                }
                $vId = !empty($it->product_variant_id) ? (int) $it->product_variant_id : null;
                $hasAnyVariants = $prod->has_variants || ($prod->relationLoaded('variants') ? $prod->variants->isNotEmpty() : $prod->variants()->exists());
                if ($hasAnyVariants && empty($vId)) {
                    $msg = "Product '{$prod->name}' has variants. Please select a specific variation before converting to a Sale.";
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => $msg,
                        'errors' => ['items' => [$msg], 'product_variant_id' => [$msg]],
                    ], 422);
                }
            }

            // 🛡️ Guard 3: Below-Cost Selling Guard
            $managerPin = $request->input('manager_pin');
            $managerPinSetting = (string)\App\Models\Setting::get('manager_pin', '1234');
            $authRole = $authUser
                ? ($authUser->roleModel?->name ?? ($authUser->role ?? $currentUserRole))
                : $currentUserRole;
            $isManagerAuthorized = in_array(strtolower((string)$authRole), ['super admin', 'admin', 'manager'])
                || (!empty($managerPin) && (string)$managerPin === $managerPinSetting);

            if (!$isManagerAuthorized) {
                $totalEstimatedBaseCost = 0.0;
                foreach ($quotation->items as $it) {
                    $prod = (!empty($it->product_id) && isset($productsById[$it->product_id]))
                        ? $productsById[$it->product_id]
                        : ($productsByCode[$it->product_code] ?? null);
                    if (!$prod) continue;
                    $vId = !empty($it->product_variant_id) ? (int)$it->product_variant_id : null;
                    $vObj = ($vId && $prod->relationLoaded('variants')) ? $prod->variants->firstWhere('id', $vId) : null;
                    $costPrice = (float)($vObj?->cost_price ?: ($vObj?->purchase_price ?: ($prod->cost_price ?: ($prod->purchase_price ?: 0))));
                    if ($costPrice <= 0) continue;

                    $cRate = (float)($it->conversion_rate ?? 1.0);
                    if ($cRate <= 0) $cRate = 1.0;
                    $bQty = ($it->base_quantity !== null && (float)$it->base_quantity > 0)
                        ? (float)$it->base_quantity
                        : round((float)$it->quantity * $cRate, 4);
                    $totalEstimatedBaseCost += ($bQty * $costPrice);

                    $sub = (float)$it->subtotal;
                    $tx = (float)($it->tax ?? 0);
                    $netRev = max(0.0, $sub - $tx);
                    $effBasePrice = $bQty > 0 ? ($netRev / $bQty) : 0.0;

                    if ($effBasePrice < ($costPrice - 0.01)) {
                        $costStr = number_format($costPrice, 2);
                        $priceStr = number_format($effBasePrice, 2);
                        $pName = $it->product_name;
                        $msg = "ကုန်ပစ္စည်း '{$pName}' ကို အရင်းဈေး ({$costStr}) အောက် လျှော့ချရောင်းချခွင့်မပြုပါ။ (လက်ရှိရောင်းဈေး: {$priceStr}). မန်နေဂျာ PIN ခွင့်ပြုချက် လိုအပ်ပါသည်။";
                        DB::rollBack();
                        return response()->json([
                            'status' => 'error',
                            'message' => $msg,
                            'errors' => ['items' => [$msg], 'below_cost' => [$msg]],
                        ], 422);
                    }
                }

                // Check Order-Level Discount Below-Cost (exclude both order tax and item-level taxes from net merchandise revenue)
                $orderDiscount = (float)($quotation->discount ?? 0);
                // Sum tax-exclusive net line revenues directly (excluding item-level taxes & shipping).
                $taxExclusiveBaseTotal = (float)$quotation->items->sum(function ($it) {
                    $gross = (float)$it->quantity * (float)$it->unit_price;
                    $itemDisc = max(0.0, (float)($it->discount ?? 0));
                    return round(max(0.0, $gross - $itemDisc), 2);
                });
                $orderNetRevenue = max(0.0, round($taxExclusiveBaseTotal - $orderDiscount, 2));
                if ($totalEstimatedBaseCost > 0 && $orderNetRevenue < ($totalEstimatedBaseCost - 0.01)) {
                    $costStr = number_format($totalEstimatedBaseCost, 2);
                    $revStr = number_format($orderNetRevenue, 2);
                    $msg = "ဘောင်ချာတစ်ခုလုံး၏ အသားတင်ရောင်းရငွေ ({$revStr}) သည် စုစုပေါင်းအရင်းစရိတ် ({$costStr}) အောက် လျော့နည်းနေပါသည်။ မန်နေဂျာ PIN ခွင့်ပြုချက် လိုအပ်ပါသည်။";
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => $msg,
                        'errors' => ['discount' => [$msg], 'below_cost' => [$msg]],
                    ], 422);
                }
            }

            // 🛡️ Stock Pre-Check: Aggregate multi-unit rows for the same product/variant (e.g. 1 Box + 5 Pcs)
            if ($warehouseId) {
                $requiredStockByItem = [];
                foreach ($quotation->items as $it) {
                    $prod = (!empty($it->product_id) && isset($productsById[$it->product_id]))
                        ? $productsById[$it->product_id]
                        : ($productsByCode[$it->product_code] ?? null);
                    if (!$prod) continue;

                    $cRate = (float)($it->conversion_rate ?? 1.0);
                    if ($cRate <= 0) $cRate = 1.0;
                    $bQty = ($it->base_quantity !== null && (float)$it->base_quantity > 0)
                        ? (float)$it->base_quantity
                        : round((float)$it->quantity * $cRate, 4);
                    $vId = !empty($it->product_variant_id) ? (int)$it->product_variant_id : null;
                    $aggKey = $prod->id . '_' . ($vId ?? '0');

                    if (!isset($requiredStockByItem[$aggKey])) {
                        $requiredStockByItem[$aggKey] = [
                            'product' => $prod,
                            'variant_id' => $vId,
                            'total_base_qty' => 0.0,
                        ];
                    }
                    $requiredStockByItem[$aggKey]['total_base_qty'] += $bQty;
                }

                $reqProductIds = array_unique(array_map(fn($r) => $r['product']->id, $requiredStockByItem));
                $stockBalances = \App\Models\StockBalance::where('warehouse_id', $warehouseId)
                    ->whereIn('product_id', $reqProductIds)
                    ->orderBy('product_id')
                    ->orderBy('product_variant_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(fn($sb) => $sb->product_id . '_' . ($sb->product_variant_id ?? '0'));

                $existingBalanceProductIds = \App\Models\StockBalance::whereIn('product_id', $reqProductIds)
                    ->distinct()
                    ->pluck('product_id')
                    ->flip()
                    ->all();

                foreach ($requiredStockByItem as $req) {
                    $prod = $req['product'];
                    $vId = $req['variant_id'];
                    $totalBaseNeeded = $req['total_base_qty'];

                    $balKey = $prod->id . '_' . ($vId ?? '0');
                    $sb = $stockBalances->get($balKey);
                    $hasAnyWarehouseBalance = isset($existingBalanceProductIds[$prod->id]);
                    $available = $sb !== null
                        ? (float)$sb->quantity
                        : ($vId === null && !$hasAnyWarehouseBalance ? (float)$prod->stock_quantity : 0.0);

                    if ($totalBaseNeeded > $available + 0.0001) {
                        $varName = '';
                        if ($vId && $prod->relationLoaded('variants')) {
                            $vObj = $prod->variants->firstWhere('id', $vId);
                            if ($vObj) $varName = " ({$vObj->name})";
                        }
                        $whName = $quotation->warehouse_name ?? 'Selected Warehouse';
                        DB::rollBack();
                        return response()->json([
                            'status' => 'error',
                            'message' => "Insufficient stock for '{$prod->name}{$varName}' in '{$whName}'. Total requested: {$totalBaseNeeded}, Available: {$available}.",
                        ], 422);
                    }
                }
            }

            // Create Sale record with full traceability & register links (using store timezone now())
            $saleTimestamp = now()->format('Y-m-d H:i:s');
            $sale = Sale::create([
                'reference_no'     => $refNo,
                'quotation_id'     => $quotation->id,
                'customer_id'      => $quotation->customer_id,
                'customer_name'    => $quotation->customer_name,
                'warehouse_id'     => $warehouseId,
                'warehouse_name'   => $quotation->warehouse_name,
                'date'             => $saleTimestamp,
                'status'           => 'Completed',
                'tax_rate'         => (float)$quotation->tax_rate,
                'tax_amount'       => $recalcTaxAmount,
                'discount'         => $recalcDiscount,
                'shipping'         => (float)$quotation->shipping,
                'subtotal'         => $subtotal,
                'grand_total'      => $grandTotal,
                'paid_amount'      => $paidAmount,
                'due_amount'       => $dueAmount,
                'change_amount'    => $changeAmount,
                'payment_status'   => $paymentStatus,
                'payment_type'     => $paymentType,
                'notes'            => 'Converted from Quotation: ' . $quotation->reference_no . ($quotation->notes ? ' | ' . $quotation->notes : ''),
                'pos_flag'         => true,
                'cash_register_id' => $cashRegisterId,
                'created_by'       => $currentUserName,
                'user_id'          => $currentUserId,
                'user_name'        => $currentUserName,
                'user_role'        => $currentUserRole,
                'total_cogs'       => 0,
            ]);

            $totalSaleCogs = 0.0;
            $touchedProductIds = [];
            $touchedVariantIds = [];

            foreach ($quotation->items as $item) {
                $product = (!empty($item->product_id) && isset($productsById[$item->product_id]))
                    ? $productsById[$item->product_id]
                    : ($productsByCode[$item->product_code] ?? null);

                $convRate = (float)($item->conversion_rate ?? 1.0);
                if ($convRate <= 0) $convRate = 1.0;
                $qty      = (float)$item->quantity;
                $unitPrice = (float)$item->unit_price;
                $baseQty  = $item->base_quantity !== null && (float)$item->base_quantity > 0
                    ? (float)$item->base_quantity
                    : ($qty * $convRate);
                $basePrice = $item->base_unit_price !== null && (float)$item->base_unit_price > 0
                    ? (float)$item->base_unit_price
                    : ($unitPrice / $convRate);
                $lineGross = max(0.0, round($unitPrice * $qty, 2));
                $lineDisc = round(min($lineGross, max(0.0, (float)($item->discount ?? 0))), 2);
                $lineTax = round(max(0.0, (float)($item->tax ?? 0)), 2);
                $lineSubtotal = round(max(0.0, $lineGross - $lineDisc) + $lineTax, 2);

                $saleItem = SaleItem::create([
                    'sale_id'            => $sale->id,
                    'product_id'         => $product?->id ?? $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'unit_id'            => $item->unit_id,
                    'unit_name'          => $item->unit_name ?? 'pcs',
                    'conversion_rate'    => $convRate,
                    'base_quantity'      => $baseQty,
                    'base_unit_price'    => $basePrice,
                    'product_code'       => $item->product_code,
                    'product_name'       => $item->product_name,
                    'variant_name'       => $item->variant_name,
                    'unit_price'         => $unitPrice,
                    'quantity'           => $qty,
                    'tax'                => $lineTax,
                    'discount'           => $lineDisc,
                    'subtotal'           => $lineSubtotal,
                    'base_unit_cost'     => 0,
                    'total_cogs'         => 0,
                ]);

                // FIFO Cost Depletion + Stock Deduction (run FIFO first so StockMovement records authoritative unitCost)
                if ($product) {
                    $movementUnitCost = null;
                    if ($warehouseId && $baseQty > 0) {
                        $fallbackCost = 0.0;
                        if ($item->product_variant_id && $product->relationLoaded('variants')) {
                            $varObj = $product->variants->firstWhere('id', $item->product_variant_id);
                            $fallbackCost = (float)($varObj?->cost_price ?: ($varObj?->purchase_price ?: 0));
                        }
                        if ($fallbackCost <= 0) {
                            $fallbackCost = (float)($product->cost_price ?: ($product->purchase_price ?: 0));
                        }

                        $depletion = $fifoService->depleteFifo(
                            warehouseId:   (int)$warehouseId,
                            productId:     (int)$product->id,
                            variantId:     $item->product_variant_id,
                            neededBaseQty: (float)$baseQty,
                            saleId:        (int)$sale->id,
                            saleItemId:    (int)$saleItem->id,
                            saleDate:      $sale->date,
                            fallbackCost:  $fallbackCost
                        );

                        $saleItem->update([
                            'base_unit_cost' => $depletion['avg_unit_cost'],
                            'total_cogs'     => $depletion['total_cogs'],
                        ]);
                        $totalSaleCogs += (float)$depletion['total_cogs'];
                        if ((float)$depletion['avg_unit_cost'] > 0) {
                            $movementUnitCost = (float)$depletion['avg_unit_cost'];
                        }
                    }

                    $unitService->applyStock(
                        product: $product,
                        warehouseId: $warehouseId,
                        variantId: $item->product_variant_id,
                        baseQuantity: $baseQty,
                        direction: 'out',
                        movementType: 'sale',
                        referenceType: 'sale',
                        referenceId: (string)$sale->id,
                        note: 'Converted from Quotation ' . $quotation->reference_no,
                        notifyRestock: true,
                        allowOverdraft: false,
                        syncAggregates: false,
                        unitCost: $movementUnitCost,
                        movementDate: $sale->date
                    );
                    $touchedProductIds[] = $product->id;
                    if ($item->product_variant_id) {
                        $touchedVariantIds[] = (int)$item->product_variant_id;
                    }
                }
            }

            // Batch sync aggregates once outside loop (eliminates N+1 queries)
            if (!empty($touchedProductIds) || !empty($touchedVariantIds)) {
                $unitService->syncAggregatesForProducts(array_values(array_unique($touchedProductIds)), array_values(array_unique($touchedVariantIds)));
            }

            // Persist total COGS to the sale header for P&L reporting
            if ($totalSaleCogs > 0) {
                $sale->update(['total_cogs' => round($totalSaleCogs, 2)]);
            }

            // Record Sale Payment transaction(s) — allocate non-cash legs before cash so cash excess never displaces card/digital tenders
            $cashAddedByRegister = [];
            $payStamp = now()->format('YmdHis');
            if ($hasPaymentsArray) {
                $runningRemainingDue = (float) $grandTotal;
                $nonCashIndices = [];
                $cashIndices = [];
                foreach ($paymentsInput as $idx => $p) {
                    $pTypeTmp = (string)($p['payment_type'] ?? $paymentType);
                    if ($purchaseAccounting->isCashPaymentType($pTypeTmp)) {
                        $cashIndices[] = $idx;
                    } else {
                        $nonCashIndices[] = $idx;
                    }
                }
                $allocatedLines = [];
                foreach (array_merge($nonCashIndices, $cashIndices) as $idx) {
                    $p = $paymentsInput[$idx];
                    $rawAmount = (float) ($p['amount'] ?? 0);
                    if ($rawAmount <= 0) {
                        continue;
                    }
                    $netAmount = min($runningRemainingDue, $rawAmount);
                    $runningRemainingDue = max(0.0, round($runningRemainingDue - $netAmount, 2));
                    $pType = (string) ($p['payment_type'] ?? $paymentType);
                    $isCashLeg = $purchaseAccounting->isCashPaymentType($pType);
                    $recAmt = $isCashLeg && isset($p['received_amount'])
                        ? max($netAmount, (float) $p['received_amount'])
                        : ($isCashLeg ? $rawAmount : $netAmount);
                    $chgAmt = $isCashLeg ? max(0.0, round($recAmt - $netAmount, 2)) : 0.0;
                    if ($netAmount <= 0 && count($paymentsInput) > 1 && $chgAmt <= 0.001) {
                        continue;
                    }
                    $allocatedLines[$idx] = [
                        'p' => $p,
                        'payment_type' => $pType,
                        'amount' => $netAmount,
                        'received_amount' => $recAmt,
                        'change_amount' => $chgAmt,
                    ];
                }
                ksort($allocatedLines);
                foreach ($allocatedLines as $idx => $line) {
                    $p = $line['p'];
                    $lineIsCash = $purchaseAccounting->isCashPaymentType($line['payment_type']);
                    $lineRegId = !empty($p['cash_register_id'])
                        ? (int)$p['cash_register_id']
                        : $cashRegisterId;
                    SalePayment::create([
                        'sale_id'          => $sale->id,
                        'cash_register_id' => $lineRegId,
                        'reference_no'     => 'PAY-' . $payStamp . '-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) . '-' . ($idx + 1),
                        'payment_date'     => !empty($p['payment_date']) ? $purchaseAccounting->normalizeTransactionDate($p['payment_date'], $sale->date) : $sale->date,
                        'payment_type'     => $line['payment_type'],
                        'amount'           => $line['amount'],
                        'received_amount'  => $line['received_amount'],
                        'change_amount'    => $line['change_amount'],
                        'notes'            => $p['notes'] ?? 'Quotation conversion (' . ($paymentType === 'Split Payment' ? 'Split payment line' : $line['payment_type']) . '): ' . $quotation->reference_no,
                        'created_by'       => $currentUserName,
                        'user_id'          => $currentUserId,
                        'user_name'        => $currentUserName,
                        'user_role'        => $currentUserRole,
                    ]);

                    if ($lineIsCash && $line['amount'] > 0.001 && $lineRegId) {
                        $cashAddedByRegister[$lineRegId] = round(($cashAddedByRegister[$lineRegId] ?? 0.0) + $line['amount'], 2);
                    }
                }
            } elseif ($paidAmount > 0) {
                $singleIsCash = $purchaseAccounting->isCashPaymentType($paymentType);
                SalePayment::create([
                    'sale_id'          => $sale->id,
                    'cash_register_id' => $cashRegisterId,
                    'reference_no'     => 'PAY-' . $payStamp . '-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                    'payment_date'     => $sale->date,
                    'payment_type'     => $paymentType,
                    'amount'           => $paidAmount,
                    'received_amount'  => $singleIsCash ? $receivedAmount : $paidAmount,
                    'change_amount'    => $singleIsCash ? $changeAmount : 0.0,
                    'notes'            => 'Quotation conversion: ' . $quotation->reference_no,
                    'created_by'       => $currentUserName,
                    'user_id'          => $currentUserId,
                    'user_name'        => $currentUserName,
                    'user_role'        => $currentUserRole,
                ]);

                if ($singleIsCash && $paidAmount > 0.001 && $cashRegisterId) {
                    $cashAddedByRegister[$cashRegisterId] = round(($cashAddedByRegister[$cashRegisterId] ?? 0.0) + $paidAmount, 2);
                }
            }

            // Increment each distinct Cash Register strictly by its own net Cash tender received (ordered by ID to avoid deadlocks)
            if (!empty($cashAddedByRegister)) {
                ksort($cashAddedByRegister);
                foreach ($cashAddedByRegister as $targetRegId => $cashAmt) {
                    if ($cashAmt <= 0.001) {
                        continue;
                    }
                    $register = CashRegister::where('id', (int)$targetRegId)->lockForUpdate()->first();
                    if ($register && in_array(strtolower(trim((string) $register->status)), ['open', 'opened'], true)) {
                        $register->increment('expected_cash', round($cashAmt, 2));
                    }
                }
            }

            // Update customer total spend and visit count
            if (!empty($quotation->customer_id)) {
                $cust = Customer::where('id', $quotation->customer_id)->lockForUpdate()->first();
                if ($cust) {
                    $cust->increment('total_spent', $grandTotal);
                    $cust->increment('total_visits', 1);
                }
            }

            // Mark quotation as Ordered (prevents future re-conversion)
            $quotation->update(['status' => 'Ordered']);

            DB::commit();

            $this->clearQuotationRelatedCaches(true);

            $canViewCost = $accounting->canViewCost($request);
            $loadedSale = $sale->load(['items.product', 'items.variant', 'payments']);
            if (!$canViewCost) {
                $loadedSale->makeHidden(['total_cogs']);
                foreach ($loadedSale->items as $sItem) {
                    $sItem->makeHidden(['base_unit_cost', 'total_cogs']);
                    if ($sItem->relationLoaded('product') && $sItem->product) {
                        $sItem->product->makeHidden(['cost_price', 'purchase_price']);
                    }
                    if ($sItem->relationLoaded('variant') && $sItem->variant) {
                        $sItem->variant->makeHidden(['cost_price', 'purchase_price', 'cost']);
                    }
                }
            }
            $loadedQuo = $accounting->enrichQuotationResponse(
                $quotation->fresh(['items.variant.values', 'items.variant.attributeValues', 'items.product']),
                $canViewCost
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Quotation successfully converted to Sale ' . $sale->reference_no,
                'data'    => [
                    'sale'      => $loadedSale,
                    'quotation' => $loadedQuo,
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            $statusCode = 500;
            if (str_contains($e->getMessage(), 'Insufficient stock') ||
                str_contains($e->getMessage(), 'Stock') ||
                str_contains($e->getMessage(), 'stock') ||
                str_contains($e->getMessage(), 'Credit Limit') ||
                str_contains($e->getMessage(), 'credit limit') ||
                str_contains($e->getMessage(), 'အရင်းဈေး') ||
                str_contains($e->getMessage(), 'warehouse')) {
                $statusCode = 422;
            }
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $statusCode);
        }
    }

    /**
     * Zero-cache mode compatibility hook: quotations, sales, and reports query live DB.
     */
    private function clearQuotationRelatedCaches(bool $convertedToSale = false): void
    {
    }
}
