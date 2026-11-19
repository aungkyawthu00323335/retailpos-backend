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
use App\Support\AdjustmentReasonCatalog;
use App\Support\ListCursorHelper;
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

    protected function accounting(): \App\Services\PurchaseAccountingService
    {
        return app(\App\Services\PurchaseAccountingService::class);
    }

    /**
     * Generate next sequential adjustment code and formatted reference number.
     */
    public function generateCode()
    {
        return response()->json($this->peekNextSequentialCode('adjustment'));
    }

    /**
     * Authoritative Adjustment Reason Code & Accounting Classification catalog.
     */
    public function reasonCatalog()
    {
        return response()->json([
            'status' => 'success',
            'data' => AdjustmentReasonCatalog::all(),
        ]);
    }

    private function resolveProductForItem(array|object $item, $preloadedProducts): ?Product
    {
        $pid = is_array($item) ? ($item['product_id'] ?? null) : ($item->product_id ?? null);
        if (!empty($pid)) {
            return $preloadedProducts->get((int)$pid) ?? Product::find((int)$pid);
        }

        $code = trim((string)(is_array($item) ? ($item['product_code'] ?? '') : ($item->product_code ?? '')));
        if ($code === '') {
            return null;
        }

        return $preloadedProducts->first(fn($p) => $p->code === $code || $p->sku === $code)
            ?? Product::where('code', $code)->orWhere('sku', $code)->first();
    }

    private function resolveUnseededStockBalance(?Product $prod, ?int $variantId, $preloadedVariants = null): float
    {
        if (!$prod) {
            return 0.0;
        }
        if ($variantId !== null && $variantId > 0) {
            static $hasVariantStockCol = null;
            if ($hasVariantStockCol === null) {
                $hasVariantStockCol = \Illuminate\Support\Facades\Schema::hasColumn('product_variants', 'stock_quantity');
            }
            if (!$hasVariantStockCol) {
                return 0.0;
            }
            $variantObj = ($preloadedVariants && $preloadedVariants->has($variantId))
                ? $preloadedVariants->get($variantId)
                : ($prod->relationLoaded('variants') ? $prod->variants->firstWhere('id', $variantId) : ProductVariant::find($variantId));
            return (float)($variantObj?->stock_quantity ?? 0.0);
        }
        return (float)($prod->stock_quantity ?? 0.0);
    }

    private function resolveCurrentStockBalance(
        int $warehouseId,
        int $pid,
        ?int $vid,
        ?Product $prod,
        $preloadedBalances,
        array $existingBalanceProductIds,
        $preloadedVariants = null
    ): float {
        $balKey = $warehouseId . '_' . $pid . '_' . ($vid ?: '0');
        $bal = $preloadedBalances->get($balKey);
        if ($bal) {
            return (float)$bal->quantity;
        }
        if (!isset($existingBalanceProductIds[$pid])) {
            return $this->resolveUnseededStockBalance($prod, $vid, $preloadedVariants);
        }
        if ($vid !== null && $vid > 0) {
            $hasVariantAnyWh = StockBalance::where('product_id', $pid)
                ->where('product_variant_id', $vid)
                ->exists();
            if (!$hasVariantAnyWh) {
                return $this->resolveUnseededStockBalance($prod, $vid, $preloadedVariants);
            }
        }
        return 0.0;
    }

    private function normalizeItemType(?string $itemType, ?string $headerType = 'Addition'): string
    {
        $raw = strtolower(trim((string)($itemType ?: $headerType ?: 'Addition')));
        return $raw === 'subtraction' ? 'Subtraction' : 'Addition';
    }

    /**
     * Validate AdjustmentReasonCatalog direction & gate affects_pnl overrides behind adjustments.approve.
     *
     * @return array{error?: string, classification?: array, stored_reason_code?: ?string}
     */
    private function resolveValidatedClassification(
        mixed $rawReasonCode,
        string $voucherType,
        mixed $explicitPnlInput,
        ?User $authUser,
        ?bool $existingPnl = null,
        ?bool $fallbackPnl = null
    ): array {
        if ($rawReasonCode !== null && trim((string)$rawReasonCode) !== '') {
            $normalizedReason = AdjustmentReasonCatalog::normalize((string)$rawReasonCode);
            foreach (AdjustmentReasonCatalog::all() as $entry) {
                if (strtolower($entry['code']) === strtolower($normalizedReason)) {
                    $expectedDir = (string)($entry['direction'] ?? 'Mixed');
                    if ($expectedDir !== 'Mixed' && strcasecmp($expectedDir, $voucherType) !== 0) {
                        return [
                            'error' => "Reason '{$normalizedReason}' requires '{$expectedDir}' adjustment type (got '{$voucherType}').",
                        ];
                    }
                    break;
                }
            }
        }

        $catalogDefault = AdjustmentReasonCatalog::classify(
            $rawReasonCode !== null ? (string)$rawReasonCode : null,
            $voucherType,
            null
        );
        $defaultPnl = (bool)$catalogDefault['affects_pnl'];

        if ($explicitPnlInput !== null) {
            $requestedPnl = (bool)$explicitPnlInput;
            $differsFromCatalog = ($requestedPnl !== $defaultPnl);
            $differsFromExisting = ($existingPnl === null) ? true : ($requestedPnl !== $existingPnl);
            if ($differsFromCatalog && $differsFromExisting && $authUser && $authUser->exists && !$authUser->hasPermission('adjustments.approve')) {
                return [
                    'error' => 'Permission denied: adjustments.approve permission is required to override affects_pnl.',
                ];
            }
        }

        $effectiveExplicit = $explicitPnlInput !== null ? (bool)$explicitPnlInput : $fallbackPnl;
        $classification = AdjustmentReasonCatalog::classify(
            $rawReasonCode !== null ? (string)$rawReasonCode : null,
            $voucherType,
            $effectiveExplicit
        );
        $storedReasonCode = ($rawReasonCode === null || trim((string)$rawReasonCode) === '')
            ? null
            : $classification['reason_code'];

        return [
            'classification' => $classification,
            'stored_reason_code' => $storedReasonCode,
        ];
    }

    private function hasActiveListFilters(Request $request): bool
    {
        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') return true;
        if ($request->filled('warehouse_id')) return true;
        if ($request->filled('type') && $request->input('type') !== 'All') return true;
        if ($request->filled('reason_code') && $request->input('reason_code') !== 'All') return true;
        if ($request->filled('user') && $request->input('user') !== 'All') return true;
        if ($request->filled('user_id')) return true;
        if (!empty($request->input('date_from') ?: $request->input('from_date'))) return true;
        if (!empty($request->input('date_to') ?: $request->input('to_date'))) return true;
        if ($request->filled('search')) return true;
        return false;
    }

    private function canViewCost(?Request $request = null): bool
    {
        return app(\App\Services\PurchaseAccountingService::class)->canViewCost($request ?? request());
    }

    private const ADJUSTMENT_LIST_COLUMNS = [
        'id', 'reference_no', 'warehouse_id', 'warehouse_name', 'date',
        'total_products', 'total_quantity', 'net_quantity', 'gross_quantity',
        'total_value', 'type', 'reason_code', 'accounting_category', 'affects_pnl', 'notes',
        'user_id', 'user_name', 'created_by', 'user_role', 'created_at', 'updated_at',
    ];

    private const CURSOR_SORT_COLUMNS = [
        'id', 'date', 'reference_no', 'warehouse_name', 'type',
        'total_products', 'total_quantity', 'net_quantity', 'gross_quantity',
        'total_value',
    ];

    private function normalizeSignedTotalValue(?string $type, float $rawValue): float
    {
        $rounded = round($rawValue, 2);
        if (abs($rounded) < 0.0001) {
            return 0.0;
        }
        $normalizedType = strtolower(trim((string)$type));
        if ($normalizedType === 'subtraction') {
            return -abs($rounded);
        }
        if ($normalizedType === 'addition') {
            return abs($rounded);
        }
        return $rounded;
    }

    private function aggregateCountAndSummaryForQuery($query): array
    {
        $base = (clone $query)->reorder()->toBase();
        $base->columns = null;

        $normTypeSql = "LOWER(TRIM(COALESCE(stock_adjustments.type, '')))";
        $isSimpleHeaderSql = "({$normTypeSql} IN ('addition', 'subtraction') AND stock_adjustments.total_value IS NOT NULL AND stock_adjustments.gross_quantity IS NOT NULL AND (stock_adjustments.gross_quantity > 0 OR COALESCE(stock_adjustments.total_products, 0) = 0))";

        $agg = $base->selectRaw("
            COUNT(*) as total_count,
            COALESCE(SUM(CASE WHEN {$isSimpleHeaderSql} AND {$normTypeSql} = 'addition' THEN ABS(stock_adjustments.total_value) ELSE 0 END), 0) as add_val,
            COALESCE(SUM(CASE WHEN {$isSimpleHeaderSql} AND {$normTypeSql} = 'subtraction' THEN ABS(stock_adjustments.total_value) ELSE 0 END), 0) as sub_val,
            COALESCE(SUM(CASE WHEN {$isSimpleHeaderSql} THEN ABS(stock_adjustments.gross_quantity) ELSE 0 END), 0) as adj_qty,
            SUM(CASE WHEN NOT {$isSimpleHeaderSql} THEN 1 ELSE 0 END) as fallback_cnt
        ")->first();

        $totalCount = (int)($agg?->total_count ?? 0);
        if ($totalCount === 0) {
            return [
                'total' => 0,
                'summary' => $this->formatSummaryRow(null),
            ];
        }

        $addVal = (float)($agg?->add_val ?? 0.0);
        $subVal = (float)($agg?->sub_val ?? 0.0);
        $adjQty = (float)($agg?->adj_qty ?? 0.0);
        $fallbackCnt = (int)($agg?->fallback_cnt ?? 0);

        if ($fallbackCnt > 0) {
            $fallbackSubquery = (clone $query)->reorder()->toBase()->select('stock_adjustments.id')
                ->whereRaw("NOT {$isSimpleHeaderSql}");
            $fallbackSummary = $this->aggregateSummaryForAdjustmentIds($fallbackSubquery);
            $addVal += (float)$fallbackSummary['total_additions_value'];
            $subVal += (float)$fallbackSummary['total_subtractions_value'];
            $adjQty += (float)$fallbackSummary['total_adjusted_quantity'];
        }

        return [
            'total' => $totalCount,
            'summary' => [
                'total_additions_value' => round($addVal, 2),
                'total_subtractions_value' => round($subVal, 2),
                'total_adjusted_quantity' => round($adjQty, 4),
            ],
        ];
    }

    private function formatSummaryRow($summary): array
    {
        return [
            'total_additions_value' => round((float)($summary?->total_additions_value ?? 0.0), 2),
            'total_subtractions_value' => round((float)($summary?->total_subtractions_value ?? 0.0), 2),
            'total_adjusted_quantity' => round((float)($summary?->total_adjusted_quantity ?? 0.0), 4),
        ];
    }

    private function aggregateSummaryForAdjustmentIds(?object $subqueryIds = null, array $idList = []): array
    {
        $q = DB::table('stock_adjustment_items as sai')
            ->join('stock_adjustments as sa', 'sa.id', '=', 'sai.stock_adjustment_id');

        if (!empty($idList)) {
            $q->whereIn('sai.stock_adjustment_id', $idList);
        } elseif ($subqueryIds !== null) {
            $q->whereIn('sai.stock_adjustment_id', $subqueryIds);
        }

        $effectiveTypeSql = "LOWER(COALESCE(NULLIF(TRIM(sai.type), ''), NULLIF(TRIM(sa.type), ''), 'addition'))";
        $itemValueSql = "ABS(COALESCE(NULLIF(sai.subtotal, 0), COALESCE(sai.quantity, 0) * COALESCE(sai.unit_cost, 0), 0))";
        $itemBaseQtySql = "ABS(COALESCE(NULLIF(sai.base_quantity, 0), COALESCE(sai.quantity, 0) * COALESCE(NULLIF(sai.conversion_rate, 0), 1), 0))";

        $row = $q->selectRaw("
            COALESCE(SUM(CASE WHEN {$effectiveTypeSql} = 'addition' THEN {$itemValueSql} ELSE 0 END), 0) as total_additions_value,
            COALESCE(SUM(CASE WHEN {$effectiveTypeSql} = 'subtraction' THEN {$itemValueSql} ELSE 0 END), 0) as total_subtractions_value,
            COALESCE(SUM({$itemBaseQtySql}), 0) as total_adjusted_quantity
        ")->first();

        return $this->formatSummaryRow($row);
    }

    private function maskSummaryIfRestricted(?array $summary, bool $canViewCost): ?array
    {
        if ($summary === null || $canViewCost) {
            return $summary;
        }
        $summary['total_additions_value'] = 0.0;
        $summary['total_subtractions_value'] = 0.0;
        return $summary;
    }

    private function maskCostFieldsIfRestricted($adjustment, bool $canViewCost)
    {
        if ($canViewCost || !$adjustment) {
            return $adjustment;
        }
        $adjustment->total_value = null;
        if ($adjustment->relationLoaded('items') && $adjustment->items) {
            foreach ($adjustment->items as $item) {
                $item->unit_cost = null;
                $item->base_unit_cost = null;
                $item->subtotal = null;
                if ($item->relationLoaded('product') && $item->product) {
                    $item->product->makeHidden(['cost_price', 'purchase_price']);
                }
                if ($item->relationLoaded('variant') && $item->variant) {
                    $item->variant->makeHidden(['cost_price', 'purchase_price', 'cost']);
                }
            }
        }
        return $adjustment;
    }

    private function encodeListCursor(object $row, string $sortBy, string $sortOrder): ?string
    {
        return ListCursorHelper::encode($row, $sortBy, $sortOrder, self::CURSOR_SORT_COLUMNS);
    }

    private function decodeListCursor(?string $raw, string $sortBy, string $sortOrder): ?array
    {
        return ListCursorHelper::decode(
            $raw,
            $sortBy,
            $sortOrder,
            self::CURSOR_SORT_COLUMNS,
            ['id', 'total_products'],
            ['total_quantity', 'net_quantity', 'gross_quantity', 'total_value']
        );
    }

    private function applyCursorConstraint($query, string $sortBy, string $sortOrder, array $cursor): void
    {
        ListCursorHelper::apply($query, $sortBy, $sortOrder, $cursor);
    }

    private function fetchNullTotalValuesByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $effectiveTypeSql = "LOWER(COALESCE(NULLIF(TRIM(sai.type), ''), NULLIF(TRIM(sa.type), ''), 'addition'))";
        $itemValueSql = "ABS(COALESCE(NULLIF(sai.subtotal, 0), COALESCE(sai.quantity, 0) * COALESCE(sai.unit_cost, 0), 0))";

        return DB::table('stock_adjustment_items as sai')
            ->join('stock_adjustments as sa', 'sa.id', '=', 'sai.stock_adjustment_id')
            ->whereIn('sai.stock_adjustment_id', $ids)
            ->groupBy('sai.stock_adjustment_id')
            ->selectRaw("sai.stock_adjustment_id, SUM(CASE WHEN {$effectiveTypeSql} = 'subtraction' THEN -{$itemValueSql} ELSE {$itemValueSql} END) as net_val")
            ->pluck('net_val', 'stock_adjustment_id')
            ->all();
    }

    private function attachNoItemsTotalValues($adjustments): void
    {
        $missingIds = [];
        foreach ($adjustments as $adj) {
            if ($adj->total_value === null && !empty($adj->id)) {
                $missingIds[] = (int)$adj->id;
            }
        }
        $valueMap = !empty($missingIds) ? $this->fetchNullTotalValuesByIds($missingIds) : [];
        foreach ($adjustments as $adj) {
            $rawVal = $adj->total_value !== null
                ? (float)$adj->total_value
                : (float)($valueMap[$adj->id] ?? 0.0);
            $adj->setAttribute('total_value', $this->normalizeSignedTotalValue($adj->type ?? null, $rawVal));
        }
    }

    private function formatBaseNoItemsRows($rows, bool $canViewCost = true): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $missingValueIds = [];
        $userIds = [];
        foreach ($rows as $row) {
            if ($canViewCost && $row->total_value === null && !empty($row->id)) {
                $missingValueIds[] = (int)$row->id;
            }
            if (!empty($row->user_id)) {
                $uid = (int)$row->user_id;
                $userIds[$uid] = $uid;
            }
        }

        $fallbackValues = $canViewCost ? $this->fetchNullTotalValuesByIds($missingValueIds) : [];
        $userMap = !empty($userIds)
            ? DB::table('users')->whereIn('id', array_values($userIds))->get(['id', 'name', 'role'])->keyBy('id')
            : collect();

        $formatted = [];
        foreach ($rows as $row) {
            $u = !empty($row->user_id) ? $userMap->get((int)$row->user_id) : null;
            $createdBy = $u ? (string)$u->name : ($row->created_by ?? null);
            $userRole = ($u && !empty($u->role)) ? (string)$u->role : ($row->user_role ?? null);
            $totalValue = null;
            if ($canViewCost) {
                $rawVal = $row->total_value !== null
                    ? (float)$row->total_value
                    : (float)($fallbackValues[$row->id] ?? 0.0);
                $totalValue = $this->normalizeSignedTotalValue($row->type ?? null, $rawVal);
            }

            $formatted[] = [
                'id' => (int)$row->id,
                'reference_no' => $row->reference_no,
                'warehouse_id' => $row->warehouse_id !== null ? (int)$row->warehouse_id : null,
                'warehouse_name' => $row->warehouse_name,
                'date' => $row->date,
                'total_products' => (int)$row->total_products,
                'total_quantity' => (float)$row->total_quantity,
                'net_quantity' => (float)$row->net_quantity,
                'gross_quantity' => (float)$row->gross_quantity,
                'total_value' => $totalValue,
                'type' => $row->type,
                'reason_code' => $row->reason_code,
                'accounting_category' => $row->accounting_category ?? 'general_adjustment',
                'affects_pnl' => $row->affects_pnl !== null ? (bool)$row->affects_pnl : !AdjustmentReasonCatalog::isNonPnlReasonCode($row->reason_code),
                'notes' => $row->notes,
                'user_id' => $row->user_id !== null ? (int)$row->user_id : null,
                'user_name' => $row->user_name,
                'created_by' => $createdBy,
                'user_role' => $userRole,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'user' => $u ? ['id' => (int)$u->id, 'name' => $u->name, 'role' => $u->role] : null,
            ];
        }

        return $formatted;
    }

    public function buildFilteredQuery(Request $request, bool $skipRelations = false)
    {
        $accounting = $this->accounting();
        $query = StockAdjustment::select(self::ADJUSTMENT_LIST_COLUMNS);
        $accounting->applyOutletScope($query, $accounting->extractAllowedOutletIds($request), 'stock_adjustments.warehouse_id');
        if (!$skipRelations) {
            $relations = ['user:id,name,role'];
            if (!$request->boolean('no_items', false)) {
                $relations[] = 'items:id,stock_adjustment_id,product_id,product_variant_id,product_code,product_name,variant_name,unit_id,unit_name,conversion_rate,quantity,base_quantity,type,unit_cost,base_unit_cost,subtotal';
            }
            $query->with($relations);
        }

        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $query->where('stock_adjustments.warehouse_name', $request->input('warehouse'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('stock_adjustments.warehouse_id', (int)$request->input('warehouse_id'));
        }

        if ($request->filled('type') && $request->input('type') !== 'All') {
            $query->where('stock_adjustments.type', $request->input('type'));
        }

        if ($request->filled('reason_code') && $request->input('reason_code') !== 'All') {
            $reason = trim($request->input('reason_code'));
            if ($reason === 'Normal / Other' || $reason === 'Normal') {
                $query->where(function ($q) {
                    $q->whereNull('stock_adjustments.reason_code')
                      ->orWhere('stock_adjustments.reason_code', '')
                      ->orWhere('stock_adjustments.reason_code', 'Normal / Other')
                      ->orWhere('stock_adjustments.reason_code', 'Normal');
                });
            } else {
                $query->where('stock_adjustments.reason_code', $reason);
            }
        }

        if ($request->filled('user_id')) {
            $query->where('stock_adjustments.user_id', (int)$request->input('user_id'));
        }

        if ($request->filled('user') && $request->input('user') !== 'All') {
            $userFilter = trim((string)$request->input('user'));
            $query->where(function ($q) use ($userFilter) {
                $q->where('stock_adjustments.created_by', $userFilter)
                  ->orWhere('stock_adjustments.user_name', $userFilter)
                  ->orWhere('stock_adjustments.created_by', 'like', $userFilter . ' (%')
                  ->orWhereHas('user', function ($uq) use ($userFilter) {
                      $uq->where('name', $userFilter);
                  });
            });
        }

        $dateFrom = $request->input('date_from') ?: $request->input('from_date');
        if (!empty($dateFrom)) {
            $dateFrom = trim((string)$dateFrom);
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+00:00:00$/', $dateFrom, $m)) {
                $dateFrom = $m[1];
            }
            $query->where('stock_adjustments.date', '>=', $dateFrom);
        }

        $dateTo = $request->input('date_to') ?: $request->input('to_date');
        if (!empty($dateTo)) {
            $dateTo = trim((string)$dateTo);
            if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s+23:59:59)?$/', $dateTo, $m)) {
                $nextDay = \Carbon\Carbon::createFromFormat('Y-m-d', $m[1])->addDay()->format('Y-m-d');
                $query->where('stock_adjustments.date', '<', $nextDay);
            } else {
                $query->where('stock_adjustments.date', '<=', $dateTo);
            }
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('stock_adjustments.reference_no', 'like', "%{$search}%")
                  ->orWhere('stock_adjustments.warehouse_name', 'like', "%{$search}%")
                  ->orWhere('stock_adjustments.reason_code', 'like', "%{$search}%")
                  ->orWhere('stock_adjustments.notes', 'like', "%{$search}%")
                  ->orWhere('stock_adjustments.created_by', 'like', "%{$search}%")
                  ->orWhere('stock_adjustments.user_name', 'like', "%{$search}%")
                  ->orWhere('stock_adjustments.user_role', 'like', "%{$search}%")
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
        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        $noItems = $request->boolean('no_items', false);
        $skipSummary = $request->boolean('skip_summary', false);
        $knownTotal = $request->filled('known_total') ? max(0, (int)$request->input('known_total')) : null;
        $isUnfiltered = !$this->hasActiveListFilters($request);
        $canViewCost = $this->canViewCost($request);

        $sortBy = (string)$request->input('sort_by', 'id');
        $sortColumnMap = [
            'reference' => 'reference_no',
            'warehouse' => 'warehouse_name',
            'items'     => 'total_products',
            'quantity'  => 'total_quantity',
            'user'      => 'created_by',
            'value'     => 'total_value',
        ];
        if (isset($sortColumnMap[$sortBy])) {
            $sortBy = $sortColumnMap[$sortBy];
        }
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'reference_no', 'warehouse_name', 'date', 'type', 'total_products', 'total_quantity', 'net_quantity', 'gross_quantity', 'total_value', 'reason_code', 'created_at', 'created_by', 'user_role'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }
        if (!$canViewCost && $sortBy === 'total_value') {
            $sortBy = 'id';
        }

        $perPage = min(max((int)$request->input('per_page', 50), 1), 1000);
        $currentPage = max(1, (int)$request->input('page', 1));
        $cursorData = !$isAll
            ? $this->decodeListCursor((string)$request->input('cursor', ''), $sortBy, $sortOrder)
            : null;
        $isScrollPage = ($currentPage > 1 || $cursorData !== null);

        $accounting = $this->accounting();
        $cacheControl = 'no-store, no-cache, must-revalidate, private';

        $query = $this->buildFilteredQuery($request, $noItems);

        $mapUserFields = static function ($adj) {
            if ($adj->user) {
                $adj->created_by = $adj->user->name;
                if (!empty($adj->user->role)) {
                    $adj->user_role = $adj->user->role;
                }
            }
            return $adj;
        };

        $computeSummaryForFiltered = function ($totalCount) use ($query) {
            if ($totalCount === 0) {
                return $this->formatSummaryRow(null);
            }
            return $this->aggregateSummaryForAdjustmentIds(
                (clone $query)->reorder()->toBase()->select('stock_adjustments.id')
            );
        };

        if ($isAll) {
            $orderedQuery = (clone $query)->orderBy($sortBy, $sortOrder);
            if ($sortBy !== 'id') {
                $orderedQuery->orderBy('id', $sortOrder);
            }
            $aggResult = $this->aggregateCountAndSummaryForQuery($query);
            $totalCount = $aggResult['total'];
            $summaryData = $aggResult['summary'];
            $limit = min(500, max(1, (int)$request->input('limit', 500)));
            if ($noItems) {
                $rows = $orderedQuery->toBase()->limit($limit)->get();
                $dataPayload = $this->formatBaseNoItemsRows($rows, $canViewCost);
                $rowCount = count($dataPayload);
            } else {
                $adjustments = $orderedQuery->take($limit)->get();
                if ($canViewCost) {
                    $this->attachNoItemsTotalValues($adjustments);
                }
                $dataPayload = $adjustments->map(function ($adj) use ($mapUserFields, $canViewCost) {
                    $adj = $mapUserFields($adj);
                    return $this->maskCostFieldsIfRestricted($adj, $canViewCost);
                });
                $rowCount = $adjustments->count();
            }
            $summaryData = $this->maskSummaryIfRestricted($summaryData, $canViewCost);

            return response()->json([
                'status' => 'success',
                'data' => $dataPayload,
                'total' => $totalCount,
                'summary' => $summaryData,
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $rowCount,
                    'total' => $totalCount,
                    'last_page' => max(1, (int)ceil($totalCount / max(1, $limit))),
                ],
            ])->header('Cache-Control', $cacheControl);
        }

        // Resolve total & summary live in a single header-level aggregate pass,
        // skipping redundant COUNT(*) / SUM() on scroll pages or pure sort re-fetches.
        $summaryData = null;
        if ($skipSummary && $knownTotal !== null) {
            $totalCount = $knownTotal;
        } elseif ($skipSummary) {
            $totalCount = (clone $query)->toBase()->count();
        } else {
            $aggResult = $this->aggregateCountAndSummaryForQuery($query);
            $totalCount = $aggResult['total'];
            $summaryData = $aggResult['summary'];
        }

        // Execute keyset cursor query when cursor provided (O(log N), zero OFFSET scan),
        // or lean offset+1 query on direct page loads.
        $listQuery = (clone $query);
        if ($cursorData !== null) {
            $this->applyCursorConstraint($listQuery, $sortBy, $sortOrder, $cursorData);
        }
        $listQuery->orderBy($sortBy, $sortOrder);
        if ($sortBy !== 'id') {
            $listQuery->orderBy('id', $sortOrder);
        }

        $offset = $cursorData !== null ? 0 : (($currentPage - 1) * $perPage);
        if ($noItems) {
            $fetched = $listQuery->toBase()->offset($offset)->limit($perPage + 1)->get();
            $hasMore = $fetched->count() > $perPage;
            $pageRows = $hasMore ? $fetched->slice(0, $perPage)->values() : $fetched;
            $dataPayload = $this->formatBaseNoItemsRows($pageRows, $canViewCost);
            $lastRowObj = $pageRows->last();
            if ($totalCount === 0 && $pageRows->isNotEmpty()) {
                $totalCount = $offset + $pageRows->count() + ($hasMore ? 1 : 0);
            }
        } else {
            $fetched = $listQuery->offset($offset)->limit($perPage + 1)->get();
            $hasMore = $fetched->count() > $perPage;
            $pageRows = $hasMore ? $fetched->slice(0, $perPage)->values() : $fetched;
            if ($canViewCost) {
                $this->attachNoItemsTotalValues($pageRows);
            }
            $dataPayload = $pageRows->map(function ($adj) use ($mapUserFields, $canViewCost) {
                $adj = $mapUserFields($adj);
                return $this->maskCostFieldsIfRestricted($adj, $canViewCost);
            });
            $lastRowObj = $pageRows->last();
            if ($totalCount === 0 && $pageRows->isNotEmpty()) {
                $totalCount = $offset + $pageRows->count() + ($hasMore ? 1 : 0);
            }
        }

        // Single-page optimization: compute summary strictly for fetched page IDs when totalCount <= perPage
        if ($summaryData === null && !$skipSummary && $totalCount > 0) {
            $pageIds = $pageRows->pluck('id')->filter()->values()->all();
            if ($totalCount <= $perPage && count($pageIds) === $totalCount) {
                $summaryData = $this->aggregateSummaryForAdjustmentIds(null, $pageIds);
            } else {
                $summaryData = $computeSummaryForFiltered($totalCount);
            }
        }
        $summaryData = $this->maskSummaryIfRestricted($summaryData, $canViewCost);

        $nextCursor = ($hasMore && $lastRowObj !== null)
            ? $this->encodeListCursor((object)$lastRowObj, $sortBy, $sortOrder)
            : null;
        $lastPage = max(1, (int)ceil($totalCount / max(1, $perPage)));

        $pagination = [
            'current_page' => $currentPage,
            'per_page' => $perPage,
            'total' => $totalCount,
            'last_page' => $lastPage,
            'has_more' => $hasMore,
        ];
        if ($nextCursor !== null) {
            $pagination['next_cursor'] = $nextCursor;
        }

        $payload = [
            'status' => 'success',
            'data' => $dataPayload,
            'total' => $totalCount,
            'pagination' => $pagination,
        ];
        if ($summaryData !== null) {
            $payload['summary'] = $summaryData;
        }

        return response()->json($payload)->header('Cache-Control', $cacheControl);
    }

    /**
     * Stream complete filtered stock adjustments export as CSV.
     */
    public function export(Request $request)
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $canViewCost = $this->canViewCost($request);
        $query = $this->buildFilteredQuery($request, true);
        $query->select(array_map(
            static fn (string $col): string => "stock_adjustments.{$col}",
            self::ADJUSTMENT_LIST_COLUMNS
        ));

        $rawSort = (string)$request->input('sort_by', 'stock_adjustments.id');
        $sortColumnMap = [
            'reference' => 'stock_adjustments.reference_no',
            'reference_no' => 'stock_adjustments.reference_no',
            'warehouse' => 'stock_adjustments.warehouse_name',
            'warehouse_name' => 'stock_adjustments.warehouse_name',
            'items' => 'stock_adjustments.total_products',
            'total_products' => 'stock_adjustments.total_products',
            'quantity' => 'stock_adjustments.total_quantity',
            'total_quantity' => 'stock_adjustments.total_quantity',
            'net_quantity' => 'stock_adjustments.net_quantity',
            'gross_quantity' => 'stock_adjustments.gross_quantity',
            'value' => 'estimated_val',
            'total_value' => 'estimated_val',
            'user' => 'resolved_user_name',
            'created_by' => 'resolved_user_name',
            'user_role' => 'resolved_user_role',
            'date' => 'stock_adjustments.date',
            'type' => 'stock_adjustments.type',
            'reason_code' => 'stock_adjustments.reason_code',
            'created_at' => 'stock_adjustments.created_at',
            'id' => 'stock_adjustments.id',
            'stock_adjustments.id' => 'stock_adjustments.id',
        ];
        $sortBy = $sortColumnMap[$rawSort] ?? 'stock_adjustments.id';
        if (!$canViewCost && $sortBy === 'estimated_val') {
            $sortBy = 'stock_adjustments.id';
        }
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

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

        // Resolve latest user metadata & aggregated item valuation in O(1) per row without N+1 queries
        $query->leftJoin('users as u', 'stock_adjustments.user_id', '=', 'u.id')
            ->addSelect(
                DB::raw("COALESCE(NULLIF(u.name, ''), NULLIF(stock_adjustments.created_by, ''), NULLIF(stock_adjustments.user_name, ''), 'Admin') as resolved_user_name"),
                DB::raw("COALESCE(NULLIF(u.role, ''), NULLIF(stock_adjustments.user_role, ''), 'Admin') as resolved_user_role")
            );

        $saiSql = "(SELECT sai_i.stock_adjustment_id, SUM(CASE WHEN LOWER(COALESCE(NULLIF(TRIM(sai_i.type), ''), NULLIF(TRIM(sai_h.type), ''), 'addition')) = 'subtraction' THEN -ABS(COALESCE(NULLIF(sai_i.subtotal, 0), COALESCE(sai_i.quantity, 0) * COALESCE(sai_i.unit_cost, 0), 0)) ELSE ABS(COALESCE(NULLIF(sai_i.subtotal, 0), COALESCE(sai_i.quantity, 0) * COALESCE(sai_i.unit_cost, 0), 0)) END) as val FROM stock_adjustment_items as sai_i INNER JOIN stock_adjustments as sai_h ON sai_h.id = sai_i.stock_adjustment_id GROUP BY sai_i.stock_adjustment_id) as sai";
        $query->leftJoin(
            DB::raw($saiSql),
            'stock_adjustments.id',
            '=',
            'sai.stock_adjustment_id'
        )->addSelect(DB::raw('COALESCE(sai.val, stock_adjustments.total_value, 0) as estimated_val'));

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
            $searchQuery,
            $canViewCost
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
            fputcsv($file, ['Stock Adjustments Export']);
            fputcsv($file, ['Generated At', date('c')]);
            fputcsv($file, ['Warehouse Filter', $sanitizeCsv($warehouseFilter ?: 'All')]);
            fputcsv($file, ['Type Filter', $sanitizeCsv($typeFilter ?: 'All')]);
            if (!empty($reasonFilter) && $reasonFilter !== 'All') {
                fputcsv($file, ['Reason Code Filter', $sanitizeCsv($reasonFilter)]);
            }
            if (!empty($userFilter) && $userFilter !== 'All') {
                fputcsv($file, ['Created By Filter', $sanitizeCsv($userFilter)]);
            }
            if (!empty($dateFrom) || !empty($dateTo)) {
                $fromStr = !empty($dateFrom) ? explode(' ', (string)$dateFrom)[0] : 'Beginning';
                $toStr = !empty($dateTo) ? explode(' ', (string)$dateTo)[0] : 'Present';
                fputcsv($file, ['Date Range', "$fromStr to $toStr"]);
            }
            if (!empty($searchQuery)) {
                fputcsv($file, ['Search Filter', $sanitizeCsv($searchQuery)]);
            }
            fwrite($file, "\r\n");

            $currencySettings = Setting::whereIn('key', ['default_currency', 'currency_symbol'])->pluck('value', 'key');
            $currencyLabel = trim((string)($currencySettings['default_currency'] ?? $currencySettings['currency_symbol'] ?? ''));
            $currencyLabel = $currencyLabel !== '' ? $currencyLabel : 'MMK';

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
                "Total Estimated Value ({$currencyLabel})",
                'Created By',
                'User Role',
                'Notes',
                'Accounting Category',
                'Affects P&L',
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
            $orderedQuery = $query->orderBy($sortBy, $sortOrder);
            if ($sortBy !== 'stock_adjustments.id') {
                $orderedQuery->orderBy('stock_adjustments.id', $sortOrder);
            }

            foreach ($orderedQuery->toBase()->cursor() as $adj) {
                $gross = (float)$adj->gross_quantity > 0 ? (float)$adj->gross_quantity : abs((float)$adj->total_quantity);
                $net = (float)$adj->net_quantity;
                if (abs($net) <= 0.0001 && abs($gross) > 0.0001) {
                    $adjTypeLower = strtolower(trim((string)$adj->type));
                    if ($adjTypeLower === 'addition') {
                        $net = $gross;
                    } elseif ($adjTypeLower === 'subtraction') {
                        $net = -$gross;
                    }
                }
                $val = $canViewCost ? (float)$adj->estimated_val : 0.0;

                $totalItemsSum += (int)$adj->total_products;
                $totalGrossQtySum += $gross;
                $totalNetQtySum += $net;
                if ($canViewCost) {
                    $totalEstimatedValueSum += $val;
                }

                $rawDate = trim((string)($adj->date ?? ''));
                if ($rawDate !== '' && (str_ends_with($rawDate, 'Z') || str_ends_with($rawDate, 'z') || preg_match('/[+-]\d{2}:?\d{2}$/', $rawDate))) {
                    try {
                        $dt = \Carbon\Carbon::parse($rawDate)->setTimezone(config('app.timezone', 'UTC'));
                        $dateOnly = $dt->format('Y-m-d');
                        $timeStr = $dt->format('H:i:s');
                    } catch (\Throwable) {
                        $dateParts = preg_split('/[T ]+/', $rawDate, 2);
                        $dateOnly = $dateParts[0] ?? '';
                        $timeStr = $dateParts[1] ?? '';
                    }
                } else {
                    $dateParts = preg_split('/[T ]+/', $rawDate, 2);
                    $dateOnly = $dateParts[0] ?? '';
                    $timeStr = isset($dateParts[1]) ? preg_replace('/\.\d+$/', '', $dateParts[1]) : '';
                }

                $createdBy = trim((string)($adj->resolved_user_name ?: ($adj->created_by ?: ($adj->user_name ?: 'Admin'))));
                if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $createdBy, $m)) {
                    $createdBy = trim($m[1]);
                }
                $userRole = trim((string)($adj->resolved_user_role ?: ($adj->user_role ?: 'Admin')));

                $adjCategory = $adj->accounting_category ?: 'general_adjustment';
                $adjAffectsPnl = ($adj->affects_pnl !== null
                    ? (bool)$adj->affects_pnl
                    : !AdjustmentReasonCatalog::isNonPnlReasonCode($adj->reason_code))
                    ? 'Yes'
                    : 'No';

                $row = [
                    $sn++,
                    $sanitizeCsv($adj->reference_no),
                    $sanitizeCsv($adj->warehouse_name),
                    $dateOnly,
                    $timeStr,
                    $sanitizeCsv($adj->type),
                    $sanitizeCsv($adj->reason_code ?: '-'),
                    $adj->total_products,
                    $formatNum($gross),
                    $formatNum($net),
                    $canViewCost ? $formatNum($val) : 'Protected',
                    $sanitizeCsv($createdBy),
                    $sanitizeCsv($userRole),
                    $sanitizeCsv($adj->notes ?? ''),
                    $sanitizeCsv($adjCategory),
                    $adjAffectsPnl,
                ];
                fputcsv($file, $row);

                if (++$flushCount % 500 === 0) {
                    flush();
                }
            }

            // 4. Summary Row
            fputcsv($file, []);
            fputcsv($file, [
                'TOTAL',
                ($sn - 1) . ' Adjustments',
                '',
                '',
                '',
                '',
                '',
                $formatNum($totalItemsSum),
                $formatNum($totalGrossQtySum),
                $formatNum($totalNetQtySum),
                $canViewCost ? $formatNum($totalEstimatedValueSum) : 'Protected',
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:255',
            'warehouse_id' => 'nullable|integer',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'type' => 'required|string|in:Addition,Subtraction,Mixed',
            'reason_code' => 'nullable|string|max:100',
            'affects_pnl' => 'nullable|boolean',
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
        $this->accounting()->assertOutletAccess($request, $warehouseId, $validated['warehouse_name']);

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

            $preloadedProducts = Product::with(['unitLinks', 'variants'])
                ->whereIn('id', $sortedPids)
                ->when(!empty($rawCodes), fn($q) => $q->orWhereIn('code', $rawCodes)->orWhereIn('sku', $rawCodes))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $preloadedVariants = !empty($rawVids)
                ? ProductVariant::with('values')->whereIn('id', array_values(array_unique($rawVids)))->get()->keyBy('id')
                : collect();

            if ($variantErr = $this->validateAdjustmentItemsConsistency($validated['items'], $preloadedProducts, $preloadedVariants)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => $variantErr,
                ], 422);
            }

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
                $itemType = $this->normalizeItemType($item['type'] ?? null, $validated['type']);
                $prod = $this->resolveProductForItem($item, $preloadedProducts);
                $pid = $prod?->id ?? (!empty($item['product_id']) ? (int)$item['product_id'] : null);
                $vid = $item['product_variant_id'] ?? null;
                $key = ($pid ?? 'p0') . '_' . ($vid ? "var_{$vid}" : 'single');
                $cRate = $this->resolveItemConversionRate($item, $prod, $vid);
                $qty = (float)$item['quantity'];
                $baseQty = $this->computeAuthoritativeBaseQuantity($qty, $cRate);

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
                    $prod = $preloadedProducts->get($pid) ?? Product::find($pid);
                    $curBal = $this->resolveCurrentStockBalance(
                        (int)$warehouseId,
                        (int)$pid,
                        $vid ? (int)$vid : null,
                        $prod,
                        $preloadedBalances,
                        $existingBalanceProductIds,
                        $preloadedVariants
                    );

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
                $prod = $this->resolveProductForItem($item, $preloadedProducts);
                $vid = $item['product_variant_id'] ?? null;
                $cRate = $this->resolveItemConversionRate($item, $prod, $vid);
                $q = (float)$item['quantity'];
                $baseQ = $this->computeAuthoritativeBaseQuantity($q, $cRate);
                $itemType = $this->normalizeItemType($item['type'] ?? null, $validated['type']);

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

            $authUser = User::resolveFromRequest($request) ?? $request->user() ?? auth()->user();
            if ($authUser && $authUser->exists) {
                $userId = $authUser->id;
                $createdByName = $authUser->name ?: 'Super Admin';
                $userRole = $authUser->roleModel?->name ?? $authUser->role ?? 'Super Admin';
            } else {
                $targetUser = !empty($validated['user_id']) ? User::find($validated['user_id']) : null;
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
            }

            $txDate = app(\App\Services\PurchaseAccountingService::class)->normalizeTransactionDate($validated['date']);

            $rawReasonCode = $validated['reason_code'] ?? null;
            $explicitPnl = array_key_exists('affects_pnl', $validated) && $validated['affects_pnl'] !== null
                ? (bool)$validated['affects_pnl']
                : null;
            $resolvedClass = $this->resolveValidatedClassification(
                $rawReasonCode,
                $voucherType,
                $explicitPnl,
                $authUser
            );
            if (isset($resolvedClass['error'])) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => $resolvedClass['error'],
                ], 422);
            }
            $classification = $resolvedClass['classification'];
            $storedReasonCode = $resolvedClass['stored_reason_code'];

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
                'reason_code' => $storedReasonCode,
                'accounting_category' => $classification['accounting_category'],
                'affects_pnl' => $classification['affects_pnl'],
                'notes' => $validated['notes'] ?? null,
                'user_id' => $userId,
                'user_name' => $createdByName,
                'created_by' => $createdByName,
                'user_role' => $userRole,
            ]);

            $fifoService = app(\App\Services\FifoInventoryService::class);

            // Process Addition items before Subtraction items so intra-voucher additions supply batches for subtractions.
            $orderedStoreItems = $validated['items'];
            usort($orderedStoreItems, function ($a, $b) use ($adjustment) {
                $typeA = $this->normalizeItemType($a['type'] ?? null, $adjustment->type);
                $typeB = $this->normalizeItemType($b['type'] ?? null, $adjustment->type);
                if ($typeA === $typeB) return 0;
                return $typeA === 'Addition' ? -1 : 1;
            });

            $dirtyBalances = [];
            $dirtyProducts = [];
            $dirtyVariants = [];
            $pendingMovements = [];
            $totalNetValue = 0.0;

            $canViewCost = $this->canViewCost($request);
            foreach ($orderedStoreItems as $itemData) {
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
                $baseQty = $this->computeAuthoritativeBaseQuantity($quantity, $conversionRate);

                $variantName = $this->resolveVariantDisplayName($itemData['variant_name'] ?? null, $resolvedVariantId, $preloadedVariants);
                $itemType = $this->normalizeItemType($itemData['type'] ?? null, $validated['type'] ?? $voucherType);

                // Cost-blind users cannot forge custom unit_cost values on create; enforce authoritative catalog cost.
                if (!$canViewCost) {
                    $varObj = ($resolvedVariantId && $preloadedVariants->has($resolvedVariantId)) ? $preloadedVariants->get($resolvedVariantId) : ($resolvedVariantId ? ProductVariant::find($resolvedVariantId) : null);
                    $catalogCost = (float)($varObj?->cost_price ?: ($varObj?->purchase_price ?: ($prod?->cost_price ?: ($prod?->purchase_price ?: 0.0))));
                    if ($catalogCost <= 0.0001) {
                        $msg = 'Cannot create adjustment items without an authoritative product cost when lacking products.view_cost permission.';
                        throw new \Illuminate\Validation\ValidationException(
                            validator: \Illuminate\Support\Facades\Validator::make([], []),
                            response: response()->json([
                                'status' => 'error',
                                'message' => $msg,
                                'errors' => ['items' => [$msg]],
                            ], 422)
                        );
                    }
                    $baseCost = $catalogCost;
                    $unitCost = $conversionRate > 0 ? round($catalogCost * $conversionRate, 4) : $catalogCost;
                } elseif ($unitCost <= 0.0001 && $resolvedProductId) {
                    $varObj = ($resolvedVariantId && $preloadedVariants->has($resolvedVariantId)) ? $preloadedVariants->get($resolvedVariantId) : ($resolvedVariantId ? ProductVariant::find($resolvedVariantId) : null);
                    $fallbackCost = (float)($varObj?->cost_price ?: ($varObj?->purchase_price ?: ($prod?->cost_price ?: ($prod?->purchase_price ?: 0.0))));
                    if ($fallbackCost > 0) {
                        $baseCost = $fallbackCost;
                        $unitCost = $conversionRate > 0 ? round($fallbackCost * $conversionRate, 4) : $fallbackCost;
                    } else {
                        $baseCost = 0.0;
                    }
                } else {
                    $baseCost = $conversionRate > 0 ? round($unitCost / $conversionRate, 8) : round($unitCost, 8);
                }

                $subtotal = round($quantity * $unitCost, 2);

                $finalSubtotal = $subtotal;
                $finalBaseCost = $baseCost;
                $finalUnitCost = $unitCost;
                $recordedLayers = [];

                if ($resolvedProductId && $itemType === 'Subtraction') {
                    $actualLossCost = $fifoService->depleteForAdjustment(
                        warehouseId: (int)$warehouseId,
                        productId: (int)$resolvedProductId,
                        variantId: $resolvedVariantId,
                        baseQty: (float)$baseQty,
                        referenceNo: (string)$refNo,
                        fallbackCost: (float)$baseCost,
                        recordedLayers: $recordedLayers
                    );
                    $finalSubtotal = $actualLossCost;
                    $finalBaseCost = $baseQty > 0 ? round($actualLossCost / $baseQty, 8) : $baseCost;
                    $finalUnitCost = $quantity > 0
                        ? round($actualLossCost / $quantity, 4)
                        : round($finalBaseCost * ($conversionRate > 0 ? $conversionRate : 1.0), 4);
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
                if (!empty($recordedLayers)) {
                    $fifoService->persistAdjustmentCostLayers($adjustment->id, $adjItem->id, $recordedLayers);
                }
                $totalNetValue += $itemType === 'Subtraction' ? -$finalSubtotal : $finalSubtotal;

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
                    $pendingMovements,
                    (string)$adjustment->date
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

            $adjustment->update(['total_value' => round($totalNetValue, 2)]);

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
                'data' => $this->prepareAdjustmentResponse($adjustment, true, $request),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("AdjustmentController store error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function prepareAdjustmentResponse(StockAdjustment $adjustment, bool $reloadRelations = true, ?Request $request = null): StockAdjustment
    {
        if ($reloadRelations) {
            $adjustment->load([
                'user:id,name,role',
                'items.variant.values',
                'items.product',
            ]);
        }
        if ($adjustment->user) {
            $adjustment->created_by = $adjustment->user->name;
            if (!empty($adjustment->user->role)) {
                $adjustment->user_role = $adjustment->user->role;
            }
        }
        $this->enrichAdjustmentItems($adjustment);
        return $this->maskCostFieldsIfRestricted($adjustment, $this->canViewCost($request));
    }

    public function show(Request $request, $id)
    {
        $adjustment = StockAdjustment::with([
            'user:id,name,role',
            'items.variant.values',
            'items.product',
        ])->find($id);

        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }
        $this->accounting()->assertOutletAccess(
            $request,
            $adjustment->warehouse_id ? (int)$adjustment->warehouse_id : null,
            $adjustment->warehouse_name
        );

        return response()->json([
            'status' => 'success',
            'data' => $this->prepareAdjustmentResponse($adjustment, false, $request),
        ]);
    }

    public function update(Request $request, $id)
    {
        $adjustment = StockAdjustment::with('items')->find($id);
        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }
        $this->accounting()->assertOutletAccess(
            $request,
            $adjustment->warehouse_id ? (int)$adjustment->warehouse_id : null,
            $adjustment->warehouse_name
        );

        $validated = $request->validate([
            'reference_no' => ['nullable', 'string', 'max:255', Rule::unique('stock_adjustments', 'reference_no')->ignore($id)],
            'warehouse_id' => 'nullable|integer',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'type' => 'nullable|string|in:Addition,Subtraction,Mixed',
            'reason_code' => 'nullable|string|max:100',
            'affects_pnl' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'user_id' => 'nullable|integer',
            'user_name' => 'nullable|string|max:255',
            'created_by' => 'nullable|string|max:255',
            'user_role' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|integer',
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
            if ($oldWarehouseId !== $newWarehouseId) {
                $this->accounting()->assertOutletAccess($request, $newWarehouseId, $validated['warehouse_name']);
            }
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

            $preloadedProducts = Product::with(['unitLinks', 'variants'])
                ->whereIn('id', $sortedPids)
                ->when(!empty($rawCodes), fn($q) => $q->orWhereIn('code', $rawCodes)->orWhereIn('sku', $rawCodes))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $preloadedVariants = !empty($rawVids)
                ? ProductVariant::with('values')->whereIn('id', array_values(array_unique($rawVids)))->get()->keyBy('id')
                : collect();

            if ($variantErr = $this->validateAdjustmentItemsConsistency($validated['items'], $preloadedProducts, $preloadedVariants)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => $variantErr,
                ], 422);
            }

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
                    $oldType = $this->normalizeItemType($oldItem->type, $adjustment->type);
                    $signedEffect = ($oldType === 'Subtraction') ? -$baseQ : $baseQ;

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
                    $baseQ = $this->computeAuthoritativeBaseQuantity($q, $cRate);
                    $itemType = $this->normalizeItemType($item['type'] ?? null, $validated['type'] ?? $adjustment->type);
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
                        $prod = $preloadedProducts->get($pid) ?? Product::find($pid);
                        $curBal = $this->resolveCurrentStockBalance(
                            (int)$newWarehouseId,
                            (int)$pid,
                            $vid ? (int)$vid : null,
                            $prod,
                            $preloadedBalances,
                            $existingBalanceProductIds,
                            $preloadedVariants
                        );
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
                    $oldType = $this->normalizeItemType($oldItem->type, $adjustment->type);

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
                        $prod = $preloadedProducts->get($pid) ?? Product::find($pid);
                        $curBal = $this->resolveCurrentStockBalance(
                            (int)$oldWarehouseId,
                            (int)$pid,
                            $vid ? (int)$vid : null,
                            $prod,
                            $preloadedBalances,
                            $existingBalanceProductIds,
                            $preloadedVariants
                        );
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

            $canViewCost = $this->canViewCost($request);
            $fifoService = app(\App\Services\FifoInventoryService::class);
            // Restore this voucher's own prior Subtraction FIFO layers BEFORE inspecting Addition batches
            // so Mixed same-SKU vouchers accurately report only external downstream consumption.
            $fifoService->rollbackAdjustmentSubtractions($adjustment);
            $oldAdditionEntries = $fifoService->inspectAdditionBatches($adjustment);

            // Build cost-preservation lookups from prior items (protects historical unit_cost when user lacks products.view_cost)
            $oldCostById = [];
            $oldCostQueues = [];
            $oldCostAnyQueues = [];
            foreach ($adjustment->items as $oldItem) {
                $oldCostById[$oldItem->id] = $oldItem;
                $oldPid = $oldItem->product_id;
                if (!$oldPid && !empty($oldItem->product_code)) {
                    $p = $preloadedProducts->first(fn($prod) => $prod->code === $oldItem->product_code || $prod->sku === $oldItem->product_code);
                    $oldPid = $p?->id;
                }
                $oldNormType = $this->normalizeItemType($oldItem->type, $adjustment->type);
                $qKey = ($oldPid ?: '0') . '_' . ($oldItem->product_variant_id ?: '0') . '_' . $oldNormType;
                $anyKey = ($oldPid ?: '0') . '_' . ($oldItem->product_variant_id ?: '0');
                $oldCostQueues[$qKey][] = $oldItem;
                $oldCostAnyQueues[$anyKey][] = $oldItem;
            }

            // Build FIFO addition queues for in-place batch matching
            $oldAdditionByItemId = [];
            $oldAdditionQueues = [];
            foreach ($oldAdditionEntries as $entry) {
                $oldAdditionByItemId[$entry['item']->id] = $entry;
                $ePid = $entry['item']->product_id ?: 0;
                $eVid = $entry['item']->product_variant_id ?: 0;
                $oldAdditionQueues["{$ePid}_{$eVid}"][] = $entry;
            }

            // Prepare and classify all incoming items + verify consumed Addition batch invariants UPFRONT
            $netQty = 0.0;
            $grossQty = 0.0;
            $hasAddition = false;
            $hasSubtraction = false;
            $preparedUpdateItems = [];
            $prelimAdjType = $validated['type'] ?? $adjustment->type;

            foreach ($validated['items'] as $idx => $itemData) {
                $resolvedProductId = $itemData['product_id'] ?? null;
                if (!$resolvedProductId && !empty($itemData['product_code'])) {
                    $prod = $preloadedProducts->first(fn($p) => $p->code === $itemData['product_code'] || $p->sku === $itemData['product_code']);
                    $resolvedProductId = $prod?->id;
                }
                $resolvedVariantId = $itemData['product_variant_id'] ?? null;
                $prod = $resolvedProductId ? ($preloadedProducts->get($resolvedProductId) ?? Product::find($resolvedProductId)) : null;
                $conversionRate = $this->resolveItemConversionRate($itemData, $prod, $resolvedVariantId);
                $quantity = (float)$itemData['quantity'];
                $baseQty = $this->computeAuthoritativeBaseQuantity($quantity, $conversionRate);
                $itemType = $this->normalizeItemType($itemData['type'] ?? null, $prelimAdjType);

                $grossQty += $baseQty;
                if ($itemType === 'Subtraction') {
                    $hasSubtraction = true;
                    $netQty -= $baseQty;
                } else {
                    $hasAddition = true;
                    $netQty += $baseQty;
                }

                // Match old item for historical cost preservation if user lacks view_cost permission
                $incomingItemId = !empty($itemData['id']) ? (int)$itemData['id'] : null;
                $matchedOldItem = null;
                if ($incomingItemId && isset($oldCostById[$incomingItemId])) {
                    $matchedOldItem = $oldCostById[$incomingItemId];
                } else {
                    $qKey = ($resolvedProductId ?: '0') . '_' . ($resolvedVariantId ?: '0') . '_' . $itemType;
                    $anyKey = ($resolvedProductId ?: '0') . '_' . ($resolvedVariantId ?: '0');
                    if (!empty($oldCostQueues[$qKey])) {
                        $matchedOldItem = array_shift($oldCostQueues[$qKey]);
                    } elseif (!empty($oldCostAnyQueues[$anyKey])) {
                        $matchedOldItem = array_shift($oldCostAnyQueues[$anyKey]);
                    }
                }

                $unitCost = (float)($itemData['unit_cost'] ?? 0);
                if (!$canViewCost && $matchedOldItem) {
                    $preservedBase = (float)$matchedOldItem->base_unit_cost;
                    if ($preservedBase <= 0.0001 && (float)$matchedOldItem->unit_cost > 0.0001) {
                        $oldRate = (float)($matchedOldItem->conversion_rate ?: 1.0);
                        $preservedBase = $oldRate > 0 ? round((float)$matchedOldItem->unit_cost / $oldRate, 8) : round((float)$matchedOldItem->unit_cost, 8);
                    }
                    $baseCost = max(0.0, $preservedBase);
                    $unitCost = $conversionRate > 0 ? round($baseCost * $conversionRate, 4) : $baseCost;
                } elseif (!$canViewCost) {
                    $catalogCost = 0.0;
                    if ($resolvedProductId) {
                        $varObj = ($resolvedVariantId && $preloadedVariants->has($resolvedVariantId))
                            ? $preloadedVariants->get($resolvedVariantId)
                            : ($resolvedVariantId ? ProductVariant::find($resolvedVariantId) : null);
                        $catalogCost = (float)($varObj?->cost_price ?: ($varObj?->purchase_price ?: ($prod?->cost_price ?: ($prod?->purchase_price ?: 0.0))));
                    }
                    if ($catalogCost <= 0.0001) {
                        $msg = 'Cannot add unpriced items or modify item costs without products.view_cost permission.';
                        throw new \Illuminate\Validation\ValidationException(
                            validator: \Illuminate\Support\Facades\Validator::make([], []),
                            response: response()->json([
                                'status' => 'error',
                                'message' => $msg,
                                'errors' => ['items' => [$msg]],
                            ], 422)
                        );
                    }
                    $baseCost = $catalogCost;
                    $unitCost = $conversionRate > 0 ? round($catalogCost * $conversionRate, 4) : $catalogCost;
                } elseif ($unitCost <= 0.0001) {
                    $fallbackCost = 0.0;
                    if ($resolvedProductId) {
                        $varObj = ($resolvedVariantId && $preloadedVariants->has($resolvedVariantId))
                            ? $preloadedVariants->get($resolvedVariantId)
                            : ($resolvedVariantId ? ProductVariant::find($resolvedVariantId) : null);
                        $fallbackCost = (float)($varObj?->cost_price ?: ($varObj?->purchase_price ?: ($prod?->cost_price ?: ($prod?->purchase_price ?: 0.0))));
                    }
                    if ($fallbackCost > 0) {
                        $baseCost = $fallbackCost;
                        $unitCost = $conversionRate > 0 ? round($fallbackCost * $conversionRate, 4) : $fallbackCost;
                    } else {
                        $baseCost = 0.0;
                    }
                } else {
                    $baseCost = $conversionRate > 0 ? round($unitCost / $conversionRate, 8) : round($unitCost, 8);
                }

                $preparedUpdateItems[] = [
                    'raw' => $itemData,
                    'incoming_item_id' => $incomingItemId,
                    'resolved_product_id' => $resolvedProductId,
                    'resolved_variant_id' => $resolvedVariantId,
                    'product' => $prod,
                    'variant_name' => $this->resolveVariantDisplayName($itemData['variant_name'] ?? null, $resolvedVariantId, $preloadedVariants),
                    'conversion_rate' => $conversionRate,
                    'quantity' => $quantity,
                    'base_qty' => $baseQty,
                    'item_type' => $itemType,
                    'unit_cost' => $unitCost,
                    'base_cost' => $baseCost,
                    'matched_addition_entry' => null,
                ];
            }

            // Sort Additions before Subtractions so updated batches are available to Subtractions in the same voucher
            usort($preparedUpdateItems, function ($a, $b) {
                if ($a['item_type'] === $b['item_type']) return 0;
                return $a['item_type'] === 'Addition' ? -1 : 1;
            });

            // Claim old Addition batches onto incoming Addition items and validate FIFO invariants
            $claimedOldItemIds = [];
            $retainedBatchIds = [];
            foreach ($preparedUpdateItems as &$prepItem) {
                if ($prepItem['item_type'] !== 'Addition' || !$prepItem['resolved_product_id']) {
                    continue;
                }
                $entry = null;
                $incId = $prepItem['incoming_item_id'];
                if ($incId && isset($oldAdditionByItemId[$incId]) && !isset($claimedOldItemIds[$incId])) {
                    $cand = $oldAdditionByItemId[$incId];
                    if ((int)$cand['item']->product_id === (int)$prepItem['resolved_product_id']
                        && ($cand['item']->product_variant_id ? (int)$cand['item']->product_variant_id : null) === ($prepItem['resolved_variant_id'] ? (int)$prepItem['resolved_variant_id'] : null)) {
                        $entry = $cand;
                    }
                }
                if (!$entry) {
                    $qKey = ($prepItem['resolved_product_id'] ?: '0') . '_' . ($prepItem['resolved_variant_id'] ?: '0');
                    if (!empty($oldAdditionQueues[$qKey])) {
                        while (!empty($oldAdditionQueues[$qKey])) {
                            $cand = array_shift($oldAdditionQueues[$qKey]);
                            if (!isset($claimedOldItemIds[$cand['item']->id])) {
                                $entry = $cand;
                                break;
                            }
                        }
                    }
                }

                if ($entry) {
                    $claimedOldItemIds[$entry['item']->id] = true;
                    $batch = $entry['batch'];
                    $consumed = (float)$entry['consumed'];
                    $hasLayers = (bool)$entry['has_cost_layers'];

                    if ($batch && ($consumed > 0.0001 || $hasLayers)) {
                        $pName = $prepItem['raw']['product_name'] ?? $entry['item']->product_name ?? 'Product';
                        if (!$isSameWarehouse) {
                            $msg = "FIFO စာရင်းထိန်းချုပ်မှုအရ ဂိုဒေါင်ပြောင်း၍မရပါ: '{$pName}' ၏ Addition Batch မှ {$consumed} ခုကို ရောင်းချ/အသုံးပြုပြီးဖြစ်ပါသည်။";
                            throw new \Illuminate\Validation\ValidationException(
                                validator: \Illuminate\Support\Facades\Validator::make([], []),
                                response: response()->json(['status' => 'error', 'message' => $msg, 'errors' => ['warehouse' => [$msg]]], 422)
                            );
                        }
                        if ($prepItem['base_qty'] < $consumed - 0.0001) {
                            $msg = "FIFO စာရင်းထိန်းချုပ်မှုအရ လျှော့ချ၍မရပါ: '{$pName}' မှ {$consumed} ခုကို ရောင်းချ/အသုံးပြုပြီးဖြစ်သောကြောင့် အနည်းဆုံး {$consumed} ခု ရှိရန်လိုအပ်ပါသည်။";
                            throw new \Illuminate\Validation\ValidationException(
                                validator: \Illuminate\Support\Facades\Validator::make([], []),
                                response: response()->json(['status' => 'error', 'message' => $msg, 'errors' => ['quantity' => [$msg]]], 422)
                            );
                        }
                        if ($consumed > 0.0001 && abs($prepItem['base_cost'] - (float)$batch->unit_cost) > 0.01) {
                            $msg = "FIFO စာရင်းထိန်းချုပ်မှုအရ ယူနစ်စျေးပြောင်း၍မရပါ: '{$pName}' မှ {$consumed} ခုကို COGS စာရင်းသွင်းပြီးဖြစ်သောကြောင့် မူလတန်ဖိုးကို ပြင်ဆင်၍မရပါ။";
                            throw new \Illuminate\Validation\ValidationException(
                                validator: \Illuminate\Support\Facades\Validator::make([], []),
                                response: response()->json(['status' => 'error', 'message' => $msg, 'errors' => ['unit_cost' => [$msg]]], 422)
                            );
                        }
                    }

                    if ($batch && $isSameWarehouse) {
                        $prepItem['matched_addition_entry'] = $entry;
                        $retainedBatchIds[] = $batch->id;
                    }
                }
            }
            unset($prepItem);

            // Any unclaimed consumed Addition batch means the user deleted or flipped that item -> reject cleanly with 422
            foreach ($oldAdditionEntries as $entry) {
                if (isset($claimedOldItemIds[$entry['item']->id])) {
                    continue;
                }
                if ($entry['batch'] && ((float)$entry['consumed'] > 0.0001 || (bool)$entry['has_cost_layers'])) {
                    $pName = $entry['item']->product_name ?: $entry['item']->product_code;
                    $msg = "FIFO စာရင်းထိန်းချုပ်မှုအရ ဖယ်ရှား၍မရပါ: '{$pName}' ၏ Addition Batch မှ {$entry['consumed']} ခုကို ရောင်းချ/အသုံးပြုပြီးဖြစ်သောကြောင့် ဖယ်ရှားပါက COGS ပျက်စီးနိုင်ပါသည်။";
                    throw new \Illuminate\Validation\ValidationException(
                        validator: \Illuminate\Support\Facades\Validator::make([], []),
                        response: response()->json(['status' => 'error', 'message' => $msg, 'errors' => ['items' => [$msg]]], 422)
                    );
                }
            }

            // 1. Rollback previous items' stock balances (sort Subtraction first so +stock happens before -stock)
            $dirtyBalances = [];
            $dirtyProducts = [];
            $dirtyVariants = [];
            $sortedOldItems = $adjustment->items->sortBy(function ($item) use ($adjustment) {
                $t = strtolower(trim((string)($item->type ?: $adjustment->type)));
                return $t === 'subtraction' ? 0 : 1;
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

            // 1b. Delete ONLY unretained unconsumed Addition batches (Subtraction layers were already rolled back above)
            InventoryBatch::where('warehouse_id', $oldWarehouseId)
                ->where('source_type', 'adjustment_addition')
                ->where(function ($q) use ($adjustment) {
                    $q->where('source_reference', $adjustment->reference_no)
                      ->orWhere('source_reference', 'like', "{$adjustment->reference_no}#%");
                })
                ->when(!empty($retainedBatchIds), fn($q) => $q->whereNotIn('id', $retainedBatchIds))
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
                    $itemType = $this->normalizeItemType($item['type'] ?? null, $validated['type'] ?? $adjustment->type);
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
                    $baseQty = $this->computeAuthoritativeBaseQuantity($qty, $cRate);

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
                        $prod = $preloadedProducts->get($pid) ?? Product::find($pid);
                        $curBal = $this->resolveCurrentStockBalance(
                            (int)$newWarehouseId,
                            (int)$pid,
                            $vid ? (int)$vid : null,
                            $prod,
                            $preloadedBalances,
                            $existingBalanceProductIds,
                            $preloadedVariants
                        );

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

            $adjType = $validated['type'] ?? $adjustment->type;
            if ($hasAddition && $hasSubtraction) {
                $adjType = 'Mixed';
            } elseif ($hasAddition) {
                $adjType = 'Addition';
            } elseif ($hasSubtraction) {
                $adjType = 'Subtraction';
            }

            $rawDateInput = trim($validated['date']);
            if (!str_contains($rawDateInput, ':') && !str_contains($rawDateInput, 'T') && $adjustment->date && str_contains((string)$adjustment->date, ':')) {
                $parts = preg_split('/[T ]/', (string)$adjustment->date);
                $timePart = end($parts);
                if (is_string($timePart) && str_contains($timePart, ':')) {
                    $rawDateInput .= ' ' . $timePart;
                }
            }
            $txDate = app(\App\Services\PurchaseAccountingService::class)->normalizeTransactionDate($rawDateInput, (string)$adjustment->date);

            $hasReasonInput = array_key_exists('reason_code', $validated);
            $effectiveReasonInput = $hasReasonInput ? $validated['reason_code'] : $adjustment->reason_code;
            $explicitPnlInput = array_key_exists('affects_pnl', $validated) && $validated['affects_pnl'] !== null
                ? (bool)$validated['affects_pnl']
                : null;
            $incomingReasonNorm = $hasReasonInput && $validated['reason_code'] !== null && trim((string)$validated['reason_code']) !== ''
                ? AdjustmentReasonCatalog::normalize((string)$validated['reason_code'])
                : ($hasReasonInput ? null : $adjustment->reason_code);
            $existingReasonNorm = $adjustment->reason_code !== null && trim((string)$adjustment->reason_code) !== ''
                ? AdjustmentReasonCatalog::normalize((string)$adjustment->reason_code)
                : null;
            $reasonChanged = $hasReasonInput && strcasecmp((string)($incomingReasonNorm ?? ''), (string)($existingReasonNorm ?? '')) !== 0;
            $fallbackPnl = (!$reasonChanged && $adjustment->affects_pnl !== null)
                ? (bool)$adjustment->affects_pnl
                : null;
            $existingPnlForRbac = $adjustment->affects_pnl !== null
                ? (bool)$adjustment->affects_pnl
                : null;
            $authUser = User::resolveFromRequest($request) ?? $request->user() ?? auth()->user();

            $resolvedClass = $this->resolveValidatedClassification(
                $effectiveReasonInput,
                $adjType,
                $explicitPnlInput,
                $authUser,
                $existingPnlForRbac,
                $fallbackPnl
            );
            if (isset($resolvedClass['error'])) {
                throw new \Illuminate\Validation\ValidationException(
                    validator: \Illuminate\Support\Facades\Validator::make([], []),
                    response: response()->json([
                        'status' => 'error',
                        'message' => $resolvedClass['error'],
                    ], 422)
                );
            }
            $classification = $resolvedClass['classification'];
            $storedReasonCode = $resolvedClass['stored_reason_code'];

            $updateData = [
                'warehouse_id' => $newWarehouseId,
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $txDate,
                'total_products' => count($validated['items']),
                'total_quantity' => $netQty,
                'net_quantity' => $netQty,
                'gross_quantity' => $grossQty,
                'type' => $adjType,
                'reason_code' => $storedReasonCode,
                'accounting_category' => $classification['accounting_category'],
                'affects_pnl' => $classification['affects_pnl'],
                'notes' => array_key_exists('notes', $validated)
                    ? $validated['notes']
                    : $adjustment->notes,
            ];
            $authUser = User::resolveFromRequest($request) ?? $request->user() ?? auth()->user();
            if (!empty($validated['reference_no'])) {
                $updateData['reference_no'] = $validated['reference_no'];
            }
            if ($authUser && $authUser->exists) {
                if (empty($adjustment->user_id)) {
                    $updateData['user_id'] = $authUser->id;
                }
                if (empty($adjustment->user_name) && $authUser->name) {
                    $updateData['user_name'] = $authUser->name;
                }
                if (empty($adjustment->created_by) && $authUser->name) {
                    $updateData['created_by'] = $authUser->name;
                }
                if (empty($adjustment->user_role)) {
                    $updateData['user_role'] = $authUser->roleModel?->name ?? $authUser->role ?? 'Super Admin';
                }
            } else {
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
            }

            $adjustment->update($updateData);

            $effectiveUserId = $authUser?->id ?? ($validated['user_id'] ?? $adjustment->user_id ?? auth()->id());

            $dirtyBalances = [];
            $dirtyProducts = [];
            $dirtyVariants = [];
            $pendingMovements = [];
            $totalNetValue = 0.0;

            foreach ($preparedUpdateItems as $prepItem) {
                $itemData = $prepItem['raw'];
                $resolvedProductId = $prepItem['resolved_product_id'];
                $resolvedVariantId = $prepItem['resolved_variant_id'];
                $conversionRate = $prepItem['conversion_rate'];
                $quantity = $prepItem['quantity'];
                $unitCost = $prepItem['unit_cost'];
                $baseQty = $prepItem['base_qty'];
                $baseCost = $prepItem['base_cost'];
                $variantName = $prepItem['variant_name'];
                $itemType = $prepItem['item_type'];

                $subtotal = round($quantity * $unitCost, 2);

                $finalSubtotal = $subtotal;
                $finalBaseCost = $baseCost;
                $finalUnitCost = $unitCost;
                $recordedLayers = [];

                if ($resolvedProductId && $itemType === 'Subtraction') {
                    $actualLossCost = $fifoService->depleteForAdjustment(
                        warehouseId: (int)$newWarehouseId,
                        productId: (int)$resolvedProductId,
                        variantId: $resolvedVariantId,
                        baseQty: (float)$baseQty,
                        referenceNo: (string)$adjustment->reference_no,
                        fallbackCost: (float)$baseCost,
                        recordedLayers: $recordedLayers
                    );
                    $finalSubtotal = $actualLossCost;
                    $finalBaseCost = $baseQty > 0 ? round($actualLossCost / $baseQty, 8) : $baseCost;
                    $finalUnitCost = $quantity > 0
                        ? round($actualLossCost / $quantity, 4)
                        : round($finalBaseCost * ($conversionRate > 0 ? $conversionRate : 1.0), 4);
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
                if (!empty($recordedLayers)) {
                    $fifoService->persistAdjustmentCostLayers($adjustment->id, $adjItem->id, $recordedLayers);
                }
                $totalNetValue += $itemType === 'Subtraction' ? -$finalSubtotal : $finalSubtotal;

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
                    $pendingMovements,
                    (string)$adjustment->date
                );

                if ($resolvedProductId && $itemType === 'Addition') {
                    $matchedEntry = $prepItem['matched_addition_entry'];
                    if ($matchedEntry && !empty($matchedEntry['batch'])) {
                        $fifoService->updateAdditionBatchInPlace(
                            batch: $matchedEntry['batch'],
                            consumedQty: (float)$matchedEntry['consumed'],
                            newBaseQty: (float)$baseQty,
                            newBaseCost: (float)$finalBaseCost,
                            referenceNo: (string)$adjustment->reference_no,
                            newAdjItemId: (int)$adjItem->id,
                            receivedDate: (string)$adjustment->date,
                            notes: $validated['notes'] ?? 'Stock adjustment addition'
                        );
                    } else {
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
            }

            $adjustment->update(['total_value' => round($totalNetValue, 2)]);

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
                'data' => $this->prepareAdjustmentResponse($adjustment, true, $request),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("AdjustmentController update error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $adjustment = StockAdjustment::with('items')->find($id);
        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }
        $this->accounting()->assertOutletAccess(
            $request,
            $adjustment->warehouse_id ? (int)$adjustment->warehouse_id : null,
            $adjustment->warehouse_name
        );

        DB::beginTransaction();
        try {
            $warehouseId = $this->resolveWarehouseId($adjustment->warehouse_id, $adjustment->warehouse_name);

            // Pre-fetch and lock products & variants deterministically to prevent deadlocks and N+1 queries
            $rawPids = [];
            $rawCodes = [];
            $rawVids = [];
            foreach ($adjustment->items as $item) {
                if (!empty($item->product_id)) $rawPids[] = (int)$item->product_id;
                if (!empty($item->product_code)) $rawCodes[] = (string)$item->product_code;
                if (!empty($item->product_variant_id)) $rawVids[] = (int)$item->product_variant_id;
            }
            $sortedPids = array_values(array_unique($rawPids));
            sort($sortedPids);

            $preloadedProducts = Product::with(['variants'])
                ->whereIn('id', $sortedPids)
                ->when(!empty($rawCodes), fn($q) => $q->orWhereIn('code', $rawCodes)->orWhereIn('sku', $rawCodes))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
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
                $prod = $this->resolveProductForItem($item, $preloadedProducts);
                $pid = $prod?->id ?? (!empty($item->product_id) ? (int)$item->product_id : null);
                $vid = $item->product_variant_id ? (int)$item->product_variant_id : null;
                $pidKey = $pid ? (string)$pid : ('p0_' . md5((string)($item->product_code ?? '')));
                $key = $pidKey . '_' . ($vid ? "var_{$vid}" : 'single');
                $itemType = strtolower(trim((string)($item->type ?: $adjustment->type)));
                $baseQty = (float)$item->base_quantity > 0
                    ? (float)$item->base_quantity
                    : $this->computeAuthoritativeBaseQuantity((float)$item->quantity, (float)($item->conversion_rate ?: 1.0));

                if (!isset($reversalNetByItem[$key])) {
                    $reversalNetByItem[$key] = [
                        'product_id' => $pid,
                        'variant_id' => $vid,
                        'product' => $prod,
                        'product_name' => $item->product_name ?? 'Product',
                        'needed_reduction' => 0.0,
                    ];
                }
                // Rolling back an Addition reduces warehouse stock
                // Rolling back a Subtraction adds warehouse stock
                if ($itemType === 'addition') {
                    $reversalNetByItem[$key]['needed_reduction'] += $baseQty;
                } elseif ($itemType === 'subtraction') {
                    $reversalNetByItem[$key]['needed_reduction'] -= $baseQty;
                }
            }

            $outletObj = Outlet::find($warehouseId);
            $outletName = $outletObj ? $outletObj->name : "Warehouse #$warehouseId";

            foreach ($reversalNetByItem as $rev) {
                $needed = $rev['needed_reduction'];
                $pid = $rev['product_id'];
                if ($needed > 0.0001 && $pid) {
                    $vid = $rev['variant_id'];
                    $prod = $rev['product'] ?? $preloadedProducts->get($pid) ?? Product::find($pid);
                    $curBal = $this->resolveCurrentStockBalance(
                        (int)$warehouseId,
                        (int)$pid,
                        $vid ? (int)$vid : null,
                        $prod,
                        $preloadedBalances,
                        $existingBalanceProductIds,
                        $preloadedVariants
                    );

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
                $t = strtolower(trim((string)($item->type ?: $adjustment->type)));
                return $t === 'subtraction' ? 0 : 1;
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

            // FIFO Subledger check & rollback FIRST: protects historical COGS if an Addition batch was already consumed
            $fifoService = app(\App\Services\FifoInventoryService::class);
            $fifoService->rollbackAdjustmentFifo($adjustment);

            // Clean up any orphaned unconsumed addition batches generated by this adjustment
            InventoryBatch::where('warehouse_id', $warehouseId)
                ->where('source_type', 'adjustment_addition')
                ->where(function ($q) use ($adjustment) {
                    $q->where('source_reference', $adjustment->reference_no)
                      ->orWhere('source_reference', 'like', "{$adjustment->reference_no}#%");
                })
                ->delete();

            // Clean up StockMovements after FIFO check passes
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Forbidden'], $e->getStatusCode());
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
        ?array &$pendingMovements = null,
        ?string $transactionDate = null
    ): void {
        $productId = $item->product_id;
        $variantId = $item->product_variant_id;
        $qty = (float)$item->quantity;
        $conversionRate = (float)($item->conversion_rate ?: 1.0);
        $baseQty = (float)($item->base_quantity ?: ($qty * $conversionRate));
        $effectiveQty = $baseQty > 0 ? $baseQty : $qty;
        $type = $this->normalizeItemType($item->type, $item->adjustment?->type ?? 'Addition');

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
        $isAddition = (strtolower(trim((string)$type)) === 'addition');
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
            $normalizedVid = ($variantId && $variantId > 0) ? (int)$variantId : null;

            if (!$hasExistingBalances && ($normalizedVid !== null || $prod->has_variants)) {
                $variantsToSeed = $prod->relationLoaded('variants') && $prod->variants->isNotEmpty()
                    ? $prod->variants
                    : ProductVariant::where('product_id', $productId)->get();
                if ($variantsToSeed->isNotEmpty()) {
                    foreach ($variantsToSeed as $seedVar) {
                        $seedKey = $warehouseId . '_' . $productId . '_' . $seedVar->id;
                        if ($preloadedBalances && $preloadedBalances->has($seedKey)) {
                            continue;
                        }
                        $seedQty = $this->resolveUnseededStockBalance($prod, (int)$seedVar->id, $preloadedVariants);
                        $seededBal = StockBalance::firstOrCreate(
                            [
                                'warehouse_id' => $warehouseId,
                                'product_id' => $productId,
                                'product_variant_id' => (int)$seedVar->id,
                            ],
                            ['quantity' => $seedQty]
                        );
                        if ($preloadedBalances) {
                            $preloadedBalances->put($seedKey, $seededBal);
                        }
                        if ($preloadedVariants && !$preloadedVariants->has($seedVar->id)) {
                            $preloadedVariants->put($seedVar->id, $seedVar);
                        }
                    }
                    $existingBalanceProductIds[$productId] = true;
                    $balance = $preloadedBalances ? $preloadedBalances->get($balKey) : null;
                }
            }

            if (!$balance) {
                $initial = 0.0;
                if (!$hasExistingBalances) {
                    $initial = $this->resolveUnseededStockBalance($prod, $normalizedVid, $preloadedVariants);
                } elseif ($normalizedVid !== null && !StockBalance::where('product_id', $productId)->where('product_variant_id', $normalizedVid)->exists()) {
                    $initial = $this->resolveUnseededStockBalance($prod, $normalizedVid, $preloadedVariants);
                }
                $balance = StockBalance::create([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'product_variant_id' => $normalizedVid,
                    'quantity' => $initial,
                ]);
                if ($preloadedBalances) {
                    $preloadedBalances->put($balKey, $balance);
                }
                if ($existingBalanceProductIds !== null) {
                    $existingBalanceProductIds[$productId] = true;
                }
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
            $movementCreatedBy = $resolvedUserId ?: (request()?->user()?->id ?? auth()->id());
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

            $movementCreatedAt = now();
            if (!empty($transactionDate)) {
                try {
                    $movementCreatedAt = \Carbon\Carbon::parse($transactionDate)->setTimezone(config('app.timezone'));
                } catch (\Throwable $e) {
                    $movementCreatedAt = now();
                }
            }

            static $hasMovementUnitCost = null;
            if ($hasMovementUnitCost === null) {
                $hasMovementUnitCost = \Illuminate\Support\Facades\Schema::hasColumn('stock_movements', 'unit_cost');
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
                'created_at' => $movementCreatedAt,
            ];
            if ($hasMovementUnitCost) {
                if ($item->base_unit_cost !== null) {
                    $baseUnitCost = (float)$item->base_unit_cost;
                } elseif ($item->unit_cost !== null) {
                    $cRate = (float)($item->conversion_rate ?: 1.0);
                    $baseUnitCost = (float)$item->unit_cost / ($cRate > 0.0001 ? $cRate : 1.0);
                } else {
                    $baseUnitCost = (float)($prod->cost_price ?? $prod->purchase_price ?? 0);
                }
                $movementData['unit_cost'] = round(max(0.0, $baseUnitCost), 4);
            }

            if ($pendingMovements !== null) {
                $pendingMovements[] = $movementData;
            } else {
                StockMovement::create($movementData);
            }
        }
    }

    /**
     * Authoritative server-side base quantity calculation from display quantity and conversion rate.
     */
    private function computeAuthoritativeBaseQuantity(float $quantity, float $conversionRate): float
    {
        $rate = $conversionRate > 0.0001 ? $conversionRate : 1.0;
        return round($quantity * $rate, 4);
    }

    /**
     * Validates variant ownership and enforces variant selection for variable products.
     */
    private function validateAdjustmentItemsConsistency(
        array $items,
        $preloadedProducts,
        $preloadedVariants
    ): ?string {
        foreach ($items as $item) {
            $pid = $item['product_id'] ?? null;
            $prod = $pid ? $preloadedProducts->get((int)$pid) : null;
            if (!$prod && !empty($item['product_code'])) {
                $code = (string)$item['product_code'];
                $prod = $preloadedProducts->first(fn($p) => $p->code === $code || $p->sku === $code);
            }
            $vid = !empty($item['product_variant_id']) ? (int)$item['product_variant_id'] : null;

            if ($vid !== null) {
                $varObj = $preloadedVariants->get($vid) ?? ProductVariant::find($vid);
                if (!$varObj || ($prod && (int)$varObj->product_id !== (int)$prod->id)) {
                    $pName = $prod?->name ?? ($item['product_name'] ?? 'Product');
                    return "Variant ID #{$vid} does not belong to product '{$pName}'.";
                }
            } elseif ($prod && $prod->has_variants) {
                $hasAnyVariants = $prod->relationLoaded('variants')
                    ? $prod->variants->isNotEmpty()
                    : $prod->variants()->exists();
                if ($hasAnyVariants) {
                    return "Product '{$prod->name}' uses variants; please select a specific variation.";
                }
            }
        }

        return null;
    }

    /**
     * Resolves and verifies unit conversion rate against authoritative ProductUnitLinks or base unit.
     */
    private function resolveItemConversionRate(
        array $itemData,
        ?Product $product,
        ?int $variantId = null
    ): float {
        $cRate = isset($itemData['conversion_rate']) ? (float)$itemData['conversion_rate'] : 0.0;
        $unitId = isset($itemData['unit_id']) && $itemData['unit_id'] !== '' ? (int)$itemData['unit_id'] : null;
        $unitName = strtolower(trim((string)($itemData['unit_name'] ?? '')));

        if ($product) {
            // Check configured active ProductUnitLinks first so stale client conversion_rates cannot corrupt multi-unit ledger math
            try {
                $links = $product->relationLoaded('unitLinks')
                    ? $product->unitLinks
                    : $product->unitLinks()->get();

                $matchingLink = $links
                    ->filter(function ($l) use ($variantId, $unitId, $unitName) {
                        if (!$l->is_active || (float)$l->conversion_rate <= 0) {
                            return false;
                        }
                        if ($variantId === null && $l->product_variant_id !== null) {
                            return false;
                        }
                        if ($variantId !== null && $l->product_variant_id !== null && (int)$l->product_variant_id !== (int)$variantId) {
                            return false;
                        }
                        if ($unitId !== null && $l->unit_id !== null && (int)$l->unit_id === $unitId) {
                            return true;
                        }
                        if ($unitName !== '' && strtolower(trim((string)$l->unit_name)) === $unitName) {
                            return true;
                        }
                        return false;
                    })
                    ->sortBy(function ($l) use ($variantId) {
                        $variantRank = ($variantId !== null && (int)$l->product_variant_id === (int)$variantId) ? 0 : 1;
                        $typeRank = strtolower((string)$l->unit_type) === 'sale' ? 0 : 1;
                        return ($variantRank * 10) + $typeRank;
                    })
                    ->first();

                if ($matchingLink) {
                    return (float)$matchingLink->conversion_rate;
                }
            } catch (\Throwable $e) {
                // Fall back safely below if unitLinks cannot be loaded
            }

            // Explicit base unit check
            if ($unitName !== '' && $unitName === strtolower(trim((string)$product->unit_name))) {
                return 1.0;
            }
            if ($unitId !== null && $product->base_unit_id !== null && $unitId === (int)$product->base_unit_id && $unitName === '') {
                return 1.0;
            }

            if ($cRate <= 0.0001) {
                try {
                    $conversionService = app(\App\Services\ProductUnitConversionService::class);
                    $resolved = $conversionService->resolve(
                        product: $product,
                        type: 'sale',
                        unitId: $unitId,
                        unitName: $itemData['unit_name'] ?? null,
                        variantId: $variantId
                    );
                    if (!empty($resolved['conversion_rate']) && (float)$resolved['conversion_rate'] > 0) {
                        return (float)$resolved['conversion_rate'];
                    }
                } catch (\Throwable $e) {
                    // fallback below
                }
            }
        }

        return $cRate > 0.0001 ? $cRate : 1.0;
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
     * Zero-cache mode compatibility hook: list and report endpoints always query live DB.
     */
    protected function clearAdjustmentRelatedCaches(): void
    {
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

