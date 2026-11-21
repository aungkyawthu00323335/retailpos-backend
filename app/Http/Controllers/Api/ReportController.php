<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Supplier;
use Carbon\Carbon;
use App\Models\StockBalance;
use App\Models\Outlet;
use App\Models\User;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\StockReportResource;
use App\Services\InventoryReconciliationService;
use App\Services\PurchaseAccountingService;
use App\Support\AdjustmentReasonCatalog;
use App\Support\ListCursorHelper;
class ReportController extends Controller
{
    /** @var array<string, ?int> */
    private array $warehouseIdCache = [];

    private function accounting(): PurchaseAccountingService
    {
        return app(PurchaseAccountingService::class);
    }

    private function noStoreJson(array $payload, int $status = 200)
    {
        return response()->json($payload, $status)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    private function resolveReportPerPage(Request $request, int $default = 50, int $max = 10000): int
    {
        $perPage = (int)($request->query('per_page') ?? $request->query('limit') ?? $default);
        if ($perPage < 1) {
            return $default;
        }
        return min($perPage, $max);
    }

    private function extractAllowedOutletIds(Request $request): array
    {
        return $this->accounting()->extractAllowedOutletIds($request);
    }

    private function applyActiveDocumentFilter($query, string $statusCol = 'status'): void
    {
        $query->where(function ($q) use ($statusCol) {
            $q->whereNull($statusCol)
              ->orWhereNotIn($statusCol, [
                  'Voided', 'voided', 'VOIDED',
                  'Cancelled', 'cancelled', 'CANCELLED',
                  'Canceled', 'canceled', 'CANCELED',
                  'Draft', 'draft', 'DRAFT',
                  'Pending', 'pending', 'PENDING',
                  'Ordered', 'ordered', 'ORDERED',
              ]);
        });
    }

    private function effectiveAdjustmentTypeSql(): string
    {
        return "LOWER(COALESCE(NULLIF(TRIM(stock_adjustment_items.type), ''), NULLIF(TRIM(stock_adjustments.type), ''), 'Addition'))";
    }

    private function excludeOpeningStockPurchases($query, string $column = 'payment_type'): void
    {
        $this->accounting()->excludeOpeningStockPurchases($query, $column);
    }

    private function applyOperatingAdjustmentScope($query, bool $operatingOnly = true)
    {
        $nonPnlReasons = AdjustmentReasonCatalog::nonPnlReasonCodesLower();
        $placeholders = implode(', ', array_fill(0, count($nonPnlReasons), '?'));

        return $query->where(function ($q) use ($operatingOnly, $nonPnlReasons, $placeholders) {
            if ($operatingOnly) {
                $q->where('stock_adjustments.affects_pnl', true)
                  ->orWhere(function ($sub) use ($nonPnlReasons, $placeholders) {
                      $sub->whereNull('stock_adjustments.affects_pnl')
                          ->where(function ($r) use ($nonPnlReasons, $placeholders) {
                              $r->whereNull('stock_adjustments.reason_code')
                                ->orWhereRaw("LOWER(TRIM(stock_adjustments.reason_code)) NOT IN ({$placeholders})", $nonPnlReasons);
                          });
                  });
            } else {
                $q->where('stock_adjustments.affects_pnl', false)
                  ->orWhere(function ($sub) use ($nonPnlReasons, $placeholders) {
                      $sub->whereNull('stock_adjustments.affects_pnl')
                          ->whereRaw("LOWER(TRIM(COALESCE(stock_adjustments.reason_code, ''))) IN ({$placeholders})", $nonPnlReasons);
                  });
            }
        });
    }

    private function resolveWarehouseIdParam(?string $warehouseParam): ?int
    {
        if (empty($warehouseParam) || $warehouseParam === 'All') {
            return null;
        }
        if (is_numeric($warehouseParam)) {
            return (int)$warehouseParam;
        }
        $key = trim($warehouseParam);
        if (array_key_exists($key, $this->warehouseIdCache)) {
            return $this->warehouseIdCache[$key];
        }
        $id = Outlet::where('name', $key)->value('id');
        return $this->warehouseIdCache[$key] = ($id !== null ? (int)$id : null);
    }

    /**
     * Zero or redact sensitive numeric cost keys in an associative array for cost-blind callers.
     */
    private function maskAssocNumericKeys(array $row, array $keys, mixed $replacement = 0.0): array
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = $replacement;
            }
        }
        return $row;
    }

    /**
     * Escape dangerous spreadsheet formula prefixes on exported CSV string cells.
     */
    private function escapeCsvCell($value): string
    {
        $str = (string)($value ?? '');
        if ($str === '') {
            return '';
        }
        $trimmed = ltrim($str);
        if ($trimmed !== '') {
            $first = $trimmed[0];
            if (in_array($first, ['=', '+', '@', "\t", "\r"], true)
                || ($first === '-' && !preg_match('/^-\d+(\.\d+)?$/', $trimmed))
            ) {
                return "'" . $str;
            }
        }
        return $str;
    }

    /**
     * Redact sensitive valuation & P&L cost figures from Inventory Reconciliation output.
     */
    private function maskReconciliationAuditData(array $data): array
    {
        $data['cost_masked'] = true;
        if (isset($data['valuation_summary']) && is_array($data['valuation_summary'])) {
            $data['valuation_summary'] = $this->maskAssocNumericKeys(
                $data['valuation_summary'],
                ['master_valuation', 'fifo_batch_valuation', 'effective_valuation', 'valuation_variance'],
                0.0
            );
        }
        if (isset($data['adjustment_accounting_summary']) && is_array($data['adjustment_accounting_summary'])) {
            $data['adjustment_accounting_summary'] = $this->maskAssocNumericKeys(
                $data['adjustment_accounting_summary'],
                ['operating_pnl_loss', 'operating_pnl_gain', 'net_pnl_impact', 'non_pnl_additions', 'non_pnl_subtractions', 'net_non_pnl_capital'],
                0.0
            );
        }
        if (isset($data['sku_discrepancies']) && is_array($data['sku_discrepancies'])) {
            foreach ($data['sku_discrepancies'] as $idx => $disc) {
                if (is_array($disc)) {
                    $data['sku_discrepancies'][$idx] = $this->maskAssocNumericKeys(
                        $disc,
                        [
                            'master_unit_cost',
                            'master_valuation',
                            'fifo_valuation',
                            'effective_valuation',
                            'valuation_variance',
                            'master_cost',
                            'fifo_value',
                            'master_value',
                            'cost_variance',
                        ],
                        null
                    );
                }
            }
        }
        if (isset($data['layer_alerts']) && is_array($data['layer_alerts'])) {
            foreach ($data['layer_alerts'] as $idx => $alert) {
                if (is_array($alert)) {
                    $data['layer_alerts'][$idx] = $this->maskAssocNumericKeys(
                        $alert,
                        ['item_subtotal', 'layer_total'],
                        null
                    );
                }
            }
        }
        return $data;
    }

    /**
     * Senior Accounting Subledger Audit & FIFO Valuation Reconciliation Endpoint
     */
    public function inventoryReconciliation(Request $request, InventoryReconciliationService $reconciliation)
    {
        $rawWarehouse = $request->query('warehouse_id')
            ?? $request->query('warehouse')
            ?? $request->query('warehouse_name');
        $warehouseId = $this->resolveWarehouseIdParam(is_scalar($rawWarehouse) ? (string)$rawWarehouse : null);
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        if (!empty($allowedOutlets) && $warehouseId !== null && !in_array($warehouseId, $allowedOutlets, true)) {
            return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
        }
        $perPage = $this->resolveReportPerPage($request, 50, 200);
        $canViewCost = $this->accounting()->canViewCost($request);
        $auditData = $reconciliation->audit($warehouseId, !empty($allowedOutlets) ? $allowedOutlets : null, $perPage);
        if (!$canViewCost) {
            $auditData = $this->maskReconciliationAuditData($auditData);
        }

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'data' => $auditData,
        ]);
    }

    /**
     * Aggregate per-product base-unit stock inflows & outflows over [from, to].
     * Used cleanly for both period movements and post-toDate historical rollback in Stock Ledger Bin Cards.
     */
    /**
     * Single-pass per-product base-unit stock inflows & outflows for both
     * the reporting window [fromDateTime, toDateTime] and post-cutoff rollback (toDateTime, now].
     */
    private function aggregateBinCardMovementsByProduct(
        string $fromDateTime,
        ?string $toDateTime,
        string $warehouse,
        bool $fromInclusive = true,
        array $allowedOutlets = [],
        ?array $productIds = null,
        bool $includePostWindow = false
    ): array {
        $emptyResult = [
            'purchases' => [], 'sale_returns' => [], 'transfers_in' => [], 'processing_in' => [], 'adjust_in' => [],
            'sales' => [], 'purchase_returns' => [], 'transfers_out' => [], 'processing_out' => [], 'adjust_out' => [],
        ];
        if ($includePostWindow) {
            $emptyCombined = ['period' => $emptyResult, 'post' => $emptyResult];
            if ($productIds !== null && empty($productIds)) {
                return $emptyCombined;
            }
            if ($productIds !== null && count($productIds) > 400) {
                $combined = $emptyCombined;
                foreach (array_chunk($productIds, 400) as $idChunk) {
                    $part = $this->aggregateBinCardMovementsByProduct($fromDateTime, $toDateTime, $warehouse, $fromInclusive, $allowedOutlets, $idChunk, true);
                    foreach (['period', 'post'] as $bucket) {
                        foreach ($part[$bucket] as $k => $map) {
                            foreach ($map as $pid => $val) {
                                $combined[$bucket][$k][$pid] = ($combined[$bucket][$k][$pid] ?? 0.0) + (float)$val;
                            }
                        }
                    }
                }
                return $combined;
            }
        } elseif ($productIds !== null) {
            if (empty($productIds)) {
                return $emptyResult;
            }
            if (count($productIds) > 400) {
                $acc = $emptyResult;
                foreach (array_chunk($productIds, 400) as $idChunk) {
                    $part = $this->aggregateBinCardMovementsByProduct($fromDateTime, $toDateTime, $warehouse, $fromInclusive, $allowedOutlets, $idChunk, false);
                    foreach ($part as $k => $map) {
                        foreach ($map as $pid => $val) {
                            $acc[$k][$pid] = ($acc[$k][$pid] ?? 0.0) + (float)$val;
                        }
                    }
                }
                return $acc;
            }
        }

        $applyWindow = function ($q, string $dateCol) use ($fromDateTime, $toDateTime, $fromInclusive, $includePostWindow) {
            if ($fromInclusive) {
                $q->where($dateCol, '>=', $fromDateTime);
            } else {
                $q->where($dateCol, '>', $fromDateTime);
            }
            if (!$includePostWindow && $toDateTime !== null) {
                $q->where($dateCol, '<=', $toDateTime);
            }
            return $q;
        };

        $filterWh = ($warehouse !== 'All' && $warehouse !== '');
        $filterProducts = $productIds !== null && !empty($productIds);
        $effAdjTypeSql = $this->effectiveAdjustmentTypeSql();
        $accounting = $this->accounting();

        $extractMaps = function ($rows, array &$periodMap, array &$postMap) use ($includePostWindow) {
            foreach ($rows as $row) {
                $pid = (int)$row->product_id;
                if ($includePostWindow) {
                    $pVal = (float)($row->period_qty ?? 0);
                    $qVal = (float)($row->post_qty ?? 0);
                    if ($pVal != 0.0) $periodMap[$pid] = $pVal;
                    if ($qVal != 0.0) $postMap[$pid] = $qVal;
                } else {
                    $periodMap[$pid] = (float)($row->qty ?? 0);
                }
            }
        };

        $period = $emptyResult;
        $post = $emptyResult;

        $purchQtySql = 'COALESCE(NULLIF(purchase_items.base_quantity, 0), purchase_items.quantity * COALESCE(NULLIF(purchase_items.conversion_rate, 0), 1))';
        $purchQ = DB::table('purchase_items')
            ->join('purchases', 'purchase_items.purchase_id', '=', 'purchases.id')
            ->whereIn('purchases.status', ['Received', 'Completed']);
        $applyWindow($purchQ, 'purchases.date');
        if (!empty($allowedOutlets)) $purchQ->whereIn('purchases.warehouse_id', $allowedOutlets);
        if ($filterWh) $purchQ->where('purchases.warehouse_name', $warehouse);
        if ($filterProducts) $purchQ->whereIn('purchase_items.product_id', $productIds);
        if ($includePostWindow) {
            $purchRows = $purchQ->selectRaw("purchase_items.product_id, SUM(CASE WHEN purchases.date <= ? THEN {$purchQtySql} ELSE 0 END) as period_qty, SUM(CASE WHEN purchases.date > ? THEN {$purchQtySql} ELSE 0 END) as post_qty", [$toDateTime, $toDateTime])
                ->groupBy('purchase_items.product_id')->get();
            $extractMaps($purchRows, $period['purchases'], $post['purchases']);
        } else {
            $period['purchases'] = $purchQ->select('purchase_items.product_id', DB::raw("SUM({$purchQtySql}) as qty"))
                ->groupBy('purchase_items.product_id')->pluck('qty', 'product_id')->all();
        }

        $saleQtySql = 'COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity * COALESCE(NULLIF(sale_items.conversion_rate, 0), 1))';
        $salesQ = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id');
        $this->applyActiveDocumentFilter($salesQ, 'sales.status');
        $applyWindow($salesQ, 'sales.date');
        if (!empty($allowedOutlets)) $salesQ->whereIn('sales.warehouse_id', $allowedOutlets);
        if ($filterWh) $salesQ->where('sales.warehouse_name', $warehouse);
        if ($filterProducts) $salesQ->whereIn('sale_items.product_id', $productIds);
        if ($includePostWindow) {
            $saleRows = $salesQ->selectRaw("sale_items.product_id, SUM(CASE WHEN sales.date <= ? THEN {$saleQtySql} ELSE 0 END) as period_qty, SUM(CASE WHEN sales.date > ? THEN {$saleQtySql} ELSE 0 END) as post_qty", [$toDateTime, $toDateTime])
                ->groupBy('sale_items.product_id')->get();
            $extractMaps($saleRows, $period['sales'], $post['sales']);
        } else {
            $period['sales'] = $salesQ->select('sale_items.product_id', DB::raw("SUM({$saleQtySql}) as qty"))
                ->groupBy('sale_items.product_id')->pluck('qty', 'product_id')->all();
        }

        $adjQtySql = 'COALESCE(NULLIF(stock_adjustment_items.base_quantity, 0), stock_adjustment_items.quantity * COALESCE(NULLIF(stock_adjustment_items.conversion_rate, 0), 1))';
        $adjBaseQ = DB::table('stock_adjustment_items')
            ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id');
        $applyWindow($adjBaseQ, 'stock_adjustments.date');
        if (!empty($allowedOutlets)) $adjBaseQ->whereIn('stock_adjustments.warehouse_id', $allowedOutlets);
        if ($filterWh) $adjBaseQ->where('stock_adjustments.warehouse_name', $warehouse);
        if ($filterProducts) $adjBaseQ->whereIn('stock_adjustment_items.product_id', $productIds);
        if ($includePostWindow) {
            $adjRows = $adjBaseQ->selectRaw("
                stock_adjustment_items.product_id,
                SUM(CASE WHEN stock_adjustments.date <= ? AND {$effAdjTypeSql} = 'addition' THEN {$adjQtySql} ELSE 0 END) as p_in,
                SUM(CASE WHEN stock_adjustments.date > ? AND {$effAdjTypeSql} = 'addition' THEN {$adjQtySql} ELSE 0 END) as post_in,
                SUM(CASE WHEN stock_adjustments.date <= ? AND {$effAdjTypeSql} = 'subtraction' THEN {$adjQtySql} ELSE 0 END) as p_out,
                SUM(CASE WHEN stock_adjustments.date > ? AND {$effAdjTypeSql} = 'subtraction' THEN {$adjQtySql} ELSE 0 END) as post_out
            ", [$toDateTime, $toDateTime, $toDateTime, $toDateTime])
                ->groupBy('stock_adjustment_items.product_id')->get();
            foreach ($adjRows as $ar) {
                $pid = (int)$ar->product_id;
                if ((float)$ar->p_in != 0.0) $period['adjust_in'][$pid] = (float)$ar->p_in;
                if ((float)$ar->post_in != 0.0) $post['adjust_in'][$pid] = (float)$ar->post_in;
                if ((float)$ar->p_out != 0.0) $period['adjust_out'][$pid] = (float)$ar->p_out;
                if ((float)$ar->post_out != 0.0) $post['adjust_out'][$pid] = (float)$ar->post_out;
            }
        } else {
            $adjRows = $adjBaseQ->selectRaw("
                stock_adjustment_items.product_id,
                SUM(CASE WHEN {$effAdjTypeSql} = 'addition' THEN {$adjQtySql} ELSE 0 END) as qty_in,
                SUM(CASE WHEN {$effAdjTypeSql} = 'subtraction' THEN {$adjQtySql} ELSE 0 END) as qty_out
            ")->groupBy('stock_adjustment_items.product_id')->get();
            foreach ($adjRows as $ar) {
                $pid = (int)$ar->product_id;
                if ((float)$ar->qty_in != 0.0) $period['adjust_in'][$pid] = (float)$ar->qty_in;
                if ((float)$ar->qty_out != 0.0) $period['adjust_out'][$pid] = (float)$ar->qty_out;
            }
        }

        $trfQtySql = 'COALESCE(NULLIF(stock_transfer_items.base_quantity, 0), stock_transfer_items.quantity * COALESCE(NULLIF(stock_transfer_items.conversion_rate, 0), 1))';
        $trfOutQ = DB::table('stock_transfer_items')
            ->join('stock_transfers', 'stock_transfer_items.stock_transfer_id', '=', 'stock_transfers.id')
            ->whereIn('stock_transfers.status', ['Sent', 'Completed']);
        $applyWindow($trfOutQ, 'stock_transfers.date');
        if (!empty($allowedOutlets)) $trfOutQ->whereIn('stock_transfers.from_warehouse_id', $allowedOutlets);
        if ($filterWh) $trfOutQ->where('stock_transfers.from_warehouse', $warehouse);
        if ($filterProducts) $trfOutQ->whereIn('stock_transfer_items.product_id', $productIds);
        if ($includePostWindow) {
            $extractMaps(
                $trfOutQ->selectRaw("stock_transfer_items.product_id, SUM(CASE WHEN stock_transfers.date <= ? THEN {$trfQtySql} ELSE 0 END) as period_qty, SUM(CASE WHEN stock_transfers.date > ? THEN {$trfQtySql} ELSE 0 END) as post_qty", [$toDateTime, $toDateTime])
                    ->groupBy('stock_transfer_items.product_id')->get(),
                $period['transfers_out'],
                $post['transfers_out']
            );
        } else {
            $period['transfers_out'] = $trfOutQ->select('stock_transfer_items.product_id', DB::raw("SUM({$trfQtySql}) as qty"))
                ->groupBy('stock_transfer_items.product_id')->pluck('qty', 'product_id')->all();
        }

        $trfInQ = DB::table('stock_transfer_items')
            ->join('stock_transfers', 'stock_transfer_items.stock_transfer_id', '=', 'stock_transfers.id')
            ->where('stock_transfers.status', 'Completed');
        $applyWindow($trfInQ, 'stock_transfers.date');
        if (!empty($allowedOutlets)) $trfInQ->whereIn('stock_transfers.to_warehouse_id', $allowedOutlets);
        if ($filterWh) $trfInQ->where('stock_transfers.to_warehouse', $warehouse);
        if ($filterProducts) $trfInQ->whereIn('stock_transfer_items.product_id', $productIds);
        if ($includePostWindow) {
            $extractMaps(
                $trfInQ->selectRaw("stock_transfer_items.product_id, SUM(CASE WHEN stock_transfers.date <= ? THEN {$trfQtySql} ELSE 0 END) as period_qty, SUM(CASE WHEN stock_transfers.date > ? THEN {$trfQtySql} ELSE 0 END) as post_qty", [$toDateTime, $toDateTime])
                    ->groupBy('stock_transfer_items.product_id')->get(),
                $period['transfers_in'],
                $post['transfers_in']
            );
        } else {
            $period['transfers_in'] = $trfInQ->select('stock_transfer_items.product_id', DB::raw("SUM({$trfQtySql}) as qty"))
                ->groupBy('stock_transfer_items.product_id')->pluck('qty', 'product_id')->all();
        }

        $srQ = DB::table('stock_movements')
            ->leftJoin('sale_returns', function ($join) {
                $join->on('stock_movements.reference_id', '=', 'sale_returns.id')
                     ->orOn('stock_movements.reference_id', '=', DB::raw('CAST(sale_returns.id AS CHAR)'));
            })
            ->leftJoin('outlets', 'stock_movements.warehouse_id', '=', 'outlets.id')
            ->whereIn('stock_movements.movement_type', ['sale_return', 'sale_return_void', 'sale_return_edit_reverse']);
        $srDateExpr = 'COALESCE(sale_returns.date, stock_movements.created_at)';
        if ($fromInclusive) {
            $srQ->whereRaw("{$srDateExpr} >= ?", [$fromDateTime]);
        } else {
            $srQ->whereRaw("{$srDateExpr} > ?", [$fromDateTime]);
        }
        if (!$includePostWindow && $toDateTime !== null) {
            $srQ->whereRaw("{$srDateExpr} <= ?", [$toDateTime]);
        }
        if (!empty($allowedOutlets)) {
            $srQ->whereRaw('COALESCE(sale_returns.warehouse_id, stock_movements.warehouse_id) IN (' . implode(',', array_map('intval', $allowedOutlets)) . ')');
        }
        if ($filterWh) {
            $srQ->whereRaw('COALESCE(sale_returns.warehouse_name, outlets.name) = ?', [$warehouse]);
        }
        if ($filterProducts) {
            $srQ->whereIn('stock_movements.product_id', $productIds);
        }
        $srNetExpr = 'stock_movements.quantity_in - stock_movements.quantity_out';
        if ($includePostWindow) {
            $extractMaps(
                $srQ->selectRaw("stock_movements.product_id, SUM(CASE WHEN {$srDateExpr} <= ? THEN ({$srNetExpr}) ELSE 0 END) as period_qty, SUM(CASE WHEN {$srDateExpr} > ? THEN ({$srNetExpr}) ELSE 0 END) as post_qty", [$toDateTime, $toDateTime])
                    ->groupBy('stock_movements.product_id')->get(),
                $period['sale_returns'],
                $post['sale_returns']
            );
        } else {
            $period['sale_returns'] = $srQ->select('stock_movements.product_id', DB::raw("SUM({$srNetExpr}) as qty"))
                ->groupBy('stock_movements.product_id')->pluck('qty', 'product_id')->all();
        }

        $prQ = DB::table('stock_movements')
            ->leftJoin('purchase_returns', function ($join) {
                $join->on('stock_movements.reference_id', '=', 'purchase_returns.id')
                     ->orOn('stock_movements.reference_id', '=', DB::raw('CAST(purchase_returns.id AS CHAR)'));
            })
            ->leftJoin('outlets', 'stock_movements.warehouse_id', '=', 'outlets.id')
            ->whereIn('stock_movements.movement_type', ['purchase_return', 'purchase_return_void']);
        $prDateExpr = 'COALESCE(purchase_returns.date, stock_movements.created_at)';
        if ($fromInclusive) {
            $prQ->whereRaw("{$prDateExpr} >= ?", [$fromDateTime]);
        } else {
            $prQ->whereRaw("{$prDateExpr} > ?", [$fromDateTime]);
        }
        if (!$includePostWindow && $toDateTime !== null) {
            $prQ->whereRaw("{$prDateExpr} <= ?", [$toDateTime]);
        }
        if (!empty($allowedOutlets)) {
            $prQ->whereRaw('COALESCE(purchase_returns.warehouse_id, stock_movements.warehouse_id) IN (' . implode(',', array_map('intval', $allowedOutlets)) . ')');
        }
        if ($filterWh) {
            $prQ->whereRaw('COALESCE(purchase_returns.warehouse_name, outlets.name) = ?', [$warehouse]);
        }
        if ($filterProducts) {
            $prQ->whereIn('stock_movements.product_id', $productIds);
        }
        $prNetExpr = 'stock_movements.quantity_out - stock_movements.quantity_in';
        if ($includePostWindow) {
            $extractMaps(
                $prQ->selectRaw("stock_movements.product_id, SUM(CASE WHEN {$prDateExpr} <= ? THEN ({$prNetExpr}) ELSE 0 END) as period_qty, SUM(CASE WHEN {$prDateExpr} > ? THEN ({$prNetExpr}) ELSE 0 END) as post_qty", [$toDateTime, $toDateTime])
                    ->groupBy('stock_movements.product_id')->get(),
                $period['purchase_returns'],
                $post['purchase_returns']
            );
        } else {
            $period['purchase_returns'] = $prQ->select('stock_movements.product_id', DB::raw("SUM({$prNetExpr}) as qty"))
                ->groupBy('stock_movements.product_id')->pluck('qty', 'product_id')->all();
        }

        if ($accounting->schemaHasTable('processings') && $accounting->schemaHasTable('processing_items')) {
            $pInQ = DB::table('processings')->where('status', 'completed');
            $applyWindow($pInQ, 'date');
            if (!empty($allowedOutlets)) $pInQ->whereIn('warehouse_id', $allowedOutlets);
            if ($filterWh) $pInQ->where('warehouse_name', $warehouse);
            if ($filterProducts) $pInQ->whereIn('output_product_id', $productIds);
            if ($includePostWindow) {
                $pInRows = $pInQ->selectRaw('output_product_id as product_id, SUM(CASE WHEN date <= ? THEN output_base_quantity ELSE 0 END) as period_qty, SUM(CASE WHEN date > ? THEN output_base_quantity ELSE 0 END) as post_qty', [$toDateTime, $toDateTime])
                    ->groupBy('output_product_id')->get();
                $extractMaps($pInRows, $period['processing_in'], $post['processing_in']);
            } else {
                $period['processing_in'] = $pInQ->select('output_product_id', DB::raw('SUM(output_base_quantity) as qty'))
                    ->groupBy('output_product_id')->pluck('qty', 'output_product_id')->all();
            }

            $pOutQ = DB::table('processing_items')
                ->join('processings', 'processing_items.processing_id', '=', 'processings.id')
                ->where('processings.status', 'completed');
            $applyWindow($pOutQ, 'processings.date');
            if (!empty($allowedOutlets)) $pOutQ->whereIn('processings.warehouse_id', $allowedOutlets);
            if ($filterWh) $pOutQ->where('processings.warehouse_name', $warehouse);
            if ($filterProducts) $pOutQ->whereIn('processing_items.product_id', $productIds);
            if ($includePostWindow) {
                $pOutRows = $pOutQ->selectRaw('processing_items.product_id, SUM(CASE WHEN processings.date <= ? THEN processing_items.base_quantity ELSE 0 END) as period_qty, SUM(CASE WHEN processings.date > ? THEN processing_items.base_quantity ELSE 0 END) as post_qty', [$toDateTime, $toDateTime])
                    ->groupBy('processing_items.product_id')->get();
                $extractMaps($pOutRows, $period['processing_out'], $post['processing_out']);
            } else {
                $period['processing_out'] = $pOutQ->select('processing_items.product_id', DB::raw('SUM(processing_items.base_quantity) as qty'))
                    ->groupBy('processing_items.product_id')->pluck('qty', 'product_id')->all();
            }
        }

        return $includePostWindow ? ['period' => $period, 'post' => $post] : $period;
    }
    /**
     * Ultra-fast High-Performance Executive Dashboard Analytics Pipeline
     * Single-pass SQL aggregation & sub-5ms caching execution
     */
    public function dashboardSummary(Request $request)
    {
        $warehouse = $request->query('warehouse', 'All');
        $year = (int)$request->query('year', Carbon::now()->year);
        $forceRefresh = $request->boolean('force_refresh') || $request->query('force_refresh') === 'true' || $request->query('force_refresh') === '1';

        $dateFilter = strtolower(trim((string)$request->query('date_filter', 'this_month')));
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        // Resolve standard presets if start_date/end_date not provided explicitly
        switch ($dateFilter) {
            case 'today':
                $startDate = Carbon::today()->toDateString();
                $endDate = Carbon::today()->toDateString();
                break;
            case 'yesterday':
                $startDate = Carbon::yesterday()->toDateString();
                $endDate = Carbon::yesterday()->toDateString();
                break;
            case 'last_7_days':
            case '7_days':
                $startDate = Carbon::today()->subDays(6)->toDateString();
                $endDate = Carbon::today()->toDateString();
                break;
            case 'this_month':
                $startDate = Carbon::today()->startOfMonth()->toDateString();
                $endDate = Carbon::today()->toDateString();
                break;
            case 'last_month':
                $startDate = Carbon::today()->subMonth()->startOfMonth()->toDateString();
                $endDate = Carbon::today()->subMonth()->endOfMonth()->toDateString();
                break;
            case 'this_year':
                $startDate = Carbon::today()->startOfYear()->toDateString();
                $endDate = Carbon::today()->toDateString();
                break;
            case 'all':
                $startDate = null;
                $endDate = null;
                break;
            case 'custom':
                if (empty($startDate)) {
                    $startDate = Carbon::today()->startOfMonth()->toDateString();
                }
                if (empty($endDate)) {
                    $endDate = Carbon::today()->toDateString();
                }
                break;
            default:
                if (!empty($startDate) && !empty($endDate)) {
                    $dateFilter = 'custom';
                } else {
                    $dateFilter = 'this_month';
                    $startDate = Carbon::today()->startOfMonth()->toDateString();
                    $endDate = Carbon::today()->toDateString();
                }
                break;
        }

        if (!empty($startDate) && !empty($endDate) && $startDate > $endDate) {
            $temp = $startDate;
            $startDate = $endDate;
            $endDate = $temp;
        }

        $canViewCost = $this->accounting()->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $warehouseId = $this->resolveWarehouseIdParam($warehouse);

        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();
        $todayStart = $today . ' 00:00:00';
        $todayEnd = $today . ' 23:59:59';
        $yesterdayStart = $yesterday . ' 00:00:00';
        $yesterdayEnd = $yesterday . ' 23:59:59';

        // Date scoping helper (preserves index range seek)
        $applyDateScope = function ($query, $column = 'date') use ($startDate, $endDate) {
            if (!empty($startDate) && !empty($endDate)) {
                $query->where($column, '>=', $startDate)
                      ->where($column, '<=', $endDate . ' 23:59:59');
            }
        };

        // 1. Base query scopes
        $salesBaseQuery = DB::table('sales');
        $this->applyActiveDocumentFilter($salesBaseQuery, 'status');
        $purchBaseQuery = DB::table('purchases')->whereIn('status', ['Received', 'Completed']);
        $this->excludeOpeningStockPurchases($purchBaseQuery);
        $expBaseQuery = DB::table('expenses')
            ->where(function ($q) {
                $q->whereNull('payment_status')
                  ->orWhereNotIn('payment_status', [
                      'Pending', 'pending', 'PENDING',
                      'Draft', 'draft', 'DRAFT',
                      'Voided', 'voided', 'VOIDED',
                  ]);
            });

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $salesBaseQuery->where('warehouse_name', $warehouse);
            $purchBaseQuery->where('warehouse_name', $warehouse);
            $expBaseQuery->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $salesBaseQuery->whereIn('warehouse_id', $allowedOutlets);
            $purchBaseQuery->whereIn('warehouse_id', $allowedOutlets);
            $expBaseQuery->whereIn('warehouse_id', $allowedOutlets);
        }

        // 2. Single-Pass Consolidated Period Sales Aggregation
        $periodSalesQuery = clone $salesBaseQuery;
        $applyDateScope($periodSalesQuery, 'date');
        $periodSalesAgg = $periodSalesQuery->selectRaw('
            COALESCE(SUM(grand_total), 0) as total_sales,
            COALESCE(SUM(paid_amount), 0) as paid_amount,
            COALESCE(SUM(due_amount), 0) as due_amount,
            COALESCE(SUM(total_cogs), 0) as total_cogs,
            COUNT(*) as orders_count
        ')->first();

        $totalSales = (float)($periodSalesAgg->total_sales ?? 0);
        $periodPaid = (float)($periodSalesAgg->paid_amount ?? 0);
        $periodDue = (float)($periodSalesAgg->due_amount ?? 0);
        $ordersCount = (int)($periodSalesAgg->orders_count ?? 0);
        $totalCogs = (float)($periodSalesAgg->total_cogs ?? 0);

        // Real-time Pulse Metrics (Today vs Yesterday)
        $pulseSalesAgg = (clone $salesBaseQuery)
            ->where('date', '>=', min($yesterdayStart, $todayStart))
            ->where('date', '<=', $todayEnd)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN grand_total ELSE 0 END), 0) as today_sales,
                COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN 1 ELSE 0 END), 0) as today_sales_count,
                COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN total_cogs ELSE 0 END), 0) as today_cogs,
                COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN grand_total ELSE 0 END), 0) as yesterday_sales
            ', [$todayStart, $todayEnd, $todayStart, $todayEnd, $todayStart, $todayEnd, $yesterdayStart, $yesterdayEnd])->first();

        $todaySales = (float)($pulseSalesAgg->today_sales ?? 0);
        $todaySalesCount = (int)($pulseSalesAgg->today_sales_count ?? 0);
        $todayCogs = (float)($pulseSalesAgg->today_cogs ?? 0);
        $yesterdaySales = (float)($pulseSalesAgg->yesterday_sales ?? 0);
        $salesGrowthPct = $yesterdaySales > 0
            ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100, 1)
            : ($todaySales > 0 ? 100.0 : 0.0);

        // Sales Returns & Returned-Goods COGS in matching period (and today) without correlated subquery per row
        $totalSaleReturns = 0.0;
        $totalReturnedCogs = 0.0;
        $todaySaleReturns = 0.0;
        $todayReturnedCogs = 0.0;
        try {
            $srBaseQ = DB::table('sale_returns as sr');
            $this->applyActiveDocumentFilter($srBaseQ, 'sr.status');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $srBaseQ->where('sr.warehouse_name', $warehouse);
            }
            if (!empty($allowedOutlets)) {
                $srBaseQ->whereIn('sr.warehouse_id', $allowedOutlets);
            }
            $srLayerSub = DB::table('sale_return_cost_layers')
                ->select('sale_return_id', DB::raw('SUM(total_cost) as layer_cost'))
                ->groupBy('sale_return_id');
            $srCogsExpr = 'COALESCE(NULLIF(sr.return_cost_total, 0), COALESCE(srcl.layer_cost, 0))';

            $srPeriodQ = (clone $srBaseQ)->leftJoinSub($srLayerSub, 'srcl', 'srcl.sale_return_id', '=', 'sr.id');
            $applyDateScope($srPeriodQ, 'sr.date');
            $srPeriodAgg = $srPeriodQ->selectRaw("
                COALESCE(SUM(sr.grand_total), 0) as ret_total,
                COALESCE(SUM({$srCogsExpr}), 0) as ret_cogs
            ")->first();
            $totalSaleReturns = (float)($srPeriodAgg->ret_total ?? 0);
            $totalReturnedCogs = (float)($srPeriodAgg->ret_cogs ?? 0);

            $srTodayAgg = (clone $srBaseQ)
                ->leftJoinSub($srLayerSub, 'srcl', 'srcl.sale_return_id', '=', 'sr.id')
                ->where('sr.date', '>=', $todayStart)
                ->where('sr.date', '<=', $todayEnd)
                ->selectRaw("
                    COALESCE(SUM(sr.grand_total), 0) as ret_total,
                    COALESCE(SUM({$srCogsExpr}), 0) as ret_cogs
                ")->first();
            $todaySaleReturns = (float)($srTodayAgg->ret_total ?? 0);
            $todayReturnedCogs = (float)($srTodayAgg->ret_cogs ?? 0);
        } catch (\Throwable $e) {}

        $netSales = round($totalSales - $totalSaleReturns, 2);
        $averageOrderValue = $ordersCount > 0 ? round($netSales / $ordersCount, 2) : 0.0;
        $cashRatioPct = $totalSales > 0 ? round(($periodPaid / $totalSales) * 100, 1) : 0.0;

        // 3. Consolidated Purchases Aggregation via Pre-Joined Tax Subquery (zero per-row correlated subquery)
        $purchTaxSub = DB::table('purchase_items as pi')
            ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->where('p.is_tax_recoverable', 1)
            ->select('pi.purchase_id', DB::raw('COALESCE(SUM(pi.tax), 0) as item_tax'))
            ->groupBy('pi.purchase_id');
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $purchTaxSub->where('p.warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $purchTaxSub->whereIn('p.warehouse_id', $allowedOutlets);
        }

        $netPurchJoinedSql = 'CASE WHEN COALESCE(purchases.is_tax_recoverable, 0) = 1 THEN CASE WHEN (purchases.grand_total - COALESCE(purchases.tax_amount, 0) - COALESCE(pt.item_tax, 0)) < 0 THEN 0 ELSE (purchases.grand_total - COALESCE(purchases.tax_amount, 0) - COALESCE(pt.item_tax, 0)) END ELSE purchases.grand_total END';

        $periodPurchQuery = (clone $purchBaseQuery)->leftJoinSub($purchTaxSub, 'pt', 'pt.purchase_id', '=', 'purchases.id');
        $applyDateScope($periodPurchQuery, 'purchases.date');
        $periodPurchAgg = $periodPurchQuery->selectRaw("
            COALESCE(SUM({$netPurchJoinedSql}), 0) as total_purchases,
            COALESCE(SUM(purchases.paid_amount), 0) as paid_amount,
            COALESCE(SUM(purchases.due_amount), 0) as due_amount,
            COUNT(*) as purchases_count
        ")->first();

        $totalPurchases = (float)($periodPurchAgg->total_purchases ?? 0);
        $periodPurchDue = (float)($periodPurchAgg->due_amount ?? 0);

        $todayPurchases = (float)(clone $purchBaseQuery)
            ->leftJoinSub($purchTaxSub, 'pt', 'pt.purchase_id', '=', 'purchases.id')
            ->where('purchases.date', '>=', $todayStart)
            ->where('purchases.date', '<=', $todayEnd)
            ->selectRaw("COALESCE(SUM({$netPurchJoinedSql}), 0) as total")
            ->value('total');

        $purchaseAccounting = $this->accounting();
        $totalPurchaseReturns = 0.0;
        $totalPurchaseReturnVariance = 0.0;
        $todayPurchaseReturnVariance = 0.0;
        try {
            $prBaseQuery = DB::table('purchase_returns')->whereIn('status', ['Completed', 'Received']);
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $prBaseQuery->where('warehouse_name', $warehouse);
            }
            if (!empty($allowedOutlets)) {
                $prBaseQuery->whereIn('warehouse_id', $allowedOutlets);
            }
            $prPeriodQuery = clone $prBaseQuery;
            $applyDateScope($prPeriodQuery, 'date');
            $prAgg = (clone $prPeriodQuery)->selectRaw('
                COALESCE(SUM(CASE WHEN COALESCE(is_tax_recoverable, 0) != 1 THEN grand_total ELSE 0 END), 0) as non_recov_total,
                COALESCE(SUM(COALESCE(cost_variance, 0)), 0) as total_variance
            ')->first();
            $totalPurchaseReturns = (float)($prAgg->non_recov_total ?? 0);
            $totalPurchaseReturnVariance = round((float)($prAgg->total_variance ?? 0), 2);

            $recovPrRows = (clone $prPeriodQuery)
                ->where('is_tax_recoverable', 1)
                ->select(['grand_total', 'tax_amount', 'is_tax_recoverable', 'items_payload'])
                ->get();
            foreach ($recovPrRows as $prRow) {
                $totalPurchaseReturns += $purchaseAccounting->netPurchaseReturnAmount($prRow);
            }
            $totalPurchaseReturns = round($totalPurchaseReturns, 2);

            $todayPurchaseReturnVariance = round((float)(clone $prBaseQuery)
                ->where('date', '>=', $todayStart)
                ->where('date', '<=', $todayEnd)
                ->sum('cost_variance'), 2);
        } catch (\Throwable $e) {}

        $netPurchases = round($totalPurchases - $totalPurchaseReturns, 2);

        // 4. Single-Pass Consolidated Expenses Aggregation in Period
        $periodExpQuery = clone $expBaseQuery;
        $applyDateScope($periodExpQuery, 'date');
        $totalExpenses = (float)$periodExpQuery->sum('amount');

        $todayExpenses = (float)(clone $expBaseQuery)
            ->where('date', '>=', $todayStart)
            ->where('date', '<=', $todayEnd)
            ->sum('amount');

        // 5. Cost of Goods Sold (COGS) & GAAP/IFRS Operational Profitability
        if ($totalCogs == 0.0 && $totalSales > 0.0) {
            try {
                $cogsBaseQuery = DB::table('sale_items')
                    ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                    ->leftJoin('product_variants', 'sale_items.product_variant_id', '=', 'product_variants.id')
                    ->leftJoin('products', 'sale_items.product_id', '=', 'products.id');
                $this->applyActiveDocumentFilter($cogsBaseQuery, 'sales.status');

                if ($warehouse !== 'All' && !empty($warehouse)) {
                    $cogsBaseQuery->where('sales.warehouse_name', $warehouse);
                }
                if (!empty($allowedOutlets)) {
                    $cogsBaseQuery->whereIn('sales.warehouse_id', $allowedOutlets);
                }

                $cogsExpr = 'COALESCE(SUM(COALESCE(NULLIF(sale_items.total_cogs, 0), COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity, 1) * COALESCE(NULLIF(product_variants.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0))), 0) as total_cogs';
                $periodCogsQuery = clone $cogsBaseQuery;
                $applyDateScope($periodCogsQuery, 'sales.date');
                $totalCogs = (float)$periodCogsQuery->selectRaw($cogsExpr)->value('total_cogs');
            } catch (\Throwable $e) {}
        }

        // 4b. Operating Stock Adjustments
        $totalAdjustmentLoss = 0.0;
        $totalAdjustmentGain = 0.0;
        $todayAdjustmentNet = 0.0;
        $nonPnlAdjustments = 0.0;
        try {
            $effAdjTypeSql = $this->effectiveAdjustmentTypeSql();
            $opAdjBase = DB::table('stock_adjustment_items')
                ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $opAdjBase->where('stock_adjustments.warehouse_name', $warehouse);
            }
            if (!empty($allowedOutlets)) {
                $opAdjBase->whereIn('stock_adjustments.warehouse_id', $allowedOutlets);
            }
            $this->applyOperatingAdjustmentScope($opAdjBase, true);

            $periodOpAdj = clone $opAdjBase;
            $applyDateScope($periodOpAdj, 'stock_adjustments.date');
            $adjAgg = $periodOpAdj->selectRaw("
                COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'subtraction' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as adj_loss,
                COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'addition' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as adj_gain
            ")->first();
            $totalAdjustmentLoss = (float)($adjAgg->adj_loss ?? 0);
            $totalAdjustmentGain = (float)($adjAgg->adj_gain ?? 0);

            $todayOpAdj = (clone $opAdjBase)
                ->where('stock_adjustments.date', '>=', $todayStart)
                ->where('stock_adjustments.date', '<=', $todayEnd)
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'addition' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) -
                    COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'subtraction' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as net_adj
                ")->value('net_adj');
            $todayAdjustmentNet = (float)($todayOpAdj ?? 0);

            $nonPnlQuery = DB::table('stock_adjustment_items')
                ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $nonPnlQuery->where('stock_adjustments.warehouse_name', $warehouse);
            }
            if (!empty($allowedOutlets)) {
                $nonPnlQuery->whereIn('stock_adjustments.warehouse_id', $allowedOutlets);
            }
            $this->applyOperatingAdjustmentScope($nonPnlQuery, false);
            $applyDateScope($nonPnlQuery, 'stock_adjustments.date');
            $nonPnlAdjustments = (float)$nonPnlQuery->selectRaw("
                COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'addition' THEN stock_adjustment_items.subtotal ELSE -stock_adjustment_items.subtotal END), 0) as net_cap
            ")->value('net_cap');
        } catch (\Throwable $e) {}

        $totalCogs = round($totalCogs - $totalReturnedCogs, 2);
        $todayCogs = round($todayCogs - $todayReturnedCogs, 2);
        $grossProfit = round($netSales - $totalCogs, 2);
        $netProfit = round($grossProfit - $totalExpenses - $totalAdjustmentLoss + $totalAdjustmentGain + $totalPurchaseReturnVariance, 2);
        $profitMarginPct = $netSales > 0 ? round(($netProfit / $netSales) * 100, 2) : 0.0;
        $todayGrossProfit = round(($todaySales - $todaySaleReturns) - $todayCogs, 2);
        $todayProfit = round($todayGrossProfit - $todayExpenses + $todayAdjustmentNet + $todayPurchaseReturnVariance, 2);

        // 6. Live Balance Sheet & SQL-Level Inventory Valuation Summary (zero cache)
        $grossReceivablesQ = DB::table('sales');
        $this->applyActiveDocumentFilter($grossReceivablesQ, 'status');
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $grossReceivablesQ->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $grossReceivablesQ->whereIn('warehouse_id', $allowedOutlets);
        }
        $grossCustomerDue = (float)$grossReceivablesQ->sum('due_amount');

        $openCreditNotesQ = DB::table('sale_returns');
        $this->applyActiveDocumentFilter($openCreditNotesQ, 'status');
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $openCreditNotesQ->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $openCreditNotesQ->whereIn('warehouse_id', $allowedOutlets);
        }
        $openCreditNotes = (float)$openCreditNotesQ->sum('due_amount');
        $customerDue = max(0.0, round($grossCustomerDue - $openCreditNotes, 2));

        $supplierDue = $purchaseAccounting
            ->computeNetSupplierPayable($warehouse, !empty($allowedOutlets) ? $allowedOutlets : null);

        $valuation = app(InventoryReconciliationService::class)->computeValuationSummary(
            $warehouseId,
            !empty($allowedOutlets) ? $allowedOutlets : null
        );
        $inventoryValuationCost = round((float)$valuation['effective_valuation'], 2);
        $fifoBatchValuation = round((float)$valuation['fifo_batch_valuation'], 2);
        $masterInventoryValuation = round((float)$valuation['master_valuation'], 2);
        $totalInStockUnits = round((float)($valuation['total_book_qty'] ?? 0), 4);

        $totalProducts = (int) DB::table('products')->whereNull('deleted_at')->count();

        $singleLowStockQuery = DB::table('products as p')
            ->whereNull('p.deleted_at')
            ->where(function ($q) {
                $q->where('p.has_variants', false)->orWhereNull('p.has_variants');
            });

        $isBranchScoped = $warehouseId !== null || !empty($allowedOutlets);
        if ($isBranchScoped) {
            $sbProdSub = DB::table('stock_balances')
                ->select('product_id', DB::raw('SUM(quantity) as wh_qty'))
                ->where(function ($q) {
                    $q->whereNull('product_variant_id')->orWhere('product_variant_id', 0);
                })
                ->groupBy('product_id');
            if ($warehouseId !== null) {
                $sbProdSub->where('warehouse_id', $warehouseId);
            } else {
                $sbProdSub->whereIn('warehouse_id', $allowedOutlets);
            }
            $singleLowStockRows = $singleLowStockQuery
                ->joinSub($sbProdSub, 'sb', 'sb.product_id', '=', 'p.id')
                ->whereRaw('sb.wh_qty <= COALESCE(NULLIF(p.alert_quantity, 0), NULLIF(p.stock_alert, 0), NULLIF(p.low_qty, 0), 10)')
                ->select([
                    'p.id', 'p.name', 'p.code', 'p.sku', 'p.alternative_name',
                    'p.category_name', 'p.brand_name', 'p.unit_name',
                    'sb.wh_qty as effective_stock',
                    'p.alert_quantity', 'p.stock_alert', 'p.low_qty',
                    'p.retail_price', 'p.selling_price',
                ])
                ->orderBy('sb.wh_qty', 'asc')
                ->limit(15)
                ->get();
        } else {
            $singleLowStockRows = $singleLowStockQuery
                ->where(function ($q) {
                    $q->whereColumn('p.stock_quantity', '<=', 'p.alert_quantity')
                      ->orWhereColumn('p.stock_quantity', '<=', 'p.stock_alert')
                      ->orWhereColumn('p.stock_quantity', '<=', 'p.low_qty')
                      ->orWhere('p.stock_quantity', '<=', 0);
                })
                ->select([
                    'p.id', 'p.name', 'p.code', 'p.sku', 'p.alternative_name',
                    'p.category_name', 'p.brand_name', 'p.unit_name',
                    'p.stock_quantity as effective_stock',
                    'p.alert_quantity', 'p.stock_alert', 'p.low_qty',
                    'p.retail_price', 'p.selling_price',
                ])
                ->orderBy('p.stock_quantity', 'asc')
                ->limit(15)
                ->get();
        }

        $singleLowStock = $singleLowStockRows->map(function ($p) {
            $alertQty = (float)($p->alert_quantity ?: ($p->stock_alert ?: ($p->low_qty ?: 10)));
            $stockQty = (float)($p->effective_stock ?? 0);
            return [
                'id' => (int)$p->id,
                'product_id' => (int)$p->id,
                'is_variant' => false,
                'variant_name' => '',
                'product_name' => $p->name,
                'code' => $p->code ?: ($p->sku ?? ''),
                'name' => $p->name,
                'display_name' => $p->name,
                'alternative_name' => $p->alternative_name ?? '',
                'category_name' => $p->category_name ?? 'General',
                'brand_name' => $p->brand_name ?? 'Generic',
                'unit_name' => $p->unit_name ?? 'Piece',
                'stock_quantity' => $stockQty,
                'alert_quantity' => $alertQty,
                'retail_price' => (float)($p->retail_price ?? ($p->selling_price ?? 0)),
            ];
        });

        $variantLowStock = collect();
        try {
            $sbVarQuery = DB::table('stock_balances')
                ->select('product_variant_id', DB::raw('SUM(quantity) as current_stock'))
                ->whereNotNull('product_variant_id');
            if ($warehouseId !== null) {
                $sbVarQuery->where('warehouse_id', $warehouseId);
            } elseif (!empty($allowedOutlets)) {
                $sbVarQuery->whereIn('warehouse_id', $allowedOutlets);
            }
            $lowStockVariantIds = $sbVarQuery
                ->groupBy('product_variant_id')
                ->havingRaw('current_stock <= 10')
                ->orderBy('current_stock', 'asc')
                ->limit(15)
                ->get();

            if ($lowStockVariantIds->isNotEmpty()) {
                $vIds = $lowStockVariantIds->pluck('product_variant_id')->all();
                $variantDetails = DB::table('product_variants')
                    ->join('products', 'product_variants.product_id', '=', 'products.id')
                    ->whereIn('product_variants.id', $vIds)
                    ->whereNull('products.deleted_at')
                    ->whereNull('product_variants.deleted_at')
                    ->select(
                        'product_variants.id as variant_id',
                        'products.id as product_id',
                        'products.name as product_name',
                        'product_variants.name as variant_name',
                        'product_variants.sku as variant_sku',
                        'product_variants.barcode as variant_barcode',
                        'products.code as product_code',
                        'products.alternative_name',
                        'products.category_name',
                        'products.brand_name',
                        'products.unit_name',
                        'products.alert_quantity',
                        'products.stock_alert',
                        'products.low_qty',
                        'product_variants.selling_price as variant_price',
                        'products.retail_price as product_price'
                    )
                    ->get()
                    ->keyBy('variant_id');

                foreach ($lowStockVariantIds as $vItem) {
                    $v = $variantDetails->get($vItem->product_variant_id);
                    if (!$v) continue;
                    $alertQty = (float)($v->alert_quantity ?: ($v->stock_alert ?: ($v->low_qty ?: 10)));
                    $stockQty = (float)$vItem->current_stock;
                    $displayName = $v->product_name . ' (' . $v->variant_name . ')';
                    $variantLowStock->push([
                        'id' => (int)$v->variant_id,
                        'product_id' => (int)$v->product_id,
                        'is_variant' => true,
                        'variant_name' => $v->variant_name,
                        'product_name' => $v->product_name,
                        'code' => $v->variant_sku ?: ($v->variant_barcode ?: ($v->product_code ?? '')),
                        'name' => $displayName,
                        'display_name' => $displayName,
                        'alternative_name' => $v->alternative_name ?? '',
                        'category_name' => $v->category_name ?? 'General',
                        'brand_name' => $v->brand_name ?? 'Generic',
                        'unit_name' => $v->unit_name ?? 'Piece',
                        'stock_quantity' => $stockQty,
                        'alert_quantity' => $alertQty,
                        'retail_price' => (float)($v->variant_price ?? ($v->product_price ?? 0)),
                    ]);
                }
            }
        } catch (\Throwable $e) {}

        $allLowStock = $singleLowStock->concat($variantLowStock)
            ->sortBy('stock_quantity')
            ->values();
        $lowStockProducts = $allLowStock->take(10)->values();

        $totalVariants = 0;
        try {
            $totalVariants = DB::table('product_variants')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->whereNull('products.deleted_at')
                ->whereNull('product_variants.deleted_at')
                ->count();
        } catch (\Throwable $e) {}

        $lowStockCount = $allLowStock->count();
        $outOfStockCount = $allLowStock->where('stock_quantity', '<=', 0)->count();
        $totalCustomers = (int) DB::table('customers')->count();
        $totalSuppliers = (int) DB::table('suppliers')->count();
        $totalOutlets = 1;
        try {
            $totalOutlets = (int)(DB::table('outlets')->count() ?: 1);
        } catch (\Throwable $e) {}

        // 7. Monthly 12-Month Trends for Selected Year
        $monthlySales = array_fill(0, 12, 0.0);
        $monthlyPurchases = array_fill(0, 12, 0.0);
        $monthlyExpenses = array_fill(0, 12, 0.0);
        $monthlyProfit = array_fill(0, 12, 0.0);
        $monthlyCogs = array_fill(0, 12, 0.0);

        $yearStart = "{$year}-01-01 00:00:00";
        $yearEnd = "{$year}-12-31 23:59:59";

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $monthExpr = $isSqlite ? DB::raw("CAST(strftime('%m', date) AS integer)") : DB::raw('MONTH(date)');

        $salesMonthlyData = (clone $salesBaseQuery)
            ->where('date', '>=', $yearStart)
            ->where('date', '<=', $yearEnd)
            ->selectRaw($isSqlite
                ? "CAST(strftime('%m', date) AS integer) as m, SUM(grand_total) as total, SUM(total_cogs) as total_cogs"
                : "MONTH(date) as m, SUM(grand_total) as total, SUM(total_cogs) as total_cogs"
            )
            ->groupBy($monthExpr)
            ->get();

        foreach ($salesMonthlyData as $row) {
            $mIdx = (int)$row->m;
            if ($mIdx >= 1 && $mIdx <= 12) {
                $monthlySales[$mIdx - 1] = (float)$row->total;
                $monthlyCogs[$mIdx - 1] = (float)($row->total_cogs ?? 0);
            }
        }

        // Deduct monthly sale returns & returned COGS using pre-aggregated layer join
        try {
            $srLayerYearSub = DB::table('sale_return_cost_layers')
                ->select('sale_return_id', DB::raw('SUM(total_cost) as layer_cost'))
                ->groupBy('sale_return_id');
            $srMonthQuery = DB::table('sale_returns as sr')
                ->leftJoinSub($srLayerYearSub, 'srcl', 'srcl.sale_return_id', '=', 'sr.id');
            $this->applyActiveDocumentFilter($srMonthQuery, 'sr.status');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $srMonthQuery->where('sr.warehouse_name', $warehouse);
            }
            if (!empty($allowedOutlets)) {
                $srMonthQuery->whereIn('sr.warehouse_id', $allowedOutlets);
            }
            $srMonthExpr = $isSqlite ? DB::raw("CAST(strftime('%m', sr.date) AS integer)") : DB::raw('MONTH(sr.date)');
            $srYearCogsExpr = 'COALESCE(NULLIF(sr.return_cost_total, 0), COALESCE(srcl.layer_cost, 0))';
            $saleReturnsByMonth = $srMonthQuery
                ->where('sr.date', '>=', $yearStart)
                ->where('sr.date', '<=', $yearEnd)
                ->selectRaw($isSqlite
                    ? "CAST(strftime('%m', sr.date) AS integer) as m, SUM(sr.grand_total) as total, SUM({$srYearCogsExpr}) as ret_cogs"
                    : "MONTH(sr.date) as m, SUM(sr.grand_total) as total, SUM({$srYearCogsExpr}) as ret_cogs")
                ->groupBy($srMonthExpr)
                ->get();

            foreach ($saleReturnsByMonth as $srRow) {
                $mIdx = (int)$srRow->m;
                if ($mIdx >= 1 && $mIdx <= 12) {
                    $monthlySales[$mIdx - 1] = max(0.0, round($monthlySales[$mIdx - 1] - (float)$srRow->total, 2));
                    $monthlyCogs[$mIdx - 1] = round($monthlyCogs[$mIdx - 1] - (float)$srRow->ret_cogs, 2);
                }
            }
        } catch (\Throwable $e) {}

        // Group purchases by month using pre-joined tax subquery
        $purchasesByMonth = (clone $purchBaseQuery)
            ->leftJoinSub($purchTaxSub, 'pt', 'pt.purchase_id', '=', 'purchases.id')
            ->where('purchases.date', '>=', $yearStart)
            ->where('purchases.date', '<=', $yearEnd)
            ->selectRaw($isSqlite
                ? "CAST(strftime('%m', purchases.date) AS integer) as m, SUM({$netPurchJoinedSql}) as total"
                : "MONTH(purchases.date) as m, SUM({$netPurchJoinedSql}) as total")
            ->groupBy($isSqlite ? DB::raw("CAST(strftime('%m', purchases.date) AS integer)") : DB::raw('MONTH(purchases.date)'))
            ->pluck('total', 'm');

        foreach ($purchasesByMonth as $m => $tot) {
            $mIdx = (int)$m;
            if ($mIdx >= 1 && $mIdx <= 12) {
                $monthlyPurchases[$mIdx - 1] = (float)$tot;
            }
        }

        // Deduct monthly completed purchase returns (SQL for non-recoverable + PHP only for recoverable tax rows)
        $monthlyPrVariance = array_fill(0, 12, 0.0);
        try {
            $prMonthQuery = DB::table('purchase_returns')->whereIn('status', ['Completed', 'Received']);
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $prMonthQuery->where('warehouse_name', $warehouse);
            }
            if (!empty($allowedOutlets)) {
                $prMonthQuery->whereIn('warehouse_id', $allowedOutlets);
            }
            $prYearBase = (clone $prMonthQuery)
                ->where('date', '>=', $yearStart)
                ->where('date', '<=', $yearEnd);

            $prMonthAggs = (clone $prYearBase)
                ->selectRaw($isSqlite
                    ? "CAST(strftime('%m', date) AS integer) as m, COALESCE(SUM(CASE WHEN COALESCE(is_tax_recoverable, 0) != 1 THEN grand_total ELSE 0 END), 0) as non_recov, COALESCE(SUM(COALESCE(cost_variance, 0)), 0) as var_sum"
                    : "MONTH(date) as m, COALESCE(SUM(CASE WHEN COALESCE(is_tax_recoverable, 0) != 1 THEN grand_total ELSE 0 END), 0) as non_recov, COALESCE(SUM(COALESCE(cost_variance, 0)), 0) as var_sum")
                ->groupBy($monthExpr)
                ->get();

            foreach ($prMonthAggs as $pmRow) {
                $mIdx = (int)$pmRow->m;
                if ($mIdx >= 1 && $mIdx <= 12) {
                    $monthlyPurchases[$mIdx - 1] = max(0.0, round($monthlyPurchases[$mIdx - 1] - (float)$pmRow->non_recov, 2));
                    $monthlyPrVariance[$mIdx - 1] = round($monthlyPrVariance[$mIdx - 1] + (float)$pmRow->var_sum, 2);
                }
            }

            $recovPrYearRows = (clone $prYearBase)
                ->where('is_tax_recoverable', 1)
                ->select(['date', 'grand_total', 'tax_amount', 'is_tax_recoverable', 'items_payload'])
                ->get();
            foreach ($recovPrYearRows as $prMRow) {
                $mIdx = (int)substr((string)$prMRow->date, 5, 2);
                if ($mIdx >= 1 && $mIdx <= 12) {
                    $netPrAmt = $purchaseAccounting->netPurchaseReturnAmount($prMRow);
                    $monthlyPurchases[$mIdx - 1] = max(0.0, round($monthlyPurchases[$mIdx - 1] - $netPrAmt, 2));
                }
            }
        } catch (\Throwable $e) {}

        // Group expenses by month
        $expensesByMonth = (clone $expBaseQuery)
            ->where('date', '>=', $yearStart)
            ->where('date', '<=', $yearEnd)
            ->selectRaw($isSqlite ? "CAST(strftime('%m', date) AS integer) as m, SUM(amount) as total" : "MONTH(date) as m, SUM(amount) as total")
            ->groupBy($monthExpr)
            ->pluck('total', 'm');

        foreach ($expensesByMonth as $m => $tot) {
            $mIdx = (int)$m;
            if ($mIdx >= 1 && $mIdx <= 12) {
                $monthlyExpenses[$mIdx - 1] = (float)$tot;
            }
        }

        // Group monthly operating stock adjustments (enforcing $allowedOutlets & sargable date index)
        $monthlyAdjNet = array_fill(0, 12, 0.0);
        try {
            $effAdjTypeSqlM = $this->effectiveAdjustmentTypeSql();
            $adjMonthBase = DB::table('stock_adjustment_items')
                ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id')
                ->where('stock_adjustments.date', '>=', $yearStart)
                ->where('stock_adjustments.date', '<=', $yearEnd);
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $adjMonthBase->where('stock_adjustments.warehouse_name', $warehouse);
            }
            if (!empty($allowedOutlets)) {
                $adjMonthBase->whereIn('stock_adjustments.warehouse_id', $allowedOutlets);
            }
            $this->applyOperatingAdjustmentScope($adjMonthBase, true);

            $adjMonthExpr = $isSqlite
                ? "CAST(strftime('%m', stock_adjustments.date) AS integer)"
                : 'MONTH(stock_adjustments.date)';
            $adjMonthRows = $adjMonthBase
                ->selectRaw("
                    {$adjMonthExpr} as m,
                    COALESCE(SUM(CASE WHEN {$effAdjTypeSqlM} = 'addition' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as adj_gain,
                    COALESCE(SUM(CASE WHEN {$effAdjTypeSqlM} = 'subtraction' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as adj_loss
                ")
                ->groupBy(DB::raw($adjMonthExpr))
                ->get();

            foreach ($adjMonthRows as $am) {
                $mIdx = (int)$am->m;
                if ($mIdx >= 1 && $mIdx <= 12) {
                    $monthlyAdjNet[$mIdx - 1] = round((float)$am->adj_gain - (float)$am->adj_loss, 2);
                }
            }
        } catch (\Throwable $e) {}

        for ($i = 0; $i < 12; $i++) {
            $monthlyProfit[$i] = round($monthlySales[$i] - $monthlyCogs[$i] - $monthlyExpenses[$i] + $monthlyPrVariance[$i] + $monthlyAdjNet[$i], 2);
        }

        // 8. Payment Methods Tender Mix in Period
        $rawPaymentMethodsQuery = (clone $salesBaseQuery);
        $applyDateScope($rawPaymentMethodsQuery, 'date');
        $rawPaymentMethods = $rawPaymentMethodsQuery
            ->select('payment_type', DB::raw('COUNT(*) as count'), DB::raw('SUM(grand_total) as amount'))
            ->groupBy('payment_type')
            ->orderByDesc('amount')
            ->get();

        $paymentMethods = [];
        $pTotal = $totalSales > 0 ? $totalSales : 1.0;
        foreach ($rawPaymentMethods as $pm) {
            $type = !empty($pm->payment_type) ? $pm->payment_type : 'Cash';
            $amt = (float)$pm->amount;
            $paymentMethods[] = [
                'type' => $type,
                'amount' => $amt,
                'count' => (int)$pm->count,
                'percentage' => round(($amt / $pTotal) * 100, 1),
            ];
        }

        // 9. Top Selling Products in Period (uniformly applying active filter + allowedOutlets)
        $topProducts = [];
        try {
            $topProductAggQuery = DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id');
            $this->applyActiveDocumentFilter($topProductAggQuery, 'sales.status');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $topProductAggQuery->where('sales.warehouse_name', $warehouse);
            }
            if (!empty($allowedOutlets)) {
                $topProductAggQuery->whereIn('sales.warehouse_id', $allowedOutlets);
            }
            $applyDateScope($topProductAggQuery, 'sales.date');

            $topProductAggs = $topProductAggQuery
                ->select(
                    'sale_items.product_id',
                    DB::raw('MAX(sale_items.product_name) as product_name'),
                    DB::raw('MAX(sale_items.product_code) as product_code'),
                    DB::raw('SUM(sale_items.quantity) as total_qty'),
                    DB::raw('SUM(sale_items.subtotal) as total_rev')
                )
                ->groupBy('sale_items.product_id')
                ->orderByDesc('total_qty')
                ->limit(6)
                ->get();

            if ($topProductAggs->isNotEmpty()) {
                $pIds = $topProductAggs->pluck('product_id')->filter()->all();
                $productDetails = DB::table('products')
                    ->whereIn('id', $pIds)
                    ->select('id', 'name', 'alternative_name', 'brand_name', 'category_name', 'retail_price', 'stock_quantity', 'image_url')
                    ->get()
                    ->keyBy('id');

                foreach ($topProductAggs as $tp) {
                    $prod = $productDetails->get($tp->product_id);
                    $topProducts[] = [
                        'id' => (int)$tp->product_id,
                        'name' => $prod->name ?? ($tp->product_name ?? 'Product'),
                        'code' => $tp->product_code ?? '',
                        'alternative_name' => $prod->alternative_name ?? '',
                        'brand' => $prod->brand_name ?? 'Generic',
                        'category' => $prod->category_name ?? 'General',
                        'price' => (float)($prod->retail_price ?? 0),
                        'stock_quantity' => (float)($prod->stock_quantity ?? 0),
                        'total_quantity' => (float)$tp->total_qty,
                        'total_revenue' => (float)$tp->total_rev,
                        'image_url' => $prod->image_url ?? '',
                    ];
                }
            }
        } catch (\Throwable $e) {}

        if (empty($topProducts)) {
            $fallbackProdQ = Product::whereNull('products.deleted_at');
            if ($isBranchScoped) {
                $fallbackProdQ->whereExists(function ($q) use ($warehouseId, $allowedOutlets) {
                    $q->select(DB::raw(1))
                        ->from('stock_balances as sb')
                        ->whereColumn('sb.product_id', 'products.id');
                    if ($warehouseId !== null) {
                        $q->where('sb.warehouse_id', $warehouseId);
                    } else {
                        $q->whereIn('sb.warehouse_id', $allowedOutlets);
                    }
                });
            }
            $topProducts = $fallbackProdQ
                ->select('products.id', 'products.name', 'products.code', 'products.alternative_name', 'products.brand_name', 'products.category_name', 'products.retail_price', 'products.selling_price', 'products.stock_quantity', 'products.image_url')
                ->orderBy('products.stock_quantity', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($prod) {
                    return [
                        'id' => $prod->id,
                        'name' => $prod->name,
                        'code' => $prod->code,
                        'alternative_name' => $prod->alternative_name ?? '',
                        'brand' => $prod->brand_name ?? 'Generic',
                        'category' => $prod->category_name ?? 'General',
                        'price' => (float)($prod->retail_price ?? ($prod->selling_price ?? 0)),
                        'stock_quantity' => (float)$prod->stock_quantity,
                        'total_quantity' => 0.0,
                        'total_revenue' => 0.0,
                        'image_url' => $prod->image_url,
                    ];
                })->all();
        }

        // 10. Top Category Sales Share in Period (uniformly applying active filter + allowedOutlets)
        $categorySales = [];
        try {
            $catQuery = DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->leftJoin('products', 'sale_items.product_id', '=', 'products.id');
            $this->applyActiveDocumentFilter($catQuery, 'sales.status');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $catQuery->where('sales.warehouse_name', $warehouse);
            }
            if (!empty($allowedOutlets)) {
                $catQuery->whereIn('sales.warehouse_id', $allowedOutlets);
            }
            $applyDateScope($catQuery, 'sales.date');

            $catResults = $catQuery
                ->select(
                    DB::raw("COALESCE(NULLIF(products.category_name, ''), 'General') as category_name"),
                    DB::raw('COALESCE(SUM(sale_items.subtotal), 0) as total_sales'),
                    DB::raw('COALESCE(SUM(sale_items.quantity), 0) as total_qty'),
                    DB::raw('COUNT(sale_items.id) as orders_count')
                )
                ->groupBy('category_name')
                ->orderByDesc('total_sales')
                ->limit(8)
                ->get();

            foreach ($catResults as $cr) {
                $amt = (float)$cr->total_sales;
                $categorySales[] = [
                    'category_name' => $cr->category_name,
                    'amount' => $amt,
                    'quantity' => (float)$cr->total_qty,
                    'orders_count' => (int)$cr->orders_count,
                    'percentage' => $totalSales > 0 ? round(($amt / $totalSales) * 100, 1) : 0.0,
                ];
            }
        } catch (\Throwable $e) {}

        // 11. Recent Sales Transactions (Latest 10 with explicit column projection)
        $recentSales = (clone $salesBaseQuery)
            ->select([
                'id',
                'reference_no',
                'customer_name',
                'warehouse_name',
                'date',
                'grand_total',
                'paid_amount',
                'due_amount',
                'payment_type',
                'payment_status',
                'status',
            ])
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($s) {
                return [
                    'id' => $s->id,
                    'reference_no' => $s->reference_no,
                    'customer_name' => $s->customer_name ?? 'Walk-in Customer',
                    'warehouse_name' => $s->warehouse_name ?? 'Main Branch Outlet',
                    'date' => $s->date,
                    'grand_total' => (float)$s->grand_total,
                    'paid_amount' => (float)$s->paid_amount,
                    'due_amount' => (float)$s->due_amount,
                    'payment_type' => $s->payment_type ?? 'Cash',
                    'payment_status' => $s->payment_status ?? 'Paid',
                    'status' => $s->status ?? 'Completed',
                ];
            })->all();

        $responsePayload = [
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'data' => [
                'cost_masked' => !$canViewCost,
                'date_filter' => $dateFilter,
                'start_date' => $startDate,
                'end_date' => $endDate,

                'total_sales' => $totalSales,
                'total_sale_returns' => $totalSaleReturns,
                'net_sales' => $netSales,
                'orders_count' => $ordersCount,
                'average_order_value' => $averageOrderValue,
                'paid_amount' => $periodPaid,
                'cash_collected' => $periodPaid,
                'cash_ratio_pct' => $cashRatioPct,
                'period_due' => $periodDue,

                'total_purchases' => $canViewCost ? $totalPurchases : 0.0,
                'total_purchase_returns' => $canViewCost ? $totalPurchaseReturns : 0.0,
                'net_purchases' => $canViewCost ? $netPurchases : 0.0,

                'total_expenses' => $totalExpenses,
                'total_adjustment_loss' => $canViewCost ? round($totalAdjustmentLoss, 2) : 0.0,
                'total_adjustment_gain' => $canViewCost ? round($totalAdjustmentGain, 2) : 0.0,
                'inventory_loss' => $canViewCost ? round($totalAdjustmentLoss, 2) : 0.0,
                'inventory_gain' => $canViewCost ? round($totalAdjustmentGain, 2) : 0.0,
                'purchase_return_variance' => $canViewCost ? $totalPurchaseReturnVariance : 0.0,
                'non_pnl_adjustments' => $canViewCost ? round($nonPnlAdjustments, 2) : 0.0,
                'cogs' => $canViewCost ? $totalCogs : 0.0,
                'gross_profit' => $canViewCost ? $grossProfit : 0.0,
                'net_profit' => $canViewCost ? $netProfit : 0.0,
                'profit_margin_pct' => $canViewCost ? $profitMarginPct : 0.0,

                'customer_due' => $customerDue,
                'supplier_due' => $canViewCost ? $supplierDue : 0.0,
                'inventory_valuation_cost' => $canViewCost ? $inventoryValuationCost : 0.0,
                'fifo_batch_valuation' => $canViewCost ? $fifoBatchValuation : 0.0,
                'master_inventory_valuation' => $canViewCost ? $masterInventoryValuation : 0.0,

                'today_sales' => $todaySales,
                'today_sales_count' => $todaySalesCount,
                'today_purchases' => $canViewCost ? $todayPurchases : 0.0,
                'today_expenses' => $todayExpenses,
                'today_cogs' => $canViewCost ? $todayCogs : 0.0,
                'today_profit' => $canViewCost ? $todayProfit : 0.0,
                'yesterday_sales' => $yesterdaySales,
                'sales_growth_pct' => $salesGrowthPct,

                'total_products' => $totalProducts,
                'total_variants' => $totalVariants,
                'total_in_stock_units' => $totalInStockUnits,
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
                'total_customers' => $totalCustomers,
                'total_suppliers' => $totalSuppliers,
                'total_outlets' => $totalOutlets,

                'monthly_trends' => [
                    'year' => $year,
                    'months' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    'sales' => $monthlySales,
                    'purchases' => $canViewCost ? $monthlyPurchases : array_fill(0, 12, 0.0),
                    'expenses' => $monthlyExpenses,
                    'net_profit' => $canViewCost ? $monthlyProfit : array_fill(0, 12, 0.0),
                ],

                'payment_methods' => $paymentMethods,
                'top_products' => $topProducts,
                'top_categories' => $categorySales,
                'recent_sales' => $recentSales,
                'low_stock_products' => $lowStockProducts,
            ],
        ];

        return $this->noStoreJson($responsePayload);
    }

    /**
     * Zero-cache mode compatibility hook.
     */
    public static function bumpCacheVersion(string $key): void
    {
        // Reports and lists always query live DB without versioned response caches.
    }

    /**
     * Zero-cache mode compatibility hook.
     */
    public static function invalidateDashboardCache(): void
    {
        // Reports and lists always query live DB without dashboard/report caches.
    }

    private function applyReportDateRange($query, ?string $startDate, ?string $endDate, string $column = 'date'): void
    {
        if (!empty($startDate)) {
            $trimmed = trim((string)$startDate);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
                $startVal = $trimmed . ' 00:00:00';
            } else {
                try {
                    $startVal = \Carbon\Carbon::parse($trimmed)
                        ->setTimezone(config('app.timezone'))
                        ->format('Y-m-d H:i:s');
                } catch (\Throwable $e) {
                    $startVal = str_replace('T', ' ', $trimmed);
                }
            }
            $query->where($column, '>=', $startVal);
        }

        if (!empty($endDate)) {
            $trimmed = trim((string)$endDate);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
                try {
                    $nextDay = \Carbon\Carbon::parse($trimmed)->addDay()->format('Y-m-d');
                    $query->where($column, '<', $nextDay);
                    return;
                } catch (\Throwable $e) {
                    $endVal = $trimmed . ' 23:59:59';
                }
            } else {
                try {
                    $endVal = \Carbon\Carbon::parse($trimmed)
                        ->setTimezone(config('app.timezone'))
                        ->format('Y-m-d H:i:s');
                } catch (\Throwable $e) {
                    $endVal = str_replace('T', ' ', $trimmed);
                }
            }
            $query->where($column, '<=', $endVal);
        }
    }

    public function salesReport(Request $request)
    {
        $includeItems = $request->boolean('include_items') || $request->query('include_items') === 'true' || $request->query('include_items') === '1';
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';

        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $baseQuery = Sale::query();
        if (!empty($allowedOutlets)) {
            $baseQuery->whereIn('warehouse_id', $allowedOutlets);
        }

        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate   = $request->input('end_date') ?? $request->input('to_date');
        $this->applyReportDateRange($baseQuery, $startDate, $endDate, 'date');

        $userFilter = $request->input('user_name') ?? $request->input('user') ?? $request->input('created_by');
        if (!empty($userFilter) && $userFilter !== 'All' && $userFilter !== 'all') {
            $baseQuery->where(function ($q) use ($userFilter) {
                $q->where('user_name', $userFilter)
                  ->orWhere('created_by', 'like', "%{$userFilter}%");
            });
        }

        if ($request->filled('user_id') && $request->input('user_id') !== 'All') {
            $baseQuery->where('user_id', $request->input('user_id'));
        }

        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse');
        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $baseQuery->where('warehouse_name', $warehouse);
        }

        if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'All') {
            $baseQuery->where('warehouse_id', $request->input('warehouse_id'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $baseQuery->where('status', $request->input('status'));
        } else {
            $this->applyActiveDocumentFilter($baseQuery, 'status');
        }

        if ($request->filled('payment_status') && $request->input('payment_status') !== 'All') {
            $baseQuery->where('payment_status', $request->input('payment_status'));
        }

        if ($request->filled('payment_type') && $request->input('payment_type') !== 'All') {
            $baseQuery->where('payment_type', $request->input('payment_type'));
        }

        if ($request->filled('customer_id') && $request->input('customer_id') !== 'All') {
            $baseQuery->where('customer_id', $request->input('customer_id'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $digits = preg_replace('/[^0-9]/', '', $search);
            $baseQuery->where(function ($q) use ($search, $digits) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('payment_type', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%");
                if (!empty($digits)) {
                    $q->orWhere('reference_no', 'like', "%{$digits}%");
                }
            });
        }

        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $knownTotal = $request->filled('known_total') ? max(0, (int)$request->input('known_total')) : null;

        $totalCount = 0;
        $totalRevenue = 0.0;
        $totalPaid = 0.0;
        $totalDue = 0.0;
        if (!$skipSummary && $page === 1) {
            $totals = (clone $baseQuery)->select(DB::raw('
                COUNT(*) as total_count,
                COALESCE(SUM(grand_total), 0) as total_revenue,
                COALESCE(SUM(paid_amount), 0) as total_paid,
                COALESCE(SUM(due_amount), 0) as total_due
            '))->first();

            $totalCount = (int)($totals->total_count ?? 0);
            $totalRevenue = (float)($totals->total_revenue ?? 0);
            $totalPaid = (float)($totals->total_paid ?? 0);
            $totalDue = (float)($totals->total_due ?? 0);
        } elseif ($knownTotal !== null) {
            $totalCount = $knownTotal;
        } else {
            $totalCount = (int)(clone $baseQuery)->count();
        }

        $selectCols = [
            'id', 'reference_no', 'customer_id', 'customer_name',
            'warehouse_id', 'warehouse_name', 'date', 'status',
            'tax_rate', 'tax_amount', 'discount', 'shipping', 'subtotal',
            'grand_total', 'paid_amount', 'due_amount', 'change_amount',
            'payment_status', 'payment_type', 'notes', 'pos_flag',
            'created_by', 'user_id', 'user_name', 'user_role',
            'created_at', 'updated_at',
        ];
        if ($this->accounting()->schemaHasColumn('sales', 'points_discount')) {
            $selectCols[] = 'points_discount';
            $selectCols[] = 'redeemed_points';
        }
        $listQuery = (clone $baseQuery)->select($selectCols);

        if ($includeItems) {
            $listQuery->with([
                'items:id,sale_id,product_id,product_variant_id,unit_id,unit_name,conversion_rate,product_code,product_name,unit_price,quantity,tax,discount,subtotal',
                'payments:id,sale_id,reference_no,payment_date,payment_type,amount,received_amount,change_amount',
            ]);
        }

        $rawCursor = $request->query('cursor');
        $cursor = ListCursorHelper::decode(
            is_scalar($rawCursor) ? (string)$rawCursor : null,
            'date',
            'desc',
            ['date', 'id']
        );
        if ($cursor !== null) {
            ListCursorHelper::apply($listQuery->toBase(), 'date', 'desc', $cursor);
        } elseif ($page > 1) {
            $listQuery->offset(($page - 1) * $perPage);
        }

        $rows = $listQuery->orderBy('date', 'desc')->orderBy('id', 'desc')->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $salesItems = $hasMore ? $rows->slice(0, $perPage)->values() : $rows->values();
        $lastRow = $salesItems->last();
        $nextCursor = ($hasMore && $lastRow)
            ? ListCursorHelper::encode($lastRow, 'date', 'desc', ['date', 'id'])
            : null;
        $lastPage = max(1, (int)ceil($totalCount / max(1, $perPage)));

        return $this->noStoreJson([
            'status' => 'success',
            'data' => [
                'total_sales_count' => $totalCount,
                'total_revenue' => $totalRevenue,
                'total_paid' => $totalPaid,
                'total_due' => $totalDue,
                'sales' => $salesItems,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $totalCount,
                    'last_page' => $lastPage,
                    'next_cursor' => $nextCursor,
                    'has_more' => $hasMore,
                ],
            ],
        ]);
    }

    public function purchasesReport(Request $request)
    {
        $includeItems = $request->boolean('include_items') || $request->query('include_items') === 'true' || $request->query('include_items') === '1';
        $includePayments = !$request->boolean('no_payments') && !$request->boolean('compact');
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';

        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        $baseQuery = Purchase::query();
        if (!empty($allowedOutlets)) {
            $baseQuery->whereIn('warehouse_id', $allowedOutlets);
        }

        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate = $request->input('end_date') ?? $request->input('to_date');

        $this->applyReportDateRange($baseQuery, $startDate ? (string)$startDate : null, $endDate ? (string)$endDate : null, 'date');

        $userFilter = $request->input('user_name') ?? $request->input('user') ?? $request->input('created_by');
        if (!empty($userFilter) && $userFilter !== 'All' && $userFilter !== 'all') {
            $baseQuery->where(function ($q) use ($userFilter) {
                $q->where('user_name', $userFilter)
                  ->orWhere('created_by', 'like', "%{$userFilter}%");
            });
        }

        if ($request->filled('user_id') && $request->input('user_id') !== 'All') {
            $baseQuery->where('user_id', $request->input('user_id'));
        }

        $supplierName = $request->input('supplier_name') ?? $request->input('supplier');
        if (!empty($supplierName) && $supplierName !== 'All' && $supplierName !== 'all') {
            $baseQuery->where('supplier_name', $supplierName);
        }

        if ($request->filled('supplier_id') && $request->input('supplier_id') !== 'All') {
            $baseQuery->where('supplier_id', $request->input('supplier_id'));
        }

        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse');
        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $baseQuery->where('warehouse_name', $warehouse);
        }

        if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'All') {
            $baseQuery->where('warehouse_id', $request->input('warehouse_id'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $baseQuery->where('status', $request->input('status'));
        } else {
            $baseQuery->whereIn('status', ['Received', 'Completed']);
        }

        if ($request->filled('payment_status') && $request->input('payment_status') !== 'All') {
            $baseQuery->where('payment_status', $request->input('payment_status'));
        }

        if ($request->filled('payment_type') && $request->input('payment_type') !== 'All') {
            $baseQuery->where('payment_type', $request->input('payment_type'));
        } else {
            $this->excludeOpeningStockPurchases($baseQuery);
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $digits = preg_replace('/[^0-9]/', '', $search);
            $baseQuery->where(function ($q) use ($search, $digits) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('supplier_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('payment_type', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%");
                if (!empty($digits)) {
                    $q->orWhere('reference_no', 'like', "%{$digits}%");
                }
            });
        }

        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $knownTotal = $request->filled('known_total') ? max(0, (int)$request->input('known_total')) : null;

        $totalCount = 0;
        $totalCost = 0.0;
        $totalPaid = 0.0;
        $totalDue = 0.0;
        if (!$skipSummary && $page === 1) {
            $totals = (clone $baseQuery)->select(DB::raw('
                COUNT(*) as total_count,
                COALESCE(SUM(grand_total), 0) as total_cost,
                COALESCE(SUM(paid_amount), 0) as total_paid,
                COALESCE(SUM(due_amount), 0) as total_due
            '))->first();

            $totalCount = (int)($totals->total_count ?? 0);
            $totalCost = (float)($totals->total_cost ?? 0);
            $totalPaid = (float)($totals->total_paid ?? 0);
            $totalDue = (float)($totals->total_due ?? 0);
        } elseif ($knownTotal !== null) {
            $totalCount = $knownTotal;
        } else {
            $totalCount = (int)(clone $baseQuery)->count();
        }

        $selectCols = [
            'id', 'reference_no', 'supplier_id', 'supplier_name',
            'warehouse_id', 'warehouse_name', 'date', 'status',
            'tax_rate', 'tax_amount', 'discount', 'shipping', 'is_tax_recoverable',
            'grand_total', 'paid_amount', 'due_amount', 'returned_credit_amount',
            'payment_status', 'payment_type', 'cash_register_id', 'notes',
            'created_by', 'user_id', 'user_name', 'user_role',
            'created_at', 'updated_at',
        ];
        if ($includePayments) {
            $selectCols[] = 'payments_payload';
        }
        $listQuery = (clone $baseQuery)->select($selectCols);

        if ($includeItems) {
            $listQuery->with([
                'items:id,purchase_id,product_id,product_variant_id,product_code,product_name,unit_id,unit_name,conversion_rate,unit_cost,quantity,base_quantity,base_unit_cost,tax,discount,subtotal',
            ]);
        }

        $rawCursor = $request->query('cursor');
        $cursor = ListCursorHelper::decode(
            is_scalar($rawCursor) ? (string)$rawCursor : null,
            'date',
            'desc',
            ['date', 'id']
        );
        if ($cursor !== null) {
            ListCursorHelper::apply($listQuery->toBase(), 'date', 'desc', $cursor);
        } elseif ($page > 1) {
            $listQuery->offset(($page - 1) * $perPage);
        }

        $rows = $listQuery->orderBy('date', 'desc')->orderBy('id', 'desc')->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $slicedRows = $hasMore ? $rows->slice(0, $perPage)->values() : $rows->values();
        $lastRow = $slicedRows->last();
        $nextCursor = ($hasMore && $lastRow)
            ? ListCursorHelper::encode($lastRow, 'date', 'desc', ['date', 'id'])
            : null;
        $lastPage = max(1, (int)ceil($totalCount / max(1, $perPage)));

        $purchaseRows = $slicedRows->each(function ($p) use ($accounting, $canViewCost, $includePayments) {
            if (!$includePayments) {
                $p->setAppends([]);
            }
            $accounting->maskPurchaseCostFields($p, $canViewCost);
        })->all();

        $maskedTotals = $accounting->maskFinancialSummary([
            'total_purchases_count' => $totalCount,
            'total_cost' => $totalCost,
            'total_paid' => $totalPaid,
            'total_due' => $totalDue,
        ], $canViewCost, ['total_purchases_count']);

        return $this->noStoreJson([
            'status' => 'success',
            'data' => [
                'total_purchases_count' => $maskedTotals['total_purchases_count'],
                'total_cost' => $maskedTotals['total_cost'],
                'total_paid' => $maskedTotals['total_paid'],
                'total_due' => $maskedTotals['total_due'],
                'cost_masked' => !$canViewCost,
                'purchases' => $purchaseRows,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $totalCount,
                    'last_page' => $lastPage,
                    'next_cursor' => $nextCursor,
                    'has_more' => $hasMore,
                ],
            ],
        ]);
    }

    /**
     * Live Zero-Cache Executive Expense Report Pipeline (SQL aggregation + cursor pagination)
     */
    public function expenseReport(Request $request)
    {
        $accounting = $this->accounting();
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';

        $baseQuery = Expense::query()
            ->where(function ($q) {
                $q->whereNull('payment_status')
                  ->orWhereNotIn('payment_status', [
                      'Pending', 'pending', 'PENDING',
                      'Draft', 'draft', 'DRAFT',
                      'Voided', 'voided', 'VOIDED',
                  ]);
            });
        if (!empty($allowedOutlets)) {
            $baseQuery->whereIn('warehouse_id', $allowedOutlets);
        }

        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate   = $request->input('end_date') ?? $request->input('to_date');
        $this->applyReportDateRange($baseQuery, $startDate, $endDate, 'date');

        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse');
        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $baseQuery->where('warehouse_name', $warehouse);
        }

        if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'All') {
            $baseQuery->where('warehouse_id', $request->input('warehouse_id'));
        }

        $category = $request->input('category_name') ?? $request->input('expense_category_name') ?? $request->input('category');
        if (!empty($category) && $category !== 'All' && $category !== 'all') {
            $baseQuery->where('expense_category_name', $category);
        }

        if ($request->filled('category_id') && $request->input('category_id') !== 'All') {
            $baseQuery->where('expense_category_id', $request->input('category_id'));
        }

        $userFilter = $request->input('user_name') ?? $request->input('user') ?? $request->input('created_by');
        if (!empty($userFilter) && $userFilter !== 'All' && $userFilter !== 'all') {
            $baseQuery->where(function ($q) use ($userFilter) {
                $q->where('user_name', $userFilter)
                  ->orWhere('created_by', 'like', "%{$userFilter}%");
            });
        }

        if ($request->filled('user_id') && $request->input('user_id') !== 'All') {
            $baseQuery->where('user_id', $request->input('user_id'));
        }

        $paymentMethod = $request->input('payment_method') ?? $request->input('payment_type');
        if (!empty($paymentMethod) && $paymentMethod !== 'All' && $paymentMethod !== 'all') {
            $baseQuery->where('payment_method', $paymentMethod);
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $digits = preg_replace('/[^0-9]/', '', $search);
            $baseQuery->where(function ($q) use ($search, $digits) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('expense_category_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('details', 'like', "%{$search}%");
                if (!empty($digits)) {
                    $q->orWhere('reference_no', 'like', "%{$digits}%");
                }
            });
        }

        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $knownTotal = $request->filled('known_total') ? max(0, (int)$request->input('known_total')) : null;

        $totalRecords = 0;
        $totalAmount = 0.0;
        $totalPaid = 0.0;
        $totalDue = 0.0;
        $cashPaid = 0.0;
        $digitalPaid = 0.0;
        $averageAmount = 0.0;
        $topCategoryName = 'None';
        $topCategoryAmount = 0.0;
        $byCategoryList = [];
        $byPaymentMethodList = [];

        if (!$skipSummary && $page === 1) {
            $totals = (clone $baseQuery)->select(DB::raw("
                COUNT(*) as total_count,
                COALESCE(SUM(amount), 0) as total_amount,
                COALESCE(SUM(CASE
                    WHEN LOWER(TRIM(COALESCE(payment_status, ''))) = 'unpaid' THEN 0
                    WHEN COALESCE(paid_amount, 0) > 0 THEN paid_amount
                    WHEN COALESCE(due_amount, 0) <= 0 AND LOWER(TRIM(COALESCE(payment_status, ''))) IN ('', 'paid') THEN amount
                    ELSE 0
                END), 0) as total_paid,
                COALESCE(SUM(COALESCE(due_amount, 0)), 0) as total_due,
                COALESCE(AVG(amount), 0) as average_amount
            "))->first();

            $totalRecords = (int)($totals->total_count ?? 0);
            $totalAmount = (float)($totals->total_amount ?? 0.0);
            $totalPaid = (float)($totals->total_paid ?? 0.0);
            $totalDue = (float)($totals->total_due ?? 0.0);
            $averageAmount = (float)($totals->average_amount ?? 0.0);

            $categoryBreakdown = (clone $baseQuery)->select(
                'expense_category_name',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(amount), 0) as total')
            )
            ->groupBy('expense_category_name')
            ->orderByDesc('total')
            ->get();

            if ($categoryBreakdown->isNotEmpty()) {
                $topCategoryName = $categoryBreakdown->first()->expense_category_name ?: 'General';
                $topCategoryAmount = (float)$categoryBreakdown->first()->total;
            }
            foreach ($categoryBreakdown as $cb) {
                $catTotal = (float)$cb->total;
                $byCategoryList[] = [
                    'category' => $cb->expense_category_name ?: 'General',
                    'expense_category_name' => $cb->expense_category_name ?: 'General',
                    'total' => $catTotal,
                    'total_amount' => $catTotal,
                    'count' => (int)$cb->count,
                    'percentage' => $totalAmount > 0 ? round(($catTotal / $totalAmount) * 100, 2) : 0.0,
                ];
            }

            $expIdsSub = (clone $baseQuery)->toBase();
            $expIdsSub->columns = ['id'];
            $expIdsSub->orders = null;

            $payLegAgg = DB::table('expense_payments')
                ->whereIn('expense_id', $expIdsSub)
                ->select(
                    DB::raw("COALESCE(NULLIF(TRIM(payment_type), ''), 'Cash') as method_name"),
                    DB::raw('COUNT(*) as count'),
                    DB::raw('COALESCE(SUM(amount), 0) as total')
                )
                ->groupBy('method_name')
                ->get();

            $legacyPaidAgg = (clone $baseQuery)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('expense_payments')
                      ->whereColumn('expense_payments.expense_id', 'expenses.id');
                })
                ->where(function ($q) {
                    $q->whereNull('payment_status')
                      ->orWhereNotIn('payment_status', ['unpaid', 'Unpaid', 'UNPAID']);
                })
                ->select(
                    DB::raw("COALESCE(NULLIF(TRIM(payment_method), ''), 'Cash') as method_name"),
                    DB::raw('COUNT(*) as count'),
                    DB::raw("COALESCE(SUM(CASE WHEN COALESCE(paid_amount, 0) > 0 THEN paid_amount WHEN COALESCE(due_amount, 0) <= 0 AND LOWER(TRIM(COALESCE(payment_status, ''))) IN ('', 'paid') THEN amount ELSE 0 END), 0) as total")
                )
                ->groupBy('method_name')
                ->get();

            $methodMap = [];
            foreach ($payLegAgg->concat($legacyPaidAgg) as $row) {
                $pmAmt = (float)$row->total;
                if ($pmAmt <= 0.0001) {
                    continue;
                }
                $mName = trim((string)$row->method_name);
                if (!isset($methodMap[$mName])) {
                    $methodMap[$mName] = ['count' => 0, 'total' => 0.0];
                }
                $methodMap[$mName]['count'] += (int)$row->count;
                $methodMap[$mName]['total'] += $pmAmt;
                if ($accounting->isCashPaymentType($mName)) {
                    $cashPaid += $pmAmt;
                } elseif (!in_array(strtolower($mName), ['', 'unpaid', 'n/a', 'split payment'], true)) {
                    $digitalPaid += $pmAmt;
                }
            }
            uasort($methodMap, static fn($a, $b) => $b['total'] <=> $a['total']);

            foreach ($methodMap as $mName => $mData) {
                $pmTotal = round((float)$mData['total'], 2);
                $byPaymentMethodList[] = [
                    'payment_method' => $mName,
                    'total'          => $pmTotal,
                    'total_amount'   => $pmTotal,
                    'count'          => (int)$mData['count'],
                    'percentage'     => $totalPaid > 0 ? round(($pmTotal / $totalPaid) * 100, 2) : 0.0,
                ];
            }
        } elseif ($knownTotal !== null) {
            $totalRecords = $knownTotal;
        } else {
            $totalRecords = (int)(clone $baseQuery)->count();
        }

        // Explicit column projection + keyset cursor support
        $sortBy = $request->input('sort_by', 'date');
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'date', 'amount', 'title', 'reference_no', 'warehouse_name', 'expense_category_name', 'created_by'];
        $sortCol = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'date';

        $rawCursor = $request->query('cursor');
        $cursor = ListCursorHelper::decode(
            is_scalar($rawCursor) ? (string)$rawCursor : null,
            $sortCol,
            $sortOrder,
            $allowedSorts,
            ['id'],
            ['amount']
        );
        if ($cursor !== null) {
            ListCursorHelper::apply($baseQuery->toBase(), $sortCol, $sortOrder, $cursor);
        } elseif ($page > 1) {
            $baseQuery->offset(($page - 1) * $perPage);
        }

        if ($sortCol !== 'id') {
            $baseQuery->orderBy($sortCol, $sortOrder)->orderBy('id', $sortOrder);
        } else {
            $baseQuery->orderBy('id', $sortOrder);
        }

        $expenseRows = $baseQuery->select([
            'id', 'reference_no', 'warehouse_id', 'warehouse_name',
            'expense_category_id', 'expense_category_name', 'title',
            'amount', 'paid_amount', 'due_amount', 'payment_status',
            'payment_method', 'date', 'details', 'created_by',
            'user_id', 'user_name', 'user_role', 'created_at', 'updated_at',
        ])->limit($perPage + 1)->get();

        $hasMore = $expenseRows->count() > $perPage;
        $expenses = $hasMore ? $expenseRows->slice(0, $perPage)->values() : $expenseRows->values();
        $lastRow = $expenses->last();
        $nextCursor = ($hasMore && $lastRow)
            ? ListCursorHelper::encode($lastRow, $sortCol, $sortOrder, $allowedSorts)
            : null;
        $lastPage = max(1, (int)ceil($totalRecords / max(1, $perPage)));

        $pagination = [
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $totalRecords,
            'last_page'    => $lastPage,
            'next_cursor'  => $nextCursor,
            'has_more'     => $hasMore,
        ];

        $summary = [
            'total_count' => $totalRecords,
            'total_amount' => $totalAmount,
            'total_paid' => round($totalPaid, 2),
            'total_due' => round($totalDue, 2),
            'cash_paid' => round($cashPaid, 2),
            'digital_paid' => round($digitalPaid, 2),
            'average_amount' => $averageAmount,
            'top_category_name' => $topCategoryName,
            'top_category_amount' => $topCategoryAmount,
        ];

        return $this->noStoreJson([
            'status' => 'success',
            'data' => [
                'total_expense' => $totalAmount,
                'total_count' => $totalRecords,
                'total_amount' => $totalAmount,
                'total_paid' => round($totalPaid, 2),
                'total_due' => round($totalDue, 2),
                'cash_paid' => round($cashPaid, 2),
                'digital_paid' => round($digitalPaid, 2),
                'average_amount' => $averageAmount,
                'top_category_name' => $topCategoryName,
                'top_category_amount' => $topCategoryAmount,
                'summary' => $summary,
                'pagination' => $pagination,
                'by_category' => $byCategoryList,
                'by_payment_method' => $byPaymentMethodList,
                'expenses' => $expenses,
            ],
            'pagination' => $pagination,
        ]);
    }

    /**
     * Live Zero-Cache Executive Customer Report Pipeline (SQL aggregation + per_page=50 pagination)
     */
    public function customerReport(Request $request)
    {
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';

        $salesQuery = Sale::query();
        $this->applyActiveDocumentFilter($salesQuery, 'status');
        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate   = $request->input('end_date') ?? $request->input('to_date');
        $this->applyReportDateRange($salesQuery, $startDate, $endDate, 'date');
        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse');
        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $salesQuery->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $salesQuery->whereIn('warehouse_id', $allowedOutlets);
        }

        $salesAgg = $salesQuery->select(
            DB::raw('COALESCE(customer_id, 1) as customer_id'),
            DB::raw('COUNT(*) as total_orders'),
            DB::raw('COALESCE(SUM(grand_total), 0) as total_spent'),
            DB::raw('COALESCE(SUM(paid_amount), 0) as total_paid'),
            DB::raw('COALESCE(SUM(due_amount), 0) as total_due'),
            DB::raw('MAX(date) as last_purchase_date')
        )->groupBy(DB::raw('COALESCE(customer_id, 1)'));

        $returnsQuery = SaleReturn::query();
        $this->applyActiveDocumentFilter($returnsQuery, 'status');
        $this->applyReportDateRange($returnsQuery, $startDate, $endDate, 'date');
        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $returnsQuery->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $returnsQuery->whereIn('warehouse_id', $allowedOutlets);
        }

        $returnsAgg = $returnsQuery->select(
            DB::raw('COALESCE(customer_id, 1) as customer_id'),
            DB::raw('COUNT(*) as total_returns'),
            DB::raw('COALESCE(SUM(grand_total), 0) as total_returned_amount'),
            DB::raw('COALESCE(SUM(paid_amount), 0) as total_refunded_amount'),
            DB::raw('COALESCE(SUM(due_amount), 0) as total_return_due')
        )->groupBy(DB::raw('COALESCE(customer_id, 1)'));

        $driver = DB::connection()->getDriverName();
        $codeExpr = $driver === 'sqlite'
            ? "COALESCE(customers.customer_id, 'CUST-' || printf('%03d', customers.id)) as code"
            : 'COALESCE(customers.customer_id, CONCAT("CUST-", LPAD(customers.id, 3, "0"))) as code';

        $tier = $request->input('tier') ?? $request->input('customer_group');
        $status = $request->input('status');
        $dueFilter = $request->input('due_filter');
        $search = $request->filled('search') ? trim((string)$request->input('search')) : '';
        $digits = $search !== '' ? preg_replace('/[^0-9]/', '', $search) : '';

        $applyCustomerFilters = function ($q) use ($tier, $status, $dueFilter, $search, $digits) {
            if (!empty($tier) && $tier !== 'All' && $tier !== 'all') {
                $q->where('customers.tier', $tier);
            }
            if (!empty($status) && $status !== 'All' && $status !== 'all') {
                $q->where('customers.status', $status);
            }
            if ($search !== '') {
                $q->where(function ($sub) use ($search, $digits) {
                    $sub->where('customers.name', 'like', "%{$search}%")
                        ->orWhere('customers.phone', 'like', "%{$search}%")
                        ->orWhere('customers.email', 'like', "%{$search}%")
                        ->orWhere('customers.tier', 'like', "%{$search}%");
                    if (!empty($digits)) {
                        $sub->orWhere('customers.phone', 'like', "%{$digits}%");
                    }
                });
            }
            if ($dueFilter === 'due_only' || $dueFilter === 'has_due') {
                $q->whereRaw('COALESCE(sa.total_due, 0) > 0');
            } elseif ($dueFilter === 'paid_only' || $dueFilter === 'fully_paid') {
                $q->whereRaw('COALESCE(sa.total_due, 0) <= 0');
            }
        };

        $joinedBase = DB::table('customers')
            ->leftJoinSub($salesAgg, 'sa', 'customers.id', '=', 'sa.customer_id')
            ->leftJoinSub($returnsAgg, 'ra', 'customers.id', '=', 'ra.customer_id');
        $applyCustomerFilters($joinedBase);

        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $knownTotal = $request->filled('known_total') ? max(0, (int)$request->input('known_total')) : null;

        $totalCustomers = 0;
        $totalRevenue = 0.0;
        $totalReturnedAmount = 0.0;
        $totalReturnDue = 0.0;
        $totalPaid = 0.0;
        $totalDue = 0.0;
        if (!$skipSummary && $page === 1) {
            $summaryRow = (clone $joinedBase)->selectRaw('
                COUNT(DISTINCT customers.id) as total_customers,
                COALESCE(SUM(COALESCE(sa.total_spent, 0)), 0) as total_revenue,
                COALESCE(SUM(COALESCE(ra.total_returned_amount, 0)), 0) as total_returned_amount,
                COALESCE(SUM(COALESCE(ra.total_return_due, 0)), 0) as total_return_due,
                COALESCE(SUM(COALESCE(sa.total_paid, 0)), 0) as total_paid,
                COALESCE(SUM(COALESCE(sa.total_due, 0)), 0) as total_due
            ')->first();

            $totalCustomers = (int)($summaryRow->total_customers ?? 0);
            $totalRevenue = (float)($summaryRow->total_revenue ?? 0);
            $totalReturnedAmount = (float)($summaryRow->total_returned_amount ?? 0);
            $totalReturnDue = (float)($summaryRow->total_return_due ?? 0);
            $totalPaid = (float)($summaryRow->total_paid ?? 0);
            $totalDue = (float)($summaryRow->total_due ?? 0);
        } elseif ($knownTotal !== null) {
            $totalCustomers = $knownTotal;
        } else {
            $totalCustomers = (int)(clone $joinedBase)->count('customers.id');
        }
        $avgSpent = $totalCustomers > 0 ? ($totalRevenue / $totalCustomers) : 0.0;

        $listQuery = (clone $joinedBase)->select(
            'customers.id',
            DB::raw($codeExpr),
            'customers.name',
            'customers.phone',
            'customers.email',
            'customers.tier',
            'customers.status',
            DB::raw('COALESCE(sa.total_orders, 0) as total_orders'),
            DB::raw('COALESCE(sa.total_spent, 0) as total_spent'),
            DB::raw('COALESCE(ra.total_returns, 0) as total_returns'),
            DB::raw('COALESCE(ra.total_returned_amount, 0) as total_returned_amount'),
            DB::raw('COALESCE(ra.total_refunded_amount, 0) as total_refunded_amount'),
            DB::raw('COALESCE(ra.total_return_due, 0) as total_return_due'),
            DB::raw('(COALESCE(sa.total_spent, 0) - COALESCE(ra.total_returned_amount, 0)) as net_spent'),
            DB::raw('COALESCE(sa.total_paid, 0) as total_paid'),
            DB::raw('COALESCE(sa.total_due, 0) as total_due'),
            DB::raw('sa.last_purchase_date as last_purchase_date')
        );

        $sortBy = $request->input('sort_by', 'total_spent');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['name', 'tier', 'total_orders', 'total_spent', 'total_returns', 'total_returned_amount', 'net_spent', 'total_paid', 'total_due', 'last_purchase_date'];
        if (in_array($sortBy, $allowedSorts, true)) {
            $listQuery->orderBy($sortBy, $sortOrder)->orderBy('customers.id', $sortOrder);
        } else {
            $listQuery->orderByDesc('total_spent')->orderBy('customers.id', 'desc');
        }

        $offset = ($page - 1) * $perPage;
        $rawRows = $listQuery->offset($offset)->limit($perPage + 1)->get();
        $hasMore = $rawRows->count() > $perPage;
        $pagedRows = $hasMore ? $rawRows->slice(0, $perPage)->values() : $rawRows->values();

        $customers = $pagedRows->map(function ($c) {
            $cDue    = (float)($c->total_due ?? 0);
            $cRetDue = (float)($c->total_return_due ?? 0);
            return [
                'id' => (int)$c->id,
                'code' => (string)($c->code ?? ('CUST-' . str_pad($c->id, 3, '0', STR_PAD_LEFT))),
                'name' => (string)$c->name,
                'phone' => (string)($c->phone ?? ''),
                'email' => (string)($c->email ?? ''),
                'tier' => (string)($c->tier ?? 'General'),
                'status' => (string)($c->status ?? 'Active'),
                'total_orders' => (int)($c->total_orders ?? 0),
                'total_spent' => (float)($c->total_spent ?? 0),
                'total_returns' => (int)($c->total_returns ?? 0),
                'total_returned_amount' => (float)($c->total_returned_amount ?? 0),
                'total_refunded_amount' => (float)($c->total_refunded_amount ?? 0),
                'total_return_due' => $cRetDue,
                'net_spent' => (float)($c->net_spent ?? 0),
                'total_paid' => (float)($c->total_paid ?? 0),
                'total_due' => $cDue,
                'net_due' => max(0.0, $cDue - $cRetDue),
                'last_purchase_date' => (string)($c->last_purchase_date ?? ''),
            ];
        })->all();

        $lastPage = max(1, (int)ceil($totalCustomers / max(1, $perPage)));
        $pagination = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $totalCustomers,
            'last_page' => $lastPage,
            'has_more' => $hasMore,
        ];

        $summary = [
            'total_customers' => $totalCustomers,
            'total_revenue' => $totalRevenue,
            'total_returned' => $totalReturnedAmount,
            'total_return_due' => $totalReturnDue,
            'net_revenue' => max(0.0, $totalRevenue - $totalReturnedAmount),
            'total_paid' => $totalPaid,
            'total_due' => $totalDue,
            'net_due' => max(0.0, $totalDue - $totalReturnDue),
            'average_spent' => $avgSpent,
        ];

        return $this->noStoreJson([
            'status' => 'success',
            'data' => [
                'total_customers' => $totalCustomers,
                'total_revenue' => $totalRevenue,
                'total_returned' => $totalReturnedAmount,
                'net_revenue' => max(0.0, $totalRevenue - $totalReturnedAmount),
                'total_paid' => $totalPaid,
                'total_due' => $totalDue,
                'average_spent' => $avgSpent,
                'summary' => $summary,
                'pagination' => $pagination,
                'customers' => $customers,
            ],
            'pagination' => $pagination,
        ]);
    }

    /**
     * Customer Account Statement & Sales Invoice Ledger (SQL totals + paginated rows, zero N+1)
     */
    public function customerStatement(Request $request, $id)
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return $this->noStoreJson([
                'status' => 'error',
                'message' => 'Customer not found',
            ], 404);
        }

        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse');
        $search = trim((string)$request->input('search', ''));
        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate = $request->input('end_date') ?? $request->input('to_date');
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';

        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $offset = ($page - 1) * $perPage;

        $salesQuery = DB::table('sales')
            ->where(function ($q) use ($id) {
                $q->where('customer_id', $id);
                if ((int)$id === 1) {
                    $q->orWhereNull('customer_id');
                }
            });
        $this->applyActiveDocumentFilter($salesQuery, 'status');

        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $salesQuery->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $salesQuery->whereIn('warehouse_id', $allowedOutlets);
        }
        $this->applyReportDateRange($salesQuery, $startDate, $endDate, 'date');

        if (!empty($search)) {
            $salesQuery->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('payment_type', 'like', "%{$search}%")
                  ->orWhere('payment_status', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%");
            });
        }

        $salesAgg = !$skipSummary && $page === 1
            ? (clone $salesQuery)->selectRaw('
                COUNT(*) as invoices_count,
                COALESCE(SUM(grand_total), 0) as total_invoiced,
                COALESCE(SUM(paid_amount), 0) as total_paid,
                COALESCE(SUM(due_amount), 0) as total_due
            ')->first()
            : null;

        $saleItemsCountSub = DB::table('sale_items')
            ->select('sale_id', DB::raw('COUNT(*) as items_count'))
            ->groupBy('sale_id');

        $rawInvoices = (clone $salesQuery)
            ->leftJoinSub($saleItemsCountSub, 'sic', 'sic.sale_id', '=', 'sales.id')
            ->select([
                'sales.id',
                'sales.reference_no',
                'sales.date',
                'sales.warehouse_name',
                'sales.grand_total',
                'sales.paid_amount',
                'sales.due_amount',
                'sales.payment_status',
                'sales.payment_type',
                DB::raw('COALESCE(sic.items_count, 0) as items_count'),
            ])
            ->orderBy('sales.date', 'desc')
            ->orderBy('sales.id', 'desc')
            ->offset($offset)
            ->limit($perPage + 1)
            ->get();

        $invoicesHasMore = $rawInvoices->count() > $perPage;
        $invoicesSlice = $invoicesHasMore ? $rawInvoices->slice(0, $perPage)->values() : $rawInvoices->values();
        $invoices = $invoicesSlice->map(function ($s) {
            return [
                'id' => (int)$s->id,
                'reference_no' => (string)$s->reference_no,
                'date' => (string)$s->date,
                'warehouse_name' => (string)($s->warehouse_name ?? 'Main Outlet'),
                'grand_total' => (float)$s->grand_total,
                'paid_amount' => (float)$s->paid_amount,
                'due_amount' => (float)$s->due_amount,
                'payment_status' => (string)$s->payment_status,
                'payment_type' => (string)$s->payment_type,
                'items_count' => (int)($s->items_count ?? 0),
            ];
        })->all();

        $returnsQuery = DB::table('sale_returns')
            ->where(function ($q) use ($id) {
                $q->where('customer_id', $id);
                if ((int)$id === 1) {
                    $q->orWhereNull('customer_id');
                }
            });
        $this->applyActiveDocumentFilter($returnsQuery, 'status');

        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $returnsQuery->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $returnsQuery->whereIn('warehouse_id', $allowedOutlets);
        }
        $this->applyReportDateRange($returnsQuery, $startDate, $endDate, 'date');

        if (!empty($search)) {
            $returnsQuery->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('sale_reference_no', 'like', "%{$search}%")
                  ->orWhere('refund_tender', 'like', "%{$search}%")
                  ->orWhere('return_reason', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%");
            });
        }

        $returnsAgg = !$skipSummary && $page === 1
            ? (clone $returnsQuery)->selectRaw('
                COUNT(*) as returns_count,
                COALESCE(SUM(grand_total), 0) as total_returned,
                COALESCE(SUM(paid_amount), 0) as total_refunded,
                COALESCE(SUM(due_amount), 0) as total_return_due
            ')->first()
            : null;

        $rawReturns = (clone $returnsQuery)
            ->select([
                'id', 'reference_no', 'sale_reference_no', 'date', 'warehouse_name',
                'return_reason', 'refund_tender', 'payment_type', 'grand_total',
                'paid_amount', 'due_amount', 'status', 'payment_status', 'items_payload',
            ])
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->offset($offset)
            ->limit($perPage + 1)
            ->get();

        $returnsHasMore = $rawReturns->count() > $perPage;
        $returnsSlice = $returnsHasMore ? $rawReturns->slice(0, $perPage)->values() : $rawReturns->values();
        $returns = $returnsSlice->map(function ($r) {
            $items = is_string($r->items_payload) ? json_decode($r->items_payload, true) : null;
            if (!is_array($items)) {
                $items = [];
            }
            return [
                'id' => (int)$r->id,
                'reference_no' => (string)$r->reference_no,
                'sale_reference_no' => (string)($r->sale_reference_no ?? ''),
                'date' => (string)$r->date,
                'warehouse_name' => (string)($r->warehouse_name ?? 'Main Outlet'),
                'return_reason' => (string)($r->return_reason ?? 'Customer Return'),
                'refund_tender' => (string)($r->refund_tender ?? ($r->payment_type ?? 'Cash')),
                'grand_total' => (float)$r->grand_total,
                'paid_amount' => (float)$r->paid_amount,
                'due_amount' => (float)$r->due_amount,
                'status' => (string)($r->status ?? 'Completed'),
                'payment_status' => (string)($r->payment_status ?? 'Paid'),
                'items_count' => !empty($items) ? count($items) : 1,
                'items' => $items,
            ];
        })->all();

        $invoicesCount = (int)($salesAgg->invoices_count ?? count($invoices));
        $totalInvoiced = (float)($salesAgg->total_invoiced ?? 0);
        $totalPaid = (float)($salesAgg->total_paid ?? 0);
        $totalDue = (float)($salesAgg->total_due ?? 0);

        $returnsCount = (int)($returnsAgg->returns_count ?? count($returns));
        $totalReturned = (float)($returnsAgg->total_returned ?? 0);
        $totalRefunded = (float)($returnsAgg->total_refunded ?? 0);
        $totalReturnDue = (float)($returnsAgg->total_return_due ?? 0);

        $netInvoiced = max(0.0, $totalInvoiced - $totalReturned);
        $netPaid = max(0.0, $totalPaid - $totalRefunded);
        $netDue = max(0.0, $totalDue - $totalReturnDue);
        $returnRatePct = $totalInvoiced > 0 ? round(($totalReturned / $totalInvoiced) * 100, 2) : 0.0;
        $paymentRatePct = $totalInvoiced > 0 ? round(($totalPaid / $totalInvoiced) * 100, 2) : 100.0;
        $averageOrderValue = $invoicesCount > 0 ? round($totalInvoiced / $invoicesCount, 2) : 0.0;

        $totalStatementCount = max($invoicesCount, $returnsCount);
        $pagination = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $totalStatementCount,
            'last_page' => max(1, (int)ceil($totalStatementCount / max(1, $perPage))),
            'has_more' => $invoicesHasMore || $returnsHasMore,
            'invoices_has_more' => $invoicesHasMore,
            'returns_has_more' => $returnsHasMore,
        ];

        return $this->noStoreJson([
            'status' => 'success',
            'data' => [
                'customer' => [
                    'id' => (int)$customer->id,
                    'code' => (string)($customer->customer_id ?? ('CUST-' . str_pad($customer->id, 3, '0', STR_PAD_LEFT))),
                    'name' => (string)$customer->name,
                    'phone' => (string)($customer->phone ?? ''),
                    'email' => (string)($customer->email ?? ''),
                    'tier' => (string)($customer->tier ?? 'General'),
                    'status' => (string)($customer->status ?? 'Active'),
                    'total_orders' => $invoicesCount,
                    'total_returns' => $returnsCount,
                    'total_spent' => $totalInvoiced,
                    'total_returned' => $totalReturned,
                    'net_spent' => $netInvoiced,
                    'total_paid' => $totalPaid,
                    'total_refunded' => $totalRefunded,
                    'net_paid' => $netPaid,
                    'total_due' => $totalDue,
                    'net_due' => $netDue,
                    'return_rate_pct' => $returnRatePct,
                ],
                'summary' => [
                    'invoices_count' => $invoicesCount,
                    'total_invoiced' => $totalInvoiced,
                    'total_paid' => $totalPaid,
                    'total_due' => $totalDue,
                    'returns_count' => $returnsCount,
                    'total_returned' => $totalReturned,
                    'total_refunded' => $totalRefunded,
                    'total_return_due' => $totalReturnDue,
                    'net_invoiced' => $netInvoiced,
                    'net_paid' => $netPaid,
                    'net_due' => $netDue,
                    'return_rate_pct' => $returnRatePct,
                    'payment_rate_pct' => $paymentRatePct,
                    'average_order_value' => $averageOrderValue,
                ],
                'pagination' => $pagination,
                'invoices' => $invoices,
                'returns' => $returns,
            ],
        ]);
    }

    /**
     * Ultra-fast High-Performance Executive Supplier Report Pipeline
     * File-based caching, single-pass SQL aggregation & sub-10ms response time
     */
    public function supplierReport(Request $request)
    {
        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';

        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate = $request->input('end_date') ?? $request->input('to_date');
        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse');

        $hasNullPoSup = DB::table('purchases')->whereNull('supplier_id')->exists();
        $hasNullPrSup = DB::table('purchase_returns')->whereNull('supplier_id')->exists();

        $resolvedSupPurchExpr = $hasNullPoSup
            ? "COALESCE(supplier_id, (SELECT s2.id FROM suppliers s2 WHERE LOWER(TRIM(s2.name)) = LOWER(TRIM(purchases.supplier_name)) ORDER BY s2.id ASC LIMIT 1))"
            : 'supplier_id';
        $resolvedSupPrExpr = $hasNullPrSup
            ? "COALESCE(supplier_id, (SELECT s2.id FROM suppliers s2 WHERE LOWER(TRIM(s2.name)) = LOWER(TRIM(purchase_returns.supplier_name)) ORDER BY s2.id ASC LIMIT 1))"
            : 'purchase_returns.supplier_id';

        $purchQuery = DB::table('purchases')->whereIn('status', ['Received', 'Completed']);
        $this->excludeOpeningStockPurchases($purchQuery);
        if (!empty($allowedOutlets)) {
            $purchQuery->whereIn('warehouse_id', $allowedOutlets);
        }
        $this->applyReportDateRange($purchQuery, $startDate ? (string)$startDate : null, $endDate ? (string)$endDate : null, 'date');
        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $purchQuery->where('warehouse_name', $warehouse);
        }

        $purchAgg = (clone $purchQuery)->select(
            DB::raw("{$resolvedSupPurchExpr} as resolved_supplier_id"),
            DB::raw('COUNT(*) as total_purchases_count'),
            DB::raw('COALESCE(SUM(grand_total), 0) as total_purchased_amount'),
            DB::raw('COALESCE(SUM(paid_amount), 0) as total_paid_amount'),
            DB::raw('COALESCE(SUM(due_amount), 0) as total_due'),
            DB::raw('MAX(date) as last_purchase_date')
        )->groupBy(DB::raw($resolvedSupPurchExpr));

        $hasReleasedAtCol = $accounting->schemaHasColumn('purchase_return_allocations', 'released_at');
        $praSub = DB::table('purchase_return_allocations')
            ->select('purchase_return_id', DB::raw('COALESCE(SUM(amount), 0) as alloc_amt'))
            ->when($hasReleasedAtCol, fn($q) => $q->whereNull('released_at'))
            ->groupBy('purchase_return_id');

        $unallocExpr = 'CASE WHEN (purchase_returns.due_amount - COALESCE(pra.alloc_amt, 0)) > 0 THEN (purchase_returns.due_amount - COALESCE(pra.alloc_amt, 0)) ELSE 0 END';
        $returnsQuery = DB::table('purchase_returns')
            ->leftJoinSub($praSub, 'pra', 'pra.purchase_return_id', '=', 'purchase_returns.id')
            ->whereIn('purchase_returns.status', ['Completed', 'Received']);
        if (!empty($allowedOutlets)) {
            $returnsQuery->whereIn('purchase_returns.warehouse_id', $allowedOutlets);
        }
        $this->applyReportDateRange($returnsQuery, $startDate ? (string)$startDate : null, $endDate ? (string)$endDate : null, 'purchase_returns.date');
        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $returnsQuery->where('purchase_returns.warehouse_name', $warehouse);
        }

        $returnsAgg = (clone $returnsQuery)->select(
            DB::raw("{$resolvedSupPrExpr} as resolved_supplier_id"),
            DB::raw('COUNT(*) as total_returns_count'),
            DB::raw('COALESCE(SUM(purchase_returns.grand_total), 0) as total_returned_amount'),
            DB::raw('COALESCE(SUM(purchase_returns.paid_amount), 0) as total_refunded_amount'),
            DB::raw('COALESCE(SUM(purchase_returns.due_amount), 0) as total_return_due'),
            DB::raw("COALESCE(SUM({$unallocExpr}), 0) as unallocated_return_credit")
        )->groupBy(DB::raw($resolvedSupPrExpr));

        $hasWarehouseFilter = !empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all';
        $hasStartDate = !empty($startDate);
        $hasEndDate = !empty($endDate);
        $isDateFiltered = $hasStartDate || $hasEndDate;
        $isFiltered = $isDateFiltered || $hasWarehouseFilter || !empty($allowedOutlets);

        $allPurchAgg = DB::table('purchases')->whereIn('status', ['Received', 'Completed']);
        $this->excludeOpeningStockPurchases($allPurchAgg);
        if (!empty($allowedOutlets)) {
            $allPurchAgg->whereIn('warehouse_id', $allowedOutlets);
        }
        $allPurchAgg = $allPurchAgg
            ->select(
                DB::raw("{$resolvedSupPurchExpr} as resolved_supplier_id"),
                DB::raw('COALESCE(SUM(due_amount), 0) as all_due')
            )
            ->groupBy(DB::raw($resolvedSupPurchExpr));

        $allReturnsAgg = DB::table('purchase_returns')
            ->leftJoinSub($praSub, 'pra', 'pra.purchase_return_id', '=', 'purchase_returns.id')
            ->whereIn('purchase_returns.status', ['Completed', 'Received'])
            ->when(!empty($allowedOutlets), fn($q) => $q->whereIn('purchase_returns.warehouse_id', $allowedOutlets))
            ->select(
                DB::raw("{$resolvedSupPrExpr} as resolved_supplier_id"),
                DB::raw("COALESCE(SUM({$unallocExpr}), 0) as all_unalloc_credit")
            )
            ->groupBy(DB::raw($resolvedSupPrExpr));

        $signedNetDueSql = $isFiltered
            ? '(COALESCE(pa.total_due, 0) - COALESCE(ra.unallocated_return_credit, 0))'
            : 'COALESCE(suppliers.balance_due, 0)';
        $openingAdjSql = $isFiltered
            ? '0'
            : '(COALESCE(suppliers.balance_due, 0) - (COALESCE(all_pa.all_due, 0) - COALESCE(all_ra.all_unalloc_credit, 0)))';
        $dueFormula = "CASE WHEN ({$signedNetDueSql}) < 0 THEN 0 ELSE ({$signedNetDueSql}) END";
        $creditBalFormula = "CASE WHEN ({$signedNetDueSql}) < 0 THEN ABS({$signedNetDueSql}) ELSE 0 END";

        $baseQuery = DB::table('suppliers')
            ->leftJoinSub($purchAgg, 'pa', 'suppliers.id', '=', 'pa.resolved_supplier_id')
            ->leftJoinSub($returnsAgg, 'ra', 'suppliers.id', '=', 'ra.resolved_supplier_id')
            ->leftJoinSub($allPurchAgg, 'all_pa', 'suppliers.id', '=', 'all_pa.resolved_supplier_id')
            ->leftJoinSub($allReturnsAgg, 'all_ra', 'suppliers.id', '=', 'all_ra.resolved_supplier_id');

        $category = $request->input('category') ?? $request->input('supplier_category');
        if (!empty($category) && $category !== 'All' && $category !== 'all') {
            $baseQuery->where('suppliers.category', $category);
        }

        $terms = $request->input('payment_terms') ?? $request->input('terms');
        if (!empty($terms) && $terms !== 'All' && $terms !== 'all') {
            $baseQuery->where('suppliers.payment_terms', $terms);
        }

        $status = $request->input('status');
        if (!empty($status) && $status !== 'All' && $status !== 'all') {
            $baseQuery->where('suppliers.status', $status);
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $digits = preg_replace('/[^0-9]/', '', $search);
            $baseQuery->where(function ($q) use ($search, $digits) {
                $q->where('suppliers.name', 'like', "%{$search}%")
                  ->orWhere('suppliers.contact_person', 'like', "%{$search}%")
                  ->orWhere('suppliers.phone', 'like', "%{$search}%")
                  ->orWhere('suppliers.email', 'like', "%{$search}%")
                  ->orWhere('suppliers.category', 'like', "%{$search}%");
                if (!empty($digits)) {
                    $q->orWhere('suppliers.phone', 'like', "%{$digits}%");
                }
            });
        }

        $dueFilter = $request->input('due_filter');
        $sortBy = $request->input('sort_by', 'total_purchased_amount');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = [
            'name', 'category', 'payment_terms', 'total_purchases_count',
            'total_purchased_amount', 'net_purchased_amount', 'total_returned_amount',
            'total_paid_amount', 'net_paid_amount', 'total_due', 'net_due', 'credit_balance', 'last_purchase_date'
        ];
        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $offset = ($page - 1) * $perPage;
        $knownTotal = $request->filled('known_total') ? max(0, (int)$request->input('known_total')) : null;

        $selectCols = [
            'suppliers.id',
            'suppliers.name',
            'suppliers.contact_person',
            'suppliers.phone',
            'suppliers.email',
            'suppliers.category',
            'suppliers.payment_terms',
            'suppliers.status',
            'suppliers.city',
            'suppliers.address',
            'suppliers.balance_due as ledger_balance_due',
            DB::raw("ROUND({$openingAdjSql}, 2) as opening_balance_due"),
            DB::raw("ROUND({$openingAdjSql}, 2) as opening_balance_adjustment"),
            DB::raw('COALESCE(pa.total_purchases_count, 0) as total_purchases_count'),
            DB::raw('COALESCE(pa.total_purchased_amount, 0) as total_purchased_amount'),
            DB::raw('COALESCE(pa.total_due, 0) as open_po_due'),
            DB::raw('COALESCE(ra.total_returns_count, 0) as total_returns_count'),
            DB::raw('COALESCE(ra.total_returned_amount, 0) as total_returned_amount'),
            DB::raw('COALESCE(ra.total_refunded_amount, 0) as total_refunded_amount'),
            DB::raw('COALESCE(ra.total_return_due, 0) as returned_credit'),
            DB::raw('COALESCE(ra.total_return_due, 0) as total_return_due'),
            DB::raw('COALESCE(ra.unallocated_return_credit, 0) as unallocated_credit'),
            DB::raw('(COALESCE(pa.total_purchased_amount, 0) - COALESCE(ra.total_returned_amount, 0)) as net_purchased_amount'),
            DB::raw('COALESCE(pa.total_paid_amount, 0) as total_paid_amount'),
            DB::raw('(COALESCE(pa.total_paid_amount, 0) - COALESCE(ra.total_refunded_amount, 0)) as net_paid_amount'),
            DB::raw("{$signedNetDueSql} as net_due"),
            DB::raw("{$creditBalFormula} as credit_balance"),
            DB::raw("{$dueFormula} as total_due"),
            DB::raw('COALESCE(pa.last_purchase_date, suppliers.updated_at) as last_purchase_date'),
        ];

        $totalSuppliers = 0;
        $totalPurchased = 0.0;
        $totalReturned = 0.0;
        $netPurchased = 0.0;
        $totalPaid = 0.0;
        $netPaid = 0.0;
        $totalDue = 0.0;
        $totalNetDue = 0.0;
        $totalCreditBalance = 0.0;
        $hasMore = false;

        if ($isDateFiltered) {
            $suppliersAll = (clone $baseQuery)->select($selectCols)->get();
            $rangeStartDt = $hasStartDate ? $accounting->normalizeFilterBoundaryDate((string)$startDate, false) : null;
            $rangeEndDt = $hasEndDate ? $accounting->normalizeFilterBoundaryDate((string)$endDate, true) : null;
            $closingCutoff = $rangeEndDt ?? '9999-12-31 23:59:59';

            $supIdByName = [];
            foreach ($suppliersAll as $supItem) {
                $nk = strtolower(trim((string)($supItem->name ?? '')));
                if ($nk !== '' && !isset($supIdByName[$nk])) {
                    $supIdByName[$nk] = (int)$supItem->id;
                }
            }

            $cumPoQuery = DB::table('purchases')->whereIn('status', ['Received', 'Completed']);
            $this->excludeOpeningStockPurchases($cumPoQuery);
            if (!empty($allowedOutlets)) {
                $cumPoQuery->whereIn('warehouse_id', $allowedOutlets);
            }
            if ($hasWarehouseFilter) {
                $cumPoQuery->where('warehouse_name', $warehouse);
            }
            if ($hasEndDate) {
                $this->applyReportDateRange($cumPoQuery, null, (string)$endDate, 'date');
            }
            $histPoRows = $cumPoQuery
                ->select(['id', 'supplier_id', 'supplier_name', 'date', 'grand_total', 'paid_amount', 'returned_credit_amount', 'payments_payload'])
                ->get();

            $cumPrQuery = DB::table('purchase_returns')->whereIn('status', ['Completed', 'Received']);
            if (!empty($allowedOutlets)) {
                $cumPrQuery->whereIn('warehouse_id', $allowedOutlets);
            }
            if ($hasWarehouseFilter) {
                $cumPrQuery->where('warehouse_name', $warehouse);
            }
            if ($hasEndDate) {
                $this->applyReportDateRange($cumPrQuery, null, (string)$endDate, 'date');
            }
            $histPrRows = $cumPrQuery
                ->select(['id', 'supplier_id', 'supplier_name', 'date', 'grand_total', 'paid_amount', 'payments_payload'])
                ->get();

            $allocMaps = $accounting->computeAsOfPurchaseReturnMaps(
                $histPoRows->pluck('id')->all(),
                $histPrRows->pluck('id')->all(),
                $closingCutoff
            );
            $poAllocAsOf = $allocMaps['po_alloc'];
            $poHasAnyAlloc = $allocMaps['po_has_any_alloc'];
            $prAllocAsOf = $allocMaps['pr_alloc'];

            $histPoBySup = [];
            foreach ($histPoRows as $po) {
                $sId = (int)($po->supplier_id ?? 0);
                if ($sId <= 0) {
                    $sId = $supIdByName[strtolower(trim((string)($po->supplier_name ?? '')))] ?? 0;
                }
                if ($sId <= 0) continue;
                $paidInPeriod = $accounting->sumPayloadPaymentsInRange(
                    $po->payments_payload ?? null,
                    (float)$po->paid_amount,
                    (string)$po->date,
                    $rangeStartDt,
                    $rangeEndDt
                );
                $paidClosing = $accounting->sumPayloadPaymentsAsOf(
                    $po->payments_payload ?? null,
                    (float)$po->paid_amount,
                    (string)$po->date,
                    $closingCutoff
                );
                $credit = isset($poHasAnyAlloc[$po->id])
                    ? (float)($poAllocAsOf[$po->id] ?? 0.0)
                    : (float)($po->returned_credit_amount ?? 0.0);
                $due = round(max(0.0, (float)$po->grand_total - $paidClosing - $credit), 2);
                if (!isset($histPoBySup[$sId])) {
                    $histPoBySup[$sId] = ['paid' => 0.0, 'due' => 0.0];
                }
                $histPoBySup[$sId]['paid'] += $paidInPeriod;
                $histPoBySup[$sId]['due'] += $due;
            }

            $histPrBySup = [];
            foreach ($histPrRows as $pr) {
                $sId = (int)($pr->supplier_id ?? 0);
                if ($sId <= 0) {
                    $sId = $supIdByName[strtolower(trim((string)($pr->supplier_name ?? '')))] ?? 0;
                }
                if ($sId <= 0) continue;
                $refundedInPeriod = $accounting->sumPayloadPaymentsInRange(
                    $pr->payments_payload ?? null,
                    (float)$pr->paid_amount,
                    (string)$pr->date,
                    $rangeStartDt,
                    $rangeEndDt
                );
                $refundedClosing = $accounting->sumPayloadPaymentsAsOf(
                    $pr->payments_payload ?? null,
                    (float)$pr->paid_amount,
                    (string)$pr->date,
                    $closingCutoff
                );
                $prDue = round(max(0.0, (float)$pr->grand_total - $refundedClosing), 2);
                $unalloc = round(max(0.0, $prDue - (float)($prAllocAsOf[$pr->id] ?? 0.0)), 2);
                if (!isset($histPrBySup[$sId])) {
                    $histPrBySup[$sId] = ['refunded' => 0.0, 'ret_due' => 0.0, 'unalloc' => 0.0];
                }
                $histPrBySup[$sId]['refunded'] += $refundedInPeriod;
                $histPrBySup[$sId]['ret_due'] += $prDue;
                $histPrBySup[$sId]['unalloc'] += $unalloc;
            }

            $openAdjustments = (!$hasWarehouseFilter && empty($allowedOutlets))
                ? $accounting->computeSupplierOpeningBalanceAdjustments($suppliersAll->pluck('id')->all(), $hasEndDate ? $closingCutoff : null)
                : [];

            foreach ($suppliersAll as $supRow) {
                $sId = (int)$supRow->id;
                $poAgg = $histPoBySup[$sId] ?? ['paid' => 0.0, 'due' => 0.0];
                $prAgg = $histPrBySup[$sId] ?? ['refunded' => 0.0, 'ret_due' => 0.0, 'unalloc' => 0.0];
                $openAdj = (!$hasWarehouseFilter && empty($allowedOutlets))
                    ? round((float)($openAdjustments[$sId]['opening_adjustment'] ?? 0.0), 2)
                    : 0.0;
                $signedNet = round($poAgg['due'] - $prAgg['unalloc'] + $openAdj, 2);

                $supRow->opening_balance_due = $openAdj;
                $supRow->opening_balance_adjustment = $openAdj;
                $supRow->total_paid_amount = round($poAgg['paid'], 2);
                $supRow->open_po_due = round($poAgg['due'], 2);
                $supRow->total_refunded_amount = round($prAgg['refunded'], 2);
                $supRow->returned_credit = round($prAgg['ret_due'], 2);
                $supRow->total_return_due = round($prAgg['ret_due'], 2);
                $supRow->unallocated_credit = round($prAgg['unalloc'], 2);
                $supRow->net_paid_amount = round($poAgg['paid'] - $prAgg['refunded'], 2);
                $supRow->net_due = $signedNet;
                $supRow->credit_balance = $signedNet < 0 ? round(abs($signedNet), 2) : 0.0;
                $supRow->total_due = $signedNet > 0 ? $signedNet : 0.0;
            }

            if ($dueFilter === 'due_only' || $dueFilter === 'has_due') {
                $suppliersAll = $suppliersAll->filter(fn($s) => (float)$s->net_due > 0.005)->values();
            } elseif ($dueFilter === 'paid_only' || $dueFilter === 'fully_paid') {
                $suppliersAll = $suppliersAll->filter(fn($s) => (float)$s->net_due <= 0.005)->values();
            }

            $totalSuppliers = $suppliersAll->count();
            if (!$skipSummary && $page === 1) {
                $totalPurchased = (float)$suppliersAll->sum('total_purchased_amount');
                $totalReturned = (float)$suppliersAll->sum('total_returned_amount');
                $netPurchased = (float)$suppliersAll->sum('net_purchased_amount');
                $totalPaid = (float)$suppliersAll->sum('total_paid_amount');
                $netPaid = (float)$suppliersAll->sum('net_paid_amount');
                $totalDue = (float)$suppliersAll->sum('total_due');
                $totalNetDue = (float)$suppliersAll->sum('net_due');
                $totalCreditBalance = (float)$suppliersAll->sum('credit_balance');
            }

            $sortField = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'total_purchased_amount';
            $suppliersAll = $suppliersAll->sortBy(
                fn($s) => $s->{$sortField},
                in_array($sortField, ['name', 'category', 'payment_terms', 'last_purchase_date'], true) ? SORT_NATURAL | SORT_FLAG_CASE : SORT_NUMERIC,
                $sortOrder === 'desc'
            )->values();

            $hasMore = ($offset + $perPage) < $totalSuppliers;
            $suppliers = $suppliersAll->slice($offset, $perPage)->values();
        } else {
            if ($dueFilter === 'due_only' || $dueFilter === 'has_due') {
                $baseQuery->whereRaw("{$signedNetDueSql} > 0.005");
            } elseif ($dueFilter === 'paid_only' || $dueFilter === 'fully_paid') {
                $baseQuery->whereRaw("{$signedNetDueSql} <= 0.005");
            }

            if (!$skipSummary && $page === 1) {
                $sumRow = (clone $baseQuery)->selectRaw("
                    COUNT(*) as total_suppliers,
                    COALESCE(SUM(COALESCE(pa.total_purchased_amount, 0)), 0) as total_purchased,
                    COALESCE(SUM(COALESCE(ra.total_returned_amount, 0)), 0) as total_returned,
                    COALESCE(SUM(COALESCE(pa.total_purchased_amount, 0) - COALESCE(ra.total_returned_amount, 0)), 0) as net_purchased,
                    COALESCE(SUM(COALESCE(pa.total_paid_amount, 0)), 0) as total_paid,
                    COALESCE(SUM(COALESCE(pa.total_paid_amount, 0) - COALESCE(ra.total_refunded_amount, 0)), 0) as net_paid,
                    COALESCE(SUM({$dueFormula}), 0) as total_due,
                    COALESCE(SUM({$signedNetDueSql}), 0) as net_due,
                    COALESCE(SUM({$creditBalFormula}), 0) as credit_balance
                ")->first();
                $totalSuppliers = (int)($sumRow->total_suppliers ?? 0);
                $totalPurchased = (float)($sumRow->total_purchased ?? 0);
                $totalReturned = (float)($sumRow->total_returned ?? 0);
                $netPurchased = (float)($sumRow->net_purchased ?? 0);
                $totalPaid = (float)($sumRow->total_paid ?? 0);
                $netPaid = (float)($sumRow->net_paid ?? 0);
                $totalDue = (float)($sumRow->total_due ?? 0);
                $totalNetDue = (float)($sumRow->net_due ?? 0);
                $totalCreditBalance = (float)($sumRow->credit_balance ?? 0);
            } elseif ($knownTotal !== null) {
                $totalSuppliers = $knownTotal;
            } else {
                $totalSuppliers = (int)(clone $baseQuery)->count('suppliers.id');
            }

            $listQ = (clone $baseQuery)->select($selectCols);
            if (in_array($sortBy, $allowedSorts, true)) {
                $listQ->orderBy($sortBy, $sortOrder)->orderBy('suppliers.id', $sortOrder);
            } else {
                $listQ->orderByDesc('total_purchased_amount')->orderBy('suppliers.id', 'desc');
            }

            $rawRows = $listQ->offset($offset)->limit($perPage + 1)->get();
            $hasMore = $rawRows->count() > $perPage;
            $suppliers = ($hasMore ? $rawRows->slice(0, $perPage) : $rawRows)->values();
        }

        $avgPurchased = $totalSuppliers > 0 ? ($totalPurchased / $totalSuppliers) : 0.0;
        $avgNetPurchased = $totalSuppliers > 0 ? ($netPurchased / $totalSuppliers) : 0.0;

        if (!$canViewCost) {
            $supMaskKeys = [
                'ledger_balance_due', 'opening_balance_due', 'opening_balance_adjustment',
                'total_purchased_amount', 'open_po_due', 'total_returned_amount',
                'total_refunded_amount', 'returned_credit', 'total_return_due',
                'unallocated_credit', 'net_purchased_amount', 'total_paid_amount',
                'net_paid_amount', 'net_due', 'credit_balance', 'total_due',
            ];
            $suppliers = $suppliers->map(function ($s) use ($supMaskKeys) {
                foreach ($supMaskKeys as $k) {
                    $s->{$k} = null;
                }
                return $s;
            })->values();
            $totalPurchased = $totalReturned = $netPurchased = $totalPaid = $netPaid = $totalDue = $totalNetDue = $totalCreditBalance = $avgPurchased = $avgNetPurchased = 0.0;
        }

        $lastPage = max(1, (int)ceil($totalSuppliers / max(1, $perPage)));
        $pagination = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $totalSuppliers,
            'last_page' => $lastPage,
            'has_more' => $hasMore,
        ];

        $summary = [
            'cost_masked' => !$canViewCost,
            'total_suppliers' => $totalSuppliers,
            'total_purchased' => $totalPurchased,
            'total_returned' => $totalReturned,
            'net_purchased' => $netPurchased,
            'total_paid' => $totalPaid,
            'net_paid' => $netPaid,
            'total_due' => $totalDue,
            'net_due' => round($totalNetDue, 2),
            'total_credit_balance' => round($totalCreditBalance, 2),
            'average_purchased' => $avgPurchased,
            'average_net_purchased' => $avgNetPurchased,
        ];

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'data' => [
                'cost_masked' => !$canViewCost,
                'total_suppliers' => $totalSuppliers,
                'total_purchased' => $totalPurchased,
                'total_returned' => $totalReturned,
                'net_purchased' => $netPurchased,
                'total_paid' => $totalPaid,
                'net_paid' => $netPaid,
                'total_due' => $totalDue,
                'net_due' => round($totalNetDue, 2),
                'total_credit_balance' => round($totalCreditBalance, 2),
                'average_purchased' => $avgPurchased,
                'average_net_purchased' => $avgNetPurchased,
                'summary' => $summary,
                'pagination' => $pagination,
                'suppliers' => $suppliers,
            ],
            'pagination' => $pagination,
        ]);
    }

    /**
     * Supplier Statement & Accounts Payable Ledger Drilldown (SQL totals + paginated rows, single history pass)
     */
    public function supplierStatement(Request $request, $id)
    {
        $supplier = Supplier::find($id);
        if (!$supplier) {
            return $this->noStoreJson([
                'status' => 'error',
                'message' => 'Supplier not found',
            ], 404);
        }

        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse');
        $search = trim((string)$request->input('search', ''));
        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate = $request->input('end_date') ?? $request->input('to_date');
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';

        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $offset = ($page - 1) * $perPage;

        $applySupMatch = function ($q) use ($id, $supplier) {
            $q->where('supplier_id', $id);
            if (!empty($supplier->name)) {
                $q->orWhere(function ($sq) use ($supplier) {
                    $sq->whereNull('supplier_id')->where('supplier_name', $supplier->name);
                });
            }
        };

        $purchQuery = DB::table('purchases')
            ->whereIn('status', ['Received', 'Completed'])
            ->where($applySupMatch);
        $this->excludeOpeningStockPurchases($purchQuery);
        if (!empty($allowedOutlets)) {
            $purchQuery->whereIn('warehouse_id', $allowedOutlets);
        }
        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $purchQuery->where('warehouse_name', $warehouse);
        }
        $this->applyReportDateRange($purchQuery, !empty($startDate) ? (string)$startDate : null, !empty($endDate) ? (string)$endDate : null, 'date');
        if (!empty($search)) {
            $purchQuery->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('payment_type', 'like', "%{$search}%")
                  ->orWhere('payment_status', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%");
            });
        }

        $purchItemCountSub = DB::table('purchase_items')
            ->select('purchase_id', DB::raw('COUNT(*) as items_count'))
            ->groupBy('purchase_id');

        $rawPurchasesPage = (clone $purchQuery)
            ->leftJoinSub($purchItemCountSub, 'pic', 'pic.purchase_id', '=', 'purchases.id')
            ->select([
                'purchases.id',
                'purchases.reference_no',
                'purchases.date',
                'purchases.warehouse_name',
                'purchases.grand_total',
                'purchases.paid_amount',
                'purchases.due_amount',
                'purchases.returned_credit_amount',
                'purchases.payments_payload',
                'purchases.status',
                'purchases.payment_status',
                'purchases.payment_type',
                DB::raw('COALESCE(pic.items_count, 0) as items_count'),
            ])
            ->orderBy('purchases.date', 'desc')
            ->orderBy('purchases.id', 'desc')
            ->offset($offset)
            ->limit($perPage + 1)
            ->get();

        $purchHasMore = $rawPurchasesPage->count() > $perPage;
        $rawPurchases = ($purchHasMore ? $rawPurchasesPage->slice(0, $perPage) : $rawPurchasesPage)->values();

        $returnsQuery = DB::table('purchase_returns')
            ->whereIn('status', ['Completed', 'Received'])
            ->when(!empty($allowedOutlets), fn($q) => $q->whereIn('warehouse_id', $allowedOutlets))
            ->where($applySupMatch);
        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $returnsQuery->where('warehouse_name', $warehouse);
        }
        $this->applyReportDateRange($returnsQuery, !empty($startDate) ? (string)$startDate : null, !empty($endDate) ? (string)$endDate : null, 'date');
        if (!empty($search)) {
            $returnsQuery->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('purchase_reference_no', 'like', "%{$search}%")
                  ->orWhere('payment_type', 'like', "%{$search}%")
                  ->orWhere('return_reason', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%");
            });
        }

        $rawReturnsPage = (clone $returnsQuery)
            ->select([
                'id', 'reference_no', 'purchase_reference_no', 'date', 'warehouse_name',
                'return_reason', 'payment_type', 'grand_total', 'paid_amount', 'due_amount',
                'status', 'payment_status', 'items_payload', 'payments_payload',
            ])
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->offset($offset)
            ->limit($perPage + 1)
            ->get();

        $returnsHasMore = $rawReturnsPage->count() > $perPage;
        $rawReturns = ($returnsHasMore ? $rawReturnsPage->slice(0, $perPage) : $rawReturnsPage)->values();

        $hasEndDate = !empty($endDate);
        $hasStartDate = !empty($startDate);
        $hasWarehouseFilter = !empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all';
        $isDateFiltered = $hasStartDate || $hasEndDate;
        $isFiltered = $isDateFiltered || $hasWarehouseFilter || !empty($search) || !empty($allowedOutlets);
        $asOfEnd = $hasEndDate ? $accounting->normalizeFilterBoundaryDate((string)$endDate, true) : null;

        // Compute summary totals cleanly via SQL or single cumulative pass BEFORE mapping paginated rows
        $poPeriodCount = 0;
        $prPeriodCount = 0;
        $totalPurchased = 0.0;
        $totalReturned = 0.0;
        $totalPaid = 0.0;
        $totalDue = 0.0;
        $totalRefunded = 0.0;
        $totalReturnDue = 0.0;
        $unallocatedReturnCredit = 0.0;
        $openingBalance = 0.0;
        $openingBalanceAdjustment = 0.0;
        $ledgerBalanceDue = (float)($supplier->balance_due ?? 0.0);
        $lastPurchaseDate = '';
        $pagePoMaps = ['po_alloc' => [], 'po_has_any_alloc' => [], 'pr_alloc' => []];

        if (!$skipSummary && $page === 1) {
            if ($isFiltered) {
                $rangeStartDt = $hasStartDate ? $accounting->normalizeFilterBoundaryDate((string)$startDate, false) : null;
                $rangeEndDt = $asOfEnd;
                $closingCutoff = $rangeEndDt ?? '9999-12-31 23:59:59';

                $periodPoAgg = (clone $purchQuery)->selectRaw('COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as grand_sum')->first();
                $poPeriodCount = (int)($periodPoAgg->cnt ?? 0);
                $totalPurchased = (float)($periodPoAgg->grand_sum ?? 0);

                $periodPrAgg = (clone $returnsQuery)->selectRaw('COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as grand_sum')->first();
                $prPeriodCount = (int)($periodPrAgg->cnt ?? 0);
                $totalReturned = (float)($periodPrAgg->grand_sum ?? 0);

                $cumPoQuery = DB::table('purchases')
                    ->whereIn('status', ['Received', 'Completed'])
                    ->where($applySupMatch);
                $this->excludeOpeningStockPurchases($cumPoQuery);
                if (!empty($allowedOutlets)) {
                    $cumPoQuery->whereIn('warehouse_id', $allowedOutlets);
                }
                if ($hasWarehouseFilter) {
                    $cumPoQuery->where('warehouse_name', $warehouse);
                }
                if ($hasEndDate) {
                    $this->applyReportDateRange($cumPoQuery, null, (string)$endDate, 'date');
                }
                $cumPoRows = $cumPoQuery->get(['id', 'date', 'grand_total', 'paid_amount', 'due_amount', 'returned_credit_amount', 'payments_payload']);

                $cumPrQuery = DB::table('purchase_returns')
                    ->whereIn('status', ['Completed', 'Received'])
                    ->when(!empty($allowedOutlets), fn($q) => $q->whereIn('warehouse_id', $allowedOutlets))
                    ->where($applySupMatch);
                if ($hasWarehouseFilter) {
                    $cumPrQuery->where('warehouse_name', $warehouse);
                }
                if ($hasEndDate) {
                    $this->applyReportDateRange($cumPrQuery, null, (string)$endDate, 'date');
                }
                $cumPrRows = $cumPrQuery->get(['id', 'date', 'grand_total', 'paid_amount', 'due_amount', 'payments_payload']);

                $cumMaps = $accounting->computeAsOfPurchaseReturnMaps(
                    $cumPoRows->pluck('id')->all(),
                    $cumPrRows->pluck('id')->all(),
                    $closingCutoff
                );
                if ($hasEndDate) {
                    $pagePoMaps = $cumMaps;
                }

                foreach ($cumPoRows as $cPo) {
                    $totalPaid += $accounting->sumPayloadPaymentsInRange(
                        $cPo->payments_payload ?? null,
                        (float)$cPo->paid_amount,
                        (string)$cPo->date,
                        $rangeStartDt,
                        $rangeEndDt
                    );
                    $paidClosing = $accounting->sumPayloadPaymentsAsOf(
                        $cPo->payments_payload ?? null,
                        (float)$cPo->paid_amount,
                        (string)$cPo->date,
                        $closingCutoff
                    );
                    $credit = isset($cumMaps['po_has_any_alloc'][$cPo->id])
                        ? (float)($cumMaps['po_alloc'][$cPo->id] ?? 0.0)
                        : (float)($cPo->returned_credit_amount ?? 0.0);
                    $totalDue += round(max(0.0, (float)$cPo->grand_total - $paidClosing - $credit), 2);
                }

                foreach ($cumPrRows as $cPr) {
                    $totalRefunded += $accounting->sumPayloadPaymentsInRange(
                        $cPr->payments_payload ?? null,
                        (float)$cPr->paid_amount,
                        (string)$cPr->date,
                        $rangeStartDt,
                        $rangeEndDt
                    );
                    $refundedClosing = $accounting->sumPayloadPaymentsAsOf(
                        $cPr->payments_payload ?? null,
                        (float)$cPr->paid_amount,
                        (string)$cPr->date,
                        $closingCutoff
                    );
                    $cPrDue = round(max(0.0, (float)$cPr->grand_total - $refundedClosing), 2);
                    $totalReturnDue += $cPrDue;
                    $unallocatedReturnCredit += round(max(0.0, $cPrDue - (float)($cumMaps['pr_alloc'][$cPr->id] ?? 0.0)), 2);
                }
                $totalPaid = round($totalPaid, 2);
                $totalDue = round($totalDue, 2);
                $totalRefunded = round($totalRefunded, 2);
                $totalReturnDue = round($totalReturnDue, 2);
                $unallocatedReturnCredit = round($unallocatedReturnCredit, 2);

                $openAdjMap = (!$hasWarehouseFilter && empty($allowedOutlets))
                    ? $accounting->computeSupplierOpeningBalanceAdjustments([$id], $hasEndDate ? $closingCutoff : null)
                    : [];
                $openingBalanceAdjustment = round((float)($openAdjMap[$id]['opening_adjustment'] ?? 0.0), 2);
                $openingBalance = ($hasWarehouseFilter || !empty($allowedOutlets)) ? 0.0 : $openingBalanceAdjustment;
            } else {
                $poSum = (clone $purchQuery)->selectRaw('
                    COUNT(*) as cnt,
                    COALESCE(SUM(grand_total), 0) as grand_sum,
                    COALESCE(SUM(paid_amount), 0) as paid_sum,
                    COALESCE(SUM(due_amount), 0) as due_sum
                ')->first();
                $poPeriodCount = (int)($poSum->cnt ?? 0);
                $totalPurchased = (float)($poSum->grand_sum ?? 0);
                $totalPaid = (float)($poSum->paid_sum ?? 0);
                $totalDue = (float)($poSum->due_sum ?? 0);

                $hasRel = $accounting->schemaHasColumn('purchase_return_allocations', 'released_at');
                $praSub = DB::table('purchase_return_allocations')
                    ->select('purchase_return_id', DB::raw('COALESCE(SUM(amount), 0) as allocated'))
                    ->when($hasRel, fn($q) => $q->whereNull('released_at'))
                    ->groupBy('purchase_return_id');
                $prSum = (clone $returnsQuery)
                    ->leftJoinSub($praSub, 'pra', 'pra.purchase_return_id', '=', 'purchase_returns.id')
                    ->selectRaw('
                        COUNT(*) as cnt,
                        COALESCE(SUM(purchase_returns.grand_total), 0) as grand_sum,
                        COALESCE(SUM(purchase_returns.paid_amount), 0) as paid_sum,
                        COALESCE(SUM(purchase_returns.due_amount), 0) as due_sum,
                        COALESCE(SUM(CASE WHEN (purchase_returns.due_amount - COALESCE(pra.allocated, 0)) > 0 THEN (purchase_returns.due_amount - COALESCE(pra.allocated, 0)) ELSE 0 END), 0) as unalloc_sum
                    ')->first();
                $prPeriodCount = (int)($prSum->cnt ?? 0);
                $totalReturned = (float)($prSum->grand_sum ?? 0);
                $totalRefunded = (float)($prSum->paid_sum ?? 0);
                $totalReturnDue = (float)($prSum->due_sum ?? 0);
                $unallocatedReturnCredit = round((float)($prSum->unalloc_sum ?? 0), 2);

                $openAdjMap = $accounting->computeSupplierOpeningBalanceAdjustments([$id], null);
                $openingBalanceAdjustment = round((float)($openAdjMap[$id]['opening_adjustment'] ?? 0.0), 2);
                $openingBalance = $openingBalanceAdjustment;
            }
        } else {
            if ($hasEndDate) {
                $pagePoMaps = $accounting->computeAsOfPurchaseReturnMaps(
                    $rawPurchases->pluck('id')->all(),
                    $rawReturns->pluck('id')->all(),
                    $asOfEnd
                );
            }
        }

        $purchases = $rawPurchases->map(function ($p) use ($hasEndDate, $asOfEnd, $pagePoMaps, $accounting) {
            $paid = $hasEndDate
                ? $accounting->sumPayloadPaymentsAsOf($p->payments_payload ?? null, (float)$p->paid_amount, (string)$p->date, $asOfEnd)
                : (float)$p->paid_amount;
            if ($hasEndDate) {
                $credit = isset($pagePoMaps['po_has_any_alloc'][$p->id])
                    ? (float)($pagePoMaps['po_alloc'][$p->id] ?? 0.0)
                    : (float)($p->returned_credit_amount ?? 0.0);
                $due = round(max(0.0, (float)$p->grand_total - $paid - $credit), 2);
                $pStatus = $accounting->derivePurchasePaymentStatus((float)$p->grand_total, $paid, $credit, $due);
            } else {
                $due = (float)$p->due_amount;
                $pStatus = (string)($p->payment_status ?? 'Paid');
            }
            return [
                'id' => (int)$p->id,
                'reference_no' => (string)$p->reference_no,
                'date' => (string)$p->date,
                'warehouse_name' => (string)($p->warehouse_name ?? 'Main Warehouse'),
                'grand_total' => (float)$p->grand_total,
                'paid_amount' => $paid,
                'due_amount' => $due,
                'status' => (string)($p->status ?? 'Received'),
                'payment_status' => $pStatus,
                'payment_type' => (string)($p->payment_type ?? 'Cash'),
                'items_count' => (int)($p->items_count ?? 0),
            ];
        });

        $returns = $rawReturns->map(function ($r) use ($hasEndDate, $asOfEnd, $accounting) {
            $items = !empty($r->items_payload) ? json_decode((string)$r->items_payload, true) : null;
            if (!is_array($items)) {
                $items = [];
            }
            $paid = $hasEndDate
                ? $accounting->sumPayloadPaymentsAsOf($r->payments_payload ?? null, (float)$r->paid_amount, (string)$r->date, $asOfEnd)
                : (float)$r->paid_amount;
            if ($hasEndDate) {
                $due = round(max(0.0, (float)$r->grand_total - $paid), 2);
                $pStatus = $accounting->deriveReturnPaymentStatus((float)$r->grand_total, $paid, $due);
            } else {
                $due = (float)$r->due_amount;
                $pStatus = (string)($r->payment_status ?? 'Paid');
            }
            return [
                'id' => (int)$r->id,
                'reference_no' => (string)$r->reference_no,
                'purchase_reference_no' => (string)($r->purchase_reference_no ?? ''),
                'date' => (string)$r->date,
                'warehouse_name' => (string)($r->warehouse_name ?? 'Main Warehouse'),
                'return_reason' => (string)($r->return_reason ?? 'Goods Return'),
                'refund_tender' => (string)($r->payment_type ?? 'Cash'),
                'grand_total' => (float)$r->grand_total,
                'paid_amount' => $paid,
                'due_amount' => $due,
                'status' => (string)($r->status ?? 'Completed'),
                'payment_status' => $pStatus,
                'items_count' => !empty($items) ? count($items) : 1,
                'items' => $items,
            ];
        });

        $signedNetDue = round($totalDue - $unallocatedReturnCredit + $openingBalance, 2);
        $positivePayable = round(max(0.0, $signedNetDue), 2);
        $creditBalance = round(max(0.0, -$signedNetDue), 2);
        $netPurchased = max(0.0, $totalPurchased - $totalReturned);
        $netPaid = max(0.0, $totalPaid - $totalRefunded);
        $returnRatePct = $totalPurchased > 0 ? round(($totalReturned / $totalPurchased) * 100, 2) : 0.0;
        $paymentRatePct = $totalPurchased > 0 ? round(($totalPaid / $totalPurchased) * 100, 2) : 100.0;
        $averagePurchaseValue = $poPeriodCount > 0 ? round($totalPurchased / $poPeriodCount, 2) : 0.0;
        $lastPurchaseDate = $purchases->isNotEmpty() ? $purchases->first()['date'] : ($supplier->updated_at ?? '');

        if (!$canViewCost) {
            $purchases = $purchases->map(function ($row) {
                return $this->maskAssocNumericKeys($row, ['grand_total', 'paid_amount', 'due_amount'], null);
            })->values();
            $returns = $returns->map(function ($row) {
                $masked = $this->maskAssocNumericKeys($row, ['grand_total', 'paid_amount', 'due_amount'], null);
                $masked['items'] = array_map(
                    fn($it) => is_array($it)
                        ? $this->maskAssocNumericKeys($it, ['unit_cost', 'base_unit_cost', 'tax', 'discount', 'subtotal', 'tax_exclusive_subtotal', 'landed_total', 'landed_base_unit_cost'], null)
                        : $it,
                    $masked['items'] ?? []
                );
                return $masked;
            })->values();
            $ledgerBalanceDue = $openingBalance = $openingBalanceAdjustment = $totalDue = $totalReturnDue = $unallocatedReturnCredit = 0.0;
            $totalPurchased = $netPurchased = $totalPaid = $signedNetDue = $creditBalance = $positivePayable = 0.0;
            $totalReturned = $totalRefunded = $netPaid = $returnRatePct = $paymentRatePct = $averagePurchaseValue = 0.0;
        }

        $totalCount = max($poPeriodCount, $prPeriodCount, count($purchases), count($returns));
        $pagination = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $totalCount,
            'last_page' => max(1, (int)ceil($totalCount / max(1, $perPage))),
            'has_more' => $purchHasMore || $returnsHasMore,
            'purchases_has_more' => $purchHasMore,
            'returns_has_more' => $returnsHasMore,
        ];

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'data' => [
                'cost_masked' => !$canViewCost,
                'supplier' => [
                    'id' => (int)$supplier->id,
                    'name' => (string)$supplier->name,
                    'contact_person' => (string)($supplier->contact_person ?? ''),
                    'phone' => (string)($supplier->phone ?? ''),
                    'email' => (string)($supplier->email ?? ''),
                    'category' => (string)($supplier->category ?? 'General'),
                    'payment_terms' => (string)($supplier->payment_terms ?? 'Net 30'),
                    'status' => (string)($supplier->status ?? 'Active'),
                    'city' => (string)($supplier->city ?? ''),
                    'address' => (string)($supplier->address ?? ''),
                    'ledger_balance_due' => $canViewCost ? $ledgerBalanceDue : null,
                    'opening_balance_due' => $canViewCost ? $openingBalance : null,
                    'opening_balance_adjustment' => $canViewCost ? $openingBalanceAdjustment : null,
                    'open_po_due' => $canViewCost ? round($totalDue, 2) : null,
                    'returned_credit' => $canViewCost ? round($totalReturnDue, 2) : null,
                    'unallocated_credit' => $canViewCost ? $unallocatedReturnCredit : null,
                    'total_purchases_count' => $poPeriodCount,
                    'total_purchased_amount' => $canViewCost ? ($isFiltered ? $totalPurchased : ($totalPurchased > 0 ? $totalPurchased : (float)($supplier->total_purchased ?? 0))) : null,
                    'net_purchased_amount' => $canViewCost ? $netPurchased : null,
                    'total_paid_amount' => $canViewCost ? $totalPaid : null,
                    'net_due' => $canViewCost ? $signedNetDue : null,
                    'credit_balance' => $canViewCost ? $creditBalance : null,
                    'total_due' => $canViewCost ? $positivePayable : null,
                    'last_purchase_date' => $lastPurchaseDate,
                ],
                'summary' => [
                    'cost_masked' => !$canViewCost,
                    'purchases_count' => $poPeriodCount,
                    'total_purchased' => $totalPurchased,
                    'total_paid' => $totalPaid,
                    'total_due' => $positivePayable,
                    'open_po_due' => round($totalDue, 2),
                    'returns_count' => $prPeriodCount,
                    'total_returned' => $totalReturned,
                    'total_refunded' => $totalRefunded,
                    'total_return_due' => $totalReturnDue,
                    'returned_credit' => $totalReturnDue,
                    'unallocated_credit' => $unallocatedReturnCredit,
                    'net_purchased' => $netPurchased,
                    'net_paid' => $netPaid,
                    'net_due' => $signedNetDue,
                    'credit_balance' => $creditBalance,
                    'opening_balance' => $openingBalance,
                    'opening_balance_adjustment' => $openingBalanceAdjustment,
                    'return_rate_pct' => $returnRatePct,
                    'payment_rate_pct' => $paymentRatePct,
                    'average_purchase_value' => $averagePurchaseValue,
                ],
                'pagination' => $pagination,
                'purchases' => $purchases->all(),
                'returns' => $returns->all(),
            ],
        ]);
    }

    public function stockReport(Request $request)
    {
        $warehouseParam = $request->input('warehouse_id') ?? $request->input('outlet_id');
        $warehouseId = ($warehouseParam && $warehouseParam !== 'All' && $warehouseParam !== 'all')
            ? $this->resolveWarehouseIdParam((string)$warehouseParam)
            : null;
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        if (!empty($allowedOutlets) && $warehouseId !== null && !in_array($warehouseId, $allowedOutlets, true)) {
            return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
        }

        $category = $request->input('category', 'All');
        $brand = $request->input('brand', 'All');
        $stockStatus = $request->input('stock_status', 'All');
        $search = trim((string)$request->input('search', ''));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $page = max(1, (int)$request->input('page', 1));
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;

        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);

        $query = Product::query()
            ->select([
                'id', 'uuid', 'code', 'sku', 'barcode', 'name', 'alternative_name',
                'category_name', 'brand_name', 'unit_name',
                'cost_price', 'selling_price', 'purchase_price', 'retail_price',
                'stock_quantity', 'alert_quantity', 'stock_alert', 'low_qty',
                'has_variants', 'image_url', 'status', 'is_active',
            ])
            ->with([
                'variants:id,product_id,name,sku,cost_price,selling_price,is_active,sort_order',
                'variants.values:id,product_variant_id,attribute_name,attribute_value',
                'variants.stockBalances' => function ($q) use ($warehouseId, $allowedOutlets) {
                    $q->select(['id', 'product_variant_id', 'warehouse_id', 'quantity']);
                    if ($warehouseId !== null) {
                        $q->where('warehouse_id', $warehouseId);
                    } elseif (!empty($allowedOutlets)) {
                        $q->whereIn('warehouse_id', $allowedOutlets);
                    }
                },
                'stockBalances' => function ($q) use ($warehouseId, $allowedOutlets) {
                    $q->select(['id', 'product_id', 'warehouse_id', 'product_variant_id', 'quantity']);
                    if ($warehouseId !== null) {
                        $q->where('warehouse_id', $warehouseId);
                    } elseif (!empty($allowedOutlets)) {
                        $q->whereIn('warehouse_id', $allowedOutlets);
                    }
                },
            ]);

        if ($warehouseId !== null) {
            $query->withSum(['stockBalances as warehouse_stock' => function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            }], 'quantity');
        } elseif (!empty($allowedOutlets)) {
            $query->withSum(['stockBalances as warehouse_stock' => function ($q) use ($allowedOutlets) {
                $q->whereIn('warehouse_id', $allowedOutlets);
            }], 'quantity');
            $query->where(function ($wq) use ($allowedOutlets) {
                $wq->whereHas('stockBalances', function ($q) use ($allowedOutlets) {
                    $q->whereIn('warehouse_id', $allowedOutlets);
                })->orWhereHas('variants.stockBalances', function ($q) use ($allowedOutlets) {
                    $q->whereIn('warehouse_id', $allowedOutlets);
                });
            });
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('code', $search)
                  ->orWhere('sku', $search)
                  ->orWhere('barcode', $search)
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('alternative_name', 'like', "%{$search}%")
                  ->orWhere('brand_name', 'like', "%{$search}%")
                  ->orWhere('category_name', 'like', "%{$search}%")
                  ->orWhereIn('id', function ($sub) use ($search) {
                      $sub->select('product_id')
                          ->from('product_variants')
                          ->where('name', 'like', "%{$search}%")
                          ->orWhere('sku', 'like', "%{$search}%")
                          ->orWhere('barcode', 'like', "%{$search}%");
                  })
                  ->orWhereIn('id', function ($sub) use ($search) {
                      $sub->select('pv.product_id')
                          ->from('product_variants as pv')
                          ->join('product_variant_values as pvv', 'pv.id', '=', 'pvv.product_variant_id')
                          ->where('pvv.attribute_value', 'like', "%{$search}%")
                          ->orWhere('pvv.attribute_name', 'like', "%{$search}%");
                  });
            });
        }

        if ($category !== 'All' && !empty($category)) {
            $query->where('category_name', $category);
        }

        if ($brand !== 'All' && !empty($brand)) {
            $query->where('brand_name', $brand);
        }

        if ($stockStatus !== 'All' && !empty($stockStatus)) {
            if ($stockStatus === 'in_stock') {
                if ($warehouseId !== null) {
                    $query->whereHas('stockBalances', function ($q) use ($warehouseId) {
                        $q->where('warehouse_id', $warehouseId)->where('quantity', '>', 0);
                    });
                } else {
                    $query->where('stock_quantity', '>', 0);
                }
            } else if ($stockStatus === 'out_of_stock') {
                if ($warehouseId !== null) {
                    $query->whereDoesntHave('stockBalances', function ($q) use ($warehouseId) {
                        $q->where('warehouse_id', $warehouseId)->where('quantity', '>', 0);
                    });
                } else {
                    $query->where('stock_quantity', '<=', 0);
                }
            } else if ($stockStatus === 'low_stock_only') {
                if ($warehouseId !== null) {
                    $query->where(function ($sq) use ($warehouseId) {
                        $sq->whereHas('stockBalances', function ($q) use ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId)
                              ->where('quantity', '>', 0)
                              ->whereRaw('quantity <= COALESCE((SELECT alert_quantity FROM products WHERE products.id = stock_balances.product_id), 10)');
                        })
                        ->orWhereHas('variants', function ($vq) use ($warehouseId) {
                            $vq->whereHas('stockBalances', function ($sbq) use ($warehouseId) {
                                $sbq->where('warehouse_id', $warehouseId)
                                    ->where('quantity', '>', 0)
                                    ->whereRaw('quantity <= 10');
                            });
                        });
                    });
                } else {
                    $query->where(function ($q) {
                        $q->where('stock_quantity', '>', 0)
                          ->where(function ($sub) {
                              $sub->whereColumn('stock_quantity', '<=', 'alert_quantity')
                                  ->orWhereColumn('stock_quantity', '<=', 'stock_alert')
                                  ->orWhereColumn('stock_quantity', '<=', 'low_qty')
                                  ->orWhere('stock_quantity', '<=', 10);
                          })
                          ->orWhereHas('variants', function ($vq) {
                              $vq->whereHas('stockBalances', function ($sbq) {
                                  $sbq->select('product_variant_id')
                                      ->groupBy('product_variant_id')
                                      ->havingRaw('COALESCE(SUM(quantity), 0) > 0 AND COALESCE(SUM(quantity), 0) <= 10');
                              });
                          });
                    });
                }
            } else if ($stockStatus === 'low_stock') {
                if ($warehouseId !== null) {
                    $query->where(function ($sq) use ($warehouseId) {
                        $sq->whereHas('stockBalances', function ($q) use ($warehouseId) {
                            $q->where('warehouse_id', $warehouseId)
                              ->whereRaw('quantity <= COALESCE((SELECT alert_quantity FROM products WHERE products.id = stock_balances.product_id), 10)');
                        })
                        ->orWhereHas('variants', function ($vq) use ($warehouseId) {
                            $vq->whereHas('stockBalances', function ($sbq) use ($warehouseId) {
                                $sbq->where('warehouse_id', $warehouseId)
                                    ->whereRaw('quantity <= 10');
                            });
                        })
                        ->orWhere('stock_quantity', '<=', 0);
                    });
                } else {
                    $query->where(function ($q) {
                        $q->whereColumn('stock_quantity', '<=', 'alert_quantity')
                          ->orWhereColumn('stock_quantity', '<=', 'stock_alert')
                          ->orWhereColumn('stock_quantity', '<=', 'low_qty')
                          ->orWhere('stock_quantity', '<=', 10)
                          ->orWhereHas('variants', function ($vq) {
                              $vq->whereHas('stockBalances', function ($sbq) {
                                  $sbq->select('product_variant_id')
                                      ->groupBy('product_variant_id')
                                      ->havingRaw('COALESCE(SUM(quantity), 0) <= 10');
                              });
                          });
                    });
                }
            }
        }

        $baseQuery = clone $query;
        $hasProductFilters = !empty($search)
            || ($category !== 'All' && !empty($category))
            || ($brand !== 'All' && !empty($brand))
            || ($stockStatus !== 'All' && !empty($stockStatus));

        $totalProducts = 0;
        $totalQuantity = 0.0;
        $masterTotalCost = 0.0;
        $fifoBatchTotalCost = 0.0;
        $totalCost = 0.0;
        $totalRetail = 0.0;
        $maskedStockSummary = [];

        if (!$skipSummary) {
            // Authoritative Valuation via SQL-level FIFO Batch Subledger (plus Master & Variant Reconciliation)
            if ($hasProductFilters) {
                $matchingQuery = clone $baseQuery;
                $matchingQuery->setEagerLoads([])->reorder();
                $matchingQuery->getQuery()->columns = null;
                $matchingIds = $matchingQuery->pluck('id')->map(fn($id) => (int)$id)->all();
                $totalProducts = count($matchingIds);
            } else {
                $countQuery = clone $baseQuery;
                $countQuery->setEagerLoads([])->reorder();
                $countQuery->getQuery()->columns = null;
                $totalProducts = (int)$countQuery->count();
                $matchingIds = null;
            }

            $valuation = app(InventoryReconciliationService::class)->computeValuationSummary(
                $warehouseId !== null ? (int)$warehouseId : null,
                !empty($allowedOutlets) ? $allowedOutlets : null,
                $matchingIds
            );

            $totalQuantity = round((float)$valuation['total_book_qty'], 4);
            $masterTotalCost = round((float)$valuation['master_valuation'], 2);
            $fifoBatchTotalCost = round((float)$valuation['fifo_batch_valuation'], 2);
            $totalCost = round((float)$valuation['effective_valuation'], 2);
            $totalRetail = round((float)$valuation['retail_valuation'], 2);

            if ($warehouseId !== null || !empty($allowedOutlets)) {
                $totalsQ = StockBalance::query()
                    ->join('products', 'stock_balances.product_id', '=', 'products.id')
                    ->leftJoin('product_variants', 'stock_balances.product_variant_id', '=', 'product_variants.id')
                    ->whereNull('products.deleted_at');
                if ($hasProductFilters) {
                    $subQuery = clone $baseQuery;
                    $subQuery->setEagerLoads([])->reorder();
                    $subQuery->getQuery()->columns = null;
                    $totalsQ->whereIn('stock_balances.product_id', $subQuery->select('id'));
                }
                if ($warehouseId !== null) {
                    $totalsQ->where('stock_balances.warehouse_id', $warehouseId);
                } else {
                    $totalsQ->whereIn('stock_balances.warehouse_id', $allowedOutlets);
                }
                $totals = $totalsQ->selectRaw('
                        COALESCE(SUM(CASE WHEN COALESCE(products.alert_quantity, 10) > stock_balances.quantity THEN COALESCE(products.alert_quantity, 10) - stock_balances.quantity ELSE 0 END), 0) as total_deficit_qty,
                        COALESCE(SUM((CASE WHEN COALESCE(products.alert_quantity, 10) > stock_balances.quantity THEN COALESCE(products.alert_quantity, 10) - stock_balances.quantity ELSE 0 END) * COALESCE(product_variants.cost_price, products.cost_price, products.purchase_price, 0)), 0) as estimated_restock_cost
                    ')
                    ->first();
            } else {
                $summaryAggQuery = clone $baseQuery;
                $summaryAggQuery->setEagerLoads([])->reorder();
                $summaryAggQuery->getQuery()->columns = null;
                $summaryAgg = $summaryAggQuery
                    ->select(DB::raw('
                        COALESCE(SUM(CASE WHEN COALESCE(alert_quantity, 10) > stock_quantity THEN COALESCE(alert_quantity, 10) - stock_quantity ELSE 0 END), 0) as total_deficit_qty,
                        COALESCE(SUM((CASE WHEN COALESCE(alert_quantity, 10) > stock_quantity THEN COALESCE(alert_quantity, 10) - stock_quantity ELSE 0 END) * COALESCE(cost_price, purchase_price, 0)), 0) as estimated_restock_cost
                    '))
                    ->first();
            }

            if ($hasProductFilters || !empty($allowedOutlets)) {
                $varSubQuery = clone $baseQuery;
                $varSubQuery->setEagerLoads([])->reorder();
                $varSubQuery->getQuery()->columns = null;
                $totalVariants = (int)DB::table('product_variants')
                    ->whereIn('product_id', $varSubQuery->select('id'))
                    ->count();
            } else {
                $totalVariants = (int)DB::table('product_variants')
                    ->join('products', 'products.id', '=', 'product_variants.product_id')
                    ->whereNull('products.deleted_at')
                    ->count();
            }
            $totalSkus = $totalProducts + $totalVariants;

            $grossMarginPercent = $totalRetail > 0 ? round((($totalRetail - $totalCost) / $totalRetail) * 100, 2) : 0;
            $deficitQty = (float)($totals->total_deficit_qty ?? ($summaryAgg->total_deficit_qty ?? 0));
            $restockCost = (float)($totals->estimated_restock_cost ?? ($summaryAgg->estimated_restock_cost ?? 0));

            $maskedStockSummary = $accounting->maskFinancialSummary([
                'total_products' => $totalProducts,
                'total_skus' => $totalSkus,
                'total_quantity' => $totalQuantity,
                'total_cost_value' => $totalCost,
                'total_fifo_cost_value' => $fifoBatchTotalCost,
                'total_master_cost_value' => $masterTotalCost,
                'valuation_variance' => round($fifoBatchTotalCost - $masterTotalCost, 2),
                'total_retail_value' => $totalRetail,
                'gross_margin_percent' => $grossMarginPercent,
                'total_deficit_quantity' => $deficitQty,
                'estimated_restock_cost' => $restockCost,
            ], $canViewCost, ['total_products', 'total_skus', 'total_quantity', 'total_retail_value', 'total_deficit_quantity']);
        } elseif ($knownTotal !== null) {
            $totalProducts = $knownTotal;
        } else {
            $countQuery = clone $baseQuery;
            $countQuery->setEagerLoads([])->reorder();
            $countQuery->getQuery()->columns = null;
            $totalProducts = (int)$countQuery->count();
        }

        // Fast Slice Pagination (No duplicate COUNT(*)) & Dedicated StockReportResource
        if ($warehouseId !== null) {
            $query->orderBy('warehouse_stock', 'asc')->orderBy('id', 'asc');
        } else {
            $query->orderBy('stock_quantity', 'asc')->orderBy('id', 'asc');
        }
        $rows = $query->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $pageItems = $hasMore ? $rows->slice(0, $perPage)->values() : $rows->values();
        $lastPage = max(1, (int)ceil($totalProducts / max(1, $perPage)));

        $responsePayload = [
            'status' => 'success',
            'data' => [
                'total_products' => $totalProducts,
                'total_quantity' => $totalQuantity,
                'total_stock_value_cost' => $canViewCost ? $totalCost : 0.0,
                'total_fifo_cost_value' => $canViewCost ? $fifoBatchTotalCost : 0.0,
                'total_master_cost_value' => $canViewCost ? $masterTotalCost : 0.0,
                'total_stock_value_retail' => $totalRetail,
                'cost_masked' => !$canViewCost,
                'summary' => $maskedStockSummary,
                'items' => StockReportResource::collection($pageItems)->toArray($request),
            ],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalProducts,
                'last_page' => $lastPage,
                'has_more' => $hasMore,
            ],
        ];

        return $this->noStoreJson($responsePayload);
    }

    /**
     * Streamed UTF-8 Comma-Delimited CSV Export for Stock & Low Stock Report
     */
    public function exportStock(Request $request)
    {
        $warehouse = $request->input('warehouse_id', 'All');
        $warehouseId = $this->resolveWarehouseIdParam(is_scalar($warehouse) ? (string)$warehouse : null);
        $category = $request->input('category', 'All');
        $brand = $request->input('brand', 'All');
        $stockStatus = $request->input('stock_status', 'All');
        $search = trim($request->input('search', ''));
        $isLowStock = ($stockStatus === 'low_stock');
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        $query = Product::query()
            ->with([
                'variants.values',
                'variants.stockBalances' => function ($q) use ($warehouseId, $allowedOutlets) {
                    if ($warehouseId !== null) {
                        $q->where('warehouse_id', $warehouseId);
                    }
                    if (!empty($allowedOutlets)) {
                        $q->whereIn('warehouse_id', $allowedOutlets);
                    }
                },
                'stockBalances' => function ($q) use ($warehouseId, $allowedOutlets) {
                    if ($warehouseId !== null) {
                        $q->where('warehouse_id', $warehouseId);
                    }
                    if (!empty($allowedOutlets)) {
                        $q->whereIn('warehouse_id', $allowedOutlets);
                    }
                },
            ])
            ->whereNull('deleted_at');

        if (!empty($allowedOutlets)) {
            if ($warehouseId !== null && !in_array($warehouseId, $allowedOutlets, true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($wq) use ($allowedOutlets) {
                    $wq->whereHas('stockBalances', function ($q) use ($allowedOutlets) {
                        $q->whereIn('warehouse_id', $allowedOutlets);
                    })->orWhereHas('variants.stockBalances', function ($q) use ($allowedOutlets) {
                        $q->whereIn('warehouse_id', $allowedOutlets);
                    });
                });
            }
        }

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $targetWarehouseId = $warehouseId ?? $this->resolveWarehouseIdParam(is_scalar($warehouse) ? (string)$warehouse : null) ?? $warehouse;
            $query->where(function ($wq) use ($targetWarehouseId) {
                $wq->whereHas('stockBalances', function ($q) use ($targetWarehouseId) {
                    $q->where('warehouse_id', $targetWarehouseId);
                })->orWhereHas('variants.stockBalances', function ($q) use ($targetWarehouseId) {
                    $q->where('warehouse_id', $targetWarehouseId);
                });
            });
        }

        if ($category !== 'All' && !empty($category)) {
            $query->where('category_name', $category);
        }

        if ($brand !== 'All' && !empty($brand)) {
            $query->where('brand_name', $brand);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('alternative_name', 'like', "%{$search}%")
                  ->orWhere('category_name', 'like', "%{$search}%")
                  ->orWhere('brand_name', 'like', "%{$search}%")
                  ->orWhereHas('variants', function ($vq) use ($search) {
                      $vq->where('name', 'like', "%{$search}%")
                         ->orWhere('sku', 'like', "%{$search}%");
                  });
            });
        }

        if ($stockStatus === 'in_stock') {
            if ($warehouseId !== null || !empty($allowedOutlets)) {
                $scopeWh = $warehouseId !== null ? [$warehouseId] : $allowedOutlets;
                $query->whereHas('stockBalances', fn($q) => $q->whereIn('warehouse_id', $scopeWh)->where('quantity', '>', 0));
            } else {
                $query->where('stock_quantity', '>', 0);
            }
        } elseif ($stockStatus === 'low_stock') {
            if ($warehouseId !== null || !empty($allowedOutlets)) {
                $scopeWh = $warehouseId !== null ? [$warehouseId] : $allowedOutlets;
                $query->where(function ($sq) use ($scopeWh) {
                    $sq->whereHas('stockBalances', function ($q) use ($scopeWh) {
                        $q->whereIn('warehouse_id', $scopeWh)
                          ->whereRaw('quantity <= COALESCE((SELECT alert_quantity FROM products WHERE products.id = stock_balances.product_id), 10)');
                    })->orWhereHas('variants.stockBalances', function ($sbq) use ($scopeWh) {
                        $sbq->whereIn('warehouse_id', $scopeWh)
                            ->whereRaw('quantity <= 10');
                    });
                });
            } else {
                $query->where(function ($q) {
                    $q->whereColumn('stock_quantity', '<=', 'alert_quantity')
                      ->orWhereColumn('stock_quantity', '<=', 'stock_alert')
                      ->orWhereColumn('stock_quantity', '<=', 'low_qty')
                      ->orWhere('stock_quantity', '<=', 10)
                      ->orWhereHas('variants', function ($vq) {
                          $vq->whereHas('stockBalances', function ($sbq) {
                              $sbq->select('product_variant_id')
                                  ->groupBy('product_variant_id')
                                  ->havingRaw('COALESCE(SUM(quantity), 0) <= 10');
                          });
                      });
                });
            }
        } elseif ($stockStatus === 'out_of_stock') {
            if ($warehouseId !== null || !empty($allowedOutlets)) {
                $scopeWh = $warehouseId !== null ? [$warehouseId] : $allowedOutlets;
                $query->whereDoesntHave('stockBalances', fn($q) => $q->whereIn('warehouse_id', $scopeWh)->where('quantity', '>', 0));
            } else {
                $query->where(function ($q) {
                    $q->where('stock_quantity', '<=', 0)
                      ->orWhereHas('variants', function ($vq) {
                          $vq->whereHas('stockBalances', function ($sbq) {
                              $sbq->select('product_variant_id')
                                  ->groupBy('product_variant_id')
                                  ->havingRaw('COALESCE(SUM(quantity), 0) <= 0');
                          });
                      });
                });
            }
        }

        $idQuery = clone $query;
        $idQuery->setEagerLoads([])->reorder();
        $idQuery->getQuery()->columns = null;
        $productIdsToExport = $idQuery
            ->orderBy('has_variants', 'desc')
            ->orderBy('id', 'desc')
            ->limit(5000)
            ->pluck('id')
            ->map(fn($id) => (int)$id)
            ->all();

        $prefix = $isLowStock ? 'low_stock_report' : 'stock_report';
        $filename = "{$prefix}_" . date('Ymd_His') . ".csv";

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Expires' => '0',
        ];

        $columns = [
            '#', 'Product Code', 'Product Name', 'Alternative Name (Myanmar)',
            'Category', 'Type', 'Variant SKU', 'Variant Attributes',
            'Stock Qty', 'Unit', 'Alert Qty', 'Status',
            'Cost Price', 'Retail Price', 'Value (Cost)', 'Warehouse'
        ];

        $formatNum = function ($num) {
            $f = (float)$num;
            return ($f == (int)$f) ? (string)(int)$f : number_format($f, 2, '.', '');
        };

        $formatBarcode = function ($code) {
            $code = trim((string)$code);
            if ($code === '') return '';
            if (preg_match('/^\d{10,}$/', $code) || (strlen($code) > 1 && $code[0] === '0' && ctype_digit($code))) {
                return '="' . $code . '"';
            }
            return $this->escapeCsvCell($code);
        };

        $canViewCost = $this->accounting()->canViewCost($request);
        $useBalanceSum = $warehouseId !== null || !empty($allowedOutlets);
        $safeWarehouseLabel = $this->escapeCsvCell($warehouse !== 'All' ? $warehouse : 'All Warehouses');
        $callback = function () use ($columns, $query, $productIdsToExport, $warehouseId, $allowedOutlets, $isLowStock, $formatNum, $formatBarcode, $useBalanceSum, $safeWarehouseLabel, $canViewCost) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Microsoft Excel
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $columns);

            $reconciliation = app(InventoryReconciliationService::class);
            $serial = 1;
            foreach (array_chunk($productIdsToExport, 200) as $idChunk) {
                $productsById = (clone $query)->whereIn('id', $idChunk)->get()->keyBy('id');
                $valuationData = $reconciliation->computeValuation($warehouseId, $idChunk, !empty($allowedOutlets) ? $allowedOutlets : null);
                $fifoValuation = $valuationData['by_product'] ?? [];
                $fifoBySku = $valuationData['by_sku'] ?? [];

                foreach ($idChunk as $pid) {
                    $p = $productsById->get($pid);
                    if (!$p) {
                        continue;
                    }
                    $unit = $this->escapeCsvCell($p->unit_name ?: 'pcs');
                    $category = $this->escapeCsvCell($p->category_name ?: '—');
                    $alertQty = (float)($p->alert_quantity ?: ($p->stock_alert ?: 10));

                    if ($p->has_variants && $p->variants->isNotEmpty()) {
                        $variants = $p->variants;
                        if ($isLowStock) {
                            $variants = $variants->filter(function ($v) use ($alertQty, $useBalanceSum) {
                                $stock = $useBalanceSum
                                    ? (float)$v->stockBalances->sum('quantity')
                                    : (float)$v->stock_quantity;
                                return $stock <= $alertQty || $stock <= 0;
                            });
                            if ($variants->isEmpty()) {
                                $variants = $p->variants;
                            }
                        }

                        foreach ($variants as $v) {
                            $vStock = $useBalanceSum
                                ? (float)$v->stockBalances->sum('quantity')
                                : (float)$v->stock_quantity;
                            $vCost = (float)($v->cost_price ?: $p->cost_price ?: 0);
                            $vRetail = (float)($v->retail_price ?: $p->retail_price ?: 0);
                            $skuKey = "{$p->id}|{$v->id}";
                            $vValuation = isset($fifoBySku[$skuKey])
                                ? (float)$fifoBySku[$skuKey]['effective_valuation']
                                : ($vStock * $vCost);

                            $status = $vStock <= 0 ? 'Out of Stock' : ($vStock <= $alertQty ? 'Low Stock' : 'Normal');

                            $attrStr = $v->values->map(function ($val) {
                                return "{$val->attribute_name}: {$val->attribute_value}";
                            })->implode(' | ');

                            fputcsv($file, [
                                $serial++,
                                $formatBarcode($p->code ?: $p->sku),
                                $this->escapeCsvCell($p->name),
                                $this->escapeCsvCell($p->alternative_name ?: ''),
                                $category,
                                'Variant',
                                $formatBarcode($v->sku),
                                $this->escapeCsvCell($attrStr ?: $v->name),
                                $formatNum($vStock),
                                $unit,
                                $formatNum($alertQty),
                                $status,
                                $canViewCost ? $formatNum($vCost) : 'Protected',
                                $formatNum($vRetail),
                                $canViewCost ? $formatNum($vValuation) : 'Protected',
                                $safeWarehouseLabel,
                            ]);
                        }
                    } else {
                        $pStock = $useBalanceSum
                            ? (float)$p->stockBalances->sum('quantity')
                            : (float)$p->stock_quantity;
                        $pCost = (float)($p->cost_price ?: $p->purchase_price ?: 0);
                        $pRetail = (float)($p->retail_price ?: $p->selling_price ?: 0);
                        $pValuation = isset($fifoValuation[$p->id])
                            ? (float)$fifoValuation[$p->id]['effective_valuation']
                            : ($pStock * $pCost);
                        $status = $pStock <= 0 ? 'Out of Stock' : ($pStock <= $alertQty ? 'Low Stock' : 'Normal');

                        fputcsv($file, [
                            $serial++,
                            $formatBarcode($p->code ?: $p->sku),
                            $this->escapeCsvCell($p->name),
                            $this->escapeCsvCell($p->alternative_name ?: ''),
                            $category,
                            'Single',
                            '—',
                            '—',
                            $formatNum($pStock),
                            $unit,
                            $formatNum($alertQty),
                            $status,
                            $canViewCost ? $formatNum($pCost) : 'Protected',
                            $formatNum($pRetail),
                            $canViewCost ? $formatNum($pValuation) : 'Protected',
                            $safeWarehouseLabel,
                        ]);
                    }
                }
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function topSellingReport(Request $request)
    {
        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $warehouse = $request->input('warehouse_name', 'All');
        $category = $request->input('category_name', 'All');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $search = trim((string)$request->input('search', ''));
        $sortBy = $request->input('sort_by', 'revenue');
        $canViewCost = $this->accounting()->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        $query = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id');
        $this->applyActiveDocumentFilter($query, 'sales.status');
        if (!empty($allowedOutlets)) {
            $query->whereIn('sales.warehouse_id', $allowedOutlets);
        }
        $query->when($warehouse !== 'All' && !empty($warehouse), function ($q) use ($warehouse) {
                $q->where('sales.warehouse_name', $warehouse);
            });
        $this->applyReportDateRange($query, $startDate, $endDate, 'sales.date');
        $query->when($category !== 'All' && !empty($category), function ($q) use ($category) {
                $q->where('products.category_name', $category);
            })
            ->when(!empty($search), function ($q) use ($search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('sale_items.product_name', 'like', "%{$search}%")
                       ->orWhere('sale_items.product_code', 'like', "%{$search}%")
                       ->orWhere('products.name', 'like', "%{$search}%")
                       ->orWhere('products.code', 'like', "%{$search}%")
                       ->orWhere('products.alternative_name', 'like', "%{$search}%")
                       ->orWhere('products.brand_name', 'like', "%{$search}%")
                       ->orWhere('products.category_name', 'like', "%{$search}%");
                });
            })
            ->select(
                'sale_items.product_id',
                'sale_items.product_name',
                'sale_items.product_code',
                'products.code as master_code',
                'products.alternative_name',
                'products.brand_name',
                'products.category_name',
                'products.cost_price',
                'products.retail_price',
                'products.stock_quantity',
                'products.alert_quantity',
                'products.has_variants',
                'products.image_url',
                DB::raw('COALESCE(SUM(sale_items.quantity), 0) as total_sold_quantity'),
                DB::raw('COALESCE(SUM(sale_items.subtotal), 0) as total_sales_revenue')
            )
            ->groupBy(
                'sale_items.product_id',
                'sale_items.product_name',
                'sale_items.product_code',
                'products.code',
                'products.alternative_name',
                'products.brand_name',
                'products.category_name',
                'products.cost_price',
                'products.retail_price',
                'products.stock_quantity',
                'products.alert_quantity',
                'products.has_variants',
                'products.image_url'
            );

        // Deduct active completed Sale Returns matching the same branch / date filters before sorting & pagination
        $retByProd = [];
        $srQ = DB::table('sale_returns')
            ->whereNotNull('items_payload')
            ->where('items_payload', '!=', '')
            ->where('items_payload', '!=', '[]');
        $this->applyActiveDocumentFilter($srQ, 'status');
        if (!empty($allowedOutlets)) {
            $srQ->whereIn('warehouse_id', $allowedOutlets);
        }
        $srQ->when($warehouse !== 'All' && !empty($warehouse), fn($q) => $q->where('warehouse_name', $warehouse));
        $this->applyReportDateRange($srQ, $startDate, $endDate, 'date');
        foreach ($srQ->select(['items_payload'])->cursor() as $srRow) {
            $srItems = json_decode((string)$srRow->items_payload, true);
            if (!is_array($srItems)) {
                continue;
            }
            foreach ($srItems as $ri) {
                $pid = isset($ri['product_id']) ? (int)$ri['product_id'] : 0;
                if ($pid <= 0) {
                    continue;
                }
                $qty = (float)($ri['quantity'] ?? 0);
                $rev = (float)($ri['subtotal'] ?? ($qty * (float)($ri['unit_price'] ?? 0)));
                if (!isset($retByProd[$pid])) {
                    $retByProd[$pid] = ['qty' => 0.0, 'rev' => 0.0];
                }
                $retByProd[$pid]['qty'] += $qty;
                $retByProd[$pid]['rev'] += $rev;
            }
        }

        $topProductsRaw = $query->get();

        $topProducts = [];
        $totalRevenue = 0.0;
        $totalQuantity = 0.0;

        foreach ($topProductsRaw as $p) {
            $pid = (int)$p->product_id;
            $retQty = $retByProd[$pid]['qty'] ?? 0.0;
            $retRev = $retByProd[$pid]['rev'] ?? 0.0;
            $rawSoldQty = (float)$p->total_sold_quantity;
            $rawSalesRev = (float)$p->total_sales_revenue;
            $deductQty = min($rawSoldQty, $retQty);
            $deductRev = min($rawSalesRev, $retRev);
            if ($pid > 0 && isset($retByProd[$pid])) {
                $retByProd[$pid]['qty'] = max(0.0, $retQty - $deductQty);
                $retByProd[$pid]['rev'] = max(0.0, $retRev - $deductRev);
            }

            $soldQty = round(max(0.0, $rawSoldQty - $deductQty), 4);
            $salesRev = round(max(0.0, $rawSalesRev - $deductRev), 2);
            if ($soldQty <= 0.0001) {
                continue;
            }
            $totalQuantity += $soldQty;
            $totalRevenue += $salesRev;

            $code = $p->product_code ?: ($p->master_code ?: '');
            $price = (float)($p->retail_price ?? ($soldQty > 0 ? ($salesRev / $soldQty) : 0));

            $topProducts[] = [
                'product_id' => $pid,
                'id' => $pid,
                'product_name' => $p->product_name ?: 'Product',
                'name' => $p->product_name ?: 'Product',
                'code' => $code,
                'product_code' => $code,
                'alternative_name' => $p->alternative_name ?? '',
                'brand_name' => $p->brand_name ?? 'Generic',
                'brand' => $p->brand_name ?? 'Generic',
                'category_name' => $p->category_name ?? 'General',
                'category' => $p->category_name ?? 'General',
                'cost_price' => $canViewCost ? (float)($p->cost_price ?? 0) : null,
                'price' => $price,
                'retail_price' => $price,
                'stock_quantity' => (float)($p->stock_quantity ?? 0),
                'alert_quantity' => (float)($p->alert_quantity ?? 0),
                'has_variants' => (bool)($p->has_variants ?? false),
                'total_sold_quantity' => $soldQty,
                'total_quantity' => $soldQty,
                'total_sales_revenue' => $salesRev,
                'total_sales' => $salesRev,
                'total_revenue' => $salesRev,
                'image_url' => $p->image_url ?? '',
            ];
        }

        usort($topProducts, function ($a, $b) use ($sortBy) {
            if ($sortBy === 'quantity') {
                $cmp = $b['total_sold_quantity'] <=> $a['total_sold_quantity'];
                return $cmp !== 0 ? $cmp : ($b['total_sales_revenue'] <=> $a['total_sales_revenue']);
            }
            $cmp = $b['total_sales_revenue'] <=> $a['total_sales_revenue'];
            return $cmp !== 0 ? $cmp : ($b['total_sold_quantity'] <=> $a['total_sold_quantity']);
        });

        $totalCount = count($topProducts);
        $lastPage = max(1, (int)ceil($totalCount / max(1, $perPage)));
        $offset = ($page - 1) * $perPage;
        $pagedTopProducts = array_values(array_slice($topProducts, $offset, $perPage));
        $hasMore = ($offset + count($pagedTopProducts)) < $totalCount;

        $globalProductsCount = $totalCount;
        $globalTotalRevenue = round($totalRevenue, 2);
        $globalTotalQuantity = round($totalQuantity, 2);
        $averageRevenue = $globalProductsCount > 0 ? round($globalTotalRevenue / $globalProductsCount, 2) : 0.0;

        return $this->noStoreJson([
            'status' => 'success',
            'data' => $pagedTopProducts,
            'summary' => [
                'total_products' => $globalProductsCount,
                'total_revenue' => $globalTotalRevenue,
                'total_quantity' => $globalTotalQuantity,
                'average_revenue' => $averageRevenue,
            ],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalCount,
                'last_page' => $lastPage,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * Profit & Loss Income Statement and Financial Ledger Analytics
     */
    public function profitLoss(Request $request)
    {
        $warehouse = $request->query('warehouse_name', $request->query('warehouse', 'All'));
        $dateFilter = $request->query('date_filter');
        $startDate = $request->query('start_date', $request->query('from_date', ''));
        $endDate = $request->query('end_date', $request->query('to_date', ''));
        $statusFilter = $request->query('status', 'All');
        $search = trim($request->query('search', ''));
        $forceRefresh = $request->boolean('force_refresh') || $request->query('force_refresh') === 'true' || $request->query('force_refresh') === '1';

        // 1. Unified Period Presets Parsing (Aligned with Admin Dashboard)
        if (!empty($dateFilter)) {
            switch ($dateFilter) {
                case 'today':
                    $startDate = Carbon::today()->toDateString();
                    $endDate = Carbon::today()->toDateString();
                    break;
                case 'yesterday':
                    $startDate = Carbon::yesterday()->toDateString();
                    $endDate = Carbon::yesterday()->toDateString();
                    break;
                case 'last_7_days':
                    $startDate = Carbon::today()->subDays(6)->toDateString();
                    $endDate = Carbon::today()->toDateString();
                    break;
                case 'this_month':
                    $startDate = Carbon::today()->startOfMonth()->toDateString();
                    $endDate = Carbon::today()->toDateString();
                    break;
                case 'last_month':
                    $startDate = Carbon::today()->subMonth()->startOfMonth()->toDateString();
                    $endDate = Carbon::today()->subMonth()->endOfMonth()->toDateString();
                    break;
                case 'this_year':
                    $startDate = Carbon::today()->startOfYear()->toDateString();
                    $endDate = Carbon::today()->toDateString();
                    break;
                case 'all':
                    $startDate = null;
                    $endDate = null;
                    break;
                case 'custom':
                    // Respect provided start_date & end_date
                    break;
            }
        } else {
            if (!empty($startDate) && !empty($endDate)) {
                $dateFilter = 'custom';
            } else {
                $dateFilter = 'all';
            }
        }

        if (!empty($startDate) && !empty($endDate) && $startDate > $endDate) {
            $temp = $startDate;
            $startDate = $endDate;
            $endDate = $temp;
        }

        $canViewCost = $this->accounting()->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        // B-tree Index-friendly Date Scoping helper (preserves index range seek)
        $applyDateScope = function ($query, $column = 'date') use ($startDate, $endDate) {
            if (!empty($startDate) && !empty($endDate)) {
                $query->where($column, '>=', $startDate)
                      ->where($column, '<=', $endDate . ' 23:59:59');
            } elseif (!empty($startDate)) {
                $query->where($column, '>=', $startDate);
            } elseif (!empty($endDate)) {
                $query->where($column, '<=', $endDate . ' 23:59:59');
            }
        };

        // 1. Sales Query Scope (Single-Pass Monthly Breakdown + Global Totals + Header COGS)
        $salesQuery = DB::table('sales');
        $this->applyActiveDocumentFilter($salesQuery, 'status');
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $salesQuery->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $salesQuery->whereIn('warehouse_id', $allowedOutlets);
        }
        $applyDateScope($salesQuery, 'date');

        $siTaxSub = DB::table('sale_items')
            ->select('sale_id', DB::raw('COALESCE(SUM(tax), 0) as item_tax_sum'))
            ->groupBy('sale_id');
        $plSalesTaxSql = "COALESCE(sales.tax_amount, 0) + COALESCE(sit.item_tax_sum, 0) + COALESCE(sales.shipping, 0)";
        $plNetSaleSql = "CASE WHEN (sales.grand_total - ({$plSalesTaxSql})) < 0 THEN 0 ELSE (sales.grand_total - ({$plSalesTaxSql})) END";

        $salesMonthly = (clone $salesQuery)
            ->leftJoinSub($siTaxSub, 'sit', 'sit.sale_id', '=', 'sales.id')
            ->selectRaw("
                SUBSTR(sales.date, 1, 7) as month_period,
                COALESCE(sales.warehouse_name, 'Main Branch Outlet') as w_name,
                COUNT(sales.id) as orders_count,
                COALESCE(SUM({$plNetSaleSql}), 0) as gross_sales,
                COALESCE(SUM(COALESCE(sales.tax_amount, 0) + COALESCE(sit.item_tax_sum, 0)), 0) as tax_collected,
                COALESCE(SUM(CASE WHEN sales.total_cogs > 0 THEN sales.total_cogs ELSE 0 END), 0) as total_cogs,
                COALESCE(SUM(CASE WHEN sales.total_cogs IS NULL OR sales.total_cogs <= 0 THEN 1 ELSE 0 END), 0) as missing_cogs_count
            ")
            ->groupBy(DB::raw("SUBSTR(sales.date, 1, 7)"), 'sales.warehouse_name')
            ->orderByDesc(DB::raw("SUBSTR(sales.date, 1, 7)"))
            ->get();

        $ordersCount = 0;
        $grossSales = 0.0;
        $taxCollected = 0.0;
        $cogs = 0.0;
        $missingCogsRows = 0;
        $cogsMonthlyMap = [];

        foreach ($salesMonthly as $sm) {
            $ordersCount += (int)$sm->orders_count;
            $grossSales += (float)$sm->gross_sales;
            $taxCollected += (float)($sm->tax_collected ?? 0);
            $smCogs = (float)$sm->total_cogs;
            $cogs += $smCogs;
            $missingCogsRows += (int)$sm->missing_cogs_count;
            $mKey = $sm->month_period . '_' . $sm->w_name;
            $cogsMonthlyMap[$mKey] = ($cogsMonthlyMap[$mKey] ?? 0.0) + $smCogs;
        }

        // 2. Sales Returns Monthly & Global Total (Tax-Exclusive & ex-Shipping matching P&L Revenue)
        $srLayerSub = DB::table('sale_return_cost_layers')
            ->select('sale_return_id', DB::raw('COALESCE(SUM(total_cost), 0) as layer_cost'))
            ->groupBy('sale_return_id');
        $srCogsSql = "COALESCE(NULLIF(sale_returns.return_cost_total, 0), COALESCE(srcl.layer_cost, 0))";
        $srNetRevSql = "CASE WHEN (sale_returns.grand_total - COALESCE(sale_returns.tax_amount, 0) - COALESCE(sale_returns.shipping, 0)) < 0 THEN 0 ELSE (sale_returns.grand_total - COALESCE(sale_returns.tax_amount, 0) - COALESCE(sale_returns.shipping, 0)) END";
        $returnsMonthlyQuery = DB::table('sale_returns')
            ->leftJoinSub($srLayerSub, 'srcl', 'srcl.sale_return_id', '=', 'sale_returns.id');
        $this->applyActiveDocumentFilter($returnsMonthlyQuery, 'sale_returns.status');
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $returnsMonthlyQuery->where('sale_returns.warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $returnsMonthlyQuery->whereIn('sale_returns.warehouse_id', $allowedOutlets);
        }
        $applyDateScope($returnsMonthlyQuery, 'sale_returns.date');

        $returnsMonthly = $returnsMonthlyQuery
            ->selectRaw("SUBSTR(sale_returns.date, 1, 7) as month_period, COALESCE(sale_returns.warehouse_name, 'Main Branch Outlet') as w_name, COALESCE(SUM({$srNetRevSql}), 0) as returns_total, COALESCE(SUM(COALESCE(sale_returns.tax_amount, 0)), 0) as returns_tax_total, COALESCE(SUM({$srCogsSql}), 0) as returned_cogs_total")
            ->groupBy(DB::raw("SUBSTR(sale_returns.date, 1, 7)"), 'sale_returns.warehouse_name')
            ->get()
            ->keyBy(function ($r) { return $r->month_period . '_' . $r->w_name; });

        $saleReturns = (float)$returnsMonthly->sum('returns_total');
        $taxCollected = round(max(0.0, $taxCollected - (float)$returnsMonthly->sum('returns_tax_total')), 2);
        $returnedCogs = (float)$returnsMonthly->sum('returned_cogs_total');
        foreach ($returnsMonthly as $rKey => $rRow) {
            $retCogs = (float)($rRow->returned_cogs_total ?? 0);
            if ($retCogs != 0.0) {
                $cogsMonthlyMap[$rKey] = round(($cogsMonthlyMap[$rKey] ?? 0.0) - $retCogs, 2);
            }
        }
        $cogs = round($cogs - $returnedCogs, 2);
        $netSales = round($grossSales - $saleReturns, 2);

        // 3. Purchases & Purchase Returns Query Scope (IAS 2: Received/Completed only & net of recoverable tax)
        $piTaxSub = DB::table('purchase_items')
            ->select('purchase_id', DB::raw('COALESCE(SUM(tax), 0) as item_tax_sum'))
            ->groupBy('purchase_id');
        $netPurchSql = "CASE WHEN COALESCE(purchases.is_tax_recoverable, 0) = 1 THEN CASE WHEN (purchases.grand_total - COALESCE(purchases.tax_amount, 0) - COALESCE(pit.item_tax_sum, 0)) < 0 THEN 0 ELSE (purchases.grand_total - COALESCE(purchases.tax_amount, 0) - COALESCE(pit.item_tax_sum, 0)) END ELSE purchases.grand_total END";

        $purchQuery = DB::table('purchases')
            ->leftJoinSub($piTaxSub, 'pit', 'pit.purchase_id', '=', 'purchases.id')
            ->whereIn('purchases.status', ['Received', 'Completed']);
        $this->excludeOpeningStockPurchases($purchQuery, 'purchases.payment_type');
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $purchQuery->where('purchases.warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $purchQuery->whereIn('purchases.warehouse_id', $allowedOutlets);
        }
        $applyDateScope($purchQuery, 'purchases.date');

        $grossPurchases = (float)(clone $purchQuery)->selectRaw("COALESCE(SUM({$netPurchSql}), 0) as gross_purchases")->value('gross_purchases');

        $prQuery = DB::table('purchase_returns')->whereIn('status', ['Completed', 'Received']);
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $prQuery->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $prQuery->whereIn('warehouse_id', $allowedOutlets);
        }
        $applyDateScope($prQuery, 'date');
        $purchaseAccounting = $this->accounting();
        $prBaseSummary = (clone $prQuery)->selectRaw("
            COALESCE(SUM(CASE WHEN COALESCE(is_tax_recoverable, 0) = 0 THEN grand_total ELSE 0 END), 0) as non_rec_total,
            COALESCE(SUM(COALESCE(cost_variance, 0)), 0) as total_variance
        ")->first();
        $purchaseReturns = (float)($prBaseSummary->non_rec_total ?? 0.0);
        $purchaseReturnVariance = round((float)($prBaseSummary->total_variance ?? 0.0), 2);
        foreach ((clone $prQuery)->where('is_tax_recoverable', 1)->select(['grand_total', 'tax_amount', 'is_tax_recoverable', 'items_payload'])->cursor() as $prRow) {
            $purchaseReturns += $purchaseAccounting->netPurchaseReturnAmount($prRow);
        }
        $purchaseReturns = round($purchaseReturns, 2);
        $prVarianceMonthly = (clone $prQuery)
            ->selectRaw("SUBSTR(date, 1, 7) as month_period, COALESCE(warehouse_name, 'Main Branch Outlet') as w_name, COALESCE(SUM(COALESCE(cost_variance, 0)), 0) as pr_variance")
            ->groupBy(DB::raw("SUBSTR(date, 1, 7)"), 'warehouse_name')
            ->get()
            ->keyBy(fn($r) => $r->month_period . '_' . $r->w_name);
        $netPurchases = round($grossPurchases - $purchaseReturns, 2);

        // 4. Fallback Cost of Goods Sold (COGS) via FIFO Cost Snapshot / Base Quantities
        // Only queries sale_items for rows lacking precomputed sales.total_cogs
        if ($missingCogsRows > 0) {
            $cogsExpr = 'COALESCE(SUM(COALESCE(NULLIF(sale_items.total_cogs, 0), COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity, 1) * COALESCE(NULLIF(product_variants.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0))), 0)';
            $missingCogsQuery = DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->leftJoin('product_variants', 'sale_items.product_variant_id', '=', 'product_variants.id')
                ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
                ->where(function ($q) {
                    $q->whereNull('sales.total_cogs')->orWhere('sales.total_cogs', '<=', 0);
                });
            $this->applyActiveDocumentFilter($missingCogsQuery, 'sales.status');

            if ($warehouse !== 'All' && !empty($warehouse)) {
                $missingCogsQuery->where('sales.warehouse_name', $warehouse);
            }
            if (!empty($allowedOutlets)) {
                $missingCogsQuery->whereIn('sales.warehouse_id', $allowedOutlets);
            }
            $applyDateScope($missingCogsQuery, 'sales.date');

            $missingMonthly = $missingCogsQuery
                ->selectRaw("SUBSTR(sales.date, 1, 7) as month_period, COALESCE(sales.warehouse_name, 'Main Branch Outlet') as w_name, {$cogsExpr} as cogs_total")
                ->groupBy(DB::raw("SUBSTR(sales.date, 1, 7)"), 'sales.warehouse_name')
                ->get();

            foreach ($missingMonthly as $mc) {
                $mcCogs = (float)$mc->cogs_total;
                $cogs += $mcCogs;
                $mKey = $mc->month_period . '_' . $mc->w_name;
                $cogsMonthlyMap[$mKey] = ($cogsMonthlyMap[$mKey] ?? 0.0) + $mcCogs;
            }
        }

        $grossProfit = round($netSales - $cogs, 2);
        $grossMarginPct = $netSales > 0 ? round(($grossProfit / $netSales) * 100, 2) : 0.0;

        // 5. Operating Expenses & Category Breakdown (Single Pass for Global + Category Totals)
        $expQuery = DB::table('expenses')
            ->where(function ($q) {
                $q->whereNull('payment_status')
                  ->orWhereNotIn('payment_status', [
                      'Pending', 'pending', 'PENDING',
                      'Draft', 'draft', 'DRAFT',
                      'Voided', 'voided', 'VOIDED',
                  ]);
            });
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $expQuery->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $expQuery->whereIn('warehouse_id', $allowedOutlets);
        }
        $applyDateScope($expQuery, 'date');

        $expenseCategoryRows = (clone $expQuery)
            ->selectRaw("COALESCE(expense_category_name, 'General Expenses') as category, COALESCE(SUM(amount), 0) as total_amount, COUNT(id) as count")
            ->groupBy(DB::raw("COALESCE(expense_category_name, 'General Expenses')"))
            ->orderByDesc('total_amount')
            ->get();

        $totalExpenses = (float)$expenseCategoryRows->sum('total_amount');
        $expenseCategories = $expenseCategoryRows->map(function ($ec) {
            return [
                'category' => $ec->category,
                'amount' => round((float)$ec->total_amount, 2),
                'count' => (int)$ec->count,
            ];
        })->all();

        $expensesMonthly = (clone $expQuery)
            ->selectRaw("SUBSTR(date, 1, 7) as month_period, COALESCE(warehouse_name, 'Main Branch Outlet') as w_name, COALESCE(SUM(amount), 0) as expenses_total")
            ->groupBy(DB::raw("SUBSTR(date, 1, 7)"), 'warehouse_name')
            ->get()
            ->keyBy(function($e) { return $e->month_period . '_' . $e->w_name; });

        // 5b. Operating Inventory Shrinkage / Wastage Loss & Overage / Surplus Gain (Excludes Opening Stock / Equity)
        $effAdjTypeSql = $this->effectiveAdjustmentTypeSql();
        $adjBaseQuery = DB::table('stock_adjustment_items')
            ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id');
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $adjBaseQuery->where('stock_adjustments.warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $adjBaseQuery->whereIn('stock_adjustments.warehouse_id', $allowedOutlets);
        }
        $applyDateScope($adjBaseQuery, 'stock_adjustments.date');

        $adjOpQuery = clone $adjBaseQuery;
        $this->applyOperatingAdjustmentScope($adjOpQuery, true);

        $adjSummary = (clone $adjOpQuery)->selectRaw("
            COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'subtraction' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as inventory_loss,
            COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'addition' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as inventory_gain
        ")->first();
        $adjMonthly = (clone $adjOpQuery)
            ->selectRaw("SUBSTR(stock_adjustments.date, 1, 7) as month_period, COALESCE(stock_adjustments.warehouse_name, 'Main Branch Outlet') as w_name, COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'subtraction' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as adj_loss, COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'addition' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as adj_gain")
            ->groupBy(DB::raw("SUBSTR(stock_adjustments.date, 1, 7)"), 'stock_adjustments.warehouse_name')
            ->get()
            ->keyBy(fn($a) => $a->month_period . '_' . $a->w_name);

        $nonPnlAdjQuery = clone $adjBaseQuery;
        $this->applyOperatingAdjustmentScope($nonPnlAdjQuery, false);
        $nonPnlSummary = $nonPnlAdjQuery->selectRaw("
            COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'addition' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as non_pnl_additions,
            COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'subtraction' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as non_pnl_subtractions
        ")->first();

        $inventoryLoss = (float)($adjSummary->inventory_loss ?? 0);
        $inventoryGain = (float)($adjSummary->inventory_gain ?? 0);
        $nonPnlAdditions = (float)($nonPnlSummary->non_pnl_additions ?? 0);
        $nonPnlSubtractions = (float)($nonPnlSummary->non_pnl_subtractions ?? 0);
        $nonPnlAdjustments = round($nonPnlAdditions - $nonPnlSubtractions, 2);

        $netInventoryLoss = max(0.0, round($inventoryLoss - $inventoryGain, 2));

        // Standard GAAP / Retail Accounting: Net Profit = Gross Profit - Operating Expenses - Inventory Loss + Inventory Gain + Purchase Return Cost Variance
        $netProfit = round($grossProfit - $totalExpenses - $inventoryLoss + $inventoryGain + $purchaseReturnVariance, 2);
        $netMarginPct = $netSales > 0 ? round(($netProfit / $netSales) * 100, 2) : 0.0;

        // 7. Monthly Period Ledger Breakdown (Union across Sales, Returns, Expenses, and Operating Adjustments)
        $periodKeysMap = [];
        $registerKey = function (string $mPeriod, string $wName) use (&$periodKeysMap) {
            if ($mPeriod === '') return;
            $k = $mPeriod . '_' . $wName;
            if (!isset($periodKeysMap[$k])) {
                $periodKeysMap[$k] = [
                    'month_period' => $mPeriod,
                    'w_name' => $wName,
                    'key' => $k,
                ];
            }
        };

        $salesMonthlyByKey = [];
        foreach ($salesMonthly as $sm) {
            $k = $sm->month_period . '_' . $sm->w_name;
            $salesMonthlyByKey[$k] = $sm;
            $registerKey((string)$sm->month_period, (string)$sm->w_name);
        }
        foreach ($returnsMonthly as $r) {
            $registerKey((string)$r->month_period, (string)$r->w_name);
        }
        foreach ($expensesMonthly as $e) {
            $registerKey((string)$e->month_period, (string)$e->w_name);
        }
        foreach ($adjMonthly as $a) {
            $registerKey((string)$a->month_period, (string)$a->w_name);
        }
        foreach ($prVarianceMonthly as $pv) {
            if (abs((float)$pv->pr_variance) > 0.001) {
                $registerKey((string)$pv->month_period, (string)$pv->w_name);
            }
        }

        // Order descending by month_period then ascending by warehouse name
        uasort($periodKeysMap, function ($a, $b) {
            $cmp = strcmp($b['month_period'], $a['month_period']);
            return $cmp !== 0 ? $cmp : strcmp($a['w_name'], $b['w_name']);
        });

        $periods = [];
        $idSeq = 1;
        foreach ($periodKeysMap as $pk) {
            $mPeriod = $pk['month_period'];
            $wName = $pk['w_name'];
            $mKey = $pk['key'];

            $sm = $salesMonthlyByKey[$mKey] ?? null;
            $mGross = $sm ? (float)$sm->gross_sales : 0.0;
            $mOrdersCount = $sm ? (int)$sm->orders_count : 0;
            $retObj = $returnsMonthly->get($mKey);
            $mReturns = $retObj ? (float)$retObj->returns_total : 0.0;
            $mNetSales = round($mGross - $mReturns, 2);

            $mCogs = (float)($cogsMonthlyMap[$mKey] ?? 0.0);

            $mGrossProfit = round($mNetSales - $mCogs, 2);
            $mGrossMarginPct = $mNetSales > 0 ? round(($mGrossProfit / $mNetSales) * 100, 2) : 0.0;

            $expObj = $expensesMonthly->get($mKey);
            $mExpenses = $expObj ? (float)$expObj->expenses_total : 0.0;
            $adjMObj = $adjMonthly->get($mKey);
            $mAdjLoss = $adjMObj ? (float)$adjMObj->adj_loss : 0.0;
            $mAdjGain = $adjMObj ? (float)$adjMObj->adj_gain : 0.0;
            $prVObj = $prVarianceMonthly->get($mKey);
            $mPrVariance = $prVObj ? (float)$prVObj->pr_variance : 0.0;
            $mNetProfit = round($mGrossProfit - $mExpenses - $mAdjLoss + $mAdjGain + $mPrVariance, 2);
            $mNetMarginPct = $mNetSales > 0 ? round(($mNetProfit / $mNetSales) * 100, 2) : 0.0;

            $status = $mNetProfit >= 0 ? 'Profitable' : 'Loss';
            if ($statusFilter !== 'All' && !empty($statusFilter) && strcasecmp($status, $statusFilter) !== 0) {
                continue;
            }

            $refNo = 'PL-' . str_replace('-', '', $mPeriod) . '-' . str_pad($idSeq, 3, '0', STR_PAD_LEFT);

            // Search filter
            if (!empty($search)) {
                $q = strtolower($search);
                $matches = str_contains(strtolower($refNo), $q) ||
                           str_contains(strtolower($mPeriod), $q) ||
                           str_contains(strtolower($wName), $q) ||
                           str_contains(strtolower($status), $q);
                if (!$matches) continue;
            }

            $periods[] = [
                'id' => $idSeq++,
                'period' => $mPeriod,
                'reference_no' => $refNo,
                'warehouse_name' => $wName,
                'gross_sales' => round($mGross, 2),
                'sales_return' => round($mReturns, 2),
                'net_sales' => round($mNetSales, 2),
                'cogs' => round($mCogs, 2),
                'gross_profit' => round($mGrossProfit, 2),
                'gross_margin_pct' => $mGrossMarginPct,
                'expenses' => round($mExpenses, 2),
                'inventory_loss' => round($mAdjLoss, 2),
                'inventory_gain' => round($mAdjGain, 2),
                'net_inventory_adjustment' => round($mAdjGain - $mAdjLoss, 2),
                'purchase_return_variance' => round($mPrVariance, 2),
                'net_profit' => round($mNetProfit, 2),
                'net_profit_margin_pct' => $mNetMarginPct,
                'status' => $status,
                'orders_count' => $mOrdersCount,
            ];
        }

        // Reconcile top KPI cards to filtered rows whenever statusFilter or search narrows the monthly ledger
        if (!empty($search) || ($statusFilter !== 'All' && !empty($statusFilter))) {
            $grossSales = round((float)array_sum(array_column($periods, 'gross_sales')), 2);
            $saleReturns = round((float)array_sum(array_column($periods, 'sales_return')), 2);
            $netSales = round($grossSales - $saleReturns, 2);
            $cogs = round((float)array_sum(array_column($periods, 'cogs')), 2);
            $grossProfit = round($netSales - $cogs, 2);
            $grossMarginPct = $netSales > 0 ? round(($grossProfit / $netSales) * 100, 2) : 0.0;
            $totalExpenses = round((float)array_sum(array_column($periods, 'expenses')), 2);
            $inventoryLoss = round((float)array_sum(array_column($periods, 'inventory_loss')), 2);
            $inventoryGain = round((float)array_sum(array_column($periods, 'inventory_gain')), 2);
            $netInventoryLoss = max(0.0, round($inventoryLoss - $inventoryGain, 2));
            $purchaseReturnVariance = round((float)array_sum(array_column($periods, 'purchase_return_variance')), 2);
            $netProfit = round((float)array_sum(array_column($periods, 'net_profit')), 2);
            $netMarginPct = $netSales > 0 ? round(($netProfit / $netSales) * 100, 2) : 0.0;
            $ordersCount = (int)array_sum(array_column($periods, 'orders_count'));
        }

        $summary = [
            'cost_masked' => !$canViewCost,
            'date_filter' => $dateFilter,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_gross_sales' => round($grossSales, 2),
            'total_sales_return' => round($saleReturns, 2),
            'total_net_sales' => round($netSales, 2),
            'total_tax_collected' => round($taxCollected, 2),
            'total_gross_purchases' => $canViewCost ? round($grossPurchases, 2) : 0.0,
            'total_purchase_return' => $canViewCost ? round($purchaseReturns, 2) : 0.0,
            'total_net_purchases' => $canViewCost ? round($netPurchases, 2) : 0.0,
            'total_cogs' => $canViewCost ? round($cogs, 2) : 0.0,
            'total_gross_profit' => $canViewCost ? round($grossProfit, 2) : 0.0,
            'gross_margin_percentage' => $canViewCost ? $grossMarginPct : 0.0,
            'total_expenses' => round($totalExpenses, 2),
            'inventory_loss' => $canViewCost ? round($inventoryLoss, 2) : 0.0,
            'inventory_gain' => $canViewCost ? round($inventoryGain, 2) : 0.0,
            'net_inventory_loss' => $canViewCost ? $netInventoryLoss : 0.0,
            'purchase_return_variance' => $canViewCost ? round($purchaseReturnVariance, 2) : 0.0,
            'non_pnl_additions' => $canViewCost ? round($nonPnlAdditions, 2) : 0.0,
            'non_pnl_subtractions' => $canViewCost ? round($nonPnlSubtractions, 2) : 0.0,
            'non_pnl_adjustments' => $canViewCost ? $nonPnlAdjustments : 0.0,
            'total_net_profit' => $canViewCost ? round($netProfit, 2) : 0.0,
            'net_margin_percentage' => $canViewCost ? $netMarginPct : 0.0,
            'total_periods' => count($periods),
            'total_orders' => $ordersCount,
            'average_order_value' => $ordersCount > 0 ? round($netSales / $ordersCount, 2) : 0.0,
        ];

        if (!$canViewCost) {
            $periods = array_map(fn($p) => $this->maskAssocNumericKeys(
                $p,
                ['cogs', 'gross_profit', 'gross_margin_pct', 'inventory_loss', 'inventory_gain', 'net_inventory_adjustment', 'purchase_return_variance', 'net_profit', 'net_profit_margin_pct'],
                0.0
            ), $periods);
        }

        // Backwards-compatible payload matching RetailPosTest & Flutter models
        $responseData = array_merge($summary, [
            'cost_masked' => !$canViewCost,
            'gross_sales' => round($grossSales, 2),
            'total_sales' => round($grossSales, 2),
            'sales_return' => round($saleReturns, 2),
            'net_sales' => round($netSales, 2),
            'gross_purchases' => $canViewCost ? round($grossPurchases, 2) : 0.0,
            'total_purchases' => $canViewCost ? round($grossPurchases, 2) : 0.0,
            'purchase_return' => $canViewCost ? round($purchaseReturns, 2) : 0.0,
            'net_purchases' => $canViewCost ? round($netPurchases, 2) : 0.0,
            'cogs' => $canViewCost ? round($cogs, 2) : 0.0,
            'gross_profit' => $canViewCost ? round($grossProfit, 2) : 0.0,
            'expenses' => round($totalExpenses, 2),
            'inventory_loss' => $canViewCost ? round($inventoryLoss, 2) : 0.0,
            'inventory_gain' => $canViewCost ? round($inventoryGain, 2) : 0.0,
            'net_inventory_loss' => $canViewCost ? $netInventoryLoss : 0.0,
            'purchase_return_variance' => $canViewCost ? round($purchaseReturnVariance, 2) : 0.0,
            'non_pnl_additions' => $canViewCost ? round($nonPnlAdditions, 2) : 0.0,
            'non_pnl_subtractions' => $canViewCost ? round($nonPnlSubtractions, 2) : 0.0,
            'non_pnl_adjustments' => $canViewCost ? $nonPnlAdjustments : 0.0,
            'total_expenses' => round($totalExpenses, 2),
            'net_profit' => $canViewCost ? round($netProfit, 2) : 0.0,
            'summary' => $summary,
            'periods' => $periods,
            'data' => $periods,
            'expense_breakdown' => $expenseCategories,
        ]);

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'data' => $responseData,
        ]);
    }

    public function stockProductDetails(Request $request, $id)
    {
        $product = Product::with(['variants'])->find($id);
        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found',
            ], 404);
        }

        $warehouseParam = $request->input('warehouse_id')
            ?? $request->input('outlet_id')
            ?? $request->input('warehouse_name')
            ?? $request->input('warehouse');
        $warehouseId = null;
        $warehouseName = null;
        if ($warehouseParam !== null && $warehouseParam !== '' && $warehouseParam !== 'All' && $warehouseParam !== 'all') {
            if (is_numeric($warehouseParam) && (int)$warehouseParam > 0) {
                $warehouseId = (int)$warehouseParam;
                $outlet = Outlet::find($warehouseId);
                if ($outlet) {
                    $warehouseName = $outlet->name;
                }
            } else {
                $whStr = trim((string)$warehouseParam);
                $outlet = Outlet::where('name', $whStr)->first();
                if ($outlet) {
                    $warehouseId = (int)$outlet->id;
                    $warehouseName = $outlet->name;
                } else {
                    $warehouseName = $whStr;
                }
            }
        }

        $allowedOutlets = $this->extractAllowedOutletIds($request);
        if (!empty($allowedOutlets) && $warehouseId !== null && !in_array($warehouseId, $allowedOutlets, true)) {
            return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
        }

        $search = trim($request->input('search', ''));

        // Handle variant isolation
        $variantParam = $request->input('variant_id') ?? $request->input('product_variant_id');
        $selectedVariantId = ($variantParam && $variantParam !== 'All' && $variantParam !== 'all')
            ? (int)$variantParam
            : null;

        $selectedVariant = null;
        if ($selectedVariantId !== null && $product->variants) {
            $selectedVariant = $product->variants->firstWhere('id', $selectedVariantId);
        }

        $effectiveStockQty = (float)($selectedVariant ? $selectedVariant->stock_quantity : $product->stock_quantity);

        // 1. Warehouse Stocks Breakdown (Top Card)
        $balanceQuery = StockBalance::where('stock_balances.product_id', $product->id)
            ->join('outlets', 'stock_balances.warehouse_id', '=', 'outlets.id')
            ->select(
                'outlets.id as warehouse_id',
                'outlets.name as warehouse_name',
                DB::raw('SUM(stock_balances.quantity) as quantity')
            )
            ->groupBy('outlets.id', 'outlets.name');

        if ($selectedVariantId !== null) {
            $balanceQuery->where('stock_balances.product_variant_id', $selectedVariantId);
        }

        if ($warehouseId !== null) {
            $balanceQuery->where('outlets.id', $warehouseId);
        } elseif (!empty($allowedOutlets)) {
            $balanceQuery->whereIn('outlets.id', $allowedOutlets);
        }

        $warehouseStocks = $balanceQuery->get()->map(function ($row) {
            return [
                'warehouse_id' => (int)$row->warehouse_id,
                'warehouse_name' => $row->warehouse_name,
                'quantity' => (float)$row->quantity,
            ];
        });

        if ($warehouseStocks->isEmpty()) {
            $activeOutlets = Outlet::where('is_active', true)
                ->when(!empty($allowedOutlets), fn($q) => $q->whereIn('id', $allowedOutlets))
                ->get();
            if ($activeOutlets->isNotEmpty()) {
                if ($warehouseId !== null) {
                    $matched = $activeOutlets->firstWhere('id', $warehouseId);
                    $warehouseStocks = collect([
                        [
                            'warehouse_id' => $matched ? $matched->id : $warehouseId,
                            'warehouse_name' => $matched ? $matched->name : 'Warehouse #' . $warehouseId,
                            'quantity' => $effectiveStockQty,
                        ]
                    ]);
                } else {
                    $warehouseStocks = collect([
                        [
                            'warehouse_id' => $activeOutlets->first()->id,
                            'warehouse_name' => $activeOutlets->first()->name,
                            'quantity' => $effectiveStockQty,
                        ]
                    ]);
                }
            } else {
                $warehouseStocks = collect([
                    [
                        'warehouse_id' => 1,
                        'warehouse_name' => 'Default Warehouse',
                        'quantity' => $effectiveStockQty,
                    ]
                ]);
            }
        }

        // Determine scope: if selectedVariantId is set, isolate movements to that variant only!
        if ($selectedVariantId !== null) {
            $variantIds = [$selectedVariantId];
            $productCodes = [];
            if ($selectedVariant) {
                if (!empty($selectedVariant->sku)) $productCodes[] = $selectedVariant->sku;
                if (!empty($selectedVariant->barcode)) $productCodes[] = $selectedVariant->barcode;
            }
        } else {
            $variantIds = $product->variants ? $product->variants->pluck('id')->toArray() : [];
            $productCodes = array_filter([$product->code, $product->sku, $product->barcode]);
            if ($product->variants) {
                foreach ($product->variants as $var) {
                    if (!empty($var->sku)) $productCodes[] = $var->sku;
                    if (!empty($var->barcode)) $productCodes[] = $var->barcode;
                }
            }
        }

        $canViewCost = $this->accounting()->canViewCost($request);

        $fallbackItemName = $selectedVariant
            ? ($product->name . ' - ' . ($selectedVariant->name ?? $selectedVariant->sku ?? 'Variant #' . $selectedVariant->id))
            : $product->name;

        // Helper for formatting created_on (preserves revenue subtotals on sales while masking cost valuation when restricted)
        $mapRow = function ($item, bool $isCostMovement = false) use ($fallbackItemName, $canViewCost) {
            $dateVal = $item->date ?? $item->created_at ?? date('Y-m-d H:i:s');
            $createdOn = (string)$dateVal;
            return [
                'id' => (int)($item->transaction_id ?? $item->id ?? 0),
                'created_on' => $createdOn,
                'reference' => (string)($item->reference_no ?? '—'),
                'name' => (string)($item->product_name ?? $fallbackItemName),
                'party_name' => (string)($item->party_name ?? '—'),
                'warehouse_name' => (string)($item->warehouse_name ?? '—'),
                'quantity' => (float)($item->quantity ?? 0),
                'subtotal' => ($isCostMovement && !$canViewCost) ? 0.0 : (float)($item->subtotal ?? 0),
            ];
        };
        $mapCostRow = fn ($item) => $mapRow($item, true);

        // 2. Movements: Sales
        $salesQuery = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id');

        if ($selectedVariantId !== null) {
            $salesQuery->where(function ($q) use ($selectedVariantId, $productCodes) {
                $q->where('sale_items.product_variant_id', $selectedVariantId);
                if (!empty($productCodes)) {
                    $q->orWhereIn('sale_items.product_code', $productCodes);
                }
            });
        } else {
            $salesQuery->where(function ($q) use ($product, $variantIds, $productCodes) {
                $q->where('sale_items.product_id', $product->id);
                if (!empty($variantIds)) {
                    $q->orWhereIn('sale_items.product_variant_id', $variantIds);
                }
                if (!empty($productCodes)) {
                    $q->orWhereIn('sale_items.product_code', $productCodes);
                }
            });
        }

        $salesQuery->select(
            'sales.id as transaction_id',
            'sales.date',
            'sales.created_at',
            'sales.reference_no',
            'sale_items.product_name',
            'sales.customer_name as party_name',
            'sales.warehouse_name',
            'sale_items.quantity',
            'sale_items.subtotal'
        );

        if (!empty($allowedOutlets)) {
            $salesQuery->whereIn('sales.warehouse_id', $allowedOutlets);
        }
        if ($warehouseId !== null) {
            $salesQuery->where(function ($q) use ($warehouseId, $warehouseName) {
                $q->where('sales.warehouse_id', $warehouseId);
                if ($warehouseName) {
                    $q->orWhere('sales.warehouse_name', $warehouseName);
                }
            });
        }
        if (!empty($search)) {
            $salesQuery->where(function ($q) use ($search) {
                $q->where('sales.reference_no', 'like', "%{$search}%")
                  ->orWhere('sale_items.product_name', 'like', "%{$search}%")
                  ->orWhere('sales.customer_name', 'like', "%{$search}%")
                  ->orWhere('sales.warehouse_name', 'like', "%{$search}%");
            });
        }
        $salesList = $salesQuery->orderByDesc('sales.id')->limit(100)->get()->map($mapRow);

        // 3. Movements: Purchases (Received/Completed only)
        $purchQuery = DB::table('purchase_items')
            ->join('purchases', 'purchase_items.purchase_id', '=', 'purchases.id')
            ->whereIn('purchases.status', ['Received', 'Completed']);

        if ($selectedVariantId !== null) {
            $purchQuery->where(function ($q) use ($selectedVariantId, $productCodes) {
                $q->where('purchase_items.product_variant_id', $selectedVariantId);
                if (!empty($productCodes)) {
                    $q->orWhereIn('purchase_items.product_code', $productCodes);
                }
            });
        } else {
            $purchQuery->where(function ($q) use ($product, $variantIds, $productCodes) {
                $q->where('purchase_items.product_id', $product->id);
                if (!empty($variantIds)) {
                    $q->orWhereIn('purchase_items.product_variant_id', $variantIds);
                }
                if (!empty($productCodes)) {
                    $q->orWhereIn('purchase_items.product_code', $productCodes);
                }
            });
        }

        $purchQuery->select(
            'purchases.id as transaction_id',
            'purchases.date',
            'purchases.created_at',
            'purchases.reference_no',
            'purchase_items.product_name',
            'purchases.supplier_name as party_name',
            'purchases.warehouse_name',
            DB::raw('COALESCE(NULLIF(purchase_items.base_quantity, 0), purchase_items.quantity * COALESCE(NULLIF(purchase_items.conversion_rate, 0), 1)) as quantity'),
            'purchase_items.subtotal'
        );

        if (!empty($allowedOutlets)) {
            $purchQuery->whereIn('purchases.warehouse_id', $allowedOutlets);
        }
        if ($warehouseId !== null) {
            $purchQuery->where(function ($q) use ($warehouseId, $warehouseName) {
                $q->where('purchases.warehouse_id', $warehouseId);
                if ($warehouseName) {
                    $q->orWhere('purchases.warehouse_name', $warehouseName);
                }
            });
        }
        if (!empty($search)) {
            $purchQuery->where(function ($q) use ($search) {
                $q->where('purchases.reference_no', 'like', "%{$search}%")
                  ->orWhere('purchase_items.product_name', 'like', "%{$search}%")
                  ->orWhere('purchases.supplier_name', 'like', "%{$search}%")
                  ->orWhere('purchases.warehouse_name', 'like', "%{$search}%");
            });
        }
        $purchasesList = $purchQuery->orderByDesc('purchases.id')->limit(100)->get()->map($mapCostRow);

        // 4. Movements: Sales Returns (cost-layer indexed lookup + bounded fallback for unlayered legacy rows)
        $saleRefNos = $salesList->pluck('reference')->unique()->filter()->toArray();
        $srCols = ['id', 'date', 'created_at', 'reference_no', 'sale_reference_no', 'customer_name', 'warehouse_name', 'grand_total', 'items_payload'];
        $buildSrBase = function () use ($allowedOutlets, $warehouseId, $warehouseName, $search) {
            $q = DB::table('sale_returns');
            $this->applyActiveDocumentFilter($q, 'status');
            if (!empty($allowedOutlets)) {
                $q->whereIn('warehouse_id', $allowedOutlets);
            }
            if ($warehouseId !== null) {
                $q->where(function ($wq) use ($warehouseId, $warehouseName) {
                    $wq->where('warehouse_id', $warehouseId);
                    if ($warehouseName) {
                        $wq->orWhere('warehouse_name', $warehouseName);
                    }
                });
            }
            if (!empty($search)) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('reference_no', 'like', "%{$search}%")
                       ->orWhere('sale_reference_no', 'like', "%{$search}%")
                       ->orWhere('customer_name', 'like', "%{$search}%")
                       ->orWhere('warehouse_name', 'like', "%{$search}%");
                });
            }
            return $q;
        };

        $layerSrIds = $this->accounting()->schemaHasTable('sale_return_cost_layers')
            ? DB::table('sale_return_cost_layers')
                ->where('product_id', $product->id)
                ->when($selectedVariantId !== null, fn($q) => $q->where('product_variant_id', $selectedVariantId))
                ->distinct()
                ->orderByDesc('sale_return_id')
                ->limit(250)
                ->pluck('sale_return_id')
                ->map(fn($v) => (int)$v)
                ->all()
            : [];

        $saleReturnsList = collect();
        $seenSrIds = [];
        $processSrRow = function ($srRow) use (&$saleReturnsList, &$seenSrIds, $saleRefNos, $mapRow, $fallbackItemName, $selectedVariantId, $productCodes, $product, $variantIds) {
            $srId = (int)$srRow->id;
            if (isset($seenSrIds[$srId])) {
                return;
            }
            $seenSrIds[$srId] = true;
            $rawSrItems = !empty($srRow->items_payload) ? json_decode((string)$srRow->items_payload, true) : null;
            if (!is_array($rawSrItems) || empty($rawSrItems)) {
                if (!empty($saleRefNos) && in_array($srRow->sale_reference_no, $saleRefNos, true)) {
                    $saleReturnsList->push($mapRow((object)[
                        'transaction_id' => $srRow->id,
                        'date'           => $srRow->date,
                        'created_at'     => $srRow->created_at,
                        'reference_no'   => $srRow->reference_no,
                        'product_name'   => $fallbackItemName,
                        'party_name'     => $srRow->customer_name,
                        'warehouse_name' => $srRow->warehouse_name,
                        'quantity'       => 1.0,
                        'subtotal'       => (float)$srRow->grand_total,
                    ]));
                }
                return;
            }
            $matchedQty      = 0.0;
            $matchedSubtotal = 0.0;
            $matchedName     = $fallbackItemName;
            foreach ($rawSrItems as $sItem) {
                $itPid          = isset($sItem['product_id']) ? (int)$sItem['product_id'] : 0;
                $itVid          = isset($sItem['product_variant_id']) ? (int)$sItem['product_variant_id'] : null;
                $itCode         = trim((string)($sItem['product_code'] ?? ''));
                $matchesProduct = $selectedVariantId !== null
                    ? ($itVid === $selectedVariantId || ($itCode !== '' && in_array($itCode, $productCodes, true)))
                    : ($itPid === (int)$product->id || ($itVid && in_array($itVid, $variantIds, true)) || ($itCode !== '' && in_array($itCode, $productCodes, true)));
                if (!$matchesProduct) {
                    continue;
                }
                $rawQty     = (float)($sItem['quantity'] ?? 0);
                $convRate   = (float)($sItem['conversion_rate'] ?? 1);
                if ($convRate <= 0) {
                    $convRate = 1.0;
                }
                $baseQtyVal = (float)($sItem['base_quantity'] ?? 0);
                $qty        = $baseQtyVal > 0.0001 ? $baseQtyVal : round($rawQty * $convRate, 4);
                $matchedQty      += $qty;
                $matchedSubtotal += (float)($sItem['subtotal'] ?? ((float)($sItem['unit_price'] ?? 0) * $rawQty));
                if (!empty($sItem['product_name'])) {
                    $matchedName = (string)$sItem['product_name'];
                }
            }
            if ($matchedQty > 0.0001) {
                $saleReturnsList->push($mapRow((object)[
                    'transaction_id' => $srRow->id,
                    'date'           => $srRow->date,
                    'created_at'     => $srRow->created_at,
                    'reference_no'   => $srRow->reference_no,
                    'product_name'   => $matchedName,
                    'party_name'     => $srRow->customer_name,
                    'warehouse_name' => $srRow->warehouse_name,
                    'quantity'       => $matchedQty,
                    'subtotal'       => $matchedSubtotal,
                ]));
            }
        };

        if (!empty($layerSrIds)) {
            foreach ($buildSrBase()->select($srCols)->whereIn('id', $layerSrIds)->orderByDesc('id')->get() as $srRow) {
                $processSrRow($srRow);
                if ($saleReturnsList->count() >= 100) {
                    break;
                }
            }
        }
        if ($saleReturnsList->count() < 100) {
            $fallbackSrQ = $buildSrBase()->select($srCols)->where(function ($q) use ($saleRefNos) {
                $q->whereNotNull('items_payload')->where('items_payload', '!=', '')->where('items_payload', '!=', '[]');
                if (!empty($saleRefNos)) {
                    $q->orWhereIn('sale_reference_no', $saleRefNos);
                }
            });
            if (!empty($layerSrIds)) {
                $fallbackSrQ->whereNotIn('id', $layerSrIds);
            }
            foreach ($fallbackSrQ->orderByDesc('id')->limit(500)->cursor() as $srRow) {
                $processSrRow($srRow);
                if ($saleReturnsList->count() >= 100) {
                    break;
                }
            }
        }
        $saleReturnsList = $saleReturnsList->sortByDesc('id')->take(100)->values();

        // 5. Movements: Purchases Returns (cost-layer indexed lookup + bounded fallback for unlayered legacy rows)
        $purchRefNos = $purchasesList->pluck('reference')->unique()->filter()->toArray();
        $prCols = ['id', 'date', 'created_at', 'reference_no', 'purchase_reference_no', 'supplier_name', 'warehouse_name', 'grand_total', 'items_payload'];
        $buildPrBase = function () use ($allowedOutlets, $warehouseId, $warehouseName, $search) {
            $q = DB::table('purchase_returns')->whereIn('status', ['Completed', 'Received']);
            if (!empty($allowedOutlets)) {
                $q->whereIn('warehouse_id', $allowedOutlets);
            }
            if ($warehouseId !== null) {
                $q->where(function ($wq) use ($warehouseId, $warehouseName) {
                    $wq->where('warehouse_id', $warehouseId);
                    if ($warehouseName) {
                        $wq->orWhere('warehouse_name', $warehouseName);
                    }
                });
            }
            if (!empty($search)) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('reference_no', 'like', "%{$search}%")
                       ->orWhere('purchase_reference_no', 'like', "%{$search}%")
                       ->orWhere('supplier_name', 'like', "%{$search}%")
                       ->orWhere('warehouse_name', 'like', "%{$search}%");
                });
            }
            return $q;
        };

        $layerPrIds = $this->accounting()->schemaHasTable('purchase_return_cost_layers')
            ? DB::table('purchase_return_cost_layers')
                ->where('product_id', $product->id)
                ->when($selectedVariantId !== null, fn($q) => $q->where('product_variant_id', $selectedVariantId))
                ->distinct()
                ->orderByDesc('purchase_return_id')
                ->limit(250)
                ->pluck('purchase_return_id')
                ->map(fn($v) => (int)$v)
                ->all()
            : [];

        $purchReturnsList = collect();
        $seenPrIds = [];
        $processPrRow = function ($prRow) use (&$purchReturnsList, &$seenPrIds, $purchRefNos, $mapCostRow, $fallbackItemName, $selectedVariantId, $productCodes, $product, $variantIds) {
            $prId = (int)$prRow->id;
            if (isset($seenPrIds[$prId])) {
                return;
            }
            $seenPrIds[$prId] = true;
            $rawPrItems = !empty($prRow->items_payload) ? json_decode((string)$prRow->items_payload, true) : null;
            if (!is_array($rawPrItems) || empty($rawPrItems)) {
                if (!empty($purchRefNos) && in_array($prRow->purchase_reference_no, $purchRefNos, true)) {
                    $purchReturnsList->push($mapCostRow((object)[
                        'transaction_id' => $prRow->id,
                        'date'           => $prRow->date,
                        'created_at'     => $prRow->created_at,
                        'reference_no'   => $prRow->reference_no,
                        'product_name'   => $fallbackItemName,
                        'party_name'     => $prRow->supplier_name,
                        'warehouse_name' => $prRow->warehouse_name,
                        'quantity'       => 1.0,
                        'subtotal'       => (float)$prRow->grand_total,
                    ]));
                }
                return;
            }
            $matchedQty = 0.0;
            $matchedSubtotal = 0.0;
            $matchedName = $fallbackItemName;
            foreach ($rawPrItems as $pItem) {
                $itPid = isset($pItem['product_id']) ? (int)$pItem['product_id'] : 0;
                $itVid = isset($pItem['product_variant_id']) ? (int)$pItem['product_variant_id'] : null;
                $itCode = trim((string)($pItem['product_code'] ?? ''));
                $matchesProduct = $selectedVariantId !== null
                    ? ($itVid === $selectedVariantId || ($itCode !== '' && in_array($itCode, $productCodes, true)))
                    : ($itPid === (int)$product->id || ($itVid && in_array($itVid, $variantIds, true)) || ($itCode !== '' && in_array($itCode, $productCodes, true)));
                if (!$matchesProduct) {
                    continue;
                }
                $rawQty = (float)($pItem['quantity'] ?? 0);
                $convRate = (float)($pItem['conversion_rate'] ?? 1);
                if ($convRate <= 0) {
                    $convRate = 1.0;
                }
                $baseQtyVal = (float)($pItem['base_quantity'] ?? 0);
                $qty = $baseQtyVal > 0.0001 ? $baseQtyVal : round($rawQty * $convRate, 4);
                $matchedQty += $qty;
                $matchedSubtotal += (float)($pItem['subtotal'] ?? ((float)($pItem['unit_cost'] ?? 0) * $rawQty));
                if (!empty($pItem['product_name'])) {
                    $matchedName = (string)$pItem['product_name'];
                }
            }
            if ($matchedQty > 0.0001) {
                $purchReturnsList->push($mapCostRow((object)[
                    'transaction_id' => $prRow->id,
                    'date'           => $prRow->date,
                    'created_at'     => $prRow->created_at,
                    'reference_no'   => $prRow->reference_no,
                    'product_name'   => $matchedName,
                    'party_name'     => $prRow->supplier_name,
                    'warehouse_name' => $prRow->warehouse_name,
                    'quantity'       => round($matchedQty, 4),
                    'subtotal'       => round($matchedSubtotal, 2),
                ]));
            }
        };

        if (!empty($layerPrIds)) {
            foreach ($buildPrBase()->select($prCols)->whereIn('id', $layerPrIds)->orderByDesc('id')->get() as $prRow) {
                $processPrRow($prRow);
                if ($purchReturnsList->count() >= 100) {
                    break;
                }
            }
        }
        if ($purchReturnsList->count() < 100) {
            $fallbackPrQ = $buildPrBase()->select($prCols)->where(function ($q) use ($purchRefNos) {
                $q->whereNotNull('items_payload')->where('items_payload', '!=', '')->where('items_payload', '!=', '[]');
                if (!empty($purchRefNos)) {
                    $q->orWhereIn('purchase_reference_no', $purchRefNos);
                }
            });
            if (!empty($layerPrIds)) {
                $fallbackPrQ->whereNotIn('id', $layerPrIds);
            }
            foreach ($fallbackPrQ->orderByDesc('id')->limit(500)->cursor() as $prRow) {
                $processPrRow($prRow);
                if ($purchReturnsList->count() >= 100) {
                    break;
                }
            }
        }
        $purchReturnsList = $purchReturnsList->sortByDesc('id')->take(100)->values();

        // 6. Movements: Adjustments
        $adjQuery = DB::table('stock_adjustment_items')
            ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id');

        if ($selectedVariantId !== null) {
            $adjQuery->where(function ($q) use ($selectedVariantId, $productCodes) {
                $q->where('stock_adjustment_items.product_variant_id', $selectedVariantId);
                if (!empty($productCodes)) {
                    $q->orWhereIn('stock_adjustment_items.product_code', $productCodes);
                }
            });
        } else {
            $adjQuery->where(function ($q) use ($product, $variantIds, $productCodes) {
                $q->where('stock_adjustment_items.product_id', $product->id);
                if (!empty($variantIds)) {
                    $q->orWhereIn('stock_adjustment_items.product_variant_id', $variantIds);
                }
                if (!empty($productCodes)) {
                    $q->orWhereIn('stock_adjustment_items.product_code', $productCodes);
                }
            });
        }

        $adjQuery->select(
            'stock_adjustments.id as transaction_id',
            'stock_adjustments.date',
            'stock_adjustments.created_at',
            'stock_adjustments.reference_no',
            'stock_adjustment_items.product_name',
            DB::raw("COALESCE(NULLIF(TRIM(stock_adjustment_items.type), ''), stock_adjustments.type) as party_name"),
            'stock_adjustments.warehouse_name',
            DB::raw("COALESCE(NULLIF(stock_adjustment_items.base_quantity, 0), stock_adjustment_items.quantity * COALESCE(NULLIF(stock_adjustment_items.conversion_rate, 0), 1)) as quantity"),
            'stock_adjustment_items.subtotal'
        );

        if (!empty($allowedOutlets)) {
            $adjQuery->whereIn('stock_adjustments.warehouse_id', $allowedOutlets);
        }
        if ($warehouseId !== null) {
            $adjQuery->where(function ($q) use ($warehouseId, $warehouseName) {
                $q->where('stock_adjustments.warehouse_id', $warehouseId);
                if ($warehouseName) {
                    $q->orWhere('stock_adjustments.warehouse_name', $warehouseName);
                }
            });
        }
        if (!empty($search)) {
            $adjQuery->where(function ($q) use ($search) {
                $q->where('stock_adjustments.reference_no', 'like', "%{$search}%")
                  ->orWhere('stock_adjustment_items.product_name', 'like', "%{$search}%")
                  ->orWhere('stock_adjustment_items.type', 'like', "%{$search}%")
                  ->orWhere('stock_adjustments.warehouse_name', 'like', "%{$search}%");
            });
        }
        $adjustmentsList = $adjQuery->orderByDesc('stock_adjustments.id')->limit(100)->get()->map($mapCostRow);

        // 7. Movements: Transfers
        $transQuery = DB::table('stock_transfer_items')
            ->join('stock_transfers', 'stock_transfer_items.stock_transfer_id', '=', 'stock_transfers.id')
            ->whereIn('stock_transfers.status', ['Sent', 'Completed']);

        if ($selectedVariantId !== null) {
            $transQuery->where(function ($q) use ($selectedVariantId, $productCodes) {
                $q->where('stock_transfer_items.product_variant_id', $selectedVariantId);
                if (!empty($productCodes)) {
                    $q->orWhereIn('stock_transfer_items.product_code', $productCodes);
                }
            });
        } else {
            $transQuery->where(function ($q) use ($product, $variantIds, $productCodes) {
                $q->where('stock_transfer_items.product_id', $product->id);
                if (!empty($variantIds)) {
                    $q->orWhereIn('stock_transfer_items.product_variant_id', $variantIds);
                }
                if (!empty($productCodes)) {
                    $q->orWhereIn('stock_transfer_items.product_code', $productCodes);
                }
            });
        }

        $partyExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "(stock_transfers.from_warehouse || ' ➔ ' || stock_transfers.to_warehouse) as party_name"
            : "CONCAT(stock_transfers.from_warehouse, ' ➔ ', stock_transfers.to_warehouse) as party_name";

        $transQuery->select(
            'stock_transfers.id as transaction_id',
            'stock_transfers.date',
            'stock_transfers.created_at',
            'stock_transfers.reference_no',
            'stock_transfer_items.product_name',
            DB::raw($partyExpr),
            'stock_transfers.to_warehouse as warehouse_name',
            DB::raw('COALESCE(NULLIF(stock_transfer_items.base_quantity, 0), stock_transfer_items.quantity * COALESCE(NULLIF(stock_transfer_items.conversion_rate, 0), 1)) as quantity'),
            'stock_transfer_items.subtotal'
        );

        if (!empty($allowedOutlets)) {
            $transQuery->where(function ($q) use ($allowedOutlets) {
                $q->whereIn('stock_transfers.from_warehouse_id', $allowedOutlets)
                  ->orWhere(function ($dst) use ($allowedOutlets) {
                      $dst->where('stock_transfers.status', 'Completed')
                          ->whereIn('stock_transfers.to_warehouse_id', $allowedOutlets);
                  });
            });
        }
        if ($warehouseId !== null) {
            $transQuery->where(function ($q) use ($warehouseId, $warehouseName) {
                $q->where(function ($src) use ($warehouseId, $warehouseName) {
                    $src->where('stock_transfers.from_warehouse_id', $warehouseId);
                    if ($warehouseName) {
                        $src->orWhere('stock_transfers.from_warehouse', $warehouseName);
                    }
                })->orWhere(function ($dst) use ($warehouseId, $warehouseName) {
                    $dst->where('stock_transfers.status', 'Completed')
                        ->where(function ($dw) use ($warehouseId, $warehouseName) {
                            $dw->where('stock_transfers.to_warehouse_id', $warehouseId);
                            if ($warehouseName) {
                                $dw->orWhere('stock_transfers.to_warehouse', $warehouseName);
                            }
                        });
                });
            });
        }
        if (!empty($search)) {
            $transQuery->where(function ($q) use ($search) {
                $q->where('stock_transfers.reference_no', 'like', "%{$search}%")
                  ->orWhere('stock_transfer_items.product_name', 'like', "%{$search}%")
                  ->orWhere('stock_transfers.from_warehouse', 'like', "%{$search}%")
                  ->orWhere('stock_transfers.to_warehouse', 'like', "%{$search}%");
            });
        }
        $transfersList = $transQuery->orderByDesc('stock_transfers.id')->limit(100)->get()->map($mapCostRow);

        $variantStockMap = [];
        if ($product->variants && $product->variants->isNotEmpty() && ($warehouseId !== null || !empty($allowedOutlets))) {
            $vsQ = DB::table('stock_balances')
                ->where('product_id', $product->id)
                ->whereNotNull('product_variant_id');
            if ($warehouseId !== null) {
                $vsQ->where('warehouse_id', $warehouseId);
            } else {
                $vsQ->whereIn('warehouse_id', $allowedOutlets);
            }
            $variantStockMap = $vsQ->groupBy('product_variant_id')
                ->selectRaw('product_variant_id, COALESCE(SUM(quantity), 0) as qty')
                ->pluck('qty', 'product_variant_id')
                ->all();
        }

        $mappedVariants = [];
        if ($product->variants && $product->variants->isNotEmpty()) {
            foreach ($product->variants as $v) {
                $vStock = ($warehouseId !== null || !empty($allowedOutlets))
                    ? (float)($variantStockMap[$v->id] ?? 0.0)
                    : (float)($v->stock_quantity ?? 0.0);
                $mappedVariants[] = [
                    'id' => (int)$v->id,
                    'name' => (string)$v->name,
                    'sku' => (string)($v->sku ?? ''),
                    'barcode' => (string)($v->barcode ?? ''),
                    'cost_price' => $canViewCost ? (float)$v->cost_price : 0.0,
                    'retail_price' => (float)($v->selling_price ?? $v->retail_price ?? 0.0),
                    'stock_quantity' => $vStock,
                    'image_url' => (string)($v->image_url ?? ''),
                ];
            }
        }

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'data' => [
                'product' => [
                    'id' => $product->id,
                    'variant_id' => $selectedVariant ? $selectedVariant->id : null,
                    'name' => $fallbackItemName,
                    'code' => $selectedVariant ? ($selectedVariant->sku ?? $selectedVariant->barcode ?? $product->code ?? '') : ($product->code ?? $product->sku ?? ''),
                    'sku' => $selectedVariant ? ($selectedVariant->sku ?? $product->sku ?? '') : ($product->sku ?? ''),
                    'category_name' => $product->category_name ?? '',
                    'unit_name' => $product->unit_name ?? '',
                    'stock_quantity' => $effectiveStockQty,
                    'cost_price' => $canViewCost ? (float)($selectedVariant ? $selectedVariant->cost_price : $product->cost_price) : 0.0,
                    'retail_price' => (float)($selectedVariant ? $selectedVariant->retail_price : $product->retail_price),
                    'image_url' => $selectedVariant && !empty($selectedVariant->image_url) ? $selectedVariant->image_url : ($product->image_url ?? ''),
                    'has_variants' => (bool)($product->has_variants || count($mappedVariants) > 0),
                    'variants_count' => count($mappedVariants),
                    'variants' => $mappedVariants,
                ],
                'warehouse_stocks' => $warehouseStocks,
                'movements' => [
                    'sales' => $salesList,
                    'sale_returns' => $saleReturnsList,
                    'purchases' => $purchasesList,
                    'purchase_returns' => $purchReturnsList,
                    'adjustments' => $adjustmentsList,
                    'transfers' => $transfersList,
                ],
            ],
        ]);
    }

    /**
     * Stock Alert Report (Low stock / Out of stock below threshold)
     */
    public function stockAlertReport(Request $request)
    {
        $threshold = (float)($request->input('threshold', 10));
        $warehouseParam = $request->input('warehouse_id') ?? $request->input('warehouse') ?? $request->input('warehouse_name') ?? 'All';
        $warehouseId = ($warehouseParam !== 'All' && !empty($warehouseParam))
            ? $this->resolveWarehouseIdParam(is_scalar($warehouseParam) ? (string)$warehouseParam : null)
            : null;
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        if (!empty($allowedOutlets) && $warehouseId !== null && !in_array($warehouseId, $allowedOutlets, true)) {
            return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
        }
        $category = trim((string)$request->input('category', $request->input('category_name', 'All')));
        $search = trim((string)$request->input('search', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;
        $scopeWhIds = $warehouseId !== null ? [$warehouseId] : $allowedOutlets;
        $hasVariantsTable = $this->accounting()->schemaHasTable('product_variants');
        $varNameExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "(products.name || ' (' || product_variants.name || ')')"
            : "CONCAT(products.name, ' (', product_variants.name, ')')";

        $buildAlertQuery = function () use ($threshold, $scopeWhIds, $category, $search, $hasVariantsTable, $varNameExpr) {
            if (!empty($scopeWhIds)) {
                $singleQuery = DB::table('products')
                    ->leftJoin('stock_balances', function ($join) use ($scopeWhIds) {
                        $join->on('products.id', '=', 'stock_balances.product_id')
                             ->whereNull('stock_balances.product_variant_id')
                             ->whereIn('stock_balances.warehouse_id', $scopeWhIds);
                    })
                    ->where(function ($q) {
                        $q->where('products.has_variants', false)->orWhereNull('products.has_variants');
                    })
                    ->whereNull('products.deleted_at');
                if ($category !== '' && $category !== 'All') {
                    $singleQuery->where('products.category_name', $category);
                }
                if ($search !== '') {
                    $singleQuery->where(function ($q) use ($search) {
                        $q->where('products.name', 'like', "%{$search}%")
                          ->orWhere('products.code', 'like', "%{$search}%")
                          ->orWhere('products.sku', 'like', "%{$search}%");
                    });
                }
                $singleQuery->selectRaw("
                        products.id as id,
                        products.name as name,
                        COALESCE(NULLIF(products.code, ''), products.sku, '') as sku,
                        COALESCE(products.category_name, 'General') as category,
                        COALESCE(SUM(stock_balances.quantity), 0) as current_stock,
                        COALESCE(NULLIF(products.alert_quantity, 0), NULLIF(products.stock_alert, 0), NULLIF(products.low_qty, 0), ?) as min_stock,
                        COALESCE(products.unit_name, 'pcs') as unit,
                        0 as is_variant
                    ", [$threshold])
                    ->groupBy(
                        'products.id', 'products.name', 'products.code', 'products.sku', 'products.category_name',
                        'products.unit_name', 'products.alert_quantity', 'products.stock_alert', 'products.low_qty'
                    )
                    ->havingRaw('COALESCE(SUM(stock_balances.quantity), 0) <= ?', [$threshold]);
            } else {
                $singleQuery = DB::table('products')
                    ->where(function ($q) {
                        $q->where('has_variants', false)->orWhereNull('has_variants');
                    })
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($threshold) {
                        $q->where('stock_quantity', '<=', $threshold)
                          ->orWhere('stock_quantity', '<=', 0);
                    });
                if ($category !== '' && $category !== 'All') {
                    $singleQuery->where('category_name', $category);
                }
                if ($search !== '') {
                    $singleQuery->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%")
                          ->orWhere('sku', 'like', "%{$search}%");
                    });
                }
                $singleQuery->selectRaw("
                    id,
                    name,
                    COALESCE(NULLIF(code, ''), sku, '') as sku,
                    COALESCE(category_name, 'General') as category,
                    COALESCE(stock_quantity, 0) as current_stock,
                    COALESCE(NULLIF(alert_quantity, 0), NULLIF(stock_alert, 0), NULLIF(low_qty, 0), ?) as min_stock,
                    COALESCE(unit_name, 'pcs') as unit,
                    0 as is_variant
                ", [$threshold]);
            }

            if ($hasVariantsTable) {
                $varQ = DB::table('product_variants')
                    ->join('products', 'product_variants.product_id', '=', 'products.id')
                    ->leftJoin('stock_balances', function ($join) use ($scopeWhIds) {
                        $join->on('product_variants.id', '=', 'stock_balances.product_variant_id');
                        if (!empty($scopeWhIds)) {
                            $join->whereIn('stock_balances.warehouse_id', $scopeWhIds);
                        }
                    })
                    ->where('products.has_variants', true)
                    ->whereNull('products.deleted_at')
                    ->whereNull('product_variants.deleted_at');
                if ($category !== '' && $category !== 'All') {
                    $varQ->where('products.category_name', $category);
                }
                if ($search !== '') {
                    $varQ->where(function ($q) use ($search) {
                        $q->where('products.name', 'like', "%{$search}%")
                          ->orWhere('product_variants.name', 'like', "%{$search}%")
                          ->orWhere('product_variants.sku', 'like', "%{$search}%")
                          ->orWhere('products.code', 'like', "%{$search}%");
                    });
                }
                $varQ->selectRaw("
                        product_variants.id as id,
                        {$varNameExpr} as name,
                        COALESCE(NULLIF(product_variants.sku, ''), products.code, '') as sku,
                        COALESCE(products.category_name, 'General') as category,
                        COALESCE(SUM(stock_balances.quantity), 0) as current_stock,
                        COALESCE(NULLIF(products.alert_quantity, 0), NULLIF(products.stock_alert, 0), NULLIF(products.low_qty, 0), ?) as min_stock,
                        COALESCE(products.unit_name, 'pcs') as unit,
                        1 as is_variant
                    ", [$threshold])
                    ->groupBy(
                        'product_variants.id', 'products.name', 'product_variants.name', 'product_variants.sku',
                        'products.code', 'products.category_name', 'products.unit_name',
                        'products.alert_quantity', 'products.stock_alert', 'products.low_qty'
                    )
                    ->havingRaw('COALESCE(SUM(stock_balances.quantity), 0) <= ?', [$threshold]);

                return $singleQuery->unionAll($varQ);
            }

            return $singleQuery;
        };

        $totalCount = $knownTotal ?? (int)DB::query()->fromSub($buildAlertQuery(), 'alerts')->count();
        $rows = DB::query()
            ->fromSub($buildAlertQuery(), 'alerts')
            ->orderBy('current_stock', 'asc')
            ->orderBy('is_variant', 'asc')
            ->orderBy('id', 'asc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get();

        $hasMore = $rows->count() > $perPage;
        $pageRows = $hasMore ? $rows->slice(0, $perPage)->values() : $rows->values();
        $items = $pageRows->map(function ($r) {
            return [
                'id' => (int)$r->id,
                'name' => $r->name,
                'sku' => $r->sku ?? '',
                'category' => $r->category ?? 'General',
                'current_stock' => (float)$r->current_stock,
                'min_stock' => (float)$r->min_stock,
                'unit' => $r->unit ?? 'pcs',
            ];
        })->values();
        $lastPage = max(1, (int)ceil($totalCount / max(1, $perPage)));

        return $this->noStoreJson([
            'status' => 'success',
            'data' => [
                'threshold' => $threshold,
                'total' => $totalCount,
                'items' => $items,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $totalCount,
                    'last_page' => $lastPage,
                    'has_more' => $hasMore,
                ],
            ],
        ]);
    }

    /**
     * Sales Returns Report
     */
    public function saleReturnsReport(Request $request)
    {
        $warehouse = $request->input('warehouse', 'All');
        $customer = $request->input('customer', 'All');
        $status = $request->input('status', 'All');
        $paymentStatus = $request->input('payment_status', 'All');
        $paymentType = $request->input('payment_type', 'All');
        $userName = $request->input('user_name', 'All');
        $startDate = $request->input('start_date') ?? $request->input('date_from');
        $endDate = $request->input('end_date') ?? $request->input('date_to');
        $search = trim((string)$request->input('search', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        $query = DB::table('sale_returns');
        if (!empty($allowedOutlets)) {
            $query->whereIn('warehouse_id', $allowedOutlets);
        }

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $query->where(function ($q) use ($warehouse) {
                $q->where('warehouse_id', $warehouse)
                  ->orWhere('warehouse_name', $warehouse);
            });
        }

        if ($customer !== 'All' && !empty($customer)) {
            $query->where(function ($q) use ($customer) {
                $q->where('customer_id', $customer)
                  ->orWhere('customer_name', $customer);
            });
        }

        if ($status !== 'All' && !empty($status)) {
            $query->where('status', $status);
        } else {
            $this->applyActiveDocumentFilter($query, 'status');
        }

        if ($paymentStatus !== 'All' && !empty($paymentStatus)) {
            $query->where('payment_status', $paymentStatus);
        }

        if ($paymentType !== 'All' && !empty($paymentType)) {
            $query->where('payment_type', $paymentType);
        }

        if ($userName !== 'All' && !empty($userName)) {
            $query->where('created_by', $userName);
        }

        $this->applyReportDateRange($query, $startDate, $endDate, 'date');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('sale_reference_no', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $totalReturnsCount = 0;
        $totalReturnedAmt = 0.0;
        $totalPaidAmt = 0.0;
        $totalDueAmt = 0.0;
        $avgReturnAmt = 0.0;
        $byPaymentMethodList = [];
        $byProductList = [];

        if (!$skipSummary) {
            // High-Performance KPI Aggregation in Single DB Query
            $summary = (clone $query)->selectRaw('
                COUNT(*) as total_returns,
                COALESCE(SUM(grand_total), 0) as total_returned_amount,
                COALESCE(SUM(paid_amount), 0) as total_paid_amount,
                COALESCE(SUM(due_amount), 0) as total_due_amount
            ')->first();

            $totalReturnsCount = (int)($summary->total_returns ?? 0);
            $totalReturnedAmt = (float)($summary->total_returned_amount ?? 0);
            $totalPaidAmt = (float)($summary->total_paid_amount ?? 0);
            $totalDueAmt = (float)($summary->total_due_amount ?? 0);
            $avgReturnAmt = $totalReturnsCount > 0 ? round($totalReturnedAmt / $totalReturnsCount, 2) : 0.0;

            // Breakdown by Refund Tender / Payment Method
            $paymentBreakdown = (clone $query)->select(
                DB::raw("COALESCE(NULLIF(payment_type, ''), 'Cash') as method_name"),
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(paid_amount), 0) as total_refunded'),
                DB::raw('COALESCE(SUM(grand_total), 0) as total_returned')
            )
            ->groupBy('method_name')
            ->orderByDesc('total_refunded')
            ->get();

            foreach ($paymentBreakdown as $pb) {
                $pRefunded = (float)$pb->total_refunded;
                $byPaymentMethodList[] = [
                    'payment_type' => $pb->method_name,
                    'count' => (int)$pb->count,
                    'total' => $pRefunded,
                    'total_amount' => $pRefunded,
                    'total_returned' => (float)$pb->total_returned,
                    'percentage' => $totalPaidAmt > 0 ? round(($pRefunded / $totalPaidAmt) * 100, 2) : 0.0,
                ];
            }

            // Streaming Breakdown by Product across full filtered scope (no arbitrary 1,000-row cutoff)
            $productMap = [];
            $payloadCursor = (clone $query)
                ->whereNotNull('items_payload')
                ->where('items_payload', '!=', '')
                ->where('items_payload', '!=', '[]')
                ->select(['items_payload'])
                ->cursor();
            foreach ($payloadCursor as $row) {
                $decoded = json_decode((string)$row->items_payload, true);
                if (!is_array($decoded)) {
                    continue;
                }
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $pName = (string)($item['product_name'] ?? $item['name'] ?? 'Returned Item');
                    $pCode = (string)($item['product_code'] ?? $item['code'] ?? 'SKU-001');
                    $qty = (float)($item['quantity'] ?? $item['qty'] ?? 1);
                    $subtotal = (float)($item['subtotal'] ?? $item['total'] ?? ($qty * (float)($item['unit_price'] ?? 0)));
                    $key = $pCode . '|' . $pName;
                    if (!isset($productMap[$key])) {
                        $productMap[$key] = [
                            'product_name' => $pName,
                            'product_code' => $pCode,
                            'quantity' => 0.0,
                            'total_amount' => 0.0,
                        ];
                    }
                    $productMap[$key]['quantity'] += $qty;
                    $productMap[$key]['total_amount'] += $subtotal;
                }
            }

            foreach ($productMap as $prod) {
                $amt = (float)$prod['total_amount'];
                $byProductList[] = [
                    'product_name' => $prod['product_name'],
                    'product_code' => $prod['product_code'],
                    'quantity' => (float)$prod['quantity'],
                    'total_amount' => $amt,
                    'total' => $amt,
                    'percentage' => $totalReturnedAmt > 0 ? round(($amt / $totalReturnedAmt) * 100, 2) : 0.0,
                ];
            }
            usort($byProductList, function ($a, $b) {
                return $b['total_amount'] <=> $a['total_amount'];
            });
        } elseif ($knownTotal !== null) {
            $totalReturnsCount = $knownTotal;
        } else {
            $totalReturnsCount = (int)(clone $query)->count();
        }

        // Paginated Results (Explicit projection excluding bulky payloads)
        $listQuery = (clone $query)->select([
            'id', 'reference_no', 'sale_reference_no', 'customer_id', 'customer_name',
            'warehouse_id', 'warehouse_name', 'date', 'status', 'grand_total', 'paid_amount',
            'due_amount', 'payment_status', 'payment_type', 'notes', 'created_by',
            'user_id', 'user_name', 'user_role', 'created_at', 'updated_at',
        ]);
        $rawCursor = $request->query('cursor');
        $cursor = ListCursorHelper::decode(is_scalar($rawCursor) ? (string)$rawCursor : null, 'date', 'desc', ['date', 'id']);
        if ($cursor !== null) {
            ListCursorHelper::apply($listQuery, 'date', 'desc', $cursor);
        } elseif ($page > 1) {
            $listQuery->offset(($page - 1) * $perPage);
        }

        $rows = $listQuery->orderByDesc('date')->orderByDesc('id')->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $pageRows = $hasMore ? $rows->slice(0, $perPage)->values() : $rows->values();
        $lastRow = $pageRows->last();
        $nextCursor = ($hasMore && $lastRow)
            ? ListCursorHelper::encode($lastRow, 'date', 'desc', ['date', 'id'])
            : null;
        $lastPage = max(1, (int)ceil($totalReturnsCount / max(1, $perPage)));

        $items = $pageRows->map(function ($r) {
            return [
                'id' => $r->id,
                'reference_no' => $r->reference_no ?? ('SR-' . $r->id),
                'sale_reference_no' => $r->sale_reference_no ?? '—',
                'customer_id' => $r->customer_id ?? null,
                'customer_name' => $r->customer_name ?? 'Walk-in Customer',
                'warehouse_id' => $r->warehouse_id ?? null,
                'warehouse_name' => $r->warehouse_name ?? 'Main Outlet',
                'date' => $r->date ? substr($r->date, 0, 10) : date('Y-m-d'),
                'status' => $r->status ?? 'Completed',
                'grand_total' => (float)($r->grand_total ?? 0),
                'paid_amount' => (float)($r->paid_amount ?? 0),
                'due_amount' => (float)($r->due_amount ?? 0),
                'payment_status' => $r->payment_status ?? 'Paid',
                'payment_type' => $r->payment_type ?? 'Cash',
                'notes' => $r->notes ?? '',
                'created_by' => $r->created_by ?? ($r->user_name ?? 'Super Admin'),
                'user_id' => $r->user_id ?? null,
                'user_name' => $r->user_name ?? ($r->created_by ?? 'Super Admin'),
                'user_role' => $r->user_role ?? 'Super Admin',
                'created_at' => $r->created_at ?? null,
                'updated_at' => $r->updated_at ?? null,
            ];
        })->all();

        return $this->noStoreJson([
            'status' => 'success',
            'summary' => [
                'total_returns' => $totalReturnsCount,
                'total_returned_amount' => $totalReturnedAmt,
                'total_paid_amount' => $totalPaidAmt,
                'total_due_amount' => $totalDueAmt,
                'average_return_amount' => $avgReturnAmt,
            ],
            'data' => $items,
            'by_product' => $byProductList,
            'by_refund_tender' => $byPaymentMethodList,
            'by_payment_method' => $byPaymentMethodList,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalReturnsCount,
                'last_page' => $lastPage,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * Purchase Returns Report - Real-time Uncached Accounting Report
     */
    public function purchaseReturnsReport(Request $request)
    {
        $warehouse = $request->input('warehouse') ?? $request->input('warehouse_id') ?? $request->input('outlet_id') ?? 'All';
        $supplier = $request->input('supplier') ?? $request->input('supplier_id') ?? 'All';
        $status = $request->input('status', 'All');
        $paymentStatus = $request->input('payment_status', 'All');
        $paymentType = $request->input('payment_type', 'All');
        $userName = $request->input('user_name') ?? $request->input('created_by') ?? 'All';
        $startDate = $request->input('start_date') ?? $request->input('date_from');
        $endDate = $request->input('end_date') ?? $request->input('date_to');
        $search = trim((string)$request->input('search', ''));
        $sortBy = (string)$request->input('sort_by', 'date');
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;

        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        $query = DB::table('purchase_returns');
        if (!empty($allowedOutlets)) {
            $query->whereIn('warehouse_id', $allowedOutlets);
        }

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $query->where(function ($q) use ($warehouse) {
                $q->where('warehouse_id', $warehouse)
                  ->orWhere('outlet_id', $warehouse)
                  ->orWhere('warehouse_name', $warehouse);
            });
        }

        if ($supplier !== 'All' && !empty($supplier)) {
            $query->where(function ($q) use ($supplier) {
                $q->where('supplier_id', $supplier)
                  ->orWhere('supplier_name', $supplier);
            });
        }

        if ($status !== 'All' && !empty($status)) {
            if (in_array($status, ['Completed', 'Received'], true)) {
                $query->whereIn('status', ['Completed', 'Received']);
            } else {
                $query->where('status', $status);
            }
        } else {
            // Exclude Pending drafts from financial default scope
            $query->whereIn('status', ['Completed', 'Received']);
        }

        if ($paymentStatus !== 'All' && !empty($paymentStatus)) {
            $query->where('payment_status', $paymentStatus);
        }

        if ($paymentType !== 'All' && !empty($paymentType)) {
            $query->where(function ($q) use ($paymentType) {
                $q->where('payment_type', $paymentType);
                if ($paymentType !== 'Split Payment') {
                    $q->orWhere(function ($sq) use ($paymentType) {
                        $sq->where('payment_type', 'Split Payment')
                           ->where('payments_payload', 'like', '%' . addcslashes((string)$paymentType, '%_\\') . '%');
                    });
                }
            });
        }

        if ($userName !== 'All' && !empty($userName)) {
            $trimmedUser = trim((string)$userName);
            $query->where(function ($q) use ($trimmedUser) {
                $q->where('user_name', $trimmedUser)
                  ->orWhere('created_by', $trimmedUser)
                  ->orWhere('created_by', 'like', $trimmedUser . ' (%');
            });
        }

        $this->applyReportDateRange($query, !empty($startDate) ? (string)$startDate : null, !empty($endDate) ? (string)$endDate : null, 'date');

        if (!empty($search)) {
            $altHyphen = str_replace('_', '-', $search);
            $altUnderscore = str_replace('-', '_', $search);
            $query->where(function ($q) use ($search, $altHyphen, $altUnderscore) {
                if (preg_match('/^#?(\d+)$/', $search, $m)) {
                    $q->where('id', (int)$m[1])
                      ->orWhere('reference_no', 'like', "%{$search}%");
                } else {
                    $q->where('reference_no', 'like', "%{$search}%");
                }
                if ($altHyphen !== $search) {
                    $q->orWhere('reference_no', 'like', "%{$altHyphen}%")
                      ->orWhere('purchase_reference_no', 'like', "%{$altHyphen}%");
                }
                if ($altUnderscore !== $search) {
                    $q->orWhere('reference_no', 'like', "%{$altUnderscore}%")
                      ->orWhere('purchase_reference_no', 'like', "%{$altUnderscore}%");
                }
                $q->orWhere('purchase_reference_no', 'like', "%{$search}%")
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

        $totalReturnsCount = 0;
        $totalReturnedAmt = 0.0;
        $totalPaidAmt = 0.0;
        $totalDueAmt = 0.0;
        $avgReturnAmt = 0.0;
        $byPaymentMethodList = [];
        $byProductList = [];

        if (!$skipSummary) {
            // High-Performance KPI Aggregation in Single DB Query
            $summary = (clone $query)->selectRaw('
                COUNT(*) as total_returns,
                COALESCE(SUM(grand_total), 0) as total_returned_amount,
                COALESCE(SUM(paid_amount), 0) as total_paid_amount,
                COALESCE(SUM(due_amount), 0) as total_due_amount
            ')->first();

            $totalReturnsCount = (int)($summary->total_returns ?? 0);
            $totalReturnedAmt = (float)($summary->total_returned_amount ?? 0);
            $totalPaidAmt = (float)($summary->total_paid_amount ?? 0);
            $totalDueAmt = (float)($summary->total_due_amount ?? 0);
            $avgReturnAmt = $totalReturnsCount > 0 ? round($totalReturnedAmt / $totalReturnsCount, 2) : 0.0;

            // Single streaming pass across ALL matching rows for accurate Split Payment & Product breakdowns
            $tenderMap = [];
            $productMap = [];

            $payloadCursor = (clone $query)
                ->select('id', 'reference_no', 'grand_total', 'paid_amount', 'payment_type', 'payments_payload', 'items_payload')
                ->cursor();

            foreach ($payloadCursor as $row) {
                $rowId = (int)$row->id;
                $rowGrand = (float)($row->grand_total ?? 0);
                $rowPaid = (float)($row->paid_amount ?? 0);
                $fallbackMethod = trim((string)($row->payment_type ?? ''));
                if ($fallbackMethod === '') {
                    $fallbackMethod = 'Cash';
                }

                // A. Settlement / Refund Tender attribution (splitting Split Payment payloads by leg)
                $allocatedFromLegs = false;
                if (!empty($row->payments_payload)) {
                    $decodedPayments = is_string($row->payments_payload)
                        ? json_decode($row->payments_payload, true)
                        : $row->payments_payload;
                    if (is_array($decodedPayments) && !empty($decodedPayments)) {
                        $validLegs = [];
                        $legsSum = 0.0;
                        foreach ($decodedPayments as $leg) {
                            if (!is_array($leg)) {
                                continue;
                            }
                            $legAmt = (float)($leg['amount'] ?? 0);
                            if ($legAmt <= 0) {
                                continue;
                            }
                            $legType = trim((string)($leg['payment_type'] ?? $leg['method'] ?? ''));
                            if ($legType === '' || $legType === 'Split Payment') {
                                $legType = $fallbackMethod === 'Split Payment' ? 'Cash' : $fallbackMethod;
                            }
                            $validLegs[] = ['type' => $legType, 'amount' => $legAmt];
                            $legsSum += $legAmt;
                        }

                        if ($legsSum > 0 && !empty($validLegs)) {
                            $allocatedFromLegs = true;
                            $paidScale = ($legsSum > $rowPaid + 0.01 && $legsSum > 0) ? ($rowPaid / $legsSum) : 1.0;
                            foreach ($validLegs as $vLeg) {
                                $m = $vLeg['type'];
                                if (!isset($tenderMap[$m])) {
                                    $tenderMap[$m] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                                }
                                $legPaidAmt = $vLeg['amount'] * $paidScale;
                                $tenderMap[$m]['voucher_ids'][$rowId] = true;
                                $tenderMap[$m]['total_refunded'] += $legPaidAmt;
                                $tenderMap[$m]['total_returned'] += $legPaidAmt;
                            }
                            if ($rowPaid - $legsSum > 0.01) {
                                $residualMethod = $fallbackMethod === 'Split Payment' ? 'Cash' : $fallbackMethod;
                                if (!isset($tenderMap[$residualMethod])) {
                                    $tenderMap[$residualMethod] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                                }
                                $resAmt = $rowPaid - $legsSum;
                                $tenderMap[$residualMethod]['voucher_ids'][$rowId] = true;
                                $tenderMap[$residualMethod]['total_refunded'] += $resAmt;
                                $tenderMap[$residualMethod]['total_returned'] += $resAmt;
                            }
                            $unpaidCreditAmt = $rowGrand - $rowPaid;
                            if ($unpaidCreditAmt > 0.01) {
                                if (!isset($tenderMap['Credit Note'])) {
                                    $tenderMap['Credit Note'] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                                }
                                $tenderMap['Credit Note']['voucher_ids'][$rowId] = true;
                                $tenderMap['Credit Note']['total_returned'] += $unpaidCreditAmt;
                            }
                        }
                    }
                }

                if (!$allocatedFromLegs) {
                    $paidTargetMethod = ($rowPaid > 0.01 && $fallbackMethod === 'Split Payment') ? 'Cash' : $fallbackMethod;
                    if ($rowPaid > 0.01) {
                        if (!isset($tenderMap[$paidTargetMethod])) {
                            $tenderMap[$paidTargetMethod] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                        }
                        $tenderMap[$paidTargetMethod]['voucher_ids'][$rowId] = true;
                        $tenderMap[$paidTargetMethod]['total_refunded'] += $rowPaid;
                        $tenderMap[$paidTargetMethod]['total_returned'] += $rowPaid;
                        $unpaidCreditAmt = $rowGrand - $rowPaid;
                        if ($unpaidCreditAmt > 0.01) {
                            if (!isset($tenderMap['Credit Note'])) {
                                $tenderMap['Credit Note'] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                            }
                            $tenderMap['Credit Note']['voucher_ids'][$rowId] = true;
                            $tenderMap['Credit Note']['total_returned'] += $unpaidCreditAmt;
                        }
                    } else {
                        if (!isset($tenderMap[$fallbackMethod])) {
                            $tenderMap[$fallbackMethod] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                        }
                        $tenderMap[$fallbackMethod]['voucher_ids'][$rowId] = true;
                        $tenderMap[$fallbackMethod]['total_returned'] += $rowGrand;
                    }
                }

                // B. Product Return Breakdown
                if (!empty($row->items_payload)) {
                    $decodedItems = is_string($row->items_payload)
                        ? json_decode($row->items_payload, true)
                        : $row->items_payload;
                    if (is_array($decodedItems)) {
                        foreach ($decodedItems as $item) {
                            if (!is_array($item)) {
                                continue;
                            }
                            $pName = (string)($item['product_name'] ?? $item['name'] ?? 'Returned Item');
                            $pCode = (string)($item['product_code'] ?? $item['code'] ?? $item['sku'] ?? 'SKU-001');
                            $qty = (float)($item['quantity'] ?? $item['qty'] ?? 1);
                            $unitCost = (float)($item['unit_cost'] ?? $item['unit_price'] ?? $item['cost'] ?? 0);
                            $rawSub = isset($item['subtotal']) && (float)$item['subtotal'] > 0
                                ? (float)$item['subtotal']
                                : (isset($item['total']) && (float)$item['total'] > 0
                                    ? (float)$item['total']
                                    : ($qty * $unitCost));
                            $key = $pCode . '|' . $pName;
                            if (!isset($productMap[$key])) {
                                $productMap[$key] = [
                                    'product_name' => $pName,
                                    'product_code' => $pCode,
                                    'quantity' => 0.0,
                                    'total_amount' => 0.0,
                                ];
                            }
                            $productMap[$key]['quantity'] += $qty;
                            $productMap[$key]['total_amount'] += $rawSub;
                        }
                    }
                }
            }

            $tenderPercentDenominator = $totalReturnedAmt > 0 ? $totalReturnedAmt : $totalPaidAmt;
            foreach ($tenderMap as $methodName => $pb) {
                $pRefunded = round((float)$pb['total_refunded'], 2);
                $pReturned = round((float)$pb['total_returned'], 2);
                $shareBasis = $totalReturnedAmt > 0 ? $pReturned : $pRefunded;
                $byPaymentMethodList[] = [
                    'payment_type' => $methodName,
                    'count' => count($pb['voucher_ids']),
                    'total' => $canViewCost ? $pRefunded : 0.0,
                    'total_amount' => $canViewCost ? $pRefunded : 0.0,
                    'total_returned' => $canViewCost ? $pReturned : 0.0,
                    'cost_masked' => !$canViewCost,
                    'percentage' => ($canViewCost && $tenderPercentDenominator > 0)
                        ? round(($shareBasis / $tenderPercentDenominator) * 100, 2)
                        : 0.0,
                ];
            }
            usort($byPaymentMethodList, function ($a, $b) {
                $cmp = $b['total_amount'] <=> $a['total_amount'];
                if ($cmp === 0) {
                    $cmp = $b['total_returned'] <=> $a['total_returned'];
                }
                if ($cmp === 0) {
                    $cmp = $b['count'] <=> $a['count'];
                }
                return $cmp === 0 ? strcmp($a['payment_type'], $b['payment_type']) : $cmp;
            });

            $productTotalSum = 0.0;
            foreach ($productMap as $prod) {
                $productTotalSum += (float)$prod['total_amount'];
            }
            $productShareDenominator = $productTotalSum > 0 ? $productTotalSum : $totalReturnedAmt;

            foreach ($productMap as $prod) {
                $amt = round((float)$prod['total_amount'], 2);
                $byProductList[] = [
                    'product_name' => $prod['product_name'],
                    'product_code' => $prod['product_code'],
                    'quantity' => (float)$prod['quantity'],
                    'total_amount' => $canViewCost ? $amt : 0.0,
                    'total' => $canViewCost ? $amt : 0.0,
                    'cost_masked' => !$canViewCost,
                    'percentage' => ($canViewCost && $productShareDenominator > 0)
                        ? round(($amt / $productShareDenominator) * 100, 2)
                        : 0.0,
                ];
            }
            usort($byProductList, function ($a, $b) {
                $cmp = $b['total_amount'] <=> $a['total_amount'];
                if ($cmp === 0) {
                    $cmp = $b['quantity'] <=> $a['quantity'];
                }
                return $cmp === 0 ? strcmp((string)$a['product_name'], (string)$b['product_name']) : $cmp;
            });
        } elseif ($knownTotal !== null) {
            $totalReturnsCount = $knownTotal;
        } else {
            $totalReturnsCount = (int)(clone $query)->count();
        }

        // Paginated Results (Explicit column projection excluding large payloads)
        $sortMap = [
            'id' => 'id',
            'date' => 'date',
            'reference' => 'reference_no',
            'reference_no' => 'reference_no',
            'purchaseRef' => 'purchase_reference_no',
            'purchase_reference_no' => 'purchase_reference_no',
            'supplier' => 'supplier_name',
            'supplier_name' => 'supplier_name',
            'warehouse' => 'warehouse_name',
            'warehouse_name' => 'warehouse_name',
            'status' => 'status',
            'amount' => 'grand_total',
            'grand_total' => 'grand_total',
            'paid' => 'paid_amount',
            'paid_amount' => 'paid_amount',
            'due' => 'due_amount',
            'due_amount' => 'due_amount',
            'paymentStatus' => 'payment_status',
            'payment_status' => 'payment_status',
            'paymentType' => 'payment_type',
            'payment_type' => 'payment_type',
            'createdBy' => 'created_by',
            'created_by' => 'created_by',
        ];
        $orderColumn = $sortMap[$sortBy] ?? 'date';
        $listQuery = (clone $query)->select([
            'id', 'reference_no', 'purchase_reference_no', 'supplier_id', 'supplier_name',
            'warehouse_id', 'outlet_id', 'warehouse_name', 'date', 'status',
            'grand_total', 'paid_amount', 'due_amount', 'subtotal', 'tax_rate', 'tax_amount',
            'is_tax_recoverable', 'discount', 'shipping', 'return_cost_total', 'cost_variance',
            'payment_status', 'payment_type', 'return_reason', 'notes', 'created_by',
            'user_id', 'user_name', 'user_role', 'created_at', 'updated_at',
        ]);

        $rawCursor = $request->query('cursor');
        $cursor = ListCursorHelper::decode(
            is_scalar($rawCursor) ? (string)$rawCursor : null,
            $orderColumn,
            $sortOrder,
            [$orderColumn]
        );
        if ($cursor !== null) {
            ListCursorHelper::apply($listQuery, $orderColumn, $sortOrder, $cursor);
        } elseif ($page > 1) {
            $listQuery->offset(($page - 1) * $perPage);
        }

        $listQuery->orderBy($orderColumn, $sortOrder);
        if ($orderColumn !== 'id') {
            $listQuery->orderBy('id', $sortOrder);
        }
        $rows = $listQuery->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $pageRows = $hasMore ? $rows->slice(0, $perPage)->values() : $rows->values();
        $lastRow = $pageRows->last();
        $nextCursor = ($hasMore && $lastRow)
            ? ListCursorHelper::encode($lastRow, $orderColumn, $sortOrder, [$orderColumn])
            : null;
        $lastPage = max(1, (int)ceil($totalReturnsCount / max(1, $perPage)));

        $pageReturnIds = $pageRows->pluck('id')->all();
        $allocMap = [];
        if (!empty($pageReturnIds) && $accounting->schemaHasTable('purchase_return_allocations')) {
            $allocMap = DB::table('purchase_return_allocations')
                ->whereIn('purchase_return_id', $pageReturnIds)
                ->whereNull('released_at')
                ->selectRaw('purchase_return_id, SUM(amount) as allocated_total')
                ->groupBy('purchase_return_id')
                ->pluck('allocated_total', 'purchase_return_id')
                ->all();
        }

        $items = $pageRows->map(function ($r) use ($accounting, $canViewCost, $allocMap) {
            $row = [
                'id' => $r->id,
                'reference_no' => $r->reference_no ?? ('PR-' . $r->id),
                'purchase_reference_no' => $r->purchase_reference_no ?? '—',
                'supplier_id' => $r->supplier_id ?? null,
                'supplier_name' => $r->supplier_name ?? 'Supplier',
                'warehouse_id' => $r->warehouse_id ?? null,
                'outlet_id' => $r->outlet_id ?? null,
                'warehouse_name' => $r->warehouse_name ?? 'Main Outlet',
                'date' => $r->date ? (string)$r->date : date('Y-m-d H:i:s'),
                'status' => $r->status ?? 'Completed',
                'grand_total' => (float)($r->grand_total ?? 0),
                'paid_amount' => (float)($r->paid_amount ?? 0),
                'due_amount' => (float)($r->due_amount ?? 0),
                'subtotal' => (float)($r->subtotal ?? 0),
                'tax_rate' => (float)($r->tax_rate ?? 0),
                'tax_amount' => (float)($r->tax_amount ?? 0),
                'is_tax_recoverable' => (bool)($r->is_tax_recoverable ?? false),
                'discount' => (float)($r->discount ?? 0),
                'shipping' => (float)($r->shipping ?? 0),
                'return_cost_total' => round((float)($r->return_cost_total ?? 0), 2),
                'cost_variance' => round((float)($r->cost_variance ?? 0), 2),
                'allocated_credit_amount' => round((float)($allocMap[$r->id] ?? 0.0), 2),
                'payment_status' => $r->payment_status ?? 'Paid',
                'payment_type' => $r->payment_type ?? 'Cash',
                'return_reason' => (string)($r->return_reason ?? ''),
                'notes' => $r->notes ?? '',
                'created_by' => $r->created_by ?? 'Super Admin',
                'user_id' => $r->user_id ?? 1,
                'user_name' => $r->user_name ?? ($r->created_by ?? 'Super Admin'),
                'user_role' => $r->user_role ?? 'Super Admin',
                'created_at' => $r->created_at ?? null,
                'updated_at' => $r->updated_at ?? null,
            ];
            return $accounting->maskPurchaseReturnRowArray($row, $canViewCost);
        })->all();

        $maskedSummary = $accounting->maskFinancialSummary([
            'total_returns' => $totalReturnsCount,
            'total_returned_amount' => $totalReturnedAmt,
            'total_paid_amount' => $totalPaidAmt,
            'total_due_amount' => $totalDueAmt,
            'average_return_amount' => $avgReturnAmt,
        ], $canViewCost, ['total_returns']);

        return $this->noStoreJson([
            'status' => 'success',
            'summary' => $maskedSummary,
            'cost_masked' => !$canViewCost,
            'data' => $items,
            'by_product' => $byProductList,
            'by_refund_tender' => $byPaymentMethodList,
            'by_payment_method' => $byPaymentMethodList,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalReturnsCount,
                'last_page' => $lastPage,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ],
        ]);
    }



    /**
     * End of Day Z-Report (Executive Multi-Session Ledger with Aggregate Summary)
     */
    public function zReport(Request $request)
    {
        $warehouseFilter = $request->input('warehouse_name') ?? $request->input('warehouse', 'All');
        $registerFilter = $request->input('register', 'All');
        $statusFilter = $request->input('status', 'All');
        $paymentStatusFilter = $request->input('payment_status', 'All');
        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate = $request->input('end_date') ?? $request->input('to_date');
        $singleDate = $request->input('date');
        $search = trim((string)$request->input('search', ''));
        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        $effStartDate = !empty($singleDate) ? (string)$singleDate : ($startDate ? (string)$startDate : null);
        $effEndDate = !empty($singleDate) ? (string)$singleDate : ($endDate ? (string)$endDate : null);

        $applyBucketFilters = function ($q, string $dateCol, ?string $wNameCol = 'warehouse_name', string $whIdCol = 'warehouse_id') use ($warehouseFilter, $effStartDate, $effEndDate, $allowedOutlets) {
            if (!empty($allowedOutlets)) {
                $q->whereIn($whIdCol, $allowedOutlets);
            }
            if ($warehouseFilter !== 'All' && !empty($warehouseFilter)) {
                $q->where(function ($wq) use ($warehouseFilter, $wNameCol, $whIdCol) {
                    if (is_numeric($warehouseFilter)) {
                        $wq->whereRaw("COALESCE({$whIdCol}, 1) = ?", [(int)$warehouseFilter]);
                    } elseif ($wNameCol !== null) {
                        $wq->where($wNameCol, $warehouseFilter);
                    }
                });
            }
            $this->applyReportDateRange($q, $effStartDate, $effEndDate, $dateCol);
            return $q;
        };

        // 1. Fetch active non-voided sales and resolve true cash/card/digital split receipts via chunked sale_payments
        $salesBaseQ = DB::table('sales')
            ->select([
                'id',
                DB::raw("SUBSTRING(date, 1, 10) as sale_date"),
                DB::raw("COALESCE(warehouse_id, 1) as w_id"),
                DB::raw("COALESCE(warehouse_name, 'Main Branch Outlet') as w_name"),
                'grand_total',
                'paid_amount',
                'tax_amount',
                'shipping',
                'discount',
                'payment_type',
                'date',
                'created_by',
                'user_name',
                'user_role',
            ]);
        $this->applyActiveDocumentFilter($salesBaseQ, 'status');
        $applyBucketFilters($salesBaseQ, 'date', 'warehouse_name', 'warehouse_id');

        $saleRows = $salesBaseQ->orderByDesc('date')->get();
        $saleIdsWithPayments = [];
        $siTaxBySale = [];
        if ($saleRows->isNotEmpty()) {
            $saleIdList = $saleRows->pluck('id')->all();
            foreach (array_chunk($saleIdList, 400) as $idChunk) {
                if ($accounting->schemaHasTable('sale_payments')) {
                    foreach (DB::table('sale_payments')->whereIn('sale_id', $idChunk)->distinct()->pluck('sale_id') as $spSid) {
                        $saleIdsWithPayments[(int)$spSid] = true;
                    }
                }
                foreach (DB::table('sale_items')->whereIn('sale_id', $idChunk)->selectRaw('sale_id, COALESCE(SUM(tax), 0) as line_tax')->groupBy('sale_id')->get() as $itRow) {
                    $siTaxBySale[(int)$itRow->sale_id] = (float)$itRow->line_tax;
                }
            }
        }

        // Helper to initialize a day+warehouse bucket cleanly
        $initZBucket = static function (string $d, int $wId, string $wName = 'Main Branch Outlet'): object {
            return (object)[
                'sale_date'     => $d,
                'w_id'          => $wId,
                'w_name'        => $wName ?: 'Main Branch Outlet',
                'tx_count'      => 0,
                'gross_sales'   => 0.0,
                'tax_amount'    => 0.0,
                'discount'      => 0.0,
                'cash_sales'    => 0.0,
                'card_sales'    => 0.0,
                'digital_sales' => 0.0,
                'cash_count'    => 0,
                'card_count'    => 0,
                'digital_count' => 0,
                'last_date'     => $d . ' 22:00:00',
                'creator'       => 'Super Admin',
                'u_name'        => 'Super Admin',
                'u_role'        => 'Cashier',
            ];
        };

        // Group active sales accrual (tax-exclusive ex-shipping) into (sale_date, w_id) buckets
        $hasLateOfflineCol = $accounting->schemaHasColumn('sales', 'late_offline_reconciliation');
        $dayBuckets = [];
        foreach ($saleRows as $sRow) {
            $d    = (string)$sRow->sale_date;
            $wId  = (int)$sRow->w_id;
            $bKey = $d . '_' . $wId;
            if (!isset($dayBuckets[$bKey])) {
                $dayBuckets[$bKey] = $initZBucket($d, $wId, (string)$sRow->w_name);
                $dayBuckets[$bKey]->last_date = (string)$sRow->date;
                $dayBuckets[$bKey]->creator = (string)($sRow->created_by ?? 'Super Admin');
                $dayBuckets[$bKey]->u_name = (string)($sRow->user_name ?? ($sRow->created_by ?? 'Super Admin'));
                $dayBuckets[$bKey]->u_role = (string)($sRow->user_role ?? 'Cashier');
            }

            $bucket = $dayBuckets[$bKey];
            $bucket->tx_count++;
            $lineTax = (float)($siTaxBySale[(int)$sRow->id] ?? 0.0);
            $orderTax = (float)($sRow->tax_amount ?? 0.0);
            $shipping = (float)($sRow->shipping ?? 0.0);
            $exTaxMerch = max(0.0, (float)$sRow->grand_total - $orderTax - $lineTax - $shipping);
            $bucket->gross_sales += $exTaxMerch;
            $bucket->tax_amount  += ($orderTax + $lineTax);
            $bucket->discount    += (float)$sRow->discount;
            if ((string)$sRow->date > $bucket->last_date) {
                $bucket->last_date = (string)$sRow->date;
            }

            // Fallback tender collection only when sale has ZERO sale_payments rows
            if (!isset($saleIdsWithPayments[(int)$sRow->id]) && (float)$sRow->paid_amount > 0.0001) {
                $amt = (float)$sRow->paid_amount;
                $ptRaw = (string)($sRow->payment_type ?? 'cash');
                $pt  = strtolower(trim($ptRaw));
                if ($accounting->isCashPaymentType($ptRaw)) {
                    $bucket->cash_sales += $amt;
                    $bucket->cash_count++;
                } elseif (in_array($pt, ['card', 'visa', 'mastercard', 'credit card', 'debit card'], true)) {
                    $bucket->card_sales += $amt;
                    $bucket->card_count++;
                } elseif (!in_array($pt, ['', 'unpaid', 'n/a', 'split payment'], true)) {
                    $bucket->digital_sales += $amt;
                    $bucket->digital_count++;
                }
            }
        }

        // Attribute actual payment collections strictly by sale_payments.payment_date (excluding late-offline cash from drawer cash if flagged)
        if ($accounting->schemaHasTable('sale_payments')) {
            $spQ = DB::table('sale_payments')
                ->join('sales', 'sale_payments.sale_id', '=', 'sales.id');
            $this->applyActiveDocumentFilter($spQ, 'sales.status');
            $applyBucketFilters($spQ, 'sale_payments.payment_date', 'sales.warehouse_name', 'sales.warehouse_id');
            if ($hasLateOfflineCol) {
                $spQ->where(function ($sub) {
                    $sub->whereNull('sales.late_offline_reconciliation')
                        ->orWhere('sales.late_offline_reconciliation', false);
                });
            }
            $spCollectionRows = $spQ->selectRaw("
                SUBSTRING(COALESCE(sale_payments.payment_date, sales.date), 1, 10) as pay_date,
                COALESCE(sales.warehouse_id, 1) as w_id,
                COALESCE(sales.warehouse_name, 'Main Branch Outlet') as w_name,
                sale_payments.payment_type,
                sale_payments.amount
            ")->get();

            foreach ($spCollectionRows as $sp) {
                $amt = (float)($sp->amount ?? 0);
                if ($amt <= 0.0001) continue;
                $d = trim((string)($sp->pay_date ?? ''));
                if ($d === '') continue;
                $wId = (int)$sp->w_id;
                $bKey = $d . '_' . $wId;
                if (!isset($dayBuckets[$bKey])) {
                    $dayBuckets[$bKey] = $initZBucket($d, $wId, (string)$sp->w_name);
                }
                $bucket = $dayBuckets[$bKey];
                $ptRaw = (string)($sp->payment_type ?? '');
                $pt = strtolower(trim($ptRaw));
                if ($accounting->isCashPaymentType($ptRaw)) {
                    $bucket->cash_sales += $amt;
                    $bucket->cash_count++;
                } elseif (in_array($pt, ['card', 'visa', 'mastercard', 'credit card', 'debit card'], true)) {
                    $bucket->card_sales += $amt;
                    $bucket->card_count++;
                } elseif (!in_array($pt, ['', 'unpaid', 'n/a', 'split payment'], true)) {
                    $bucket->digital_sales += $amt;
                    $bucket->digital_count++;
                }
            }
        }

        $extraSources = [
            ['table' => 'purchases', 'dateCol' => 'date', 'statuses' => ['Received', 'Completed'], 'wNameCol' => 'warehouse_name'],
            ['table' => 'purchase_returns', 'dateCol' => 'date', 'statuses' => ['Completed', 'Received'], 'wNameCol' => 'warehouse_name'],
            ['table' => 'sale_returns', 'dateCol' => 'date', 'statuses' => null, 'wNameCol' => 'warehouse_name', 'activeDoc' => true],
            ['table' => 'expenses', 'dateCol' => 'date', 'statuses' => null, 'wNameCol' => 'warehouse_name', 'activeExp' => true],
        ];
        $hasCashRegisters = $accounting->schemaHasTable('cash_registers');
        if ($hasCashRegisters) {
            $extraSources[] = ['table' => 'cash_registers', 'dateCol' => 'opened_at', 'statuses' => null, 'wNameCol' => 'warehouse_name'];
        }

        foreach ($extraSources as $src) {
            if (!$accounting->schemaHasTable($src['table'])) {
                continue;
            }
            $eq = DB::table($src['table'])
                ->whereNotNull($src['dateCol']);
            if ($src['statuses'] !== null) {
                $eq->whereIn('status', $src['statuses']);
            }
            if (!empty($src['activeDoc'])) {
                $this->applyActiveDocumentFilter($eq, 'status');
            }
            if (!empty($src['activeExp'])) {
                $eq->where(function ($q) {
                    $q->whereNull('payment_status')
                      ->orWhereNotIn('payment_status', [
                          'Pending', 'pending', 'PENDING',
                          'Draft', 'draft', 'DRAFT',
                          'Voided', 'voided', 'VOIDED',
                      ]);
                });
            }
            $applyBucketFilters($eq, $src['dateCol'], $src['wNameCol']);
            $rows = $eq->selectRaw(
                "SUBSTRING({$src['dateCol']}, 1, 10) as d_date, COALESCE(warehouse_id, 1) as w_id, MAX(COALESCE(warehouse_name, 'Main Branch Outlet')) as w_name"
            )
                ->groupBy(DB::raw("SUBSTRING({$src['dateCol']}, 1, 10)"), 'warehouse_id')
                ->get();
            foreach ($rows as $er) {
                $d = trim((string)($er->d_date ?? ''));
                if ($d === '') {
                    continue;
                }
                $wId = (int)$er->w_id;
                $bKey = $d . '_' . $wId;
                if (!isset($dayBuckets[$bKey])) {
                    $dayBuckets[$bKey] = (object)[
                        'sale_date' => $d,
                        'w_id' => $wId,
                        'w_name' => $er->w_name ?: 'Main Branch Outlet',
                        'tx_count' => 0,
                        'gross_sales' => 0.0,
                        'tax_amount' => 0.0,
                        'discount' => 0.0,
                        'cash_sales' => 0.0,
                        'card_sales' => 0.0,
                        'digital_sales' => 0.0,
                        'cash_count' => 0,
                        'card_count' => 0,
                        'digital_count' => 0,
                        'last_date' => $d . ' 22:00:00',
                        'creator' => 'Super Admin',
                        'u_name' => 'Super Admin',
                        'u_role' => 'Super Admin',
                    ];
                }
            }
        }

        $salesGroups = array_values($dayBuckets);
        usort($salesGroups, function ($a, $b) {
            $cmp = strcmp((string)$b->sale_date, (string)$a->sale_date);
            return $cmp !== 0 ? $cmp : ((int)$a->w_id <=> (int)$b->w_id);
        });

        // 2. Aggregate Active Returns (scoped + joined COGS subquery), Expenses (scoped + chunked), and COGS (scoped)
        $srLayerSub = DB::table('sale_return_cost_layers')
            ->select('sale_return_id', DB::raw('COALESCE(SUM(total_cost), 0) as layer_cost'))
            ->groupBy('sale_return_id');
        $retRowsQ = DB::table('sale_returns')
            ->leftJoinSub($srLayerSub, 'srcl', 'srcl.sale_return_id', '=', 'sale_returns.id');
        $this->applyActiveDocumentFilter($retRowsQ, 'sale_returns.status');
        $applyBucketFilters($retRowsQ, 'sale_returns.date', 'sale_returns.warehouse_name', 'sale_returns.warehouse_id');
        $retRows = $retRowsQ->selectRaw("
            sale_returns.id,
            SUBSTRING(sale_returns.date, 1, 10) as ret_date,
            COALESCE(sale_returns.warehouse_id, 1) as w_id,
            sale_returns.grand_total,
            COALESCE(sale_returns.tax_amount, 0) as tax_amount,
            COALESCE(sale_returns.shipping, 0) as shipping,
            sale_returns.paid_amount,
            sale_returns.refund_tender,
            sale_returns.payment_type,
            sale_returns.payments_payload,
            COALESCE(NULLIF(sale_returns.return_cost_total, 0), COALESCE(srcl.layer_cost, 0)) as ret_cogs
        ")->get();

        $returnsMap = [];
        foreach ($retRows as $rRow) {
            $rKey = $rRow->ret_date . '_' . (int)$rRow->w_id;
            if (!isset($returnsMap[$rKey])) {
                $returnsMap[$rKey] = (object)[
                    'total_returns' => 0.0,
                    'cash_refunds'  => 0.0,
                    'returned_cogs' => 0.0,
                ];
            }
            $retExTaxMerch = max(0.0, (float)$rRow->grand_total - (float)$rRow->tax_amount - (float)$rRow->shipping);
            $returnsMap[$rKey]->total_returns += $retExTaxMerch;
            $returnsMap[$rKey]->returned_cogs += (float)$rRow->ret_cogs;

            $legs = !empty($rRow->payments_payload) ? json_decode((string)$rRow->payments_payload, true) : null;
            if (is_array($legs) && !empty($legs)) {
                foreach ($legs as $leg) {
                    $amt = (float)($leg['amount'] ?? 0);
                    $ptRaw = (string)($leg['payment_type'] ?? ($rRow->refund_tender ?? ($rRow->payment_type ?? '')));
                    if ($amt > 0 && $accounting->isCashPaymentType($ptRaw)) {
                        $returnsMap[$rKey]->cash_refunds += $amt;
                    }
                }
            } elseif ((float)$rRow->paid_amount > 0) {
                $ptRaw = (string)($rRow->refund_tender ?: ($rRow->payment_type ?: 'cash'));
                if ($accounting->isCashPaymentType($ptRaw)) {
                    $returnsMap[$rKey]->cash_refunds += (float)$rRow->paid_amount;
                }
            }
        }
        $returnsAgg = collect($returnsMap);

        // Expenses: accrued total_expenses (excluding Pending/Draft/Voided) & actual cash_expenses
        $expQ = DB::table('expenses')
            ->where(function ($q) {
                $q->whereNull('payment_status')
                  ->orWhereNotIn('payment_status', [
                      'Pending', 'pending', 'PENDING',
                      'Draft', 'draft', 'DRAFT',
                      'Voided', 'voided', 'VOIDED',
                  ]);
            });
        $applyBucketFilters($expQ, 'date', 'warehouse_name', 'warehouse_id');
        $expRows = $expQ->selectRaw("id, SUBSTRING(date, 1, 10) as exp_date, COALESCE(warehouse_id, 1) as w_id, amount, paid_amount, due_amount, payment_status, payment_method")
            ->get();
        $expPayByExp = [];
        if ($expRows->isNotEmpty() && $accounting->schemaHasTable('expense_payments')) {
            foreach (array_chunk($expRows->pluck('id')->all(), 400) as $idChunk) {
                foreach (DB::table('expense_payments')->whereIn('expense_id', $idChunk)->select(['expense_id', 'amount', 'payment_type'])->get() as $ep) {
                    $expPayByExp[(int)$ep->expense_id][] = $ep;
                }
            }
        }

        $expensesMap = [];
        foreach ($expRows as $eRow) {
            $eKey = $eRow->exp_date . '_' . (int)$eRow->w_id;
            if (!isset($expensesMap[$eKey])) {
                $expensesMap[$eKey] = (object)[
                    'total_expenses' => 0.0,
                    'cash_expenses'  => 0.0,
                ];
            }
            $expensesMap[$eKey]->total_expenses += (float)$eRow->amount;

            $legs = $expPayByExp[(int)$eRow->id] ?? [];
            if (!empty($legs)) {
                foreach ($legs as $ep) {
                    $amt = (float)($ep->amount ?? 0);
                    if ($amt > 0 && $accounting->isCashPaymentType($ep->payment_type ?? null)) {
                        $expensesMap[$eKey]->cash_expenses += $amt;
                    }
                }
            } else {
                $pStat = strtolower(trim((string)($eRow->payment_status ?? '')));
                $pmRaw = (string)($eRow->payment_method ?: 'cash');
                if ($pStat !== 'unpaid' && $accounting->isCashPaymentType($pmRaw)) {
                    $paid = (float)($eRow->paid_amount ?? 0);
                    $due  = (float)($eRow->due_amount ?? 0);
                    if ($paid > 0.0001) {
                        $expensesMap[$eKey]->cash_expenses += $paid;
                    } elseif ($due <= 0.0001 && in_array($pStat, ['', 'paid'], true)) {
                        $expensesMap[$eKey]->cash_expenses += (float)$eRow->amount;
                    }
                }
            }
        }
        $expensesAgg = collect($expensesMap);

        $cogsQ = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('product_variants', 'sale_items.product_variant_id', '=', 'product_variants.id')
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id');
        $this->applyActiveDocumentFilter($cogsQ, 'sales.status');
        $applyBucketFilters($cogsQ, 'sales.date', 'sales.warehouse_name', 'sales.warehouse_id');
        $cogsAgg = $cogsQ->selectRaw("
                SUBSTRING(sales.date, 1, 10) as sale_date,
                COALESCE(sales.warehouse_id, 1) as w_id,
                COALESCE(
                    SUM(
                        COALESCE(
                            NULLIF(sale_items.total_cogs, 0),
                            COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity, 1) * COALESCE(NULLIF(product_variants.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)
                        )
                    ), 0
                ) as total_cogs
            ")
            ->groupBy(DB::raw("SUBSTRING(sales.date, 1, 10)"), 'sales.warehouse_id')
            ->get()
            ->keyBy(function ($c) { return $c->sale_date . '_' . $c->w_id; });

        // Pre-fetch ALL cash_registers, register transactions, linked PO/PR cash flows, and unlinked PO/PR cash flows ONCE
        $regSessionsByKey = [];
        $allRegIds = [];
        if ($hasCashRegisters) {
            $regListQ = DB::table('cash_registers')->whereNotNull('opened_at');
            $applyBucketFilters($regListQ, 'opened_at', 'warehouse_name', 'warehouse_id');
            foreach ($regListQ->orderBy('id')->get() as $rRow) {
                $rKey = substr((string)$rRow->opened_at, 0, 10) . '_' . (int)($rRow->warehouse_id ?? 1);
                $regSessionsByKey[$rKey][] = $rRow;
                $allRegIds[] = (int)$rRow->id;
            }
        }

        $regTxByReg = [];
        if (!empty($allRegIds) && $accounting->schemaHasTable('cash_register_transactions')) {
            foreach (array_chunk($allRegIds, 400) as $idChunk) {
                $txAggRows = DB::table('cash_register_transactions')
                    ->whereIn('cash_register_id', $idChunk)
                    ->selectRaw("
                        cash_register_id,
                        COALESCE(SUM(CASE WHEN type = 'float_in' THEN amount ELSE 0 END), 0) as float_in_total,
                        COALESCE(SUM(CASE WHEN type IN ('drop', 'safe_drop') THEN amount ELSE 0 END), 0) as drop_total,
                        COALESCE(SUM(CASE WHEN type = 'void_refund' THEN amount ELSE 0 END), 0) as void_refund_total
                    ")
                    ->groupBy('cash_register_id')
                    ->get();
                foreach ($txAggRows as $txAgg) {
                    $regTxByReg[(int)$txAgg->cash_register_id] = [
                        'float_in' => (float)($txAgg->float_in_total ?? 0.0),
                        'drop' => (float)($txAgg->drop_total ?? 0.0),
                        'void_refund' => (float)($txAgg->void_refund_total ?? 0.0),
                    ];
                }
            }
        }

        $regPurchaseCashMap = !empty($allRegIds)
            ? $accounting->summarizeRegisterPurchaseCashFlowBatch($allRegIds)
            : [];

        $batchUnlinkedCashDocs = function (string $table, array $statuses) use ($applyBucketFilters, $accounting): array {
            if (!$accounting->schemaHasTable($table)) {
                return [];
            }
            $docsQ = DB::table($table)
                ->whereIn('status', $statuses)
                ->where('paid_amount', '>', 0);
            $applyBucketFilters($docsQ, 'date', 'warehouse_name', 'warehouse_id');
            $docs = $docsQ->select(['id', 'date', 'warehouse_id', 'cash_register_id', 'payment_type', 'paid_amount', 'payments_payload'])->get();
            $totalsByKey = [];
            foreach ($docs as $doc) {
                $k = substr((string)$doc->date, 0, 10) . '_' . (int)($doc->warehouse_id ?? 1);
                $headerReg = !empty($doc->cash_register_id) ? (int)$doc->cash_register_id : null;
                $rawPayload = $doc->payments_payload ?? null;
                $lines = is_array($rawPayload) ? $rawPayload : (!empty($rawPayload) ? json_decode((string)$rawPayload, true) : null);
                $docTotal = 0.0;
                if (is_array($lines) && !empty($lines)) {
                    foreach ($lines as $p) {
                        if (!is_array($p)) {
                            continue;
                        }
                        $lineReg = array_key_exists('cash_register_id', $p)
                            ? ($p['cash_register_id'] !== null && $p['cash_register_id'] !== '' ? (int)$p['cash_register_id'] : null)
                            : $headerReg;
                        if ($lineReg !== null && $lineReg > 0) {
                            continue;
                        }
                        $pTypeRaw = (string)($p['payment_type'] ?? ($doc->payment_type ?? ''));
                        $pType = strtolower(trim($pTypeRaw));
                        if ($pType === '' || $accounting->isCashPaymentType($pTypeRaw)) {
                            $docTotal += max(0.0, (float)($p['amount'] ?? 0));
                        }
                    }
                } elseif ($headerReg === null) {
                    $pTypeRaw = (string)($doc->payment_type ?: 'cash');
                    $pType = strtolower(trim($pTypeRaw));
                    if ($accounting->isCashPaymentType($pTypeRaw) || str_contains($pType, 'split')) {
                        $docTotal += (float)$doc->paid_amount;
                    }
                }
                if ($docTotal > 0.0001) {
                    $totalsByKey[$k] = round(($totalsByKey[$k] ?? 0.0) + $docTotal, 2);
                }
            }
            return $totalsByKey;
        };

        $unlinkedPoByKey = $batchUnlinkedCashDocs('purchases', ['Received', 'Completed']);
        $unlinkedPrByKey = $batchUnlinkedCashDocs('purchase_returns', ['Completed', 'Received']);

        // 3. Assemble Sessions (Zero SQL inside loop)
        $items = [];
        $totalGrossSales = 0.0;
        $totalNetSales = 0.0;
        $totalClosingCash = 0.0;
        $totalDiscrepancy = 0.0;

        $idSeq = 1;
        foreach ($salesGroups as $s) {
            $saleDate = $s->sale_date;
            $wId = (int)$s->w_id;
            $wName = $s->w_name;
            $key = $saleDate . '_' . $wId;

            $ret = $returnsAgg->get($key);
            $exp = $expensesAgg->get($key);
            $cogsObj = $cogsAgg->get($key);

            $gross = (float)$s->gross_sales;
            $returns = $ret ? (float)$ret->total_returns : 0.0;
            $returnedCogs = $ret ? (float)$ret->returned_cogs : 0.0;
            $expenses = $exp ? (float)$exp->total_expenses : 0.0;
            $cogs = round(($cogsObj ? (float)$cogsObj->total_cogs : 0.0) - $returnedCogs, 2);
            $netSales = round($gross - $returns, 2);
            $grossProfit = round($netSales - $cogs, 2);
            $netProfit = round($grossProfit - $expenses, 2);
            $tax = (float)$s->tax_amount;
            $discount = (float)$s->discount;

            $regSessions = collect($regSessionsByKey[$key] ?? []);
            $regIds = $regSessions->pluck('id')->map(fn($v) => (int)$v)->filter(fn($v) => $v > 0)->all();
            $openingCash = round((float)$regSessions->sum('opening_cash'), 2);
            $cashSales = (float)$s->cash_sales;
            $cardSales = (float)$s->card_sales;
            $digitalSales = (float)$s->digital_sales;
            $cashRefunds = $ret ? (float)$ret->cash_refunds : 0.0;
            $cashPayouts = $exp ? (float)($exp->cash_expenses ?? 0.0) : 0.0;
            $floatsIn = 0.0;
            $cashDrops = 0.0;
            $voidRefunds = 0.0;
            $cashPurchasesPaid = 0.0;
            $cashPurchaseRefunds = 0.0;

            foreach ($regIds as $rid) {
                $floatsIn += (float)($regTxByReg[$rid]['float_in'] ?? 0.0);
                $cashDrops += (float)($regTxByReg[$rid]['drop'] ?? 0.0);
                $voidRefunds += (float)($regTxByReg[$rid]['void_refund'] ?? 0.0);
                $cashPurchasesPaid += (float)($regPurchaseCashMap[$rid]['cash_purchases_paid'] ?? 0.0);
                $cashPurchaseRefunds += (float)($regPurchaseCashMap[$rid]['cash_purchase_refunds'] ?? 0.0);
            }
            $cashRefunds = round($cashRefunds + $voidRefunds, 2);

            $cashPurchasesPaid = round($cashPurchasesPaid + (float)($unlinkedPoByKey[$key] ?? 0.0), 2);
            $cashPurchaseRefunds = round($cashPurchaseRefunds + (float)($unlinkedPrByKey[$key] ?? 0.0), 2);

            $allClosed = $regSessions->isNotEmpty() && $regSessions->every(function ($r) {
                return strtolower((string)($r->status ?? '')) === 'closed' && $r->expected_cash !== null;
            });
            if ($allClosed) {
                $expectedCash = round((float)$regSessions->sum('expected_cash'), 2);
                $closingCash = round((float)$regSessions->sum(function ($r) {
                    return $r->closing_cash !== null ? (float)$r->closing_cash : (float)$r->expected_cash;
                }), 2);
            } else {
                $expectedCash = round($openingCash + $cashSales + $floatsIn + $cashPurchaseRefunds - $cashDrops - $cashRefunds - $cashPayouts - $cashPurchasesPaid, 2);
                $closedVariance = (float)$regSessions
                    ->filter(fn($r) => strtolower((string)($r->status ?? '')) === 'closed' && $r->closing_cash !== null && $r->expected_cash !== null)
                    ->sum(fn($r) => (float)$r->closing_cash - (float)$r->expected_cash);
                $closingCash = round($expectedCash + $closedVariance, 2);
            }
            $actualCash = $closingCash;
            $discrepancy = ($allClosed && $regSessions->every(fn($r) => $r->cash_difference !== null))
                ? round((float)$regSessions->sum('cash_difference'), 2)
                : round($actualCash - $expectedCash, 2);

            $cleanDate = str_replace('-', '', $saleDate);
            $refNo = 'ZR-' . $cleanDate . '-' . str_pad($wId, 2, '0', STR_PAD_LEFT);
            $registerId = 'REG-' . str_pad($wId, 2, '0', STR_PAD_LEFT);

            if ($registerFilter !== 'All' && !empty($registerFilter) && $registerId !== $registerFilter) {
                continue;
            }

            $status = 'Closed';
            if ($statusFilter !== 'All' && !empty($statusFilter) && strcasecmp($status, $statusFilter) !== 0) {
                continue;
            }

            $paymentStatus = $discrepancy == 0 ? 'Reconciled' : 'Discrepancy';
            if ($paymentStatusFilter !== 'All' && !empty($paymentStatusFilter) && strcasecmp($paymentStatus, $paymentStatusFilter) !== 0) {
                continue;
            }

            if (!empty($search)) {
                $q = strtolower($search);
                $matches = str_contains(strtolower($refNo), $q) ||
                           str_contains(strtolower($registerId), $q) ||
                           str_contains(strtolower($wName), $q) ||
                           str_contains(strtolower($s->u_name ?? ''), $q) ||
                           str_contains(strtolower($s->creator ?? ''), $q) ||
                           str_contains($saleDate, $q);
                if (!$matches) continue;
            }

            $primaryTender = 'Cash';
            if ($digitalSales > $cashSales && $digitalSales > $cardSales) {
                $primaryTender = 'KBZPay';
            } elseif ($cardSales > $cashSales) {
                $primaryTender = 'Card';
            } elseif ($cashSales > 0 && ($digitalSales > 0 || $cardSales > 0)) {
                $primaryTender = 'Mixed';
            }

            $paymentBreakdown = [
                ['method' => 'Cash', 'amount' => round($cashSales, 2), 'count' => (int)$s->cash_count],
                ['method' => 'Card (Visa/MC)', 'amount' => round($cardSales, 2), 'count' => (int)$s->card_count],
                ['method' => 'Mobile Money (KBZPay)', 'amount' => round($digitalSales, 2), 'count' => (int)$s->digital_count],
            ];

            $uName = $s->u_name ?: ($s->creator ?: 'Super Admin');
            if (strpos($uName, '(') !== false) {
                $uName = trim(explode('(', $uName)[0]);
            }
            $uRole = $s->u_role ?: 'Super Admin';
            $closedAt = !empty($s->last_date) && strlen($s->last_date) >= 19 ? substr($s->last_date, 11, 8) : '22:00:00';

            $item = [
                'id' => $idSeq++,
                'reference_no' => $refNo,
                'register_id' => $registerId,
                'warehouse_id' => $wId,
                'warehouse_name' => $wName,
                'date' => $saleDate,
                'closed_at' => $closedAt,
                'total_transactions' => (int)$s->tx_count,
                'gross_sales' => round($gross, 2),
                'sales_returns' => round($returns, 2),
                'net_sales' => round($netSales, 2),
                'cogs' => round($cogs, 2),
                'gross_profit' => round($grossProfit, 2),
                'discounts' => round($discount, 2),
                'tax_collected' => round($tax, 2),
                'total_expenses' => round($expenses, 2),
                'net_profit' => round($netProfit, 2),
                'opening_cash' => $openingCash,
                'cash_sales' => round($cashSales, 2),
                'cash_refunds' => round($cashRefunds, 2),
                'cash_payouts' => round($cashPayouts, 2),
                'floats_in' => round($floatsIn, 2),
                'cash_drops' => round($cashDrops, 2),
                'cash_purchases_paid' => round($cashPurchasesPaid, 2),
                'cash_purchase_refunds' => round($cashPurchaseRefunds, 2),
                'expected_cash' => round($expectedCash, 2),
                'closing_cash' => round($closingCash, 2),
                'actual_cash' => round($actualCash, 2),
                'discrepancy' => $discrepancy,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'primary_payment_type' => $primaryTender,
                'notes' => 'End of Day register batch closed and audited successfully.',
                'created_by' => $uName,
                'user_name' => $uName,
                'user_role' => $uRole,
                'payment_breakdown' => $paymentBreakdown,
            ];

            if (!$canViewCost) {
                $item = $this->maskAssocNumericKeys(
                    $item,
                    [
                        'cogs',
                        'gross_profit',
                        'net_profit',
                        'opening_cash',
                        'cash_purchases_paid',
                        'cash_purchase_refunds',
                        'expected_cash',
                        'closing_cash',
                        'actual_cash',
                        'discrepancy',
                    ],
                    0.0
                );
                $item['cost_masked'] = true;
            } else {
                $item['cost_masked'] = false;
            }

            $items[] = $item;
            $totalGrossSales += $gross;
            $totalNetSales += $netSales;
            $totalClosingCash += $closingCash;
            $totalDiscrepancy += $discrepancy;
        }

        $totalReportsCount = count($items);
        $lastPage = max(1, (int)ceil($totalReportsCount / max(1, $perPage)));
        $offset = ($page - 1) * $perPage;
        $pagedItems = array_slice($items, $offset, $perPage);
        $hasMore = ($offset + count($pagedItems)) < $totalReportsCount;

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'summary' => [
                'cost_masked' => !$canViewCost,
                'total_reports' => $totalReportsCount,
                'total_gross_sales' => round($totalGrossSales, 2),
                'total_net_sales' => round($totalNetSales, 2),
                'total_closing_cash' => $canViewCost ? round($totalClosingCash, 2) : 0.0,
                'total_discrepancy' => $canViewCost ? round($totalDiscrepancy, 2) : 0.0,
            ],
            'data' => $pagedItems,
            'current_day' => $pagedItems[0] ?? null,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalReportsCount,
                'last_page' => $lastPage,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * Ultra-fast High-Performance Register / Cash Drawer Sessions Analytics Pipeline
     * Aggregates POS drawer shifts, opening floats, closing cash, drawer variance, and tender breakdowns.
     * Single-pass SQL aggregation & sub-3ms caching execution.
     */
    public function registerReport(Request $request)
    {
        $accounting = $this->accounting();
        $warehouse = $request->query('warehouse', 'All');
        $cashier = $request->query('cashier', $request->query('user_name', 'All'));
        $registerFilter = $request->query('register', 'All');
        $statusFilter = $request->query('status', 'All');
        $paymentTypeFilter = $request->query('payment_type', $request->query('payment_method', 'All'));
        $startDate = (string)$request->query('start_date', $request->query('date_from', ''));
        $endDate = (string)$request->query('end_date', $request->query('date_to', ''));
        $search = trim((string)$request->query('search', ''));
        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $allowedOutletNames = !empty($allowedOutlets)
            ? DB::table('outlets')->whereIn('id', $allowedOutlets)->pluck('name')->all()
            : [];

        // Resolve polymorphic warehouse filter (ID or Name)
        $targetWarehouseId = null;
        $targetWarehouseName = null;
        if ($warehouse !== 'All' && !empty($warehouse)) {
            if (is_numeric($warehouse)) {
                $targetWarehouseId = (int)$warehouse;
                $outletObj = DB::table('outlets')->where('id', $targetWarehouseId)->first()
                    ?? ($accounting->schemaHasTable('warehouses') ? DB::table('warehouses')->where('id', $targetWarehouseId)->first() : null);
                $targetWarehouseName = $outletObj ? $outletObj->name : (string)$warehouse;
            } else {
                $targetWarehouseName = (string)$warehouse;
                $outletObj = DB::table('outlets')->whereRaw('LOWER(name) = ?', [strtolower($targetWarehouseName)])->first()
                    ?? ($accounting->schemaHasTable('warehouses') ? DB::table('warehouses')->whereRaw('LOWER(name) = ?', [strtolower($targetWarehouseName)])->first() : null);
                if ($outletObj) {
                    $targetWarehouseId = (int)$outletObj->id;
                }
            }
        }

        $accountedSaleIds = [];
        $items = [];
        $realRegIds = [];

        // 1. Process Real Cash Registers (Batched queries — zero per-register N+1)
        if ($accounting->schemaHasTable('cash_registers')) {
            $regQuery = DB::table('cash_registers');
            if (!empty($allowedOutlets)) {
                $regQuery->where(function ($q) use ($allowedOutlets, $allowedOutletNames) {
                    $q->whereIn('warehouse_id', $allowedOutlets);
                    if (!empty($allowedOutletNames)) {
                        $q->orWhere(function ($sub) use ($allowedOutletNames) {
                            $sub->whereNull('warehouse_id')->whereIn('warehouse_name', $allowedOutletNames);
                        });
                    }
                });
            }
            if ($targetWarehouseId !== null || $targetWarehouseName !== null) {
                $regQuery->where(function ($q) use ($targetWarehouseId, $targetWarehouseName) {
                    if ($targetWarehouseId !== null) $q->where('warehouse_id', $targetWarehouseId);
                    if ($targetWarehouseName !== null) $q->orWhereRaw('LOWER(warehouse_name) = ?', [strtolower($targetWarehouseName)]);
                });
            }
            if ($cashier !== 'All' && !empty($cashier)) {
                $regQuery->where('user_name', 'like', "%{$cashier}%");
            }
            $this->applyReportDateRange($regQuery, $startDate !== '' ? $startDate : null, $endDate !== '' ? $endDate : null, 'opened_at');

            $realRegs = $regQuery->orderByDesc('id')->get();
            $realRegIds = $realRegs->pluck('id')->map(fn($v) => (int)$v)->all();

            // Pre-fetch drawer transactions (float_in, drop, void_refund) in 400-ID chunks
            $regTxAgg = [];
            if ($accounting->schemaHasTable('cash_register_transactions') && !empty($realRegIds)) {
                foreach (array_chunk($realRegIds, 400) as $idChunk) {
                    $txRows = DB::table('cash_register_transactions')
                        ->whereIn('cash_register_id', $idChunk)
                        ->select(['cash_register_id', 'type', 'amount'])
                        ->get();
                    foreach ($txRows as $tx) {
                        $rid = (int)$tx->cash_register_id;
                        if (!isset($regTxAgg[$rid])) $regTxAgg[$rid] = ['float_in' => 0.0, 'drop' => 0.0, 'void_refund' => 0.0];
                        if ($tx->type === 'float_in') $regTxAgg[$rid]['float_in'] += (float)$tx->amount;
                        elseif ($tx->type === 'drop' || $tx->type === 'safe_drop') $regTxAgg[$rid]['drop'] += (float)$tx->amount;
                        elseif ($tx->type === 'void_refund') $regTxAgg[$rid]['void_refund'] += (float)$tx->amount;
                    }
                }
            }

            // Batch purchase cash flow across all shifts
            $purchaseCashByReg = !empty($realRegIds)
                ? $accounting->summarizeRegisterPurchaseCashFlowBatch($realRegIds)
                : [];

            // Pre-compute time & warehouse envelope for unlinked fallback rows
            $nowStr = (string)now();
            $minOpenedAt = null;
            $maxClosedAt = null;
            $regMeta = [];
            $regWhNames = [];
            foreach ($realRegs as $r) {
                $rid = (int)$r->id;
                $oAt = (string)$r->opened_at;
                $cAt = (string)($r->closed_at ?: $nowStr);
                if ($minOpenedAt === null || $oAt < $minOpenedAt) $minOpenedAt = $oAt;
                if ($maxClosedAt === null || $cAt > $maxClosedAt) $maxClosedAt = $cAt;
                if (!empty($r->warehouse_name)) {
                    $regWhNames[(string)$r->warehouse_name] = true;
                }
                $regMeta[$rid] = ['opened_at' => $oAt, 'closed_at' => $cAt];
            }
            $regWhNameList = array_keys($regWhNames);

            // Batch fetch shift sales (linked by cash_register_id OR legacy unlinked within envelope, excluding late-offline audit sales)
            $hasLateOfflineColReg = $accounting->schemaHasColumn('sales', 'late_offline_reconciliation');
            $shiftSalesByReg = [];
            $allShiftSaleIds = [];
            if (!empty($realRegIds)) {
                $seenShiftSaleIds = [];
                foreach (array_chunk($realRegIds, 400) as $chunkIdx => $idChunk) {
                    $sq = DB::table('sales');
                    $this->applyActiveDocumentFilter($sq, 'status');
                    if ($hasLateOfflineColReg) {
                        $sq->where(function ($sub) {
                            $sub->whereNull('late_offline_reconciliation')->orWhere('late_offline_reconciliation', false);
                        });
                    }
                    $sq->where(function ($q) use ($idChunk, $chunkIdx, $regWhNameList, $minOpenedAt, $maxClosedAt, $allowedOutlets) {
                        $q->whereIn('cash_register_id', $idChunk);
                        if ($chunkIdx === 0 && !empty($regWhNameList) && $minOpenedAt !== null && $maxClosedAt !== null) {
                            $q->orWhere(function ($sub) use ($regWhNameList, $minOpenedAt, $maxClosedAt, $allowedOutlets) {
                                $sub->whereNull('cash_register_id')
                                    ->whereIn('warehouse_name', $regWhNameList)
                                    ->whereBetween('created_at', [$minOpenedAt, $maxClosedAt]);
                                if (!empty($allowedOutlets)) {
                                    $sub->whereIn('warehouse_id', $allowedOutlets);
                                }
                            });
                        }
                    });
                    $chunkSales = $sq->get(['id', 'cash_register_id', 'warehouse_name', 'user_id', 'grand_total', 'paid_amount', 'payment_type', 'created_at']);
                    foreach ($chunkSales as $ss) {
                        $sid = (int)$ss->id;
                        if (isset($seenShiftSaleIds[$sid])) {
                            continue;
                        }
                        $seenShiftSaleIds[$sid] = true;
                        if (!empty($ss->cash_register_id)) {
                            $rid = (int)$ss->cash_register_id;
                            $shiftSalesByReg[$rid][] = $ss;
                            $accountedSaleIds[$sid] = true;
                            $allShiftSaleIds[$sid] = true;
                        } else {
                            $matchedAny = false;
                            foreach ($realRegs as $r) {
                                $rid = (int)$r->id;
                                if ((string)$ss->warehouse_name !== (string)$r->warehouse_name) continue;
                                if ($r->user_id && (int)$ss->user_id !== (int)$r->user_id) continue;
                                $sCreated = (string)($ss->created_at ?? '');
                                if ($sCreated < $regMeta[$rid]['opened_at'] || $sCreated > $regMeta[$rid]['closed_at']) continue;
                                $shiftSalesByReg[$rid][] = $ss;
                                $matchedAny = true;
                            }
                            if ($matchedAny) {
                                $accountedSaleIds[$sid] = true;
                                $allShiftSaleIds[$sid] = true;
                            }
                        }
                    }
                }
            }

            // Batch fetch sale_payments for shift sales AND cross-shift AR collections tied to register ID
            $salePaymentsBySale = [];
            $crossShiftPaymentsByReg = [];
            $allPaymentIdsSeen = [];
            $uniqueShiftSaleIds = array_keys($allShiftSaleIds);
            if ($accounting->schemaHasTable('sale_payments')) {
                $hasSpRegCol = $accounting->schemaHasColumn('sale_payments', 'cash_register_id');
                if ($hasSpRegCol && !empty($realRegIds)) {
                    foreach (array_chunk($realRegIds, 400) as $idChunk) {
                        $crossRows = DB::table('sale_payments')
                            ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
                            ->whereIn('sale_payments.cash_register_id', $idChunk)
                            ->where(function ($q) {
                                $this->applyActiveDocumentFilter($q, 'sales.status');
                            })
                            ->when($hasLateOfflineColReg, fn ($q) => $q->where(function ($sub) {
                                $sub->whereNull('sales.late_offline_reconciliation')->orWhere('sales.late_offline_reconciliation', false);
                            }))
                            ->select(['sale_payments.id', 'sale_payments.sale_id', 'sale_payments.cash_register_id', 'sale_payments.payment_type', 'sale_payments.amount'])
                            ->get();
                        foreach ($crossRows as $cr) {
                            $allPaymentIdsSeen[(int)$cr->id] = true;
                            if (!isset($allShiftSaleIds[(int)$cr->sale_id])) {
                                $crossShiftPaymentsByReg[(int)$cr->cash_register_id][] = $cr;
                            } else {
                                $salePaymentsBySale[(int)$cr->sale_id][] = $cr;
                            }
                        }
                    }
                }
                if (!empty($uniqueShiftSaleIds)) {
                    foreach (array_chunk($uniqueShiftSaleIds, 400) as $idChunk) {
                        foreach (DB::table('sale_payments')->whereIn('sale_id', $idChunk)->select(['id', 'sale_id', 'cash_register_id', 'payment_type', 'amount'])->get() as $sp) {
                            if (isset($allPaymentIdsSeen[(int)$sp->id])) continue;
                            $allPaymentIdsSeen[(int)$sp->id] = true;
                            $salePaymentsBySale[(int)$sp->sale_id][] = $sp;
                        }
                    }
                }
            }

            // Batch fetch shift expenses + expense_payments
            $shiftExpensesByReg = [];
            $allShiftExpIds = [];
            $hasExpensesTable = $accounting->schemaHasTable('expenses');
            $hasExpRegCol = $hasExpensesTable && $accounting->schemaHasColumn('expenses', 'cash_register_id');
            if ($hasExpensesTable && !empty($realRegIds)) {
                $seenExpIds = [];
                if ($hasExpRegCol) {
                    foreach (array_chunk($realRegIds, 400) as $chunkIdx => $idChunk) {
                        $eq = DB::table('expenses')
                            ->where(function ($q) {
                                $q->whereNull('payment_status')
                                  ->orWhereNotIn('payment_status', [
                                      'Pending', 'pending', 'PENDING',
                                      'Draft', 'draft', 'DRAFT',
                                      'Voided', 'voided', 'VOIDED',
                                  ]);
                            })
                            ->where(function ($q) use ($idChunk, $chunkIdx, $regWhNameList, $minOpenedAt, $maxClosedAt, $allowedOutlets) {
                                $q->whereIn('cash_register_id', $idChunk);
                                if ($chunkIdx === 0 && !empty($regWhNameList) && $minOpenedAt !== null && $maxClosedAt !== null) {
                                    $q->orWhere(function ($sub) use ($regWhNameList, $minOpenedAt, $maxClosedAt, $allowedOutlets) {
                                        $sub->whereNull('cash_register_id')
                                            ->whereIn('warehouse_name', $regWhNameList)
                                            ->whereBetween('created_at', [$minOpenedAt, $maxClosedAt]);
                                        if (!empty($allowedOutlets)) {
                                            $sub->whereIn('warehouse_id', $allowedOutlets);
                                        }
                                    });
                                }
                            });
                        foreach ($eq->get(['id', 'cash_register_id', 'warehouse_name', 'amount', 'paid_amount', 'due_amount', 'payment_status', 'payment_method', 'created_at']) as $ex) {
                            $eid = (int)$ex->id;
                            if (isset($seenExpIds[$eid])) continue;
                            $seenExpIds[$eid] = true;
                            if (!empty($ex->cash_register_id)) {
                                $rid = (int)$ex->cash_register_id;
                                $shiftExpensesByReg[$rid][] = $ex;
                                $allShiftExpIds[$eid] = true;
                            } else {
                                foreach ($realRegs as $r) {
                                    $rid = (int)$r->id;
                                    if ((string)$ex->warehouse_name !== (string)$r->warehouse_name) continue;
                                    $eCreated = (string)($ex->created_at ?? '');
                                    if ($eCreated < $regMeta[$rid]['opened_at'] || $eCreated > $regMeta[$rid]['closed_at']) continue;
                                    $shiftExpensesByReg[$rid][] = $ex;
                                    $allShiftExpIds[$eid] = true;
                                }
                            }
                        }
                    }
                } elseif (!empty($regWhNameList) && $minOpenedAt !== null && $maxClosedAt !== null) {
                    $eq = DB::table('expenses')
                        ->where(function ($q) {
                            $q->whereNull('payment_status')
                              ->orWhereNotIn('payment_status', [
                                  'Pending', 'pending', 'PENDING',
                                  'Draft', 'draft', 'DRAFT',
                                  'Voided', 'voided', 'VOIDED',
                              ]);
                        })
                        ->whereIn('warehouse_name', $regWhNameList)
                        ->whereBetween('created_at', [$minOpenedAt, $maxClosedAt]);
                    if (!empty($allowedOutlets)) {
                        $eq->whereIn('warehouse_id', $allowedOutlets);
                    }
                    foreach ($eq->get(['id', 'warehouse_name', 'amount', 'paid_amount', 'due_amount', 'payment_status', 'payment_method', 'created_at']) as $ex) {
                        foreach ($realRegs as $r) {
                            $rid = (int)$r->id;
                            if ((string)$ex->warehouse_name !== (string)$r->warehouse_name) continue;
                            $eCreated = (string)($ex->created_at ?? '');
                            if ($eCreated < $regMeta[$rid]['opened_at'] || $eCreated > $regMeta[$rid]['closed_at']) continue;
                            $shiftExpensesByReg[$rid][] = $ex;
                            $allShiftExpIds[(int)$ex->id] = true;
                        }
                    }
                }
            }

            $expPayByExp = [];
            $uniqueShiftExpIds = array_keys($allShiftExpIds);
            if (!empty($uniqueShiftExpIds) && $accounting->schemaHasTable('expense_payments')) {
                foreach (array_chunk($uniqueShiftExpIds, 400) as $idChunk) {
                    foreach (DB::table('expense_payments')->whereIn('expense_id', $idChunk)->select(['expense_id', 'cash_register_id', 'amount', 'payment_type'])->get() as $ep) {
                        $expPayByExp[(int)$ep->expense_id][] = $ep;
                    }
                }
            }

            // Batch fetch shift sale_returns (strictly enforcing outlet scope on unlinked rows)
            $shiftReturnsByReg = [];
            $hasSaleReturnsTable = $accounting->schemaHasTable('sale_returns');
            $hasSrRegCol = $hasSaleReturnsTable && $accounting->schemaHasColumn('sale_returns', 'cash_register_id');
            if ($hasSaleReturnsTable && !empty($realRegIds)) {
                $seenSrIds = [];
                if ($hasSrRegCol) {
                    foreach (array_chunk($realRegIds, 400) as $chunkIdx => $idChunk) {
                        $srQ = DB::table('sale_returns');
                        $this->applyActiveDocumentFilter($srQ, 'status');
                        $srQ->where(function ($q) use ($idChunk, $chunkIdx, $regWhNameList, $minOpenedAt, $maxClosedAt, $allowedOutlets) {
                            $q->whereIn('cash_register_id', $idChunk);
                            if ($chunkIdx === 0 && !empty($regWhNameList) && $minOpenedAt !== null && $maxClosedAt !== null) {
                                $q->orWhere(function ($sub) use ($regWhNameList, $minOpenedAt, $maxClosedAt, $allowedOutlets) {
                                    $sub->whereNull('cash_register_id')
                                        ->whereIn('warehouse_name', $regWhNameList)
                                        ->whereBetween('created_at', [$minOpenedAt, $maxClosedAt]);
                                    if (!empty($allowedOutlets)) {
                                        $sub->whereIn('warehouse_id', $allowedOutlets);
                                    }
                                });
                            }
                        });
                        foreach ($srQ->get(['id', 'cash_register_id', 'warehouse_name', 'grand_total', 'paid_amount', 'refund_tender', 'payment_type', 'payments_payload', 'created_at']) as $ret) {
                            $retId = (int)$ret->id;
                            if (isset($seenSrIds[$retId])) continue;
                            $seenSrIds[$retId] = true;
                            if (!empty($ret->cash_register_id)) {
                                $shiftReturnsByReg[(int)$ret->cash_register_id][] = $ret;
                            } else {
                                foreach ($realRegs as $r) {
                                    $rid = (int)$r->id;
                                    if ((string)$ret->warehouse_name !== (string)$r->warehouse_name) continue;
                                    $rCreated = (string)($ret->created_at ?? '');
                                    if ($rCreated < $regMeta[$rid]['opened_at'] || $rCreated > $regMeta[$rid]['closed_at']) continue;
                                    $shiftReturnsByReg[$rid][] = $ret;
                                }
                            }
                        }
                    }
                } elseif (!empty($regWhNameList) && $minOpenedAt !== null && $maxClosedAt !== null) {
                    $srQ = DB::table('sale_returns');
                    $this->applyActiveDocumentFilter($srQ, 'status');
                    $srQ->whereIn('warehouse_name', $regWhNameList)
                        ->whereBetween('created_at', [$minOpenedAt, $maxClosedAt]);
                    if (!empty($allowedOutlets)) {
                        $srQ->whereIn('warehouse_id', $allowedOutlets);
                    }
                    foreach ($srQ->get(['id', 'warehouse_name', 'grand_total', 'paid_amount', 'refund_tender', 'payment_type', 'payments_payload', 'created_at']) as $ret) {
                        foreach ($realRegs as $r) {
                            $rid = (int)$r->id;
                            if ((string)$ret->warehouse_name !== (string)$r->warehouse_name) continue;
                            $rCreated = (string)($ret->created_at ?? '');
                            if ($rCreated < $regMeta[$rid]['opened_at'] || $rCreated > $regMeta[$rid]['closed_at']) continue;
                            $shiftReturnsByReg[$rid][] = $ret;
                        }
                    }
                }
            }

            foreach ($realRegs as $r) {
                $rid = (int)$r->id;
                $shiftSales = collect($shiftSalesByReg[$rid] ?? []);
                $txCount = $shiftSales->count();
                $salesTotal = (float)$shiftSales->sum('grand_total');

                $tenderTotals = [];
                $tenderCounts = [];

                foreach ($shiftSales as $s) {
                    $spForSale = $salePaymentsBySale[(int)$s->id] ?? [];
                    if (!empty($spForSale)) {
                        foreach ($spForSale as $sp) {
                            $spReg = isset($sp->cash_register_id) && $sp->cash_register_id !== null ? (int)$sp->cash_register_id : null;
                            if ($spReg !== null && $spReg !== $rid) {
                                continue;
                            }
                            $rawMethod = (string)($sp->payment_type ?? 'Cash');
                            $mName = $this->normalizePaymentMethodName($rawMethod);
                            $netAmount = max(0.0, (float)$sp->amount);
                            $tenderTotals[$mName] = ($tenderTotals[$mName] ?? 0.0) + $netAmount;
                            $tenderCounts[$mName] = ($tenderCounts[$mName] ?? 0) + 1;
                        }
                    } else {
                        $rawMethod = (string)($s->payment_type ?? 'Cash');
                        $mName = $this->normalizePaymentMethodName($rawMethod);
                        $paid = (float)$s->paid_amount;
                        if ($paid > 0) {
                            $tenderTotals[$mName] = ($tenderTotals[$mName] ?? 0.0) + $paid;
                            $tenderCounts[$mName] = ($tenderCounts[$mName] ?? 0) + 1;
                        }
                    }
                }
                foreach (($crossShiftPaymentsByReg[$rid] ?? []) as $csp) {
                    $rawMethod = (string)($csp->payment_type ?? 'Cash');
                    $mName = $this->normalizePaymentMethodName($rawMethod);
                    $netAmount = max(0.0, (float)$csp->amount);
                    $tenderTotals[$mName] = ($tenderTotals[$mName] ?? 0.0) + $netAmount;
                    $tenderCounts[$mName] = ($tenderCounts[$mName] ?? 0) + 1;
                }

                $cashSales = (float)($tenderTotals['Cash'] ?? 0.0);
                $cashCount = (int)($tenderCounts['Cash'] ?? 0);
                $cardSales = 0.0;
                $cardCount = 0;
                $digitalSales = 0.0;
                $digitalCount = 0;

                $paymentBreakdown = [];
                $paymentBreakdown[] = [
                    'method' => 'Cash',
                    'amount' => round($cashSales, 2),
                    'count' => $cashCount,
                ];

                foreach ($tenderTotals as $m => $amt) {
                    $cnt = (int)($tenderCounts[$m] ?? 0);
                    if ($m !== 'Cash' && ($amt > 0 || $cnt > 0)) {
                        $paymentBreakdown[] = [
                            'method' => $m,
                            'amount' => round($amt, 2),
                            'count' => $cnt,
                        ];
                    }
                    if ($m === 'Cash') continue;
                    $mLower = strtolower($m);
                    if (str_contains($mLower, 'card') || in_array($mLower, ['visa', 'mastercard'])) {
                        $cardSales += $amt;
                        $cardCount += $cnt;
                    } else {
                        $digitalSales += $amt;
                        $digitalCount += $cnt;
                    }
                }

                // Floats in & Cash Drops
                $floatsIn = (float)($regTxAgg[$rid]['float_in'] ?? 0.0);
                $cashDrops = (float)($regTxAgg[$rid]['drop'] ?? 0.0);

                // Shift Expenses & Refunds
                $shiftExpenses = $shiftExpensesByReg[$rid] ?? [];
                $totalExpenses = 0.0;
                $cashExpenses = 0.0;
                foreach ($shiftExpenses as $exp) {
                    $totalExpenses += (float)$exp->amount;
                    $legs = $expPayByExp[(int)$exp->id] ?? [];
                    if (!empty($legs)) {
                        foreach ($legs as $leg) {
                            $legAmt = (float)($leg->amount ?? 0);
                            $legReg = $leg->cash_register_id ?? null;
                            if ($legAmt > 0
                                && $accounting->isCashPaymentType($leg->payment_type ?? null)
                                && ($legReg === null || (int)$legReg === $rid)
                            ) {
                                $cashExpenses += $legAmt;
                            }
                        }
                    } else {
                        $pStat = strtolower(trim((string)($exp->payment_status ?? '')));
                        $pmRaw = (string)($exp->payment_method ?: 'cash');
                        if ($pStat !== 'unpaid' && $accounting->isCashPaymentType($pmRaw)) {
                            $paid = (float)($exp->paid_amount ?? 0);
                            $due = (float)($exp->due_amount ?? 0);
                            if ($paid > 0.0001) {
                                $cashExpenses += $paid;
                            } elseif ($due <= 0.0001 && in_array($pStat, ['', 'paid'], true)) {
                                $cashExpenses += (float)$exp->amount;
                            }
                        }
                    }
                }

                $shiftReturns = $shiftReturnsByReg[$rid] ?? [];
                $totalRefunds = (float)($regTxAgg[$rid]['void_refund'] ?? 0.0);
                $cashRefunds = (float)($regTxAgg[$rid]['void_refund'] ?? 0.0);
                foreach ($shiftReturns as $ret) {
                    $totalRefunds += (float)($ret->grand_total ?? 0);
                    $legs = !empty($ret->payments_payload) ? json_decode((string)$ret->payments_payload, true) : null;
                    if (is_array($legs) && !empty($legs)) {
                        foreach ($legs as $leg) {
                            $amt = (float)($leg['amount'] ?? 0);
                            $ptRaw = (string)($leg['payment_type'] ?? ($ret->refund_tender ?? ($ret->payment_type ?? '')));
                            $legReg = $leg['cash_register_id'] ?? null;
                            if ($amt > 0 && $accounting->isCashPaymentType($ptRaw) && ($legReg === null || (int)$legReg === $rid)) {
                                $cashRefunds += $amt;
                            }
                        }
                    } elseif ((float)($ret->paid_amount ?? 0) > 0) {
                        $tenderRaw = (string)($ret->refund_tender ?: ($ret->payment_type ?: 'cash'));
                        if ($accounting->isCashPaymentType($tenderRaw)) {
                            $cashRefunds += (float)$ret->paid_amount;
                        }
                    }
                }

                $openingCash = (float)$r->opening_cash;
                $status = ucfirst(strtolower((string)($r->status ?: 'Closed')));

                $purchaseCashFlow = $purchaseCashByReg[$rid] ?? ['cash_purchases_paid' => 0.0, 'cash_purchase_refunds' => 0.0];
                $cashPurchasesPaid = (float)$purchaseCashFlow['cash_purchases_paid'];
                $cashPurchaseRefunds = (float)$purchaseCashFlow['cash_purchase_refunds'];

                if (strtolower($status) === 'closed' && $r->expected_cash !== null) {
                    $expectedCash = (float)$r->expected_cash;
                    $closingCash = (float)($r->closing_cash ?? $expectedCash);
                    $variance = $r->cash_difference !== null ? (float)$r->cash_difference : round($closingCash - $expectedCash, 2);
                } else {
                    $expectedCash = round($openingCash + $cashSales + $floatsIn + $cashPurchaseRefunds - $cashDrops - $cashRefunds - $cashExpenses - $cashPurchasesPaid, 2);
                    $closingCash = $r->closing_cash !== null ? (float)$r->closing_cash : $expectedCash;
                    $variance = $r->cash_difference !== null ? (float)$r->cash_difference : round($closingCash - $expectedCash, 2);
                }

                // Primary payment type
                $primaryPayment = 'Cash';
                if ($digitalSales > $cashSales && $digitalSales > $cardSales) {
                    $maxTender = 'KBZPay';
                    $maxAmt = 0;
                    foreach ($tenderTotals as $m => $amt) {
                        if ($m !== 'Cash' && $amt > $maxAmt) {
                            $maxAmt = $amt;
                            $maxTender = $m;
                        }
                    }
                    $primaryPayment = $maxTender;
                } elseif ($cardSales > $cashSales) {
                    $primaryPayment = 'Card';
                } elseif ($cashSales > 0 && ($digitalSales > 0 || $cardSales > 0)) {
                    $primaryPayment = 'Split Payment';
                }

                $sessionDate = substr((string)$r->opened_at, 0, 10);
                $cashierName = trim(explode('(', (string)($r->user_name ?: 'Super Admin'))[0]) ?: 'Super Admin';
                $refNo = $r->reference_no ?: ('REG-' . str_replace('-', '', $sessionDate) . '-' . str_pad($r->id, 4, '0', STR_PAD_LEFT));
                $registerName = $r->register_name ?: 'Register 01';

                // Filters
                if ($registerFilter !== 'All' && !empty($registerFilter) && strcasecmp($registerName, $registerFilter) !== 0) continue;
                if ($statusFilter !== 'All' && !empty($statusFilter)) {
                    $normFilter = strtolower($statusFilter) === 'active' ? 'open' : strtolower($statusFilter);
                    $normStatus = strtolower($status) === 'active' ? 'open' : strtolower($status);
                    if ($normFilter !== $normStatus) continue;
                }
                if ($paymentTypeFilter !== 'All' && !empty($paymentTypeFilter) && strcasecmp($primaryPayment, $paymentTypeFilter) !== 0) continue;

                if (!empty($search)) {
                    $q = strtolower($search);
                    $m = str_contains(strtolower($refNo), $q) || str_contains(strtolower($registerName), $q) || str_contains(strtolower((string)$r->warehouse_name), $q) || str_contains(strtolower($cashierName), $q) || str_contains($sessionDate, $q);
                    if (!$m) continue;
                }

                $userRole = (stripos($cashierName, 'admin') !== false) ? 'Super Admin' : ((stripos($cashierName, 'manager') !== false) ? 'Branch Manager' : 'Lead Cashier');

                $items[] = [
                    'id' => $rid,
                    'reference_no' => $refNo,
                    'register_id' => $registerName,
                    'warehouse_id' => (int)($r->warehouse_id ?? 1),
                    'warehouse_name' => $r->warehouse_name ?: 'Main Branch Outlet',
                    'date' => $sessionDate,
                    'opened_at' => (string)$r->opened_at,
                    'closed_at' => $status === 'Open' ? '' : (string)($r->closed_at ?: ''),
                    'total_transactions' => $txCount,
                    'opening_cash' => round($openingCash, 2),
                    'total_sales' => round($salesTotal, 2),
                    'cash_sales' => round($cashSales, 2),
                    'card_sales' => round($cardSales, 2),
                    'digital_sales' => round($digitalSales, 2),
                    'total_refunds' => round($totalRefunds, 2),
                    'cash_refunds' => round($cashRefunds, 2),
                    'total_expenses' => round($totalExpenses, 2),
                    'cash_expenses' => round($cashExpenses, 2),
                    'digital_expenses' => round($totalExpenses - $cashExpenses, 2),
                    'floats_in' => round($floatsIn, 2),
                    'cash_drops' => round($cashDrops, 2),
                    'cash_purchases_paid' => round($cashPurchasesPaid, 2),
                    'cash_purchase_refunds' => round($cashPurchaseRefunds, 2),
                    'expected_cash' => round($expectedCash, 2),
                    'closing_cash' => round($closingCash, 2),
                    'difference' => round($variance, 2),
                    'status' => $status,
                    'primary_payment_type' => $primaryPayment,
                    'notes' => $r->closing_notes ?: ($r->opening_notes ?: 'Shift verified and audited.'),
                    'created_by' => $cashierName,
                    'cashier_name' => $cashierName,
                    'user_name' => $cashierName,
                    'user_role' => $userRole,
                    'payment_breakdown' => $paymentBreakdown,
                ];
            }
        }

        // 2. Process Legacy Sales (Not linked to any real cash register, SQLite >999 safe)
        $accountedIds = array_keys($accountedSaleIds);
        $legacySalesQuery = DB::table('sales');
        $this->applyActiveDocumentFilter($legacySalesQuery, 'status');
        if (!empty($allowedOutlets)) {
            $legacySalesQuery->where(function ($q) use ($allowedOutlets, $allowedOutletNames) {
                $q->whereIn('warehouse_id', $allowedOutlets);
                if (!empty($allowedOutletNames)) {
                    $q->orWhere(function ($sub) use ($allowedOutletNames) {
                        $sub->whereNull('warehouse_id')->whereIn('warehouse_name', $allowedOutletNames);
                    });
                }
            });
        }

        if ($targetWarehouseId !== null || $targetWarehouseName !== null) {
            $legacySalesQuery->where(function ($q) use ($targetWarehouseId, $targetWarehouseName) {
                if ($targetWarehouseId !== null) $q->where('warehouse_id', $targetWarehouseId);
                if ($targetWarehouseName !== null) $q->orWhereRaw('LOWER(warehouse_name) = ?', [strtolower($targetWarehouseName)]);
            });
        }
        if ($cashier !== 'All' && !empty($cashier)) {
            $legacySalesQuery->where('created_by', 'like', "%{$cashier}%");
        }
        $this->applyReportDateRange($legacySalesQuery, $startDate !== '' ? $startDate : null, $endDate !== '' ? $endDate : null, 'date');

        if (count($accountedIds) <= 400) {
            if (!empty($accountedIds)) {
                $legacySalesQuery->whereNotIn('id', $accountedIds);
            }
            $legacyGroups = $legacySalesQuery
                ->selectRaw("
                    SUBSTRING(date, 1, 10) as session_date,
                    MIN(date) as opened_at_raw,
                    MAX(date) as closed_at_raw,
                    COALESCE(warehouse_id, 1) as w_id,
                    COALESCE(warehouse_name, 'Main Branch Outlet') as w_name,
                    COALESCE(created_by, 'Super Admin') as cashier_name,
                    COUNT(id) as tx_count,
                    COALESCE(SUM(grand_total), 0) as total_sales,
                    COALESCE(SUM(CASE WHEN LOWER(COALESCE(payment_type, 'Cash')) LIKE '%cash%' AND LOWER(COALESCE(payment_type, '')) NOT LIKE '%non%cash%' AND LOWER(COALESCE(payment_type, '')) NOT LIKE '%cashless%' THEN paid_amount ELSE 0 END), 0) as cash_sales,
                    COALESCE(SUM(CASE WHEN LOWER(COALESCE(payment_type, '')) IN ('card', 'pos card', 'credit card', 'visa', 'mastercard') THEN paid_amount ELSE 0 END), 0) as card_sales,
                    COALESCE(SUM(CASE WHEN (LOWER(COALESCE(payment_type, 'Cash')) NOT LIKE '%cash%' OR LOWER(COALESCE(payment_type, '')) LIKE '%non%cash%' OR LOWER(COALESCE(payment_type, '')) LIKE '%cashless%') AND LOWER(COALESCE(payment_type, '')) NOT IN ('card', 'pos card', 'credit card', 'visa', 'mastercard') THEN paid_amount ELSE 0 END), 0) as digital_sales
                ")
                ->groupBy(DB::raw("SUBSTRING(date, 1, 10)"), 'warehouse_id', 'warehouse_name', 'created_by')
                ->orderByDesc(DB::raw("SUBSTRING(date, 1, 10)"))
                ->get();
        } else {
            // Stream un-accounted legacy candidates safely without hitting SQLite 999 parameter limit
            if (count($realRegIds) <= 400 && !empty($realRegIds)) {
                $legacySalesQuery->where(function ($q) use ($realRegIds) {
                    $q->whereNull('cash_register_id')->orWhereNotIn('cash_register_id', $realRegIds);
                });
            }
            $groupedMap = [];
            foreach ($legacySalesQuery->select(['id', 'date', 'warehouse_id', 'warehouse_name', 'created_by', 'grand_total', 'paid_amount', 'payment_type'])->cursor() as $lsRow) {
                if (isset($accountedSaleIds[(int)$lsRow->id])) {
                    continue;
                }
                $sDate = substr((string)$lsRow->date, 0, 10);
                $wId = (int)($lsRow->warehouse_id ?? 1);
                $wName = (string)($lsRow->warehouse_name ?: 'Main Branch Outlet');
                $cName = (string)($lsRow->created_by ?: 'Super Admin');
                $gKey = $sDate . '|' . $wId . '|' . $wName . '|' . $cName;
                if (!isset($groupedMap[$gKey])) {
                    $groupedMap[$gKey] = (object)[
                        'session_date' => $sDate,
                        'opened_at_raw' => (string)$lsRow->date,
                        'closed_at_raw' => (string)$lsRow->date,
                        'w_id' => $wId,
                        'w_name' => $wName,
                        'cashier_name' => $cName,
                        'tx_count' => 0,
                        'total_sales' => 0.0,
                        'cash_sales' => 0.0,
                        'card_sales' => 0.0,
                        'digital_sales' => 0.0,
                    ];
                }
                $grp = $groupedMap[$gKey];
                $grp->tx_count++;
                $grp->total_sales += (float)$lsRow->grand_total;
                if ((string)$lsRow->date < $grp->opened_at_raw) $grp->opened_at_raw = (string)$lsRow->date;
                if ((string)$lsRow->date > $grp->closed_at_raw) $grp->closed_at_raw = (string)$lsRow->date;
                $paid = (float)$lsRow->paid_amount;
                $pt = strtolower(trim((string)($lsRow->payment_type ?: 'cash')));
                if (str_contains($pt, 'cash') && !str_contains($pt, 'non cash') && !str_contains($pt, 'non-cash') && !str_contains($pt, 'cashless')) {
                    $grp->cash_sales += $paid;
                } elseif (in_array($pt, ['card', 'pos card', 'credit card', 'visa', 'mastercard'], true)) {
                    $grp->card_sales += $paid;
                } else {
                    $grp->digital_sales += $paid;
                }
            }
            $legacyGroups = collect(array_values($groupedMap))->sortByDesc('session_date')->values();
        }

        $seq = count($items) + 1;
        foreach ($legacyGroups as $lg) {
            $sDate = $lg->session_date;
            $cName = trim(explode('(', (string)($lg->cashier_name ?: 'Super Admin'))[0]) ?: 'Super Admin';
            $refNo = 'REG-' . str_replace('-', '', $sDate) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
            $regName = 'Register ' . str_pad(($seq % 3) + 1, 2, '0', STR_PAD_LEFT);

            if ($registerFilter !== 'All' && !empty($registerFilter) && strcasecmp($regName, $registerFilter) !== 0) continue;
            if ($statusFilter !== 'All' && !empty($statusFilter)) {
                $normFilter = strtolower($statusFilter) === 'active' ? 'open' : strtolower($statusFilter);
                if ($normFilter !== 'closed') continue;
            }

            $cSales = (float)$lg->cash_sales;
            $cdSales = (float)$lg->card_sales;
            $dgSales = (float)$lg->digital_sales;
            $totSales = (float)$lg->total_sales;

            $prim = 'Cash';
            if ($dgSales > $cSales && $dgSales > $cdSales) $prim = 'KBZPay';
            elseif ($cdSales > $cSales) $prim = 'Card';
            elseif ($cSales > 0 && ($dgSales > 0 || $cdSales > 0)) $prim = 'Split Payment';

            if ($paymentTypeFilter !== 'All' && !empty($paymentTypeFilter) && strcasecmp($prim, $paymentTypeFilter) !== 0) continue;

            if (!empty($search)) {
                $q = strtolower($search);
                $m = str_contains(strtolower($refNo), $q) || str_contains(strtolower($regName), $q) || str_contains(strtolower((string)$lg->w_name), $q) || str_contains(strtolower($cName), $q) || str_contains($sDate, $q);
                if (!$m) continue;
            }

            $openCash = 0.00;
            $expCash = max(0.0, $openCash + $cSales);
            $closeCash = $expCash;
            $var = 0.00;

            $items[] = [
                'id' => $seq++,
                'reference_no' => $refNo,
                'register_id' => $regName,
                'warehouse_id' => (int)$lg->w_id,
                'warehouse_name' => $lg->w_name,
                'date' => $sDate,
                'opened_at' => (string)$lg->opened_at_raw,
                'closed_at' => (string)$lg->closed_at_raw,
                'total_transactions' => (int)$lg->tx_count,
                'opening_cash' => $openCash,
                'total_sales' => round($totSales, 2),
                'cash_sales' => round($cSales, 2),
                'card_sales' => round($cdSales, 2),
                'digital_sales' => round($dgSales, 2),
                'total_refunds' => 0.0,
                'cash_refunds' => 0.0,
                'total_expenses' => 0.0,
                'cash_expenses' => 0.0,
                'digital_expenses' => 0.0,
                'floats_in' => 0.0,
                'cash_drops' => 0.0,
                'cash_purchases_paid' => 0.0,
                'cash_purchase_refunds' => 0.0,
                'expected_cash' => round($expCash, 2),
                'closing_cash' => round($closeCash, 2),
                'difference' => $var,
                'status' => 'Closed',
                'primary_payment_type' => $prim,
                'notes' => 'Legacy POS shift historical balance.',
                'created_by' => $cName,
                'cashier_name' => $cName,
                'user_name' => $cName,
                'user_role' => 'Lead Cashier',
                'payment_breakdown' => array_values(array_filter([
                    ['method' => 'Cash', 'amount' => round($cSales, 2), 'count' => (int)$lg->tx_count],
                    $cdSales > 0 ? ['method' => 'Card', 'amount' => round($cdSales, 2), 'count' => 1] : null,
                    $dgSales > 0 ? ['method' => ($prim !== 'Cash' && $prim !== 'Card' && $prim !== 'Split Payment' ? $prim : 'KPay'), 'amount' => round($dgSales, 2), 'count' => 1] : null,
                ])),
            ];
        }

        // Sort all items by opened_at descending, tiebreak by id descending
        usort($items, function ($a, $b) {
            $aKey = (string)($a['opened_at'] ?: ($a['date'] ?? ''));
            $bKey = (string)($b['opened_at'] ?: ($b['date'] ?? ''));
            $cmp = strcmp($bKey, $aKey);
            if ($cmp === 0) {
                return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
            }
            return $cmp;
        });

        // Calculate summary and drawer flow aggregates
        $totalShiftSales = 0.0;
        $totalOpeningCash = 0.0;
        $totalCashSales = 0.0;
        $totalNonCashSales = 0.0;
        $totalRefundsSum = 0.0;
        $totalCashRefundsSum = 0.0;
        $totalExpensesSum = 0.0;
        $totalCashExpensesSum = 0.0;
        $totalFloatsInSum = 0.0;
        $totalCashDropsSum = 0.0;
        $totalCashPurchasesPaidSum = 0.0;
        $totalCashPurchaseRefundsSum = 0.0;
        $totalExpectedCash = 0.0;
        $totalClosingCash = 0.0;
        $totalVariance = 0.0;
        $totalTxSum = 0;
        $openSessionsCount = 0;
        $closedSessionsCount = 0;
        $discrepancySessionsCount = 0;

        $tenderAgg = [];

        foreach ($items as $item) {
            $totalShiftSales += (float)$item['total_sales'];
            $totalOpeningCash += (float)$item['opening_cash'];
            $totalCashSales += (float)$item['cash_sales'];
            $totalNonCashSales += ((float)$item['card_sales'] + (float)$item['digital_sales']);
            $totalRefundsSum += (float)$item['total_refunds'];
            $totalCashRefundsSum += (float)($item['cash_refunds'] ?? 0.0);
            $totalExpensesSum += (float)$item['total_expenses'];
            $totalCashExpensesSum += (float)($item['cash_expenses'] ?? 0.0);
            $totalFloatsInSum += (float)($item['floats_in'] ?? 0.0);
            $totalCashDropsSum += (float)($item['cash_drops'] ?? 0.0);
            $totalCashPurchasesPaidSum += (float)($item['cash_purchases_paid'] ?? 0.0);
            $totalCashPurchaseRefundsSum += (float)($item['cash_purchase_refunds'] ?? 0.0);
            $totalExpectedCash += (float)$item['expected_cash'];
            $totalClosingCash += (float)$item['closing_cash'];
            $totalVariance += (float)$item['difference'];
            $totalTxSum += (int)$item['total_transactions'];

            if (strtolower($item['status']) === 'open' || strtolower($item['status']) === 'active') {
                $openSessionsCount++;
            } else {
                $closedSessionsCount++;
            }
            if (abs((float)$item['difference']) > 0.009) {
                $discrepancySessionsCount++;
            }

            // Aggregate payment tenders
            if (!empty($item['payment_breakdown']) && is_array($item['payment_breakdown'])) {
                foreach ($item['payment_breakdown'] as $pb) {
                    $m = trim((string)($pb['method'] ?? 'Other'));
                    if (empty($m)) $m = 'Other';
                    if (!isset($tenderAgg[$m])) {
                        $tenderAgg[$m] = ['count' => 0, 'total_amount' => 0.0];
                    }
                    $tenderAgg[$m]['count'] += (int)($pb['count'] ?? 0);
                    $tenderAgg[$m]['total_amount'] += (float)($pb['amount'] ?? 0.0);
                }
            } else {
                if ((float)$item['cash_sales'] > 0) {
                    $tenderAgg['Cash'] = [
                        'count' => ($tenderAgg['Cash']['count'] ?? 0) + (int)$item['total_transactions'],
                        'total_amount' => ($tenderAgg['Cash']['total_amount'] ?? 0.0) + (float)$item['cash_sales'],
                    ];
                }
                if ((float)$item['card_sales'] > 0) {
                    $tenderAgg['Card'] = [
                        'count' => ($tenderAgg['Card']['count'] ?? 0) + 1,
                        'total_amount' => ($tenderAgg['Card']['total_amount'] ?? 0.0) + (float)$item['card_sales'],
                    ];
                }
                if ((float)$item['digital_sales'] > 0) {
                    $tenderAgg['Digital'] = [
                        'count' => ($tenderAgg['Digital']['count'] ?? 0) + 1,
                        'total_amount' => ($tenderAgg['Digital']['total_amount'] ?? 0.0) + (float)$item['digital_sales'],
                    ];
                }
            }
        }

        $totalTenderCollected = array_sum(array_column($tenderAgg, 'total_amount'));
        $byPaymentTender = [];
        foreach ($tenderAgg as $method => $tData) {
            if ($tData['total_amount'] > 0 || $tData['count'] > 0) {
                $byPaymentTender[] = [
                    'payment_type' => $method,
                    'count' => (int)$tData['count'],
                    'total_amount' => round((float)$tData['total_amount'], 2),
                    'percentage' => $totalTenderCollected > 0 ? round(($tData['total_amount'] / $totalTenderCollected) * 100, 1) : 0.0,
                ];
            }
        }
        if (empty($byPaymentTender)) {
            $byPaymentTender[] = [
                'payment_type' => 'Cash',
                'count' => 0,
                'total_amount' => 0.0,
                'percentage' => 0.0,
            ];
        }
        usort($byPaymentTender, function ($a, $b) {
            return $b['total_amount'] <=> $a['total_amount'];
        });

        $drawerFlowSummary = [
            'cost_masked' => !$canViewCost,
            'total_opening_float' => $canViewCost ? round($totalOpeningCash, 2) : 0.0,
            'total_cash_sales' => round($totalCashSales, 2),
            'total_floats_in' => round($totalFloatsInSum, 2),
            'total_cash_drops' => round($totalCashDropsSum, 2),
            'total_cash_refunds' => round($totalCashRefundsSum, 2),
            'total_cash_expenses' => round($totalCashExpensesSum, 2),
            'total_cash_purchases_paid' => $canViewCost ? round($totalCashPurchasesPaidSum, 2) : 0.0,
            'total_cash_purchase_refunds' => $canViewCost ? round($totalCashPurchaseRefundsSum, 2) : 0.0,
            'net_cash_movement' => $canViewCost
                ? round($totalCashSales + $totalFloatsInSum + $totalCashPurchaseRefundsSum - $totalCashDropsSum - $totalCashRefundsSum - $totalCashExpensesSum - $totalCashPurchasesPaidSum, 2)
                : 0.0,
            'expected_cash' => $canViewCost ? round($totalExpectedCash, 2) : 0.0,
            'closing_cash' => $canViewCost ? round($totalClosingCash, 2) : 0.0,
            'total_variance' => $canViewCost ? round($totalVariance, 2) : 0.0,
        ];

        $maskedItems = $items;
        if (!$canViewCost) {
            $maskedItems = array_map(function ($row) {
                $masked = $this->maskAssocNumericKeys(
                    $row,
                    ['opening_cash', 'cash_purchases_paid', 'cash_purchase_refunds', 'expected_cash', 'closing_cash', 'difference'],
                    0.0
                );
                $masked['cost_masked'] = true;
                return $masked;
            }, $items);
        } else {
            $maskedItems = array_map(function ($row) {
                $row['cost_masked'] = false;
                return $row;
            }, $items);
        }

        $totalSessions = count($maskedItems);
        $offset = ($page - 1) * $perPage;
        $pagedSessions = array_slice($maskedItems, $offset, $perPage);
        $hasMore = ($offset + count($pagedSessions)) < $totalSessions;

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'summary' => [
                'cost_masked' => !$canViewCost,
                'total_sessions' => $totalSessions,
                'open_sessions' => $openSessionsCount,
                'closed_sessions' => $closedSessionsCount,
                'total_transactions' => $totalTxSum,
                'total_opening_cash' => $canViewCost ? round($totalOpeningCash, 2) : 0.0,
                'total_shift_sales' => round($totalShiftSales, 2),
                'total_cash_sales' => round($totalCashSales, 2),
                'total_non_cash_sales' => round($totalNonCashSales, 2),
                'total_refunds' => round($totalRefundsSum, 2),
                'total_expenses' => round($totalExpensesSum, 2),
                'total_expected_cash' => $canViewCost ? round($totalExpectedCash, 2) : 0.0,
                'total_closing_cash' => $canViewCost ? round($totalClosingCash, 2) : 0.0,
                'total_variance' => $canViewCost ? round($totalVariance, 2) : 0.0,
                'discrepancy_sessions' => $discrepancySessionsCount,
            ],
            'drawer_flow_summary' => $drawerFlowSummary,
            'by_payment_tender' => $byPaymentTender,
            'data' => $pagedSessions,
            'sessions' => $pagedSessions,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalSessions,
                'last_page' => max(1, (int)ceil($totalSessions / max(1, $perPage))),
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * 14. Accounts Receivable (AR) Aging Report
     * Buckets overdue customer sales: Current (0-30), 31-60, 61-90, 90+ days
     */
    public function arAgingReport(Request $request)
    {
        $accounting     = $this->accounting();
        $warehouse      = $request->input('warehouse_name') ?? $request->input('warehouse', 'All');
        $rawAsOfDate    = (string)$request->input('as_of_date', Carbon::today()->toDateString());
        $asOfEnd        = $accounting->normalizeFilterBoundaryDate($rawAsOfDate, true);
        $asOfDate       = substr($asOfEnd, 0, 10);
        $search         = trim((string)$request->input('search', ''));
        $page           = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage        = $this->resolveReportPerPage($request, 50, 10000);
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        // Reconstruct As-Of Receivables <= $asOfEnd across active non-voided sales
        $query = DB::table('sales')
            ->where('sales.date', '<=', $asOfEnd);
        $this->applyActiveDocumentFilter($query, 'sales.status');
        if (!empty($allowedOutlets)) {
            $query->whereIn('sales.warehouse_id', $allowedOutlets);
        }

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $query->where('sales.warehouse_name', $warehouse);
        }

        $sales = $query->select(
            'sales.id',
            'sales.reference_no',
            'sales.customer_id',
            'sales.customer_name',
            'sales.date',
            'sales.grand_total',
            'sales.paid_amount',
            'sales.due_amount',
            'sales.returned_credit_amount',
            'sales.warehouse_name'
        )->get();

        $saleIds        = $sales->pluck('id')->all();
        $salePayBySale  = [];
        if (!empty($saleIds) && $accounting->schemaHasTable('sale_payments')) {
            foreach (array_chunk($saleIds, 400) as $idChunk) {
                $spChunk = DB::table('sale_payments')
                    ->whereIn('sale_id', $idChunk)
                    ->select(['sale_id', 'amount', 'payment_date'])
                    ->get();
                foreach ($spChunk as $sp) {
                    $salePayBySale[(int)$sp->sale_id][] = $sp;
                }
            }
        }

        $activeReturnsQ = DB::table('sale_returns')
            ->where('sale_returns.date', '<=', $asOfEnd);
        $this->applyActiveDocumentFilter($activeReturnsQ, 'sale_returns.status');
        if (!empty($allowedOutlets)) {
            $activeReturnsQ->whereIn('sale_returns.warehouse_id', $allowedOutlets);
        }
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $activeReturnsQ->where('sale_returns.warehouse_name', $warehouse);
        }
        $activeReturnsAsOf = $activeReturnsQ->select([
            'id', 'sale_id', 'customer_id', 'customer_name', 'date',
            'grand_total', 'paid_amount', 'due_amount', 'applied_credit_amount',
        ])->get();

        $creditsAppliedBySale = [];
        foreach ($activeReturnsAsOf as $ar) {
            if (!empty($ar->sale_id) && (float)($ar->applied_credit_amount ?? 0) > 0) {
                $sid = (int)$ar->sale_id;
                $creditsAppliedBySale[$sid] = ($creditsAppliedBySale[$sid] ?? 0.0) + (float)$ar->applied_credit_amount;
            }
        }

        $customerMap     = [];
        $totalReceivable = 0.0;
        $totalCurrent    = 0.0;
        $totalDays31_60  = 0.0;
        $totalDays61_90  = 0.0;
        $totalDays90Plus = 0.0;

        $asOfCarbon = Carbon::parse($asOfDate);

        foreach ($sales as $s) {
            $payments = $salePayBySale[(int)$s->id] ?? [];
            if (!empty($payments)) {
                $paidAsOf     = 0.0;
                $totalRecorded = 0.0;
                foreach ($payments as $p) {
                    $amt = (float)($p->amount ?? 0);
                    if ($amt <= 0) {
                        continue;
                    }
                    $totalRecorded += $amt;
                    $pDateStr = $accounting->normalizeTransactionDate((string)($p->payment_date ?? $s->date), (string)$s->date);
                    if ($pDateStr <= $asOfEnd) {
                        $paidAsOf += $amt;
                    }
                }
                // Preserve unlinked initial payment gap if historical deposit lacked a payment row
                $gap = max(0.0, (float)$s->paid_amount - $totalRecorded);
                if ($gap > 0.01) {
                    $paidAsOf += $gap;
                }
            } else {
                $paidAsOf = (float)$s->paid_amount;
            }

            $creditAsOf = array_key_exists((int)$s->id, $creditsAppliedBySale)
                ? (float)$creditsAppliedBySale[(int)$s->id]
                : (float)($s->returned_credit_amount ?? 0.0);

            $due = round(max(0.0, (float)$s->grand_total - $paidAsOf - $creditAsOf), 2);
            if ($due <= 0.001) {
                continue;
            }

            $cId   = $s->customer_id ?: 0;
            $cName = $s->customer_name ?: 'Walk-in Customer';
            $key   = $cId > 0 ? (string)$cId : 'name_' . $cName;

            $saleDate = Carbon::parse(substr($s->date, 0, 10));
            $days     = $saleDate->diffInDays($asOfCarbon, false);
            if ($days < 0) {
                $days = 0;
            }

            if (!isset($customerMap[$key])) {
                $customerMap[$key] = [
                    'customer_id'         => $cId,
                    'customer_name'       => $cName,
                    'phone'               => '',
                    'email'               => '',
                    'tier'                => 'Regular',
                    'credit_limit'        => 0.0,
                    'gross_due'           => 0.0,
                    'open_credit_notes'   => 0.0,
                    'total_due'           => 0.0,
                    'current'             => 0.0,
                    'days_31_60'          => 0.0,
                    'days_61_90'          => 0.0,
                    'days_90_plus'        => 0.0,
                    'oldest_invoice_date' => substr($s->date, 0, 10),
                    'invoices_count'      => 0,
                    'invoices'            => [],
                ];
            }

            $customerMap[$key]['gross_due']      += $due;
            $customerMap[$key]['total_due']      += $due;
            $customerMap[$key]['invoices_count'] += 1;
            if (substr($s->date, 0, 10) < $customerMap[$key]['oldest_invoice_date']) {
                $customerMap[$key]['oldest_invoice_date'] = substr($s->date, 0, 10);
            }

            if ($days <= 30) {
                $customerMap[$key]['current'] += $due;
            } elseif ($days <= 60) {
                $customerMap[$key]['days_31_60'] += $due;
            } elseif ($days <= 90) {
                $customerMap[$key]['days_61_90'] += $due;
            } else {
                $customerMap[$key]['days_90_plus'] += $due;
            }

            if (count($customerMap[$key]['invoices']) < 15) {
                $customerMap[$key]['invoices'][] = [
                    'id'           => $s->id,
                    'reference_no' => $s->reference_no,
                    'date'         => substr($s->date, 0, 10),
                    'grand_total'  => (float)$s->grand_total,
                    'paid_amount'  => round($paidAsOf, 2),
                    'due_amount'   => $due,
                    'days_overdue' => $days,
                ];
            }
        }

        // Deduct unrefunded active Customer Credit Notes (sale_returns.due_amount) per customer cleanly
        $totalOpenCreditNotes = 0.0;
        $appliedCreditToDebtors = 0.0;
        foreach ($activeReturnsAsOf as $ar) {
            $cnDue = round((float)($ar->due_amount ?? 0), 2);
            if ($cnDue <= 0.001) {
                continue;
            }
            $totalOpenCreditNotes += $cnDue;
            $cId   = $ar->customer_id ?: 0;
            $cName = $ar->customer_name ?: 'Walk-in Customer';
            $key   = $cId > 0 ? (string)$cId : 'name_' . $cName;
            if (!isset($customerMap[$key])) {
                continue;
            }
            $custRow = &$customerMap[$key];
            $custRow['open_credit_notes'] += $cnDue;
            $remCredit = min($cnDue, (float)$custRow['total_due']);
            $appliedCreditToDebtors += $remCredit;
            $custRow['total_due'] = max(0.0, round($custRow['total_due'] - $remCredit, 2));

            // Absorb credit notes against oldest overdue buckets first (90+ -> 61-90 -> 31-60 -> current)
            foreach (['days_90_plus', 'days_61_90', 'days_31_60', 'current'] as $bField) {
                if ($remCredit <= 0.0001) {
                    break;
                }
                $absorb = min($remCredit, (float)$custRow[$bField]);
                $custRow[$bField] = max(0.0, round($custRow[$bField] - $absorb, 2));
                $remCredit -= $absorb;
            }
            unset($custRow);
        }

        // Filter out settled accounts whose open invoices were completely offset by credit notes
        $customerMap = array_filter($customerMap, static fn($row) => $row['total_due'] > 0.001);

        // Enrich with customer details (chunked SQLite-safe)
        $customerIds = array_values(array_unique(array_filter(array_column($customerMap, 'customer_id'))));
        if (!empty($customerIds)) {
            $customerDetails = collect();
            foreach (array_chunk($customerIds, 400) as $idChunk) {
                $chunkRows = Customer::whereIn('id', $idChunk)
                    ->select(['id', 'phone', 'email', 'tier', 'credit_limit'])
                    ->get();
                foreach ($chunkRows as $cd) {
                    $customerDetails->put($cd->id, $cd);
                }
            }
            foreach ($customerMap as $k => &$cust) {
                if (!empty($cust['customer_id']) && isset($customerDetails[$cust['customer_id']])) {
                    $cd = $customerDetails[$cust['customer_id']];
                    $cust['phone'] = $cd->phone ?? '';
                    $cust['email'] = $cd->email ?? '';
                    $cust['tier'] = $cd->tier ?? 'Regular';
                    $cust['credit_limit'] = (float)($cd->credit_limit ?? 0);
                }
            }
            unset($cust);
        }

        $items = array_values($customerMap);

        if (!empty($search)) {
            $q = strtolower($search);
            $items = array_filter($items, function ($it) use ($q) {
                return str_contains(strtolower($it['customer_name']), $q) ||
                       str_contains(strtolower($it['phone']), $q) ||
                       str_contains(strtolower($it['email']), $q) ||
                       str_contains(strtolower($it['tier'] ?? ''), $q);
            });
            $items = array_values($items);
        }

        $totalReceivable = 0.0;
        $totalCurrent    = 0.0;
        $totalDays31_60  = 0.0;
        $totalDays61_90  = 0.0;
        $totalDays90Plus = 0.0;
        $filteredOpenCreditNotes = 0.0;
        foreach ($items as $row) {
            $totalReceivable += $row['total_due'];
            $totalCurrent    += $row['current'];
            $totalDays31_60  += $row['days_31_60'];
            $totalDays61_90  += $row['days_61_90'];
            $totalDays90Plus += $row['days_90_plus'];
            $filteredOpenCreditNotes += (float)($row['open_credit_notes'] ?? 0);
        }
        $totalOpenCreditNotes = !empty($search) ? $filteredOpenCreditNotes : $totalOpenCreditNotes;

        usort($items, function ($a, $b) {
            return $b['total_due'] <=> $a['total_due'];
        });

        foreach ($items as &$it) {
            $it['gross_due'] = round((float)($it['gross_due'] ?? $it['total_due']), 2);
            $it['open_credit_notes'] = round((float)($it['open_credit_notes'] ?? 0), 2);
            $it['total_due'] = round($it['total_due'], 2);
            $it['current'] = round($it['current'], 2);
            $it['days_31_60'] = round($it['days_31_60'], 2);
            $it['days_61_90'] = round($it['days_61_90'], 2);
            $it['days_90_plus'] = round($it['days_90_plus'], 2);
        }
        unset($it);

        $totalOverdue = $totalDays31_60 + $totalDays61_90 + $totalDays90Plus;
        $totalCustomers = count($items);
        $lastPage = max(1, (int)ceil($totalCustomers / max(1, $perPage)));
        $offset = ($page - 1) * $perPage;
        $pagedItems = array_slice($items, $offset, $perPage);
        $hasMore = ($offset + count($pagedItems)) < $totalCustomers;

        $unappliedCreditNotes = round(max(0.0, $totalOpenCreditNotes - $appliedCreditToDebtors), 2);
        return $this->noStoreJson([
            'status' => 'success',
            'summary' => [
                'total_receivable' => round($totalReceivable, 2),
                'total_receivables' => round($totalReceivable, 2),
                'total_open_credit_notes' => round($totalOpenCreditNotes, 2),
                'unapplied_open_credit_notes' => $unappliedCreditNotes,
                'net_receivable_after_all_credits' => round($totalReceivable - $unappliedCreditNotes, 2),
                'current' => round($totalCurrent, 2),
                'days_31_60' => round($totalDays31_60, 2),
                'days_61_90' => round($totalDays61_90, 2),
                'days_90_plus' => round($totalDays90Plus, 2),
                'total_overdue' => round($totalOverdue, 2),
                'total_customers' => $totalCustomers,
                'as_of_date' => $asOfDate,
            ],
            'data' => $pagedItems,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalCustomers,
                'last_page' => $lastPage,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * 15. Accounts Payable (AP) Aging Report
     * Buckets overdue supplier purchases: Current (0-30), 31-60, 61-90, 90+ days
     */
    public function apAgingReport(Request $request)
    {
        $accounting = $this->accounting();
        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse', 'All');
        $rawAsOfDate = (string)$request->input('as_of_date', Carbon::today()->toDateString());
        $asOfEnd = $accounting->normalizeFilterBoundaryDate($rawAsOfDate, true);
        $asOfDate = substr($asOfEnd, 0, 10);
        $search = trim((string)$request->input('search', ''));
        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        $query = DB::table('purchases')
            ->whereIn('purchases.status', ['Received', 'Completed'])
            ->where('purchases.date', '<=', $asOfEnd);
        $this->excludeOpeningStockPurchases($query, 'purchases.payment_type');
        if (!empty($allowedOutlets)) {
            $query->whereIn('purchases.warehouse_id', $allowedOutlets);
        }

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $query->where('purchases.warehouse_name', $warehouse);
        }

        $purchases = $query->select(
            'purchases.id',
            'purchases.reference_no',
            'purchases.supplier_id',
            'purchases.supplier_name',
            'purchases.date',
            'purchases.grand_total',
            'purchases.paid_amount',
            'purchases.due_amount',
            'purchases.returned_credit_amount',
            'purchases.payments_payload',
            'purchases.warehouse_name'
        )->get();

        $poIds = $purchases->pluck('id')->all();
        $allocMaps = $accounting->computeAsOfPurchaseReturnMaps($poIds, [], $asOfEnd);
        $poAllocAsOf = $allocMaps['po_alloc'];
        $poHasAnyAlloc = $allocMaps['po_has_any_alloc'];

        $supplierMap = [];
        $totalPayable = 0.0;
        $totalCurrent = 0.0;
        $totalDays31_60 = 0.0;
        $totalDays61_90 = 0.0;
        $totalDays90Plus = 0.0;

        $asOfCarbon = Carbon::parse($asOfDate);

        foreach ($purchases as $p) {
            $paidAsOf = $accounting->sumPayloadPaymentsAsOf($p->payments_payload ?? null, (float)$p->paid_amount, (string)$p->date, $asOfEnd);
            $creditAsOf = isset($poHasAnyAlloc[$p->id])
                ? (float)($poAllocAsOf[$p->id] ?? 0.0)
                : (float)($p->returned_credit_amount ?? 0.0);
            $due = round(max(0.0, (float)$p->grand_total - $paidAsOf - $creditAsOf), 2);
            if ($due <= 0.001) {
                continue;
            }

            $sId = $p->supplier_id ?: 0;
            $sName = $p->supplier_name ?: 'General Supplier';
            $key = $sId > 0 ? (string)$sId : 'name_' . $sName;

            $purchDate = Carbon::parse(substr($p->date, 0, 10));
            $days = $purchDate->diffInDays($asOfCarbon, false);
            if ($days < 0) $days = 0;

            if (!isset($supplierMap[$key])) {
                $supplierMap[$key] = [
                    'supplier_id' => $sId,
                    'supplier_name' => $sName,
                    'contact_person' => '',
                    'phone' => '',
                    'email' => '',
                    'category' => 'General',
                    'payment_terms' => 'Net 30',
                    'total_due' => 0.0,
                    'current' => 0.0,
                    'days_31_60' => 0.0,
                    'days_61_90' => 0.0,
                    'days_90_plus' => 0.0,
                    'oldest_invoice_date' => substr($p->date, 0, 10),
                    'invoices_count' => 0,
                    'invoices' => [],
                ];
            }

            $supplierMap[$key]['total_due'] += $due;
            $supplierMap[$key]['invoices_count'] += 1;
            if (substr($p->date, 0, 10) < $supplierMap[$key]['oldest_invoice_date']) {
                $supplierMap[$key]['oldest_invoice_date'] = substr($p->date, 0, 10);
            }

            if ($days <= 30) {
                $supplierMap[$key]['current'] += $due;
                $totalCurrent += $due;
            } elseif ($days <= 60) {
                $supplierMap[$key]['days_31_60'] += $due;
                $totalDays31_60 += $due;
            } elseif ($days <= 90) {
                $supplierMap[$key]['days_61_90'] += $due;
                $totalDays61_90 += $due;
            } else {
                $supplierMap[$key]['days_90_plus'] += $due;
                $totalDays90Plus += $due;
            }

            $totalPayable += $due;

            if (count($supplierMap[$key]['invoices']) < 15) {
                $supplierMap[$key]['invoices'][] = [
                    'id' => $p->id,
                    'reference_no' => $p->reference_no,
                    'date' => substr($p->date, 0, 10),
                    'grand_total' => (float)$p->grand_total,
                    'paid_amount' => $paidAsOf,
                    'due_amount' => $due,
                    'days_overdue' => $days,
                ];
            }
        }

        if (($warehouse === 'All' || empty($warehouse)) && empty($allowedOutlets)) {
            $openAdjustments = $accounting->computeSupplierOpeningBalanceAdjustments([], $asOfEnd);
            foreach ($openAdjustments as $supId => $adjEntry) {
                $openDue = round((float)($adjEntry['opening_adjustment'] ?? 0.0), 2);
                if ($openDue <= 0.005) {
                    continue;
                }
                $supRow = $adjEntry['supplier'] ?? null;
                $sName = ($supRow && !empty($supRow->name)) ? $supRow->name : 'General Supplier';
                $key = (string)$supId;
                $openDateRaw = ($supRow && !empty($supRow->created_at)) ? (string)$supRow->created_at : $asOfDate;
                $openDateStr = substr($accounting->normalizeTransactionDate($openDateRaw, $asOfDate), 0, 10);
                $openDays = Carbon::parse($openDateStr)->diffInDays($asOfCarbon, false);
                if ($openDays < 0) $openDays = 0;

                if (!isset($supplierMap[$key])) {
                    $supplierMap[$key] = [
                        'supplier_id' => (int)$supId,
                        'supplier_name' => $sName,
                        'contact_person' => $supRow->contact_person ?? '',
                        'phone' => $supRow->phone ?? '',
                        'email' => $supRow->email ?? '',
                        'category' => $supRow->category ?? 'General',
                        'payment_terms' => $supRow->payment_terms ?? 'Net 30',
                        'total_due' => 0.0,
                        'current' => 0.0,
                        'days_31_60' => 0.0,
                        'days_61_90' => 0.0,
                        'days_90_plus' => 0.0,
                        'oldest_invoice_date' => $openDateStr,
                        'invoices_count' => 0,
                        'invoices' => [],
                    ];
                }

                $supplierMap[$key]['total_due'] += $openDue;
                $supplierMap[$key]['invoices_count'] += 1;
                if ($openDateStr < $supplierMap[$key]['oldest_invoice_date']) {
                    $supplierMap[$key]['oldest_invoice_date'] = $openDateStr;
                }
                if ($openDays <= 30) {
                    $supplierMap[$key]['current'] += $openDue;
                } elseif ($openDays <= 60) {
                    $supplierMap[$key]['days_31_60'] += $openDue;
                } elseif ($openDays <= 90) {
                    $supplierMap[$key]['days_61_90'] += $openDue;
                } else {
                    $supplierMap[$key]['days_90_plus'] += $openDue;
                }

                if (count($supplierMap[$key]['invoices']) < 15) {
                    $supplierMap[$key]['invoices'][] = [
                        'id' => 0,
                        'reference_no' => 'OPENING-BALANCE',
                        'date' => $openDateStr,
                        'grand_total' => $openDue,
                        'paid_amount' => 0.0,
                        'due_amount' => $openDue,
                        'days_overdue' => $openDays,
                    ];
                }
            }
        }

        // Subtract unallocated Completed/Received Purchase Return credits as of $asOfEnd
        $unallocPrQuery = DB::table('purchase_returns')
            ->whereIn('status', ['Completed', 'Received'])
            ->where('date', '<=', $asOfEnd);
        if (!empty($allowedOutlets)) {
            $unallocPrQuery->whereIn('warehouse_id', $allowedOutlets);
        }
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $unallocPrQuery->where('warehouse_name', $warehouse);
        }
        $openCredits = $unallocPrQuery->select('id', 'supplier_id', 'supplier_name', 'date', 'grand_total', 'paid_amount', 'due_amount', 'payments_payload')->get();
        if ($openCredits->isNotEmpty()) {
            $openCreditIds = $openCredits->pluck('id')->all();
            $allocByPr = [];
            $hasReleasedAt = $accounting->schemaHasColumn('purchase_return_allocations', 'released_at');
            foreach (array_chunk($openCreditIds, 400) as $idChunk) {
                $allocQuery = DB::table('purchase_return_allocations')
                    ->join('purchases', 'purchase_return_allocations.purchase_id', '=', 'purchases.id')
                    ->whereIn('purchase_return_allocations.purchase_return_id', $idChunk)
                    ->whereIn('purchases.status', ['Received', 'Completed'])
                    ->where('purchases.date', '<=', $asOfEnd);
                if (!empty($allowedOutlets)) {
                    $allocQuery->whereIn('purchases.warehouse_id', $allowedOutlets);
                }
                if ($hasReleasedAt) {
                    $allocQuery->where(function ($rq) use ($asOfEnd) {
                        $rq->whereNull('purchase_return_allocations.released_at')
                            ->orWhere('purchase_return_allocations.released_at', '>', $asOfEnd);
                    });
                }
                if ($warehouse !== 'All' && !empty($warehouse)) {
                    $allocQuery->where('purchases.warehouse_name', $warehouse);
                }
                foreach ($allocQuery
                    ->select('purchase_return_allocations.purchase_return_id', DB::raw('SUM(purchase_return_allocations.amount) as allocated'))
                    ->groupBy('purchase_return_allocations.purchase_return_id')
                    ->pluck('allocated', 'purchase_return_id') as $prId => $allocVal) {
                    $allocByPr[$prId] = ($allocByPr[$prId] ?? 0.0) + (float)$allocVal;
                }
            }

            foreach ($openCredits as $cr) {
                $refundedAsOf = $accounting->sumPayloadPaymentsAsOf($cr->payments_payload ?? null, (float)$cr->paid_amount, (string)$cr->date, $asOfEnd);
                $crDueAsOf = round(max(0.0, (float)$cr->grand_total - $refundedAsOf), 2);
                $unalloc = round(max(0.0, $crDueAsOf - (float)($allocByPr[$cr->id] ?? 0.0)), 2);
                if ($unalloc <= 0.001) {
                    continue;
                }
                $cKey = ($cr->supplier_id ?: 0) > 0
                    ? (string)$cr->supplier_id
                    : 'name_' . ($cr->supplier_name ?: 'General Supplier');
                if (!isset($supplierMap[$cKey])) {
                    $supplierMap[$cKey] = [
                        'supplier_id' => (int)($cr->supplier_id ?: 0),
                        'supplier_name' => $cr->supplier_name ?: 'General Supplier',
                        'contact_person' => '',
                        'phone' => '',
                        'email' => '',
                        'category' => 'General',
                        'payment_terms' => 'Net 30',
                        'total_due' => 0.0,
                        'unallocated_credit' => 0.0,
                        'credit_balance' => 0.0,
                        'current' => 0.0,
                        'days_31_60' => 0.0,
                        'days_61_90' => 0.0,
                        'days_90_plus' => 0.0,
                        'oldest_invoice_date' => $asOfDate,
                        'invoices_count' => 0,
                        'invoices' => [],
                    ];
                }
                $supplierMap[$cKey]['unallocated_credit'] = round(($supplierMap[$cKey]['unallocated_credit'] ?? 0.0) + $unalloc, 2);
                $rem = $unalloc;
                foreach (['days_90_plus', 'days_61_90', 'days_31_60', 'current'] as $bucket) {
                    if ($rem <= 0.001) {
                        break;
                    }
                    $avail = (float)$supplierMap[$cKey][$bucket];
                    if ($avail > 0) {
                        $deduct = min($avail, $rem);
                        $supplierMap[$cKey][$bucket] -= $deduct;
                        $rem -= $deduct;
                    }
                }
                $netRemaining = (float)$supplierMap[$cKey]['total_due'] - $unalloc;
                if ($netRemaining >= 0) {
                    $supplierMap[$cKey]['total_due'] = $netRemaining;
                } else {
                    $supplierMap[$cKey]['total_due'] = 0.0;
                    $supplierMap[$cKey]['credit_balance'] = round(($supplierMap[$cKey]['credit_balance'] ?? 0.0) + abs($netRemaining), 2);
                }
            }
        }

        // Recompute totals cleanly from reconciled supplier buckets
        $totalPayable = 0.0;
        $totalCurrent = 0.0;
        $totalDays31_60 = 0.0;
        $totalDays61_90 = 0.0;
        $totalDays90Plus = 0.0;
        foreach ($supplierMap as $entry) {
            $totalPayable += (float)$entry['total_due'];
            $totalCurrent += (float)$entry['current'];
            $totalDays31_60 += (float)$entry['days_31_60'];
            $totalDays61_90 += (float)$entry['days_61_90'];
            $totalDays90Plus += (float)$entry['days_90_plus'];
        }

        // Enrich with supplier details (chunked SQLite-safe)
        $supplierIds = array_values(array_unique(array_filter(array_column($supplierMap, 'supplier_id'))));
        if (!empty($supplierIds)) {
            $supplierDetails = collect();
            foreach (array_chunk($supplierIds, 400) as $idChunk) {
                $chunkRows = Supplier::whereIn('id', $idChunk)
                    ->select(['id', 'contact_person', 'phone', 'email', 'category', 'payment_terms'])
                    ->get();
                foreach ($chunkRows as $sd) {
                    $supplierDetails->put($sd->id, $sd);
                }
            }
            foreach ($supplierMap as $k => &$supp) {
                if (!empty($supp['supplier_id']) && isset($supplierDetails[$supp['supplier_id']])) {
                    $sd = $supplierDetails[$supp['supplier_id']];
                    $supp['contact_person'] = $sd->contact_person ?? '';
                    $supp['phone'] = $sd->phone ?? '';
                    $supp['email'] = $sd->email ?? '';
                    $supp['category'] = $sd->category ?? 'General';
                    $supp['payment_terms'] = $sd->payment_terms ?? 'Net 30';
                }
            }
            unset($supp);
        }

        $items = array_values($supplierMap);

        if (!empty($search)) {
            $q = strtolower($search);
            $items = array_filter($items, function ($it) use ($q) {
                return str_contains(strtolower($it['supplier_name']), $q) ||
                       str_contains(strtolower($it['contact_person']), $q) ||
                       str_contains(strtolower($it['phone']), $q) ||
                       str_contains(strtolower($it['email']), $q) ||
                       str_contains(strtolower($it['category'] ?? ''), $q);
            });
            $items = array_values($items);
        }

        usort($items, function ($a, $b) {
            return $b['total_due'] <=> $a['total_due'];
        });

        foreach ($items as &$it) {
            $it['total_due'] = round($it['total_due'], 2);
            $it['current'] = round($it['current'], 2);
            $it['days_31_60'] = round($it['days_31_60'], 2);
            $it['days_61_90'] = round($it['days_61_90'], 2);
            $it['days_90_plus'] = round($it['days_90_plus'], 2);
        }
        unset($it);

        if (!empty($search)) {
            $totalPayable = 0.0;
            $totalCurrent = 0.0;
            $totalDays31_60 = 0.0;
            $totalDays61_90 = 0.0;
            $totalDays90Plus = 0.0;
            foreach ($items as $it) {
                $totalPayable += (float)$it['total_due'];
                $totalCurrent += (float)$it['current'];
                $totalDays31_60 += (float)$it['days_31_60'];
                $totalDays61_90 += (float)$it['days_61_90'];
                $totalDays90Plus += (float)$it['days_90_plus'];
            }
        }

        $totalOverdue = $totalDays31_60 + $totalDays61_90 + $totalDays90Plus;
        $totalSuppliers = count($items);
        $lastPage = max(1, (int)ceil($totalSuppliers / max(1, $perPage)));
        $offset = ($page - 1) * $perPage;
        $pagedItems = array_slice($items, $offset, $perPage);
        $hasMore = ($offset + count($pagedItems)) < $totalSuppliers;

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'summary' => [
                'cost_masked' => !$canViewCost,
                'total_payable' => $canViewCost ? round($totalPayable, 2) : 0.0,
                'current' => $canViewCost ? round($totalCurrent, 2) : 0.0,
                'days_31_60' => $canViewCost ? round($totalDays31_60, 2) : 0.0,
                'days_61_90' => $canViewCost ? round($totalDays61_90, 2) : 0.0,
                'days_90_plus' => $canViewCost ? round($totalDays90Plus, 2) : 0.0,
                'total_overdue' => $canViewCost ? round($totalOverdue, 2) : 0.0,
                'total_suppliers' => $totalSuppliers,
                'as_of_date' => $asOfDate,
            ],
            'data' => $canViewCost ? $pagedItems : array_map(function ($it) {
                $masked = $this->maskAssocNumericKeys(
                    $it,
                    ['total_due', 'unallocated_credit', 'credit_balance', 'current', 'days_31_60', 'days_61_90', 'days_90_plus'],
                    null
                );
                $masked['cost_masked'] = true;
                $masked['invoices'] = [];
                return $masked;
            }, $pagedItems),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalSuppliers,
                'last_page' => $lastPage,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * 16. Cash Flow Statement (Direct Method)
     * Cash Inflows vs Cash Outflows and Net Operating Cash Flow
     */
    public function cashFlowReport(Request $request)
    {
        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse', 'All');
        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate = $request->input('end_date') ?? $request->input('to_date');

        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $accounting    = $this->accounting();
        $canViewCost   = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        $startDt = !empty($startDate) ? $accounting->normalizeFilterBoundaryDate((string)$startDate, false) : null;
        $endDt = !empty($endDate) ? $accounting->normalizeFilterBoundaryDate((string)$endDate, true) : null;
        $priorStartDateTime = $startDt;

        // 1. Cash Inflows: Sales Cash Received strictly by sale_payments.payment_date (fallback to sales.date when legacy unsplit)
        $hasSpTable = $accounting->schemaHasTable('sale_payments');
        $salesQuery = DB::table('sales');
        $this->applyActiveDocumentFilter($salesQuery, 'status');
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $salesQuery->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $salesQuery->whereIn('warehouse_id', $allowedOutlets);
        }

        $legacyNoPaySalesQ = (clone $salesQuery)
            ->where('paid_amount', '>', 0)
            ->when($hasSpTable, fn ($q) => $q->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))->from('sale_payments')->whereColumn('sale_payments.sale_id', 'sales.id');
            }));
        if ($startDt !== null) {
            $legacyNoPaySalesQ->where('date', '>=', $startDt);
        }
        if ($endDt !== null) {
            $legacyNoPaySalesQ->where('date', '<=', $endDt);
        }

        $salesCashReceived = (float)(clone $legacyNoPaySalesQ)->sum('paid_amount');
        $tenderTotalsMap = [];
        $tenderCountsMap = [];
        foreach ((clone $legacyNoPaySalesQ)
            ->select('payment_type', DB::raw('SUM(paid_amount) as amount'), DB::raw('COUNT(*) as count'))
            ->groupBy('payment_type')
            ->get() as $lt) {
            $pt = $lt->payment_type ?: 'Cash';
            $tenderTotalsMap[$pt] = ($tenderTotalsMap[$pt] ?? 0.0) + (float)$lt->amount;
            $tenderCountsMap[$pt] = ($tenderCountsMap[$pt] ?? 0) + (int)$lt->count;
        }

        $spPeriodQ = null;
        if ($hasSpTable) {
            $spPeriodQ = DB::table('sale_payments')
                ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
                ->where('sale_payments.amount', '>', 0);
            $this->applyActiveDocumentFilter($spPeriodQ, 'sales.status');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $spPeriodQ->where('sales.warehouse_name', $warehouse);
            }
            if (!empty($allowedOutlets)) {
                $spPeriodQ->whereIn('sales.warehouse_id', $allowedOutlets);
            }
            $spPayDateExpr = 'COALESCE(sale_payments.payment_date, sales.date)';
            if ($startDt !== null) {
                $spPeriodQ->whereRaw("{$spPayDateExpr} >= ?", [$startDt]);
            }
            if ($endDt !== null) {
                $spPeriodQ->whereRaw("{$spPayDateExpr} <= ?", [$endDt]);
            }
            $salesCashReceived += (float)(clone $spPeriodQ)->sum('sale_payments.amount');
            foreach ((clone $spPeriodQ)
                ->select('sale_payments.payment_type', DB::raw('SUM(sale_payments.amount) as amount'), DB::raw('COUNT(*) as count'))
                ->groupBy('sale_payments.payment_type')
                ->get() as $spt) {
                $pt = $spt->payment_type ?: 'Cash';
                $tenderTotalsMap[$pt] = ($tenderTotalsMap[$pt] ?? 0.0) + (float)$spt->amount;
                $tenderCountsMap[$pt] = ($tenderCountsMap[$pt] ?? 0) + (int)$spt->count;
            }
        }
        arsort($tenderTotalsMap);
        $tenderInflows = collect($tenderTotalsMap)->map(fn ($amt, $pt) => [
            'payment_type' => $pt,
            'amount' => round((float)$amt, 2),
            'count' => (int)($tenderCountsMap[$pt] ?? 0),
        ])->values();

        $supplierRefundsReceived = 0.0;
        $purchCashPaid = 0.0;
        $dailySupplierRefunds = collect();
        $dailyPurch = collect();
        $purchRetTxns = collect();
        $purchTxns = collect();

        // Calculate Opening Cash Balance (prior to startDate under IAS 7, payment-date aware)
        $openingBalance = 0.0;
        if ($priorStartDateTime !== null) {
            $priorLegacySalesQ = (clone $salesQuery)
                ->where('date', '<', $priorStartDateTime)
                ->where('paid_amount', '>', 0)
                ->when($hasSpTable, fn ($q) => $q->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))->from('sale_payments')->whereColumn('sale_payments.sale_id', 'sales.id');
                }));
            $priorIn = (float)$priorLegacySalesQ->sum('paid_amount');
            if ($hasSpTable) {
                $priorSpQ = DB::table('sale_payments')
                    ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
                    ->whereRaw('COALESCE(sale_payments.payment_date, sales.date) < ?', [$priorStartDateTime]);
                $this->applyActiveDocumentFilter($priorSpQ, 'sales.status');
                if ($warehouse !== 'All' && !empty($warehouse)) {
                    $priorSpQ->where('sales.warehouse_name', $warehouse);
                }
                if (!empty($allowedOutlets)) {
                    $priorSpQ->whereIn('sales.warehouse_id', $allowedOutlets);
                }
                $priorIn += (float)$priorSpQ->sum('sale_payments.amount');
            }

            $priorRetQ = DB::table('sale_returns')->where('date', '<', $priorStartDateTime);
            $this->applyActiveDocumentFilter($priorRetQ, 'status');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $priorRetQ->where('warehouse_name', $warehouse);
            }
            if (!empty($allowedOutlets)) {
                $priorRetQ->whereIn('warehouse_id', $allowedOutlets);
            }
            $priorOut = (float)$priorRetQ->sum('paid_amount');

            $openingBalance = $priorIn - $priorOut;
        }

        // Extract exact payment-dated supplier cash outflows (Received/Completed Purchases, excl Opening Stock)
        $purchBaseQ = DB::table('purchases')
            ->whereIn('status', ['Received', 'Completed'])
            ->where('paid_amount', '>', 0);
        $this->excludeOpeningStockPurchases($purchBaseQ, 'payment_type');
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $purchBaseQ->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $purchBaseQ->whereIn('warehouse_id', $allowedOutlets);
        }
        $dailyPurchArr = [];
        foreach ($purchBaseQ->select([
            'id', 'reference_no', 'date', 'supplier_name', 'warehouse_name',
            'payment_type', 'paid_amount', 'payments_payload', 'created_by',
            'user_name', 'user_role', 'status', 'notes'
        ])->cursor() as $po) {
            $legs = !empty($po->payments_payload) ? json_decode((string)$po->payments_payload, true) : null;
            if (is_array($legs) && !empty($legs)) {
                foreach ($legs as $leg) {
                    $amt = (float)($leg['amount'] ?? 0);
                    if ($amt <= 0) {
                        continue;
                    }
                    $rawDt = $accounting->normalizeTransactionDate(
                        (string)($leg['payment_date'] ?? $po->date),
                        (string)$po->date
                    );
                    if ($priorStartDateTime !== null && $rawDt < $priorStartDateTime) {
                        $openingBalance -= $amt;
                        continue;
                    }
                    if (($startDt !== null && $rawDt < $startDt) || ($endDt !== null && $rawDt > $endDt)) {
                        continue;
                    }
                    $purchCashPaid += $amt;
                    $dayKey = substr($rawDt, 0, 10);
                    $dailyPurchArr[$dayKey] = ($dailyPurchArr[$dayKey] ?? 0.0) + $amt;
                    $purchTxns->push((object)[
                        'id'             => $po->id,
                        'reference_no'   => $leg['reference_no'] ?? $po->reference_no,
                        'date'           => $rawDt,
                        'type'           => 'purchase',
                        'type_label'     => 'Operating Outflow (Purchase)',
                        'activity'       => 'Operating',
                        'party_name'     => $po->supplier_name ?: 'Supplier',
                        'warehouse_name' => $po->warehouse_name,
                        'payment_method' => $leg['payment_type'] ?? ($po->payment_type ?: 'Cash'),
                        'amount_in'      => 0.0,
                        'amount_out'     => $amt,
                        'created_by'     => $leg['user_name'] ?? $po->created_by ?? $po->user_name ?? 'Super Admin',
                        'user_role'      => $leg['user_role'] ?? $po->user_role ?? 'Super Admin',
                        'status'         => $po->status ?: 'Received',
                        'notes'          => $leg['notes'] ?? $po->notes ?? '',
                    ]);
                }
            } else {
                $rawDt = $accounting->normalizeTransactionDate((string)$po->date, (string)$po->date);
                $amt = (float)$po->paid_amount;
                if ($priorStartDateTime !== null && $rawDt < $priorStartDateTime) {
                    $openingBalance -= $amt;
                    continue;
                }
                if (($startDt !== null && $rawDt < $startDt) || ($endDt !== null && $rawDt > $endDt)) {
                    continue;
                }
                $purchCashPaid += $amt;
                $dayKey = substr($rawDt, 0, 10);
                $dailyPurchArr[$dayKey] = ($dailyPurchArr[$dayKey] ?? 0.0) + $amt;
                $purchTxns->push((object)[
                    'id'             => $po->id,
                    'reference_no'   => $po->reference_no,
                    'date'           => (string)$po->date,
                    'type'           => 'purchase',
                    'type_label'     => 'Operating Outflow (Purchase)',
                    'activity'       => 'Operating',
                    'party_name'     => $po->supplier_name ?: 'Supplier',
                    'warehouse_name' => $po->warehouse_name,
                    'payment_method' => $po->payment_type ?: 'Cash',
                    'amount_in'      => 0.0,
                    'amount_out'     => $amt,
                    'created_by'     => $po->created_by ?? $po->user_name ?? 'Super Admin',
                    'user_role'      => $po->user_role ?? 'Super Admin',
                    'status'         => $po->status ?: 'Received',
                    'notes'          => $po->notes ?? '',
                ]);
            }
        }
        $dailyPurch = collect($dailyPurchArr);

        // Extract exact payment-dated supplier refund inflows (Completed/Received Purchase Returns)
        $prBaseQ = DB::table('purchase_returns')
            ->whereIn('status', ['Completed', 'Received'])
            ->where('paid_amount', '>', 0);
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $prBaseQ->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $prBaseQ->whereIn('warehouse_id', $allowedOutlets);
        }
        $dailySupplierRefundsArr = [];
        foreach ($prBaseQ->select([
            'id', 'reference_no', 'date', 'supplier_name', 'warehouse_name',
            'payment_type', 'paid_amount', 'payments_payload', 'created_by',
            'user_name', 'user_role', 'status', 'notes'
        ])->cursor() as $pr) {
            $legs = !empty($pr->payments_payload) ? json_decode((string)$pr->payments_payload, true) : null;
            if (is_array($legs) && !empty($legs)) {
                foreach ($legs as $leg) {
                    $amt = (float)($leg['amount'] ?? 0);
                    if ($amt <= 0) {
                        continue;
                    }
                    $rawDt = $accounting->normalizeTransactionDate(
                        (string)($leg['payment_date'] ?? $pr->date),
                        (string)$pr->date
                    );
                    if ($priorStartDateTime !== null && $rawDt < $priorStartDateTime) {
                        $openingBalance += $amt;
                        continue;
                    }
                    if (($startDt !== null && $rawDt < $startDt) || ($endDt !== null && $rawDt > $endDt)) {
                        continue;
                    }
                    $supplierRefundsReceived += $amt;
                    $dayKey = substr($rawDt, 0, 10);
                    $dailySupplierRefundsArr[$dayKey] = ($dailySupplierRefundsArr[$dayKey] ?? 0.0) + $amt;
                    $purchRetTxns->push((object)[
                        'id'             => $pr->id,
                        'reference_no'   => $leg['reference_no'] ?? $pr->reference_no,
                        'date'           => $rawDt,
                        'type'           => 'purchase_return',
                        'type_label'     => 'Operating Inflow (Supplier Refund)',
                        'activity'       => 'Operating',
                        'party_name'     => $pr->supplier_name ?: 'Supplier',
                        'warehouse_name' => $pr->warehouse_name,
                        'payment_method' => $leg['payment_type'] ?? ($pr->payment_type ?: 'Cash'),
                        'amount_in'      => $amt,
                        'amount_out'     => 0.0,
                        'created_by'     => $leg['user_name'] ?? $pr->created_by ?? $pr->user_name ?? 'Super Admin',
                        'user_role'      => $leg['user_role'] ?? $pr->user_role ?? 'Super Admin',
                        'status'         => $pr->status ?: 'Completed',
                        'notes'          => $leg['notes'] ?? $pr->notes ?? '',
                    ]);
                }
            } else {
                $rawDt = $accounting->normalizeTransactionDate((string)$pr->date, (string)$pr->date);
                $amt = (float)$pr->paid_amount;
                if ($priorStartDateTime !== null && $rawDt < $priorStartDateTime) {
                    $openingBalance += $amt;
                    continue;
                }
                if (($startDt !== null && $rawDt < $startDt) || ($endDt !== null && $rawDt > $endDt)) {
                    continue;
                }
                $supplierRefundsReceived += $amt;
                $dayKey = substr($rawDt, 0, 10);
                $dailySupplierRefundsArr[$dayKey] = ($dailySupplierRefundsArr[$dayKey] ?? 0.0) + $amt;
                $purchRetTxns->push((object)[
                    'id'             => $pr->id,
                    'reference_no'   => $pr->reference_no,
                    'date'           => (string)$pr->date,
                    'type'           => 'purchase_return',
                    'type_label'     => 'Operating Inflow (Supplier Refund)',
                    'activity'       => 'Operating',
                    'party_name'     => $pr->supplier_name ?: 'Supplier',
                    'warehouse_name' => $pr->warehouse_name,
                    'payment_method' => $pr->payment_type ?: 'Cash',
                    'amount_in'      => $amt,
                    'amount_out'     => 0.0,
                    'created_by'     => $pr->created_by ?? $pr->user_name ?? 'Super Admin',
                    'user_role'      => $pr->user_role ?? 'Super Admin',
                    'status'         => $pr->status ?: 'Completed',
                    'notes'          => $pr->notes ?? '',
                ]);
            }
        }
        $dailySupplierRefunds = collect($dailySupplierRefundsArr);

        $totalInflows = $salesCashReceived + $supplierRefundsReceived;

        // 2. Remaining Cash Outflows: Expenses Actually Paid (chunked 400 to stay SQLite-safe & low RAM) + Sale Return Refunds
        $expBaseQ = DB::table('expenses')
            ->where(function ($q) {
                $q->whereNull('payment_status')
                  ->orWhereNotIn('payment_status', [
                      'Pending', 'pending', 'PENDING',
                      'Draft', 'draft', 'DRAFT',
                      'Voided', 'voided', 'VOIDED',
                  ]);
            });
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $expBaseQ->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $expBaseQ->whereIn('warehouse_id', $allowedOutlets);
        }

        $totalExpenses = 0.0;
        $dailyExpArr   = [];
        $expCatAgg     = [];
        $expTxns       = collect();

        $expBaseQ->select([
            'id', 'reference_no', 'warehouse_name', 'expense_category_name', 'title',
            'amount', 'paid_amount', 'due_amount', 'payment_status', 'payment_method',
            'date', 'details', 'created_by', 'user_name', 'user_role',
        ])->orderBy('id')->chunkById(400, function ($expChunk) use (
            &$totalExpenses, &$dailyExpArr, &$expCatAgg, &$openingBalance, $expTxns, $accounting, $priorStartDateTime, $startDt, $endDt
        ) {
            $expPaymentMap = DB::table('expense_payments')
                ->whereIn('expense_id', $expChunk->pluck('id')->all())
                ->orderBy('id')
                ->get()
                ->groupBy('expense_id');

            foreach ($expChunk as $exp) {
                $catName = $exp->expense_category_name ?: 'General Expenses';
                $legs    = $expPaymentMap->get($exp->id);

                if ($legs && $legs->isNotEmpty()) {
                    foreach ($legs as $ep) {
                        $amt = (float)($ep->amount ?? 0);
                        if ($amt <= 0.0001) {
                            continue;
                        }
                        $rawDt = $accounting->normalizeTransactionDate(
                            (string)($ep->payment_date ?: $exp->date),
                            (string)$exp->date
                        );
                        if ($priorStartDateTime !== null && $rawDt < $priorStartDateTime) {
                            $openingBalance -= $amt;
                            continue;
                        }
                        if (($startDt !== null && $rawDt < $startDt) || ($endDt !== null && $rawDt > $endDt)) {
                            continue;
                        }
                        $totalExpenses += $amt;
                        $dayKey = substr($rawDt, 0, 10);
                        $dailyExpArr[$dayKey] = ($dailyExpArr[$dayKey] ?? 0.0) + $amt;
                        if (!isset($expCatAgg[$catName])) {
                            $expCatAgg[$catName] = ['amount' => 0.0, 'count' => 0];
                        }
                        $expCatAgg[$catName]['amount'] += $amt;
                        $expCatAgg[$catName]['count']++;

                        $expTxns->push((object)[
                            'id'             => $exp->id,
                            'reference_no'   => $ep->reference_no ?: $exp->reference_no,
                            'date'           => $rawDt,
                            'type'           => 'expense',
                            'type_label'     => 'Operating Outflow (Expense)',
                            'activity'       => 'Operating',
                            'party_name'     => $exp->title ?: ($catName ?: 'General Expense'),
                            'warehouse_name' => $exp->warehouse_name,
                            'payment_method' => $ep->payment_type ?: ($exp->payment_method ?: 'Cash'),
                            'amount_in'      => 0.0,
                            'amount_out'     => $amt,
                            'created_by'     => $ep->user_name ?: ($ep->created_by ?: ($exp->created_by ?: ($exp->user_name ?: 'Super Admin'))),
                            'user_role'      => $ep->user_role ?: ($exp->user_role ?: 'Super Admin'),
                            'status'         => $exp->payment_status ?: 'Paid',
                            'notes'          => $ep->notes ?: ($exp->details ?: ''),
                        ]);
                    }
                } else {
                    // Single-record fallback when no payment leg exists (never count Unpaid bills!)
                    $pStat = strtolower(trim((string)($exp->payment_status ?? '')));
                    if ($pStat === 'unpaid') {
                        continue;
                    }
                    $paid = (float)($exp->paid_amount ?? 0);
                    $due  = (float)($exp->due_amount ?? 0);
                    if ($paid > 0.0001) {
                        $effPaid = $paid;
                    } elseif ($due <= 0.0001 && in_array($pStat, ['', 'paid'], true)) {
                        $effPaid = (float)$exp->amount;
                    } else {
                        continue;
                    }
                    if ($effPaid <= 0.0001) {
                        continue;
                    }

                    $rawDt = $accounting->normalizeTransactionDate((string)$exp->date, (string)$exp->date);
                    if ($priorStartDateTime !== null && $rawDt < $priorStartDateTime) {
                        $openingBalance -= $effPaid;
                        continue;
                    }
                    if (($startDt !== null && $rawDt < $startDt) || ($endDt !== null && $rawDt > $endDt)) {
                        continue;
                    }

                    $totalExpenses += $effPaid;
                    $dayKey = substr($rawDt, 0, 10);
                    $dailyExpArr[$dayKey] = ($dailyExpArr[$dayKey] ?? 0.0) + $effPaid;
                    if (!isset($expCatAgg[$catName])) {
                        $expCatAgg[$catName] = ['amount' => 0.0, 'count' => 0];
                    }
                    $expCatAgg[$catName]['amount'] += $effPaid;
                    $expCatAgg[$catName]['count']++;

                    $expTxns->push((object)[
                        'id'             => $exp->id,
                        'reference_no'   => $exp->reference_no,
                        'date'           => (string)$exp->date,
                        'type'           => 'expense',
                        'type_label'     => 'Operating Outflow (Expense)',
                        'activity'       => 'Operating',
                        'party_name'     => $exp->title ?: ($catName ?: 'General Expense'),
                        'warehouse_name' => $exp->warehouse_name,
                        'payment_method' => $exp->payment_method ?: 'Cash',
                        'amount_in'      => 0.0,
                        'amount_out'     => $effPaid,
                        'created_by'     => $exp->created_by ?: ($exp->user_name ?: 'Super Admin'),
                        'user_role'      => $exp->user_role ?: 'Super Admin',
                        'status'         => $exp->payment_status ?: 'Paid',
                        'notes'          => $exp->details ?: '',
                    ]);
                }
            }
        }, 'id');
        $dailyExp = collect($dailyExpArr);

        uasort($expCatAgg, static fn($a, $b) => $b['amount'] <=> $a['amount']);
        $expenseCategories = collect($expCatAgg)->map(function ($row, $cat) {
            return [
                'category' => $cat,
                'amount'   => round((float)$row['amount'], 2),
                'count'    => (int)$row['count'],
            ];
        })->values();

        $retQuery = DB::table('sale_returns');
        $this->applyActiveDocumentFilter($retQuery, 'status');
        if ($startDt !== null) {
            $retQuery->where('date', '>=', $startDt);
        }
        if ($endDt !== null) {
            $retQuery->where('date', '<=', $endDt);
        }
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $retQuery->where('warehouse_name', $warehouse);
        }
        if (!empty($allowedOutlets)) {
            $retQuery->whereIn('warehouse_id', $allowedOutlets);
        }
        $refundsPaid = (float)$retQuery->sum('paid_amount');

        $totalOutflows = $purchCashPaid + $totalExpenses + $refundsPaid;
        $netCashFlow = $totalInflows - $totalOutflows;

        // 3. Daily Timeline (payment-date accurate across Sales, Supplier Refunds, Purchases, Expenses & Sale Returns)
        $dailyInflowsArr = [];
        foreach ((clone $legacyNoPaySalesQ)
            ->selectRaw("SUBSTRING(date, 1, 10) as day_date, SUM(paid_amount) as inflow")
            ->groupBy(DB::raw("SUBSTRING(date, 1, 10)"))
            ->get() as $diRow) {
            $dKey = (string)$diRow->day_date;
            $dailyInflowsArr[$dKey] = ($dailyInflowsArr[$dKey] ?? 0.0) + (float)$diRow->inflow;
        }
        if ($hasSpTable && $spPeriodQ !== null) {
            foreach ((clone $spPeriodQ)
                ->selectRaw("SUBSTRING(COALESCE(sale_payments.payment_date, sales.date), 1, 10) as day_date, SUM(sale_payments.amount) as inflow")
                ->groupBy(DB::raw("SUBSTRING(COALESCE(sale_payments.payment_date, sales.date), 1, 10)"))
                ->get() as $spDayRow) {
                $dKey = (string)$spDayRow->day_date;
                $dailyInflowsArr[$dKey] = ($dailyInflowsArr[$dKey] ?? 0.0) + (float)$spDayRow->inflow;
            }
        }
        $dailyInflows = collect($dailyInflowsArr);

        $dailyRefunds = (clone $retQuery)
            ->selectRaw("SUBSTRING(date, 1, 10) as day_date, SUM(paid_amount) as outflow")
            ->groupBy(DB::raw("SUBSTRING(date, 1, 10)"))
            ->pluck('outflow', 'day_date');

        $allDates = array_unique(array_merge(
            $dailyInflows->keys()->all(),
            $dailySupplierRefunds->keys()->all(),
            $dailyPurch->keys()->all(),
            $dailyExp->keys()->all(),
            $dailyRefunds->keys()->all()
        ));
        sort($allDates);

        $timeline = [];
        $runningBalance = $openingBalance;
        foreach ($allDates as $d) {
            $in = (float)($dailyInflows[$d] ?? 0.0) + (float)($dailySupplierRefunds[$d] ?? 0.0);
            $out = (float)($dailyPurch[$d] ?? 0.0) + (float)($dailyExp[$d] ?? 0.0) + (float)($dailyRefunds[$d] ?? 0.0);
            $net = $in - $out;
            $runningBalance += $net;
            $timeline[] = [
                'date' => $d,
                'inflow' => round($in, 2),
                'outflow' => round($out, 2),
                'net_cash' => round($net, 2),
                'cumulative_cash' => round($runningBalance, 2),
            ];
        }

        // 4. Unified Granular Cash Flow Transactions Journal (IAS 7 Standard Direct Method)
        $legacySalesTxns = (clone $legacyNoPaySalesQ)
            ->select([
                'id',
                'reference_no',
                'date',
                DB::raw("'sale' as type"),
                DB::raw("'Operating Inflow (Sale)' as type_label"),
                DB::raw("'Operating' as activity"),
                DB::raw("COALESCE(customer_name, 'Walk-in Customer') as party_name"),
                'warehouse_name',
                DB::raw("COALESCE(payment_type, 'Cash') as payment_method"),
                'paid_amount as amount_in',
                DB::raw('0 as amount_out'),
                DB::raw("COALESCE(created_by, user_name, 'Super Admin') as created_by"),
                DB::raw("COALESCE(user_role, 'Super Admin') as user_role"),
                DB::raw("COALESCE(status, 'Completed') as status"),
                DB::raw("COALESCE(notes, '') as notes"),
            ])
            ->get();
        $spSalesTxns = ($hasSpTable && $spPeriodQ !== null)
            ? (clone $spPeriodQ)->select([
                'sales.id',
                'sales.reference_no',
                DB::raw('COALESCE(sale_payments.payment_date, sales.date) as date'),
                DB::raw("'sale' as type"),
                DB::raw("'Operating Inflow (Sale)' as type_label"),
                DB::raw("'Operating' as activity"),
                DB::raw("COALESCE(sales.customer_name, 'Walk-in Customer') as party_name"),
                'sales.warehouse_name',
                DB::raw("COALESCE(sale_payments.payment_type, 'Cash') as payment_method"),
                'sale_payments.amount as amount_in',
                DB::raw('0 as amount_out'),
                DB::raw("COALESCE(sale_payments.created_by, sale_payments.user_name, sales.created_by, 'Super Admin') as created_by"),
                DB::raw("COALESCE(sale_payments.user_role, sales.user_role, 'Super Admin') as user_role"),
                DB::raw("COALESCE(sales.status, 'Completed') as status"),
                DB::raw("COALESCE(sale_payments.notes, sales.notes, '') as notes"),
            ])->get()
            : collect();
        $salesTxns = $legacySalesTxns->concat($spSalesTxns);

        $retTxns = (clone $retQuery)
            ->where('paid_amount', '>', 0)
            ->select([
                'id',
                'reference_no',
                'date',
                DB::raw("'sale_return' as type"),
                DB::raw("'Operating Outflow (Refund)' as type_label"),
                DB::raw("'Operating' as activity"),
                DB::raw("COALESCE(customer_name, 'Walk-in Customer') as party_name"),
                'warehouse_name',
                DB::raw("COALESCE(payment_type, 'Cash') as payment_method"),
                DB::raw('0 as amount_in'),
                'paid_amount as amount_out',
                DB::raw("COALESCE(created_by, 'Super Admin') as created_by"),
                DB::raw("'Super Admin' as user_role"),
                DB::raw("COALESCE(status, 'Completed') as status"),
                DB::raw("COALESCE(notes, '') as notes"),
            ])
            ->get();

        $mergedTxns = collect([])
            ->concat($salesTxns)
            ->concat($purchRetTxns)
            ->concat($purchTxns)
            ->concat($expTxns)
            ->concat($retTxns)
            ->sort(function ($a, $b) {
                $dComp = strcmp((string)$a->date, (string)$b->date);
                if ($dComp !== 0) return $dComp;
                return ((int)$a->id) <=> ((int)$b->id);
            })
            ->values();

        $runningTxnBal = $openingBalance;
        $transactions = [];
        foreach ($mergedTxns as $t) {
            $in = (float)($t->amount_in ?? 0);
            $out = (float)($t->amount_out ?? 0);
            $runningTxnBal += ($in - $out);

            $transactions[] = [
                'id' => (int)$t->id,
                'reference_no' => (string)$t->reference_no,
                'date' => (string)$t->date,
                'type' => (string)$t->type,
                'type_label' => (string)$t->type_label,
                'activity' => (string)$t->activity,
                'party_name' => (string)$t->party_name,
                'warehouse_name' => (string)($t->warehouse_name ?: 'Main Branch Outlet'),
                'payment_method' => (string)($t->payment_method ?: 'Cash'),
                'amount_in' => round($in, 2),
                'amount_out' => round($out, 2),
                'running_balance' => round($runningTxnBal, 2),
                'created_by' => (string)($t->created_by ?: 'Super Admin'),
                'user_role' => (string)($t->user_role ?: 'Super Admin'),
                'status' => (string)($t->status ?: 'Completed'),
                'notes' => (string)($t->notes ?: ''),
            ];
        }

        // Reverse for newest first in table
        $transactions = array_reverse($transactions);

        $totalRecords = count($transactions);
        $lastPage = max(1, (int)ceil($totalRecords / max(1, $perPage)));
        $offset = ($page - 1) * $perPage;
        $pagedTransactions = array_values(array_slice($transactions, $offset, $perPage));
        $hasMore = ($offset + count($pagedTransactions)) < $totalRecords;

        $closingBalance = $openingBalance + $netCashFlow;

        $maskedTransactions = $canViewCost
            ? $pagedTransactions
            : array_values(array_map(function ($tx) {
                $txType = strtolower((string)($tx['type'] ?? ''));
                $masked = $tx;
                $masked['running_balance'] = null;
                if (in_array($txType, ['purchase', 'purchase_return'], true)) {
                    $masked['amount_in'] = null;
                    $masked['amount_out'] = null;
                }
                return $masked;
            }, $pagedTransactions));

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'summary' => [
                'cost_masked' => !$canViewCost,
                'opening_balance' => $canViewCost ? round($openingBalance, 2) : 0.0,
                'total_inflow' => $canViewCost ? round($totalInflows, 2) : 0.0,
                'total_outflow' => $canViewCost ? round($totalOutflows, 2) : 0.0,
                'net_cash_flow' => $canViewCost ? round($netCashFlow, 2) : 0.0,
                'sales_cash_received' => round($salesCashReceived, 2),
                'supplier_refunds_received' => $canViewCost ? round($supplierRefundsReceived, 2) : 0.0,
                'purchases_cash_paid' => $canViewCost ? round($purchCashPaid, 2) : 0.0,
                'operating_expenses_paid' => round($totalExpenses, 2),
                'refunds_paid' => round($refundsPaid, 2),
                'closing_balance' => $canViewCost ? round($closingBalance, 2) : 0.0,
                'total_transactions' => $totalRecords,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'warehouse' => $warehouse,
            ],
            'tender_inflows' => $tenderInflows,
            'expense_outflows' => $expenseCategories,
            'timeline' => $canViewCost ? $timeline : array_map(
                fn($row) => $this->maskAssocNumericKeys($row, ['inflow', 'outflow', 'net_cash', 'cumulative_cash'], 0.0),
                $timeline
            ),
            'transactions' => $maskedTransactions,
            'data' => $maskedTransactions,
            'pagination' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $totalRecords,
                'has_more'     => $hasMore,
            ],
        ]);
    }

    /**
     * 17. Stock Movement / Inventory Ledger Report (Bin Card)
     * Opening Stock + Inflows (Purchases, Returns, Transfer In, Adjust In) - Outflows (Sales, Transfer Out, Adjust Out) = Closing Stock
     */
    public function stockLedgerReport(Request $request)
    {
        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse', 'All');
        $startDate = $request->input('start_date') ?? $request->input('from_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date') ?? $request->input('to_date', Carbon::now()->toDateString());
        $movementType = (string)$request->input('movement_type', 'All');
        $category = $request->input('category', 'All');
        $search = trim((string)$request->input('search', ''));
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;

        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $binPage = max(1, (int)($request->input('bin_page') ?? $request->query('bin_page', $page)));

        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $warehouseId = $this->resolveWarehouseIdParam(is_scalar($warehouse) ? (string)$warehouse : null);
        if (!empty($allowedOutlets) && $warehouseId !== null && !in_array($warehouseId, $allowedOutlets, true)) {
            return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
        }

        $fromDateTime = $accounting->normalizeFilterBoundaryDate((string)$startDate, false);
        $toDateTime = $accounting->normalizeFilterBoundaryDate((string)$endDate, true);

        $effAdjTypeSql = $this->effectiveAdjustmentTypeSql();
        $saleBaseQtySql = 'COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity * COALESCE(NULLIF(sale_items.conversion_rate, 0), 1))';
        $purchBaseQtySql = 'COALESCE(NULLIF(purchase_items.base_quantity, 0), purchase_items.quantity * COALESCE(NULLIF(purchase_items.conversion_rate, 0), 1))';
        $adjBaseQtySql = 'COALESCE(NULLIF(stock_adjustment_items.base_quantity, 0), stock_adjustment_items.quantity * COALESCE(NULLIF(stock_adjustment_items.conversion_rate, 0), 1))';
        $trfBaseQtySql = 'COALESCE(NULLIF(stock_transfer_items.base_quantity, 0), stock_transfer_items.quantity * COALESCE(NULLIF(stock_transfer_items.conversion_rate, 0), 1))';

        // Prune UNION ALL branches upfront based on movement_type filter
        $wantAll = ($movementType === '' || $movementType === 'All');
        $wantSale = $wantAll || in_array($movementType, ['sale', 'sales'], true);
        $wantPurchase = $wantAll || in_array($movementType, ['purchase', 'purchases', 'opening_stock'], true);
        $wantAdjust = $wantAll || in_array($movementType, ['adjustment', 'adjustments', 'adjustment_in', 'adjustment_out'], true);
        $wantTransfer = $wantAll || in_array($movementType, ['transfer', 'transfers', 'transfer_in', 'transfer_out'], true);
        $wantSaleReturn = $wantAll || in_array($movementType, ['return', 'returns', 'sale_return', 'sale_returns', 'sale_return_void'], true);
        $wantPurchReturn = $wantAll || in_array($movementType, ['return', 'returns', 'purchase_return', 'purchase_returns', 'purchase_return_void'], true);
        $wantProc = $wantAll || in_array($movementType, ['processing', 'production', 'processing_in', 'processing_out'], true);
        $recognizedType = $wantSale || $wantPurchase || $wantAdjust || $wantTransfer || $wantSaleReturn || $wantPurchReturn || $wantProc;
        if (!$recognizedType) {
            $wantSale = $wantPurchase = $wantAdjust = $wantTransfer = $wantSaleReturn = $wantPurchReturn = $wantProc = true;
        }

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $refIntCast = $isSqlite ? 'CAST(stock_movements.reference_id AS INTEGER)' : 'CAST(stock_movements.reference_id AS UNSIGNED)';
        $prRefFallback = $isSqlite ? "('PR-' || stock_movements.reference_id)" : "CONCAT('PR-', stock_movements.reference_id)";
        $srRefFallback = $isSqlite ? "('SR-' || stock_movements.reference_id)" : "CONCAT('SR-', stock_movements.reference_id)";
        $hasMovementUnitCost = $accounting->schemaHasColumn('stock_movements', 'unit_cost');
        $smUnitCostPrefix = $hasMovementUnitCost ? 'NULLIF(stock_movements.unit_cost, 0), ' : '';

        $branches = [];

        if ($wantSale) {
            $salesMvQuery = DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
                ->where('sales.date', '>=', $fromDateTime)
                ->where('sales.date', '<=', $toDateTime);
            $this->applyActiveDocumentFilter($salesMvQuery, 'sales.status');
            if (!empty($allowedOutlets)) {
                $salesMvQuery->whereIn('sales.warehouse_id', $allowedOutlets);
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $salesMvQuery->where('sales.warehouse_name', $warehouse);
            }
            $branches[] = $salesMvQuery->select([
                'sales.id as source_id',
                'sales.reference_no as reference_no',
                DB::raw("'sale' as movement_type"),
                DB::raw("'Sale Out' as type_label"),
                'sale_items.product_id',
                'sale_items.product_name',
                'sale_items.product_code',
                DB::raw("COALESCE(products.alternative_name, '') as alternative_name"),
                'products.category_name',
                'sales.warehouse_name',
                'sales.warehouse_id',
                DB::raw('0 as quantity_in'),
                DB::raw("{$saleBaseQtySql} as quantity_out"),
                DB::raw('COALESCE(products.stock_quantity, 0) as balance_qty'),
                DB::raw("CASE WHEN {$saleBaseQtySql} > 0 AND COALESCE(sale_items.total_cogs, 0) > 0 THEN sale_items.total_cogs / {$saleBaseQtySql} ELSE COALESCE(products.cost_price, products.purchase_price, 0) END as unit_cost"),
                'sales.created_by',
                'sales.user_name',
                'sales.user_role',
                'sales.date as date',
            ]);
        }

        if ($wantPurchase) {
            $purchUnitCostSql = "COALESCE("
                . "NULLIF((SELECT CASE WHEN SUM(ib.initial_quantity) > 0 THEN SUM(ib.initial_quantity * ib.unit_cost) / SUM(ib.initial_quantity) ELSE MAX(ib.unit_cost) END FROM inventory_batches ib WHERE ib.purchase_item_id = purchase_items.id), 0), "
                . "NULLIF(purchase_items.base_unit_cost, 0), "
                . "CASE WHEN COALESCE(purchase_items.conversion_rate, 1) > 0 THEN purchase_items.unit_cost / purchase_items.conversion_rate ELSE purchase_items.unit_cost END, "
                . "products.cost_price, 0) as unit_cost";

            $purchMvQuery = DB::table('purchase_items')
                ->join('purchases', 'purchase_items.purchase_id', '=', 'purchases.id')
                ->leftJoin('products', 'purchase_items.product_id', '=', 'products.id')
                ->whereIn('purchases.status', ['Received', 'Completed'])
                ->where('purchases.date', '>=', $fromDateTime)
                ->where('purchases.date', '<=', $toDateTime);
            if (!empty($allowedOutlets)) {
                $purchMvQuery->whereIn('purchases.warehouse_id', $allowedOutlets);
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $purchMvQuery->where('purchases.warehouse_name', $warehouse);
            }
            $branches[] = $purchMvQuery->select([
                'purchases.id as source_id',
                'purchases.reference_no as reference_no',
                DB::raw("'purchase' as movement_type"),
                DB::raw("CASE WHEN COALESCE(LOWER(TRIM(purchases.payment_type)), '') = 'opening stock' THEN 'Opening Stock In' ELSE 'Purchase In' END as type_label"),
                'purchase_items.product_id',
                'purchase_items.product_name',
                'purchase_items.product_code',
                DB::raw("COALESCE(products.alternative_name, '') as alternative_name"),
                'products.category_name',
                'purchases.warehouse_name',
                'purchases.warehouse_id',
                DB::raw("{$purchBaseQtySql} as quantity_in"),
                DB::raw('0 as quantity_out'),
                DB::raw('COALESCE(products.stock_quantity, 0) as balance_qty'),
                DB::raw($purchUnitCostSql),
                'purchases.created_by',
                'purchases.user_name',
                'purchases.user_role',
                'purchases.date as date',
            ]);
        }

        if ($wantAdjust) {
            $adjMvQuery = DB::table('stock_adjustment_items')
                ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id')
                ->leftJoin('products', 'stock_adjustment_items.product_id', '=', 'products.id')
                ->where('stock_adjustments.date', '>=', $fromDateTime)
                ->where('stock_adjustments.date', '<=', $toDateTime);
            if (!empty($allowedOutlets)) {
                $adjMvQuery->whereIn('stock_adjustments.warehouse_id', $allowedOutlets);
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $adjMvQuery->where('stock_adjustments.warehouse_name', $warehouse);
            }
            $branches[] = $adjMvQuery->select([
                'stock_adjustments.id as source_id',
                'stock_adjustments.reference_no as reference_no',
                DB::raw("CASE WHEN {$effAdjTypeSql} = 'addition' THEN 'adjustment_in' ELSE 'adjustment_out' END as movement_type"),
                DB::raw("CASE WHEN {$effAdjTypeSql} = 'addition' THEN 'Adjust In' ELSE 'Adjust Out' END as type_label"),
                'stock_adjustment_items.product_id',
                'stock_adjustment_items.product_name',
                'stock_adjustment_items.product_code',
                DB::raw("COALESCE(products.alternative_name, '') as alternative_name"),
                'products.category_name',
                'stock_adjustments.warehouse_name',
                'stock_adjustments.warehouse_id',
                DB::raw("CASE WHEN {$effAdjTypeSql} = 'addition' THEN {$adjBaseQtySql} ELSE 0 END as quantity_in"),
                DB::raw("CASE WHEN {$effAdjTypeSql} = 'addition' THEN 0 ELSE {$adjBaseQtySql} END as quantity_out"),
                DB::raw('COALESCE(products.stock_quantity, 0) as balance_qty'),
                DB::raw('COALESCE(NULLIF(stock_adjustment_items.base_unit_cost, 0), CASE WHEN COALESCE(stock_adjustment_items.conversion_rate, 1) > 0 THEN stock_adjustment_items.unit_cost / stock_adjustment_items.conversion_rate ELSE stock_adjustment_items.unit_cost END, products.cost_price, 0) as unit_cost'),
                'stock_adjustments.created_by',
                'stock_adjustments.user_name',
                'stock_adjustments.user_role',
                'stock_adjustments.date as date',
            ]);
        }

        if ($wantTransfer) {
            $transOutQuery = DB::table('stock_transfer_items')
                ->join('stock_transfers', 'stock_transfer_items.stock_transfer_id', '=', 'stock_transfers.id')
                ->leftJoin('products', 'stock_transfer_items.product_id', '=', 'products.id')
                ->whereIn('stock_transfers.status', ['Sent', 'Completed'])
                ->where('stock_transfers.date', '>=', $fromDateTime)
                ->where('stock_transfers.date', '<=', $toDateTime);
            if (!empty($allowedOutlets)) {
                $transOutQuery->whereIn('stock_transfers.from_warehouse_id', $allowedOutlets);
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $transOutQuery->where('stock_transfers.from_warehouse', $warehouse);
            }
            $branches[] = $transOutQuery->select([
                'stock_transfers.id as source_id',
                'stock_transfers.reference_no as reference_no',
                DB::raw("'transfer' as movement_type"),
                DB::raw("'Transfer Out' as type_label"),
                'stock_transfer_items.product_id',
                'stock_transfer_items.product_name',
                'stock_transfer_items.product_code',
                DB::raw("COALESCE(products.alternative_name, '') as alternative_name"),
                'products.category_name',
                'stock_transfers.from_warehouse as warehouse_name',
                DB::raw('COALESCE(stock_transfers.from_warehouse_id, 0) as warehouse_id'),
                DB::raw('0 as quantity_in'),
                DB::raw("{$trfBaseQtySql} as quantity_out"),
                DB::raw('COALESCE(products.stock_quantity, 0) as balance_qty'),
                DB::raw('COALESCE(NULLIF(stock_transfer_items.base_unit_cost, 0), stock_transfer_items.unit_cost, products.cost_price, 0) as unit_cost'),
                'stock_transfers.created_by',
                'stock_transfers.user_name',
                DB::raw("'' as user_role"),
                'stock_transfers.date as date',
            ]);

            $transInQuery = DB::table('stock_transfer_items')
                ->join('stock_transfers', 'stock_transfer_items.stock_transfer_id', '=', 'stock_transfers.id')
                ->leftJoin('products', 'stock_transfer_items.product_id', '=', 'products.id')
                ->where('stock_transfers.status', 'Completed')
                ->where('stock_transfers.date', '>=', $fromDateTime)
                ->where('stock_transfers.date', '<=', $toDateTime);
            if (!empty($allowedOutlets)) {
                $transInQuery->whereIn('stock_transfers.to_warehouse_id', $allowedOutlets);
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $transInQuery->where('stock_transfers.to_warehouse', $warehouse);
            }
            $branches[] = $transInQuery->select([
                'stock_transfers.id as source_id',
                'stock_transfers.reference_no as reference_no',
                DB::raw("'transfer' as movement_type"),
                DB::raw("'Transfer In' as type_label"),
                'stock_transfer_items.product_id',
                'stock_transfer_items.product_name',
                'stock_transfer_items.product_code',
                DB::raw("COALESCE(products.alternative_name, '') as alternative_name"),
                'products.category_name',
                'stock_transfers.to_warehouse as warehouse_name',
                DB::raw('COALESCE(stock_transfers.to_warehouse_id, 0) as warehouse_id'),
                DB::raw("{$trfBaseQtySql} as quantity_in"),
                DB::raw('0 as quantity_out'),
                DB::raw('COALESCE(products.stock_quantity, 0) as balance_qty'),
                DB::raw('COALESCE(NULLIF(stock_transfer_items.base_unit_cost, 0), stock_transfer_items.unit_cost, products.cost_price, 0) as unit_cost'),
                'stock_transfers.created_by',
                'stock_transfers.user_name',
                DB::raw("'' as user_role"),
                'stock_transfers.date as date',
            ]);
        }

        if ($wantSaleReturn) {
            $srHistUnitCostSql = "COALESCE({$smUnitCostPrefix}"
                . "NULLIF((SELECT SUM(srcl.total_cost) / NULLIF(SUM(srcl.base_quantity), 0) FROM sale_return_cost_layers srcl WHERE srcl.sale_return_id = sale_returns.id AND srcl.product_id = stock_movements.product_id AND (stock_movements.product_variant_id IS NULL OR srcl.product_variant_id = stock_movements.product_variant_id)), 0), "
                . "NULLIF((SELECT SUM(srcl2.total_cost) / NULLIF(SUM(srcl2.base_quantity), 0) FROM sale_return_cost_layers srcl2 WHERE srcl2.sale_return_id = sale_returns.id AND srcl2.product_id = stock_movements.product_id), 0), "
                . "products.cost_price, products.purchase_price, 0) as unit_cost";

            $saleRetMvQuery = DB::table('stock_movements')
                ->leftJoin('sale_returns', function ($join) {
                    $join->on('stock_movements.reference_id', '=', 'sale_returns.id')
                         ->orOn('stock_movements.reference_id', '=', DB::raw('CAST(sale_returns.id AS CHAR)'));
                })
                ->leftJoin('outlets', 'stock_movements.warehouse_id', '=', 'outlets.id')
                ->leftJoin('products', 'stock_movements.product_id', '=', 'products.id')
                ->where('stock_movements.movement_type', 'sale_return')
                ->whereRaw('COALESCE(sale_returns.date, stock_movements.created_at) >= ?', [$fromDateTime])
                ->whereRaw('COALESCE(sale_returns.date, stock_movements.created_at) <= ?', [$toDateTime]);
            if (!empty($allowedOutlets)) {
                $saleRetMvQuery->whereRaw('COALESCE(sale_returns.warehouse_id, stock_movements.warehouse_id) IN (' . implode(',', array_map('intval', $allowedOutlets)) . ')');
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $saleRetMvQuery->whereRaw('COALESCE(sale_returns.warehouse_name, outlets.name) = ?', [$warehouse]);
            }
            $branches[] = $saleRetMvQuery->select([
                DB::raw("COALESCE(sale_returns.id, {$refIntCast}, 0) as source_id"),
                DB::raw("COALESCE(sale_returns.reference_no, {$srRefFallback}) as reference_no"),
                DB::raw("'sale_return' as movement_type"),
                DB::raw("'Return In' as type_label"),
                'stock_movements.product_id',
                'products.name as product_name',
                'products.code as product_code',
                DB::raw("COALESCE(products.alternative_name, '') as alternative_name"),
                'products.category_name',
                DB::raw("COALESCE(sale_returns.warehouse_name, outlets.name, '') as warehouse_name"),
                DB::raw('COALESCE(sale_returns.warehouse_id, stock_movements.warehouse_id, 1) as warehouse_id'),
                'stock_movements.quantity_in as quantity_in',
                DB::raw('0 as quantity_out'),
                DB::raw('COALESCE(products.stock_quantity, 0) as balance_qty'),
                DB::raw($srHistUnitCostSql),
                DB::raw("COALESCE(sale_returns.created_by, 'System') as created_by"),
                DB::raw("COALESCE(sale_returns.user_name, sale_returns.created_by, 'System') as user_name"),
                DB::raw("COALESCE(sale_returns.user_role, '') as user_role"),
                DB::raw('COALESCE(sale_returns.date, stock_movements.created_at) as date'),
            ]);

            $saleRetVoidMvQuery = DB::table('stock_movements')
                ->leftJoin('sale_returns', function ($join) {
                    $join->on('stock_movements.reference_id', '=', 'sale_returns.id')
                         ->orOn('stock_movements.reference_id', '=', DB::raw('CAST(sale_returns.id AS CHAR)'));
                })
                ->leftJoin('outlets', 'stock_movements.warehouse_id', '=', 'outlets.id')
                ->leftJoin('products', 'stock_movements.product_id', '=', 'products.id')
                ->whereIn('stock_movements.movement_type', ['sale_return_void', 'sale_return_edit_reverse'])
                ->whereRaw('COALESCE(sale_returns.date, stock_movements.created_at) >= ?', [$fromDateTime])
                ->whereRaw('COALESCE(sale_returns.date, stock_movements.created_at) <= ?', [$toDateTime]);
            if (!empty($allowedOutlets)) {
                $saleRetVoidMvQuery->whereRaw('COALESCE(sale_returns.warehouse_id, stock_movements.warehouse_id) IN (' . implode(',', array_map('intval', $allowedOutlets)) . ')');
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $saleRetVoidMvQuery->whereRaw('COALESCE(sale_returns.warehouse_name, outlets.name) = ?', [$warehouse]);
            }
            $branches[] = $saleRetVoidMvQuery->select([
                DB::raw("COALESCE(sale_returns.id, {$refIntCast}, 0) as source_id"),
                DB::raw("COALESCE(sale_returns.reference_no, {$srRefFallback}) as reference_no"),
                DB::raw("'sale_return_void' as movement_type"),
                DB::raw("'Return Voided Out' as type_label"),
                'stock_movements.product_id',
                'products.name as product_name',
                'products.code as product_code',
                DB::raw("COALESCE(products.alternative_name, '') as alternative_name"),
                'products.category_name',
                DB::raw("COALESCE(sale_returns.warehouse_name, outlets.name, '') as warehouse_name"),
                DB::raw('COALESCE(sale_returns.warehouse_id, stock_movements.warehouse_id, 1) as warehouse_id'),
                DB::raw('0 as quantity_in'),
                'stock_movements.quantity_out as quantity_out',
                DB::raw('COALESCE(products.stock_quantity, 0) as balance_qty'),
                DB::raw($srHistUnitCostSql),
                DB::raw("COALESCE(sale_returns.created_by, 'System') as created_by"),
                DB::raw("COALESCE(sale_returns.user_name, sale_returns.created_by, 'System') as user_name"),
                DB::raw("COALESCE(sale_returns.user_role, '') as user_role"),
                DB::raw('COALESCE(sale_returns.date, stock_movements.created_at) as date'),
            ]);
        }

        if ($wantPurchReturn) {
            $prHistUnitCostSql = "COALESCE({$smUnitCostPrefix}"
                . "NULLIF((SELECT SUM(prcl.total_cost) / NULLIF(SUM(prcl.base_quantity), 0) FROM purchase_return_cost_layers prcl WHERE prcl.purchase_return_id = purchase_returns.id AND prcl.product_id = stock_movements.product_id AND (stock_movements.product_variant_id IS NULL OR prcl.product_variant_id = stock_movements.product_variant_id)), 0), "
                . "NULLIF((SELECT SUM(prcl2.total_cost) / NULLIF(SUM(prcl2.base_quantity), 0) FROM purchase_return_cost_layers prcl2 WHERE prcl2.purchase_return_id = purchase_returns.id AND prcl2.product_id = stock_movements.product_id), 0), "
                . "NULLIF(CASE WHEN COALESCE(purchase_returns.return_cost_total, 0) > 0 AND (SELECT COALESCE(SUM(sm2.quantity_out), 0) FROM stock_movements sm2 WHERE sm2.movement_type = 'purchase_return' AND sm2.reference_id = stock_movements.reference_id) > 0 THEN purchase_returns.return_cost_total / (SELECT SUM(sm2.quantity_out) FROM stock_movements sm2 WHERE sm2.movement_type = 'purchase_return' AND sm2.reference_id = stock_movements.reference_id) ELSE 0 END, 0), "
                . "products.cost_price, products.purchase_price, 0) as unit_cost";

            $prVoidHistUnitCostSql = "COALESCE({$smUnitCostPrefix}"
                . "NULLIF((SELECT CASE WHEN SUM(ib.initial_quantity) > 0 THEN SUM(ib.initial_quantity * ib.unit_cost) / SUM(ib.initial_quantity) ELSE MAX(ib.unit_cost) END FROM inventory_batches ib WHERE ib.source_type = 'purchase_return_void' AND ib.source_reference = purchase_returns.reference_no AND ib.product_id = stock_movements.product_id AND (stock_movements.product_variant_id IS NULL OR ib.product_variant_id = stock_movements.product_variant_id)), 0), "
                . ($hasMovementUnitCost ? "NULLIF((SELECT smOrig.unit_cost FROM stock_movements smOrig WHERE smOrig.movement_type = 'purchase_return' AND smOrig.reference_id = stock_movements.reference_id AND smOrig.product_id = stock_movements.product_id ORDER BY smOrig.id DESC LIMIT 1), 0), " : "")
                . "NULLIF(CASE WHEN COALESCE(purchase_returns.return_cost_total, 0) > 0 AND (SELECT COALESCE(SUM(sm2.quantity_in), 0) FROM stock_movements sm2 WHERE sm2.movement_type = 'purchase_return_void' AND sm2.reference_id = stock_movements.reference_id) > 0 THEN purchase_returns.return_cost_total / (SELECT SUM(sm2.quantity_in) FROM stock_movements sm2 WHERE sm2.movement_type = 'purchase_return_void' AND sm2.reference_id = stock_movements.reference_id) ELSE 0 END, 0), "
                . "products.cost_price, products.purchase_price, 0) as unit_cost";

            $purchRetMvQuery = DB::table('stock_movements')
                ->leftJoin('purchase_returns', function ($join) {
                    $join->on('stock_movements.reference_id', '=', 'purchase_returns.id')
                         ->orOn('stock_movements.reference_id', '=', DB::raw('CAST(purchase_returns.id AS CHAR)'));
                })
                ->leftJoin('outlets', 'stock_movements.warehouse_id', '=', 'outlets.id')
                ->leftJoin('products', 'stock_movements.product_id', '=', 'products.id')
                ->where('stock_movements.movement_type', 'purchase_return')
                ->whereRaw('COALESCE(purchase_returns.date, stock_movements.created_at) >= ?', [$fromDateTime])
                ->whereRaw('COALESCE(purchase_returns.date, stock_movements.created_at) <= ?', [$toDateTime]);
            if (!empty($allowedOutlets)) {
                $purchRetMvQuery->whereRaw('COALESCE(purchase_returns.warehouse_id, stock_movements.warehouse_id) IN (' . implode(',', array_map('intval', $allowedOutlets)) . ')');
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $purchRetMvQuery->whereRaw('COALESCE(purchase_returns.warehouse_name, outlets.name) = ?', [$warehouse]);
            }
            $branches[] = $purchRetMvQuery->select([
                DB::raw("COALESCE(purchase_returns.id, {$refIntCast}, 0) as source_id"),
                DB::raw("COALESCE(purchase_returns.reference_no, {$prRefFallback}) as reference_no"),
                DB::raw("'purchase_return' as movement_type"),
                DB::raw("'Return Out' as type_label"),
                'stock_movements.product_id',
                'products.name as product_name',
                'products.code as product_code',
                DB::raw("COALESCE(products.alternative_name, '') as alternative_name"),
                'products.category_name',
                DB::raw("COALESCE(purchase_returns.warehouse_name, outlets.name, '') as warehouse_name"),
                DB::raw('COALESCE(purchase_returns.warehouse_id, stock_movements.warehouse_id, 1) as warehouse_id'),
                DB::raw('0 as quantity_in'),
                'stock_movements.quantity_out as quantity_out',
                DB::raw('COALESCE(products.stock_quantity, 0) as balance_qty'),
                DB::raw($prHistUnitCostSql),
                DB::raw("COALESCE(purchase_returns.created_by, '') as created_by"),
                DB::raw("COALESCE(purchase_returns.user_name, purchase_returns.created_by, '') as user_name"),
                DB::raw("COALESCE(purchase_returns.user_role, '') as user_role"),
                DB::raw('COALESCE(purchase_returns.date, stock_movements.created_at) as date'),
            ]);

            $purchRetVoidMvQuery = DB::table('stock_movements')
                ->leftJoin('purchase_returns', function ($join) {
                    $join->on('stock_movements.reference_id', '=', 'purchase_returns.id')
                         ->orOn('stock_movements.reference_id', '=', DB::raw('CAST(purchase_returns.id AS CHAR)'));
                })
                ->leftJoin('outlets', 'stock_movements.warehouse_id', '=', 'outlets.id')
                ->leftJoin('products', 'stock_movements.product_id', '=', 'products.id')
                ->where('stock_movements.movement_type', 'purchase_return_void')
                ->whereRaw('COALESCE(purchase_returns.date, stock_movements.created_at) >= ?', [$fromDateTime])
                ->whereRaw('COALESCE(purchase_returns.date, stock_movements.created_at) <= ?', [$toDateTime]);
            if (!empty($allowedOutlets)) {
                $purchRetVoidMvQuery->whereRaw('COALESCE(purchase_returns.warehouse_id, stock_movements.warehouse_id) IN (' . implode(',', array_map('intval', $allowedOutlets)) . ')');
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $purchRetVoidMvQuery->whereRaw('COALESCE(purchase_returns.warehouse_name, outlets.name) = ?', [$warehouse]);
            }
            $branches[] = $purchRetVoidMvQuery->select([
                DB::raw("COALESCE(purchase_returns.id, {$refIntCast}, 0) as source_id"),
                DB::raw("COALESCE(purchase_returns.reference_no, {$prRefFallback}) as reference_no"),
                DB::raw("'purchase_return_void' as movement_type"),
                DB::raw("'Return Voided In' as type_label"),
                'stock_movements.product_id',
                'products.name as product_name',
                'products.code as product_code',
                DB::raw("COALESCE(products.alternative_name, '') as alternative_name"),
                'products.category_name',
                DB::raw("COALESCE(purchase_returns.warehouse_name, outlets.name, '') as warehouse_name"),
                DB::raw('COALESCE(purchase_returns.warehouse_id, stock_movements.warehouse_id, 1) as warehouse_id'),
                'stock_movements.quantity_in as quantity_in',
                DB::raw('0 as quantity_out'),
                DB::raw('COALESCE(products.stock_quantity, 0) as balance_qty'),
                DB::raw($prVoidHistUnitCostSql),
                DB::raw("COALESCE(purchase_returns.created_by, 'System') as created_by"),
                DB::raw("COALESCE(purchase_returns.user_name, purchase_returns.created_by, 'System') as user_name"),
                DB::raw("COALESCE(purchase_returns.user_role, '') as user_role"),
                DB::raw('COALESCE(purchase_returns.date, stock_movements.created_at) as date'),
            ]);
        }

        if ($wantProc && $accounting->schemaHasTable('processings') && $accounting->schemaHasTable('processing_items')) {
            $procInQuery = DB::table('processings')
                ->leftJoin('products', 'processings.output_product_id', '=', 'products.id')
                ->where('processings.status', 'completed')
                ->where('processings.date', '>=', $fromDateTime)
                ->where('processings.date', '<=', $toDateTime);
            if (!empty($allowedOutlets)) {
                $procInQuery->whereIn('processings.warehouse_id', $allowedOutlets);
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $procInQuery->where('processings.warehouse_name', $warehouse);
            }
            $branches[] = $procInQuery->select([
                'processings.id as source_id',
                'processings.reference_no as reference_no',
                DB::raw("'processing_in' as movement_type"),
                DB::raw("'Production In' as type_label"),
                'processings.output_product_id as product_id',
                'processings.output_product_name as product_name',
                'processings.output_product_code as product_code',
                DB::raw("COALESCE(products.alternative_name, '') as alternative_name"),
                'products.category_name',
                'processings.warehouse_name',
                DB::raw('COALESCE(processings.warehouse_id, 1) as warehouse_id'),
                'processings.output_base_quantity as quantity_in',
                DB::raw('0 as quantity_out'),
                DB::raw('COALESCE(products.stock_quantity, 0) as balance_qty'),
                DB::raw('COALESCE(processings.output_unit_cost, products.cost_price, 0) as unit_cost'),
                'processings.created_by',
                'processings.user_name',
                'processings.user_role',
                'processings.date as date',
            ]);

            $procOutQuery = DB::table('processing_items')
                ->join('processings', 'processing_items.processing_id', '=', 'processings.id')
                ->leftJoin('products', 'processing_items.product_id', '=', 'products.id')
                ->where('processings.status', 'completed')
                ->where('processings.date', '>=', $fromDateTime)
                ->where('processings.date', '<=', $toDateTime);
            if (!empty($allowedOutlets)) {
                $procOutQuery->whereIn('processings.warehouse_id', $allowedOutlets);
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $procOutQuery->where('processings.warehouse_name', $warehouse);
            }
            $branches[] = $procOutQuery->select([
                'processings.id as source_id',
                'processings.reference_no as reference_no',
                DB::raw("'processing_out' as movement_type"),
                DB::raw("'Production Out' as type_label"),
                'processing_items.product_id',
                'processing_items.product_name',
                'processing_items.product_code',
                DB::raw("COALESCE(products.alternative_name, '') as alternative_name"),
                'products.category_name',
                'processings.warehouse_name',
                DB::raw('COALESCE(processings.warehouse_id, 1) as warehouse_id'),
                DB::raw('0 as quantity_in'),
                'processing_items.base_quantity as quantity_out',
                DB::raw('COALESCE(products.stock_quantity, 0) as balance_qty'),
                DB::raw('COALESCE(NULLIF(processing_items.base_unit_cost, 0), processing_items.unit_cost, products.cost_price, 0) as unit_cost'),
                'processings.created_by',
                'processings.user_name',
                'processings.user_role',
                'processings.date as date',
            ]);
        }

        $unionQuery = array_shift($branches);
        foreach ($branches as $branchQ) {
            $unionQuery = $unionQuery->unionAll($branchQ);
        }

        $movementsSub = DB::query()->fromSub($unionQuery, 'mv');

        if (!empty($category) && $category !== 'All') {
            $movementsSub->where('category_name', $category);
        }

        if (!empty($movementType) && $movementType !== 'All') {
            if ($movementType === 'sale' || $movementType === 'sales') {
                $movementsSub->where('movement_type', 'sale');
            } elseif ($movementType === 'purchase' || $movementType === 'purchases') {
                $movementsSub->where('movement_type', 'purchase');
            } elseif ($movementType === 'opening_stock') {
                $movementsSub->where('movement_type', 'purchase')->where('type_label', 'Opening Stock In');
            } elseif ($movementType === 'adjustment' || $movementType === 'adjustments') {
                $movementsSub->whereIn('movement_type', ['adjustment_in', 'adjustment_out']);
            } elseif ($movementType === 'transfer' || $movementType === 'transfers') {
                $movementsSub->whereIn('movement_type', ['transfer', 'transfer_in', 'transfer_out']);
            } elseif ($movementType === 'processing' || $movementType === 'production') {
                $movementsSub->whereIn('movement_type', ['processing_in', 'processing_out']);
            } elseif ($movementType === 'return' || $movementType === 'returns') {
                $movementsSub->whereIn('movement_type', ['sale_return', 'sale_return_void', 'purchase_return', 'purchase_return_void']);
            } elseif ($movementType === 'sale_return' || $movementType === 'sale_returns') {
                $movementsSub->whereIn('movement_type', ['sale_return', 'sale_return_void']);
            } elseif ($movementType === 'purchase_return' || $movementType === 'purchase_returns') {
                $movementsSub->whereIn('movement_type', ['purchase_return', 'purchase_return_void']);
            } else {
                $movementsSub->where('movement_type', $movementType);
            }
        }

        if (!empty($search)) {
            $movementsSub->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('product_name', 'like', "%{$search}%")
                  ->orWhere('product_code', 'like', "%{$search}%")
                  ->orWhere('alternative_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%");
            });
        }

        // Single-query KPI aggregation instead of 4 separate scans of 11-way UNION
        $totalMovements = 0;
        $totalInflowQty = 0.0;
        $totalOutflowQty = 0.0;
        $totalMovementValue = 0.0;
        if (!$skipSummary) {
            $mvSummary = (clone $movementsSub)->selectRaw('
                COUNT(*) as total_movements,
                COALESCE(SUM(quantity_in), 0) as total_inflow_qty,
                COALESCE(SUM(quantity_out), 0) as total_outflow_qty,
                COALESCE(SUM((quantity_in + quantity_out) * unit_cost), 0) as total_movement_value
            ')->first();
            $totalMovements = (int)($mvSummary->total_movements ?? 0);
            $totalInflowQty = (float)($mvSummary->total_inflow_qty ?? 0);
            $totalOutflowQty = (float)($mvSummary->total_outflow_qty ?? 0);
            $totalMovementValue = (float)($mvSummary->total_movement_value ?? 0);
        } elseif ($knownTotal !== null) {
            $totalMovements = $knownTotal;
        } else {
            $totalMovements = (int)(clone $movementsSub)->count();
        }
        $netMovementQty = round($totalInflowQty - $totalOutflowQty, 2);

        $lastPage = max(1, (int)ceil($totalMovements / max(1, $perPage)));
        $offset = ($page - 1) * $perPage;

        $rawMvRows = (clone $movementsSub)
            ->orderByDesc('date')
            ->offset($offset)
            ->limit($perPage + 1)
            ->get();
        $hasMoreMovements = $rawMvRows->count() > $perPage;
        $pageMvRows = $hasMoreMovements ? $rawMvRows->slice(0, $perPage)->values() : $rawMvRows->values();

        // Paginated Section 2: Product-Level Bin Card Summary (50-ID scoped, single movement pass)
        $prodQuery = DB::table('products')->whereNull('deleted_at')->select([
            'id', 'code', 'name', 'alternative_name', 'category_name', 'unit_name', 'cost_price', 'selling_price', 'stock_quantity'
        ]);
        if ($category !== 'All' && !empty($category)) {
            $prodQuery->where('category_name', $category);
        }
        if (!empty($search)) {
            $prodQuery->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('alternative_name', 'like', "%{$search}%");
            });
        }

        $totalProductsCount = !$skipSummary
            ? (int)(clone $prodQuery)->count()
            : 0;
        $binOffset = ($binPage - 1) * $perPage;
        $rawProducts = $prodQuery->orderBy('id')->offset($binOffset)->limit($perPage + 1)->get();
        $hasMoreProducts = $rawProducts->count() > $perPage;
        $products = $hasMoreProducts ? $rawProducts->slice(0, $perPage)->values() : $rawProducts->values();
        $pageProductIds = $products->pluck('id')->map(fn($v) => (int)$v)->all();

        // Scope warehouse stock balances strictly to product IDs present on this page (movements + bin cards)
        $neededProdIds = array_values(array_unique(array_merge(
            $pageProductIds,
            $pageMvRows->pluck('product_id')->filter()->map(fn($v) => (int)$v)->all()
        )));
        $warehouseBalances = [];
        if (!empty($neededProdIds) && ($warehouseId !== null || !empty($allowedOutlets))) {
            foreach (array_chunk($neededProdIds, 400) as $idChunk) {
                $wbQuery = DB::table('stock_balances')->whereIn('product_id', $idChunk);
                if ($warehouseId !== null) {
                    $wbQuery->where('warehouse_id', $warehouseId);
                } else {
                    $wbQuery->whereIn('warehouse_id', $allowedOutlets);
                }
                foreach ($wbQuery->select('product_id', DB::raw('SUM(quantity) as qty'))
                    ->groupBy('product_id')
                    ->pluck('qty', 'product_id') as $pid => $qty) {
                    $warehouseBalances[(int)$pid] = (float)$qty;
                }
            }
        }

        $movementsData = $pageMvRows->map(function ($row, $idx) use ($offset, $canViewCost, $warehouseId, $allowedOutlets, $warehouseBalances) {
            $qtyIn = (float)$row->quantity_in;
            $qtyOut = (float)$row->quantity_out;
            $unitCost = (float)$row->unit_cost;
            $qty = $qtyIn > 0 ? $qtyIn : $qtyOut;
            $val = round($qty * $unitCost, 2);
            $userName = !empty($row->user_name) ? (string)$row->user_name : (!empty($row->created_by) ? (string)$row->created_by : 'Super Admin');
            $userRole = !empty($row->user_role) ? (string)$row->user_role : 'Super Admin';
            $balanceQty = ($warehouseId !== null || !empty($allowedOutlets))
                ? (float)($warehouseBalances[(int)($row->product_id ?? 0)] ?? 0.0)
                : (float)$row->balance_qty;
            return [
                'id' => (int)($row->source_id ?? ($offset + $idx + 1)),
                'reference_no' => (string)($row->reference_no ?? 'REF-' . ($offset + $idx + 1)),
                'movement_type' => (string)$row->movement_type,
                'type_label' => (string)$row->type_label,
                'product_id' => (int)($row->product_id ?? 0),
                'product_name' => (string)($row->product_name ?? ''),
                'product_code' => (string)($row->product_code ?? ''),
                'alternative_name' => (string)($row->alternative_name ?? ''),
                'category_name' => (string)($row->category_name ?? 'General'),
                'warehouse_name' => (string)($row->warehouse_name ?? 'Main Outlet'),
                'warehouse_id' => (int)($row->warehouse_id ?? 0),
                'quantity_in' => $qtyIn,
                'quantity_out' => $qtyOut,
                'balance_qty' => $balanceQty,
                'unit_cost' => $canViewCost ? $unitCost : 0.0,
                'total_value' => $canViewCost ? $val : 0.0,
                'created_by' => $userName,
                'user_name' => $userName,
                'user_role' => $userRole,
                'date' => (string)$row->date,
            ];
        });

        $items = [];
        $totalOpeningCost = 0.0;
        $totalClosingCost = 0.0;
        $totalPurchasesIn = 0.0;
        $totalSalesOut = 0.0;

        if (!empty($pageProductIds)) {
            $fifoByProduct = collect();
            foreach (array_chunk($pageProductIds, 400) as $idChunk) {
                $fifoBatchQuery = DB::table('inventory_batches')
                    ->where('status', 'active')
                    ->where('remaining_quantity', '>', 0)
                    ->whereIn('product_id', $idChunk);
                if ($warehouseId !== null) {
                    $fifoBatchQuery->where('warehouse_id', $warehouseId);
                } elseif (!empty($allowedOutlets)) {
                    $fifoBatchQuery->whereIn('warehouse_id', $allowedOutlets);
                }
                foreach ($fifoBatchQuery
                    ->select('product_id', DB::raw('SUM(remaining_quantity) as batch_qty'), DB::raw('SUM(remaining_quantity * unit_cost) as batch_value'))
                    ->groupBy('product_id')
                    ->get() as $fb) {
                    $fifoByProduct->put($fb->product_id, $fb);
                }
            }

            // Single 9-query window pass for BOTH period & post-cutoff movements scoped to pageProductIds
            $combinedMovs = $this->aggregateBinCardMovementsByProduct($fromDateTime, $toDateTime, (string)$warehouse, true, $allowedOutlets, $pageProductIds, true);
            $periodMovs = $combinedMovs['period'];
            $postMovs = $combinedMovs['post'];

            $productsNeedingPreBatches = [];
            foreach ($pageProductIds as $pid) {
                $postIn = (float)($postMovs['purchases'][$pid] ?? 0)
                    + (float)($postMovs['sale_returns'][$pid] ?? 0)
                    + (float)($postMovs['transfers_in'][$pid] ?? 0)
                    + (float)($postMovs['processing_in'][$pid] ?? 0)
                    + (float)($postMovs['adjust_in'][$pid] ?? 0);
                $postOut = (float)($postMovs['sales'][$pid] ?? 0)
                    + (float)($postMovs['purchase_returns'][$pid] ?? 0)
                    + (float)($postMovs['transfers_out'][$pid] ?? 0)
                    + (float)($postMovs['processing_out'][$pid] ?? 0)
                    + (float)($postMovs['adjust_out'][$pid] ?? 0);
                if ($postIn > 0.0001 || $postOut > 0.0001) {
                    $productsNeedingPreBatches[] = $pid;
                }
            }

            $preCutoffBatchesByProduct = collect();
            if (!empty($productsNeedingPreBatches)) {
                foreach (array_chunk($productsNeedingPreBatches, 400) as $idChunk) {
                    $preCutoffBatchesQuery = DB::table('inventory_batches')
                        ->whereIn('status', ['active', 'exhausted'])
                        ->whereIn('product_id', $idChunk)
                        ->where('initial_quantity', '>', 0)
                        ->whereRaw('COALESCE(received_date, created_at) <= ?', [$toDateTime]);
                    if ($warehouseId !== null) {
                        $preCutoffBatchesQuery->where('warehouse_id', $warehouseId);
                    } elseif (!empty($allowedOutlets)) {
                        $preCutoffBatchesQuery->whereIn('warehouse_id', $allowedOutlets);
                    }
                    foreach ($preCutoffBatchesQuery
                        ->select(['product_id', 'initial_quantity', 'unit_cost'])
                        ->orderByDesc(DB::raw('COALESCE(received_date, created_at)'))
                        ->orderByDesc('id')
                        ->get() as $pb) {
                        $existing = $preCutoffBatchesByProduct->get($pb->product_id, collect());
                        $existing->push($pb);
                        $preCutoffBatchesByProduct->put($pb->product_id, $existing);
                    }
                }
            }

            foreach ($products as $p) {
                $pId = (int)$p->id;
                $liveStock = ($warehouseId !== null || !empty($allowedOutlets))
                    ? (float)($warehouseBalances[$pId] ?? 0.0)
                    : (float)($p->stock_quantity ?? 0.0);
                $cost = (float)($p->cost_price ?? 0);
                $selling = (float)($p->selling_price ?? 0);

                $purchQty = (float)($periodMovs['purchases'][$pId] ?? 0);
                $saleRetQty = (float)($periodMovs['sale_returns'][$pId] ?? 0);
                $trfInQty = (float)($periodMovs['transfers_in'][$pId] ?? 0);
                $procInQty = (float)($periodMovs['processing_in'][$pId] ?? 0);
                $adjustIn = (float)($periodMovs['adjust_in'][$pId] ?? 0);

                $soldQty = (float)($periodMovs['sales'][$pId] ?? 0);
                $purchRetQty = (float)($periodMovs['purchase_returns'][$pId] ?? 0);
                $trfOutQty = (float)($periodMovs['transfers_out'][$pId] ?? 0);
                $procOutQty = (float)($periodMovs['processing_out'][$pId] ?? 0);
                $adjustOut = (float)($periodMovs['adjust_out'][$pId] ?? 0);

                $totalInflow = $purchQty + $saleRetQty + $trfInQty + $procInQty + $adjustIn;
                $totalOutflow = $soldQty + $purchRetQty + $trfOutQty + $procOutQty + $adjustOut;
                $netMv = $totalInflow - $totalOutflow;

                $postInflow = (float)($postMovs['purchases'][$pId] ?? 0)
                    + (float)($postMovs['sale_returns'][$pId] ?? 0)
                    + (float)($postMovs['transfers_in'][$pId] ?? 0)
                    + (float)($postMovs['processing_in'][$pId] ?? 0)
                    + (float)($postMovs['adjust_in'][$pId] ?? 0);
                $postOutflow = (float)($postMovs['sales'][$pId] ?? 0)
                    + (float)($postMovs['purchase_returns'][$pId] ?? 0)
                    + (float)($postMovs['transfers_out'][$pId] ?? 0)
                    + (float)($postMovs['processing_out'][$pId] ?? 0)
                    + (float)($postMovs['adjust_out'][$pId] ?? 0);
                $postNet = $postInflow - $postOutflow;

                $closingStock = max(0.0, round($liveStock - $postNet, 4));
                $openingStock = max(0.0, round($closingStock - $netMv, 4));

                $closingCostVal = $closingStock * $cost;
                $fifoRow = $fifoByProduct->get($pId);
                if ($fifoRow && (float)$fifoRow->batch_qty > 0.0001 && abs($postInflow) <= 0.0001 && abs($postOutflow) <= 0.0001) {
                    $bQty = (float)$fifoRow->batch_qty;
                    $bVal = (float)$fifoRow->batch_value;
                    if ($bQty >= $closingStock - 0.01) {
                        $closingCostVal = abs($bQty - $closingStock) <= 0.01
                            ? $bVal
                            : ($closingStock * ($bVal / $bQty));
                    } else {
                        $closingCostVal = $bVal + (($closingStock - $bQty) * $cost);
                    }
                } elseif (($postInflow > 0.0001 || $postOutflow > 0.0001) && $closingStock > 0.0001) {
                    $preBatches = $preCutoffBatchesByProduct->get($pId);
                    if ($preBatches && $preBatches->isNotEmpty()) {
                        $remToValue = $closingStock;
                        $histVal = 0.0;
                        $lastHistoricalUnitCost = (float)$preBatches->first()->unit_cost;
                        foreach ($preBatches as $pb) {
                            if ($remToValue <= 0.0001) {
                                break;
                            }
                            $bInit = max(0.0, (float)$pb->initial_quantity);
                            if ($bInit <= 0.0001) {
                                continue;
                            }
                            $take = min($remToValue, $bInit);
                            $histVal += ($take * (float)$pb->unit_cost);
                            $remToValue -= $take;
                            $lastHistoricalUnitCost = (float)$pb->unit_cost;
                        }
                        if ($remToValue > 0.0001) {
                            $histVal += ($remToValue * ($lastHistoricalUnitCost > 0 ? $lastHistoricalUnitCost : $cost));
                        }
                        $closingCostVal = $histVal;
                    }
                }

                $totalOpeningCost += ($openingStock * $cost);
                $totalClosingCost += $closingCostVal;
                $totalPurchasesIn += $purchQty;
                $totalSalesOut += $soldQty;

                $items[] = [
                    'product_id' => $pId,
                    'code' => $p->code,
                    'name' => $p->name,
                    'alternative_name' => (string)($p->alternative_name ?? ''),
                    'category_name' => $p->category_name ?? 'General',
                    'unit_name' => $p->unit_name ?? 'Pcs',
                    'cost_price' => $canViewCost ? round($cost, 2) : 0.0,
                    'selling_price' => round($selling, 2),
                    'opening_stock' => round($openingStock, 2),
                    'purchases_in' => round($purchQty, 2),
                    'sale_returns_in' => round($saleRetQty, 2),
                    'transfers_in' => round($trfInQty, 2),
                    'processing_in' => round($procInQty, 2),
                    'adjustments_in' => round($adjustIn, 2),
                    'total_inflow' => round($totalInflow, 2),
                    'sales_out' => round($soldQty, 2),
                    'purchase_returns_out' => round($purchRetQty, 2),
                    'transfers_out' => round($trfOutQty, 2),
                    'processing_out' => round($procOutQty, 2),
                    'adjustments_out' => round($adjustOut, 2),
                    'total_outflow' => round($totalOutflow, 2),
                    'net_movement' => round($netMv, 2),
                    'closing_stock' => round($closingStock, 2),
                    'closing_cost_value' => $canViewCost ? round($closingCostVal, 2) : 0.0,
                    'closing_retail_value' => round($closingStock * $selling, 2),
                ];
            }
        }

        $binLastPage = max(1, (int)ceil(($totalProductsCount ?: count($items)) / max(1, $perPage)));

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'summary' => [
                'total_movements' => $totalMovements,
                'total_inflow_qty' => round($totalInflowQty, 2),
                'total_outflow_qty' => round($totalOutflowQty, 2),
                'net_movement_qty' => $netMovementQty,
                'total_movement_value' => $canViewCost ? round($totalMovementValue, 2) : 0.0,
                'total_products' => $totalProductsCount ?: count($items),
                'total_opening_cost' => $canViewCost ? round($totalOpeningCost, 2) : 0.0,
                'total_closing_cost' => $canViewCost ? round($totalClosingCost, 2) : 0.0,
                'total_purchases_in' => round($totalPurchasesIn, 2),
                'total_sales_out' => round($totalSalesOut, 2),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'warehouse' => $warehouse,
                'movement_type' => $movementType,
                'cost_masked' => !$canViewCost,
            ],
            'movements' => $movementsData,
            'data' => $items,
            'pagination' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $totalMovements,
                'has_more'     => $hasMoreMovements,
            ],
            'bin_pagination' => [
                'current_page' => $binPage,
                'last_page'    => $binLastPage,
                'per_page'     => $perPage,
                'total'        => $totalProductsCount ?: count($items),
                'has_more'     => $hasMoreProducts,
            ],
        ]);
    }

    /**
     * 18. Product Profit Margin & Contribution Report
     * Ranks products by Gross Margin %, FIFO COGS, and contribution to company gross profit
     */
    public function productMarginsReport(Request $request)
    {
        $accounting = $this->accounting();
        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse', 'All');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $category = $request->input('category', 'All');
        $search = trim((string)$request->input('search', ''));

        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        $cogsExpr = 'COALESCE(
            NULLIF(sale_items.total_cogs, 0),
            NULLIF(sale_items.base_unit_cost, 0) * COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity, 1),
            COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity, 1) * COALESCE(NULLIF(product_variants.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)
        )';
        $hasPointsDiscountCol = $accounting->schemaHasColumn('sales', 'points_discount');
        $orderDiscSql = $hasPointsDiscountCol
            ? '(COALESCE(sales.discount, 0) + COALESCE(sales.points_discount, 0))'
            : 'COALESCE(sales.discount, 0)';
        $netLineRevExpr = "(COALESCE(sale_items.subtotal, 0) - COALESCE(sale_items.tax, 0) - (CASE WHEN COALESCE(sales.subtotal, 0) > 0 THEN {$orderDiscSql} * (COALESCE(sale_items.subtotal, 0) / sales.subtotal) ELSE 0 END))";

        $query = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
            ->leftJoin('product_variants', 'sale_items.product_variant_id', '=', 'product_variants.id');
        $this->applyActiveDocumentFilter($query, 'sales.status');
        if (!empty($allowedOutlets)) {
            $query->whereIn('sales.warehouse_id', $allowedOutlets);
        }

        $this->applyReportDateRange($query, $startDate, $endDate, 'sales.date');

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $query->where(function ($q) use ($warehouse) {
                if (is_numeric($warehouse)) {
                    $q->where('sales.warehouse_id', (int)$warehouse);
                } else {
                    $q->where('sales.warehouse_name', $warehouse);
                }
            });
        }

        if ($category !== 'All' && !empty($category)) {
            $query->where('products.category_name', $category);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('sale_items.product_name', 'like', "%{$search}%")
                  ->orWhere('sale_items.product_code', 'like', "%{$search}%")
                  ->orWhere('products.name', 'like', "%{$search}%")
                  ->orWhere('products.code', 'like', "%{$search}%")
                  ->orWhere('products.alternative_name', 'like', "%{$search}%");
            });
        }

        $rawItems = $query->selectRaw("
            sale_items.product_id,
            COALESCE(products.code, sale_items.product_code, 'N/A') as code,
            COALESCE(products.name, sale_items.product_name, 'Unnamed Product') as name,
            COALESCE(products.alternative_name, '') as alternative_name,
            COALESCE(products.category_name, 'General') as category_name,
            SUM(sale_items.quantity) as units_sold,
            SUM({$netLineRevExpr}) as gross_revenue,
            SUM({$cogsExpr}) as total_cogs
        ")
        ->groupBy(
            'sale_items.product_id',
            DB::raw("COALESCE(products.code, sale_items.product_code, 'N/A')"),
            DB::raw("COALESCE(products.name, sale_items.product_name, 'Unnamed Product')"),
            DB::raw("COALESCE(products.alternative_name, '')"),
            DB::raw("COALESCE(products.category_name, 'General')")
        )
        ->get();

        // Deduct active completed Sale Returns (returned qty, net revenue, and restored COGS) per product
        $retByProd = [];
        $retCogsByProd = [];
        $retIds = [];
        $retQ = DB::table('sale_returns');
        $this->applyActiveDocumentFilter($retQ, 'status');
        if (!empty($allowedOutlets)) {
            $retQ->whereIn('warehouse_id', $allowedOutlets);
        }
        $this->applyReportDateRange($retQ, $startDate, $endDate, 'date');
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $retQ->where(function ($q) use ($warehouse) {
                if (is_numeric($warehouse)) {
                    $q->where('warehouse_id', (int)$warehouse);
                } else {
                    $q->where('warehouse_name', $warehouse);
                }
            });
        }
        foreach ($retQ->select(['id', 'subtotal', 'discount', 'items_payload'])->cursor() as $rRow) {
            $retIds[] = (int)$rRow->id;
            $itemsArr = !empty($rRow->items_payload) ? json_decode((string)$rRow->items_payload, true) : null;
            if (!is_array($itemsArr)) {
                continue;
            }
            $rSub  = (float)($rRow->subtotal ?? 0);
            $rDisc = (float)($rRow->discount ?? 0);
            foreach ($itemsArr as $ri) {
                $pid = isset($ri['product_id']) ? (int)$ri['product_id'] : 0;
                if ($pid <= 0) {
                    continue;
                }
                $qty  = (float)($ri['quantity'] ?? 0);
                $sub  = (float)($ri['subtotal'] ?? ($qty * (float)($ri['unit_price'] ?? 0)));
                $lTax = (float)($ri['tax'] ?? 0);
                $discShare = $rSub > 0.0001 ? ($rDisc * ($sub / $rSub)) : 0.0;
                $netRetRev = max(0.0, $sub - $lTax - $discShare);
                if (!isset($retByProd[$pid])) {
                    $retByProd[$pid] = ['qty' => 0.0, 'rev' => 0.0];
                }
                $retByProd[$pid]['qty'] += $qty;
                $retByProd[$pid]['rev'] += $netRetRev;
            }
        }
        if (!empty($retIds) && $accounting->schemaHasTable('sale_return_cost_layers')) {
            foreach (array_chunk($retIds, 400) as $idChunk) {
                $srclRows = DB::table('sale_return_cost_layers')
                    ->whereIn('sale_return_id', $idChunk)
                    ->select('product_id', DB::raw('SUM(total_cost) as ret_cogs'))
                    ->groupBy('product_id')
                    ->get();
                foreach ($srclRows as $srcl) {
                    $retCogsByProd[(int)$srcl->product_id] = ($retCogsByProd[(int)$srcl->product_id] ?? 0.0) + (float)$srcl->ret_cogs;
                }
            }
        }

        $totalRevenue = 0.0;
        $totalCogs = 0.0;
        $totalProfit = 0.0;
        $totalUnits = 0.0;

        $adjustedRaw = [];
        $seenMarginProdIds = [];
        foreach ($rawItems as $it) {
            $pid = (int)($it->product_id ?? 0);
            if ($pid > 0) {
                $seenMarginProdIds[$pid] = true;
            }
            $retInfo = $pid > 0 ? ($retByProd[$pid] ?? ['qty' => 0.0, 'rev' => 0.0]) : ['qty' => 0.0, 'rev' => 0.0];
            $retCogs = $pid > 0 ? (float)($retCogsByProd[$pid] ?? 0.0) : 0.0;

            $rawSoldUnits = (float)$it->units_sold;
            $rawGrossRev  = (float)$it->gross_revenue;
            $rawCogs      = (float)$it->total_cogs;

            // Signed cross-period return attribution (matches P&L without artificial min() ceiling)
            $dedQty  = (float)$retInfo['qty'];
            $dedRev  = (float)$retInfo['rev'];
            $dedCogs = $retCogs;

            $uSold = round($rawSoldUnits - $dedQty, 4);
            $rev = round($rawGrossRev - $dedRev, 2);
            $cg = round($rawCogs - $dedCogs, 2);

            $totalRevenue += $rev;
            $totalCogs += $cg;
            $totalProfit += ($rev - $cg);
            $totalUnits += $uSold;

            $adjustedRaw[] = (object)[
                'product_id'       => $it->product_id,
                'code'             => $it->code,
                'name'             => $it->name,
                'alternative_name' => $it->alternative_name,
                'category_name'    => $it->category_name,
                'units_sold'       => $uSold,
                'gross_revenue'    => $rev,
                'total_cogs'       => $cg,
            ];
        }

        // Surface products that experienced returns in this period despite zero gross sales
        $retOnlyIds = array_values(array_diff(array_keys($retByProd), array_keys($seenMarginProdIds)));
        if (!empty($retOnlyIds)) {
            $retProducts = DB::table('products')->whereIn('id', $retOnlyIds)
                ->get(['id', 'code', 'name', 'alternative_name', 'category_name'])
                ->keyBy('id');
            foreach ($retOnlyIds as $rPid) {
                $pObj = $retProducts->get($rPid);
                if (!empty($search) && $pObj) {
                    $sq = strtolower($search);
                    if (!str_contains(strtolower((string)$pObj->name), $sq)
                        && !str_contains(strtolower((string)$pObj->code), $sq)
                        && !str_contains(strtolower((string)($pObj->alternative_name ?? '')), $sq)) {
                        continue;
                    }
                }
                if ($category !== 'All' && !empty($category) && $pObj && (string)$pObj->category_name !== (string)$category) {
                    continue;
                }
                $rInfo = $retByProd[$rPid];
                $rCg = round((float)($retCogsByProd[$rPid] ?? 0.0), 2);
                $uSold = -round((float)$rInfo['qty'], 4);
                $rev = -round((float)$rInfo['rev'], 2);
                $cg = -$rCg;
                $totalRevenue += $rev;
                $totalCogs += $cg;
                $totalProfit += ($rev - $cg);
                $totalUnits += $uSold;
                $adjustedRaw[] = (object)[
                    'product_id'       => $rPid,
                    'code'             => $pObj->code ?? 'N/A',
                    'name'             => $pObj->name ?? 'Returned Product',
                    'alternative_name' => $pObj->alternative_name ?? '',
                    'category_name'    => $pObj->category_name ?? 'General',
                    'units_sold'       => $uSold,
                    'gross_revenue'    => $rev,
                    'total_cogs'       => $cg,
                ];
            }
        }

        $items = [];
        foreach ($adjustedRaw as $it) {
            $rev = (float)$it->gross_revenue;
            $cg = (float)$it->total_cogs;
            $uSold = (float)$it->units_sold;
            $gp = $rev - $cg;
            $marginPct = $rev > 0 ? round(($gp / $rev) * 100, 2) : 0.0;
            $markupPct = $cg > 0 ? round(($gp / $cg) * 100, 2) : 0.0;
            $contribPct = $totalProfit > 0 ? round(($gp / $totalProfit) * 100, 2) : 0.0;
            $avgPrice = $uSold > 0 ? round($rev / $uSold, 2) : 0.0;
            $avgCost = $uSold > 0 ? round($cg / $uSold, 2) : 0.0;

            $items[] = [
                'product_id' => $it->product_id,
                'code' => $it->code,
                'name' => $it->name,
                'alternative_name' => (string)($it->alternative_name ?? ''),
                'category_name' => $it->category_name,
                'units_sold' => $uSold,
                'avg_selling_price' => $avgPrice,
                'avg_cost_price' => $avgCost,
                'gross_revenue' => round($rev, 2),
                'cogs' => round($cg, 2),
                'gross_profit' => round($gp, 2),
                'gross_margin_pct' => $marginPct,
                'markup_pct' => $markupPct,
                'contribution_pct' => $contribPct,
            ];
        }

        usort($items, function ($a, $b) use ($canViewCost) {
            return $canViewCost
                ? ($b['gross_profit'] <=> $a['gross_profit'])
                : ($b['gross_revenue'] <=> $a['gross_revenue']);
        });

        $overallMarginPct = $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 2) : 0.0;
        $totalProducts = count($items);
        $lastPage = max(1, (int)ceil($totalProducts / max(1, $perPage)));
        $offset = ($page - 1) * $perPage;
        $pagedItems = array_slice($items, $offset, $perPage);
        $hasMore = ($offset + count($pagedItems)) < $totalProducts;
        if (!$canViewCost) {
            $pagedItems = array_map(function ($row) {
                $masked = $this->maskAssocNumericKeys(
                    $row,
                    ['cogs', 'gross_profit', 'gross_margin_pct', 'markup_pct', 'contribution_pct'],
                    0.0
                );
                $masked['avg_cost_price'] = null;
                $masked['cost_masked'] = true;
                return $masked;
            }, $pagedItems);
        }

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'summary' => [
                'cost_masked' => !$canViewCost,
                'total_products' => $totalProducts,
                'total_units_sold' => round($totalUnits, 2),
                'total_revenue' => round($totalRevenue, 2),
                'total_cogs' => $canViewCost ? round($totalCogs, 2) : 0.0,
                'total_gross_profit' => $canViewCost ? round($totalProfit, 2) : 0.0,
                'overall_margin_pct' => $canViewCost ? $overallMarginPct : 0.0,
                'start_date' => $startDate ?? '',
                'end_date' => $endDate ?? '',
                'warehouse' => $warehouse,
                'category' => $category,
            ],
            'data' => $pagedItems,
            'pagination' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $totalProducts,
                'has_more'     => $hasMore,
            ],
        ]);
    }

    /**
     * 19. Dead Stock & Slow-Moving Inventory Report
     * Identifies products with stock on hand that have zero sales over X days (30, 60, 90, 180+)
     */
    public function deadStockReport(Request $request)
    {
        $accounting = $this->accounting();
        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse', 'All');
        $daysInactive = (int)$request->input('days', 60);
        if ($daysInactive <= 0) $daysInactive = 60;
        $category = $request->input('category', 'All');
        $search = trim((string)$request->input('search', ''));
        $velocityStatusFilter = $request->input('velocity_status', 'All'); // 'All', 'dead', 'slow'

        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        // Resolve outlet/warehouse ID if specified
        $warehouseId = null;
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $matchedOutlet = DB::table('outlets')
                ->where('name', $warehouse)
                ->orWhere('id', $warehouse)
                ->first();
            if ($matchedOutlet) {
                $warehouseId = $matchedOutlet->id;
                $warehouse = $matchedOutlet->name;
            }
        }
        if (!empty($allowedOutlets) && $warehouseId !== null && !in_array((int)$warehouseId, $allowedOutlets, true)) {
            return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
        }

        // Get last sale date per product (completed active sales only; Draft/Pending/Voided must NOT reset idle timer)
        $saleQuery = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->select('sale_items.product_id', DB::raw('MAX(sales.date) as last_sale_date'));
        $this->applyActiveDocumentFilter($saleQuery, 'sales.status');
        if (!empty($allowedOutlets)) {
            $saleQuery->whereIn('sales.warehouse_id', $allowedOutlets);
        }

        if ($warehouseId !== null) {
            $saleQuery->where(function ($q) use ($warehouseId, $warehouse) {
                $q->where('sales.warehouse_id', $warehouseId)
                  ->orWhere('sales.warehouse_name', $warehouse);
            });
        }

        $lastSales = $saleQuery->groupBy('sale_items.product_id')
            ->pluck('last_sale_date', 'product_id');

        $variantAvgSub = DB::table('product_variants')
            ->selectRaw('product_id, AVG(CASE WHEN cost_price > 0 THEN cost_price ELSE NULL END) as avg_cost, AVG(CASE WHEN selling_price > 0 THEN selling_price ELSE NULL END) as avg_sell')
            ->groupBy('product_id');

        $prodQuery = DB::table('products')
            ->leftJoinSub($variantAvgSub, 'pv_avg', 'pv_avg.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at');

        $selectFields = [
            'products.id',
            'products.code',
            'products.sku',
            'products.barcode',
            'products.name',
            'products.alternative_name',
            'products.category_name',
            'products.brand_name',
            'products.unit_name',
            DB::raw('COALESCE(NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), pv_avg.avg_cost, 0) as cost_price'),
            DB::raw('COALESCE(NULLIF(products.selling_price, 0), NULLIF(products.retail_price, 0), pv_avg.avg_sell, 0) as selling_price'),
            'products.created_at',
        ];

        if ($warehouseId !== null || !empty($allowedOutlets)) {
            // Group stock_balances per product to aggregate all variants and avoid duplicate product rows
            $warehouseStockSub = DB::table('stock_balances')
                ->when($warehouseId !== null, fn($q) => $q->where('warehouse_id', $warehouseId))
                ->when($warehouseId === null && !empty($allowedOutlets), fn($q) => $q->whereIn('warehouse_id', $allowedOutlets))
                ->select('product_id', DB::raw('SUM(quantity) as stock_qty'))
                ->groupBy('product_id')
                ->having('stock_qty', '>', 0);

            $prodQuery->joinSub($warehouseStockSub, 'wb', function ($join) {
                $join->on('products.id', '=', 'wb.product_id');
            });
            $selectFields[] = 'wb.stock_qty as stock_quantity';
        } else {
            $prodQuery->where('products.stock_quantity', '>', 0);
            $selectFields[] = 'products.stock_quantity';
        }

        if ($category !== 'All' && !empty($category)) {
            $prodQuery->where('products.category_name', $category);
        }

        if (!empty($search)) {
            $prodQuery->where(function ($q) use ($search) {
                $q->where('products.code', 'like', "%{$search}%")
                  ->orWhere('products.sku', 'like', "%{$search}%")
                  ->orWhere('products.barcode', 'like', "%{$search}%")
                  ->orWhere('products.name', 'like', "%{$search}%")
                  ->orWhere('products.alternative_name', 'like', "%{$search}%")
                  ->orWhere('products.brand_name', 'like', "%{$search}%")
                  ->orWhere('products.category_name', 'like', "%{$search}%");
            });
        }

        $products = $prodQuery->select($selectFields)->get();

        $items = [];
        $totalTiedUpCapital = 0.0;
        $totalDeadUnits = 0.0;
        $slowMovingCapital = 0.0;
        $deadStockCapital = 0.0;
        $totalPotentialRevenue = 0.0;
        $nowTs = time();
        $nowDate = date('Y-m-d');

        foreach ($products as $p) {
            $pId = $p->id;
            $lastSaleRaw = $lastSales[$pId] ?? null;

            $daysSince = 999;
            $lastSaleFormatted = 'Never Sold';

            if ($lastSaleRaw) {
                $lastDateStr = substr($lastSaleRaw, 0, 10);
                $lastSaleFormatted = $lastDateStr;
                $lastTs = strtotime($lastDateStr);
                $daysSince = $lastTs ? (int) max(0, floor(($nowTs - $lastTs) / 86400)) : 999;
            } else {
                $createDateStr = substr($p->created_at ?: $nowDate, 0, 10);
                $createTs = strtotime($createDateStr);
                $daysSince = $createTs ? (int) max(0, floor(($nowTs - $createTs) / 86400)) : 999;
            }

            if ($daysSince >= $daysInactive) {
                $qty = (float)($p->stock_quantity ?? 0);
                $cost = (float)($p->cost_price ?? 0);
                $retail = (float)($p->selling_price ?? 0);
                $capital = $qty * $cost;
                $revenue = $qty * $retail;

                $isDead = ($daysSince >= 90);
                $velocityStatus = $isDead ? 'dead' : 'slow';

                // Check velocity status filter
                if ($velocityStatusFilter === 'dead' && !$isDead) {
                    continue;
                }
                if ($velocityStatusFilter === 'slow' && $isDead) {
                    continue;
                }

                $totalTiedUpCapital += $capital;
                $totalDeadUnits += $qty;
                $totalPotentialRevenue += $revenue;

                if ($isDead) {
                    $deadStockCapital += $capital;
                } else {
                    $slowMovingCapital += $capital;
                }

                // Dynamic strategic recommendations based on idle duration & sales velocity
                $action = 'Promotional Discount (15% Off)';
                if ($daysSince >= 180 || ($lastSaleRaw === null && $daysSince >= 120)) {
                    $action = 'Clearance / Liquidation (50% Off)';
                } elseif ($daysSince >= 90) {
                    $action = 'Bundle Promo / Markdown (30% Off)';
                } elseif ($daysSince < 60) {
                    $action = 'Shelf Reposition / Transfer';
                }

                $items[] = [
                    'product_id' => $pId,
                    'code' => $p->code ?? '',
                    'sku' => $p->sku ?: ($p->code ?? ''),
                    'barcode' => $p->barcode ?? '',
                    'name' => $p->name ?? '',
                    'alternative_name' => (string)($p->alternative_name ?? ''),
                    'category_name' => $p->category_name ?? 'General',
                    'brand_name' => $p->brand_name ?? '',
                    'warehouse_name' => $warehouse !== 'All' ? $warehouse : 'All Outlets & Warehouses',
                    'unit_name' => $p->unit_name ?? 'Pcs',
                    'stock_quantity' => round($qty, 2),
                    'cost_price' => round($cost, 2),
                    'selling_price' => round($retail, 2),
                    'tied_up_capital' => round($capital, 2),
                    'potential_revenue' => round($revenue, 2),
                    'days_inactive' => $daysSince,
                    'last_sale_date' => $lastSaleFormatted,
                    'velocity_status' => $velocityStatus,
                    'recommended_action' => $action,
                ];
            }
        }

        usort($items, function ($a, $b) use ($canViewCost) {
            return $canViewCost
                ? ($b['tied_up_capital'] <=> $a['tied_up_capital'])
                : ($b['potential_revenue'] <=> $a['potential_revenue']);
        });

        $totalDeadProducts = count($items);
        $lastPage = max(1, (int)ceil($totalDeadProducts / max(1, $perPage)));
        $offset = ($page - 1) * $perPage;
        $pagedItems = array_slice($items, $offset, $perPage);
        $hasMore = ($offset + count($pagedItems)) < $totalDeadProducts;
        if (!$canViewCost) {
            $pagedItems = array_map(function ($row) {
                $masked = $this->maskAssocNumericKeys($row, ['cost_price', 'tied_up_capital'], null);
                $masked['cost_masked'] = true;
                return $masked;
            }, $pagedItems);
        }

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'summary' => [
                'cost_masked' => !$canViewCost,
                'total_dead_products' => $totalDeadProducts,
                'total_dead_units' => round($totalDeadUnits, 2),
                'total_tied_up_capital' => $canViewCost ? round($totalTiedUpCapital, 2) : 0.0,
                'slow_moving_capital' => $canViewCost ? round($slowMovingCapital, 2) : 0.0,
                'dead_stock_capital' => $canViewCost ? round($deadStockCapital, 2) : 0.0,
                'total_potential_revenue' => round($totalPotentialRevenue, 2),
                'threshold_days' => $daysInactive,
                'as_of_date' => Carbon::today()->toDateString(),
                'warehouse' => $warehouse,
            ],
            'data' => $pagedItems,
            'pagination' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $totalDeadProducts,
                'has_more'     => $hasMore,
            ],
        ]);
    }

    /**
     * 20. Cashier Performance, Discount & Fraud Audit Report
     * Tracks cashier sales, bill counts, discount given, average ticket size, and drawer discrepancy
     */
    public function cashierAuditReport(Request $request)
    {
        $accounting = $this->accounting();
        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse', 'All');
        $startDate = $request->input('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $request->input('end_date') ?? Carbon::now()->toDateString();
        $search = trim((string)$request->input('search', ''));
        $riskFilter = $request->input('audit_risk', 'All');

        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);

        $allowedOutlets = $this->extractAllowedOutletIds($request);

        $warehouseId = null;
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $matchedOutlet = DB::table('outlets')
                ->where('name', $warehouse)
                ->orWhere('id', $warehouse)
                ->first();
            if ($matchedOutlet) {
                $warehouseId = $matchedOutlet->id;
                $warehouse = $matchedOutlet->name;
            }
        }
        if (!empty($allowedOutlets) && $warehouseId !== null && !in_array((int)$warehouseId, $allowedOutlets, true)) {
            return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
        }

        $query = DB::table('sales');
        $this->applyReportDateRange($query, $startDate, $endDate, 'date');
        $this->applyActiveDocumentFilter($query, 'sales.status');
        if (!empty($allowedOutlets)) {
            $query->whereIn('sales.warehouse_id', $allowedOutlets);
        }

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $query->where(function ($q) use ($warehouseId, $warehouse) {
                if ($warehouseId !== null) {
                    $q->where('sales.warehouse_id', $warehouseId)
                      ->orWhere('sales.warehouse_name', $warehouse);
                } else {
                    $q->where('sales.warehouse_name', $warehouse);
                }
            });
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%");
            });
        }

        $saleCashCond = "(LOWER(TRIM(COALESCE(payment_type, ''))) LIKE '%cash%' AND LOWER(TRIM(COALESCE(payment_type, ''))) NOT LIKE '%non%cash%' AND LOWER(TRIM(COALESCE(payment_type, ''))) NOT LIKE '%cashless%')";
        $cashiers = $query->selectRaw("
            COALESCE(user_name, created_by, 'Unknown Staff') as cashier_name,
            COALESCE(user_role, 'Cashier') as role,
            COALESCE(warehouse_name, 'Main Branch Outlet') as warehouse_name,
            COUNT(id) as total_bills,
            SUM(grand_total) as net_revenue,
            SUM(paid_amount) as total_collected,
            SUM(due_amount) as total_due,
            SUM(discount) as total_discount,
            SUM(tax_amount) as total_tax,
            SUM(subtotal) as total_subtotal,
            SUM(CASE WHEN {$saleCashCond} THEN paid_amount ELSE 0 END) as fallback_cash,
            SUM(CASE WHEN NOT {$saleCashCond} THEN paid_amount ELSE 0 END) as fallback_non_cash
        ")
        ->groupBy(
            DB::raw("COALESCE(user_name, created_by, 'Unknown Staff')"),
            DB::raw("COALESCE(user_role, 'Cashier')"),
            DB::raw("COALESCE(warehouse_name, 'Main Branch Outlet')")
        )
        ->get();

        // Accurate multi-tender split from sale_payments filtered by payment_date with fallback to sales.payment_type
        $tenderQuery = DB::table('sales')
            ->leftJoin('sale_payments', 'sales.id', '=', 'sale_payments.sale_id');
        if (!empty($startDate)) {
            $tenderQuery->whereRaw('COALESCE(sale_payments.payment_date, sales.date) >= ?', [$accounting->normalizeFilterBoundaryDate((string)$startDate, false)]);
        }
        if (!empty($endDate)) {
            $tenderQuery->whereRaw('COALESCE(sale_payments.payment_date, sales.date) <= ?', [$accounting->normalizeFilterBoundaryDate((string)$endDate, true)]);
        }
        $this->applyActiveDocumentFilter($tenderQuery, 'sales.status');
        if (!empty($allowedOutlets)) {
            $tenderQuery->whereIn('sales.warehouse_id', $allowedOutlets);
        }

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $tenderQuery->where(function ($q) use ($warehouseId, $warehouse) {
                if ($warehouseId !== null) {
                    $q->where('sales.warehouse_id', $warehouseId)
                      ->orWhere('sales.warehouse_name', $warehouse);
                } else {
                    $q->where('sales.warehouse_name', $warehouse);
                }
            });
        }

        $payLegCashCond = "(LOWER(TRIM(COALESCE(sale_payments.payment_type, ''))) LIKE '%cash%' AND LOWER(TRIM(COALESCE(sale_payments.payment_type, ''))) NOT LIKE '%non%cash%' AND LOWER(TRIM(COALESCE(sale_payments.payment_type, ''))) NOT LIKE '%cashless%')";
        $hdrCashCond = "(LOWER(TRIM(COALESCE(sales.payment_type, ''))) LIKE '%cash%' AND LOWER(TRIM(COALESCE(sales.payment_type, ''))) NOT LIKE '%non%cash%' AND LOWER(TRIM(COALESCE(sales.payment_type, ''))) NOT LIKE '%cashless%')";
        $tenderMap = $tenderQuery->selectRaw("
            COALESCE(sales.user_name, sales.created_by, 'Unknown Staff') as cashier_name,
            COALESCE(sales.warehouse_name, 'Main Branch Outlet') as warehouse_name,
            SUM(CASE 
                WHEN sale_payments.id IS NOT NULL THEN (CASE WHEN {$payLegCashCond} THEN sale_payments.amount ELSE 0 END)
                ELSE (CASE WHEN {$hdrCashCond} THEN sales.paid_amount ELSE 0 END)
            END) as cash_collected,
            SUM(CASE 
                WHEN sale_payments.id IS NOT NULL THEN (CASE WHEN NOT {$payLegCashCond} THEN sale_payments.amount ELSE 0 END)
                ELSE (CASE WHEN NOT {$hdrCashCond} THEN sales.paid_amount ELSE 0 END)
            END) as non_cash_collected
        ")
        ->groupBy(
            DB::raw("COALESCE(sales.user_name, sales.created_by, 'Unknown Staff')"),
            DB::raw("COALESCE(sales.warehouse_name, 'Main Branch Outlet')")
        )
        ->get()
        ->keyBy(function ($r) {
            return $r->cashier_name . '|' . $r->warehouse_name;
        });

        // Batch completed Sale Returns & open-shift cash refunds per cashier & branch in single pass
        $retByCashier = [];
        $openCashRefundsByKey = [];
        if ($accounting->schemaHasTable('sale_returns')) {
            $retAuditQ = DB::table('sale_returns');
            $this->applyReportDateRange($retAuditQ, $startDate, $endDate, 'date');
            $this->applyActiveDocumentFilter($retAuditQ, 'status');
            if (!empty($allowedOutlets)) {
                $retAuditQ->whereIn('warehouse_id', $allowedOutlets);
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $retAuditQ->where(function ($q) use ($warehouseId, $warehouse) {
                    if ($warehouseId !== null) {
                        $q->where('warehouse_id', $warehouseId)->orWhere('warehouse_name', $warehouse);
                    } else {
                        $q->where('warehouse_name', $warehouse);
                    }
                });
            }
            foreach ($retAuditQ->select(['user_name', 'created_by', 'warehouse_name', 'grand_total', 'paid_amount', 'refund_tender', 'payment_type', 'payments_payload'])->get() as $sr) {
                $whLabel = $sr->warehouse_name ?: 'Main Branch Outlet';
                $primaryName = $sr->user_name ?: ($sr->created_by ?: 'Unknown Staff');
                $rKey = $primaryName . '|' . $whLabel;
                if (!isset($retByCashier[$rKey])) {
                    $retByCashier[$rKey] = ['total' => 0.0, 'refunded' => 0.0, 'cash_refunded' => 0.0, 'non_cash_refunded' => 0.0];
                }
                $retByCashier[$rKey]['total'] += (float)$sr->grand_total;
                $retByCashier[$rKey]['refunded'] += (float)$sr->paid_amount;

                $legs = !empty($sr->payments_payload) ? json_decode((string)$sr->payments_payload, true) : null;
                $cashPart = 0.0;
                $nonCashPart = 0.0;
                if (is_array($legs) && !empty($legs)) {
                    foreach ($legs as $leg) {
                        $lAmt = max(0.0, (float)($leg['amount'] ?? 0));
                        if ($lAmt <= 0.0001) continue;
                        $ptRaw = (string)($leg['payment_type'] ?? ($sr->refund_tender ?: ($sr->payment_type ?: 'cash')));
                        if ($accounting->isCashPaymentType($ptRaw)) {
                            $cashPart += $lAmt;
                        } else {
                            $nonCashPart += $lAmt;
                        }
                    }
                } elseif ((float)$sr->paid_amount > 0) {
                    $tenderRaw = (string)($sr->refund_tender ?: ($sr->payment_type ?: 'cash'));
                    if ($accounting->isCashPaymentType($tenderRaw)) {
                        $cashPart += (float)$sr->paid_amount;
                    } else {
                        $nonCashPart += (float)$sr->paid_amount;
                    }
                }
                $retByCashier[$rKey]['cash_refunded'] += $cashPart;
                $retByCashier[$rKey]['non_cash_refunded'] += $nonCashPart;

                if ($cashPart > 0) {
                    $openCashRefundsByKey[$rKey] = ($openCashRefundsByKey[$rKey] ?? 0.0) + $cashPart;
                    if (!empty($sr->user_name) && !empty($sr->created_by) && $sr->user_name !== $sr->created_by) {
                        $altKey = $sr->created_by . '|' . $whLabel;
                        $openCashRefundsByKey[$altKey] = ($openCashRefundsByKey[$altKey] ?? 0.0) + $cashPart;
                    }
                }
            }
        }

        // Aggregate register sessions across the period (plus batch open-register drawers — zero N+1)
        $hasCashRegisters = $accounting->schemaHasTable('cash_registers');
        $regAgg = collect();
        $openRegIdsByKey = [];
        $allOpenRegIds = [];
        if ($hasCashRegisters) {
            $regFromDt = $accounting->normalizeFilterBoundaryDate((string)$startDate, false);
            $regToDt = $accounting->normalizeFilterBoundaryDate((string)$endDate, true);
            $regBaseQ = DB::table('cash_registers')
                ->when(!empty($allowedOutlets), fn($q) => $q->whereIn('warehouse_id', $allowedOutlets));
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $regBaseQ->where(function ($q) use ($warehouseId, $warehouse) {
                    if ($warehouseId !== null) {
                        $q->where('warehouse_id', $warehouseId)->orWhere('warehouse_name', $warehouse);
                    } else {
                        $q->where('warehouse_name', $warehouse);
                    }
                });
            }

            $regAgg = (clone $regBaseQ)
                ->where(function ($q) use ($startDate, $endDate, $regFromDt, $regToDt) {
                    if (!empty($startDate) && !empty($endDate)) {
                        $q->whereBetween('opened_at', [$regFromDt, $regToDt])
                          ->orWhereBetween('closed_at', [$regFromDt, $regToDt]);
                    }
                })
                ->selectRaw("
                    COALESCE(user_name, 'Unknown Staff') as cashier_name,
                    COALESCE(warehouse_name, 'Main Branch Outlet') as warehouse_name,
                    COUNT(id) as total_shifts,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_shifts,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_shifts,
                    SUM(opening_cash) as total_opening_cash,
                    SUM(CASE WHEN status = 'closed' THEN expected_cash ELSE 0 END) as total_expected_cash,
                    SUM(CASE WHEN status = 'closed' THEN closing_cash ELSE 0 END) as total_closing_cash,
                    SUM(CASE WHEN status = 'closed' THEN cash_difference ELSE 0 END) as total_cash_difference,
                    MAX(register_name) as last_register_name
                ")
                ->groupBy('user_name', 'warehouse_name')
                ->get()
                ->keyBy(function ($r) {
                    return $r->cashier_name . '|' . $r->warehouse_name;
                });

            foreach ((clone $regBaseQ)->whereRaw('LOWER(status) IN (?, ?)', ['open', 'opened'])->select(['id', 'user_name', 'warehouse_name'])->get() as $or) {
                $rid = (int)$or->id;
                $oKey = ($or->user_name ?: 'Unknown Staff') . '|' . ($or->warehouse_name ?: 'Main Branch Outlet');
                $openRegIdsByKey[$oKey][] = $rid;
                $allOpenRegIds[] = $rid;
            }
        }

        $openRegTxByReg = [];
        $openRegExpByReg = [];
        if (!empty($allOpenRegIds)) {
            if ($accounting->schemaHasTable('cash_register_transactions')) {
                foreach (array_chunk($allOpenRegIds, 400) as $idChunk) {
                    foreach (DB::table('cash_register_transactions')
                        ->whereIn('cash_register_id', $idChunk)
                        ->selectRaw("
                            cash_register_id,
                            COALESCE(SUM(CASE WHEN type = 'float_in' THEN amount ELSE 0 END), 0) as fi,
                            COALESCE(SUM(CASE WHEN type IN ('drop', 'safe_drop', 'void_refund') THEN amount ELSE 0 END), 0) as dr
                        ")
                        ->groupBy('cash_register_id')
                        ->get() as $txAgg) {
                        $openRegTxByReg[(int)$txAgg->cash_register_id] = [
                            'fi' => (float)($txAgg->fi ?? 0.0),
                            'dr' => (float)($txAgg->dr ?? 0.0),
                        ];
                    }
                }
            }
            if ($accounting->schemaHasTable('expense_payments') && $accounting->schemaHasColumn('expense_payments', 'cash_register_id')) {
                foreach (array_chunk($allOpenRegIds, 400) as $idChunk) {
                    foreach (DB::table('expense_payments')
                        ->whereIn('cash_register_id', $idChunk)
                        ->whereRaw("(LOWER(TRIM(COALESCE(payment_type, ''))) LIKE '%cash%' AND LOWER(TRIM(COALESCE(payment_type, ''))) NOT LIKE '%non%cash%' AND LOWER(TRIM(COALESCE(payment_type, ''))) NOT LIKE '%cashless%')")
                        ->selectRaw('cash_register_id, COALESCE(SUM(amount), 0) as amt')
                        ->groupBy('cash_register_id')
                        ->pluck('amt', 'cash_register_id') as $rid => $amt) {
                        $openRegExpByReg[(int)$rid] = (float)$amt;
                    }
                }
            }
        }

        $items = [];
        $totalStoreSales = 0.0;
        $totalStoreBills = 0;
        $totalStoreDiscounts = 0.0;
        $totalStoreCollected = 0.0;
        $totalStoreDue = 0.0;
        $totalStoreCash = 0.0;
        $totalStoreNonCash = 0.0;
        $totalDiscrepancy = 0.0;

        $idx = 1;
        foreach ($cashiers as $c) {
            $bills = (int)$c->total_bills;
            $key = $c->cashier_name . '|' . $c->warehouse_name;
            $cRet = $retByCashier[$key] ?? ['total' => 0.0, 'refunded' => 0.0, 'cash_refunded' => 0.0, 'non_cash_refunded' => 0.0];
            $grossBilled = (float)$c->net_revenue;
            $netRevenue = round(max(0.0, $grossBilled - $cRet['total']), 2);
            $discount = (float)$c->total_discount;
            $grossSales = $grossBilled + $discount;
            $collected = round(max(0.0, (float)$c->total_collected - $cRet['refunded']), 2);
            $due = (float)$c->total_due;
            $avgTicket = $bills > 0 ? round($grossSales / $bills, 2) : 0.0;
            $discountRate = $grossSales > 0 ? round(($discount / $grossSales) * 100, 1) : 0.0;

            $tender = $tenderMap->get($key);
            $grossCash = $tender ? (float)$tender->cash_collected : (float)$c->fallback_cash;
            $grossNonCash = $tender ? (float)$tender->non_cash_collected : (float)$c->fallback_non_cash;
            $cash = round(max(0.0, $grossCash - (float)$cRet['cash_refunded']), 2);
            $nonCash = round(max(0.0, $grossNonCash - (float)$cRet['non_cash_refunded']), 2);

            $reg = $regAgg->get($key);
            if ($reg && (int)$reg->closed_shifts > 0) {
                $expectedCash = (float)$reg->total_expected_cash;
                $closingCash = (float)$reg->total_closing_cash;
                $diff = (float)$reg->total_cash_difference;
                $shiftLabel = (int)$reg->closed_shifts === 1 ? '1 shift' : $reg->closed_shifts . ' shifts';
                $regName = ($reg->last_register_name ?? 'Register 01') . ' (' . $shiftLabel . ')';
            } elseif ($reg) {
                $openRegIds = $openRegIdsByKey[$key] ?? [];
                $floatsIn = 0.0;
                $cashDrops = 0.0;
                $openCashExpenses = 0.0;
                foreach ($openRegIds as $orid) {
                    $floatsIn += (float)($openRegTxByReg[$orid]['fi'] ?? 0.0);
                    $cashDrops += (float)($openRegTxByReg[$orid]['dr'] ?? 0.0);
                    $openCashExpenses += (float)($openRegExpByReg[$orid] ?? 0.0);
                }
                $openCashRefunds = (float)($openCashRefundsByKey[$key] ?? 0.0);
                $expectedCash = round((float)$reg->total_opening_cash + $grossCash + $floatsIn - $cashDrops - $openCashRefunds - $openCashExpenses, 2);
                $closingCash = $expectedCash;
                $diff = 0.0;
                $regName = ($reg->last_register_name ?? 'Register 01') . ' (Open)';
            } else {
                $expectedCash = round($cash, 2);
                $closingCash = round($cash, 2);
                $diff = 0.0;
                $regName = 'Register 01';
            }

            // Audit risk assessment
            $risk = 'healthy';
            if ($diff < -100) {
                $risk = 'critical';
            } elseif ($discountRate > 10.0 || abs($diff) > 0.01) {
                $risk = 'watch';
            }

            if ($riskFilter !== 'All' && $risk !== $riskFilter) {
                continue;
            }

            $totalStoreSales += $grossSales;
            $totalStoreBills += $bills;
            $totalStoreDiscounts += $discount;
            $totalStoreCollected += $collected;
            $totalStoreDue += $due;
            $totalStoreCash += $cash;
            $totalStoreNonCash += $nonCash;
            $totalDiscrepancy += $diff;

            $items[] = [
                'staff_code' => 'STF-' . str_pad($idx++, 3, '0', STR_PAD_LEFT),
                'cashier_name' => $c->cashier_name,
                'role' => $c->role,
                'warehouse_name' => $c->warehouse_name,
                'register_name' => $regName,
                'total_bills' => $bills,
                'total_sales' => round($grossSales, 2),
                'total_collected' => round($collected, 2),
                'total_due' => round($due, 2),
                'total_discount' => round($discount, 2),
                'net_revenue' => round($netRevenue, 2),
                'discount_rate' => $discountRate,
                'average_bill_value' => $avgTicket,
                'cash_collected' => round($cash, 2),
                'non_cash_collected' => round($nonCash, 2),
                'expected_cash' => round($expectedCash, 2),
                'counted_cash' => round($closingCash, 2),
                'cash_difference' => round($diff, 2),
                'audit_risk_status' => $risk,
                'transactions' => [],
            ];
        }

        usort($items, function ($a, $b) {
            return $b['total_sales'] <=> $a['total_sales'];
        });

        $totalCashiers = count($items);
        $lastPage = max(1, (int)ceil($totalCashiers / max(1, $perPage)));
        $offset = ($page - 1) * $perPage;
        $pagedItems = array_slice($items, $offset, $perPage);
        $hasMore = ($offset + count($pagedItems)) < $totalCashiers;

        // Batched recent transactions (up to 15) strictly for the sliced page cashiers (single query, zero N+1)
        if (!empty($pagedItems)) {
            $pagedKeyIdx = [];
            $pagedNames = [];
            $pagedWhNames = [];
            foreach ($pagedItems as $pIdx => $pItem) {
                $cKey = $pItem['cashier_name'] . '|' . $pItem['warehouse_name'];
                $pagedKeyIdx[$cKey][] = $pIdx;
                $pagedNames[$pItem['cashier_name']] = true;
                $pagedWhNames[$pItem['warehouse_name']] = true;
            }
            $nameList = array_keys($pagedNames);
            $txsQuery = DB::table('sales');
            $this->applyReportDateRange($txsQuery, $startDate, $endDate, 'date');
            $this->applyActiveDocumentFilter($txsQuery, 'sales.status');
            if (!empty($allowedOutlets)) {
                $txsQuery->whereIn('sales.warehouse_id', $allowedOutlets);
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $txsQuery->where(function ($q) use ($warehouseId, $warehouse) {
                    if ($warehouseId !== null) {
                        $q->where('sales.warehouse_id', $warehouseId)->orWhere('sales.warehouse_name', $warehouse);
                    } else {
                        $q->where('sales.warehouse_name', $warehouse);
                    }
                });
            }
            $txsQuery->where(function ($q) use ($nameList) {
                $q->whereIn('user_name', $nameList)->orWhereIn('created_by', $nameList);
                foreach ($nameList as $cName) {
                    $q->orWhere('created_by', 'like', $cName . ' (%');
                }
            });

            $remainingBuckets = count($pagedKeyIdx);
            foreach ($txsQuery
                ->orderBy('date', 'desc')
                ->orderBy('id', 'desc')
                ->select(['id', 'reference_no', 'date', 'customer_name', 'payment_type', 'grand_total', 'paid_amount', 'due_amount', 'discount', 'user_name', 'created_by', 'warehouse_name'])
                ->cursor() as $t) {
                $tWh = (string)($t->warehouse_name ?: 'Main Branch Outlet');
                $tStaff = (string)($t->user_name ?: ($t->created_by ?: 'Unknown Staff'));
                $matchedKeys = [];
                if (isset($pagedKeyIdx[$tStaff . '|' . $tWh])) {
                    $matchedKeys[] = $tStaff . '|' . $tWh;
                } elseif (!empty($t->created_by)) {
                    $cbPrefix = trim(explode('(', (string)$t->created_by)[0]);
                    if (isset($pagedKeyIdx[$cbPrefix . '|' . $tWh])) {
                        $matchedKeys[] = $cbPrefix . '|' . $tWh;
                    }
                }
                if (empty($matchedKeys)) {
                    continue;
                }
                $txRow = [
                    'id' => (int)$t->id,
                    'reference_no' => (string)($t->reference_no ?? ''),
                    'date' => (string)($t->date ?? ''),
                    'customer_name' => (string)($t->customer_name ?? 'Walk-in Customer'),
                    'payment_type' => (string)($t->payment_type ?? 'Cash'),
                    'grand_total' => (float)($t->grand_total ?? 0.0),
                    'paid_amount' => (float)($t->paid_amount ?? 0.0),
                    'due_amount' => (float)($t->due_amount ?? 0.0),
                    'discount' => (float)($t->discount ?? 0.0),
                ];
                foreach ($matchedKeys as $mk) {
                    foreach ($pagedKeyIdx[$mk] as $targetIdx) {
                        if (count($pagedItems[$targetIdx]['transactions']) < 15) {
                            $pagedItems[$targetIdx]['transactions'][] = $txRow;
                            if (count($pagedItems[$targetIdx]['transactions']) === 15) {
                                $remainingBuckets--;
                            }
                        }
                    }
                }
                if ($remainingBuckets <= 0) {
                    break;
                }
            }
        }

        return $this->noStoreJson([
            'status' => 'success',
            'summary' => [
                'total_cashiers' => $totalCashiers,
                'total_store_bills' => $totalStoreBills,
                'total_store_sales' => round($totalStoreSales, 2),
                'total_collected' => round($totalStoreCollected, 2),
                'total_due' => round($totalStoreDue, 2),
                'total_store_discounts' => round($totalStoreDiscounts, 2),
                'total_cash_collected' => round($totalStoreCash, 2),
                'total_non_cash_collected' => round($totalStoreNonCash, 2),
                'average_store_bill' => $totalStoreBills > 0 ? round($totalStoreSales / $totalStoreBills, 2) : 0.0,
                'total_drawer_discrepancy' => round($totalDiscrepancy, 2),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'warehouse' => $warehouse,
            ],
            'data' => $pagedItems,
            'pagination' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $totalCashiers,
                'has_more'     => $hasMore,
            ],
        ]);
    }

    /**
     * Commercial Tax / VAT Input vs Output Reconciliation Report (ကုန်သွယ်လုပ်ငန်းခွန် အစီရင်ခံစာ)
     * Reconciles Output Tax (Sales) against Input Tax (Purchases), returns, and computes Net Tax Payable.
     */
    public function commercialTaxReport(Request $request)
    {
        $accounting = $this->accounting();
        $warehouse = $request->query('warehouse', $request->query('warehouse_name', 'All'));
        $startDate = $request->query('start_date', $request->query('from_date', ''));
        $endDate = $request->query('end_date', $request->query('to_date', ''));
        $month = $request->query('month', '');
        $search = trim((string)($request->input('search') ?? $request->query('search', '')));
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;

        // Progressive Pagination & Filtering parameters
        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);

        $type = strtolower(trim((string)($request->input('type') ?? $request->query('type', 'all'))));
        if (!in_array($type, ['all', 'sales', 'purchases'], true)) {
            $type = 'all';
        }

        if (!empty($month)) {
            $startDate = Carbon::parse($month . '-01')->startOfMonth()->toDateString();
            $endDate = Carbon::parse($month . '-01')->endOfMonth()->toDateString();
        } elseif (empty($startDate) || empty($endDate)) {
            $startDate = Carbon::now()->startOfMonth()->toDateString();
            $endDate = Carbon::now()->endOfMonth()->toDateString();
        }

        $warehouseId = null;
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $matchedOutlet = DB::table('outlets')
                ->where('name', $warehouse)
                ->orWhere('id', $warehouse)
                ->first();
            if ($matchedOutlet) {
                $warehouseId = $matchedOutlet->id;
                $warehouse = $matchedOutlet->name;
            }
        }

        $allowedOutlets = $this->extractAllowedOutletIds($request);
        if (!empty($allowedOutlets) && $warehouseId !== null && !in_array((int)$warehouseId, $allowedOutlets, true)) {
            return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
        }
        $canViewCost = $accounting->canViewCost($request);

        $applyWhScope = function ($query, string $table = '') use ($warehouseId, $warehouse) {
            if ($warehouse === 'All' || empty($warehouse)) {
                return;
            }
            $idCol = $table !== '' ? "{$table}.warehouse_id" : 'warehouse_id';
            $nameCol = $table !== '' ? "{$table}.warehouse_name" : 'warehouse_name';
            $query->where(function ($q) use ($idCol, $nameCol, $warehouseId, $warehouse) {
                if ($warehouseId !== null) {
                    $q->where($idCol, $warehouseId)
                      ->orWhere(function ($legacy) use ($idCol, $nameCol, $warehouse) {
                          $legacy->whereNull($idCol)->where($nameCol, $warehouse);
                      });
                } else {
                    $q->where($nameCol, $warehouse);
                }
            });
        };

        // 1. Output Tax from Sales (collected from customers)
        $salesQuery = DB::table('sales');
        $this->applyActiveDocumentFilter($salesQuery, 'sales.status');
        if (!empty($allowedOutlets)) {
            $salesQuery->whereIn('sales.warehouse_id', $allowedOutlets);
        }
        $applyWhScope($salesQuery, 'sales');
        $this->applyReportDateRange($salesQuery, $startDate, $endDate, 'sales.date');
        if (!empty($search)) {
            $salesQuery->where(function ($q) use ($search) {
                $q->where('sales.reference_no', 'like', "%{$search}%")
                  ->orWhere('sales.customer_name', 'like', "%{$search}%")
                  ->orWhere('sales.warehouse_name', 'like', "%{$search}%");
            });
        }

        // 3. Input Tax from Purchases (Received/Completed & Recoverable Input Tax only)
        $purchQuery = DB::table('purchases')
            ->whereIn('purchases.status', ['Received', 'Completed'])
            ->where('purchases.is_tax_recoverable', 1);
        if (!empty($allowedOutlets)) {
            $purchQuery->whereIn('purchases.warehouse_id', $allowedOutlets);
        }
        $applyWhScope($purchQuery, 'purchases');
        $this->applyReportDateRange($purchQuery, $startDate, $endDate, 'purchases.date');
        if (!empty($search)) {
            $purchQuery->where(function ($q) use ($search) {
                $q->where('purchases.reference_no', 'like', "%{$search}%")
                  ->orWhere('purchases.supplier_name', 'like', "%{$search}%")
                  ->orWhere('purchases.warehouse_name', 'like', "%{$search}%");
            });
        }

        $needSalesMeta = !$skipSummary || $type === 'all' || $type === 'sales';
        $needPurchMeta = !$skipSummary || $type === 'all' || $type === 'purchases';

        $hasAnySaleLineTax = $needSalesMeta
            ? DB::table('sale_items')->where('tax', '>', 0)->limit(1)->exists()
            : false;
        $hasAnyPurchLineTax = $needPurchMeta
            ? DB::table('purchase_items')->where('tax', '>', 0)->limit(1)->exists()
            : false;

        $saleLineRows = collect();
        $saleLineIds = [];
        $saleLineTaxById = [];
        if ($hasAnySaleLineTax) {
            $saleLineRows = (clone $salesQuery)
                ->join('sale_items as si', 'si.sale_id', '=', 'sales.id')
                ->where('si.tax', '>', 0)
                ->selectRaw('sales.id, sales.tax_amount, sales.shipping, sales.grand_total, SUM(si.tax) as line_tax_sum')
                ->groupBy('sales.id', 'sales.tax_amount', 'sales.shipping', 'sales.grand_total')
                ->get();
            foreach ($saleLineRows as $lr) {
                $sid = (int)$lr->id;
                $saleLineIds[] = $sid;
                $saleLineTaxById[$sid] = (float)$lr->line_tax_sum;
            }
        }

        $purchLineRows = collect();
        $purchLineIds = [];
        $purchLineTaxById = [];
        if ($hasAnyPurchLineTax) {
            $purchLineRows = (clone $purchQuery)
                ->join('purchase_items as pi', 'pi.purchase_id', '=', 'purchases.id')
                ->where('pi.tax', '>', 0)
                ->selectRaw('purchases.id, purchases.tax_amount, purchases.shipping, purchases.grand_total, SUM(pi.tax) as line_tax_sum')
                ->groupBy('purchases.id', 'purchases.tax_amount', 'purchases.shipping', 'purchases.grand_total')
                ->get();
            foreach ($purchLineRows as $pr) {
                $pid = (int)$pr->id;
                $purchLineIds[] = $pid;
                $purchLineTaxById[$pid] = (float)$pr->line_tax_sum;
            }
        }

        $salesCount = 0;
        $taxableSales = 0.0;
        $grossOutputTax = 0.0;
        $refundedOutputTax = 0.0;
        $srReturnedTaxable = 0.0;
        $netOutputTax = 0.0;

        $purchCount = 0;
        $taxablePurchases = 0.0;
        $grossInputTax = 0.0;
        $recoveredInputTax = 0.0;
        $prTaxableTotal = 0.0;
        $netInputTax = 0.0;

        $netTaxPayable = 0.0;
        $taxCreditForward = 0.0;

        $driver = DB::getDriverName();

        if (!$skipSummary) {
            // Senior Accounting Fix: Aggregate ONLY taxable sales (header tax_amount > 0 OR line_tax > 0).
            // Non-taxable 0% sales MUST NEVER inflate Taxable Sales Amount or Taxable Sales Count.
            $hdrSalesQuery = (clone $salesQuery)->where('sales.tax_amount', '>', 0);
            if (!empty($saleLineIds) && count($saleLineIds) <= 500) {
                $hdrSalesQuery->whereNotIn('sales.id', $saleLineIds);
            }
            $hdrSalesAgg = $hdrSalesQuery
                ->selectRaw("
                    COUNT(*) as cnt,
                    COALESCE(SUM(CASE WHEN (COALESCE(sales.grand_total, 0) - COALESCE(sales.shipping, 0) - COALESCE(sales.tax_amount, 0)) > 0
                        THEN (COALESCE(sales.grand_total, 0) - COALESCE(sales.shipping, 0) - COALESCE(sales.tax_amount, 0)) ELSE 0 END), 0) as taxable_sales,
                    COALESCE(SUM(COALESCE(sales.tax_amount, 0)), 0) as output_tax
                ")
                ->first();

            if (count($saleLineIds) > 500) {
                // Edge case: >500 invoices have line-item taxes -> evaluate full scoped join
                $lineTaxSub = DB::table('sale_items')
                    ->where('tax', '>', 0)
                    ->whereIn('sale_id', (clone $salesQuery)->select('sales.id'))
                    ->select('sale_id', DB::raw('COALESCE(SUM(tax), 0) as line_tax_sum'))
                    ->groupBy('sale_id');
                $lineTaxExpr = 'COALESCE(sit.line_tax_sum, 0)';
                $totalSaleTaxExpr = "(COALESCE(sales.tax_amount, 0) + {$lineTaxExpr})";
                $netTaxableExpr = "(COALESCE(sales.grand_total, 0) - COALESCE(sales.shipping, 0) - {$totalSaleTaxExpr})";
                $fullAgg = (clone $salesQuery)
                    ->leftJoinSub($lineTaxSub, 'sit', 'sit.sale_id', '=', 'sales.id')
                    ->whereRaw("{$totalSaleTaxExpr} > 0")
                    ->selectRaw("
                        COUNT(sales.id) as cnt,
                        COALESCE(SUM(CASE WHEN {$netTaxableExpr} > 0 THEN {$netTaxableExpr} ELSE 0 END), 0) as taxable_sales,
                        COALESCE(SUM({$totalSaleTaxExpr}), 0) as output_tax
                    ")
                    ->first();
                $salesCount = (int)($fullAgg->cnt ?? 0);
                $taxableSales = (float)($fullAgg->taxable_sales ?? 0);
                $grossOutputTax = (float)($fullAgg->output_tax ?? 0);
            } else {
                $salesCount = (int)($hdrSalesAgg->cnt ?? 0);
                $taxableSales = (float)($hdrSalesAgg->taxable_sales ?? 0);
                $grossOutputTax = (float)($hdrSalesAgg->output_tax ?? 0);
                foreach ($saleLineRows as $lr) {
                    $rowTax = (float)($lr->tax_amount ?? 0) + (float)$lr->line_tax_sum;
                    if ($rowTax > 0) {
                        $salesCount++;
                        $rowTaxable = max(0.0, (float)($lr->grand_total ?? 0) - (float)($lr->shipping ?? 0) - $rowTax);
                        $taxableSales += $rowTaxable;
                        $grossOutputTax += $rowTax;
                    }
                }
            }

            // 2. Output Tax adjustment from Sale Returns (ONLY tax-bearing returns reduce taxable turnover & output tax)
            $srBaseQuery = DB::table('sale_returns');
            $this->applyActiveDocumentFilter($srBaseQuery, 'status');
            if (!empty($allowedOutlets)) {
                $srBaseQuery->whereIn('warehouse_id', $allowedOutlets);
            }
            $applyWhScope($srBaseQuery);
            $this->applyReportDateRange($srBaseQuery, $startDate, $endDate, 'date');
            if (!empty($search)) {
                $srBaseQuery->where(function ($q) use ($search) {
                    $q->where('reference_no', 'like', "%{$search}%")
                      ->orWhere('customer_name', 'like', "%{$search}%")
                      ->orWhere('warehouse_name', 'like', "%{$search}%");
                });
            }

            $sumReturnsTaxableAndTax = function ($rows, float &$taxableOut, float &$taxOut) {
                foreach ($rows as $r) {
                    $lineTaxSum = 0.0;
                    if (!empty($r->items_payload)) {
                        $arr = json_decode((string)$r->items_payload, true);
                        if (is_array($arr)) {
                            foreach ($arr as $it) {
                                $lineTaxSum += max(0.0, (float)($it['tax'] ?? 0));
                            }
                        }
                    }
                    $rowTax = (float)($r->tax_amount ?? 0) + $lineTaxSum;
                    if ($rowTax > 0) {
                        $rowTaxable = max(0.0, (float)($r->grand_total ?? 0) - (float)($r->shipping ?? 0) - $rowTax);
                        $taxableOut += $rowTaxable;
                        $taxOut += $rowTax;
                    }
                }
            };

            // Fast indexed scan for header-taxed Sale Returns
            $srHdrRows = (clone $srBaseQuery)
                ->where('tax_amount', '>', 0)
                ->select('tax_amount', 'shipping', 'grand_total', 'items_payload')
                ->get();
            $sumReturnsTaxableAndTax($srHdrRows, $srReturnedTaxable, $refundedOutputTax);

            // Narrow scan for returns with tax_amount = 0 that might hold item-level tax in JSON
            $srJsonQuery = (clone $srBaseQuery)
                ->where(function ($q) {
                    $q->whereNull('tax_amount')->orWhere('tax_amount', '<=', 0);
                })
                ->where('items_payload', 'like', '%"tax":%');
            if ($driver === 'mysql') {
                $srJsonQuery->whereRaw('items_payload REGEXP ?', ['"tax":[[:space:]]*([1-9]|0\.[0-9]*[1-9])']);
            }
            $sumReturnsTaxableAndTax(
                $srJsonQuery->select('tax_amount', 'shipping', 'grand_total', 'items_payload')->cursor(),
                $srReturnedTaxable,
                $refundedOutputTax
            );

            // 3. Input Tax from Taxable Recoverable Purchases (ONLY tax > 0)
            $hdrPurchQuery = (clone $purchQuery)->where('purchases.tax_amount', '>', 0);
            if (!empty($purchLineIds) && count($purchLineIds) <= 500) {
                $hdrPurchQuery->whereNotIn('purchases.id', $purchLineIds);
            }
            $hdrPurchAgg = $hdrPurchQuery
                ->selectRaw("
                    COUNT(*) as cnt,
                    COALESCE(SUM(CASE WHEN (COALESCE(purchases.grand_total, 0) - COALESCE(purchases.shipping, 0) - COALESCE(purchases.tax_amount, 0)) > 0
                        THEN (COALESCE(purchases.grand_total, 0) - COALESCE(purchases.shipping, 0) - COALESCE(purchases.tax_amount, 0)) ELSE 0 END), 0) as taxable_purchases,
                    COALESCE(SUM(COALESCE(purchases.tax_amount, 0)), 0) as input_tax
                ")
                ->first();

            if (count($purchLineIds) > 500) {
                $poLineSub = DB::table('purchase_items')
                    ->where('tax', '>', 0)
                    ->whereIn('purchase_id', (clone $purchQuery)->select('purchases.id'))
                    ->select('purchase_id', DB::raw('COALESCE(SUM(tax), 0) as line_tax_sum'))
                    ->groupBy('purchase_id');
                $poTaxExpr = '(COALESCE(purchases.tax_amount, 0) + COALESCE(pit.line_tax_sum, 0))';
                $poTaxableExpr = "(COALESCE(purchases.grand_total, 0) - COALESCE(purchases.shipping, 0) - {$poTaxExpr})";
                $fullPurchAgg = (clone $purchQuery)
                    ->leftJoinSub($poLineSub, 'pit', 'pit.purchase_id', '=', 'purchases.id')
                    ->whereRaw("{$poTaxExpr} > 0")
                    ->selectRaw("
                        COUNT(purchases.id) as cnt,
                        COALESCE(SUM(CASE WHEN {$poTaxableExpr} > 0 THEN {$poTaxableExpr} ELSE 0 END), 0) as taxable_purchases,
                        COALESCE(SUM({$poTaxExpr}), 0) as input_tax
                    ")
                    ->first();
                $purchCount = (int)($fullPurchAgg->cnt ?? 0);
                $taxablePurchases = (float)($fullPurchAgg->taxable_purchases ?? 0);
                $grossInputTax = (float)($fullPurchAgg->input_tax ?? 0);
            } else {
                $purchCount = (int)($hdrPurchAgg->cnt ?? 0);
                $taxablePurchases = (float)($hdrPurchAgg->taxable_purchases ?? 0);
                $grossInputTax = (float)($hdrPurchAgg->input_tax ?? 0);
                foreach ($purchLineRows as $pr) {
                    $rowTax = (float)($pr->tax_amount ?? 0) + (float)$pr->line_tax_sum;
                    if ($rowTax > 0) {
                        $purchCount++;
                        $rowTaxable = max(0.0, (float)($pr->grand_total ?? 0) - (float)($pr->shipping ?? 0) - $rowTax);
                        $taxablePurchases += $rowTaxable;
                        $grossInputTax += $rowTax;
                    }
                }
            }

            // 4. Input Tax adjustment from Recoverable Purchase Returns (ONLY tax-bearing returns)
            $prBaseQuery = DB::table('purchase_returns')
                ->whereIn('status', ['Completed', 'Received'])
                ->where('is_tax_recoverable', 1);
            if (!empty($allowedOutlets)) {
                $prBaseQuery->whereIn('warehouse_id', $allowedOutlets);
            }
            $applyWhScope($prBaseQuery);
            $this->applyReportDateRange($prBaseQuery, $startDate, $endDate, 'date');
            if (!empty($search)) {
                $prBaseQuery->where(function ($q) use ($search) {
                    $q->where('reference_no', 'like', "%{$search}%")
                      ->orWhere('supplier_name', 'like', "%{$search}%")
                      ->orWhere('warehouse_name', 'like', "%{$search}%");
                });
            }

            $prHdrRows = (clone $prBaseQuery)
                ->where('tax_amount', '>', 0)
                ->select('tax_amount', 'shipping', 'grand_total', 'items_payload')
                ->get();
            $sumReturnsTaxableAndTax($prHdrRows, $prTaxableTotal, $recoveredInputTax);

            $prJsonQuery = (clone $prBaseQuery)
                ->where(function ($q) {
                    $q->whereNull('tax_amount')->orWhere('tax_amount', '<=', 0);
                })
                ->where('items_payload', 'like', '%"tax":%');
            if ($driver === 'mysql') {
                $prJsonQuery->whereRaw('items_payload REGEXP ?', ['"tax":[[:space:]]*([1-9]|0\.[0-9]*[1-9])']);
            }
            $sumReturnsTaxableAndTax(
                $prJsonQuery->select('tax_amount', 'shipping', 'grand_total', 'items_payload')->cursor(),
                $prTaxableTotal,
                $recoveredInputTax
            );

            // 5. Net Commercial Tax Reconciliation Calculations
            $netOutputTax = round($grossOutputTax - $refundedOutputTax, 2);
            $netInputTax = round($grossInputTax - $recoveredInputTax, 2);
            $signedTaxBalance = round($netOutputTax - $netInputTax, 2);
            $netTaxPayable = $signedTaxBalance >= 0 ? $signedTaxBalance : 0.0;
            $taxCreditForward = $signedTaxBalance < 0 ? abs($signedTaxBalance) : 0.0;
        }

        // Taxable Invoices with Tax Breakdown (Progressive pagination per tab)
        $applyTaxableSaleScope = function ($q) use ($hasAnySaleLineTax, $saleLineIds) {
            if (!$hasAnySaleLineTax) {
                $q->where('sales.tax_amount', '>', 0);
            } elseif (!empty($saleLineIds) && count($saleLineIds) <= 500) {
                $q->where(function ($tq) use ($saleLineIds) {
                    $tq->where('sales.tax_amount', '>', 0)
                       ->orWhereIn('sales.id', $saleLineIds);
                });
            } else {
                $q->where(function ($tq) {
                    $tq->where('sales.tax_amount', '>', 0)
                       ->orWhereRaw('EXISTS (SELECT 1 FROM sale_items si WHERE si.sale_id = sales.id AND si.tax > 0)');
                });
            }
        };

        $totalSales = 0;
        $salesHasMore = false;
        $taxableSalesList = collect();
        if ($type === 'all' || $type === 'sales') {
            $salesListQuery = (clone $salesQuery);
            $applyTaxableSaleScope($salesListQuery);
            if (!$skipSummary) {
                $totalSales = $salesCount;
            } elseif ($knownTotal !== null) {
                $totalSales = $knownTotal;
            } else {
                $totalSales = (int)(clone $salesListQuery)->count();
            }

            $rawSaleRows = $salesListQuery
                ->select(
                    'sales.id',
                    'sales.reference_no',
                    'sales.date',
                    'sales.customer_name',
                    'sales.warehouse_name',
                    'sales.tax_rate',
                    'sales.tax_amount',
                    'sales.subtotal',
                    'sales.discount',
                    'sales.shipping',
                    'sales.grand_total'
                )
                ->orderBy('sales.date', 'desc')
                ->orderBy('sales.id', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage + 1)
                ->get();
            $salesHasMore = $rawSaleRows->count() > $perPage;
            $pageSaleRows = $salesHasMore ? $rawSaleRows->slice(0, $perPage)->values() : $rawSaleRows->values();

            $lineTaxForPage = $saleLineTaxById;
            if (empty($lineTaxForPage) && $hasAnySaleLineTax && $pageSaleRows->isNotEmpty()) {
                foreach (array_chunk($pageSaleRows->pluck('id')->all(), 400) as $idChunk) {
                    foreach (DB::table('sale_items')
                        ->whereIn('sale_id', $idChunk)
                        ->where('tax', '>', 0)
                        ->selectRaw('sale_id, COALESCE(SUM(tax), 0) as lt')
                        ->groupBy('sale_id')
                        ->pluck('lt', 'sale_id') as $sid => $lt) {
                        $lineTaxForPage[(int)$sid] = (float)$lt;
                    }
                }
            }

            $taxableSalesList = $pageSaleRows->map(function ($row) use ($lineTaxForPage) {
                $subtotal = (float)($row->subtotal ?? 0);
                $discount = (float)($row->discount ?? 0);
                $shipping = (float)($row->shipping ?? 0);
                $grandTotal = (float)($row->grand_total ?? 0);
                $taxAmount = round((float)($row->tax_amount ?? 0) + (float)($lineTaxForPage[(int)$row->id] ?? 0.0), 2);
                $taxableAmount = round(max(0.0, $grandTotal - $shipping - $taxAmount), 2);
                $taxRate = (float)($row->tax_rate ?? 0);
                if ($taxRate <= 0 && $taxAmount > 0 && $taxableAmount > 0) {
                    $taxRate = round(($taxAmount / $taxableAmount) * 100.0, 2);
                }
                return [
                    'id'             => $row->id,
                    'reference_no'   => $row->reference_no,
                    'date'           => $row->date,
                    'customer_name'  => $row->customer_name ?? 'Walk-in Customer',
                    'warehouse_name' => $row->warehouse_name ?? 'Main Warehouse',
                    'tax_rate'       => $taxRate,
                    'tax_amount'     => $taxAmount,
                    'taxable_amount' => $taxableAmount,
                    'subtotal'       => $subtotal,
                    'discount'       => $discount,
                    'grand_total'    => $grandTotal,
                ];
            });
        } elseif ($knownTotal !== null) {
            $totalSales = $knownTotal;
        }

        $applyTaxablePurchaseScope = function ($q) use ($hasAnyPurchLineTax, $purchLineIds) {
            if (!$hasAnyPurchLineTax) {
                $q->where('purchases.tax_amount', '>', 0);
            } elseif (!empty($purchLineIds) && count($purchLineIds) <= 500) {
                $q->where(function ($tq) use ($purchLineIds) {
                    $tq->where('purchases.tax_amount', '>', 0)
                       ->orWhereIn('purchases.id', $purchLineIds);
                });
            } else {
                $q->where(function ($tq) {
                    $tq->where('purchases.tax_amount', '>', 0)
                       ->orWhereRaw('EXISTS (SELECT 1 FROM purchase_items pi WHERE pi.purchase_id = purchases.id AND pi.tax > 0)');
                });
            }
        };

        $totalPurchases = 0;
        $purchasesHasMore = false;
        $taxablePurchasesList = collect();
        if ($type === 'all' || $type === 'purchases') {
            $purchListQuery = (clone $purchQuery);
            $applyTaxablePurchaseScope($purchListQuery);
            if (!$skipSummary) {
                $totalPurchases = $purchCount;
            } elseif ($knownTotal !== null) {
                $totalPurchases = $knownTotal;
            } else {
                $totalPurchases = (int)(clone $purchListQuery)->count();
            }

            $rawPurchRows = $purchListQuery
                ->select(
                    'purchases.id',
                    'purchases.reference_no',
                    'purchases.date',
                    'purchases.supplier_name',
                    'purchases.warehouse_name',
                    'purchases.tax_rate',
                    'purchases.tax_amount',
                    'purchases.discount',
                    'purchases.shipping',
                    'purchases.grand_total'
                )
                ->orderBy('purchases.date', 'desc')
                ->orderBy('purchases.id', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage + 1)
                ->get();
            $purchasesHasMore = $rawPurchRows->count() > $perPage;
            $pagePurchRows = $purchasesHasMore ? $rawPurchRows->slice(0, $perPage)->values() : $rawPurchRows->values();

            $lineTaxForPurchPage = $purchLineTaxById;
            if (empty($lineTaxForPurchPage) && $hasAnyPurchLineTax && $pagePurchRows->isNotEmpty()) {
                foreach (array_chunk($pagePurchRows->pluck('id')->all(), 400) as $idChunk) {
                    foreach (DB::table('purchase_items')
                        ->whereIn('purchase_id', $idChunk)
                        ->where('tax', '>', 0)
                        ->selectRaw('purchase_id, COALESCE(SUM(tax), 0) as lt')
                        ->groupBy('purchase_id')
                        ->pluck('lt', 'purchase_id') as $pid => $lt) {
                        $lineTaxForPurchPage[(int)$pid] = (float)$lt;
                    }
                }
            }

            $taxablePurchasesList = $pagePurchRows->map(function ($row) use ($lineTaxForPurchPage) {
                $grandTotal = (float)($row->grand_total ?? 0);
                $taxAmount = round((float)($row->tax_amount ?? 0) + (float)($lineTaxForPurchPage[(int)$row->id] ?? 0.0), 2);
                $shipping = (float)($row->shipping ?? 0);
                $taxableAmount = round(max(0.0, $grandTotal - $shipping - $taxAmount), 2);
                $taxRate = (float)($row->tax_rate ?? 0);
                if ($taxRate <= 0 && $taxAmount > 0 && $taxableAmount > 0) {
                    $taxRate = round(($taxAmount / $taxableAmount) * 100.0, 2);
                }
                return [
                    'id'             => $row->id,
                    'reference_no'   => $row->reference_no,
                    'date'           => $row->date,
                    'supplier_name'  => $row->supplier_name ?? 'Default Supplier',
                    'warehouse_name' => $row->warehouse_name ?? 'Main Warehouse',
                    'tax_rate'       => $taxRate,
                    'tax_amount'     => $taxAmount,
                    'taxable_amount' => $taxableAmount,
                    'discount'       => (float)($row->discount ?? 0),
                    'grand_total'    => $grandTotal,
                ];
            });
        } elseif ($knownTotal !== null) {
            $totalPurchases = $knownTotal;
        }

        $salesLastPage = max(1, (int)ceil($totalSales / max(1, $perPage)));
        $purchasesLastPage = max(1, (int)ceil($totalPurchases / max(1, $perPage)));

        $maskedPurchasesTaxRecords = $canViewCost
            ? $taxablePurchasesList
            : collect($taxablePurchasesList)->map(function ($row) {
                $masked = $this->maskAssocNumericKeys(
                    (array)$row,
                    ['tax_rate', 'tax_amount', 'taxable_amount', 'discount', 'grand_total'],
                    null
                );
                $masked['cost_masked'] = true;
                return $masked;
            })->values();

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'period' => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'warehouse'  => $warehouse,
            ],
            'output_tax' => [
                'gross_sales_tax'      => round($grossOutputTax, 2),
                'returns_tax_refund'   => round($refundedOutputTax, 2),
                'net_output_tax'       => round($netOutputTax, 2),
                'taxable_sales_amount' => round($taxableSales - $srReturnedTaxable, 2),
                'sales_count'          => $salesCount,
            ],
            'input_tax' => [
                'cost_masked'              => !$canViewCost,
                'gross_purchases_tax'      => $canViewCost ? round($grossInputTax, 2) : 0.0,
                'returns_tax_recovered'    => $canViewCost ? round($recoveredInputTax, 2) : 0.0,
                'net_input_tax'            => $canViewCost ? round($netInputTax, 2) : 0.0,
                'taxable_purchases_amount' => $canViewCost ? round($taxablePurchases - $prTaxableTotal, 2) : 0.0,
                'purchases_count'          => $purchCount,
            ],
            'tax_reconciliation' => [
                'cost_masked'            => !$canViewCost,
                'net_output_tax'         => round($netOutputTax, 2),
                'net_input_tax'          => $canViewCost ? round($netInputTax, 2) : 0.0,
                'net_tax_payable'        => $canViewCost ? $netTaxPayable : 0.0,
                'tax_credit_forward'     => $canViewCost ? $taxCreditForward : 0.0,
                'tax_status'             => !$canViewCost
                    ? 'Restricted (Protected)'
                    : ($netTaxPayable > 0 ? 'Tax Payable (ပေးဆောင်ရန်)' : ($taxCreditForward > 0 ? 'Tax Credit (ပိုလျှံ/ရရန်)' : 'Balanced (ညီမျှ)')),
            ],
            'sales_pagination' => [
                'current_page' => $page,
                'last_page'    => $salesLastPage,
                'per_page'     => $perPage,
                'total'        => $totalSales,
                'has_more'     => $salesHasMore,
            ],
            'purchases_pagination' => [
                'current_page' => $page,
                'last_page'    => $purchasesLastPage,
                'per_page'     => $perPage,
                'total'        => $totalPurchases,
                'has_more'     => $purchasesHasMore,
            ],
            'sales_tax_records'     => $taxableSalesList,
            'purchases_tax_records' => $maskedPurchasesTaxRecords,
        ]);
    }

    /**
     * Normalize payment method names for granular register reports.
     */
    private function normalizePaymentMethodName(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return 'Other';
        }
        $lower = strtolower($trimmed);
        if ($lower === 'cash') return 'Cash';
        if (in_array($lower, ['kbzpay', 'kbz pay', 'kbz_pay'])) return 'KBZPay';
        if (in_array($lower, ['kpay', 'k pay', 'k-pay'])) return 'KPay';
        if (in_array($lower, ['wave', 'wavepay', 'wave pay', 'wave_pay'])) return 'WavePay';
        if (in_array($lower, ['aya pay', 'ayapay'])) return 'AYA Pay';
        if (in_array($lower, ['cb pay', 'cbpay'])) return 'CB Pay';
        if (in_array($lower, ['one pay', 'onepay'])) return 'OnePay';
        if (in_array($lower, ['uab pay', 'uabpay'])) return 'UABPay';
        if (in_array($lower, ['card', 'credit card', 'pos card', 'credit_card'])) return 'Credit Card';
        if (in_array($lower, ['debit card', 'debit_card'])) return 'Debit Card';
        if (in_array($lower, ['visa'])) return 'Visa';
        if (in_array($lower, ['mastercard'])) return 'MasterCard';
        if (in_array($lower, ['bank transfer', 'bank_transfer', 'wire', 'bank'])) return 'Bank Transfer';
        if (in_array($lower, ['cheque', 'check'])) return 'Cheque';
        if (in_array($lower, ['digital', 'digital / mobile', 'mobile money', 'wallet'])) {
            return 'Mobile Wallet';
        }
        return ucwords($trimmed);
    }
}