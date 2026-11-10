<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\InventoryBatch;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AdjustmentController extends Controller
{
    use \App\Traits\GeneratesSequentialCode;
    private static array $outletNameCache = [];

    /**
     * Generate next sequential adjustment code and formatted reference number.
     */
    public function generateCode()
    {
        return response()->json($this->peekNextSequentialCode('adjustment'));
    }

    public function buildFilteredQuery(Request $request)
    {
        $query = StockAdjustment::select([
            'id', 'reference_no', 'warehouse_id', 'warehouse_name', 'date',
            'total_products', 'total_quantity', 'net_quantity', 'gross_quantity',
            'type', 'reason_code', 'notes',
            'user_id', 'user_name', 'created_by', 'user_role', 'created_at', 'updated_at'
        ])->with([
            'user:id,name,role',
            'items:id,stock_adjustment_id,product_id,product_variant_id,product_code,product_name,variant_name,unit_id,unit_name,conversion_rate,quantity,base_quantity,type,unit_cost,base_unit_cost,subtotal',
        ]);

        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $query->where('warehouse_name', $request->input('warehouse'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int)$request->input('warehouse_id'));
        }

        if ($request->filled('type') && $request->input('type') !== 'All') {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('reason_code') && $request->input('reason_code') !== 'All') {
            $reason = trim($request->input('reason_code'));
            if ($reason === 'Normal / Other' || $reason === 'Normal') {
                $query->where(function ($q) {
                    $q->whereNull('reason_code')
                      ->orWhere('reason_code', '')
                      ->orWhere('reason_code', 'Normal / Other')
                      ->orWhere('reason_code', 'Normal');
                });
            } else {
                $query->where('reason_code', $reason);
            }
        }

        if ($request->filled('user') && $request->input('user') !== 'All') {
            $userFilter = trim((string)$request->input('user'));
            $query->where(function ($q) use ($userFilter) {
                $q->where('created_by', $userFilter)
                  ->orWhere('user_name', $userFilter)
                  ->orWhere('created_by', 'like', $userFilter . ' (%')
                  ->orWhereHas('user', function ($uq) use ($userFilter) {
                      $uq->where('name', $userFilter);
                  });
            });
        }

        $dateFrom = $request->input('date_from') ?: $request->input('from_date');
        if (!empty($dateFrom)) {
            $dateFrom = trim($dateFrom);
            $fromStr = strlen($dateFrom) === 10 ? ($dateFrom . ' 00:00:00') : $dateFrom;
            $query->where(function ($q) use ($dateFrom, $fromStr) {
                $q->where('date', '>=', $dateFrom)
                  ->orWhere('date', '>=', $fromStr);
            });
        }

        $dateTo = $request->input('date_to') ?: $request->input('to_date');
        if (!empty($dateTo)) {
            $dateTo = trim($dateTo);
            $toStr = strlen($dateTo) === 10 ? ($dateTo . ' 23:59:59') : $dateTo;
            $query->where(function ($q) use ($dateTo, $toStr) {
                $q->where('date', '<=', $toStr);
            });
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('reason_code', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('role', 'like', "%{$search}%");
                  })
                  ->orWhereHas('items', function ($iq) use ($search) {
                      $iq->where('product_code', 'like', "%{$search}%")
                        ->orWhere('product_name', 'like', "%{$search}%");
                  });
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->buildFilteredQuery($request);

        $sortBy = $request->input('sort_by', 'id');
        $sortColumnMap = [
            'reference' => 'reference_no',
            'warehouse' => 'warehouse_name',
            'items'     => 'total_products',
            'quantity'  => 'total_quantity',
            'user'      => 'created_by',
        ];
        if (isset($sortColumnMap[$sortBy])) {
            $sortBy = $sortColumnMap[$sortBy];
        }
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'reference_no', 'warehouse_name', 'date', 'type', 'total_products', 'total_quantity', 'net_quantity', 'gross_quantity', 'reason_code', 'created_at', 'created_by', 'user_role'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'id';
        }

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';

        // Senior Performance Optimization & Complete Mixed Voucher Breakdown:
        // Aggregate line item additions, subtractions, and adjusted quantity in a single query over stock_adjustment_items
        // BEFORE attaching ORDER BY to avoid sorting inside the WHERE IN subquery.
        $summary = DB::table('stock_adjustment_items')
            ->whereIn('stock_adjustment_id', (clone $query)->reorder()->select('id'))
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'Addition' THEN subtotal ELSE 0 END), 0) as total_additions_value,
                COALESCE(SUM(CASE WHEN type = 'Subtraction' THEN subtotal ELSE 0 END), 0) as total_subtractions_value,
                COALESCE(SUM(base_quantity), 0) as total_adjusted_quantity
            ")->first();

        $orderedQuery = (clone $query)->orderBy($sortBy, $sortOrder);
        if ($sortBy !== 'id') {
            $orderedQuery->orderBy('id', $sortOrder);
        }

        $summaryData = [
            'total_additions_value' => round((float)($summary?->total_additions_value ?? 0.0), 2),
            'total_subtractions_value' => round((float)($summary?->total_subtractions_value ?? 0.0), 2),
            'total_adjusted_quantity' => round((float)($summary?->total_adjusted_quantity ?? 0.0), 4),
        ];

        if ($isAll) {
            $totalCount = (clone $query)->count();
            $limit = min(500, max(1, (int)$request->input('limit', 500)));
            $adjustments = $orderedQuery->take($limit)->get();
            $data = $adjustments->map(function ($adj) {
                if ($adj->user) {
                    $adj->created_by = $adj->user->name;
                    if (!empty($adj->user->role)) {
                        $adj->user_role = $adj->user->role;
                    }
                }
                return $adj;
            });

            return response()->json([
                'status' => 'success',
                'data' => $data,
                'total' => $totalCount,
                'summary' => $summaryData,
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $adjustments->count(),
                    'total' => $totalCount,
                    'last_page' => (int)ceil($totalCount / max(1, $limit)),
                ],
            ]);
        }

        $perPage = min(max((int)$request->input('per_page', 25), 1), 1000);
        $adjustments = $orderedQuery->paginate($perPage);

        $data = collect($adjustments->items())->map(function ($adj) {
            if ($adj->user) {
                $adj->created_by = $adj->user->name;
                if (!empty($adj->user->role)) {
                    $adj->user_role = $adj->user->role;
                }
            }
            return $adj;
        });

        return response()->json([
            'status' => 'success',
            'data' => $data,
            'total' => $adjustments->total(),
            'summary' => $summaryData,
            'pagination' => [
                'current_page' => $adjustments->currentPage(),
                'per_page' => $adjustments->perPage(),
                'total' => $adjustments->total(),
                'last_page' => $adjustments->lastPage(),
            ],
        ]);
    }

    /**
     * Stream complete filtered stock adjustments export as CSV.
     */
    public function export(Request $request)
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $query = $this->buildFilteredQuery($request);

        $sortBy = $request->input('sort_by', 'stock_adjustments.id');
        $sortColumnMap = [
            'reference' => 'stock_adjustments.reference_no',
            'warehouse' => 'stock_adjustments.warehouse_name',
            'items'     => 'stock_adjustments.total_products',
            'quantity'  => 'stock_adjustments.total_quantity',
            'user'      => 'stock_adjustments.created_by',
        ];
        if (isset($sortColumnMap[$sortBy])) {
            $sortBy = $sortColumnMap[$sortBy];
        }
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="stock_adjustments_export_' . date('Ymd_His') . '.csv"',
            'X-Accel-Buffering' => 'no',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Expires' => '0',
        ];

        $warehouseFilter = $request->input('warehouse', 'All');
        $typeFilter = $request->input('type', 'All');
        $reasonFilter = $request->input('reason_code');
        $userFilter = $request->input('user');
        $dateFrom = $request->input('date_from') ?: $request->input('from_date');
        $dateTo = $request->input('date_to') ?: $request->input('to_date');
        $searchQuery = $request->input('search');

        // Join aggregated item valuation to avoid 10,000 N+1 sub-queries
        $query->leftJoin(
            \DB::raw('(SELECT stock_adjustment_id, SUM(CASE WHEN LOWER(type) = "subtraction" THEN -COALESCE(subtotal, quantity*unit_cost) ELSE COALESCE(subtotal, quantity*unit_cost) END) as val FROM stock_adjustment_items GROUP BY stock_adjustment_id) sai'),
            'stock_adjustments.id',
            '=',
            'sai.stock_adjustment_id'
        )->addSelect(\DB::raw('COALESCE(sai.val, 0) as estimated_val'));

        $callback = function () use (
            $query,
            $sortBy,
            $sortOrder,
            $warehouseFilter,
            $typeFilter,
            $reasonFilter,
            $userFilter,
            $dateFrom,
            $dateTo,
            $searchQuery
        ) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Microsoft Excel on Windows
            fwrite($file, "\xEF\xBB\xBF");

            // 1. Metadata Header
            fputcsv($file, ['Stock Adjustments Export']);
            fputcsv($file, ['Generated At', date('c')]);
            fputcsv($file, ['Warehouse Filter', $warehouseFilter ?: 'All']);
            fputcsv($file, ['Type Filter', $typeFilter ?: 'All']);
            if (!empty($reasonFilter) && $reasonFilter !== 'All') {
                fputcsv($file, ['Reason Code Filter', $reasonFilter]);
            }
            if (!empty($userFilter) && $userFilter !== 'All') {
                fputcsv($file, ['Created By Filter', $userFilter]);
            }
            if (!empty($dateFrom) || !empty($dateTo)) {
                $fromStr = !empty($dateFrom) ? explode(' ', $dateFrom)[0] : 'Beginning';
                $toStr = !empty($dateTo) ? explode(' ', $dateTo)[0] : 'Present';
                fputcsv($file, ['Date Range', "$fromStr to $toStr"]);
            }
            if (!empty($searchQuery)) {
                fputcsv($file, ['Search Filter', $searchQuery]);
            }
            fwrite($file, "\r\n");

            // 2. Column Headers
            $columns = [
                'SN',
                'Reference No',
                'Warehouse / Outlet',
                'Date',
                'Time',
                'Adjustment Type',
                'Reason Code',
                'Total Items',
                'Gross Qty',
                'Net Qty',
                'Total Estimated Value (MMK)',
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

            $totalItemsSum = 0;
            $totalGrossQtySum = 0.0;
            $totalNetQtySum = 0.0;
            $totalEstimatedValueSum = 0.0;

            $flushCount = 0;
            foreach ($query->orderBy($sortBy, $sortOrder)->toBase()->cursor() as $adj) {
                $gross = (float)$adj->gross_quantity > 0 ? (float)$adj->gross_quantity : abs((float)$adj->total_quantity);
                $net = (float)$adj->net_quantity;
                if (abs($net) <= 0.0001) {
                    if (strtolower($adj->type) === 'addition') {
                        $net = $gross;
                    } elseif (strtolower($adj->type) === 'subtraction') {
                        $net = -$gross;
                    }
                }
                $val = (float)$adj->estimated_val;

                $totalItemsSum += (int)$adj->total_products;
                $totalGrossQtySum += $gross;
                $totalNetQtySum += $net;
                $totalEstimatedValueSum += $val;

                $dateParts = explode(' ', (string)$adj->date);
                $dateOnly = $dateParts[0] ?? '';
                $timeStr = $dateParts[1] ?? '';

                $createdBy = trim((string)($adj->created_by ?: ($adj->user_name ?: 'Admin')));
                if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $createdBy, $m)) {
                    $createdBy = trim($m[1]);
                }

                $row = [
                    $sn++,
                    $adj->reference_no,
                    $adj->warehouse_name,
                    $dateOnly,
                    $timeStr,
                    $adj->type,
                    $adj->reason_code ?: 'Normal',
                    $adj->total_products,
                    $formatNum($gross),
                    $formatNum($net),
                    $formatNum($val),
                    $createdBy,
                    $adj->user_role ?: 'Admin',
                    $adj->notes ?? '',
                ];
                fputcsv($file, $row);

                if (++$flushCount % 500 === 0) {
                    flush();
                }
            }

            // 4. Summary Row
            fputcsv($file, []);
            fputcsv($file, [
                'TOTALS',
                ($sn - 1) . ' Adjustments',
                '',
                '',
                '',
                '',
                '',
                $formatNum($totalItemsSum),
                $formatNum($totalGrossQtySum),
                $formatNum($totalNetQtySum),
                $formatNum($totalEstimatedValueSum),
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
            'reference_no' => 'nullable|string|max:255|unique:stock_adjustments,reference_no',
            'warehouse_id' => 'nullable|integer',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'type' => 'required|string|in:Addition,Subtraction,Mixed',
            'reason_code' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'user_id' => 'nullable|integer',
            'user_name' => 'nullable|string|max:255',
            'created_by' => 'nullable|string|max:255',
            'user_role' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.variant_name' => 'nullable|string',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.unit_name' => 'nullable|string|max:100',
            'items.*.conversion_rate' => 'nullable|numeric|min:0.0001',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.base_quantity' => 'nullable|numeric|min:0',
            'items.*.type' => 'nullable|string|in:Addition,Subtraction',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.base_unit_cost' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
        ]);

        $warehouseId = $this->resolveWarehouseId($validated['warehouse_id'] ?? null, $validated['warehouse_name']);

        DB::beginTransaction();
        try {
            // 1. Pre-fetch and lock all affected Products and Variants to prevent N+1 queries and race conditions
            $rawPids = [];
            $rawCodes = [];
            $rawVids = [];
            foreach ($validated['items'] as $item) {
                if (!empty($item['product_id'])) $rawPids[] = (int)$item['product_id'];
                if (!empty($item['product_code'])) $rawCodes[] = (string)$item['product_code'];
                if (!empty($item['product_variant_id'])) $rawVids[] = (int)$item['product_variant_id'];
            }
            $sortedPids = array_values(array_unique($rawPids));
            sort($sortedPids);

            $preloadedProducts = Product::whereIn('id', $sortedPids)
                ->when(!empty($rawCodes), fn($q) => $q->orWhereIn('code', $rawCodes)->orWhereIn('sku', $rawCodes))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $preloadedVariants = !empty($rawVids)
                ? ProductVariant::with('values')->whereIn('id', array_values(array_unique($rawVids)))->get()->keyBy('id')
                : collect();

            // Batch lock StockBalance rows
            $preloadedBalances = StockBalance::where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $preloadedProducts->pluck('id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy(fn($b) => $b->warehouse_id . '_' . $b->product_id . '_' . ($b->product_variant_id ?? '0'));

            // Senior Accounting Validation: Verify total subtraction deductions per product & variant against warehouse balance,
            // taking into account any additions for the same item within the same adjustment voucher.
            $subtractionsByItem = [];
            $additionsByItem = [];
            foreach ($validated['items'] as $item) {
                $itemType = $item['type'] ?? $validated['type'];
                $pid = $item['product_id'] ?? null;
                if (!$pid && !empty($item['product_code'])) {
                    $p = $preloadedProducts->first(fn($prod) => $prod->code === $item['product_code'] || $prod->sku === $item['product_code']);
                    $pid = $p?->id;
                }
                $vid = $item['product_variant_id'] ?? null;
                $key = ($pid ?? 'p0') . '_' . ($vid ? "var_{$vid}" : 'single');
                $prod = $pid ? ($preloadedProducts->get($pid) ?? Product::find($pid)) : null;
                $cRate = $this->resolveItemConversionRate($item, $prod, $vid);
                $qty = (float)$item['quantity'];
                $baseQty = isset($item['base_quantity']) && (float)$item['base_quantity'] > 0
                    ? (float)$item['base_quantity']
                    : round($qty * $cRate, 4);

                if ($itemType === 'Subtraction') {
                    if (!isset($subtractionsByItem[$key])) {
                        $subtractionsByItem[$key] = [
                            'product_id' => $pid,
                            'variant_id' => $vid,
                            'product_name' => $item['product_name'] ?? 'Product',
                            'total_base_qty' => 0.0,
                        ];
                    }
                    $subtractionsByItem[$key]['total_base_qty'] += $baseQty;
                } elseif ($itemType === 'Addition') {
                    $additionsByItem[$key] = ($additionsByItem[$key] ?? 0.0) + $baseQty;
                }
            }

            $existingBalanceProductIds = StockBalance::whereIn('product_id', $preloadedProducts->pluck('id')->all())->distinct()->pluck('product_id')->flip()->all();
            $outletObj = Outlet::find($warehouseId);
            $outletName = $outletObj ? $outletObj->name : $validated['warehouse_name'];

            foreach ($subtractionsByItem as $sub) {
                $pid = $sub['product_id'];
                $vid = $sub['variant_id'];
                $needed = $sub['total_base_qty'];

                if ($pid) {
                    $key = ($pid ?? 'p0') . '_' . ($vid ? "var_{$vid}" : 'single');
                    $balKey = $warehouseId . '_' . $pid . '_' . ($vid ?: '0');
                    $prod = $preloadedProducts->get($pid) ?? Product::find($pid);
                    $hasExistingBalances = isset($existingBalanceProductIds[$pid]);
                    if (!$hasExistingBalances) {
                        $curBal = ($vid === null && $prod) ? (float)$prod->stock_quantity : 0.0;
                    } else {
                        $bal = $preloadedBalances->get($balKey);
                        $curBal = $bal ? (float)$bal->quantity : 0.0;
                    }

                    $intraVoucherAdd = $additionsByItem[$key] ?? 0.0;
                    $effectiveAvailable = $curBal + $intraVoucherAdd;

                    if ($needed > $effectiveAvailable + 0.0001) {
                        DB::rollBack();
                        $pName = $sub['product_name'];
                        return response()->json([
                            'status' => 'error',
                            'message' => "လက်ကျန်စတော့ထက် ပိုမိုနုတ်၍မရပါ: {$outletName} တွင် '{$pName}' စုစုပေါင်း လက်ကျန် {$effectiveAvailable} သာရှိသော်လည်း စုစုပေါင်း {$needed} နုတ်ရန် တောင်းဆိုထားပါသည်။",
                        ], 422);
                    }
                }
            }

            $refNo = $this->acquireUniqueReference('adjustment', $validated['reference_no'] ?? null);

            // Calculate net quantity, gross quantity and handle mixed additions and subtractions
            $netQty = 0.0;
            $grossQty = 0.0;
            $hasAddition = false;
            $hasSubtraction = false;

            foreach ($validated['items'] as $item) {
                $pid = $item['product_id'] ?? null;
                $vid = $item['product_variant_id'] ?? null;
                $prod = $pid ? ($preloadedProducts->get($pid) ?? Product::find($pid)) : null;
                $cRate = $this->resolveItemConversionRate($item, $prod, $vid);
                $q = (float)$item['quantity'];
                $baseQ = isset($item['base_quantity']) && (float)$item['base_quantity'] > 0
                    ? (float)$item['base_quantity']
                    : round($q * $cRate, 4);
                $itemType = $item['type'] ?? $validated['type'];

                $grossQty += $baseQ;
                if ($itemType === 'Subtraction') {
                    $hasSubtraction = true;
                    $netQty -= $baseQ;
                } else {
                    $hasAddition = true;
                    $netQty += $baseQ;
                }
            }

            $voucherType = $validated['type'];
            if ($hasAddition && $hasSubtraction) {
                $voucherType = 'Mixed';
            } elseif ($hasAddition) {
                $voucherType = 'Addition';
            } elseif ($hasSubtraction) {
                $voucherType = 'Subtraction';
            }

            $targetUser = $request->user();
            if (!$targetUser && !empty($validated['user_id'])) {
                $targetUser = User::find($validated['user_id']);
            }
            if (!$targetUser) {
                $targetUser = User::first();
            }

            $createdByName = !empty($validated['user_name'])
                ? $validated['user_name']
                : (!empty($validated['created_by']) ? $validated['created_by'] : ($targetUser ? $targetUser->name : 'Super Admin'));
            if ($targetUser && (empty($createdByName) || $createdByName === 'Super Admin')) {
                $createdByName = $targetUser->name;
            }

            $userRole = !empty($validated['user_role'])
                ? $validated['user_role']
                : ($targetUser ? ($targetUser->roleModel?->name ?? $targetUser->role ?? 'Super Admin') : 'Super Admin');
            $userId = !empty($validated['user_id'])
                ? $validated['user_id']
                : ($targetUser ? $targetUser->id : null);

            $txDate = trim($validated['date']);
            if (!str_contains($txDate, ':')) {
                $txDate .= ' ' . now()->format('H:i:s');
            }

            $adjustment = StockAdjustment::create([
                'reference_no' => $refNo,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $txDate,
                'total_products' => count($validated['items']),
                'total_quantity' => $netQty,
                'net_quantity' => $netQty,
                'gross_quantity' => $grossQty,
                'type' => $voucherType,
                'reason_code' => $validated['reason_code'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'user_id' => $userId,
                'user_name' => $createdByName,
                'created_by' => $createdByName,
                'user_role' => $userRole,
            ]);

            $fifoService = app(\App\Services\FifoInventoryService::class);

            // Senior Accounting Invariant: Always process Additions before Subtractions
            // to ensure stock and FIFO batches are deposited before any deductions are made.
            usort($validated['items'], function ($a, $b) use ($validated, $voucherType) {
                $typeA = $a['type'] ?? ($validated['type'] ?? $voucherType);
                $typeB = $b['type'] ?? ($validated['type'] ?? $voucherType);
                if ($typeA === $typeB) return 0;
                return ($typeA === 'Addition') ? -1 : 1;
            });

            $dirtyBalances = [];
            $dirtyProducts = [];
            $dirtyVariants = [];
            $pendingMovements = [];

            foreach ($validated['items'] as $itemData) {
                $resolvedProductId = $itemData['product_id'] ?? null;
                if (!$resolvedProductId && !empty($itemData['product_code'])) {
                    $prod = $preloadedProducts->first(fn($p) => $p->code === $itemData['product_code'] || $p->sku === $itemData['product_code']);
                    $resolvedProductId = $prod?->id;
                }

                $resolvedVariantId = $itemData['product_variant_id'] ?? null;
                $prod = $resolvedProductId ? ($preloadedProducts->get($resolvedProductId) ?? Product::find($resolvedProductId)) : null;
                $conversionRate = $this->resolveItemConversionRate($itemData, $prod, $resolvedVariantId);
                $quantity = (float)$itemData['quantity'];
                $unitCost = (float)($itemData['unit_cost'] ?? 0);
                $baseQty = isset($itemData['base_quantity']) && (float)$itemData['base_quantity'] > 0
                    ? (float)$itemData['base_quantity']
                    : round($quantity * $conversionRate, 4);

                $variantName = $this->resolveVariantDisplayName($itemData['variant_name'] ?? null, $resolvedVariantId, $preloadedVariants);
                $itemType = $itemData['type'] ?? $validated['type'];

                // Zero-cost FIFO guard: Fallback to variant/product cost price if unit cost is not provided or 0
                // Note: $prod->cost_price and $varObj->cost_price are ALWAYS Base Unit Cost.
                if ($unitCost <= 0.0001 && $resolvedProductId) {
                    $varObj = ($resolvedVariantId && $preloadedVariants->has($resolvedVariantId)) ? $preloadedVariants->get($resolvedVariantId) : ($resolvedVariantId ? ProductVariant::find($resolvedVariantId) : null);
                    $fallbackCost = (float)($varObj?->cost_price ?: ($varObj?->purchase_price ?: ($prod?->cost_price ?: ($prod?->purchase_price ?: 0.0))));
                    if ($fallbackCost > 0) {
                        $baseCost = $fallbackCost;
                        $unitCost = $conversionRate > 0 ? round($fallbackCost * $conversionRate, 4) : $fallbackCost;
                    } else {
                        $baseCost = 0.0;
                    }
                } else {
                    $baseCost = $conversionRate > 0 ? round($unitCost / $conversionRate, 4) : $unitCost;
                }

                $subtotal = round($quantity * $unitCost, 2);

                $finalSubtotal = $subtotal;
                $finalBaseCost = $baseCost;
                $finalUnitCost = $unitCost;

                if ($resolvedProductId && $itemType === 'Subtraction') {
                    $actualLossCost = $fifoService->depleteForAdjustment(
                        warehouseId: (int)$warehouseId,
                        productId: (int)$resolvedProductId,
                        variantId: $resolvedVariantId,
                        baseQty: (float)$baseQty,
                        referenceNo: (string)$refNo,
                        fallbackCost: (float)$baseCost
                    );
                    $finalSubtotal = $actualLossCost;
                    $finalBaseCost = $baseQty > 0 ? round($actualLossCost / $baseQty, 4) : $baseCost;
                    $finalUnitCost = round($finalBaseCost * ($conversionRate > 0 ? $conversionRate : 1.0), 4);
                }

                $adjItem = StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $resolvedProductId,
                    'product_variant_id' => $resolvedVariantId,
                    'product_code' => $itemData['product_code'],
                    'product_name' => $itemData['product_name'],
                    'variant_name' => $variantName,
                    'unit_id' => $itemData['unit_id'] ?? null,
                    'unit_name' => $itemData['unit_name'] ?? 'Pcs',
                    'conversion_rate' => $conversionRate,
                    'quantity' => $quantity,
                    'base_quantity' => $baseQty,
                    'type' => $itemType,
                    'unit_cost' => $finalUnitCost,
                    'base_unit_cost' => $finalBaseCost,
                    'subtotal' => $finalSubtotal,
                ]);

                $this->applyItemStock(
                    $adjItem,
                    $warehouseId,
                    $adjustment->id,
                    $refNo,
                    $validated['notes'] ?? null,
                    $createdByName,
                    false,
                    $preloadedProducts,
                    $preloadedVariants,
                    $userId,
                    false,
                    $preloadedBalances,
                    $existingBalanceProductIds,
                    $dirtyBalances,
                    $dirtyProducts,
                    $dirtyVariants,
                    $pendingMovements
                );

                if ($resolvedProductId && $adjItem->type === 'Addition') {
                    $fifoService->recordBatch(
                        warehouseId: (int)$warehouseId,
                        productId: (int)$resolvedProductId,
                        variantId: $adjItem->product_variant_id,
                        baseQuantity: (float)$baseQty,
                        baseUnitCost: (float)$finalBaseCost,
                        sourceType: 'adjustment_addition',
                        sourceReference: "{$refNo}#{$adjItem->id}",
                        receivedDate: (string)$adjustment->date,
                        notes: $validated['notes'] ?? 'Stock adjustment addition'
                    );
                }
            }

            foreach ($dirtyBalances as $b) {
                $b->save();
            }
            foreach ($dirtyProducts as $p) {
                $p->save();
            }
            foreach ($dirtyVariants as $v) {
                $v->save();
            }
            if (!empty($dirtyProducts) || !empty($dirtyVariants)) {
                app(\App\Services\ProductUnitConversionService::class)->syncAggregatesForProducts(
                    array_keys($dirtyProducts),
                    array_keys($dirtyVariants)
                );
            }
            if (!empty($pendingMovements)) {
                StockMovement::insert($pendingMovements);
            }

            DB::commit();

            $this->clearAdjustmentRelatedCaches();

            return response()->json([
                'status' => 'success',
                'message' => 'Stock adjustment saved successfully',
                'data' => $adjustment->load('items'),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("AdjustmentController store error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $adjustment = StockAdjustment::with([
            'user:id,name,role',
            'items.variant.values',
            'items.product',
        ])->find($id);

        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }
        if ($adjustment->user) {
            $adjustment->created_by = $adjustment->user->name;
            if (!empty($adjustment->user->role)) {
                $adjustment->user_role = $adjustment->user->role;
            }
        }
        $this->enrichAdjustmentItems($adjustment);

        return response()->json(['status' => 'success', 'data' => $adjustment]);
    }

    public function update(Request $request, $id)
    {
        $adjustment = StockAdjustment::with('items')->find($id);
        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }

        $validated = $request->validate([
            'reference_no' => ['nullable', 'string', 'max:255', Rule::unique('stock_adjustments', 'reference_no')->ignore($id)],
            'warehouse_id' => 'nullable|integer',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'type' => 'nullable|string|in:Addition,Subtraction,Mixed',
            'reason_code' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'user_id' => 'nullable|integer',
            'user_name' => 'nullable|string|max:255',
            'created_by' => 'nullable|string|max:255',
            'user_role' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.variant_name' => 'nullable|string',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.unit_name' => 'nullable|string|max:100',
            'items.*.conversion_rate' => 'nullable|numeric|min:0.0001',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.base_quantity' => 'nullable|numeric|min:0',
            'items.*.type' => 'nullable|string|in:Addition,Subtraction',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.base_unit_cost' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $oldWarehouseId = $this->resolveWarehouseId($adjustment->warehouse_id, $adjustment->warehouse_name);
            $newWarehouseId = $this->resolveWarehouseId($validated['warehouse_id'] ?? null, $validated['warehouse_name']);
            $isSameWarehouse = ($oldWarehouseId === $newWarehouseId);

            // Pre-fetch all affected products and variants (from both old items and new items) with deterministic sorted lock
            $rawPids = [];
            $rawCodes = [];
            $rawVids = [];

            foreach ($adjustment->items as $oldItem) {
                if (!empty($oldItem->product_id)) $rawPids[] = (int)$oldItem->product_id;
                if (!empty($oldItem->product_code)) $rawCodes[] = (string)$oldItem->product_code;
                if (!empty($oldItem->product_variant_id)) $rawVids[] = (int)$oldItem->product_variant_id;
            }

            foreach ($validated['items'] as $item) {
                if (!empty($item['product_id'])) $rawPids[] = (int)$item['product_id'];
                if (!empty($item['product_code'])) $rawCodes[] = (string)$item['product_code'];
                if (!empty($item['product_variant_id'])) $rawVids[] = (int)$item['product_variant_id'];
            }

            $sortedPids = array_values(array_unique($rawPids));
            sort($sortedPids);

            $preloadedProducts = Product::whereIn('id', $sortedPids)
                ->when(!empty($rawCodes), fn($q) => $q->orWhereIn('code', $rawCodes)->orWhereIn('sku', $rawCodes))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $preloadedVariants = !empty($rawVids)
                ? ProductVariant::with('values')->whereIn('id', array_values(array_unique($rawVids)))->get()->keyBy('id')
                : collect();

            $whIds = array_values(array_unique([$oldWarehouseId, $newWarehouseId]));
            $preloadedBalances = StockBalance::whereIn('warehouse_id', $whIds)
                ->whereIn('product_id', $preloadedProducts->pluck('id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy(fn($b) => $b->warehouse_id . '_' . $b->product_id . '_' . ($b->product_variant_id ?? '0'));

            $newWarehouseOutlet = Outlet::find($newWarehouseId);
            $newWarehouseName = $newWarehouseOutlet ? $newWarehouseOutlet->name : $validated['warehouse_name'];
            $existingBalanceProductIds = StockBalance::whereIn('product_id', $preloadedProducts->pluck('id')->all())->distinct()->pluck('product_id')->flip()->all();

            // Net sufficiency verification when editing within the same warehouse
            if ($isSameWarehouse) {
                $netDeltas = [];

                foreach ($adjustment->items as $oldItem) {
                    $pid = $oldItem->product_id;
                    $vid = $oldItem->product_variant_id;
                    $key = ($pid ?: 'p0') . '_' . ($vid ?: '0');
                    $cRate = (float)($oldItem->conversion_rate ?: 1.0);
                    $baseQ = (float)($oldItem->base_quantity ?: ((float)$oldItem->quantity * $cRate));
                    $signedEffect = ($oldItem->type === 'Subtraction') ? -$baseQ : $baseQ;

                    if (!isset($netDeltas[$key])) {
                        $netDeltas[$key] = [
                            'product_id' => $pid,
                            'variant_id' => $vid,
                            'product_name' => $oldItem->product_name ?? 'Product',
                            'old_effect' => 0.0,
                            'new_effect' => 0.0,
                        ];
                    }
                    $netDeltas[$key]['old_effect'] += $signedEffect;
                }

                foreach ($validated['items'] as $item) {
                    $pid = $item['product_id'] ?? null;
                    if (!$pid && !empty($item['product_code'])) {
                        $p = $preloadedProducts->first(fn($prod) => $prod->code === $item['product_code'] || $prod->sku === $item['product_code']);
                        $pid = $p?->id;
                    }
                    $vid = $item['product_variant_id'] ?? null;
                    $key = ($pid ?: 'p0') . '_' . ($vid ?: '0');
                    $prod = $pid ? ($preloadedProducts->get($pid) ?? Product::find($pid)) : null;
                    $cRate = $this->resolveItemConversionRate($item, $prod, $vid);
                    $q = (float)$item['quantity'];
                    $baseQ = isset($item['base_quantity']) && (float)$item['base_quantity'] > 0
                        ? (float)$item['base_quantity']
                        : round($q * $cRate, 4);
                    $itemType = $item['type'] ?? ($validated['type'] ?? $adjustment->type);
                    $signedEffect = ($itemType === 'Subtraction') ? -$baseQ : $baseQ;

                    if (!isset($netDeltas[$key])) {
                        $netDeltas[$key] = [
                            'product_id' => $pid,
                            'variant_id' => $vid,
                            'product_name' => $item['product_name'] ?? 'Product',
                            'old_effect' => 0.0,
                            'new_effect' => 0.0,
                        ];
                    }
                    $netDeltas[$key]['new_effect'] += $signedEffect;
                }

                foreach ($netDeltas as $delta) {
                    $pid = $delta['product_id'];
                    $vid = $delta['variant_id'];
                    $netChange = $delta['new_effect'] - $delta['old_effect'];

                    // If netChange is negative, stock will decrease by abs($netChange)
                    if ($netChange < -0.0001 && $pid) {
                        $balKey = $newWarehouseId . '_' . $pid . '_' . ($vid ?: '0');
                        $prod = $preloadedProducts->get($pid) ?? Product::find($pid);
                        $hasExistingBalances = isset($existingBalanceProductIds[$pid]);
                        if (!$hasExistingBalances) {
                            $curBal = ($vid === null && $prod) ? (float)$prod->stock_quantity : 0.0;
                        } else {
                            $bal = $preloadedBalances->get($balKey);
                            $curBal = $bal ? (float)$bal->quantity : 0.0;
                        }
                        $neededReduction = abs($netChange);

                        if ($curBal < $neededReduction - 0.0001) {
                            $pName = $delta['product_name'];
                            $msg = "လက်ကျန်စတော့ထက် ပိုမိုနုတ်ယူ၍မရပါ: {$newWarehouseName} တွင် '{$pName}' လက်ကျန် {$curBal} သာရှိသော်လည်း Adjustment ပြင်ဆင်မှုကြောင့် စုစုပေါင်း " . round($neededReduction, 4) . " လျှော့ချရန် လိုအပ်နေပါသည်။ (ကုန်ပစ္စည်းများကို ရောင်းချပြီးဖြစ်နိုင်ပါသည်)";
                            $validator = \Illuminate\Support\Facades\Validator::make([], []);
                            $validator->errors()->add('stock', $msg);
                            throw new \Illuminate\Validation\ValidationException(
                                validator: $validator,
                                response: response()->json([
                                    'status' => 'error',
                                    'message' => $msg,
                                    'errors' => ['stock' => [$msg]],
                                ], 422)
                            );
                        }
                    }
                }
            } else {
                // Warehouse changed: verify net reversal sufficiency on the old warehouse before rolling back
                $oldWarehouseModel = Outlet::find($oldWarehouseId);
                $oldWarehouseName = $oldWarehouseModel?->name ?? ($adjustment->warehouse_name ?: 'Old Warehouse');
                $oldReversalDeltas = [];
                foreach ($adjustment->items as $oldItem) {
                    $pid = $oldItem->product_id;
                    $vid = $oldItem->product_variant_id;
                    $key = ($pid ?: 'p0') . '_' . ($vid ?: '0');
                    $cRate = (float)$oldItem->conversion_rate > 0 ? (float)$oldItem->conversion_rate : 1.0;
                    $baseQ = (float)$oldItem->base_quantity > 0
                        ? (float)$oldItem->base_quantity
                        : round((float)$oldItem->quantity * $cRate, 4);
                    $oldType = $oldItem->type ?: $adjustment->type;

                    if (!isset($oldReversalDeltas[$key])) {
                        $oldReversalDeltas[$key] = [
                            'product_id' => $pid,
                            'variant_id' => $vid,
                            'product_name' => $oldItem->product_name ?? 'Product',
                            'needed_reduction' => 0.0,
                        ];
                    }
                    if ($oldType === 'Addition') {
                        $oldReversalDeltas[$key]['needed_reduction'] += $baseQ;
                    } else {
                        $oldReversalDeltas[$key]['needed_reduction'] -= $baseQ;
                    }
                }

                foreach ($oldReversalDeltas as $rev) {
                    $pid = $rev['product_id'];
                    $vid = $rev['variant_id'];
                    $needed = $rev['needed_reduction'];
                    if ($needed > 0.0001 && $pid) {
                        $balKey = $oldWarehouseId . '_' . $pid . '_' . ($vid ?: '0');
                        $prod = $preloadedProducts->get($pid) ?? Product::find($pid);
                        $hasExistingBalances = isset($existingBalanceProductIds[$pid]);
                        if (!$hasExistingBalances) {
                            $curBal = ($vid === null && $prod) ? (float)$prod->stock_quantity : 0.0;
                        } else {
                            $bal = $preloadedBalances->get($balKey);
                            $curBal = $bal ? (float)$bal->quantity : 0.0;
                        }
                        if ($curBal < $needed - 0.0001) {
                            $pName = $rev['product_name'];
                            $msg = "လက်ကျန်စတော့ မလုံလောက်ပါ: ဂိုဒေါင်ဟောင်း ({$oldWarehouseName}) တွင် '{$pName}' ကို စတော့ပြန်နုတ်ရန် အရေအတွက် (" . round($needed, 4) . ") လိုအပ်သော်လည်း လက်ရှိလက်ကျန် ({$curBal}) သာ ကျန်ရှိတော့ပါသည်။";
                            $validator = \Illuminate\Support\Facades\Validator::make([], []);
                            $validator->errors()->add('stock', $msg);
                            throw new \Illuminate\Validation\ValidationException(
                                validator: $validator,
                                response: response()->json([
                                    'status' => 'error',
                                    'message' => $msg,
                                    'errors' => ['stock' => [$msg]],
                                ], 422)
                            );
                        }
                    }
                }
            }

            // 1. Rollback previous items' stock balances (sort Subtraction first so +stock happens before -stock)
            $dirtyBalances = [];
            $dirtyProducts = [];
            $dirtyVariants = [];
            $sortedOldItems = $adjustment->items->sortBy(function ($item) use ($adjustment) {
                $t = $item->type ?: $adjustment->type;
                return $t === 'Subtraction' ? 0 : 1;
            });
            foreach ($sortedOldItems as $oldItem) {
                $this->applyItemStock(
                    $oldItem,
                    $oldWarehouseId,
                    $adjustment->id,
                    $adjustment->reference_no,
                    null,
                    null,
                    true, // rollback
                    $preloadedProducts,
                    $preloadedVariants,
                    null,
                    true, // net sufficiency already verified above for both same-warehouse and cross-warehouse
                    $preloadedBalances,
                    $existingBalanceProductIds,
                    $dirtyBalances,
                    $dirtyProducts,
                    $dirtyVariants
                );
            }
            foreach ($dirtyBalances as $b) {
                $b->save();
            }
            foreach ($dirtyProducts as $p) {
                $p->save();
            }
            foreach ($dirtyVariants as $v) {
                $v->save();
            }

            // 1b. Rollback FIFO batches and clean prior stock movements
            $fifoService = app(\App\Services\FifoInventoryService::class);
            $fifoService->rollbackAdjustmentFifo($adjustment);
            InventoryBatch::where('warehouse_id', $oldWarehouseId)
                ->where('source_type', 'adjustment_addition')
                ->where(function ($q) use ($adjustment) {
                    $q->where('source_reference', $adjustment->reference_no)
                      ->orWhere('source_reference', 'like', "{$adjustment->reference_no}#%");
                })
                ->delete();
            StockMovement::where('reference_type', 'stock_adjustment')
                ->where('reference_id', $adjustment->id)
                ->delete();

            $adjustment->items()->delete();

            // 2. If warehouse changed, verify subtractions against the new warehouse
            if (!$isSameWarehouse) {
                $subtractionsByItem = [];
                $additionsByItem = [];
                foreach ($validated['items'] as $item) {
                    $itemType = $item['type'] ?? ($validated['type'] ?? $adjustment->type);
                    $pid = $item['product_id'] ?? null;
                    if (!$pid && !empty($item['product_code'])) {
                        $p = $preloadedProducts->first(fn($prod) => $prod->code === $item['product_code'] || $prod->sku === $item['product_code']);
                        $pid = $p?->id;
                    }
                    $vid = $item['product_variant_id'] ?? null;
                    $key = ($pid ?? 'p0') . '_' . ($vid ? "var_{$vid}" : 'single');
                    $prod = $pid ? ($preloadedProducts->get($pid) ?? Product::find($pid)) : null;
                    $cRate = $this->resolveItemConversionRate($item, $prod, $vid);
                    $qty = (float)$item['quantity'];
                    $baseQty = isset($item['base_quantity']) && (float)$item['base_quantity'] > 0
                        ? (float)$item['base_quantity']
                        : round($qty * $cRate, 4);

                    if ($itemType === 'Subtraction') {
                        if (!isset($subtractionsByItem[$key])) {
                            $subtractionsByItem[$key] = [
                                'product_id' => $pid,
                                'variant_id' => $vid,
                                'product_name' => $item['product_name'] ?? 'Product',
                                'total_base_qty' => 0.0,
                            ];
                        }
                        $subtractionsByItem[$key]['total_base_qty'] += $baseQty;
                    } elseif ($itemType === 'Addition') {
                        $additionsByItem[$key] = ($additionsByItem[$key] ?? 0.0) + $baseQty;
                    }
                }

                foreach ($subtractionsByItem as $sub) {
                    $pid = $sub['product_id'];
                    $vid = $sub['variant_id'];
                    $needed = $sub['total_base_qty'];

                    if ($pid) {
                        $key = ($pid ?? 'p0') . '_' . ($vid ? "var_{$vid}" : 'single');
                        $balKey = $newWarehouseId . '_' . $pid . '_' . ($vid ?: '0');
                        $prod = $preloadedProducts->get($pid) ?? Product::find($pid);
                        $hasExistingBalances = isset($existingBalanceProductIds[$pid]);
                        if (!$hasExistingBalances) {
                            $curBal = ($vid === null && $prod) ? (float)$prod->stock_quantity : 0.0;
                        } else {
                            $bal = $preloadedBalances->get($balKey);
                            $curBal = $bal ? (float)$bal->quantity : 0.0;
                        }

                        $intraVoucherAdd = $additionsByItem[$key] ?? 0.0;
                        $effectiveAvailable = $curBal + $intraVoucherAdd;

                        if ($needed > $effectiveAvailable + 0.0001) {
                            $pName = $sub['product_name'];
                            throw new \Illuminate\Validation\ValidationException(
                                validator: \Illuminate\Support\Facades\Validator::make([], []),
                                response: response()->json([
                                    'status' => 'error',
                                    'message' => "လက်ကျန်စတော့ထက် ပိုမိုနုတ်၍မရပါ: {$newWarehouseName} တွင် '{$pName}' စုစုပေါင်း လက်ကျန် {$effectiveAvailable} သာရှိသော်လည်း စုစုပေါင်း {$needed} နုတ်ရန် တောင်းဆိုထားပါသည်။",
                                ], 422)
                            );
                        }
                    }
                }
            }

            $netQty = 0.0;
            $grossQty = 0.0;
            $hasAddition = false;
            $hasSubtraction = false;
            foreach ($validated['items'] as $item) {
                $pid = $item['product_id'] ?? null;
                $vid = $item['product_variant_id'] ?? null;
                $prod = $pid ? ($preloadedProducts->get($pid) ?? Product::find($pid)) : null;
                $cRate = $this->resolveItemConversionRate($item, $prod, $vid);
                $q = (float)$item['quantity'];
                $baseQ = isset($item['base_quantity']) && (float)$item['base_quantity'] > 0
                    ? (float)$item['base_quantity']
                    : round($q * $cRate, 4);
                $itemType = $item['type'] ?? ($validated['type'] ?? $adjustment->type);
                $grossQty += $baseQ;
                if ($itemType === 'Subtraction') {
                    $hasSubtraction = true;
                    $netQty -= $baseQ;
                } else {
                    $hasAddition = true;
                    $netQty += $baseQ;
                }
            }

            $adjType = $validated['type'] ?? $adjustment->type;
            if ($hasAddition && $hasSubtraction) {
                $adjType = 'Mixed';
            } elseif ($hasAddition) {
                $adjType = 'Addition';
            } elseif ($hasSubtraction) {
                $adjType = 'Subtraction';
            }

            $txDate = trim($validated['date']);
            if (!str_contains($txDate, ':')) {
                if ($adjustment->date && str_contains($adjustment->date, ':')) {
                    $parts = explode(' ', (string)$adjustment->date);
                    $timePart = end($parts);
                    if (str_contains($timePart, ':')) {
                        $txDate .= ' ' . $timePart;
                    } else {
                        $txDate .= ' ' . now()->format('H:i:s');
                    }
                } else {
                    $txDate .= ' ' . now()->format('H:i:s');
                }
            }

            $updateData = [
                'warehouse_id' => $newWarehouseId,
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $txDate,
                'total_products' => count($validated['items']),
                'total_quantity' => $netQty,
                'net_quantity' => $netQty,
                'gross_quantity' => $grossQty,
                'type' => $adjType,
                'reason_code' => $validated['reason_code'] ?? $adjustment->reason_code,
                'notes' => $validated['notes'] ?? $adjustment->notes,
            ];
            if (!empty($validated['reference_no'])) {
                $updateData['reference_no'] = $validated['reference_no'];
            }
            if (isset($validated['user_name'])) {
                $updateData['user_name'] = $validated['user_name'];
                $updateData['created_by'] = $validated['user_name'];
            } elseif (isset($validated['created_by'])) {
                $updateData['user_name'] = $validated['created_by'];
                $updateData['created_by'] = $validated['created_by'];
            }
            if (isset($validated['user_role'])) {
                $updateData['user_role'] = $validated['user_role'];
            }
            if (isset($validated['user_id'])) {
                $updateData['user_id'] = $validated['user_id'];
            }

            $adjustment->update($updateData);

            $effectiveUserId = $validated['user_id'] ?? $adjustment->user_id ?? auth()->id();

            // Sort items so Additions are processed before Subtractions
            usort($validated['items'], function ($a, $b) use ($adjType) {
                $typeA = $a['type'] ?? $adjType;
                $typeB = $b['type'] ?? $adjType;
                if ($typeA === $typeB) return 0;
                return $typeA === 'Addition' ? -1 : 1;
            });

            $dirtyBalances = [];
            $dirtyProducts = [];
            $dirtyVariants = [];
            $pendingMovements = [];

            foreach ($validated['items'] as $itemData) {
                $resolvedProductId = $itemData['product_id'] ?? null;
                if (!$resolvedProductId && !empty($itemData['product_code'])) {
                    $prod = $preloadedProducts->first(fn($p) => $p->code === $itemData['product_code'] || $p->sku === $itemData['product_code']);
                    $resolvedProductId = $prod?->id;
                }

                $resolvedVariantId = $itemData['product_variant_id'] ?? null;
                $prod = $resolvedProductId ? ($preloadedProducts->get($resolvedProductId) ?? Product::find($resolvedProductId)) : null;
                $conversionRate = $this->resolveItemConversionRate($itemData, $prod, $resolvedVariantId);
                $quantity = (float)$itemData['quantity'];
                $unitCost = (float)($itemData['unit_cost'] ?? 0);
                $baseQty = isset($itemData['base_quantity']) && (float)$itemData['base_quantity'] > 0
                    ? (float)$itemData['base_quantity']
                    : round($quantity * $conversionRate, 4);

                $variantName = $this->resolveVariantDisplayName($itemData['variant_name'] ?? null, $resolvedVariantId, $preloadedVariants);
                $itemType = $itemData['type'] ?? $adjType;

                // Zero-cost FIFO guard: Fallback to variant/product cost price if unit cost is not provided or 0
                if ($unitCost <= 0.0001 && $resolvedProductId) {
                    $varObj = ($resolvedVariantId && $preloadedVariants->has($resolvedVariantId)) ? $preloadedVariants->get($resolvedVariantId) : ($resolvedVariantId ? ProductVariant::find($resolvedVariantId) : null);
                    $fallbackCost = (float)($varObj?->cost_price ?: ($varObj?->purchase_price ?: ($prod?->cost_price ?: ($prod?->purchase_price ?: 0.0))));
                    if ($fallbackCost > 0) {
                        $baseCost = $fallbackCost;
                        $unitCost = $conversionRate > 0 ? round($fallbackCost * $conversionRate, 4) : $fallbackCost;
                    } else {
                        $baseCost = 0.0;
                    }
                } else {
                    $baseCost = $conversionRate > 0 ? round($unitCost / $conversionRate, 4) : $unitCost;
                }

                $subtotal = round($quantity * $unitCost, 2);

                $finalSubtotal = $subtotal;
                $finalBaseCost = $baseCost;
                $finalUnitCost = $unitCost;

                if ($resolvedProductId && $itemType === 'Subtraction') {
                    $actualLossCost = $fifoService->depleteForAdjustment(
                        warehouseId: (int)$newWarehouseId,
                        productId: (int)$resolvedProductId,
                        variantId: $resolvedVariantId,
                        baseQty: (float)$baseQty,
                        referenceNo: (string)$adjustment->reference_no,
                        fallbackCost: (float)$baseCost
                    );
                    $finalSubtotal = $actualLossCost;
                    $finalBaseCost = $baseQty > 0 ? round($actualLossCost / $baseQty, 4) : $baseCost;
                    $finalUnitCost = round($finalBaseCost * ($conversionRate > 0 ? $conversionRate : 1.0), 4);
                }

                $adjItem = StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $resolvedProductId,
                    'product_variant_id' => $resolvedVariantId,
                    'product_code' => $itemData['product_code'],
                    'product_name' => $itemData['product_name'],
                    'variant_name' => $variantName,
                    'unit_id' => $itemData['unit_id'] ?? null,
                    'unit_name' => $itemData['unit_name'] ?? 'Pcs',
                    'conversion_rate' => $conversionRate,
                    'quantity' => $quantity,
                    'base_quantity' => $baseQty,
                    'type' => $itemType,
                    'unit_cost' => $finalUnitCost,
                    'base_unit_cost' => $finalBaseCost,
                    'subtotal' => $finalSubtotal,
                ]);

                $this->applyItemStock(
                    $adjItem,
                    $newWarehouseId,
                    $adjustment->id,
                    $adjustment->reference_no,
                    $validated['notes'] ?? null,
                    $validated['created_by'] ?? $adjustment->created_by,
                    false,
                    $preloadedProducts,
                    $preloadedVariants,
                    $effectiveUserId,
                    false,
                    $preloadedBalances,
                    $existingBalanceProductIds,
                    $dirtyBalances,
                    $dirtyProducts,
                    $dirtyVariants,
                    $pendingMovements
                );

                if ($resolvedProductId && $adjItem->type === 'Addition') {
                    $fifoService->recordBatch(
                        warehouseId: (int)$newWarehouseId,
                        productId: (int)$resolvedProductId,
                        variantId: $adjItem->product_variant_id,
                        baseQuantity: (float)$baseQty,
                        baseUnitCost: (float)$finalBaseCost,
                        sourceType: 'adjustment_addition',
                        sourceReference: "{$adjustment->reference_no}#{$adjItem->id}",
                        receivedDate: (string)$adjustment->date,
                        notes: $validated['notes'] ?? 'Stock adjustment addition'
                    );
                }
            }

            foreach ($dirtyBalances as $b) {
                $b->save();
            }
            foreach ($dirtyProducts as $p) {
                $p->save();
            }
            foreach ($dirtyVariants as $v) {
                $v->save();
            }
            if ($preloadedProducts->isNotEmpty() || $preloadedVariants->isNotEmpty()) {
                app(\App\Services\ProductUnitConversionService::class)->syncAggregatesForProducts(
                    $preloadedProducts->keys()->all(),
                    $preloadedVariants->keys()->all()
                );
            }
            if (!empty($pendingMovements)) {
                StockMovement::insert($pendingMovements);
            }

            DB::commit();

            $this->clearAdjustmentRelatedCaches();

            return response()->json([
                'status' => 'success',
                'message' => 'Stock adjustment updated successfully',
                'data' => $adjustment->load('items'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("AdjustmentController update error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $adjustment = StockAdjustment::with('items')->find($id);
        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }

        DB::beginTransaction();
        try {
            $warehouseId = $this->resolveWarehouseId($adjustment->warehouse_id, $adjustment->warehouse_name);

            // Pre-fetch and lock products & variants deterministically to prevent deadlocks and N+1 queries
            $rawPids = [];
            $rawVids = [];
            foreach ($adjustment->items as $item) {
                if (!empty($item->product_id)) $rawPids[] = (int)$item->product_id;
                if (!empty($item->product_variant_id)) $rawVids[] = (int)$item->product_variant_id;
            }
            $sortedPids = array_values(array_unique($rawPids));
            sort($sortedPids);

            $preloadedProducts = Product::whereIn('id', $sortedPids)->lockForUpdate()->get()->keyBy('id');
            $preloadedVariants = !empty($rawVids)
                ? ProductVariant::with('values')->whereIn('id', array_values(array_unique($rawVids)))->get()->keyBy('id')
                : collect();

            $preloadedBalances = StockBalance::where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $preloadedProducts->pluck('id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy(fn($b) => $b->warehouse_id . '_' . $b->product_id . '_' . ($b->product_variant_id ?? '0'));

            $existingBalanceProductIds = StockBalance::whereIn('product_id', $preloadedProducts->pluck('id')->all())
                ->distinct()
                ->pluck('product_id')
                ->flip()
                ->all();

            $reversalNetByItem = [];
            foreach ($adjustment->items as $item) {
                $pid = (int)$item->product_id;
                $vid = $item->product_variant_id ? (int)$item->product_variant_id : null;
                $key = $pid . '_' . ($vid ? "var_{$vid}" : 'single');
                $itemType = $item->type ?: $adjustment->type;
                $baseQty = (float)$item->base_quantity;

                if (!isset($reversalNetByItem[$key])) {
                    $reversalNetByItem[$key] = [
                        'product_id' => $pid,
                        'variant_id' => $vid,
                        'product_name' => $item->product_name ?? 'Product',
                        'needed_reduction' => 0.0,
                    ];
                }
                // Rolling back an Addition reduces warehouse stock
                // Rolling back a Subtraction adds warehouse stock
                if ($itemType === 'Addition') {
                    $reversalNetByItem[$key]['needed_reduction'] += $baseQty;
                } elseif ($itemType === 'Subtraction') {
                    $reversalNetByItem[$key]['needed_reduction'] -= $baseQty;
                }
            }

            $outletObj = Outlet::find($warehouseId);
            $outletName = $outletObj ? $outletObj->name : "Warehouse #$warehouseId";

            foreach ($reversalNetByItem as $rev) {
                $needed = $rev['needed_reduction'];
                if ($needed > 0.0001) {
                    $pid = $rev['product_id'];
                    $vid = $rev['variant_id'];
                    $balKey = $warehouseId . '_' . $pid . '_' . ($vid ?: '0');
                    $prod = $preloadedProducts->get($pid) ?? Product::find($pid);
                    $hasExistingBalances = isset($existingBalanceProductIds[$pid]);
                    if (!$hasExistingBalances) {
                        $curBal = ($vid === null && $prod) ? (float)$prod->stock_quantity : 0.0;
                    } else {
                        $bal = $preloadedBalances->get($balKey);
                        $curBal = $bal ? (float)$bal->quantity : 0.0;
                    }

                    if ($needed > $curBal + 0.0001) {
                        $pName = $rev['product_name'];
                        throw new \Illuminate\Validation\ValidationException(
                            validator: \Illuminate\Support\Facades\Validator::make([], []),
                            response: response()->json([
                                'status' => 'error',
                                'message' => "မလုံလောက်သော လက်ကျန်စတော့ကြောင့် Adjustment ကို ဖျက်ပစ်၍မရပါ: {$outletName} တွင် '{$pName}' လက်ကျန် {$curBal} သာကျန်ရှိတော့သဖြင့် မူလချိန်ညှိမှုအားလုံးကို ပြန်လည်နုတ်ယူရန် " . round($needed, 4) . " လျှော့ချရန် လိုအပ်နေပါသည်။ (ကုန်ပစ္စည်းများကို ရောင်းချပြီးဖြစ်နိုင်ပါသည်)",
                                'errors' => [
                                    'rollback' => ["မလုံလောက်သော လက်ကျန်စတော့ကြောင့် Adjustment ကို ဖျက်ပစ်၍မရပါ"],
                                ],
                            ], 422)
                        );
                    }
                }
            }

            // Senior Accounting Invariant for Reversals:
            // Process Subtraction rollbacks FIRST (which add stock into the warehouse),
            // followed by Addition rollbacks (which subtract stock).
            $sortedOldItems = $adjustment->items->sortBy(function ($item) use ($adjustment) {
                $t = $item->type ?: $adjustment->type;
                return $t === 'Subtraction' ? 0 : 1;
            });

            $dirtyBalances = [];
            $dirtyProducts = [];
            $dirtyVariants = [];
            foreach ($sortedOldItems as $oldItem) {
                $this->applyItemStock(
                    $oldItem,
                    $warehouseId,
                    $adjustment->id,
                    $adjustment->reference_no,
                    null,
                    null,
                    true, // rollback
                    $preloadedProducts,
                    $preloadedVariants,
                    null,
                    true, // skipSufficiencyCheck = true (net sufficiency strictly verified upfront)
                    $preloadedBalances,
                    $existingBalanceProductIds,
                    $dirtyBalances,
                    $dirtyProducts,
                    $dirtyVariants
                );
            }
            foreach ($dirtyBalances as $b) {
                $b->save();
            }
            foreach ($dirtyProducts as $p) {
                $p->save();
            }
            foreach ($dirtyVariants as $v) {
                $v->save();
            }
            if (!empty($dirtyProducts) || !empty($dirtyVariants)) {
                app(\App\Services\ProductUnitConversionService::class)->syncAggregatesForProducts(
                    array_keys($dirtyProducts),
                    array_keys($dirtyVariants)
                );
            }

            // FIFO Batch Rollback and remove StockMovements
            $fifoService = app(\App\Services\FifoInventoryService::class);
            $fifoService->rollbackAdjustmentFifo($adjustment);

            // Clean up any remaining addition batches generated by this adjustment
            // (Preserve 'adjustment_reversal' batches created by rollbackAdjustmentFifo above!)
            InventoryBatch::where('warehouse_id', $warehouseId)
                ->where('source_type', 'adjustment_addition')
                ->where(function ($q) use ($adjustment) {
                    $q->where('source_reference', $adjustment->reference_no)
                      ->orWhere('source_reference', 'like', "{$adjustment->reference_no}#%");
                })
                ->delete();

            StockMovement::where('reference_type', 'stock_adjustment')
                ->where('reference_id', $adjustment->id)
                ->delete();

            $adjustment->items()->delete();
            $adjustment->delete();
            DB::commit();

            $this->clearAdjustmentRelatedCaches();

            return response()->json(['status' => 'success', 'message' => 'Stock adjustment deleted']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("AdjustmentController destroy error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Resolve warehouse ID from warehouse_id or warehouse_name.
     */
    private function resolveWarehouseId(?int $warehouseId, string $warehouseName): int
    {
        if ($warehouseId && $warehouseId > 0) {
            $w = Outlet::find($warehouseId);
            if ($w) return $w->id;
        }

        if (!empty($warehouseName)) {
            $w = Outlet::where('name', $warehouseName)->first();
            if ($w) return $w->id;
        }

        $fallback = Outlet::first();
        return $fallback ? $fallback->id : 1;
    }

    /**
     * Apply or rollback StockBalance, Product stock, and StockMovement.
     */
    private function applyItemStock(
        StockAdjustmentItem $item,
        int $warehouseId,
        int $adjustmentId,
        string $adjustmentRef,
        ?string $notes = null,
        ?string $userName = null,
        bool $isRollback = false,
        $preloadedProducts = null,
        $preloadedVariants = null,
        ?int $resolvedUserId = null,
        bool $skipSufficiencyCheck = false,
        $preloadedBalances = null,
        ?array $existingBalanceProductIds = null,
        ?array &$dirtyBalances = null,
        ?array &$dirtyProducts = null,
        ?array &$dirtyVariants = null,
        ?array &$pendingMovements = null
    ): void {
        $productId = $item->product_id;
        $variantId = $item->product_variant_id;
        $qty = (float)$item->quantity;
        $conversionRate = (float)($item->conversion_rate ?: 1.0);
        $baseQty = (float)($item->base_quantity ?: ($qty * $conversionRate));
        $effectiveQty = $baseQty > 0 ? $baseQty : $qty;
        $type = $item->type; // 'Addition' or 'Subtraction'

        if (!$productId) {
            $prod = Product::where('code', $item->product_code)
                ->orWhere('sku', $item->product_code)
                ->lockForUpdate()
                ->first();
            if ($prod) {
                $productId = $prod->id;
                $item->update(['product_id' => $productId]);
            }
        } else {
            $prod = ($preloadedProducts && $preloadedProducts->has($productId))
                ? $preloadedProducts->get($productId)
                : Product::where('id', $productId)->lockForUpdate()->first();
        }

        if (!$prod) {
            return;
        }

        // Determine effective operation direction
        $isAddition = ($type === 'Addition');
        if ($isRollback) {
            $isAddition = !$isAddition;
        }

        // 1. Update StockBalance for the specific warehouse & variant (or single)
        $balKey = $warehouseId . '_' . $productId . '_' . ($variantId && $variantId > 0 ? $variantId : '0');
        $balance = ($preloadedBalances && $preloadedBalances->has($balKey))
            ? $preloadedBalances->get($balKey)
            : null;

        if (!$balance) {
            $balanceQuery = StockBalance::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId);

            if ($variantId && $variantId > 0) {
                $balanceQuery->where('product_variant_id', $variantId);
            } else {
                $balanceQuery->whereNull('product_variant_id');
            }

            $balance = $balanceQuery->lockForUpdate()->first();
        }

        if (!$balance) {
            $hasExistingBalances = $existingBalanceProductIds !== null
                ? isset($existingBalanceProductIds[$productId])
                : StockBalance::where('product_id', $productId)->exists();
            $initial = ($variantId === null && !$hasExistingBalances) ? (float)$prod->stock_quantity : 0.0;
            $balance = StockBalance::create([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => ($variantId && $variantId > 0) ? $variantId : null,
                'quantity' => $initial,
            ]);
            if ($preloadedBalances) {
                $preloadedBalances->put($balKey, $balance);
            }
        }

        if (!$isAddition) {
            // Check stock balance sufficiency during both rollback and normal subtraction
            if (!$skipSufficiencyCheck) {
                $avail = (float)$balance->quantity;
                if ($avail < $effectiveQty - 0.0001) {
                    $prodName = $prod->name;
                    if (!isset(self::$outletNameCache[$warehouseId])) {
                        $outletObj = Outlet::find($warehouseId);
                        self::$outletNameCache[$warehouseId] = $outletObj ? $outletObj->name : "Warehouse #$warehouseId";
                    }
                    $outletName = self::$outletNameCache[$warehouseId];
                    if ($isRollback) {
                        $msg = "မလုံလောက်သော လက်ကျန်စတော့ကြောင့် Adjustment ကို ပြင်ဆင်/ဖျက်ပစ်၍မရပါ: {$outletName} တွင် '{$prodName}' လက်ကျန် {$avail} သာကျန်ရှိတော့သဖြင့် မူလပေါင်းထည့်ခဲ့သော {$effectiveQty} ကို ပြန်လည်နုတ်ယူ၍ မရနိုင်ပါ။ (ကုန်ပစ္စည်းများကို ရောင်းချပြီးဖြစ်နိုင်ပါသည်)";
                        $validator = \Illuminate\Support\Facades\Validator::make([], []);
                        $validator->errors()->add('rollback', $msg);
                        throw new \Illuminate\Validation\ValidationException(
                            validator: $validator,
                            response: response()->json([
                                'status' => 'error',
                                'message' => $msg,
                                'errors' => [
                                    'rollback' => [$msg],
                                ],
                            ], 422)
                        );
                    } else {
                        $msg = "လက်ကျန်စတော့ထက် ပိုမိုနုတ်၍မရပါ: {$outletName} တွင် '{$prodName}' လက်ကျန် {$avail} သာရှိသော်လည်း {$effectiveQty} နုတ်ရန် ကြိုးစားနေပါသည်။ Accounting rules strictly prohibit negative outlet inventory balances.";
                        $validator = \Illuminate\Support\Facades\Validator::make([], []);
                        $validator->errors()->add('stock', $msg);
                        throw new \Illuminate\Validation\ValidationException(
                            validator: $validator,
                            response: response()->json([
                                'status' => 'error',
                                'message' => $msg,
                                'errors' => [
                                    'stock' => [$msg],
                                ],
                            ], 422)
                        );
                    }
                }
            }
        }

        $balance->quantity = round((float)$balance->quantity + ($isAddition ? $effectiveQty : -$effectiveQty), 4);
        if ($dirtyBalances !== null) {
            $dirtyBalances[$balance->id] = $balance;
        } else {
            $balance->save();
        }

        // 2. Update parent Product global stock_quantity
        $prod->stock_quantity = round((float)$prod->stock_quantity + ($isAddition ? $effectiveQty : -$effectiveQty), 4);
        if ($dirtyProducts !== null) {
            $dirtyProducts[$prod->id] = $prod;
        } else {
            $prod->save();
        }

        // 2b. Update ProductVariant stock_quantity if variant item and column exists
        static $hasVariantStockCol = null;
        if ($hasVariantStockCol === null) {
            $hasVariantStockCol = \Illuminate\Support\Facades\Schema::hasColumn('product_variants', 'stock_quantity');
        }

        if ($variantId && $variantId > 0 && $hasVariantStockCol) {
            $variantObj = ($preloadedVariants && $preloadedVariants->has($variantId))
                ? $preloadedVariants->get($variantId)
                : ProductVariant::find($variantId);
            if ($variantObj) {
                $variantObj->stock_quantity = round((float)$variantObj->stock_quantity + ($isAddition ? $effectiveQty : -$effectiveQty), 4);
                if ($dirtyVariants !== null) {
                    $dirtyVariants[$variantObj->id] = $variantObj;
                } else {
                    $variantObj->save();
                }
            }
        }

        // 3. Record StockMovement audit entry
        if (!$isRollback) {
            $movementCreatedBy = $resolvedUserId ?: auth()->id();
            if (!$movementCreatedBy && is_numeric($userName)) {
                $movementCreatedBy = (int)$userName;
            }
            if (!$movementCreatedBy) {
                static $firstUserId = null;
                if ($firstUserId === null) {
                    $firstUserId = User::first()?->id ?? 1;
                }
                $movementCreatedBy = $firstUserId;
            }

            $movementData = [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => ($variantId && $variantId > 0) ? $variantId : null,
                'movement_type' => 'adjustment',
                'reference_type' => 'stock_adjustment',
                'reference_id' => $adjustmentId,
                'quantity_in' => $isAddition ? $effectiveQty : 0,
                'quantity_out' => !$isAddition ? $effectiveQty : 0,
                'balance_after' => (float)$balance->quantity,
                'created_by' => $movementCreatedBy,
                'note' => $notes ?: "Stock Adjustment ($adjustmentRef)",
                'created_at' => now(),
            ];

            if ($pendingMovements !== null) {
                $pendingMovements[] = $movementData;
            } else {
                StockMovement::create($movementData);
            }
        }
    }

    /**
     * Resolves and verifies unit conversion rate against ProductUnitConversionService if missing or <= 0
     */
    private function resolveItemConversionRate(
        array $itemData,
        ?Product $product,
        ?int $variantId = null
    ): float {
        $cRate = isset($itemData['conversion_rate']) ? (float)$itemData['conversion_rate'] : 0.0;
        if ($cRate > 0.0001) {
            return $cRate;
        }

        if ($product) {
            try {
                $conversionService = app(\App\Services\ProductUnitConversionService::class);
                $resolved = $conversionService->resolve(
                    product: $product,
                    type: 'sale',
                    unitId: isset($itemData['unit_id']) ? (int)$itemData['unit_id'] : null,
                    unitName: $itemData['unit_name'] ?? null,
                    variantId: $variantId
                );
                if (!empty($resolved['conversion_rate']) && (float)$resolved['conversion_rate'] > 0) {
                    return (float)$resolved['conversion_rate'];
                }
            } catch (\Throwable $e) {
                // fallback to 1.0 if not resolvable
            }
        }

        return 1.0;
    }

    /**
     * Resolves human-readable variation display string (e.g., 'Size: 32, Color: Red')
     */
    private function resolveVariantDisplayName(?string $providedName, ?int $variantId, $preloadedVariants = null): ?string
    {
        $name = trim($providedName ?? '');
        if (!empty($name) && str_contains($name, ':')) {
            return $name;
        }

        if (!empty($variantId) && $variantId > 0) {
            $pv = ($preloadedVariants && $preloadedVariants->has($variantId))
                ? $preloadedVariants->get($variantId)
                : ProductVariant::with('values')->find($variantId);
            if ($pv && $pv->relationLoaded('values') && $pv->values->isNotEmpty()) {
                $parts = [];
                foreach ($pv->values as $val) {
                    $an = trim($val->attribute_name ?? '');
                    $av = trim($val->attribute_value ?? '');
                    if (!empty($an) && !empty($av)) {
                        $parts[] = "{$an}: {$av}";
                    } elseif (!empty($av)) {
                        $parts[] = $av;
                    }
                }
                if (!empty($parts)) {
                    return implode(', ', $parts);
                }
            }
            if ($pv && !empty($pv->name)) {
                return $pv->name;
            }
        }

        return !empty($name) ? $name : null;
    }

    /**
     * Clear product list, stock, and report caches.
     */
    protected function clearAdjustmentRelatedCaches(): void
    {
        try {
            Cache::forget('inventory_valuation_report');
            Cache::forget('stock_alert_report');
            Cache::forget('pos_stock_summary');
            Cache::forget('products_cache_all');
            ReportController::invalidateDashboardCache();
        } catch (\Throwable $e) {
            Log::warning('Adjustment cache clearing failed: ' . $e->getMessage());
        }
    }

    /**
     * Enriches adjustment items with accurate product info, variation name, and attribute display
     */
    private function enrichAdjustmentItems($adj): void
    {
        if (!$adj || !$adj->relationLoaded('items') || !$adj->items) {
            return;
        }

        foreach ($adj->items as $item) {
            // 1. Ensure product name
            if (empty($item->product_name) && $item->product) {
                $item->product_name = $item->product->name;
            }

            // 2. Ensure product code (fallback to variant SKU/barcode or product code/sku)
            if (empty($item->product_code)) {
                if ($item->variant && (!empty($item->variant->sku) || !empty($item->variant->barcode))) {
                    $item->product_code = !empty($item->variant->sku) ? $item->variant->sku : $item->variant->barcode;
                } elseif ($item->product) {
                    $item->product_code = !empty($item->product->code) ? $item->product->code : ($item->product->sku ?? '');
                }
            }

            // 3. Enrich variation type and name
            if ($item->variant) {
                if ($item->variant->relationLoaded('values') && $item->variant->values && $item->variant->values->isNotEmpty()) {
                    $parts = [];
                    foreach ($item->variant->values as $val) {
                        $attrName = trim($val->attribute_name ?? '');
                        $attrVal = trim($val->attribute_value ?? '');
                        if (!empty($attrName) && !empty($attrVal)) {
                            $parts[] = "{$attrName}: {$attrVal}";
                        } elseif (!empty($attrVal)) {
                            $parts[] = $attrVal;
                        } elseif (!empty($attrName)) {
                            $parts[] = $attrName;
                        }
                    }
                    if (!empty($parts)) {
                        $item->variant_name = implode(', ', $parts);
                    }
                } elseif (empty($item->variant_name) && !empty($item->variant->name)) {
                    $item->variant_name = $item->variant->name;
                }
            }
        }
    }
}

