<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    /** @var array<string, ?int> */
    private array $supplierIdCache = [];

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
     * Unified warehouse filter supporting both numeric outlet IDs and warehouse display names.
     */
    private function applyWarehouseFilter($query, ?string $warehouse, ?int $warehouseId, string $nameCol = 'warehouse_name', string $idCol = 'warehouse_id'): void
    {
        if (empty($warehouse) || $warehouse === 'All') {
            return;
        }
        if ($warehouseId !== null) {
            if (is_numeric($warehouse)) {
                $query->where($idCol, $warehouseId);
            } else {
                $query->where(function ($q) use ($idCol, $nameCol, $warehouseId, $warehouse) {
                    $q->where($idCol, $warehouseId)
                      ->orWhere(function ($sub) use ($idCol, $nameCol, $warehouse) {
                          $sub->whereNull($idCol)->where($nameCol, $warehouse);
                      });
                });
            }
        } else {
            $query->where($nameCol, $warehouse);
        }
    }

    /**
     * Compute active Sale Return adjustments (tax-exclusive ex-shipping revenue, total tax incl. line tax,
     * and FIFO / catalog-fallback returned COGS) consistently across P&L, Dashboard, Z-Report & Margins.
     */
    private function computeSaleReturnAdjustments(
        ?string $startDate,
        ?string $endDate,
        ?string $warehouse,
        ?int $warehouseId,
        array $allowedOutlets,
        bool $groupByMonthWarehouse = false,
        bool $groupByMonthOnly = false
    ): array {
        $result = [
            'returns_total' => 0.0,
            'returns_tax' => 0.0,
            'returned_cogs' => 0.0,
            'by_month_warehouse' => [],
            'by_month' => [],
        ];

        $accounting = $this->accounting();
        $hasSrcl = $accounting->schemaHasTable('sale_return_cost_layers');
        $hasReturnCostCol = $accounting->schemaHasColumn('sale_returns', 'return_cost_total');
        $endBound = !empty($endDate) ? (strlen(trim($endDate)) === 10 ? trim($endDate) . ' 23:59:59' : trim($endDate)) : null;
        $startBound = !empty($startDate) ? trim($startDate) : null;

        $srQuery = DB::table('sale_returns as sr');
        if ($hasSrcl) {
            $srLayerSub = DB::table('sale_return_cost_layers as srcl')
                ->join('sale_returns as sr_i', 'sr_i.id', '=', 'srcl.sale_return_id')
                ->select('srcl.sale_return_id', DB::raw('COALESCE(SUM(srcl.total_cost), 0) as layer_cost'))
                ->groupBy('srcl.sale_return_id');
            $this->applyWarehouseFilter($srLayerSub, $warehouse, $warehouseId, 'sr_i.warehouse_name', 'sr_i.warehouse_id');
            if (!empty($allowedOutlets)) {
                $srLayerSub->whereIn('sr_i.warehouse_id', $allowedOutlets);
            }
            if (!empty($startBound)) {
                $srLayerSub->where('sr_i.date', '>=', $startBound);
            }
            if (!empty($endBound)) {
                $srLayerSub->where('sr_i.date', '<=', $endBound);
            }
            $srQuery->leftJoinSub($srLayerSub, 'srcl', 'srcl.sale_return_id', '=', 'sr.id');
        }

        $this->applyActiveDocumentFilter($srQuery, 'sr.status');
        $this->applyWarehouseFilter($srQuery, $warehouse, $warehouseId, 'sr.warehouse_name', 'sr.warehouse_id');
        if (!empty($allowedOutlets)) {
            $srQuery->whereIn('sr.warehouse_id', $allowedOutlets);
        }
        if (!empty($startBound)) {
            $srQuery->where('sr.date', '>=', $startBound);
        }
        if (!empty($endBound)) {
            $srQuery->where('sr.date', '<=', $endBound);
        }

        $selectCols = [
            'sr.id',
            'sr.date',
            'sr.warehouse_name',
            'sr.grand_total',
            'sr.tax_amount',
            'sr.shipping',
            'sr.items_payload',
        ];
        if ($hasReturnCostCol) {
            $selectCols[] = 'sr.return_cost_total';
        }
        if ($hasSrcl) {
            $selectCols[] = DB::raw('COALESCE(srcl.layer_cost, 0) as layer_cost');
        }

        $rows = $srQuery->select($selectCols)->get();
        if ($rows->isEmpty()) {
            return $result;
        }

        $neededProductIds = [];
        $neededVariantIds = [];
        $parsedPayloads = [];

        foreach ($rows as $row) {
            $rowCost = ($hasReturnCostCol ? (float)($row->return_cost_total ?? 0) : 0.0);
            $layerCost = ($hasSrcl ? (float)($row->layer_cost ?? 0) : 0.0);
            $needsCogsFallback = ($rowCost <= 0.00001 && $layerCost <= 0.00001);
            $rawPayload = (string)($row->items_payload ?? '');
            $mightHaveLineTax = ($rawPayload !== '' && stripos($rawPayload, '"tax"') !== false);

            if (($needsCogsFallback || $mightHaveLineTax) && $rawPayload !== '') {
                $decoded = json_decode($rawPayload, true);
                if (is_array($decoded)) {
                    $parsedPayloads[(int)$row->id] = $decoded;
                    if ($needsCogsFallback) {
                        foreach ($decoded as $ri) {
                            if (!is_array($ri)) {
                                continue;
                            }
                            $pid = isset($ri['product_id']) ? (int)$ri['product_id'] : 0;
                            $vid = isset($ri['product_variant_id']) ? (int)$ri['product_variant_id'] : 0;
                            if ($pid > 0) {
                                $neededProductIds[$pid] = true;
                            }
                            if ($vid > 0) {
                                $neededVariantIds[$vid] = true;
                            }
                        }
                    }
                }
            }
        }

        $productCostMap = [];
        if (!empty($neededProductIds)) {
            foreach (array_chunk(array_keys($neededProductIds), 400) as $chunk) {
                foreach (DB::table('products')->whereIn('id', $chunk)->get(['id', 'cost_price', 'purchase_price']) as $p) {
                    $c = (float)($p->cost_price ?? 0);
                    $productCostMap[(int)$p->id] = $c > 0 ? $c : (float)($p->purchase_price ?? 0);
                }
            }
        }

        $variantCostMap = [];
        if (!empty($neededVariantIds)) {
            foreach (array_chunk(array_keys($neededVariantIds), 400) as $chunk) {
                foreach (DB::table('product_variants')->whereIn('id', $chunk)->get(['id', 'cost_price']) as $v) {
                    $variantCostMap[(int)$v->id] = (float)($v->cost_price ?? 0);
                }
            }
        }

        foreach ($rows as $row) {
            $rid = (int)$row->id;
            $items = $parsedPayloads[$rid] ?? null;

            $lineTax = 0.0;
            if (is_array($items)) {
                foreach ($items as $ri) {
                    if (is_array($ri)) {
                        $lineTax += max(0.0, (float)($ri['tax'] ?? 0));
                    }
                }
            }

            $headerTax = max(0.0, (float)($row->tax_amount ?? 0));
            $shipping = max(0.0, (float)($row->shipping ?? 0));
            $grandTotal = (float)($row->grand_total ?? 0);
            $rowTax = $headerTax + $lineTax;
            $rowNetRev = max(0.0, $grandTotal - $rowTax - $shipping);

            $headerCost = $hasReturnCostCol ? (float)($row->return_cost_total ?? 0) : 0.0;
            $layerCost = $hasSrcl ? (float)($row->layer_cost ?? 0) : 0.0;
            if ($headerCost > 0.00001) {
                $rowCogs = $headerCost;
            } elseif ($layerCost > 0.00001) {
                $rowCogs = $layerCost;
            } elseif (is_array($items)) {
                $rowCogs = 0.0;
                foreach ($items as $ri) {
                    if (!is_array($ri)) {
                        continue;
                    }
                    $qty = (float)($ri['quantity'] ?? 0);
                    $convRate = (float)($ri['conversion_rate'] ?? 1);
                    if ($convRate <= 0) {
                        $convRate = 1.0;
                    }
                    $baseQty = (float)($ri['base_quantity'] ?? 0);
                    if ($baseQty <= 0) {
                        $baseQty = $qty * $convRate;
                    }
                    $itemTotalCost = (float)($ri['total_cost'] ?? 0);
                    $itemBaseUnitCost = (float)($ri['base_unit_cost'] ?? 0);
                    $itemUnitCost = (float)($ri['unit_cost'] ?? $ri['cost_price'] ?? 0);

                    if ($itemTotalCost > 0) {
                        $rowCogs += $itemTotalCost;
                    } elseif ($itemBaseUnitCost > 0) {
                        $rowCogs += $baseQty * $itemBaseUnitCost;
                    } elseif ($itemUnitCost > 0) {
                        $rowCogs += $qty * $itemUnitCost;
                    } elseif ($baseQty > 0) {
                        $vid = isset($ri['product_variant_id']) ? (int)$ri['product_variant_id'] : 0;
                        $pid = isset($ri['product_id']) ? (int)$ri['product_id'] : 0;
                        $unitFallback = ($vid > 0 && ($variantCostMap[$vid] ?? 0) > 0)
                            ? $variantCostMap[$vid]
                            : ($productCostMap[$pid] ?? 0.0);
                        if ($unitFallback > 0) {
                            $rowCogs += $baseQty * $unitFallback;
                        }
                    }
                }
            } else {
                $rowCogs = 0.0;
            }

            $result['returns_total'] += $rowNetRev;
            $result['returns_tax'] += $rowTax;
            $result['returned_cogs'] += $rowCogs;

            if ($groupByMonthWarehouse) {
                $mPeriod = substr((string)($row->date ?? ''), 0, 7);
                $wName = !empty($row->warehouse_name) ? (string)$row->warehouse_name : 'Main Branch Outlet';
                if ($mPeriod !== '') {
                    $mKey = $mPeriod . '_' . $wName;
                    if (!isset($result['by_month_warehouse'][$mKey])) {
                        $result['by_month_warehouse'][$mKey] = [
                            'month_period' => $mPeriod,
                            'w_name' => $wName,
                            'returns_total' => 0.0,
                            'returns_tax' => 0.0,
                            'returned_cogs' => 0.0,
                        ];
                    }
                    $result['by_month_warehouse'][$mKey]['returns_total'] += $rowNetRev;
                    $result['by_month_warehouse'][$mKey]['returns_tax'] += $rowTax;
                    $result['by_month_warehouse'][$mKey]['returned_cogs'] += $rowCogs;
                }
            }

            if ($groupByMonthOnly) {
                $mIdx = (int)substr((string)($row->date ?? ''), 5, 2);
                if ($mIdx >= 1 && $mIdx <= 12) {
                    if (!isset($result['by_month'][$mIdx])) {
                        $result['by_month'][$mIdx] = [
                            'returns_total' => 0.0,
                            'returns_tax' => 0.0,
                            'returned_cogs' => 0.0,
                        ];
                    }
                    $result['by_month'][$mIdx]['returns_total'] += $rowNetRev;
                    $result['by_month'][$mIdx]['returns_tax'] += $rowTax;
                    $result['by_month'][$mIdx]['returned_cogs'] += $rowCogs;
                }
            }
        }

        $result['returns_total'] = round($result['returns_total'], 2);
        $result['returns_tax'] = round($result['returns_tax'], 2);
        $result['returned_cogs'] = round($result['returned_cogs'], 2);

        return $result;
    }

    /**
     * Recover catalog-cost fallback COGS for both uncosted sales (sales.total_cogs <= 0)
     * AND mixed invoices where some lines have FIFO COGS (> 0) and sibling lines have 0 COGS.
     */
    private function computeMixedInvoiceMissingCogs(
        ?string $startDate,
        ?string $endDate,
        ?string $warehouse,
        ?int $warehouseId,
        array $allowedOutlets,
        bool $groupByMonthWarehouse = false,
        bool $groupByMonthOnly = false
    ): array {
        $cogsFallbackExpr = 'COALESCE(SUM(COALESCE(NULLIF(sale_items.total_cogs, 0), COALESCE(NULLIF(sale_items.base_quantity, 0), COALESCE(sale_items.quantity, 1) * COALESCE(NULLIF(sale_items.conversion_rate, 0), 1)) * COALESCE(NULLIF(sale_items.base_unit_cost, 0), NULLIF(product_variants.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0))), 0)';
        $endBound = !empty($endDate) ? (strlen(trim($endDate)) === 10 ? trim($endDate) . ' 23:59:59' : trim($endDate)) : null;
        $startBound = !empty($startDate) ? trim($startDate) : null;

        $q = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('product_variants', 'sale_items.product_variant_id', '=', 'product_variants.id')
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
            ->where(function ($outer) {
                $outer->whereNull('sales.total_cogs')
                      ->orWhere('sales.total_cogs', '<=', 0)
                      ->orWhere(function ($mixed) {
                          $mixed->where('sales.total_cogs', '>', 0)
                                ->where(function ($lineZero) {
                                    $lineZero->whereNull('sale_items.total_cogs')
                                             ->orWhere('sale_items.total_cogs', '<=', 0);
                                })
                                ->whereExists(function ($sib) {
                                    $sib->select(DB::raw(1))
                                        ->from('sale_items as si2')
                                        ->whereColumn('si2.sale_id', 'sales.id')
                                        ->where('si2.total_cogs', '>', 0);
                                });
                      });
            });

        $this->applyActiveDocumentFilter($q, 'sales.status');
        $this->applyWarehouseFilter($q, $warehouse, $warehouseId, 'sales.warehouse_name', 'sales.warehouse_id');
        if (!empty($allowedOutlets)) {
            $q->whereIn('sales.warehouse_id', $allowedOutlets);
        }
        if (!empty($startBound)) {
            $q->where('sales.date', '>=', $startBound);
        }
        if (!empty($endBound)) {
            $q->where('sales.date', '<=', $endBound);
        }

        if ($groupByMonthWarehouse) {
            $rows = $q->selectRaw("SUBSTR(sales.date, 1, 7) as month_period, COALESCE(sales.warehouse_name, 'Main Branch Outlet') as w_name, {$cogsFallbackExpr} as cogs_total")
                ->groupBy(DB::raw('SUBSTR(sales.date, 1, 7)'), 'sales.warehouse_name')
                ->get();
            $total = 0.0;
            $byMw = [];
            foreach ($rows as $r) {
                $val = (float)($r->cogs_total ?? 0);
                $total += $val;
                $mKey = $r->month_period . '_' . $r->w_name;
                $byMw[$mKey] = ($byMw[$mKey] ?? 0.0) + $val;
            }
            return ['total' => $total, 'by_month_warehouse' => $byMw];
        }

        if ($groupByMonthOnly) {
            $isSqlite = DB::connection()->getDriverName() === 'sqlite';
            $monthExpr = $isSqlite ? "CAST(strftime('%m', sales.date) AS integer)" : 'MONTH(sales.date)';
            $rows = $q->selectRaw("{$monthExpr} as m, {$cogsFallbackExpr} as cogs_total")
                ->groupBy(DB::raw($monthExpr))
                ->get();
            $total = 0.0;
            $byM = [];
            foreach ($rows as $r) {
                $mIdx = (int)$r->m;
                $val = (float)($r->cogs_total ?? 0);
                $total += $val;
                if ($mIdx >= 1 && $mIdx <= 12) {
                    $byM[$mIdx] = ($byM[$mIdx] ?? 0.0) + $val;
                }
            }
            return ['total' => $total, 'by_month' => $byM];
        }

        $total = (float)$q->selectRaw("{$cogsFallbackExpr} as cogs_total")->value('cogs_total');
        return ['total' => $total];
    }

    /**
     * IAS 1.32 Per-Customer Net AR calculation: never nets Customer A's open return credit
     * against Customer B's open receivable balance.
     */
    private function computePerCustomerNetAr(?string $warehouse, ?int $warehouseId, array $allowedOutlets): array
    {
        $salesQ = DB::table('sales');
        $this->applyActiveDocumentFilter($salesQ, 'status');
        $this->applyWarehouseFilter($salesQ, $warehouse, $warehouseId, 'warehouse_name', 'warehouse_id');
        if (!empty($allowedOutlets)) {
            $salesQ->whereIn('warehouse_id', $allowedOutlets);
        }
        $grossByCust = $salesQ->where('due_amount', '>', 0)
            ->selectRaw('COALESCE(customer_id, 0) as cid, COALESCE(SUM(due_amount), 0) as due_sum')
            ->groupBy(DB::raw('COALESCE(customer_id, 0)'))
            ->pluck('due_sum', 'cid');

        $srQ = DB::table('sale_returns');
        $this->applyActiveDocumentFilter($srQ, 'status');
        $this->applyWarehouseFilter($srQ, $warehouse, $warehouseId, 'warehouse_name', 'warehouse_id');
        if (!empty($allowedOutlets)) {
            $srQ->whereIn('warehouse_id', $allowedOutlets);
        }
        $creditByCust = $srQ->where('due_amount', '>', 0)
            ->selectRaw('COALESCE(customer_id, 0) as cid, COALESCE(SUM(due_amount), 0) as credit_sum')
            ->groupBy(DB::raw('COALESCE(customer_id, 0)'))
            ->pluck('credit_sum', 'cid');

        $allCids = array_unique(array_merge($grossByCust->keys()->all(), $creditByCust->keys()->all()));
        $totalNetAr = 0.0;
        $totalCreditBalance = 0.0;
        foreach ($allCids as $cid) {
            $net = round((float)($grossByCust[$cid] ?? 0.0) - (float)($creditByCust[$cid] ?? 0.0), 2);
            if ($net > 0) {
                $totalNetAr += $net;
            } elseif ($net < 0) {
                $totalCreditBalance += abs($net);
            }
        }

        return [
            'net_ar' => round($totalNetAr, 2),
            'credit_balance' => round($totalCreditBalance, 2),
        ];
    }

    private function resolveSupplierIdParam(?string $supplierParam): ?int
    {
        if (empty($supplierParam) || $supplierParam === 'All') {
            return null;
        }
        if (is_numeric($supplierParam)) {
            return (int)$supplierParam;
        }
        $key = trim($supplierParam);
        if (array_key_exists($key, $this->supplierIdCache)) {
            return $this->supplierIdCache[$key];
        }
        $id = Supplier::where('name', $key)->value('id');
        return $this->supplierIdCache[$key] = ($id !== null ? (int)$id : null);
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
            'purchases' => [], 'sale_returns' => [], 'transfers_in' => [], 'adjust_in' => [],
            'sales' => [], 'purchase_returns' => [], 'transfers_out' => [], 'adjust_out' => [],
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
        $warehouseId = $filterWh ? $this->resolveWarehouseIdParam($warehouse) : null;
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
        if ($warehouseId !== null) {
            $purchQ->where('purchases.warehouse_id', $warehouseId);
        } elseif ($filterWh) {
            $purchQ->where('purchases.warehouse_name', $warehouse);
        }
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
        if ($warehouseId !== null) {
            $salesQ->where('sales.warehouse_id', $warehouseId);
        } elseif ($filterWh) {
            $salesQ->where('sales.warehouse_name', $warehouse);
        }
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
        if ($warehouseId !== null) {
            $adjBaseQ->where('stock_adjustments.warehouse_id', $warehouseId);
        } elseif ($filterWh) {
            $adjBaseQ->where('stock_adjustments.warehouse_name', $warehouse);
        }
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
        if ($warehouseId !== null) {
            $trfOutQ->where('stock_transfers.from_warehouse_id', $warehouseId);
        } elseif ($filterWh) {
            $trfOutQ->where('stock_transfers.from_warehouse', $warehouse);
        }
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
        if ($warehouseId !== null) {
            $trfInQ->where('stock_transfers.to_warehouse_id', $warehouseId);
        } elseif ($filterWh) {
            $trfInQ->where('stock_transfers.to_warehouse', $warehouse);
        }
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
        $srDateExpr = "CASE WHEN stock_movements.movement_type IN ('sale_return_void', 'sale_return_edit_reverse') THEN stock_movements.created_at ELSE COALESCE(sale_returns.date, stock_movements.created_at) END";
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
        if ($warehouseId !== null) {
            $srQ->whereRaw('COALESCE(sale_returns.warehouse_id, stock_movements.warehouse_id) = ?', [$warehouseId]);
        } elseif ($filterWh) {
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
        $prDateExpr = "CASE WHEN stock_movements.movement_type = 'purchase_return_void' THEN stock_movements.created_at ELSE COALESCE(purchase_returns.date, stock_movements.created_at) END";
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
        if ($warehouseId !== null) {
            $prQ->whereRaw('COALESCE(purchase_returns.warehouse_id, stock_movements.warehouse_id) = ?', [$warehouseId]);
        } elseif ($filterWh) {
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

        // Include variant-addition opening stock movements (reference_type = 'product_update') which have no Purchase row
        if ($accounting->schemaHasTable('stock_movements')) {
            $opUpdQ = DB::table('stock_movements')
                ->leftJoin('outlets', 'stock_movements.warehouse_id', '=', 'outlets.id')
                ->where('stock_movements.movement_type', 'opening_stock')
                ->where('stock_movements.reference_type', 'product_update');
            $applyWindow($opUpdQ, 'stock_movements.created_at');
            if (!empty($allowedOutlets)) {
                $opUpdQ->whereIn('stock_movements.warehouse_id', $allowedOutlets);
            }
            if ($warehouseId !== null) {
                $opUpdQ->where('stock_movements.warehouse_id', $warehouseId);
            } elseif ($filterWh) {
                $opUpdQ->where('outlets.name', $warehouse);
            }
            if ($filterProducts) {
                $opUpdQ->whereIn('stock_movements.product_id', $productIds);
            }
            if ($includePostWindow) {
                $opRows = $opUpdQ->selectRaw(
                    'stock_movements.product_id, SUM(CASE WHEN stock_movements.created_at <= ? THEN stock_movements.quantity_in ELSE 0 END) as period_qty, SUM(CASE WHEN stock_movements.created_at > ? THEN stock_movements.quantity_in ELSE 0 END) as post_qty',
                    [$toDateTime, $toDateTime]
                )->groupBy('stock_movements.product_id')->get();
                foreach ($opRows as $or) {
                    $pid = (int)$or->product_id;
                    $pv = (float)($or->period_qty ?? 0);
                    $qv = (float)($or->post_qty ?? 0);
                    if ($pv != 0.0) {
                        $period['purchases'][$pid] = ($period['purchases'][$pid] ?? 0.0) + $pv;
                    }
                    if ($qv != 0.0) {
                        $post['purchases'][$pid] = ($post['purchases'][$pid] ?? 0.0) + $qv;
                    }
                }
            } else {
                foreach ($opUpdQ->select('stock_movements.product_id', DB::raw('SUM(stock_movements.quantity_in) as qty'))
                    ->groupBy('stock_movements.product_id')->pluck('qty', 'product_id') as $pid => $qty) {
                    $period['purchases'][(int)$pid] = ($period['purchases'][(int)$pid] ?? 0.0) + (float)$qty;
                }
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
        $compact = $request->boolean('compact') || $request->query('compact') === 'true' || $request->query('compact') === '1';

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
        // Use 10-char YYYY-MM-DD lower bounds so date-only rows ('2026-11-23') and datetime rows ('2026-11-23 09:00:00') BOTH match
        $todayStart = $today;
        $todayEnd = $today . ' 23:59:59';
        $yesterdayStart = $yesterday;
        $yesterdayEnd = $yesterday . ' 23:59:59';

        // Date scoping helper (preserves B-tree index range seek)
        $applyDateScope = function ($query, $column = 'date') use ($startDate, $endDate) {
            if (!empty($startDate) && !empty($endDate)) {
                $query->where($column, '>=', $startDate)
                      ->where($column, '<=', $endDate . ' 23:59:59');
            }
        };

        // Always query live DB (invalidateDashboardCache is a no-op in zero-cache mode)
        $useShortCache = false;
        $scopeCacheKey = md5($warehouse . '|' . implode(',', $allowedOutlets) . '|' . ($canViewCost ? '1' : '0'));

        // 1. Base query scopes
        $salesBaseQuery = DB::table('sales');
        $this->applyActiveDocumentFilter($salesBaseQuery, 'sales.status');
        $purchBaseQuery = DB::table('purchases')->whereIn('purchases.status', ['Received', 'Completed']);
        $this->excludeOpeningStockPurchases($purchBaseQuery, 'purchases.payment_type');
        $expBaseQuery = DB::table('expenses')
            ->where(function ($q) {
                $q->whereNull('payment_status')
                  ->orWhereNotIn('payment_status', [
                      'Pending', 'pending', 'PENDING',
                      'Draft', 'draft', 'DRAFT',
                      'Voided', 'voided', 'VOIDED',
                      'Cancelled', 'cancelled', 'CANCELLED',
                  ]);
            });

        $this->applyWarehouseFilter($salesBaseQuery, $warehouse, $warehouseId, 'sales.warehouse_name', 'sales.warehouse_id');
        $this->applyWarehouseFilter($purchBaseQuery, $warehouse, $warehouseId, 'purchases.warehouse_name', 'purchases.warehouse_id');
        $this->applyWarehouseFilter($expBaseQuery, $warehouse, $warehouseId, 'expenses.warehouse_name', 'expenses.warehouse_id');
        if (!empty($allowedOutlets)) {
            $salesBaseQuery->whereIn('sales.warehouse_id', $allowedOutlets);
            $purchBaseQuery->whereIn('purchases.warehouse_id', $allowedOutlets);
            $expBaseQuery->whereIn('expenses.warehouse_id', $allowedOutlets);
        }

        // Helper for scoped sale_items tax subquery (strictly matches ReportController::profitLoss IFRS/GAAP revenue)
        $buildSiTaxSub = function (?string $fromDate, ?string $toDateEnd) use ($warehouse, $warehouseId, $allowedOutlets) {
            $q = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->where('si.tax', '>', 0);
            $this->applyWarehouseFilter($q, $warehouse, $warehouseId, 's.warehouse_name', 's.warehouse_id');
            if (!empty($allowedOutlets)) {
                $q->whereIn('s.warehouse_id', $allowedOutlets);
            }
            if (!empty($fromDate) && !empty($toDateEnd)) {
                $q->where('s.date', '>=', $fromDate)->where('s.date', '<=', $toDateEnd);
            }
            return $q->select('si.sale_id', DB::raw('COALESCE(SUM(si.tax), 0) as item_tax_sum'))
                ->groupBy('si.sale_id');
        };

        $plSalesTaxSql = 'COALESCE(sales.tax_amount, 0) + COALESCE(sit.item_tax_sum, 0) + COALESCE(sales.shipping, 0)';
        $plNetSaleSql = "CASE WHEN (sales.grand_total - ({$plSalesTaxSql})) < 0 THEN 0 ELSE (sales.grand_total - ({$plSalesTaxSql})) END";
        $srNetRevSql = 'CASE WHEN (sr.grand_total - COALESCE(sr.tax_amount, 0) - COALESCE(sr.shipping, 0)) < 0 THEN 0 ELSE (sr.grand_total - COALESCE(sr.tax_amount, 0) - COALESCE(sr.shipping, 0)) END';
        $cogsFallbackExpr = 'COALESCE(SUM(COALESCE(NULLIF(sale_items.total_cogs, 0), COALESCE(NULLIF(sale_items.base_quantity, 0), COALESCE(sale_items.quantity, 1) * COALESCE(NULLIF(sale_items.conversion_rate, 0), 1)) * COALESCE(NULLIF(sale_items.base_unit_cost, 0), NULLIF(product_variants.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0))), 0)';

        // 2. Single-Pass Consolidated Period Sales Aggregation (Tax-Exclusive & Ex-Shipping matching P&L)
        $periodSiTaxSub = $buildSiTaxSub($startDate, !empty($endDate) ? $endDate . ' 23:59:59' : null);
        $periodSalesQuery = (clone $salesBaseQuery)
            ->leftJoinSub($periodSiTaxSub, 'sit', 'sit.sale_id', '=', 'sales.id');
        $applyDateScope($periodSalesQuery, 'sales.date');
        $periodSalesAgg = $periodSalesQuery->selectRaw("
            COALESCE(SUM({$plNetSaleSql}), 0) as total_sales,
            COALESCE(SUM(sales.grand_total), 0) as gross_invoiced_sales,
            COALESCE(SUM(COALESCE(sales.tax_amount, 0) + COALESCE(sit.item_tax_sum, 0)), 0) as tax_collected,
            COALESCE(SUM(sales.paid_amount), 0) as paid_amount,
            COALESCE(SUM(sales.due_amount), 0) as due_amount,
            COALESCE(SUM(CASE WHEN sales.total_cogs > 0 THEN sales.total_cogs ELSE 0 END), 0) as total_cogs,
            COALESCE(SUM(CASE WHEN sales.total_cogs IS NULL OR sales.total_cogs <= 0 THEN 1 ELSE 0 END), 0) as missing_cogs_count,
            COUNT(sales.id) as orders_count
        ")->first();

        $totalSales = round((float)($periodSalesAgg->total_sales ?? 0), 2);
        $grossInvoicedSales = round((float)($periodSalesAgg->gross_invoiced_sales ?? 0), 2);
        $taxCollected = round((float)($periodSalesAgg->tax_collected ?? 0), 2);
        $periodPaid = round((float)($periodSalesAgg->paid_amount ?? 0), 2);
        $periodDue = round((float)($periodSalesAgg->due_amount ?? 0), 2);
        $ordersCount = (int)($periodSalesAgg->orders_count ?? 0);
        $totalCogs = (float)($periodSalesAgg->total_cogs ?? 0);
        $missingPeriodCogsCount = (int)($periodSalesAgg->missing_cogs_count ?? 0);

        // Real-time Pulse Metrics (Today vs Yesterday, Tax-Exclusive & Ex-Shipping matching P&L)
        $pulseStart = min($yesterdayStart, $todayStart);
        $pulseSiTaxSub = $buildSiTaxSub($pulseStart, $todayEnd);
        $pulseSalesAgg = (clone $salesBaseQuery)
            ->leftJoinSub($pulseSiTaxSub, 'sit', 'sit.sale_id', '=', 'sales.id')
            ->where('sales.date', '>=', $pulseStart)
            ->where('sales.date', '<=', $todayEnd)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN sales.date >= ? AND sales.date <= ? THEN {$plNetSaleSql} ELSE 0 END), 0) as today_sales,
                COALESCE(SUM(CASE WHEN sales.date >= ? AND sales.date <= ? THEN 1 ELSE 0 END), 0) as today_sales_count,
                COALESCE(SUM(CASE WHEN sales.date >= ? AND sales.date <= ? AND sales.total_cogs > 0 THEN sales.total_cogs ELSE 0 END), 0) as today_cogs,
                COALESCE(SUM(CASE WHEN sales.date >= ? AND sales.date <= ? AND (sales.total_cogs IS NULL OR sales.total_cogs <= 0) THEN 1 ELSE 0 END), 0) as today_missing_cogs,
                COALESCE(SUM(CASE WHEN sales.date >= ? AND sales.date <= ? THEN {$plNetSaleSql} ELSE 0 END), 0) as yesterday_sales
            ", [
                $todayStart, $todayEnd,
                $todayStart, $todayEnd,
                $todayStart, $todayEnd,
                $todayStart, $todayEnd,
                $yesterdayStart, $yesterdayEnd,
            ])->first();

        $todayGrossSales = round((float)($pulseSalesAgg->today_sales ?? 0), 2);
        $todaySalesCount = (int)($pulseSalesAgg->today_sales_count ?? 0);
        $todayCogs = (float)($pulseSalesAgg->today_cogs ?? 0);
        $todayMissingCogsCount = (int)($pulseSalesAgg->today_missing_cogs ?? 0);
        $yesterdaySales = round((float)($pulseSalesAgg->yesterday_sales ?? 0), 2);

        // Sales Returns & Returned-Goods COGS (Tax-Exclusive & Ex-Shipping matching P&L, with unlayered catalog COGS + line-tax support)
        $totalSaleReturns = 0.0;
        $totalReturnedCogs = 0.0;
        $todaySaleReturns = 0.0;
        $todayReturnedCogs = 0.0;
        $yesterdaySaleReturns = 0.0;
        try {
            $srPeriod = $this->computeSaleReturnAdjustments($startDate, $endDate, $warehouse, $warehouseId, $allowedOutlets);
            $totalSaleReturns = $srPeriod['returns_total'];
            $taxCollected = round(max(0.0, $taxCollected - $srPeriod['returns_tax']), 2);
            $totalReturnedCogs = $srPeriod['returned_cogs'];

            $srToday = $this->computeSaleReturnAdjustments($todayStart, $todayEnd, $warehouse, $warehouseId, $allowedOutlets);
            $todaySaleReturns = $srToday['returns_total'];
            $todayReturnedCogs = $srToday['returned_cogs'];

            $srYesterday = $this->computeSaleReturnAdjustments($yesterdayStart, $yesterdayEnd, $warehouse, $warehouseId, $allowedOutlets);
            $yesterdaySaleReturns = $srYesterday['returns_total'];
        } catch (\Throwable $e) {}

        $netSales = round($totalSales - $totalSaleReturns, 2);
        $todaySales = round($todayGrossSales - $todaySaleReturns, 2);
        $yesterdayNetSales = round($yesterdaySales - $yesterdaySaleReturns, 2);
        $salesGrowthPct = $yesterdayNetSales > 0
            ? round((($todaySales - $yesterdayNetSales) / $yesterdayNetSales) * 100, 1)
            : ($todaySales > 0 ? 100.0 : 0.0);
        $averageOrderValue = $ordersCount > 0 ? round($netSales / $ordersCount, 2) : 0.0;
        $cashRatioPct = $grossInvoicedSales > 0 ? round(($periodPaid / $grossInvoicedSales) * 100, 1) : 0.0;

        // 3. Consolidated Purchases Aggregation via Date-Scoped Tax Subquery (zero full-table purchase_items scan)
        $buildPurchTaxSub = function (?string $fromDate, ?string $toDateEnd) use ($warehouse, $warehouseId, $allowedOutlets) {
            $q = DB::table('purchase_items as pi')
                ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
                ->where('p.is_tax_recoverable', 1)
                ->where('pi.tax', '>', 0);
            $this->applyWarehouseFilter($q, $warehouse, $warehouseId, 'p.warehouse_name', 'p.warehouse_id');
            if (!empty($allowedOutlets)) {
                $q->whereIn('p.warehouse_id', $allowedOutlets);
            }
            if (!empty($fromDate) && !empty($toDateEnd)) {
                $q->where('p.date', '>=', $fromDate)->where('p.date', '<=', $toDateEnd);
            }
            return $q->select('pi.purchase_id', DB::raw('COALESCE(SUM(pi.tax), 0) as item_tax'))
                ->groupBy('pi.purchase_id');
        };

        $netPurchJoinedSql = 'CASE WHEN COALESCE(purchases.is_tax_recoverable, 0) = 1 THEN CASE WHEN (purchases.grand_total - COALESCE(purchases.tax_amount, 0) - COALESCE(pt.item_tax, 0)) < 0 THEN 0 ELSE (purchases.grand_total - COALESCE(purchases.tax_amount, 0) - COALESCE(pt.item_tax, 0)) END ELSE purchases.grand_total END';

        $periodPurchTaxSub = $buildPurchTaxSub($startDate, !empty($endDate) ? $endDate . ' 23:59:59' : null);
        $periodPurchQuery = (clone $purchBaseQuery)->leftJoinSub($periodPurchTaxSub, 'pt', 'pt.purchase_id', '=', 'purchases.id');
        $applyDateScope($periodPurchQuery, 'purchases.date');
        $periodPurchAgg = $periodPurchQuery->selectRaw("
            COALESCE(SUM({$netPurchJoinedSql}), 0) as total_purchases,
            COALESCE(SUM(purchases.paid_amount), 0) as paid_amount,
            COALESCE(SUM(purchases.due_amount), 0) as due_amount,
            COUNT(*) as purchases_count
        ")->first();

        $totalPurchases = round((float)($periodPurchAgg->total_purchases ?? 0), 2);
        $periodPurchDue = round((float)($periodPurchAgg->due_amount ?? 0), 2);

        $todayPurchTaxSub = $buildPurchTaxSub($todayStart, $todayEnd);
        $todayPurchases = round((float)(clone $purchBaseQuery)
            ->leftJoinSub($todayPurchTaxSub, 'pt', 'pt.purchase_id', '=', 'purchases.id')
            ->where('purchases.date', '>=', $todayStart)
            ->where('purchases.date', '<=', $todayEnd)
            ->selectRaw("COALESCE(SUM({$netPurchJoinedSql}), 0) as total")
            ->value('total'), 2);

        $purchaseAccounting = $this->accounting();
        $totalPurchaseReturns = 0.0;
        $totalPurchaseReturnVariance = 0.0;
        $todayPurchaseReturnVariance = 0.0;
        try {
            $prBaseQuery = DB::table('purchase_returns')->whereIn('status', ['Completed', 'Received']);
            $this->applyWarehouseFilter($prBaseQuery, $warehouse, $warehouseId, 'warehouse_name', 'warehouse_id');
            if (!empty($allowedOutlets)) {
                $prBaseQuery->whereIn('warehouse_id', $allowedOutlets);
            }
            $prPeriodQuery = clone $prBaseQuery;
            $applyDateScope($prPeriodQuery, 'date');
            $prAgg = (clone $prPeriodQuery)->selectRaw('
                COALESCE(SUM(CASE WHEN COALESCE(is_tax_recoverable, 0) != 1 THEN grand_total ELSE 0 END), 0) as non_recov_total,
                COALESCE(SUM(CASE WHEN COALESCE(is_tax_recoverable, 0) = 1 THEN 1 ELSE 0 END), 0) as recov_count,
                COALESCE(SUM(COALESCE(cost_variance, 0)), 0) as total_variance
            ')->first();
            $totalPurchaseReturns = (float)($prAgg->non_recov_total ?? 0);
            $totalPurchaseReturnVariance = round((float)($prAgg->total_variance ?? 0), 2);

            if ((int)($prAgg->recov_count ?? 0) > 0) {
                $recovPrRows = (clone $prPeriodQuery)
                    ->where('is_tax_recoverable', 1)
                    ->select(['grand_total', 'tax_amount', 'is_tax_recoverable', 'items_payload'])
                    ->get();
                foreach ($recovPrRows as $prRow) {
                    $totalPurchaseReturns += $purchaseAccounting->netPurchaseReturnAmount($prRow);
                }
            }
            $totalPurchaseReturns = round($totalPurchaseReturns, 2);

            $todayPurchaseReturnVariance = round((float)(clone $prBaseQuery)
                ->where('date', '>=', $todayStart)
                ->where('date', '<=', $todayEnd)
                ->sum('cost_variance'), 2);
        } catch (\Throwable $e) {}

        $netPurchases = round($totalPurchases - $totalPurchaseReturns, 2);

        // 4. Consolidated Expenses Aggregation (Period + Today)
        $periodExpQuery = clone $expBaseQuery;
        $applyDateScope($periodExpQuery, 'date');
        $totalExpenses = round((float)$periodExpQuery->sum('amount'), 2);

        $todayExpenses = round((float)(clone $expBaseQuery)
            ->where('date', '>=', $todayStart)
            ->where('date', '<=', $todayEnd)
            ->sum('amount'), 2);

        // 5. Accurate Partial-Missing & Mixed-Invoice COGS Fallback (Period + Today, matching ReportController::profitLoss)
        try {
            $missingPeriodRes = $this->computeMixedInvoiceMissingCogs($startDate, $endDate, $warehouse, $warehouseId, $allowedOutlets);
            $totalCogs += (float)($missingPeriodRes['total'] ?? 0);
        } catch (\Throwable $e) {}

        try {
            $missingTodayRes = $this->computeMixedInvoiceMissingCogs($todayStart, $todayEnd, $warehouse, $warehouseId, $allowedOutlets);
            $todayCogs += (float)($missingTodayRes['total'] ?? 0);
        } catch (\Throwable $e) {}

        // 4b. Operating Stock Adjustments
        $totalAdjustmentLoss = 0.0;
        $totalAdjustmentGain = 0.0;
        $todayAdjustmentNet = 0.0;
        $nonPnlAdjustments = 0.0;
        try {
            $effAdjTypeSql = $this->effectiveAdjustmentTypeSql();
            $opAdjBase = DB::table('stock_adjustment_items')
                ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id');
            $this->applyWarehouseFilter($opAdjBase, $warehouse, $warehouseId, 'stock_adjustments.warehouse_name', 'stock_adjustments.warehouse_id');
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

            if (!$compact) {
                $nonPnlQuery = DB::table('stock_adjustment_items')
                    ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id');
                $this->applyWarehouseFilter($nonPnlQuery, $warehouse, $warehouseId, 'stock_adjustments.warehouse_name', 'stock_adjustments.warehouse_id');
                if (!empty($allowedOutlets)) {
                    $nonPnlQuery->whereIn('stock_adjustments.warehouse_id', $allowedOutlets);
                }
                $this->applyOperatingAdjustmentScope($nonPnlQuery, false);
                $applyDateScope($nonPnlQuery, 'stock_adjustments.date');
                $nonPnlAdjustments = (float)$nonPnlQuery->selectRaw("
                    COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'addition' THEN stock_adjustment_items.subtotal ELSE -stock_adjustment_items.subtotal END), 0) as net_cap
                ")->value('net_cap');
            }
        } catch (\Throwable $e) {}

        $totalCogs = round($totalCogs - $totalReturnedCogs, 2);
        $todayCogs = round($todayCogs - $todayReturnedCogs, 2);
        $grossProfit = round($netSales - $totalCogs, 2);
        $netProfit = round($grossProfit - $totalExpenses - $totalAdjustmentLoss + $totalAdjustmentGain + $totalPurchaseReturnVariance, 2);
        $profitMarginPct = $netSales > 0 ? round(($netProfit / $netSales) * 100, 2) : 0.0;
        $todayGrossProfit = round($todaySales - $todayCogs, 2);
        $todayProfit = round($todayGrossProfit - $todayExpenses + $todayAdjustmentNet + $todayPurchaseReturnVariance, 2);

        // 6. Balance Sheet Snapshot (AR, AP, Shelf Inventory Cost Valuation — independent of date_filter)
        $computeBalanceSheet = function () use ($warehouse, $warehouseId, $allowedOutlets, $canViewCost, $purchaseAccounting): array {
            $arStats = $this->computePerCustomerNetAr($warehouse, $warehouseId, $allowedOutlets);
            $customerDue = (float)$arStats['net_ar'];

            $supplierDue = $canViewCost
                ? $purchaseAccounting->computeNetSupplierPayable($warehouse, !empty($allowedOutlets) ? $allowedOutlets : null)
                : 0.0;

            $inventoryValuationCost = 0.0;
            $fifoBatchValuation = 0.0;
            $masterInventoryValuation = 0.0;
            $totalInStockUnits = 0.0;

            if ($canViewCost) {
                // includeInTransit=false: Dashboard displays shelf inventory valuation only (skips 266ms stock_transfer_items scan)
                $valuation = app(InventoryReconciliationService::class)->computeValuationSummary(
                    $warehouseId,
                    !empty($allowedOutlets) ? $allowedOutlets : null,
                    null,
                    null,
                    false
                );
                $inventoryValuationCost = round((float)$valuation['effective_valuation'], 2);
                $fifoBatchValuation = round((float)$valuation['fifo_batch_valuation'], 2);
                $masterInventoryValuation = round((float)$valuation['master_valuation'], 2);
                $totalInStockUnits = round((float)($valuation['total_book_qty'] ?? 0), 4);
            }

            return [
                'customer_due' => $customerDue,
                'supplier_due' => $supplierDue,
                'inventory_valuation_cost' => $inventoryValuationCost,
                'fifo_batch_valuation' => $fifoBatchValuation,
                'master_inventory_valuation' => $masterInventoryValuation,
                'total_in_stock_units' => $totalInStockUnits,
            ];
        };

        $bsCacheKey = "dash_bs_v2_{$scopeCacheKey}";
        $balanceSheet = $useShortCache
            ? Cache::remember($bsCacheKey, 20, $computeBalanceSheet)
            : $computeBalanceSheet();

        $customerDue = $balanceSheet['customer_due'];
        $supplierDue = $balanceSheet['supplier_due'];
        $inventoryValuationCost = $balanceSheet['inventory_valuation_cost'];
        $fifoBatchValuation = $balanceSheet['fifo_batch_valuation'];
        $masterInventoryValuation = $balanceSheet['master_inventory_valuation'];
        $totalInStockUnits = $balanceSheet['total_in_stock_units'];

        // Optional extra dashboard lists (skipped in compact=1 mode used by DashboardScreen -> saves ~700ms!)
        $totalProducts = 0;
        $totalVariants = 0;
        $lowStockCount = 0;
        $outOfStockCount = 0;
        $totalCustomers = 0;
        $totalSuppliers = 0;
        $totalOutlets = 1;
        $lowStockProducts = collect();
        $isBranchScoped = $warehouseId !== null || !empty($allowedOutlets);

        if (!$compact) {
            $totalProducts = (int) DB::table('products')->whereNull('deleted_at')->count();

            $singleLowStockQuery = DB::table('products as p')
                ->whereNull('p.deleted_at')
                ->where(function ($q) {
                    $q->where('p.has_variants', false)->orWhereNull('p.has_variants');
                });

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
            try {
                $totalOutlets = (int)(DB::table('outlets')->count() ?: 1);
            } catch (\Throwable $e) {}
        }

        // 7. Monthly 12-Month Trends for Selected Year (IFRS/GAAP Tax-Exclusive, Partial-COGS Fallback, Live DB)
        $computeMonthlyTrends = function () use (
            $year, $warehouse, $warehouseId, $allowedOutlets, $canViewCost,
            $salesBaseQuery, $purchBaseQuery, $expBaseQuery,
            $buildSiTaxSub, $buildPurchTaxSub,
            $plNetSaleSql, $netPurchJoinedSql,
            $purchaseAccounting
        ): array {
            $monthlySales = array_fill(0, 12, 0.0);
            $monthlyPurchases = array_fill(0, 12, 0.0);
            $monthlyExpenses = array_fill(0, 12, 0.0);
            $monthlyProfit = array_fill(0, 12, 0.0);
            $monthlyCogs = array_fill(0, 12, 0.0);

            // Use YYYY-01-01 (10-char) lower bound so date-only rows on Jan 1 are never missed
            $yearStart = "{$year}-01-01";
            $yearEnd = "{$year}-12-31 23:59:59";

            $isSqlite = DB::connection()->getDriverName() === 'sqlite';
            $monthExprSales = $isSqlite ? "CAST(strftime('%m', sales.date) AS integer)" : 'MONTH(sales.date)';
            $monthExprGeneric = $isSqlite ? "CAST(strftime('%m', date) AS integer)" : 'MONTH(date)';

            $yearSiTaxSub = $buildSiTaxSub($yearStart, $yearEnd);
            $salesMonthlyData = (clone $salesBaseQuery)
                ->leftJoinSub($yearSiTaxSub, 'sit', 'sit.sale_id', '=', 'sales.id')
                ->where('sales.date', '>=', $yearStart)
                ->where('sales.date', '<=', $yearEnd)
                ->selectRaw("
                    {$monthExprSales} as m,
                    COALESCE(SUM({$plNetSaleSql}), 0) as total,
                    COALESCE(SUM(CASE WHEN sales.total_cogs > 0 THEN sales.total_cogs ELSE 0 END), 0) as total_cogs
                ")
                ->groupBy(DB::raw($monthExprSales))
                ->get();

            foreach ($salesMonthlyData as $row) {
                $mIdx = (int)$row->m;
                if ($mIdx >= 1 && $mIdx <= 12) {
                    $monthlySales[$mIdx - 1] = round((float)$row->total, 2);
                    $monthlyCogs[$mIdx - 1] = round((float)($row->total_cogs ?? 0), 2);
                }
            }

            if ($canViewCost) {
                try {
                    $missingMonthly = $this->computeMixedInvoiceMissingCogs(
                        $yearStart,
                        $yearEnd,
                        $warehouse,
                        $warehouseId,
                        $allowedOutlets,
                        false,
                        true
                    );
                    foreach ($missingMonthly['by_month'] ?? [] as $mIdx => $cogsSum) {
                        if ($mIdx >= 1 && $mIdx <= 12) {
                            $monthlyCogs[$mIdx - 1] = round($monthlyCogs[$mIdx - 1] + (float)$cogsSum, 2);
                        }
                    }
                } catch (\Throwable $e) {}
            }

            // Deduct monthly sale returns & returned COGS (Tax-Exclusive & Ex-Shipping, with catalog COGS + line-tax fallback)
            try {
                $srYearAdj = $this->computeSaleReturnAdjustments(
                    $yearStart,
                    $yearEnd,
                    $warehouse,
                    $warehouseId,
                    $allowedOutlets,
                    false,
                    true
                );
                foreach ($srYearAdj['by_month'] ?? [] as $mIdx => $srM) {
                    if ($mIdx >= 1 && $mIdx <= 12) {
                        $monthlySales[$mIdx - 1] = round($monthlySales[$mIdx - 1] - (float)$srM['returns_total'], 2);
                        $monthlyCogs[$mIdx - 1] = round($monthlyCogs[$mIdx - 1] - (float)$srM['returned_cogs'], 2);
                    }
                }
            } catch (\Throwable $e) {}

            // Group purchases & purchase returns by month (only needed when canViewCost)
            $monthlyPrVariance = array_fill(0, 12, 0.0);
            if ($canViewCost) {
                $yearPurchTaxSub = $buildPurchTaxSub($yearStart, $yearEnd);
                $purchMonthExpr = $isSqlite ? "CAST(strftime('%m', purchases.date) AS integer)" : 'MONTH(purchases.date)';
                $purchasesByMonth = (clone $purchBaseQuery)
                    ->leftJoinSub($yearPurchTaxSub, 'pt', 'pt.purchase_id', '=', 'purchases.id')
                    ->where('purchases.date', '>=', $yearStart)
                    ->where('purchases.date', '<=', $yearEnd)
                    ->selectRaw("{$purchMonthExpr} as m, COALESCE(SUM({$netPurchJoinedSql}), 0) as total")
                    ->groupBy(DB::raw($purchMonthExpr))
                    ->pluck('total', 'm');

                foreach ($purchasesByMonth as $m => $tot) {
                    $mIdx = (int)$m;
                    if ($mIdx >= 1 && $mIdx <= 12) {
                        $monthlyPurchases[$mIdx - 1] = round((float)$tot, 2);
                    }
                }

                try {
                    $prYearBase = DB::table('purchase_returns')
                        ->whereIn('status', ['Completed', 'Received'])
                        ->where('date', '>=', $yearStart)
                        ->where('date', '<=', $yearEnd);
                    $this->applyWarehouseFilter($prYearBase, $warehouse, $warehouseId, 'warehouse_name', 'warehouse_id');
                    if (!empty($allowedOutlets)) {
                        $prYearBase->whereIn('warehouse_id', $allowedOutlets);
                    }

                    $prMonthAggs = (clone $prYearBase)
                        ->selectRaw("
                            {$monthExprGeneric} as m,
                            COALESCE(SUM(CASE WHEN COALESCE(is_tax_recoverable, 0) != 1 THEN grand_total ELSE 0 END), 0) as non_recov,
                            COALESCE(SUM(CASE WHEN COALESCE(is_tax_recoverable, 0) = 1 THEN 1 ELSE 0 END), 0) as recov_cnt,
                            COALESCE(SUM(COALESCE(cost_variance, 0)), 0) as var_sum
                        ")
                        ->groupBy(DB::raw($monthExprGeneric))
                        ->get();

                    $hasRecovPrYear = false;
                    foreach ($prMonthAggs as $pmRow) {
                        $mIdx = (int)$pmRow->m;
                        if ($mIdx >= 1 && $mIdx <= 12) {
                            $monthlyPurchases[$mIdx - 1] = max(0.0, round($monthlyPurchases[$mIdx - 1] - (float)$pmRow->non_recov, 2));
                            $monthlyPrVariance[$mIdx - 1] = round($monthlyPrVariance[$mIdx - 1] + (float)$pmRow->var_sum, 2);
                            if ((int)($pmRow->recov_cnt ?? 0) > 0) {
                                $hasRecovPrYear = true;
                            }
                        }
                    }

                    if ($hasRecovPrYear) {
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
                    }
                } catch (\Throwable $e) {}
            }

            // Group expenses by month
            $expensesByMonth = (clone $expBaseQuery)
                ->where('date', '>=', $yearStart)
                ->where('date', '<=', $yearEnd)
                ->selectRaw("{$monthExprGeneric} as m, COALESCE(SUM(amount), 0) as total")
                ->groupBy(DB::raw($monthExprGeneric))
                ->pluck('total', 'm');

            foreach ($expensesByMonth as $m => $tot) {
                $mIdx = (int)$m;
                if ($mIdx >= 1 && $mIdx <= 12) {
                    $monthlyExpenses[$mIdx - 1] = round((float)$tot, 2);
                }
            }

            // Group monthly operating stock adjustments (only needed for profit when canViewCost)
            $monthlyAdjNet = array_fill(0, 12, 0.0);
            if ($canViewCost) {
                try {
                    $effAdjTypeSqlM = $this->effectiveAdjustmentTypeSql();
                    $adjMonthBase = DB::table('stock_adjustment_items')
                        ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id')
                        ->where('stock_adjustments.date', '>=', $yearStart)
                        ->where('stock_adjustments.date', '<=', $yearEnd);
                    $this->applyWarehouseFilter($adjMonthBase, $warehouse, $warehouseId, 'stock_adjustments.warehouse_name', 'stock_adjustments.warehouse_id');
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
            }

            return [
                'year' => $year,
                'months' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                'sales' => $monthlySales,
                'purchases' => $canViewCost ? $monthlyPurchases : array_fill(0, 12, 0.0),
                'expenses' => $monthlyExpenses,
                'net_profit' => $canViewCost ? $monthlyProfit : array_fill(0, 12, 0.0),
            ];
        };

        $trendCacheKey = "dash_trends_v2_{$year}_{$scopeCacheKey}";
        $monthlyTrends = $useShortCache
            ? Cache::remember($trendCacheKey, 20, $computeMonthlyTrends)
            : $computeMonthlyTrends();

        // 8. Payment Methods Tender Mix (aggregates actual split sale_payments + legacy paid sales + unpaid credit)
        $paymentMethods = [];
        if (!$compact) {
            $pmBuckets = [];
            try {
                // a) Actual collected tender legs from sale_payments for active sales in period
                $spQuery = DB::table('sale_payments as sp')
                    ->join('sales', 'sales.id', '=', 'sp.sale_id');
                $this->applyActiveDocumentFilter($spQuery, 'sales.status');
                $this->applyWarehouseFilter($spQuery, $warehouse, $warehouseId, 'sales.warehouse_name', 'sales.warehouse_id');
                if (!empty($allowedOutlets)) {
                    $spQuery->whereIn('sales.warehouse_id', $allowedOutlets);
                }
                $applyDateScope($spQuery, 'sales.date');
                $spRows = $spQuery
                    ->select('sp.payment_type', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(sp.amount), 0) as amount'))
                    ->groupBy('sp.payment_type')
                    ->get();

                foreach ($spRows as $r) {
                    $amt = round((float)($r->amount ?? 0), 2);
                    if ($amt <= 0) {
                        continue;
                    }
                    $type = trim((string)($r->payment_type ?? '')) !== '' ? trim((string)$r->payment_type) : 'Cash';
                    if (!isset($pmBuckets[$type])) {
                        $pmBuckets[$type] = ['amount' => 0.0, 'count' => 0];
                    }
                    $pmBuckets[$type]['amount'] += $amt;
                    $pmBuckets[$type]['count'] += (int)$r->count;
                }

                // b) Legacy paid sales that lack sale_payments rows
                $legacyPaidQ = (clone $salesBaseQuery)
                    ->where('sales.paid_amount', '>', 0)
                    ->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('sale_payments as sp2')
                            ->whereColumn('sp2.sale_id', 'sales.id');
                    });
                $applyDateScope($legacyPaidQ, 'sales.date');
                $legacyRows = $legacyPaidQ
                    ->select('sales.payment_type', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(sales.paid_amount), 0) as amount'))
                    ->groupBy('sales.payment_type')
                    ->get();

                foreach ($legacyRows as $r) {
                    $amt = round((float)($r->amount ?? 0), 2);
                    if ($amt <= 0) {
                        continue;
                    }
                    $type = trim((string)($r->payment_type ?? '')) !== '' ? trim((string)$r->payment_type) : 'Cash';
                    if (!isset($pmBuckets[$type])) {
                        $pmBuckets[$type] = ['amount' => 0.0, 'count' => 0];
                    }
                    $pmBuckets[$type]['amount'] += $amt;
                    $pmBuckets[$type]['count'] += (int)$r->count;
                }

                // c) Unpaid receivables on active sales in period
                $unpaidAgg = (clone $salesBaseQuery);
                $applyDateScope($unpaidAgg, 'sales.date');
                $unpaidRow = $unpaidAgg->where('sales.due_amount', '>', 0)
                    ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(sales.due_amount), 0) as due_sum')
                    ->first();
                $unpaidAmt = round((float)($unpaidRow->due_sum ?? 0), 2);
                if ($unpaidAmt > 0) {
                    if (!isset($pmBuckets['Credit / Unpaid'])) {
                        $pmBuckets['Credit / Unpaid'] = ['amount' => 0.0, 'count' => 0];
                    }
                    $pmBuckets['Credit / Unpaid']['amount'] += $unpaidAmt;
                    $pmBuckets['Credit / Unpaid']['count'] += (int)($unpaidRow->cnt ?? 0);
                }
            } catch (\Throwable $e) {}

            uasort($pmBuckets, fn($a, $b) => $b['amount'] <=> $a['amount']);
            $pTotal = array_sum(array_column($pmBuckets, 'amount'));
            if ($pTotal <= 0) {
                $pTotal = 1.0;
            }
            foreach ($pmBuckets as $type => $info) {
                $amt = round((float)$info['amount'], 2);
                $paymentMethods[] = [
                    'type' => $type,
                    'amount' => $amt,
                    'count' => (int)$info['count'],
                    'percentage' => round(($amt / $pTotal) * 100, 1),
                ];
            }
        }

        // 9. Top Selling Products in Period (uniformly applying active filter + allowedOutlets)
        $topProducts = [];
        try {
            $topProductAggQuery = DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id');
            $this->applyActiveDocumentFilter($topProductAggQuery, 'sales.status');
            $this->applyWarehouseFilter($topProductAggQuery, $warehouse, $warehouseId, 'sales.warehouse_name', 'sales.warehouse_id');
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

        // 10 & 11. Top Category Sales Share & Recent Sales (skipped in compact=1 mode)
        $categorySales = [];
        $recentSales = [];
        if (!$compact) {
            try {
                $catQuery = DB::table('sale_items')
                    ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                    ->leftJoin('products', 'sale_items.product_id', '=', 'products.id');
                $this->applyActiveDocumentFilter($catQuery, 'sales.status');
                $this->applyWarehouseFilter($catQuery, $warehouse, $warehouseId, 'sales.warehouse_name', 'sales.warehouse_id');
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

            $recentSales = (clone $salesBaseQuery)
                ->select([
                    'sales.id',
                    'sales.reference_no',
                    'sales.customer_name',
                    'sales.warehouse_name',
                    'sales.date',
                    'sales.grand_total',
                    'sales.paid_amount',
                    'sales.due_amount',
                    'sales.payment_type',
                    'sales.payment_status',
                    'sales.status',
                ])
                ->orderBy('sales.id', 'desc')
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
        }

        $responsePayload = [
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'data' => [
                'cost_masked' => !$canViewCost,
                'date_filter' => $dateFilter,
                'start_date' => $startDate,
                'end_date' => $endDate,

                'total_sales' => $totalSales,
                'gross_invoiced_sales' => $grossInvoicedSales,
                'tax_collected' => $taxCollected,
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

                'monthly_trends' => $monthlyTrends,

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
                // Keep 10-char YYYY-MM-DD lower bound so both date-only ('YYYY-MM-DD') and datetime ('YYYY-MM-DD HH:MM:SS') rows match
                $startVal = $trimmed;
            } else {
                try {
                    $startVal = \Carbon\Carbon::parse($trimmed)
                        ->setTimezone(config('app.timezone'))
                        ->format('Y-m-d H:i:s');
                    if (str_ends_with($startVal, ' 00:00:00')) {
                        $startVal = substr($startVal, 0, 10);
                    }
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
            $whId = $this->resolveWarehouseIdParam((string)$warehouse);
            if ($whId !== null) {
                $hasNullSaleWh = DB::table('sales')->whereNull('warehouse_id')->limit(1)->exists();
                if ($hasNullSaleWh) {
                    $baseQuery->where(function ($q) use ($warehouse, $whId) {
                        $q->where('warehouse_id', $whId)
                          ->orWhere(function ($legacy) use ($warehouse) {
                              $legacy->whereNull('warehouse_id')->where('warehouse_name', $warehouse);
                          });
                    });
                } else {
                    $baseQuery->where('warehouse_id', $whId);
                }
            } elseif (is_numeric($warehouse)) {
                $baseQuery->where('warehouse_id', -1);
            } else {
                $baseQuery->where('warehouse_name', $warehouse);
            }
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
            $paymentType = (string)$request->input('payment_type');
            $hasSalePaymentsPayload = $this->accounting()->schemaHasColumn('sales', 'payments_payload');
            $baseQuery->where(function ($q) use ($paymentType, $hasSalePaymentsPayload) {
                $q->where('payment_type', $paymentType);
                if ($paymentType !== 'Split Payment') {
                    $q->orWhere(function ($sq) use ($paymentType, $hasSalePaymentsPayload) {
                        $sq->where('payment_type', 'Split Payment')
                           ->where(function ($sub) use ($paymentType, $hasSalePaymentsPayload) {
                               $sub->whereExists(function ($ep) use ($paymentType) {
                                   $ep->select(DB::raw(1))
                                      ->from('sale_payments as sp_f')
                                      ->whereColumn('sp_f.sale_id', 'sales.id')
                                      ->where('sp_f.payment_type', $paymentType);
                               });
                               if ($hasSalePaymentsPayload) {
                                   $sub->orWhere('payments_payload', 'like', '%' . addcslashes($paymentType, '%_\\') . '%');
                               }
                           });
                    });
                }
            });
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

        $hasReturnedCredit = $this->accounting()->schemaHasColumn('sales', 'returned_credit_amount');
        $hasPaymentTypeFilter = $request->filled('payment_type') && $request->input('payment_type') !== 'All';
        $filterPaymentType = $hasPaymentTypeFilter ? (string)$request->input('payment_type') : null;
        $isSingleTenderFilter = $hasPaymentTypeFilter && strcasecmp((string)$filterPaymentType, 'Split Payment') !== 0;

        $totalCount = 0;
        $totalRevenue = 0.0;
        $totalPaid = 0.0;
        $totalDue = 0.0;
        $totalReturnedCredit = 0.0;
        $totalSaleReturns = 0.0;
        if (!$skipSummary && $page === 1) {
            $retCreditExpr = $hasReturnedCredit ? 'COALESCE(SUM(returned_credit_amount), 0)' : '0';
            $totals = (clone $baseQuery)->select(DB::raw("
                COUNT(*) as total_count,
                COALESCE(SUM(grand_total), 0) as total_revenue,
                COALESCE(SUM(paid_amount), 0) as total_paid,
                COALESCE(SUM(due_amount), 0) as total_due,
                {$retCreditExpr} as total_returned_credit
            "))->first();

            $totalCount = (int)($totals->total_count ?? 0);
            $totalRevenue = (float)($totals->total_revenue ?? 0);
            $totalPaid = (float)($totals->total_paid ?? 0);
            $totalDue = (float)($totals->total_due ?? 0);
            $totalReturnedCredit = round((float)($totals->total_returned_credit ?? 0), 2);

            // Under single-tender filtering (e.g. KBZPay), constrain total_paid on split sales to the filtered tender's leg
            if ($isSingleTenderFilter && $totalCount > 0) {
                $matchedIdsSub = (clone $baseQuery)->select('id as s_id');
                $legPaid = $this->accounting()->schemaHasTable('sale_payments')
                    ? (float)DB::table('sale_payments as sp')
                        ->joinSub($matchedIdsSub, 'ms', fn($j) => $j->on('ms.s_id', '=', 'sp.sale_id'))
                        ->where('sp.payment_type', $filterPaymentType)
                        ->sum('sp.amount')
                    : 0.0;
                $legacyNoRowPaid = (float)(clone $baseQuery)
                    ->where('payment_type', $filterPaymentType)
                    ->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('sale_payments as sp_chk')
                            ->whereColumn('sp_chk.sale_id', 'sales.id');
                    })
                    ->sum('paid_amount');
                $totalPaid = round($legPaid + $legacyNoRowPaid, 2);
            }

            // Include all completed Sale Returns (cash, digital, store credit, AR credit offset) in net_revenue
            if ($this->accounting()->schemaHasTable('sale_returns')) {
                $hasNarrowInvoiceFilter = $hasPaymentTypeFilter
                    || ($request->filled('payment_status') && $request->input('payment_status') !== 'All')
                    || ($request->filled('status') && $request->input('status') !== 'All')
                    || $request->filled('search');

                $srNetQ = DB::table('sale_returns');
                $this->applyActiveDocumentFilter($srNetQ, 'status');
                if (!empty($allowedOutlets)) {
                    $srNetQ->whereIn('warehouse_id', $allowedOutlets);
                }
                if ($hasNarrowInvoiceFilter) {
                    $srNetQ->whereIn('sale_id', (clone $baseQuery)->select('id'));
                } else {
                    $this->applyReportDateRange($srNetQ, $startDate, $endDate, 'date');
                    if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
                        $whIdSr = $this->resolveWarehouseIdParam((string)$warehouse);
                        if ($whIdSr !== null) {
                            $srNetQ->where(function ($q) use ($warehouse, $whIdSr) {
                                $q->where('warehouse_id', $whIdSr)
                                  ->orWhere(function ($leg) use ($warehouse) {
                                      $leg->whereNull('warehouse_id')->where('warehouse_name', $warehouse);
                                  });
                            });
                        } elseif (is_numeric($warehouse)) {
                            $srNetQ->where('warehouse_id', -1);
                        } else {
                            $srNetQ->where('warehouse_name', $warehouse);
                        }
                    }
                    if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'All') {
                        $srNetQ->where('warehouse_id', $request->input('warehouse_id'));
                    }
                    if ($request->filled('customer_id') && $request->input('customer_id') !== 'All') {
                        $srNetQ->where('customer_id', $request->input('customer_id'));
                    }
                    if (!empty($userFilter) && $userFilter !== 'All' && $userFilter !== 'all') {
                        $srNetQ->where(function ($q) use ($userFilter) {
                            $q->where('user_name', $userFilter)
                              ->orWhere('created_by', 'like', "%{$userFilter}%");
                        });
                    }
                    if ($request->filled('user_id') && $request->input('user_id') !== 'All') {
                        $srNetQ->where('user_id', $request->input('user_id'));
                    }
                }
                $totalSaleReturns = round((float)$srNetQ->sum('grand_total'), 2);
            }
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
        if ($hasReturnedCredit) {
            $selectCols[] = 'returned_credit_amount';
        }
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

        $availableFilters = [];
        if (!$skipSummary && $page === 1) {
            $userNames = [];
            foreach ($salesItems as $sRow) {
                $raw = trim((string)($sRow->user_name ?: $sRow->created_by));
                if ($raw !== '') {
                    $clean = trim((string)(preg_replace('/\s*\([^)]*\)\s*$/', '', $raw) ?: $raw));
                    if ($clean !== '') {
                        $userNames[strtolower($clean)] = $clean;
                    }
                }
            }
            $distinctCreators = DB::table('sales')
                ->select('user_name', 'created_by')
                ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('warehouse_id', $allowedOutlets))
                ->distinct()
                ->limit(100)
                ->get();
            foreach ($distinctCreators as $uRow) {
                $raw = trim((string)($uRow->user_name ?: $uRow->created_by));
                if ($raw !== '') {
                    $clean = trim((string)(preg_replace('/\s*\([^)]*\)\s*$/', '', $raw) ?: $raw));
                    if ($clean !== '') {
                        $userNames[strtolower($clean)] = $clean;
                    }
                }
            }
            $userList = array_values($userNames);
            natcasesort($userList);

            $availableFilters = [
                'warehouses' => DB::table('outlets')
                    ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('id', $allowedOutlets))
                    ->orderBy('name')
                    ->pluck('name')
                    ->values()
                    ->all(),
                'users' => array_values($userList),
            ];
        }

        return $this->noStoreJson([
            'status' => 'success',
            'available_filters' => $availableFilters,
            'data' => [
                'available_filters' => $availableFilters,
                'total_sales_count' => $totalCount,
                'total_revenue' => $totalRevenue,
                'total_returns' => $totalSaleReturns,
                'total_returned_credit' => $totalReturnedCredit,
                'net_revenue' => round($totalRevenue - max($totalReturnedCredit, $totalSaleReturns), 2),
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
            $supId = $this->resolveSupplierIdParam((string)$supplierName);
            if ($supId !== null) {
                $hasNullSup = DB::table('purchases')->whereNull('supplier_id')->limit(1)->exists();
                if ($hasNullSup) {
                    $baseQuery->where(function ($q) use ($supplierName, $supId) {
                        $q->where('supplier_id', $supId)
                          ->orWhere(function ($legacy) use ($supplierName) {
                              $legacy->whereNull('supplier_id')->where('supplier_name', $supplierName);
                          });
                    });
                } else {
                    $baseQuery->where('supplier_id', $supId);
                }
            } elseif (is_numeric($supplierName)) {
                $baseQuery->where('supplier_id', -1);
            } else {
                $baseQuery->where('supplier_name', $supplierName);
            }
        }

        if ($request->filled('supplier_id') && $request->input('supplier_id') !== 'All') {
            $baseQuery->where('supplier_id', $request->input('supplier_id'));
        }

        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse');
        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $whId = $this->resolveWarehouseIdParam((string)$warehouse);
            if ($whId !== null) {
                $hasNullWh = DB::table('purchases')->whereNull('warehouse_id')->limit(1)->exists();
                if ($hasNullWh) {
                    $baseQuery->where(function ($q) use ($warehouse, $whId) {
                        $q->where('warehouse_id', $whId)
                          ->orWhere(function ($legacy) use ($warehouse) {
                              $legacy->whereNull('warehouse_id')->where('warehouse_name', $warehouse);
                          });
                    });
                } else {
                    $baseQuery->where('warehouse_id', $whId);
                }
            } elseif (is_numeric($warehouse)) {
                $baseQuery->where('warehouse_id', -1);
            } else {
                $baseQuery->where('warehouse_name', $warehouse);
            }
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
            $payStatus = trim((string)$request->input('payment_status'));
            $payLower = strtolower($payStatus);
            if ($payLower === 'due' || $payLower === 'unpaid') {
                $baseQuery->whereIn('payment_status', ['Unpaid', 'Due']);
            } else {
                $baseQuery->where('payment_status', $payStatus);
            }
        }

        if ($request->filled('payment_type') && $request->input('payment_type') !== 'All') {
            $paymentType = (string)$request->input('payment_type');
            if ($paymentType !== 'Opening Stock') {
                $this->excludeOpeningStockPurchases($baseQuery);
            }
            $hasSplitPurchase = ($paymentType !== 'Split Payment' && $paymentType !== 'Opening Stock')
                ? DB::table('purchases')->where('payment_type', 'Split Payment')->limit(1)->exists()
                : false;
            if ($hasSplitPurchase) {
                $baseQuery->where(function ($q) use ($paymentType) {
                    $q->where('payment_type', $paymentType)
                      ->orWhere(function ($sq) use ($paymentType) {
                          $sq->where('payment_type', 'Split Payment')
                             ->where('payments_payload', 'like', '%' . addcslashes($paymentType, '%_\\') . '%');
                      });
                });
            } else {
                $baseQuery->where('payment_type', $paymentType);
            }
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

        $hasReturnedCredit = $accounting->schemaHasColumn('purchases', 'returned_credit_amount');
        $hasPoPayTypeFilter = $request->filled('payment_type') && $request->input('payment_type') !== 'All';
        $poFilterPayType = $hasPoPayTypeFilter ? (string)$request->input('payment_type') : null;
        $isPoSingleTenderFilter = $hasPoPayTypeFilter
            && strcasecmp((string)$poFilterPayType, 'Split Payment') !== 0
            && strcasecmp((string)$poFilterPayType, 'Opening Stock') !== 0;

        $totalCount = 0;
        $totalCost = 0.0;
        $totalPaid = 0.0;
        $totalDue = 0.0;
        $totalReturnedCredit = 0.0;
        $totalPurchaseReturns = 0.0;
        if (!$skipSummary && $page === 1) {
            $retCreditExpr = $hasReturnedCredit ? 'COALESCE(SUM(returned_credit_amount), 0)' : '0';
            $totals = (clone $baseQuery)->select(DB::raw("
                COUNT(*) as total_count,
                COALESCE(SUM(grand_total), 0) as total_cost,
                COALESCE(SUM(paid_amount), 0) as total_paid,
                COALESCE(SUM(due_amount), 0) as total_due,
                {$retCreditExpr} as total_returned_credit
            "))->first();

            $totalCount = (int)($totals->total_count ?? 0);
            $totalCost = (float)($totals->total_cost ?? 0);
            $totalPaid = (float)($totals->total_paid ?? 0);
            $totalDue = (float)($totals->total_due ?? 0);
            $totalReturnedCredit = round((float)($totals->total_returned_credit ?? 0), 2);

            // Under single-tender filtering (e.g. Bank Transfer), constrain total_paid on split POs to the filtered tender leg
            if ($isPoSingleTenderFilter && $totalCount > 0) {
                $directTenderPaid = (float)(clone $baseQuery)
                    ->where('payment_type', $poFilterPayType)
                    ->sum('paid_amount');
                $splitTenderPaid = 0.0;
                foreach ((clone $baseQuery)
                    ->where('payment_type', 'Split Payment')
                    ->select(['payments_payload'])
                    ->cursor() as $spRow) {
                    $legs = is_string($spRow->payments_payload)
                        ? json_decode($spRow->payments_payload, true)
                        : $spRow->payments_payload;
                    if (!is_array($legs)) continue;
                    foreach ($legs as $leg) {
                        if (!is_array($leg)) continue;
                        $lt = trim((string)($leg['payment_type'] ?? $leg['method'] ?? ''));
                        if (strcasecmp($lt, (string)$poFilterPayType) === 0) {
                            $splitTenderPaid += max(0.0, (float)($leg['amount'] ?? 0));
                        }
                    }
                }
                $totalPaid = round($directTenderPaid + $splitTenderPaid, 2);
            }

            // Include all completed Purchase Returns (cash, digital, credit note) in net_cost
            if ($accounting->schemaHasTable('purchase_returns')) {
                $hasNarrowPoFilter = $hasPoPayTypeFilter
                    || ($request->filled('payment_status') && $request->input('payment_status') !== 'All')
                    || ($request->filled('status') && $request->input('status') !== 'All')
                    || $request->filled('search');

                $prNetQ = DB::table('purchase_returns')->whereIn('status', ['Completed', 'Received']);
                if (!empty($allowedOutlets)) {
                    $prNetQ->whereIn('warehouse_id', $allowedOutlets);
                }
                if ($hasNarrowPoFilter) {
                    $prNetQ->whereIn('purchase_reference_no', (clone $baseQuery)->select('reference_no'));
                } else {
                    $this->applyReportDateRange($prNetQ, $startDate ? (string)$startDate : null, $endDate ? (string)$endDate : null, 'date');
                    if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
                        $whIdPr = $this->resolveWarehouseIdParam((string)$warehouse);
                        if ($whIdPr !== null) {
                            $prNetQ->where(function ($q) use ($warehouse, $whIdPr) {
                                $q->where('warehouse_id', $whIdPr)
                                  ->orWhere(function ($leg) use ($warehouse) {
                                      $leg->whereNull('warehouse_id')->where('warehouse_name', $warehouse);
                                  });
                            });
                        } elseif (is_numeric($warehouse)) {
                            $prNetQ->where('warehouse_id', -1);
                        } else {
                            $prNetQ->where('warehouse_name', $warehouse);
                        }
                    }
                    if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'All') {
                        $prNetQ->where('warehouse_id', $request->input('warehouse_id'));
                    }
                    if (!empty($supplierName) && $supplierName !== 'All' && $supplierName !== 'all') {
                        $supIdPr = $this->resolveSupplierIdParam((string)$supplierName);
                        if ($supIdPr !== null) {
                            $prNetQ->where(function ($q) use ($supplierName, $supIdPr) {
                                $q->where('supplier_id', $supIdPr)
                                  ->orWhere(function ($leg) use ($supplierName) {
                                      $leg->whereNull('supplier_id')->where('supplier_name', $supplierName);
                                  });
                            });
                        } elseif (is_numeric($supplierName)) {
                            $prNetQ->where('supplier_id', -1);
                        } else {
                            $prNetQ->where('supplier_name', $supplierName);
                        }
                    }
                    if ($request->filled('supplier_id') && $request->input('supplier_id') !== 'All') {
                        $prNetQ->where('supplier_id', $request->input('supplier_id'));
                    }
                    if (!empty($userFilter) && $userFilter !== 'All' && $userFilter !== 'all') {
                        $prNetQ->where(function ($q) use ($userFilter) {
                            $q->where('user_name', $userFilter)
                              ->orWhere('created_by', 'like', "%{$userFilter}%");
                        });
                    }
                    if ($request->filled('user_id') && $request->input('user_id') !== 'All') {
                        $prNetQ->where('user_id', $request->input('user_id'));
                    }
                }
                $totalPurchaseReturns = round((float)$prNetQ->sum('grand_total'), 2);
            }
        } elseif ($knownTotal !== null) {
            $totalCount = $knownTotal;
        } else {
            $totalCount = (int)(clone $baseQuery)->count();
        }

        $selectCols = [
            'id', 'reference_no', 'supplier_id', 'supplier_name',
            'warehouse_id', 'warehouse_name', 'date', 'status',
            'tax_rate', 'tax_amount', 'discount', 'shipping', 'is_tax_recoverable',
            'grand_total', 'paid_amount', 'due_amount',
            'payment_status', 'payment_type', 'cash_register_id', 'notes',
            'created_by', 'user_id', 'user_name', 'user_role',
            'created_at', 'updated_at',
        ];
        if ($hasReturnedCredit) {
            $selectCols[] = 'returned_credit_amount';
        }
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
            'total_returns' => $totalPurchaseReturns,
            'total_returned_credit' => $totalReturnedCredit,
            'net_cost' => round(max(0.0, $totalCost - max($totalReturnedCredit, $totalPurchaseReturns)), 2),
        ], $canViewCost, ['total_purchases_count']);

        $availableFilters = [];
        if (!$skipSummary && $page === 1) {
            $userNames = [];
            $payTypes = [];
            $supplierNames = [];
            foreach ($slicedRows as $pRow) {
                $rawU = trim((string)($pRow->user_name ?: $pRow->created_by));
                if ($rawU !== '') {
                    $cleanU = trim((string)(preg_replace('/\s*\([^)]*\)\s*$/', '', $rawU) ?: $rawU));
                    if ($cleanU !== '') {
                        $userNames[strtolower($cleanU)] = $cleanU;
                    }
                }
                $pt = trim((string)($pRow->payment_type ?? ''));
                if ($pt !== '' && strcasecmp($pt, 'Opening Stock') !== 0 && strcasecmp($pt, 'Split Payment') !== 0) {
                    $payTypes[strtolower($pt)] = $pt;
                }
                $sn = trim((string)($pRow->supplier_name ?? ''));
                if ($sn !== '') {
                    $supplierNames[strtolower($sn)] = $sn;
                }
            }
            $distinctCreators = DB::table('purchases')
                ->whereNotNull('created_by')
                ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('warehouse_id', $allowedOutlets))
                ->distinct()
                ->limit(100)
                ->pluck('created_by');
            foreach ($distinctCreators as $rawCreator) {
                $rawU = trim((string)$rawCreator);
                if ($rawU !== '') {
                    $cleanU = trim((string)(preg_replace('/\s*\([^)]*\)\s*$/', '', $rawU) ?: $rawU));
                    if ($cleanU !== '') {
                        $userNames[strtolower($cleanU)] = $userNames[strtolower($cleanU)] ?? $cleanU;
                    }
                }
            }
            $distinctPayTypes = DB::table('purchases')
                ->whereNotNull('payment_type')
                ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('warehouse_id', $allowedOutlets))
                ->distinct()
                ->limit(50)
                ->pluck('payment_type');
            foreach ($distinctPayTypes as $ptRow) {
                $pt = trim((string)$ptRow);
                if ($pt !== '' && strcasecmp($pt, 'Opening Stock') !== 0 && strcasecmp($pt, 'Split Payment') !== 0) {
                    $payTypes[strtolower($pt)] = $payTypes[strtolower($pt)] ?? $pt;
                }
            }
            foreach (DB::table('suppliers')->orderBy('name')->limit(200)->pluck('name') as $sRow) {
                $sn = trim((string)$sRow);
                if ($sn !== '') {
                    $supplierNames[strtolower($sn)] = $supplierNames[strtolower($sn)] ?? $sn;
                }
            }
            $userList = array_values($userNames);
            natcasesort($userList);
            $supplierList = array_values($supplierNames);
            natcasesort($supplierList);
            $payTypeList = array_values($payTypes);
            natcasesort($payTypeList);

            $availableFilters = [
                'warehouses' => DB::table('outlets')
                    ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('id', $allowedOutlets))
                    ->orderBy('name')
                    ->pluck('name')
                    ->values()
                    ->all(),
                'suppliers' => array_values($supplierList),
                'users' => array_values($userList),
                'payment_types' => array_values($payTypeList),
            ];
        }

        return $this->noStoreJson([
            'status' => 'success',
            'available_filters' => $availableFilters,
            'data' => [
                'available_filters' => $availableFilters,
                'total_purchases_count' => $maskedTotals['total_purchases_count'],
                'total_cost' => $maskedTotals['total_cost'],
                'total_paid' => $maskedTotals['total_paid'],
                'total_due' => $maskedTotals['total_due'],
                'total_returns' => $maskedTotals['total_returns'],
                'total_returned_credit' => $maskedTotals['total_returned_credit'],
                'net_cost' => $maskedTotals['net_cost'],
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

        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse');
        $requestedWhId = null;
        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $requestedWhId = $this->resolveWarehouseIdParam((string)$warehouse);
            if (!empty($allowedOutlets)) {
                if ($requestedWhId !== null && !in_array($requestedWhId, $allowedOutlets, true)) {
                    return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized outlet access.'], 403);
                }
                if ($requestedWhId === null && is_numeric($warehouse) && !in_array((int)$warehouse, $allowedOutlets, true)) {
                    return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized outlet access.'], 403);
                }
                if ($requestedWhId === null && !is_numeric($warehouse)) {
                    $allowedNames = DB::table('outlets')
                        ->whereIn('id', $allowedOutlets)
                        ->pluck('name')
                        ->map(fn ($n) => strtolower(trim((string)$n)))
                        ->all();
                    if (!in_array(strtolower(trim((string)$warehouse)), $allowedNames, true)) {
                        return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized outlet access.'], 403);
                    }
                }
            }
        }
        if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'All') {
            $explicitWhId = (int)$request->input('warehouse_id');
            if (!empty($allowedOutlets) && !in_array($explicitWhId, $allowedOutlets, true)) {
                return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized outlet access.'], 403);
            }
        }

        $baseQuery = DB::table('expenses')
            ->where(function ($q) {
                $q->whereNull('payment_status')
                  ->orWhereNotIn('payment_status', [
                      'Pending', 'pending', 'PENDING',
                      'Draft', 'draft', 'DRAFT',
                      'Voided', 'voided', 'VOIDED',
                      'Cancelled', 'cancelled', 'CANCELLED',
                  ]);
            });
        if (!empty($allowedOutlets)) {
            $baseQuery->whereIn('warehouse_id', $allowedOutlets);
        }

        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate   = $request->input('end_date') ?? $request->input('to_date');
        $this->applyReportDateRange($baseQuery, $startDate, $endDate, 'date');

        if (!empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all') {
            $whId = $requestedWhId;
            if ($whId !== null) {
                $hasNullWh = DB::table('expenses')->whereNull('warehouse_id')->limit(1)->exists();
                if ($hasNullWh) {
                    $baseQuery->where(function ($q) use ($warehouse, $whId) {
                        $q->where('warehouse_id', $whId)
                          ->orWhere(function ($legacy) use ($warehouse) {
                              $legacy->whereNull('warehouse_id')->where('warehouse_name', $warehouse);
                          });
                    });
                } else {
                    $baseQuery->where('warehouse_id', $whId);
                }
            } elseif (is_numeric($warehouse)) {
                $baseQuery->where('warehouse_id', -1);
            } else {
                $baseQuery->where('warehouse_name', $warehouse);
            }
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
        $hasPaymentMethodFilter = !empty($paymentMethod) && $paymentMethod !== 'All' && $paymentMethod !== 'all';
        $isSingleTenderFilter = $hasPaymentMethodFilter && strcasecmp((string)$paymentMethod, 'Split Payment') !== 0;
        if ($hasPaymentMethodFilter) {
            $baseQuery->where(function ($q) use ($paymentMethod) {
                $q->where('payment_method', $paymentMethod);
                if (strcasecmp((string)$paymentMethod, 'Split Payment') !== 0) {
                    $q->orWhere(function ($sq) use ($paymentMethod) {
                        $sq->where('payment_method', 'Split Payment')
                           ->whereExists(function ($ep) use ($paymentMethod) {
                               $ep->select(DB::raw(1))
                                  ->from('expense_payments as ep_f')
                                  ->whereColumn('ep_f.expense_id', 'expenses.id')
                                  ->where('ep_f.payment_type', $paymentMethod);
                           });
                    });
                }
            });
        }

        if ($request->filled('payment_status') && $request->input('payment_status') !== 'All') {
            $baseQuery->where('payment_status', $request->input('payment_status'));
        }

        $dueFilter = $request->input('due_filter');
        if ($dueFilter === 'due_only' || $dueFilter === 'has_due') {
            $baseQuery->where('due_amount', '>', 0.005);
        } elseif ($dueFilter === 'paid_only' || $dueFilter === 'fully_paid') {
            $baseQuery->whereRaw('COALESCE(due_amount, 0) <= 0.005');
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
            // Single-pass SQL aggregation by category (yields totals + category breakdown together)
            $categoryBreakdown = (clone $baseQuery)->select(
                'expense_category_name',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(amount), 0) as total'),
                DB::raw("COALESCE(SUM(CASE
                    WHEN LOWER(TRIM(COALESCE(payment_status, ''))) = 'unpaid' THEN 0
                    WHEN COALESCE(paid_amount, 0) > 0 THEN paid_amount
                    WHEN COALESCE(due_amount, 0) <= 0 AND LOWER(TRIM(COALESCE(payment_status, ''))) IN ('', 'paid') THEN amount
                    ELSE 0
                END), 0) as paid_total"),
                DB::raw('COALESCE(SUM(COALESCE(due_amount, 0)), 0) as due_total')
            )
            ->groupBy('expense_category_name')
            ->get()
            ->sortByDesc('total')
            ->values();

            foreach ($categoryBreakdown as $cb) {
                $totalRecords += (int)$cb->count;
                $totalAmount += (float)$cb->total;
                $totalPaid += (float)$cb->paid_total;
                $totalDue += (float)$cb->due_total;
            }
            $averageAmount = $totalRecords > 0 ? round($totalAmount / $totalRecords, 2) : 0.0;

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

            // INNER JOIN derived table of matched expense IDs avoids slow MariaDB IN-subquery materialization
            $payLegQuery = DB::table('expense_payments as ep')
                ->joinSub((clone $baseQuery)->select('id as exp_id'), 'matched_exp', function ($join) {
                    $join->on('matched_exp.exp_id', '=', 'ep.expense_id');
                });
            if ($isSingleTenderFilter) {
                $payLegQuery->where('ep.payment_type', $paymentMethod);
            }
            $payLegAgg = $payLegQuery
                ->select(
                    DB::raw("COALESCE(NULLIF(TRIM(ep.payment_type), ''), 'Cash') as method_name"),
                    DB::raw('COUNT(*) as count'),
                    DB::raw('COALESCE(SUM(ep.amount), 0) as total')
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
            $reconciledPaidFromLegs = 0.0;
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
                $reconciledPaidFromLegs += $pmAmt;
                if ($accounting->isCashPaymentType($mName)) {
                    $cashPaid += $pmAmt;
                } elseif (!in_array(strtolower($mName), ['', 'unpaid', 'n/a', 'split payment'], true)) {
                    $digitalPaid += $pmAmt;
                }
            }

            // Under single-tender filtering (e.g. Cash), constrain totalPaid on split vouchers to the filtered tender's disbursement
            if ($isSingleTenderFilter) {
                $totalPaid = $reconciledPaidFromLegs;
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
            ListCursorHelper::apply($baseQuery, $sortCol, $sortOrder, $cursor);
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

        $availableCategories = [];
        $availablePaymentMethods = [];
        $availableUsers = [];
        if (!$skipSummary && $page === 1) {
            $availableCategories = DB::table('expenses')
                ->whereNotNull('expense_category_name')
                ->where('expense_category_name', '!=', '')
                ->distinct()
                ->orderBy('expense_category_name')
                ->pluck('expense_category_name')
                ->values()
                ->all();

            $availablePaymentMethods = DB::table('expenses')
                ->whereNotNull('payment_method')
                ->where('payment_method', '!=', '')
                ->distinct()
                ->pluck('payment_method')
                ->merge(
                    DB::table('expense_payments')
                        ->whereNotNull('payment_type')
                        ->where('payment_type', '!=', '')
                        ->distinct()
                        ->pluck('payment_type')
                )
                ->unique()
                ->sort()
                ->values()
                ->all();

            // Index-only scans on idx_exp_user_name + idx_exp_created_by (avoids slow COALESCE full scan)
            $userNamesCol = DB::table('expenses')
                ->whereNotNull('user_name')
                ->where('user_name', '!=', '')
                ->distinct()
                ->limit(200)
                ->pluck('user_name')
                ->merge(
                    DB::table('expenses')
                        ->whereNotNull('created_by')
                        ->where('created_by', '!=', '')
                        ->distinct()
                        ->limit(200)
                        ->pluck('created_by')
                )
                ->map(fn ($v) => trim((string)$v))
                ->filter(fn ($v) => $v !== '')
                ->unique()
                ->values()
                ->all();
            natcasesort($userNamesCol);
            $availableUsers = array_values($userNamesCol);
        }

        $summary = [
            'total_count' => $totalRecords,
            'total_amount' => round($totalAmount, 2),
            'total_paid' => round($totalPaid, 2),
            'total_due' => round($totalDue, 2),
            'cash_paid' => round($cashPaid, 2),
            'digital_paid' => round($digitalPaid, 2),
            'average_amount' => $averageAmount,
            'top_category_name' => $topCategoryName,
            'top_category_amount' => round($topCategoryAmount, 2),
        ];

        return $this->noStoreJson([
            'status' => 'success',
            'data' => [
                'total_expense' => round($totalAmount, 2),
                'total_count' => $totalRecords,
                'total_amount' => round($totalAmount, 2),
                'total_paid' => round($totalPaid, 2),
                'total_due' => round($totalDue, 2),
                'cash_paid' => round($cashPaid, 2),
                'digital_paid' => round($digitalPaid, 2),
                'average_amount' => $averageAmount,
                'top_category_name' => $topCategoryName,
                'top_category_amount' => round($topCategoryAmount, 2),
                'available_categories' => $availableCategories,
                'available_payment_methods' => $availablePaymentMethods,
                'available_users' => $availableUsers,
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

        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate   = $request->input('end_date') ?? $request->input('to_date');
        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse');
        $hasWarehouseFilter = !empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all';
        $warehouseIdResolved = $hasWarehouseFilter ? $this->resolveWarehouseIdParam((string)$warehouse) : null;
        if (!empty($allowedOutlets) && $warehouseIdResolved !== null && !in_array($warehouseIdResolved, $allowedOutlets, true)) {
            return $this->noStoreJson([
                'status' => 'error',
                'message' => 'Unauthorized outlet access.',
            ], 403);
        }

        $hasNullSWh = $hasWarehouseFilter && $warehouseIdResolved !== null
            ? DB::table('sales')->whereNull('warehouse_id')->exists()
            : false;
        $hasNullSrWh = $hasWarehouseFilter && $warehouseIdResolved !== null
            ? DB::table('sale_returns')->whereNull('warehouse_id')->exists()
            : false;
        $hasNullSCust = DB::table('sales')->whereNull('customer_id')->exists();
        $hasNullSrCust = DB::table('sale_returns')->whereNull('customer_id')->exists();

        $salesQuery = DB::table('sales');
        $this->applyActiveDocumentFilter($salesQuery, 'status');
        $this->applyReportDateRange($salesQuery, $startDate, $endDate, 'date');
        if ($hasWarehouseFilter) {
            if ($warehouseIdResolved !== null && !$hasNullSWh) {
                $salesQuery->where('warehouse_id', $warehouseIdResolved);
            } else {
                $salesQuery->where(function ($q) use ($warehouse, $warehouseIdResolved) {
                    $q->where('warehouse_name', $warehouse);
                    if ($warehouseIdResolved !== null) {
                        $q->orWhere('warehouse_id', $warehouseIdResolved);
                    }
                });
            }
        }
        if (!empty($allowedOutlets)) {
            $salesQuery->whereIn('warehouse_id', $allowedOutlets);
        }

        $walkInCustomerId = ($hasNullSCust || $hasNullSrCust)
            ? DB::table('customers')
                ->where(function ($q) {
                    $q->whereRaw('LOWER(name) LIKE ?', ['%walk-in%'])
                      ->orWhereRaw('LOWER(name) LIKE ?', ['%walkin%'])
                      ->orWhere('customer_id', 'CUST-001');
                })
                ->orderBy('id')
                ->value('id')
            : null;
        $saleCustExpr = ($hasNullSCust && $walkInCustomerId)
            ? ('COALESCE(customer_id, ' . (int)$walkInCustomerId . ')')
            : 'customer_id';
        $retCustExpr = ($hasNullSrCust && $walkInCustomerId)
            ? ('COALESCE(customer_id, ' . (int)$walkInCustomerId . ')')
            : 'customer_id';

        $salesAgg = $salesQuery->select(
            DB::raw("{$saleCustExpr} as customer_id"),
            DB::raw('COUNT(*) as total_orders'),
            DB::raw('COALESCE(SUM(grand_total), 0) as total_spent'),
            DB::raw('COALESCE(SUM(CASE WHEN paid_amount > grand_total THEN grand_total ELSE paid_amount END), 0) as total_paid'),
            DB::raw('COALESCE(SUM(due_amount), 0) as total_due'),
            DB::raw('MAX(date) as last_purchase_date')
        )->groupBy(DB::raw($saleCustExpr));

        $returnsQuery = DB::table('sale_returns');
        $this->applyActiveDocumentFilter($returnsQuery, 'status');
        $this->applyReportDateRange($returnsQuery, $startDate, $endDate, 'date');
        if ($hasWarehouseFilter) {
            if ($warehouseIdResolved !== null && !$hasNullSrWh) {
                $returnsQuery->where('warehouse_id', $warehouseIdResolved);
            } else {
                $returnsQuery->where(function ($q) use ($warehouse, $warehouseIdResolved) {
                    $q->where('warehouse_name', $warehouse);
                    if ($warehouseIdResolved !== null) {
                        $q->orWhere('warehouse_id', $warehouseIdResolved);
                    }
                });
            }
        }
        if (!empty($allowedOutlets)) {
            $returnsQuery->whereIn('warehouse_id', $allowedOutlets);
        }

        $returnsAgg = $returnsQuery->select(
            DB::raw("{$retCustExpr} as customer_id"),
            DB::raw('COUNT(*) as total_returns'),
            DB::raw('COALESCE(SUM(grand_total), 0) as total_returned_amount'),
            DB::raw('COALESCE(SUM(CASE WHEN paid_amount > grand_total THEN grand_total ELSE paid_amount END), 0) as total_refunded_amount'),
            DB::raw('COALESCE(SUM(due_amount), 0) as total_return_due')
        )->groupBy(DB::raw($retCustExpr));

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
                $tierNorm = strtolower(trim((string)$tier));
                if (in_array($tierNorm, ['platinum', 'gold', 'silver', 'bronze', 'general', 'walk-in', 'standard'], true)) {
                    $q->whereRaw('LOWER(customers.tier) LIKE ?', [$tierNorm . '%']);
                } else {
                    $q->where('customers.tier', $tier);
                }
            }
            if (!empty($status) && $status !== 'All' && $status !== 'all') {
                $q->where('customers.status', $status);
            }
            if ($search !== '') {
                $q->where(function ($sub) use ($search, $digits) {
                    $sub->where('customers.name', 'like', "%{$search}%")
                        ->orWhere('customers.customer_id', 'like', "%{$search}%")
                        ->orWhere('customers.phone', 'like', "%{$search}%")
                        ->orWhere('customers.email', 'like', "%{$search}%")
                        ->orWhere('customers.tier', 'like', "%{$search}%");
                    if (!empty($digits)) {
                        $sub->orWhere('customers.phone', 'like', "%{$digits}%");
                    }
                });
            }
            if ($dueFilter === 'due_only' || $dueFilter === 'has_due') {
                $q->whereRaw('(COALESCE(sa.total_due, 0) - COALESCE(ra.total_return_due, 0)) > 0.005');
            } elseif ($dueFilter === 'paid_only' || $dueFilter === 'fully_paid') {
                $q->whereRaw('(COALESCE(sa.total_due, 0) - COALESCE(ra.total_return_due, 0)) <= 0.005');
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
        $totalRefundedAmount = 0.0;
        $totalReturnDue = 0.0;
        $totalPaid = 0.0;
        $totalDue = 0.0;
        $perCustomerNetDue = 0.0;
        $totalCreditBalance = 0.0;
        $needWinSummary = !$skipSummary && $page === 1;
        if (!$needWinSummary) {
            $totalCustomers = $knownTotal !== null
                ? $knownTotal
                : (int)(clone $joinedBase)->count('customers.id');
        }

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
        if ($needWinSummary) {
            // IAS 1.32: Sum per-customer positive net AR & per-customer credit balances separately (never cross-customer netting)
            $listQuery->selectRaw('
                COUNT(*) OVER() as _win_total_customers,
                SUM(COALESCE(sa.total_spent, 0)) OVER() as _win_total_revenue,
                SUM(COALESCE(ra.total_returned_amount, 0)) OVER() as _win_total_returned,
                SUM(COALESCE(ra.total_refunded_amount, 0)) OVER() as _win_total_refunded,
                SUM(COALESCE(ra.total_return_due, 0)) OVER() as _win_total_return_due,
                SUM(COALESCE(sa.total_paid, 0)) OVER() as _win_total_paid,
                SUM(COALESCE(sa.total_due, 0)) OVER() as _win_total_due,
                SUM(CASE WHEN (COALESCE(sa.total_due, 0) - COALESCE(ra.total_return_due, 0)) > 0 THEN (COALESCE(sa.total_due, 0) - COALESCE(ra.total_return_due, 0)) ELSE 0 END) OVER() as _win_net_due,
                SUM(CASE WHEN (COALESCE(sa.total_due, 0) - COALESCE(ra.total_return_due, 0)) < 0 THEN ABS(COALESCE(sa.total_due, 0) - COALESCE(ra.total_return_due, 0)) ELSE 0 END) OVER() as _win_credit_balance
            ');
        }

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

        if ($needWinSummary) {
            $firstWin = $pagedRows->first();
            if ($firstWin !== null) {
                $totalCustomers = (int)($firstWin->_win_total_customers ?? $pagedRows->count());
                $totalRevenue = (float)($firstWin->_win_total_revenue ?? 0);
                $totalReturnedAmount = (float)($firstWin->_win_total_returned ?? 0);
                $totalRefundedAmount = (float)($firstWin->_win_total_refunded ?? 0);
                $totalReturnDue = (float)($firstWin->_win_total_return_due ?? 0);
                $totalPaid = (float)($firstWin->_win_total_paid ?? 0);
                $totalDue = (float)($firstWin->_win_total_due ?? 0);
                $perCustomerNetDue = (float)($firstWin->_win_net_due ?? 0);
                $totalCreditBalance = (float)($firstWin->_win_credit_balance ?? 0);
            }
        }
        $avgSpent = $totalCustomers > 0 ? ($totalRevenue / $totalCustomers) : 0.0;
        $netRevenueTotal = round($totalRevenue - $totalReturnedAmount, 2);
        $netDueTotal = round($perCustomerNetDue, 2);
        $totalCreditBalance = round($totalCreditBalance, 2);
        $netAvgSpent = $totalCustomers > 0 ? ($netRevenueTotal / $totalCustomers) : 0.0;

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
                'net_due' => max(0.0, round($cDue - $cRetDue, 2)),
                'credit_balance' => max(0.0, round($cRetDue - $cDue, 2)),
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

        $availableTiers = !$skipSummary && $page === 1
            ? DB::table('customers')
                ->whereNotNull('tier')
                ->where('tier', '!=', '')
                ->distinct()
                ->orderBy('tier')
                ->pluck('tier')
                ->values()
                ->all()
            : [];

        $summary = [
            'total_customers' => $totalCustomers,
            'total_revenue' => $totalRevenue,
            'total_returned' => $totalReturnedAmount,
            'total_refunded' => $totalRefundedAmount,
            'total_return_due' => $totalReturnDue,
            'net_revenue' => $netRevenueTotal,
            'total_paid' => $totalPaid,
            'net_paid' => $totalPaid - $totalRefundedAmount,
            'total_due' => $totalDue,
            'net_due' => $netDueTotal,
            'total_credit_balance' => $totalCreditBalance,
            'average_spent' => $avgSpent,
            'net_average_spent' => $netAvgSpent,
        ];

        return $this->noStoreJson([
            'status' => 'success',
            'data' => [
                'total_customers' => $totalCustomers,
                'total_revenue' => $totalRevenue,
                'total_returned' => $totalReturnedAmount,
                'total_refunded' => $totalRefundedAmount,
                'net_revenue' => $netRevenueTotal,
                'total_paid' => $totalPaid,
                'net_paid' => $totalPaid - $totalRefundedAmount,
                'total_due' => $totalDue,
                'net_due' => $netDueTotal,
                'total_credit_balance' => $totalCreditBalance,
                'average_spent' => $avgSpent,
                'net_average_spent' => $netAvgSpent,
                'available_tiers' => $availableTiers,
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
        $hasWarehouseFilter = !empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all';
        $warehouseIdResolved = $hasWarehouseFilter ? $this->resolveWarehouseIdParam((string)$warehouse) : null;
        if (!empty($allowedOutlets) && $warehouseIdResolved !== null && !in_array($warehouseIdResolved, $allowedOutlets, true)) {
            return $this->noStoreJson([
                'status' => 'error',
                'message' => 'Unauthorized outlet access.',
            ], 403);
        }

        $applyWhScope = function ($q) use ($hasWarehouseFilter, $warehouse, $warehouseIdResolved) {
            if (!$hasWarehouseFilter) {
                return;
            }
            $q->where(function ($wq) use ($warehouse, $warehouseIdResolved) {
                $wq->where('warehouse_name', $warehouse);
                if ($warehouseIdResolved !== null) {
                    $wq->orWhere('warehouse_id', $warehouseIdResolved);
                }
            });
        };

        $search = trim((string)$request->input('search', ''));
        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate = $request->input('end_date') ?? $request->input('to_date');
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';

        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $offset = ($page - 1) * $perPage;

        $isWalkInCustomer = $customer->customer_id === 'CUST-001'
            || stripos((string)$customer->name, 'walk-in') !== false
            || stripos((string)$customer->name, 'walkin') !== false;

        $salesQuery = DB::table('sales')
            ->where(function ($q) use ($id, $isWalkInCustomer) {
                $q->where('customer_id', $id);
                if ($isWalkInCustomer) {
                    $q->orWhereNull('customer_id');
                }
            });
        $this->applyActiveDocumentFilter($salesQuery, 'status');

        $applyWhScope($salesQuery);
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
                COALESCE(SUM(CASE WHEN paid_amount > grand_total THEN grand_total ELSE paid_amount END), 0) as total_paid,
                COALESCE(SUM(due_amount), 0) as total_due
            ')->first()
            : null;

        // Fetch ONLY the paged invoice rows first (avoids scanning entire sale_items table!)
        $rawInvoices = (clone $salesQuery)
            ->select([
                'sales.id',
                'sales.reference_no',
                'sales.date',
                'sales.warehouse_name',
                'sales.grand_total',
                'sales.paid_amount',
                'sales.due_amount',
                'sales.status',
                'sales.payment_status',
                'sales.payment_type',
            ])
            ->orderBy('sales.date', 'desc')
            ->orderBy('sales.id', 'desc')
            ->offset($offset)
            ->limit($perPage + 1)
            ->get();

        $invoicesHasMore = $rawInvoices->count() > $perPage;
        $invoicesSlice = $invoicesHasMore ? $rawInvoices->slice(0, $perPage)->values() : $rawInvoices->values();
        $pagedSaleIds = $invoicesSlice->pluck('id')->map(fn ($v) => (int)$v)->all();
        $saleItemCounts = !empty($pagedSaleIds)
            ? DB::table('sale_items')
                ->whereIn('sale_id', $pagedSaleIds)
                ->selectRaw('sale_id, COUNT(*) as cnt')
                ->groupBy('sale_id')
                ->pluck('cnt', 'sale_id')
                ->all()
            : [];

        $invoices = $invoicesSlice->map(function ($s) use ($saleItemCounts) {
            $gt = (float)$s->grand_total;
            $rawPaid = (float)$s->paid_amount;
            $effPaid = min($rawPaid, $gt);
            return [
                'id' => (int)$s->id,
                'reference_no' => (string)$s->reference_no,
                'date' => (string)$s->date,
                'warehouse_name' => (string)($s->warehouse_name ?? 'Main Outlet'),
                'status' => (string)($s->status ?? 'Completed'),
                'grand_total' => $gt,
                'paid_amount' => $effPaid,
                'due_amount' => (float)$s->due_amount,
                'payment_status' => (string)$s->payment_status,
                'payment_type' => (string)$s->payment_type,
                'items_count' => (int)($saleItemCounts[(int)$s->id] ?? 0),
            ];
        })->all();

        $returnsBaseQuery = DB::table('sale_returns')
            ->where(function ($q) use ($id, $isWalkInCustomer) {
                $q->where('customer_id', $id);
                if ($isWalkInCustomer) {
                    $q->orWhereNull('customer_id');
                }
            });
        $applyWhScope($returnsBaseQuery);
        if (!empty($allowedOutlets)) {
            $returnsBaseQuery->whereIn('warehouse_id', $allowedOutlets);
        }
        $this->applyReportDateRange($returnsBaseQuery, $startDate, $endDate, 'date');
        if (!empty($search)) {
            $returnsBaseQuery->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('sale_reference_no', 'like', "%{$search}%")
                  ->orWhere('refund_tender', 'like', "%{$search}%")
                  ->orWhere('return_reason', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%");
            });
        }

        // Single-pass conditional aggregation computes BOTH posted returns & unposted pending returns in 1 scan
        $returnsAgg = !$skipSummary && $page === 1
            ? (clone $returnsBaseQuery)->selectRaw("
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','canceled','draft','pending','ordered') THEN 1 ELSE 0 END), 0) as returns_count,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','canceled','draft','pending','ordered') THEN grand_total ELSE 0 END), 0) as total_returned,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','canceled','draft','pending','ordered') THEN (CASE WHEN paid_amount > grand_total THEN grand_total ELSE paid_amount END) ELSE 0 END), 0) as total_refunded,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(status, 'completed')) NOT IN ('voided','cancelled','canceled','draft','pending','ordered') THEN due_amount ELSE 0 END), 0) as total_return_due,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(status, '')) IN ('pending','draft','ordered') THEN 1 ELSE 0 END), 0) as pending_count,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(status, '')) IN ('pending','draft','ordered') THEN grand_total ELSE 0 END), 0) as pending_amount
            ")->first()
            : null;

        $returnsQuery = clone $returnsBaseQuery;
        $this->applyActiveDocumentFilter($returnsQuery, 'status');

        // Avoid transferring heavy items_payload JSON blobs for every row; compute count in SQL when supported
        $driver = DB::connection()->getDriverName();
        $returnsSelect = [
            'id', 'reference_no', 'sale_reference_no', 'date', 'warehouse_name',
            'return_reason', 'refund_tender', 'payment_type', 'grand_total',
            'paid_amount', 'due_amount', 'status', 'payment_status',
        ];
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $returnsSelect[] = DB::raw('CASE WHEN items_payload IS NOT NULL AND JSON_VALID(items_payload) THEN JSON_LENGTH(items_payload) ELSE 1 END as computed_items_count');
        } else {
            $returnsSelect[] = 'items_payload';
        }

        $rawReturns = (clone $returnsQuery)
            ->select($returnsSelect)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->offset($offset)
            ->limit($perPage + 1)
            ->get();

        $returnsHasMore = $rawReturns->count() > $perPage;
        $returnsSlice = $returnsHasMore ? $rawReturns->slice(0, $perPage)->values() : $rawReturns->values();
        $returns = $returnsSlice->map(function ($r) {
            if (property_exists($r, 'computed_items_count')) {
                $itemsCount = max(1, (int)$r->computed_items_count);
            } else {
                $items = is_string($r->items_payload ?? null) ? json_decode($r->items_payload, true) : null;
                $itemsCount = is_array($items) && !empty($items) ? count($items) : 1;
            }
            $gt = (float)$r->grand_total;
            $effRefunded = min((float)$r->paid_amount, $gt);
            return [
                'id' => (int)$r->id,
                'reference_no' => (string)$r->reference_no,
                'sale_reference_no' => (string)($r->sale_reference_no ?? ''),
                'date' => (string)$r->date,
                'warehouse_name' => (string)($r->warehouse_name ?? 'Main Outlet'),
                'return_reason' => (string)($r->return_reason ?? 'Customer Return'),
                'refund_tender' => (string)($r->refund_tender ?? ($r->payment_type ?? 'Cash')),
                'grand_total' => $gt,
                'paid_amount' => $effRefunded,
                'due_amount' => (float)$r->due_amount,
                'status' => (string)($r->status ?? 'Completed'),
                'payment_status' => (string)($r->payment_status ?? 'Paid'),
                'items_count' => $itemsCount,
            ];
        })->all();

        $invoicesCount = (int)($salesAgg->invoices_count ?? count($invoices));
        $totalInvoiced = round((float)($salesAgg->total_invoiced ?? 0), 2);
        $totalPaid = round((float)($salesAgg->total_paid ?? 0), 2);
        $totalDue = round((float)($salesAgg->total_due ?? 0), 2);

        $returnsCount = (int)($returnsAgg->returns_count ?? count($returns));
        $totalReturned = round((float)($returnsAgg->total_returned ?? 0), 2);
        $totalRefunded = round((float)($returnsAgg->total_refunded ?? 0), 2);
        $totalReturnDue = round((float)($returnsAgg->total_return_due ?? 0), 2);

        $pendingReturnsCount = (int)($returnsAgg->pending_count ?? 0);
        $pendingReturnsAmount = round((float)($returnsAgg->pending_amount ?? 0), 2);

        // Exact Double-Entry AR Reconciliation:
        // Net Sales - Net Collected === Invoice Due - Unrefunded Return Credit === Signed Net Balance
        $netInvoiced = round($totalInvoiced - $totalReturned, 2);
        $netPaid = round($totalPaid - $totalRefunded, 2);
        $signedNetBalance = round($totalDue - $totalReturnDue, 2);
        $netDue = $signedNetBalance > 0.005 ? $signedNetBalance : 0.0;
        $creditBalance = $signedNetBalance < -0.005 ? round(abs($signedNetBalance), 2) : 0.0;
        $returnRatePct = $totalInvoiced > 0 ? round(($totalReturned / $totalInvoiced) * 100, 2) : 0.0;
        $paymentRatePct = $netInvoiced > 0
            ? round(min(100.0, max(0.0, ($netPaid / $netInvoiced) * 100)), 2)
            : ($netDue <= 0.005 ? 100.0 : 0.0);
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
                    'credit_balance' => $creditBalance,
                    'signed_net_balance' => $signedNetBalance,
                    'return_rate_pct' => $returnRatePct,
                ],
                'summary' => [
                    'invoices_count' => $invoicesCount,
                    'total_orders' => $invoicesCount,
                    'total_invoiced' => $totalInvoiced,
                    'total_paid' => $totalPaid,
                    'total_due' => $totalDue,
                    'returns_count' => $returnsCount,
                    'total_returned' => $totalReturned,
                    'total_refunded' => $totalRefunded,
                    'total_return_due' => $totalReturnDue,
                    'pending_returns_count' => $pendingReturnsCount,
                    'pending_returns_amount' => $pendingReturnsAmount,
                    'net_invoiced' => $netInvoiced,
                    'net_paid' => $netPaid,
                    'net_due' => $netDue,
                    'credit_balance' => $creditBalance,
                    'signed_net_balance' => $signedNetBalance,
                    'return_rate_pct' => $returnRatePct,
                    'payment_rate_pct' => $paymentRatePct,
                    'average_order_value' => $averageOrderValue,
                ],
                'pagination' => $pagination,
                'invoices_pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $invoicesCount,
                    'has_more' => $invoicesHasMore,
                ],
                'returns_pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $returnsCount,
                    'has_more' => $returnsHasMore,
                ],
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
        $hasWarehouseFilter = !empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all';
        $warehouseIdResolved = $hasWarehouseFilter ? $this->resolveWarehouseIdParam((string)$warehouse) : null;
        if (!empty($allowedOutlets) && $warehouseIdResolved !== null && !in_array($warehouseIdResolved, $allowedOutlets, true)) {
            return $this->noStoreJson([
                'status' => 'error',
                'message' => 'Unauthorized outlet access.',
            ], 403);
        }

        $hasNullPoSup = DB::table('purchases')->whereNull('supplier_id')->exists();
        $hasNullPrSup = DB::table('purchase_returns')->whereNull('supplier_id')->exists();
        $hasNullPoWh = $hasWarehouseFilter && $warehouseIdResolved !== null
            ? DB::table('purchases')->whereNull('warehouse_id')->exists()
            : false;
        $hasNullPrWh = $hasWarehouseFilter && $warehouseIdResolved !== null
            ? DB::table('purchase_returns')->whereNull('warehouse_id')->exists()
            : false;

        $resolvedSupPurchExpr = $hasNullPoSup
            ? "COALESCE(supplier_id, (SELECT s2.id FROM suppliers s2 WHERE LOWER(TRIM(s2.name)) = LOWER(TRIM(purchases.supplier_name)) ORDER BY s2.id ASC LIMIT 1))"
            : 'supplier_id';
        $resolvedSupPrExpr = $hasNullPrSup
            ? "COALESCE(supplier_id, (SELECT s2.id FROM suppliers s2 WHERE LOWER(TRIM(s2.name)) = LOWER(TRIM(purchase_returns.supplier_name)) ORDER BY s2.id ASC LIMIT 1))"
            : 'purchase_returns.supplier_id';

        $category = $request->input('category') ?? $request->input('supplier_category');
        $terms = $request->input('payment_terms') ?? $request->input('terms');
        $status = $request->input('status');
        $searchSup = $request->filled('search') ? trim($request->input('search')) : '';
        $digitsSup = $searchSup !== '' ? preg_replace('/[^0-9]/', '', $searchSup) : '';

        $applySupFilters = function ($q) use ($category, $terms, $status, $searchSup, $digitsSup) {
            if (!empty($category) && $category !== 'All' && $category !== 'all') {
                $q->where('suppliers.category', $category);
            }
            if (!empty($terms) && $terms !== 'All' && $terms !== 'all') {
                $q->where('suppliers.payment_terms', $terms);
            }
            if (!empty($status) && $status !== 'All' && $status !== 'all') {
                $q->where('suppliers.status', $status);
            }
            if ($searchSup !== '') {
                $supIdMatch = null;
                if (preg_match('/^SUP-?(\d+)$/i', $searchSup, $m)) {
                    $supIdMatch = (int)$m[1];
                }
                $q->where(function ($sub) use ($searchSup, $digitsSup, $supIdMatch) {
                    $sub->where('suppliers.name', 'like', "%{$searchSup}%")
                        ->orWhere('suppliers.contact_person', 'like', "%{$searchSup}%")
                        ->orWhere('suppliers.phone', 'like', "%{$searchSup}%")
                        ->orWhere('suppliers.email', 'like', "%{$searchSup}%")
                        ->orWhere('suppliers.category', 'like', "%{$searchSup}%")
                        ->orWhere('suppliers.city', 'like', "%{$searchSup}%")
                        ->orWhere('suppliers.address', 'like', "%{$searchSup}%");
                    if ($supIdMatch !== null && $supIdMatch > 0) {
                        $sub->orWhere('suppliers.id', $supIdMatch);
                    }
                    if (!empty($digitsSup)) {
                        $sub->orWhere('suppliers.phone', 'like', "%{$digitsSup}%");
                    }
                });
            }
        };

        $hasStartDate = !empty($startDate);
        $hasEndDate = !empty($endDate);
        $isDateFiltered = $hasStartDate || $hasEndDate;
        $isFiltered = $isDateFiltered || $hasWarehouseFilter || !empty($allowedOutlets);
        $hasPraRows = $accounting->schemaHasColumn('purchase_return_allocations', 'purchase_return_id')
            && DB::table('purchase_return_allocations')->exists();

        $baseQuery = DB::table('suppliers');
        $selectCols = [];

        if (!$isDateFiltered) {
            $purchQuery = DB::table('purchases')->whereIn('status', ['Received', 'Completed']);
            $this->excludeOpeningStockPurchases($purchQuery);
            if (!empty($allowedOutlets)) {
                $purchQuery->whereIn('warehouse_id', $allowedOutlets);
            }
            if ($hasWarehouseFilter) {
                if ($warehouseIdResolved !== null && !$hasNullPoWh) {
                    $purchQuery->where('warehouse_id', $warehouseIdResolved);
                } else {
                    $purchQuery->where(function ($q) use ($warehouse, $warehouseIdResolved) {
                        $q->where('warehouse_name', $warehouse);
                        if ($warehouseIdResolved !== null) {
                            $q->orWhere('warehouse_id', $warehouseIdResolved);
                        }
                    });
                }
            }

            $purchAgg = (clone $purchQuery)->select(
                DB::raw("{$resolvedSupPurchExpr} as resolved_supplier_id"),
                DB::raw('COUNT(*) as total_purchases_count'),
                DB::raw('COALESCE(SUM(grand_total), 0) as total_purchased_amount'),
                DB::raw('COALESCE(SUM(paid_amount), 0) as total_paid_amount'),
                DB::raw('COALESCE(SUM(due_amount), 0) as total_due'),
                DB::raw('MAX(date) as last_purchase_date')
            )->groupBy(DB::raw($resolvedSupPurchExpr));

            $returnsQuery = DB::table('purchase_returns')
                ->whereIn('purchase_returns.status', ['Completed', 'Received']);
            if ($hasPraRows) {
                $hasReleasedAtCol = $accounting->schemaHasColumn('purchase_return_allocations', 'released_at');
                $praSub = DB::table('purchase_return_allocations')
                    ->select('purchase_return_id', DB::raw('COALESCE(SUM(amount), 0) as alloc_amt'))
                    ->when($hasReleasedAtCol, fn($q) => $q->whereNull('released_at'))
                    ->groupBy('purchase_return_id');
                $unallocExpr = 'CASE WHEN (purchase_returns.due_amount - COALESCE(pra.alloc_amt, 0)) > 0 THEN (purchase_returns.due_amount - COALESCE(pra.alloc_amt, 0)) ELSE 0 END';
                $returnsQuery->leftJoinSub($praSub, 'pra', 'pra.purchase_return_id', '=', 'purchase_returns.id');
            } else {
                $unallocExpr = 'CASE WHEN purchase_returns.due_amount > 0 THEN purchase_returns.due_amount ELSE 0 END';
            }
            if (!empty($allowedOutlets)) {
                $returnsQuery->whereIn('purchase_returns.warehouse_id', $allowedOutlets);
            }
            if ($hasWarehouseFilter) {
                if ($warehouseIdResolved !== null && !$hasNullPrWh) {
                    $returnsQuery->where('purchase_returns.warehouse_id', $warehouseIdResolved);
                } else {
                    $returnsQuery->where(function ($q) use ($warehouse, $warehouseIdResolved) {
                        $q->where('purchase_returns.warehouse_name', $warehouse);
                        if ($warehouseIdResolved !== null) {
                            $q->orWhere('purchase_returns.warehouse_id', $warehouseIdResolved);
                        }
                    });
                }
            }

            $returnsAgg = (clone $returnsQuery)->select(
                DB::raw("{$resolvedSupPrExpr} as resolved_supplier_id"),
                DB::raw('COUNT(*) as total_returns_count'),
                DB::raw('COALESCE(SUM(purchase_returns.grand_total), 0) as total_returned_amount'),
                DB::raw('COALESCE(SUM(purchase_returns.paid_amount), 0) as total_refunded_amount'),
                DB::raw('COALESCE(SUM(purchase_returns.due_amount), 0) as total_return_due'),
                DB::raw("COALESCE(SUM({$unallocExpr}), 0) as unallocated_return_credit")
            )->groupBy(DB::raw($resolvedSupPrExpr));

            // Authoritative Net AP: subledger (open PO due - unallocated PR credits) + safe positive opening balance
            // (Negative balance_due only applies when no subledger docs exist, protecting direct-DB fixtures)
            $subledgerNetExpr = '(COALESCE(pa.total_due, 0) - COALESCE(ra.unallocated_return_credit, 0))';
            $hasSubledgerExpr = '(COALESCE(pa.total_due, 0) > 0.005 OR COALESCE(ra.unallocated_return_credit, 0) > 0.005)';
            $rawOpenAdjExpr = "(COALESCE(suppliers.balance_due, 0) - {$subledgerNetExpr})";
            $effOpenAdjExpr = "(CASE WHEN {$hasSubledgerExpr} THEN (CASE WHEN {$rawOpenAdjExpr} > 0.005 THEN {$rawOpenAdjExpr} ELSE 0 END) ELSE COALESCE(suppliers.balance_due, 0) END)";

            $signedNetDueSql = $isFiltered
                ? $subledgerNetExpr
                : "({$subledgerNetExpr} + {$effOpenAdjExpr})";
            $openingAdjSql = $isFiltered
                ? '0'
                : $effOpenAdjExpr;
            $dueFormula = "CASE WHEN ({$signedNetDueSql}) < 0 THEN 0 ELSE ({$signedNetDueSql}) END";
            $creditBalFormula = "CASE WHEN ({$signedNetDueSql}) < 0 THEN ABS({$signedNetDueSql}) ELSE 0 END";

            $baseQuery->leftJoinSub($purchAgg, 'pa', 'suppliers.id', '=', 'pa.resolved_supplier_id')
                ->leftJoinSub($returnsAgg, 'ra', 'suppliers.id', '=', 'ra.resolved_supplier_id');

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
        } else {
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
                'suppliers.updated_at',
            ];
        }
        $applySupFilters($baseQuery);

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
                if ($warehouseIdResolved !== null && !$hasNullPoWh) {
                    $cumPoQuery->where('warehouse_id', $warehouseIdResolved);
                } else {
                    $cumPoQuery->where(function ($q) use ($warehouse, $warehouseIdResolved) {
                        $q->where('warehouse_name', $warehouse);
                        if ($warehouseIdResolved !== null) {
                            $q->orWhere('warehouse_id', $warehouseIdResolved);
                        }
                    });
                }
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
                if ($warehouseIdResolved !== null && !$hasNullPrWh) {
                    $cumPrQuery->where('warehouse_id', $warehouseIdResolved);
                } else {
                    $cumPrQuery->where(function ($q) use ($warehouse, $warehouseIdResolved) {
                        $q->where('warehouse_name', $warehouse);
                        if ($warehouseIdResolved !== null) {
                            $q->orWhere('warehouse_id', $warehouseIdResolved);
                        }
                    });
                }
            }
            if ($hasEndDate) {
                $this->applyReportDateRange($cumPrQuery, null, (string)$endDate, 'date');
            }
            $histPrRows = $cumPrQuery
                ->select(['id', 'supplier_id', 'supplier_name', 'date', 'grand_total', 'paid_amount', 'payments_payload'])
                ->get();

            $allocMaps = $hasPraRows
                ? $accounting->computeAsOfPurchaseReturnMaps(
                    $histPoRows->pluck('id')->all(),
                    $histPrRows->pluck('id')->all(),
                    $closingCutoff
                )
                : ['po_alloc' => [], 'po_has_any_alloc' => [], 'pr_alloc' => []];
            $poAllocAsOf = $allocMaps['po_alloc'];
            $poHasAnyAlloc = $allocMaps['po_has_any_alloc'];
            $prAllocAsOf = $allocMaps['pr_alloc'];

            $fastPaySums = static function ($payload, float $fallbackPaid, string $docDate) use ($accounting, $rangeStartDt, $rangeEndDt, $closingCutoff): array {
                return $accounting->sumPayloadPaymentsRangeAndAsOf(
                    $payload,
                    $fallbackPaid,
                    $docDate,
                    $rangeStartDt,
                    $rangeEndDt,
                    $closingCutoff
                );
            };

            $histPoBySup = [];
            foreach ($histPoRows as $po) {
                $sId = (int)($po->supplier_id ?? 0);
                if ($sId <= 0) {
                    $sId = $supIdByName[strtolower(trim((string)($po->supplier_name ?? '')))] ?? 0;
                }
                if ($sId <= 0) continue;
                $poDateStr = (string)$po->date;
                [$paidInPeriod, $paidClosing, $inPeriod] = $fastPaySums($po->payments_payload ?? null, (float)$po->paid_amount, $poDateStr);
                $credit = isset($poHasAnyAlloc[$po->id])
                    ? (float)($poAllocAsOf[$po->id] ?? 0.0)
                    : (float)($po->returned_credit_amount ?? 0.0);
                $due = round(max(0.0, (float)$po->grand_total - $paidClosing - $credit), 2);
                if (!isset($histPoBySup[$sId])) {
                    $histPoBySup[$sId] = ['cnt' => 0, 'purchased' => 0.0, 'paid' => 0.0, 'due' => 0.0, 'last_date' => ''];
                }
                if ($inPeriod) {
                    $histPoBySup[$sId]['cnt']++;
                    $histPoBySup[$sId]['purchased'] += (float)$po->grand_total;
                    if ($poDateStr > $histPoBySup[$sId]['last_date']) {
                        $histPoBySup[$sId]['last_date'] = $poDateStr;
                    }
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
                [$refundedInPeriod, $refundedClosing, $inPeriod] = $fastPaySums($pr->payments_payload ?? null, (float)$pr->paid_amount, (string)$pr->date);
                $prDue = round(max(0.0, (float)$pr->grand_total - $refundedClosing), 2);
                $unalloc = round(max(0.0, $prDue - (float)($prAllocAsOf[$pr->id] ?? 0.0)), 2);
                if (!isset($histPrBySup[$sId])) {
                    $histPrBySup[$sId] = ['cnt' => 0, 'returned' => 0.0, 'refunded' => 0.0, 'ret_due' => 0.0, 'unalloc' => 0.0];
                }
                if ($inPeriod) {
                    $histPrBySup[$sId]['cnt']++;
                    $histPrBySup[$sId]['returned'] += (float)$pr->grand_total;
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
                $poAgg = $histPoBySup[$sId] ?? ['cnt' => 0, 'purchased' => 0.0, 'paid' => 0.0, 'due' => 0.0, 'last_date' => ''];
                $prAgg = $histPrBySup[$sId] ?? ['cnt' => 0, 'returned' => 0.0, 'refunded' => 0.0, 'ret_due' => 0.0, 'unalloc' => 0.0];
                $openAdj = (!$hasWarehouseFilter && empty($allowedOutlets))
                    ? round((float)($openAdjustments[$sId]['opening_adjustment'] ?? 0.0), 2)
                    : 0.0;
                $signedNet = round($poAgg['due'] - $prAgg['unalloc'] + $openAdj, 2);
                $poAmt = round($poAgg['purchased'], 2);
                $prAmt = round($prAgg['returned'], 2);

                $supRow->total_purchases_count = (int)$poAgg['cnt'];
                $supRow->total_purchased_amount = $poAmt;
                $supRow->total_returns_count = (int)$prAgg['cnt'];
                $supRow->total_returned_amount = $prAmt;
                $supRow->net_purchased_amount = round($poAmt - $prAmt, 2);
                $supRow->last_purchase_date = $poAgg['last_date'] !== '' ? $poAgg['last_date'] : ($supRow->updated_at ?? null);
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

            $needWinSummary = !$skipSummary && $page === 1;
            if (!$needWinSummary) {
                $totalSuppliers = $knownTotal !== null
                    ? $knownTotal
                    : (int)(clone $baseQuery)->count('suppliers.id');
            }

            $listQ = (clone $baseQuery)->select($selectCols);
            if ($needWinSummary) {
                $listQ->selectRaw("
                    COUNT(*) OVER() as _win_total_suppliers,
                    SUM(COALESCE(pa.total_purchased_amount, 0)) OVER() as _win_total_purchased,
                    SUM(COALESCE(ra.total_returned_amount, 0)) OVER() as _win_total_returned,
                    SUM(COALESCE(pa.total_purchased_amount, 0) - COALESCE(ra.total_returned_amount, 0)) OVER() as _win_net_purchased,
                    SUM(COALESCE(pa.total_paid_amount, 0)) OVER() as _win_total_paid,
                    SUM(COALESCE(pa.total_paid_amount, 0) - COALESCE(ra.total_refunded_amount, 0)) OVER() as _win_net_paid,
                    SUM({$dueFormula}) OVER() as _win_total_due,
                    SUM({$signedNetDueSql}) OVER() as _win_net_due,
                    SUM({$creditBalFormula}) OVER() as _win_credit_balance
                ");
            }
            if (in_array($sortBy, $allowedSorts, true)) {
                $listQ->orderBy($sortBy, $sortOrder)->orderBy('suppliers.id', $sortOrder);
            } else {
                $listQ->orderByDesc('total_purchased_amount')->orderBy('suppliers.id', 'desc');
            }

            $rawRows = $listQ->offset($offset)->limit($perPage + 1)->get();
            $hasMore = $rawRows->count() > $perPage;
            $suppliers = ($hasMore ? $rawRows->slice(0, $perPage) : $rawRows)->values();

            if ($needWinSummary) {
                $firstWin = $suppliers->first();
                if ($firstWin !== null) {
                    $totalSuppliers = (int)($firstWin->_win_total_suppliers ?? $suppliers->count());
                    $totalPurchased = (float)($firstWin->_win_total_purchased ?? 0);
                    $totalReturned = (float)($firstWin->_win_total_returned ?? 0);
                    $netPurchased = (float)($firstWin->_win_net_purchased ?? 0);
                    $totalPaid = (float)($firstWin->_win_total_paid ?? 0);
                    $netPaid = (float)($firstWin->_win_net_paid ?? 0);
                    $totalDue = (float)($firstWin->_win_total_due ?? 0);
                    $totalNetDue = (float)($firstWin->_win_net_due ?? 0);
                    $totalCreditBalance = (float)($firstWin->_win_credit_balance ?? 0);
                }
                foreach ($suppliers as $supItem) {
                    unset(
                        $supItem->_win_total_suppliers,
                        $supItem->_win_total_purchased,
                        $supItem->_win_total_returned,
                        $supItem->_win_net_purchased,
                        $supItem->_win_total_paid,
                        $supItem->_win_net_paid,
                        $supItem->_win_total_due,
                        $supItem->_win_net_due,
                        $supItem->_win_credit_balance
                    );
                }
            }
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

        $availableCategories = (!$skipSummary && $page === 1)
            ? DB::table('suppliers')
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->values()
                ->all()
            : [];
        $availableTerms = (!$skipSummary && $page === 1)
            ? DB::table('suppliers')
                ->whereNotNull('payment_terms')
                ->where('payment_terms', '!=', '')
                ->distinct()
                ->orderBy('payment_terms')
                ->pluck('payment_terms')
                ->values()
                ->all()
            : [];

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
                'available_categories' => $availableCategories,
                'available_payment_terms' => $availableTerms,
                'summary' => $summary,
                'pagination' => $pagination,
                'suppliers' => $suppliers,
            ],
            'pagination' => $pagination,
        ]);
    }

    /**
     * Supplier Statement & Accounts Payable Ledger Drilldown
     * Index-native SQL aggregation, zero N+1/full-table scans, single-pass posted+pending returns,
     * exact double-entry AP reconciliation (Gross - Returns = Net Purchases; Open PO Due - Unallocated Credit + Opening = Signed Net AP).
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
        $hasWarehouseFilter = !empty($warehouse) && $warehouse !== 'All' && $warehouse !== 'all';
        $warehouseIdResolved = $hasWarehouseFilter ? $this->resolveWarehouseIdParam((string)$warehouse) : null;
        if (!empty($allowedOutlets) && $warehouseIdResolved !== null && !in_array($warehouseIdResolved, $allowedOutlets, true)) {
            return $this->noStoreJson([
                'status' => 'error',
                'message' => 'Unauthorized outlet access.',
            ], 403);
        }

        $search = trim((string)$request->input('search', ''));
        $startDate = $request->input('start_date') ?? $request->input('from_date');
        $endDate = $request->input('end_date') ?? $request->input('to_date');
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';

        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $offset = ($page - 1) * $perPage;

        $hasEndDate = !empty($endDate);
        $hasStartDate = !empty($startDate);
        $isDateFiltered = $hasStartDate || $hasEndDate;
        $isFiltered = $isDateFiltered || $hasWarehouseFilter || $search !== '' || !empty($allowedOutlets);
        $asOfEnd = $hasEndDate ? $accounting->normalizeFilterBoundaryDate((string)$endDate, true) : null;

        // Fast index probes: avoid OR-conditions that defeat supplier_id / warehouse_id covering indexes
        $hasNullPoSup = !empty($supplier->name) && DB::table('purchases')->whereNull('supplier_id')->exists();
        $hasNullPrSup = !empty($supplier->name) && DB::table('purchase_returns')->whereNull('supplier_id')->exists();
        $hasNullPoWh = $hasWarehouseFilter && $warehouseIdResolved !== null
            ? DB::table('purchases')->whereNull('warehouse_id')->exists()
            : false;
        $hasNullPrWh = $hasWarehouseFilter && $warehouseIdResolved !== null
            ? DB::table('purchase_returns')->whereNull('warehouse_id')->exists()
            : false;

        $applyPoSupMatch = function ($q) use ($id, $supplier, $hasNullPoSup) {
            if (!$hasNullPoSup) {
                $q->where('supplier_id', $id);
                return;
            }
            $q->where(function ($sub) use ($id, $supplier) {
                $sub->where('supplier_id', $id)
                    ->orWhere(function ($sq) use ($supplier) {
                        $sq->whereNull('supplier_id')->where('supplier_name', $supplier->name);
                    });
            });
        };
        $applyPrSupMatch = function ($q) use ($id, $supplier, $hasNullPrSup) {
            if (!$hasNullPrSup) {
                $q->where('supplier_id', $id);
                return;
            }
            $q->where(function ($sub) use ($id, $supplier) {
                $sub->where('supplier_id', $id)
                    ->orWhere(function ($sq) use ($supplier) {
                        $sq->whereNull('supplier_id')->where('supplier_name', $supplier->name);
                    });
            });
        };
        $applyPoWhScope = function ($q) use ($hasWarehouseFilter, $warehouse, $warehouseIdResolved, $hasNullPoWh) {
            if (!$hasWarehouseFilter) {
                return;
            }
            if ($warehouseIdResolved !== null && !$hasNullPoWh) {
                $q->where('warehouse_id', $warehouseIdResolved);
                return;
            }
            $q->where(function ($wq) use ($warehouse, $warehouseIdResolved) {
                $wq->where('warehouse_name', $warehouse);
                if ($warehouseIdResolved !== null) {
                    $wq->orWhere('warehouse_id', $warehouseIdResolved);
                }
            });
        };
        $applyPrWhScope = function ($q) use ($hasWarehouseFilter, $warehouse, $warehouseIdResolved, $hasNullPrWh) {
            if (!$hasWarehouseFilter) {
                return;
            }
            if ($warehouseIdResolved !== null && !$hasNullPrWh) {
                $q->where('warehouse_id', $warehouseIdResolved);
                return;
            }
            $q->where(function ($wq) use ($warehouse, $warehouseIdResolved) {
                $wq->where('warehouse_name', $warehouse);
                if ($warehouseIdResolved !== null) {
                    $wq->orWhere('warehouse_id', $warehouseIdResolved);
                }
            });
        };
        $applyPoSearch = function ($q) use ($search) {
            if ($search === '') {
                return;
            }
            $q->where(function ($sq) use ($search) {
                $sq->where('reference_no', 'like', "%{$search}%")
                    ->orWhere('payment_type', 'like', "%{$search}%")
                    ->orWhere('payment_status', 'like', "%{$search}%")
                    ->orWhere('warehouse_name', 'like', "%{$search}%");
            });
        };
        $applyPrSearch = function ($q) use ($search) {
            if ($search === '') {
                return;
            }
            $q->where(function ($sq) use ($search) {
                $sq->where('reference_no', 'like', "%{$search}%")
                    ->orWhere('purchase_reference_no', 'like', "%{$search}%")
                    ->orWhere('payment_type', 'like', "%{$search}%")
                    ->orWhere('return_reason', 'like', "%{$search}%")
                    ->orWhere('warehouse_name', 'like', "%{$search}%");
            });
        };

        $purchQuery = DB::table('purchases')->whereIn('status', ['Received', 'Completed']);
        $applyPoSupMatch($purchQuery);
        $this->excludeOpeningStockPurchases($purchQuery);
        if (!empty($allowedOutlets)) {
            $purchQuery->whereIn('warehouse_id', $allowedOutlets);
        }
        $applyPoWhScope($purchQuery);
        $this->applyReportDateRange($purchQuery, !empty($startDate) ? (string)$startDate : null, !empty($endDate) ? (string)$endDate : null, 'date');
        $applyPoSearch($purchQuery);

        // Fetch ONLY the paged purchase rows first (avoids scanning entire purchase_items table!)
        $poSelectCols = [
            'id',
            'reference_no',
            'date',
            'warehouse_name',
            'grand_total',
            'paid_amount',
            'due_amount',
            'returned_credit_amount',
            'status',
            'payment_status',
            'payment_type',
        ];
        if ($hasEndDate) {
            $poSelectCols[] = 'payments_payload';
        }

        $rawPurchasesPage = (clone $purchQuery)
            ->select($poSelectCols)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->offset($offset)
            ->limit($perPage + 1)
            ->get();

        $purchHasMore = $rawPurchasesPage->count() > $perPage;
        $rawPurchases = ($purchHasMore ? $rawPurchasesPage->slice(0, $perPage) : $rawPurchasesPage)->values();

        // Batch item counts ONLY for the paged purchase IDs (<1ms via idx_pi_pur_prod)
        $pagedPoIds = $rawPurchases->pluck('id')->map(fn ($v) => (int)$v)->all();
        $poItemCounts = !empty($pagedPoIds)
            ? DB::table('purchase_items')
                ->whereIn('purchase_id', $pagedPoIds)
                ->selectRaw('purchase_id, COUNT(*) as cnt')
                ->groupBy('purchase_id')
                ->pluck('cnt', 'purchase_id')
                ->all()
            : [];

        // Base purchase_returns scope (status-agnostic so 1 scan computes both posted & pending returns)
        $returnsBaseQuery = DB::table('purchase_returns')
            ->when(!empty($allowedOutlets), fn($q) => $q->whereIn('warehouse_id', $allowedOutlets));
        $applyPrSupMatch($returnsBaseQuery);
        $applyPrWhScope($returnsBaseQuery);
        $this->applyReportDateRange($returnsBaseQuery, !empty($startDate) ? (string)$startDate : null, !empty($endDate) ? (string)$endDate : null, 'date');
        $applyPrSearch($returnsBaseQuery);

        $returnsQuery = (clone $returnsBaseQuery)->whereIn('status', ['Completed', 'Received']);

        // Compute items_count in SQL on MySQL/MariaDB to avoid transferring heavy items_payload JSON blobs
        $driver = DB::connection()->getDriverName();
        $prSelectCols = [
            'id', 'reference_no', 'purchase_reference_no', 'date', 'warehouse_name',
            'return_reason', 'payment_type', 'grand_total', 'paid_amount', 'due_amount',
            'status', 'payment_status',
        ];
        if ($hasEndDate) {
            $prSelectCols[] = 'payments_payload';
        }
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $prSelectCols[] = DB::raw('CASE WHEN items_payload IS NOT NULL AND JSON_VALID(items_payload) THEN JSON_LENGTH(items_payload) ELSE 1 END as computed_items_count');
        } else {
            $prSelectCols[] = 'items_payload';
        }

        $rawReturnsPage = (clone $returnsQuery)
            ->select($prSelectCols)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->offset($offset)
            ->limit($perPage + 1)
            ->get();

        $returnsHasMore = $rawReturnsPage->count() > $perPage;
        $rawReturns = ($returnsHasMore ? $rawReturnsPage->slice(0, $perPage) : $rawReturnsPage)->values();

        // Only probe purchase_return_allocations when summary or as-of end-date maps are needed (0 extra queries on Load +50 pagination)
        $needsPraCheck = (!$skipSummary && $page === 1) || $hasEndDate;
        $hasPraRows = $needsPraCheck
            && $accounting->schemaHasColumn('purchase_return_allocations', 'purchase_return_id')
            && DB::table('purchase_return_allocations')->exists();

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
        $pendingReturnsCount = 0;
        $pendingReturnsAmount = 0.0;
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

                // Single-pass conditional aggregation for posted + unposted pending returns in period
                $periodPrAgg = (clone $returnsBaseQuery)->selectRaw("
                    COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN 1 ELSE 0 END), 0) as cnt,
                    COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN grand_total ELSE 0 END), 0) as grand_sum,
                    COALESCE(SUM(CASE WHEN LOWER(COALESCE(status, '')) IN ('pending', 'draft', 'ordered') THEN 1 ELSE 0 END), 0) as pending_cnt,
                    COALESCE(SUM(CASE WHEN LOWER(COALESCE(status, '')) IN ('pending', 'draft', 'ordered') THEN grand_total ELSE 0 END), 0) as pending_amt
                ")->first();
                $prPeriodCount = (int)($periodPrAgg->cnt ?? 0);
                $totalReturned = (float)($periodPrAgg->grand_sum ?? 0);
                $pendingReturnsCount = (int)($periodPrAgg->pending_cnt ?? 0);
                $pendingReturnsAmount = round((float)($periodPrAgg->pending_amt ?? 0), 2);

                $cumPoQuery = DB::table('purchases')->whereIn('status', ['Received', 'Completed']);
                $applyPoSupMatch($cumPoQuery);
                $this->excludeOpeningStockPurchases($cumPoQuery);
                if (!empty($allowedOutlets)) {
                    $cumPoQuery->whereIn('warehouse_id', $allowedOutlets);
                }
                $applyPoWhScope($cumPoQuery);
                $applyPoSearch($cumPoQuery);
                if ($hasEndDate) {
                    $this->applyReportDateRange($cumPoQuery, null, (string)$endDate, 'date');
                }
                $cumPoRows = $cumPoQuery->get(['id', 'date', 'grand_total', 'paid_amount', 'due_amount', 'returned_credit_amount', 'payments_payload']);

                $cumPrQuery = DB::table('purchase_returns')
                    ->whereIn('status', ['Completed', 'Received'])
                    ->when(!empty($allowedOutlets), fn($q) => $q->whereIn('warehouse_id', $allowedOutlets));
                $applyPrSupMatch($cumPrQuery);
                $applyPrWhScope($cumPrQuery);
                $applyPrSearch($cumPrQuery);
                if ($hasEndDate) {
                    $this->applyReportDateRange($cumPrQuery, null, (string)$endDate, 'date');
                }
                $cumPrRows = $cumPrQuery->get(['id', 'date', 'grand_total', 'paid_amount', 'due_amount', 'payments_payload']);

                $cumMaps = $hasPraRows
                    ? $accounting->computeAsOfPurchaseReturnMaps(
                        $cumPoRows->pluck('id')->all(),
                        $cumPrRows->pluck('id')->all(),
                        $closingCutoff
                    )
                    : ['po_alloc' => [], 'po_has_any_alloc' => [], 'pr_alloc' => []];
                if ($hasEndDate) {
                    $pagePoMaps = $cumMaps;
                }

                foreach ($cumPoRows as $cPo) {
                    [$paidInPeriod, $paidClosing] = $accounting->sumPayloadPaymentsRangeAndAsOf(
                        $cPo->payments_payload ?? null,
                        (float)$cPo->paid_amount,
                        (string)$cPo->date,
                        $rangeStartDt,
                        $rangeEndDt,
                        $closingCutoff
                    );
                    $totalPaid += $paidInPeriod;
                    $credit = isset($cumMaps['po_has_any_alloc'][$cPo->id])
                        ? (float)($cumMaps['po_alloc'][$cPo->id] ?? 0.0)
                        : (float)($cPo->returned_credit_amount ?? 0.0);
                    $totalDue += round(max(0.0, (float)$cPo->grand_total - $paidClosing - $credit), 2);
                }

                foreach ($cumPrRows as $cPr) {
                    [$refundedInPeriod, $refundedClosing] = $accounting->sumPayloadPaymentsRangeAndAsOf(
                        $cPr->payments_payload ?? null,
                        (float)$cPr->paid_amount,
                        (string)$cPr->date,
                        $rangeStartDt,
                        $rangeEndDt,
                        $closingCutoff
                    );
                    $totalRefunded += $refundedInPeriod;
                    $cPrDue = round(max(0.0, (float)$cPr->grand_total - $refundedClosing), 2);
                    $totalReturnDue += $cPrDue;
                    $unallocatedReturnCredit += round(max(0.0, $cPrDue - (float)($cumMaps['pr_alloc'][$cPr->id] ?? 0.0)), 2);
                }
                $totalPaid = round($totalPaid, 2);
                $totalDue = round($totalDue, 2);
                $totalRefunded = round($totalRefunded, 2);
                $totalReturnDue = round($totalReturnDue, 2);
                $unallocatedReturnCredit = round($unallocatedReturnCredit, 2);

                $openAdjMap = (!$hasWarehouseFilter && empty($allowedOutlets) && $search === '')
                    ? $accounting->computeSupplierOpeningBalanceAdjustments([$id], $hasEndDate ? $closingCutoff : null)
                    : [];
                $openingBalanceAdjustment = round((float)($openAdjMap[$id]['opening_adjustment'] ?? 0.0), 2);
                $openingBalance = ($hasWarehouseFilter || !empty($allowedOutlets) || $search !== '') ? 0.0 : $openingBalanceAdjustment;
            } else {
                $poSum = (clone $purchQuery)->selectRaw('
                    COUNT(*) as cnt,
                    COALESCE(SUM(grand_total), 0) as grand_sum,
                    COALESCE(SUM(paid_amount), 0) as paid_sum,
                    COALESCE(SUM(due_amount), 0) as due_sum
                ')->first();
                $poPeriodCount = (int)($poSum->cnt ?? 0);
                $totalPurchased = round((float)($poSum->grand_sum ?? 0), 2);
                $totalPaid = round((float)($poSum->paid_sum ?? 0), 2);
                $totalDue = round((float)($poSum->due_sum ?? 0), 2);

                // Single-pass conditional aggregation for posted + unposted pending returns
                if ($hasPraRows) {
                    $hasRel = $accounting->schemaHasColumn('purchase_return_allocations', 'released_at');
                    $praSub = DB::table('purchase_return_allocations')
                        ->select('purchase_return_id', DB::raw('COALESCE(SUM(amount), 0) as allocated'))
                        ->when($hasRel, fn($q) => $q->whereNull('released_at'))
                        ->groupBy('purchase_return_id');
                    $prSum = (clone $returnsBaseQuery)
                        ->leftJoinSub($praSub, 'pra', 'pra.purchase_return_id', '=', 'purchase_returns.id')
                        ->selectRaw("
                            COALESCE(SUM(CASE WHEN purchase_returns.status IN ('Completed', 'Received') THEN 1 ELSE 0 END), 0) as cnt,
                            COALESCE(SUM(CASE WHEN purchase_returns.status IN ('Completed', 'Received') THEN purchase_returns.grand_total ELSE 0 END), 0) as grand_sum,
                            COALESCE(SUM(CASE WHEN purchase_returns.status IN ('Completed', 'Received') THEN purchase_returns.paid_amount ELSE 0 END), 0) as paid_sum,
                            COALESCE(SUM(CASE WHEN purchase_returns.status IN ('Completed', 'Received') THEN purchase_returns.due_amount ELSE 0 END), 0) as due_sum,
                            COALESCE(SUM(CASE WHEN purchase_returns.status IN ('Completed', 'Received') AND (purchase_returns.due_amount - COALESCE(pra.allocated, 0)) > 0 THEN (purchase_returns.due_amount - COALESCE(pra.allocated, 0)) ELSE 0 END), 0) as unalloc_sum,
                            COALESCE(SUM(CASE WHEN LOWER(COALESCE(purchase_returns.status, '')) IN ('pending', 'draft', 'ordered') THEN 1 ELSE 0 END), 0) as pending_cnt,
                            COALESCE(SUM(CASE WHEN LOWER(COALESCE(purchase_returns.status, '')) IN ('pending', 'draft', 'ordered') THEN purchase_returns.grand_total ELSE 0 END), 0) as pending_amt
                        ")->first();
                } else {
                    $prSum = (clone $returnsBaseQuery)->selectRaw("
                        COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN 1 ELSE 0 END), 0) as cnt,
                        COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN grand_total ELSE 0 END), 0) as grand_sum,
                        COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN paid_amount ELSE 0 END), 0) as paid_sum,
                        COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') THEN due_amount ELSE 0 END), 0) as due_sum,
                        COALESCE(SUM(CASE WHEN status IN ('Completed', 'Received') AND due_amount > 0 THEN due_amount ELSE 0 END), 0) as unalloc_sum,
                        COALESCE(SUM(CASE WHEN LOWER(COALESCE(status, '')) IN ('pending', 'draft', 'ordered') THEN 1 ELSE 0 END), 0) as pending_cnt,
                        COALESCE(SUM(CASE WHEN LOWER(COALESCE(status, '')) IN ('pending', 'draft', 'ordered') THEN grand_total ELSE 0 END), 0) as pending_amt
                    ")->first();
                }

                $prPeriodCount = (int)($prSum->cnt ?? 0);
                $totalReturned = round((float)($prSum->grand_sum ?? 0), 2);
                $totalRefunded = round((float)($prSum->paid_sum ?? 0), 2);
                $totalReturnDue = round((float)($prSum->due_sum ?? 0), 2);
                $unallocatedReturnCredit = round((float)($prSum->unalloc_sum ?? 0), 2);
                $pendingReturnsCount = (int)($prSum->pending_cnt ?? 0);
                $pendingReturnsAmount = round((float)($prSum->pending_amt ?? 0), 2);

                // Reuse already-aggregated open PO due & unallocated return credit (0 extra queries, exact PurchaseAccountingService parity)
                $rawAdj = round($ledgerBalanceDue - ($totalDue - $unallocatedReturnCredit), 2);
                $hasSubledgerDocs = ($totalDue > 0.005 || $unallocatedReturnCredit > 0.005);
                $openingBalanceAdjustment = ($hasSubledgerDocs ? ($rawAdj > 0.005) : (abs($rawAdj) > 0.005)) ? $rawAdj : 0.0;
                $openingBalance = $openingBalanceAdjustment;
            }
        } else {
            if ($hasEndDate && $hasPraRows) {
                $pagePoMaps = $accounting->computeAsOfPurchaseReturnMaps(
                    $rawPurchases->pluck('id')->all(),
                    $rawReturns->pluck('id')->all(),
                    $asOfEnd
                );
            }
        }

        $purchases = $rawPurchases->map(function ($p) use ($hasEndDate, $asOfEnd, $pagePoMaps, $accounting, $poItemCounts) {
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
                'items_count' => (int)($poItemCounts[(int)$p->id] ?? 0),
            ];
        });

        $returns = $rawReturns->map(function ($r) use ($hasEndDate, $asOfEnd, $accounting) {
            if (property_exists($r, 'computed_items_count')) {
                $itemsCount = max(1, (int)$r->computed_items_count);
            } else {
                $decoded = is_string($r->items_payload ?? null) ? json_decode($r->items_payload, true) : null;
                $itemsCount = is_array($decoded) && !empty($decoded) ? count($decoded) : 1;
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
                'items_count' => $itemsCount,
            ];
        });

        $signedNetDue = round($totalDue - $unallocatedReturnCredit + $openingBalance, 2);
        $positivePayable = round(max(0.0, $signedNetDue), 2);
        $creditBalance = round(max(0.0, -$signedNetDue), 2);
        $netPurchased = round($totalPurchased - $totalReturned, 2);
        $netPaid = round($totalPaid - $totalRefunded, 2);
        $returnRatePct = $totalPurchased > 0 ? round(($totalReturned / $totalPurchased) * 100, 2) : 0.0;
        $netPayableBase = round($netPurchased + $openingBalance, 2);
        $paymentRatePct = $netPayableBase > 0.005
            ? round(min(100.0, max(0.0, ($netPaid / $netPayableBase) * 100)), 2)
            : ($positivePayable <= 0.005 ? 100.0 : 0.0);
        $averagePurchaseValue = $poPeriodCount > 0 ? round($totalPurchased / $poPeriodCount, 2) : 0.0;
        $lastPurchaseDate = $purchases->isNotEmpty() ? $purchases->first()['date'] : ($supplier->updated_at ?? '');

        if (!$canViewCost) {
            $purchases = $purchases->map(function ($row) {
                return $this->maskAssocNumericKeys($row, ['grand_total', 'paid_amount', 'due_amount'], null);
            })->values();
            $returns = $returns->map(function ($row) {
                return $this->maskAssocNumericKeys($row, ['grand_total', 'paid_amount', 'due_amount'], null);
            })->values();
            $ledgerBalanceDue = $openingBalance = $openingBalanceAdjustment = $totalDue = $totalReturnDue = $unallocatedReturnCredit = 0.0;
            $totalPurchased = $netPurchased = $totalPaid = $signedNetDue = $creditBalance = $positivePayable = 0.0;
            $totalReturned = $totalRefunded = $netPaid = $returnRatePct = $paymentRatePct = $averagePurchaseValue = $pendingReturnsAmount = 0.0;
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

        $supplierCode = 'SUP-' . str_pad((string)$supplier->id, 5, '0', STR_PAD_LEFT);

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'data' => [
                'cost_masked' => !$canViewCost,
                'supplier' => [
                    'id' => (int)$supplier->id,
                    'code' => $supplierCode,
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
                    'total_purchased_amount' => $canViewCost ? $totalPurchased : null,
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
                    'pending_returns_count' => $pendingReturnsCount,
                    'pending_returns_amount' => $pendingReturnsAmount,
                    'net_purchased' => $netPurchased,
                    'net_paid' => $netPaid,
                    'net_due' => $signedNetDue,
                    'signed_net_balance' => $signedNetDue,
                    'credit_balance' => $creditBalance,
                    'opening_balance' => $openingBalance,
                    'opening_balance_adjustment' => $openingBalanceAdjustment,
                    'return_rate_pct' => $returnRatePct,
                    'payment_rate_pct' => $paymentRatePct,
                    'average_purchase_value' => $averagePurchaseValue,
                ],
                'pagination' => $pagination,
                'purchases_pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $poPeriodCount,
                    'has_more' => $purchHasMore,
                ],
                'returns_pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $prPeriodCount,
                    'has_more' => $returnsHasMore,
                ],
                'purchases' => $purchases->all(),
                'returns' => $returns->all(),
            ],
        ]);
    }

    private function applyStockStatusFilter($query, string $stockStatus, ?int $warehouseId, array $allowedOutlets): void
    {
        if ($stockStatus === '' || $stockStatus === 'All') {
            return;
        }

        $scopeWh = $warehouseId !== null
            ? [$warehouseId]
            : (!empty($allowedOutlets) ? array_values(array_map('intval', $allowedOutlets)) : null);
        $alertSqlExpr = 'COALESCE(NULLIF(products.alert_quantity, 0), NULLIF(products.stock_alert, 0), NULLIF(products.low_qty, 0), 10)';

        if ($stockStatus === 'in_stock') {
            if ($scopeWh !== null) {
                $query->whereExists(function ($sq) use ($scopeWh) {
                    $sq->select(DB::raw(1))
                        ->from('stock_balances as sb')
                        ->whereColumn('sb.product_id', 'products.id');
                    if (count($scopeWh) === 1) {
                        $sq->where('sb.warehouse_id', $scopeWh[0])
                           ->where('sb.quantity', '>', 0);
                    } else {
                        $sq->whereIn('sb.warehouse_id', $scopeWh)
                           ->groupBy('sb.product_variant_id')
                           ->havingRaw('SUM(sb.quantity) > 0');
                    }
                });
            } else {
                $query->where('stock_quantity', '>', 0);
            }
            return;
        }

        if ($stockStatus === 'out_of_stock') {
            if ($scopeWh !== null) {
                $query->where(function ($oq) use ($scopeWh) {
                    $oq->whereNotExists(function ($sq) use ($scopeWh) {
                        $sq->select(DB::raw(1))
                           ->from('stock_balances as sb')
                           ->whereColumn('sb.product_id', 'products.id');
                        if (count($scopeWh) === 1) {
                            $sq->where('sb.warehouse_id', $scopeWh[0])
                               ->where('sb.quantity', '>', 0);
                        } else {
                            $sq->whereIn('sb.warehouse_id', $scopeWh)
                               ->groupBy('sb.product_variant_id')
                               ->havingRaw('SUM(sb.quantity) > 0');
                        }
                    })->orWhereExists(function ($vq) use ($scopeWh) {
                        $vq->select(DB::raw(1))
                           ->from('product_variants as pv')
                           ->leftJoin('stock_balances as sb', function ($j) use ($scopeWh) {
                               $j->on('sb.product_variant_id', '=', 'pv.id');
                               if (count($scopeWh) === 1) {
                                   $j->where('sb.warehouse_id', $scopeWh[0]);
                               } else {
                                   $j->whereIn('sb.warehouse_id', $scopeWh);
                               }
                           })
                           ->whereColumn('pv.product_id', 'products.id')
                           ->where('products.has_variants', 1)
                           ->groupBy('pv.id')
                           ->havingRaw('COALESCE(SUM(sb.quantity), 0) <= 0');
                    });
                });
            } else {
                $query->where(function ($oq) {
                    $oq->where('products.stock_quantity', '<=', 0)
                       ->orWhereExists(function ($vq) {
                           $vq->select(DB::raw(1))
                              ->from('product_variants as pv')
                              ->leftJoin('stock_balances as sb', 'sb.product_variant_id', '=', 'pv.id')
                              ->whereColumn('pv.product_id', 'products.id')
                              ->where('products.has_variants', 1)
                              ->groupBy('pv.id')
                              ->havingRaw('COALESCE(SUM(sb.quantity), 0) <= 0');
                       });
                });
            }
            return;
        }

        if ($stockStatus === 'low_stock_only') {
            if ($scopeWh !== null) {
                $query->whereExists(function ($sq) use ($scopeWh, $alertSqlExpr) {
                    $sq->select(DB::raw(1))
                       ->from('stock_balances as sb')
                       ->whereColumn('sb.product_id', 'products.id');
                    if (count($scopeWh) === 1) {
                        $sq->where('sb.warehouse_id', $scopeWh[0])
                           ->where('sb.quantity', '>', 0)
                           ->whereRaw("sb.quantity <= {$alertSqlExpr}");
                    } else {
                        $sq->whereIn('sb.warehouse_id', $scopeWh)
                           ->groupBy('sb.product_variant_id')
                           ->havingRaw("SUM(sb.quantity) > 0 AND SUM(sb.quantity) <= {$alertSqlExpr}");
                    }
                });
            } else {
                $query->where(function ($q) use ($alertSqlExpr) {
                    $q->where(function ($sp) use ($alertSqlExpr) {
                        $sp->where('products.stock_quantity', '>', 0)
                           ->whereRaw("products.stock_quantity <= {$alertSqlExpr}");
                    })
                    ->orWhereExists(function ($vq) use ($alertSqlExpr) {
                        $vq->select(DB::raw(1))
                           ->from('product_variants as pv')
                           ->leftJoin('stock_balances as sb', 'sb.product_variant_id', '=', 'pv.id')
                           ->whereColumn('pv.product_id', 'products.id')
                           ->groupBy('pv.id')
                           ->havingRaw("COALESCE(SUM(sb.quantity), 0) > 0 AND COALESCE(SUM(sb.quantity), 0) <= {$alertSqlExpr}");
                    });
                });
            }
            return;
        }

        if ($stockStatus === 'low_stock') {
            if ($scopeWh !== null) {
                $query->where(function ($sq) use ($scopeWh, $alertSqlExpr) {
                    $sq->where(function ($sp) use ($scopeWh, $alertSqlExpr) {
                        $sp->where(function ($nv) {
                            $nv->where('products.has_variants', 0)
                               ->orWhereNull('products.has_variants');
                        });
                        if (count($scopeWh) === 1) {
                            $sp->whereNotExists(function ($above) use ($scopeWh, $alertSqlExpr) {
                                $above->select(DB::raw(1))
                                      ->from('stock_balances as sb')
                                      ->whereColumn('sb.product_id', 'products.id')
                                      ->where('sb.warehouse_id', $scopeWh[0])
                                      ->whereRaw("sb.quantity > {$alertSqlExpr}");
                            });
                        } else {
                            $sp->whereNotExists(function ($above) use ($scopeWh, $alertSqlExpr) {
                                $above->select(DB::raw(1))
                                      ->from('stock_balances as sb')
                                      ->whereColumn('sb.product_id', 'products.id')
                                      ->whereIn('sb.warehouse_id', $scopeWh)
                                      ->groupBy('sb.product_variant_id')
                                      ->havingRaw("SUM(sb.quantity) > {$alertSqlExpr}");
                            });
                        }
                    })->orWhereExists(function ($vq) use ($scopeWh, $alertSqlExpr) {
                        $vq->select(DB::raw(1))
                           ->from('product_variants as pv')
                           ->leftJoin('stock_balances as sb', function ($j) use ($scopeWh) {
                               $j->on('sb.product_variant_id', '=', 'pv.id');
                               if (count($scopeWh) === 1) {
                                   $j->where('sb.warehouse_id', $scopeWh[0]);
                               } else {
                                   $j->whereIn('sb.warehouse_id', $scopeWh);
                               }
                           })
                           ->whereColumn('pv.product_id', 'products.id')
                           ->where('products.has_variants', 1)
                           ->groupBy('pv.id')
                           ->havingRaw("COALESCE(SUM(sb.quantity), 0) <= {$alertSqlExpr}");
                    });
                });
            } else {
                $query->where(function ($q) use ($alertSqlExpr) {
                    $q->whereRaw("products.stock_quantity <= {$alertSqlExpr}")
                      ->orWhereExists(function ($vq) use ($alertSqlExpr) {
                          $vq->select(DB::raw(1))
                             ->from('product_variants as pv')
                             ->leftJoin('stock_balances as sb', 'sb.product_variant_id', '=', 'pv.id')
                             ->whereColumn('pv.product_id', 'products.id')
                             ->groupBy('pv.id')
                             ->havingRaw("COALESCE(SUM(sb.quantity), 0) <= {$alertSqlExpr}");
                      });
                });
            }
        }
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

        // Build pure filter query first (no select/with/withSum bindings) so cloned count/id subqueries stay binding-clean
        $query = Product::query();
        if ($warehouseId === null && !empty($allowedOutlets)) {
            $query->where(function ($wq) use ($allowedOutlets) {
                $wq->whereHas('stockBalances', function ($q) use ($allowedOutlets) {
                    $q->whereIn('warehouse_id', $allowedOutlets);
                })->orWhereHas('variants.stockBalances', function ($q) use ($allowedOutlets) {
                    $q->whereIn('warehouse_id', $allowedOutlets);
                });
            });
        }

        if (!empty($search)) {
            $varSearchIds1 = DB::table('product_variants')
                ->whereNull('deleted_at')
                ->where(function ($vq) use ($search) {
                    $vq->where('name', 'like', "%{$search}%")
                       ->orWhere('sku', 'like', "%{$search}%")
                       ->orWhere('barcode', 'like', "%{$search}%");
                })
                ->pluck('product_id')
                ->all();
            $varSearchIds2 = DB::table('product_variants as pv')
                ->join('product_variant_values as pvv', 'pv.id', '=', 'pvv.product_variant_id')
                ->whereNull('pv.deleted_at')
                ->where(function ($av) use ($search) {
                    $av->where('pvv.attribute_value', 'like', "%{$search}%")
                       ->orWhere('pvv.attribute_name', 'like', "%{$search}%");
                })
                ->pluck('pv.product_id')
                ->all();
            $varSearchIds = array_values(array_unique(array_map('intval', array_merge($varSearchIds1, $varSearchIds2))));

            $query->where(function ($q) use ($search, $varSearchIds) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('alternative_name', 'like', "%{$search}%")
                  ->orWhere('brand_name', 'like', "%{$search}%")
                  ->orWhere('category_name', 'like', "%{$search}%");
                if (!empty($varSearchIds)) {
                    $q->orWhereIn('id', $varSearchIds);
                }
            });
        }

        if ($category !== 'All' && !empty($category)) {
            $query->where('category_name', $category);
        }

        if ($brand !== 'All' && !empty($brand)) {
            $query->where('brand_name', $brand);
        }

        $this->applyStockStatusFilter($query, (string)$stockStatus, $warehouseId, $allowedOutlets);

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

        $deficitQty = 0.0;
        $restockCost = 0.0;
        $totalVariants = 0;

        $lowStockCount = 0;
        $outOfStockCount = 0;
        $impactedSkusCount = 0;

        $matchingIds = null;
        $matchingSubQuery = null;
        if (!$skipSummary) {
            $countQuery = clone $baseQuery;
            $countQuery->setEagerLoads([])->reorder();
            $countQuery->getQuery()->columns = null;
            $totalProducts = (int)$countQuery->count();

            if ($totalProducts > 0 && $hasProductFilters) {
                if ($totalProducts <= 400) {
                    $matchingQuery = clone $baseQuery;
                    $matchingQuery->setEagerLoads([])->reorder();
                    $matchingQuery->getQuery()->columns = null;
                    $matchingIds = $matchingQuery->pluck('id')->map(fn($id) => (int)$id)->all();
                } else {
                    $matchingSubQuery = (clone $baseQuery);
                    $matchingSubQuery->setEagerLoads([])->reorder();
                    $matchingSubQuery->getQuery()->columns = null;
                    $matchingSubQuery->select('products.id');
                }
            }

            if ($totalProducts > 0) {
                $valuation = app(InventoryReconciliationService::class)->computeValuationSummary(
                    $warehouseId !== null ? (int)$warehouseId : null,
                    !empty($allowedOutlets) ? $allowedOutlets : null,
                    $matchingIds,
                    $hasProductFilters ? null : $totalProducts,
                    false,
                    $matchingSubQuery
                );

                $totalQuantity = round((float)$valuation['total_book_qty'], 4);
                $masterTotalCost = round((float)$valuation['master_valuation'], 2);
                $fifoBatchTotalCost = round((float)$valuation['fifo_batch_valuation'], 2);
                $totalCost = round((float)$valuation['effective_valuation'], 2);
                $totalRetail = round((float)$valuation['retail_valuation'], 2);

                $effAlertProd = 'COALESCE(NULLIF(p.alert_quantity, 0), NULLIF(p.stock_alert, 0), NULLIF(p.low_qty, 0), 10)';
                $prodCostExpr = 'COALESCE(NULLIF(p.cost_price, 0), NULLIF(p.purchase_price, 0), 0)';
                $varCostExpr = 'COALESCE(NULLIF(pv.cost_price, 0), NULLIF(p.cost_price, 0), NULLIF(p.purchase_price, 0), 0)';
                $useWhBalances = ($warehouseId !== null || !empty($allowedOutlets));

                $filterIdsLimit = 400;
                $canUseMatchingIdsInSql = $matchingIds !== null && count($matchingIds) <= $filterIdsLimit;

                // 1) Simple products: warehouse-accurate SKU health & deficit (plus edge case has_variants=1 with 0 variant rows)
                $simpleQ = DB::table('products as p')
                    ->whereNull('p.deleted_at')
                    ->where(function ($q) {
                        $q->where('p.has_variants', 0)
                          ->orWhereNull('p.has_variants');
                    });
                if ($canUseMatchingIdsInSql) {
                    $simpleQ->whereIn('p.id', $matchingIds);
                } elseif ($hasProductFilters || (!empty($allowedOutlets) && $warehouseId === null)) {
                    $subQuery = clone $baseQuery;
                    $subQuery->setEagerLoads([])->reorder();
                    $subQuery->getQuery()->columns = null;
                    $simpleQ->whereIn('p.id', $subQuery->select('id'));
                }

                if ($useWhBalances) {
                    $sbProdSub = DB::table('stock_balances')
                        ->select(['product_id', DB::raw('SUM(quantity) as wh_qty')])
                        ->groupBy('product_id');
                    if ($warehouseId !== null) {
                        $sbProdSub->where('warehouse_id', $warehouseId);
                    } else {
                        $sbProdSub->whereIn('warehouse_id', $allowedOutlets);
                    }
                    $simpleQ->leftJoinSub($sbProdSub, 'sb_p', fn($j) => $j->on('sb_p.product_id', '=', 'p.id'));
                    $sQtyExpr = 'COALESCE(sb_p.wh_qty, 0)';
                } else {
                    $sQtyExpr = 'COALESCE(p.stock_quantity, 0)';
                }

                $healthSelect = function (string $qtyExpr, string $costExpr, bool $includeCount = false) use ($effAlertProd, $stockStatus) {
                    $lowCond = "({$qtyExpr}) > 0 AND ({$qtyExpr}) <= ({$effAlertProd})";
                    $outCond = "({$qtyExpr}) <= 0";
                    if ($stockStatus === 'low_stock_only') {
                        $lowExpr = "CASE WHEN {$lowCond} THEN 1 ELSE 0 END";
                        $outExpr = '0';
                        $defCond = "CASE WHEN {$lowCond} THEN ({$effAlertProd}) - ({$qtyExpr}) ELSE 0 END";
                    } elseif ($stockStatus === 'out_of_stock') {
                        $lowExpr = '0';
                        $outExpr = "CASE WHEN {$outCond} THEN 1 ELSE 0 END";
                        $defCond = "CASE WHEN {$outCond} AND ({$effAlertProd}) > ({$qtyExpr}) THEN ({$effAlertProd}) - ({$qtyExpr}) ELSE 0 END";
                    } else {
                        $lowExpr = "CASE WHEN {$lowCond} THEN 1 ELSE 0 END";
                        $outExpr = "CASE WHEN {$outCond} THEN 1 ELSE 0 END";
                        $defCond = "CASE WHEN ({$effAlertProd}) > ({$qtyExpr}) THEN ({$effAlertProd}) - ({$qtyExpr}) ELSE 0 END";
                    }
                    $prefix = $includeCount ? 'COUNT(*) as var_cnt, ' : '';
                    return "{$prefix}
                        COALESCE(SUM({$lowExpr}), 0) as low_cnt,
                        COALESCE(SUM({$outExpr}), 0) as out_cnt,
                        COALESCE(SUM({$defCond}), 0) as deficit_qty,
                        COALESCE(SUM(({$defCond}) * ({$costExpr})), 0) as restock_cost";
                };

                $simpleAgg = $simpleQ->selectRaw($healthSelect($sQtyExpr, $prodCostExpr))->first();

                // Edge case: variant-flagged products with zero active variant rows
                $orphanVarQ = DB::table('products as p')
                    ->whereNull('p.deleted_at')
                    ->where('p.has_variants', 1)
                    ->whereNotExists(function ($vq) {
                        $vq->select(DB::raw(1))
                           ->from('product_variants as pv0')
                           ->whereColumn('pv0.product_id', 'p.id')
                           ->whereNull('pv0.deleted_at');
                    });
                if ($canUseMatchingIdsInSql) {
                    $orphanVarQ->whereIn('p.id', $matchingIds);
                } elseif ($hasProductFilters || (!empty($allowedOutlets) && $warehouseId === null)) {
                    $orphanSub = clone $baseQuery;
                    $orphanSub->setEagerLoads([])->reorder();
                    $orphanSub->getQuery()->columns = null;
                    $orphanVarQ->whereIn('p.id', $orphanSub->select('id'));
                }
                if ($useWhBalances) {
                    $sbOrphanSub = DB::table('stock_balances')
                        ->select(['product_id', DB::raw('SUM(quantity) as wh_qty')])
                        ->groupBy('product_id');
                    if ($warehouseId !== null) {
                        $sbOrphanSub->where('warehouse_id', $warehouseId);
                    } else {
                        $sbOrphanSub->whereIn('warehouse_id', $allowedOutlets);
                    }
                    $orphanVarQ->leftJoinSub($sbOrphanSub, 'sb_p', fn($j) => $j->on('sb_p.product_id', '=', 'p.id'));
                }
                $orphanAgg = $orphanVarQ->selectRaw($healthSelect($sQtyExpr, $prodCostExpr))->first();

                // 2) Active Variant SKUs: compute warehouse-accurate SKU health, deficit & totalVariants
                $sbVarSub = DB::table('stock_balances')
                    ->select(['product_variant_id', DB::raw('SUM(quantity) as var_qty')])
                    ->whereNotNull('product_variant_id')
                    ->groupBy('product_variant_id');
                if ($warehouseId !== null) {
                    $sbVarSub->where('warehouse_id', $warehouseId);
                } elseif (!empty($allowedOutlets)) {
                    $sbVarSub->whereIn('warehouse_id', $allowedOutlets);
                }

                $varQ = DB::table('product_variants as pv')
                    ->join('products as p', 'p.id', '=', 'pv.product_id')
                    ->leftJoinSub($sbVarSub, 'sb_v', fn($j) => $j->on('sb_v.product_variant_id', '=', 'pv.id'))
                    ->whereNull('p.deleted_at')
                    ->whereNull('pv.deleted_at')
                    ->where('p.has_variants', 1);
                if ($canUseMatchingIdsInSql) {
                    $varQ->whereIn('pv.product_id', $matchingIds);
                } elseif ($hasProductFilters || (!empty($allowedOutlets) && $warehouseId === null)) {
                    $varSubQuery = clone $baseQuery;
                    $varSubQuery->setEagerLoads([])->reorder();
                    $varSubQuery->getQuery()->columns = null;
                    $varQ->whereIn('pv.product_id', $varSubQuery->select('id'));
                }

                $vQtyExpr = 'COALESCE(sb_v.var_qty, 0)';
                $varAgg = $varQ->selectRaw($healthSelect($vQtyExpr, $varCostExpr, true))->first();

                $totalVariants = (int)($varAgg->var_cnt ?? 0);
                $lowStockCount = (int)($simpleAgg->low_cnt ?? 0) + (int)($orphanAgg->low_cnt ?? 0) + (int)($varAgg->low_cnt ?? 0);
                $outOfStockCount = (int)($simpleAgg->out_cnt ?? 0) + (int)($orphanAgg->out_cnt ?? 0) + (int)($varAgg->out_cnt ?? 0);
                $impactedSkusCount = $lowStockCount + $outOfStockCount;
                $deficitQty = round((float)($simpleAgg->deficit_qty ?? 0) + (float)($orphanAgg->deficit_qty ?? 0) + (float)($varAgg->deficit_qty ?? 0), 4);
                $restockCost = round((float)($simpleAgg->restock_cost ?? 0) + (float)($orphanAgg->restock_cost ?? 0) + (float)($varAgg->restock_cost ?? 0), 2);
            }
            $totalSkus = $totalProducts + $totalVariants;
            $grossMarginPercent = $totalRetail > 0 ? round((($totalRetail - $totalCost) / $totalRetail) * 100, 2) : 0;

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
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
                'impacted_skus_count' => $impactedSkusCount,
            ], $canViewCost, [
                'total_products',
                'total_skus',
                'total_quantity',
                'total_retail_value',
                'total_deficit_quantity',
                'low_stock_count',
                'out_of_stock_count',
                'impacted_skus_count',
            ]);
        } elseif ($knownTotal !== null) {
            $totalProducts = $knownTotal;
        } else {
            $countQuery = clone $baseQuery;
            $countQuery->setEagerLoads([])->reorder();
            $countQuery->getQuery()->columns = null;
            $totalProducts = (int)$countQuery->count();
        }

        if (!$skipSummary && $totalProducts === 0) {
            $pageItems = collect();
            $hasMore = false;
        } else {
            $listBase = ($matchingIds !== null && count($matchingIds) <= 400)
                ? Product::query()->whereIn('id', $matchingIds)
                : (clone $baseQuery);
            $listQuery = $listBase
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
                $listQuery->withSum(['stockBalances as warehouse_stock' => function ($q) use ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                }], 'quantity');
                $listQuery->orderBy('warehouse_stock', 'asc')->orderBy('id', 'asc');
            } elseif (!empty($allowedOutlets)) {
                $listQuery->withSum(['stockBalances as warehouse_stock' => function ($q) use ($allowedOutlets) {
                    $q->whereIn('warehouse_id', $allowedOutlets);
                }], 'quantity');
                $listQuery->orderBy('stock_quantity', 'asc')->orderBy('id', 'asc');
            } else {
                $listQuery->orderBy('stock_quantity', 'asc')->orderBy('id', 'asc');
            }
            $rows = $listQuery->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();
            $hasMore = $rows->count() > $perPage;
            $pageItems = $hasMore ? $rows->slice(0, $perPage)->values() : $rows->values();
        }
        $lastPage = max(1, (int)ceil($totalProducts / max(1, $perPage)));
        $request->attributes->set('_stock_report_can_view_cost', $canViewCost);
        if ($canViewCost && $pageItems->isNotEmpty()) {
            $pageItemIds = $pageItems->pluck('id')->map(fn($id) => (int)$id)->all();
            $pageVal = app(InventoryReconciliationService::class)->computeValuation(
                $warehouseId !== null ? (int)$warehouseId : null,
                $pageItemIds,
                !empty($allowedOutlets) ? $allowedOutlets : null
            );
            $request->attributes->set('_stock_report_fifo_by_product', $pageVal['by_product'] ?? []);
            $request->attributes->set('_stock_report_fifo_by_sku', $pageVal['by_sku'] ?? []);
        }

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
        $isLowStock = ($stockStatus === 'low_stock' || $stockStatus === 'low_stock_only');
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

        $this->applyStockStatusFilter($query, (string)$stockStatus, $warehouseId, $allowedOutlets);

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
                            $variants = $variants->filter(function ($v) use ($alertQty) {
                                $stock = (float)$v->stockBalances->sum('quantity');
                                return $stock <= $alertQty || $stock <= 0;
                            });
                            if ($variants->isEmpty()) {
                                $variants = $p->variants;
                            }
                        }

                        foreach ($variants as $v) {
                            $vStock = (float)$v->stockBalances->sum('quantity');
                            $vCost = (float)($v->cost_price ?: $p->cost_price ?: $p->purchase_price ?: 0);
                            $vRetail = (float)($v->selling_price ?: $p->retail_price ?: $p->selling_price ?: 0);
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
        $rawLimit = $request->has('limit') ? (int)$request->input('limit') : 0;
        $topCap = $rawLimit > 0 ? $rawLimit : 0;
        $warehouse = $request->input('warehouse_name') ?? $request->input('warehouse', 'All');
        $warehouseIdResolved = ($warehouse !== 'All' && !empty($warehouse)) ? $this->resolveWarehouseIdParam((string)$warehouse) : null;
        $category = $request->input('category_name', 'All');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $search = trim((string)$request->input('search', ''));
        $sortBy = $request->input('sort_by', 'revenue');
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;
        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        if (!empty($allowedOutlets) && $warehouseIdResolved !== null && !in_array($warehouseIdResolved, $allowedOutlets, true)) {
            return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
        }

        $saleBaseQtySql = 'COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity * COALESCE(NULLIF(sale_items.conversion_rate, 0), 1))';
        $hasPointsDiscountCol = $accounting->schemaHasColumn('sales', 'points_discount');
        $orderDiscSql = $hasPointsDiscountCol
            ? '(COALESCE(sales.discount, 0) + COALESCE(sales.points_discount, 0))'
            : 'COALESCE(sales.discount, 0)';
        $netSaleRevSql = "(COALESCE(sale_items.subtotal, 0) - COALESCE(sale_items.tax, 0) - (CASE WHEN COALESCE(sales.subtotal, 0) > 0 THEN {$orderDiscSql} * (COALESCE(sale_items.subtotal, 0) / sales.subtotal) ELSE 0 END))";

        $salesAgg = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id');
        $this->applyActiveDocumentFilter($salesAgg, 'sales.status');
        if (!empty($allowedOutlets)) {
            $salesAgg->whereIn('sales.warehouse_id', $allowedOutlets);
        }
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $salesAgg->where(function ($q) use ($warehouse, $warehouseIdResolved) {
                $q->where('sales.warehouse_name', $warehouse);
                if ($warehouseIdResolved !== null) {
                    $q->orWhere('sales.warehouse_id', $warehouseIdResolved);
                }
            });
        }
        $this->applyReportDateRange($salesAgg, $startDate, $endDate, 'sales.date');
        $salesAggSelect = [
            'sale_items.product_id',
            DB::raw("COALESCE(SUM({$saleBaseQtySql}), 0) as total_sold_quantity"),
            DB::raw("COALESCE(SUM({$netSaleRevSql}), 0) as total_sales_revenue"),
            DB::raw("COALESCE(SUM(COALESCE(NULLIF(sale_items.total_cogs, 0), NULLIF(sale_items.base_unit_cost, 0) * ({$saleBaseQtySql}), 0)), 0) as total_sales_cogs"),
        ];
        if (!empty($search)) {
            $salesAggSelect[] = DB::raw('MIN(sale_items.product_name) as si_product_name');
            $salesAggSelect[] = DB::raw('MIN(sale_items.product_code) as si_product_code');
        }
        $salesAgg->select($salesAggSelect)->groupBy('sale_items.product_id');

        $query = DB::query()
            ->fromSub($salesAgg, 'sa')
            ->leftJoin('products', 'sa.product_id', '=', 'products.id');

        // Scope displayed stock_quantity to selected warehouse / RBAC outlets when active
        $useWhStock = ($warehouseIdResolved !== null || !empty($allowedOutlets));
        if ($useWhStock) {
            $sbSub = DB::table('stock_balances')
                ->select(['product_id', DB::raw('SUM(quantity) as wh_stock')])
                ->groupBy('product_id');
            if ($warehouseIdResolved !== null) {
                $sbSub->where('warehouse_id', $warehouseIdResolved);
            } elseif (!empty($allowedOutlets)) {
                $sbSub->whereIn('warehouse_id', $allowedOutlets);
            }
            $query->leftJoinSub($sbSub, 'wh_sb', fn($j) => $j->on('wh_sb.product_id', '=', 'sa.product_id'));
        }

        $query->when($category !== 'All' && !empty($category), function ($q) use ($category) {
                $q->where('products.category_name', $category);
            })
            ->when(!empty($search), function ($q) use ($search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('products.name', 'like', "%{$search}%")
                       ->orWhere('products.code', 'like', "%{$search}%")
                       ->orWhere('products.alternative_name', 'like', "%{$search}%")
                       ->orWhere('products.brand_name', 'like', "%{$search}%")
                       ->orWhere('products.category_name', 'like', "%{$search}%")
                       ->orWhere('sa.si_product_name', 'like', "%{$search}%")
                       ->orWhere('sa.si_product_code', 'like', "%{$search}%");
                });
            });

        $stockSelect = $useWhStock
            ? DB::raw('COALESCE(wh_sb.wh_stock, 0) as resolved_stock_quantity')
            : DB::raw('COALESCE(products.stock_quantity, 0) as resolved_stock_quantity');

        // Deduct active completed Sale Returns (tax-exclusive, discount-prorated, FIFO/catalog COGS) before sorting & pagination
        $retByProd = [];
        $retCogsByProd = [];
        $srclByRetAndProd = [];
        $hasSrclTop = $accounting->schemaHasTable('sale_return_cost_layers') && DB::table('sale_return_cost_layers')->exists();
        if ($hasSrclTop) {
            $srclTopQ = DB::table('sale_return_cost_layers')
                ->join('sale_returns', 'sale_return_cost_layers.sale_return_id', '=', 'sale_returns.id');
            $this->applyActiveDocumentFilter($srclTopQ, 'sale_returns.status');
            if (!empty($allowedOutlets)) {
                $srclTopQ->whereIn('sale_returns.warehouse_id', $allowedOutlets);
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $srclTopQ->where(function ($q) use ($warehouse, $warehouseIdResolved) {
                    $q->where('sale_returns.warehouse_name', $warehouse);
                    if ($warehouseIdResolved !== null) {
                        $q->orWhere('sale_returns.warehouse_id', $warehouseIdResolved);
                    }
                });
            }
            $this->applyReportDateRange($srclTopQ, $startDate, $endDate, 'sale_returns.date');
            foreach ($srclTopQ->select(
                'sale_return_cost_layers.sale_return_id',
                'sale_return_cost_layers.product_id',
                DB::raw('SUM(sale_return_cost_layers.total_cost) as ret_cogs')
            )->groupBy('sale_return_cost_layers.sale_return_id', 'sale_return_cost_layers.product_id')->get() as $clRow) {
                $rid = (int)$clRow->sale_return_id;
                $pid = (int)$clRow->product_id;
                $rc = (float)$clRow->ret_cogs;
                $srclByRetAndProd[$rid][$pid] = ($srclByRetAndProd[$rid][$pid] ?? 0.0) + $rc;
                $retCogsByProd[$pid] = ($retCogsByProd[$pid] ?? 0.0) + $rc;
            }
        }

        $srQ = DB::table('sale_returns')
            ->whereNotNull('items_payload')
            ->where('items_payload', '!=', '')
            ->where('items_payload', '!=', '[]');
        $this->applyActiveDocumentFilter($srQ, 'status');
        if (!empty($allowedOutlets)) {
            $srQ->whereIn('warehouse_id', $allowedOutlets);
        }
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $srQ->where(function ($q) use ($warehouse, $warehouseIdResolved) {
                $q->where('warehouse_name', $warehouse);
                if ($warehouseIdResolved !== null) {
                    $q->orWhere('warehouse_id', $warehouseIdResolved);
                }
            });
        }
        $this->applyReportDateRange($srQ, $startDate, $endDate, 'date');
        $srTopCols = ['id', 'subtotal', 'discount', 'items_payload'];
        if ($accounting->schemaHasColumn('sale_returns', 'return_cost_total')) {
            $srTopCols[] = 'return_cost_total';
        }
        if ($accounting->schemaHasColumn('sale_returns', 'points_discount')) {
            $srTopCols[] = 'points_discount';
        }
        $unlayeredRetQtyTop = [];
        $unlayeredRetVarQtyTop = [];
        foreach ($srQ->select($srTopCols)->cursor() as $srRow) {
            $srItems = json_decode((string)$srRow->items_payload, true);
            if (!is_array($srItems)) {
                continue;
            }
            $rId = (int)$srRow->id;
            $rSub = (float)($srRow->subtotal ?? 0);
            $rDisc = (float)($srRow->discount ?? 0) + (float)($srRow->points_discount ?? 0);
            $rCostTotal = (float)($srRow->return_cost_total ?? 0);
            foreach ($srItems as $ri) {
                $pid = isset($ri['product_id']) ? (int)$ri['product_id'] : 0;
                if ($pid <= 0) {
                    continue;
                }
                $vid = !empty($ri['product_variant_id']) ? (int)$ri['product_variant_id'] : 0;
                $qty = (float)($ri['quantity'] ?? 0);
                $convRate = (float)($ri['conversion_rate'] ?? 1.0);
                if ($convRate <= 0) {
                    $convRate = 1.0;
                }
                $baseQty = (float)($ri['base_quantity'] ?? 0);
                if ($baseQty <= 0) {
                    $baseQty = $qty * $convRate;
                }
                $sub = (float)($ri['subtotal'] ?? ($qty * (float)($ri['unit_price'] ?? 0)));
                $lTax = (float)($ri['tax'] ?? 0);
                $discShare = $rSub > 0.0001 ? ($rDisc * ($sub / $rSub)) : 0.0;
                $netRev = max(0.0, $sub - $lTax - $discShare);
                if (!isset($retByProd[$pid])) {
                    $retByProd[$pid] = ['qty' => 0.0, 'rev' => 0.0];
                }
                $retByProd[$pid]['qty'] += $baseQty;
                $retByProd[$pid]['rev'] += $netRev;

                if (!isset($srclByRetAndProd[$rId][$pid])) {
                    $itemTotalCost = (float)($ri['total_cost'] ?? 0);
                    $itemBaseUnitCost = (float)($ri['base_unit_cost'] ?? 0);
                    $itemUnitCost = (float)($ri['unit_cost'] ?? $ri['cost_price'] ?? 0);
                    if ($itemTotalCost > 0) {
                        $retCogsByProd[$pid] = ($retCogsByProd[$pid] ?? 0.0) + $itemTotalCost;
                    } elseif ($itemBaseUnitCost > 0) {
                        $retCogsByProd[$pid] = ($retCogsByProd[$pid] ?? 0.0) + ($baseQty * $itemBaseUnitCost);
                    } elseif ($itemUnitCost > 0) {
                        $retCogsByProd[$pid] = ($retCogsByProd[$pid] ?? 0.0) + ($qty * $itemUnitCost);
                    } elseif ($rCostTotal > 0 && $rSub > 0.0001) {
                        $retCogsByProd[$pid] = ($retCogsByProd[$pid] ?? 0.0) + ($rCostTotal * ($sub / $rSub));
                    } elseif ($baseQty > 0) {
                        if ($vid > 0) {
                            $unlayeredRetVarQtyTop[$vid] = [
                                'pid' => $pid,
                                'qty' => ($unlayeredRetVarQtyTop[$vid]['qty'] ?? 0.0) + $baseQty,
                            ];
                        } else {
                            $unlayeredRetQtyTop[$pid] = ($unlayeredRetQtyTop[$pid] ?? 0.0) + $baseQty;
                        }
                    }
                }
            }
        }
        if (!empty($unlayeredRetVarQtyTop)) {
            $varCostMapTop = [];
            if ($accounting->schemaHasTable('product_variants')) {
                foreach (array_chunk(array_keys($unlayeredRetVarQtyTop), 400) as $vChunk) {
                    foreach (DB::table('product_variants')->whereIn('id', $vChunk)->pluck('cost_price', 'id') as $kVid => $vCost) {
                        if ((float)$vCost > 0) {
                            $varCostMapTop[(int)$kVid] = (float)$vCost;
                        }
                    }
                }
            }
            foreach ($unlayeredRetVarQtyTop as $kVid => $vInfo) {
                $vPid = (int)$vInfo['pid'];
                $vQty = (float)$vInfo['qty'];
                if (isset($varCostMapTop[$kVid])) {
                    $retCogsByProd[$vPid] = ($retCogsByProd[$vPid] ?? 0.0) + ($vQty * $varCostMapTop[$kVid]);
                } else {
                    $unlayeredRetQtyTop[$vPid] = ($unlayeredRetQtyTop[$vPid] ?? 0.0) + $vQty;
                }
            }
        }
        if (!empty($unlayeredRetQtyTop)) {
            foreach (array_chunk(array_keys($unlayeredRetQtyTop), 400) as $idChunk) {
                foreach (DB::table('products')
                    ->whereIn('id', $idChunk)
                    ->selectRaw('id, COALESCE(NULLIF(cost_price, 0), NULLIF(purchase_price, 0), 0) as u_cost')
                    ->pluck('u_cost', 'id') as $uPid => $uCost) {
                    $retCogsByProd[(int)$uPid] = ($retCogsByProd[(int)$uPid] ?? 0.0)
                        + ($unlayeredRetQtyTop[(int)$uPid] * (float)$uCost);
                }
            }
        }

        $selectColumns = [
            'sa.product_id',
            !empty($search)
                ? DB::raw("COALESCE(NULLIF(products.name, ''), NULLIF(sa.si_product_name, ''), 'Product') as product_name")
                : DB::raw("COALESCE(NULLIF(products.name, ''), '') as product_name"),
            !empty($search)
                ? DB::raw("COALESCE(NULLIF(products.code, ''), NULLIF(sa.si_product_code, ''), '') as product_code")
                : DB::raw("COALESCE(NULLIF(products.code, ''), '') as product_code"),
            'products.code as master_code',
            'products.alternative_name',
            'products.brand_name',
            'products.category_name',
            DB::raw('COALESCE(NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0) as master_cost_price'),
            DB::raw('COALESCE(NULLIF(products.retail_price, 0), NULLIF(products.selling_price, 0), 0) as master_retail_price'),
            $stockSelect,
            'products.alert_quantity',
            'products.has_variants',
            'products.image_url',
            'sa.total_sold_quantity',
            'sa.total_sales_revenue',
            'sa.total_sales_cogs',
        ];

        $formatProductRow = function ($p, float $soldQty, float $salesRev, float $salesCogs) use ($canViewCost): array {
            $code = $p->product_code ?: ($p->master_code ?: '');
            // Use weighted-average realized selling price so Units Sold x Price reconciles with Net Revenue
            $price = $soldQty > 0
                ? round($salesRev / $soldQty, 2)
                : (float)($p->master_retail_price ?? 0);
            $masterCost = (float)($p->master_cost_price ?? 0);
            $effectiveCogs = $salesCogs > 0 ? round($salesCogs, 2) : round($soldQty * $masterCost, 2);
            $grossProfit = round($salesRev - $effectiveCogs, 2);
            $marginPct = $salesRev > 0 ? round(($grossProfit / $salesRev) * 100, 2) : 0.0;

            return [
                'product_id' => (int)$p->product_id,
                'id' => (int)$p->product_id,
                'product_name' => $p->product_name ?: 'Product',
                'name' => $p->product_name ?: 'Product',
                'code' => $code,
                'product_code' => $code,
                'alternative_name' => $p->alternative_name ?? '',
                'brand_name' => $p->brand_name ?? 'Generic',
                'brand' => $p->brand_name ?? 'Generic',
                'category_name' => $p->category_name ?? 'General',
                'category' => $p->category_name ?? 'General',
                'cost_price' => $canViewCost ? ($soldQty > 0 ? round($effectiveCogs / $soldQty, 2) : $masterCost) : null,
                'total_cost' => $canViewCost ? $effectiveCogs : null,
                'gross_profit' => $canViewCost ? $grossProfit : null,
                'profit_margin' => $canViewCost ? $marginPct : null,
                'cost_masked' => !$canViewCost,
                'price' => $price,
                'retail_price' => $price,
                'stock_quantity' => (float)($p->resolved_stock_quantity ?? 0),
                'alert_quantity' => (float)($p->alert_quantity ?? 0),
                'has_variants' => (bool)($p->has_variants ?? false),
                'total_sold_quantity' => $soldQty,
                'total_quantity' => $soldQty,
                'total_sales_revenue' => $salesRev,
                'total_sales' => $salesRev,
                'total_revenue' => $salesRev,
                'image_url' => $p->image_url ?? '',
            ];
        };

        $offset = ($page - 1) * $perPage;
        $pagedTopProducts = [];
        $totalCount = 0;
        $totalRevenue = 0.0;
        $totalQuantity = 0.0;
        $hasMore = false;

        if (empty($retByProd)) {
            // Fast SQL-level summary + LIMIT/OFFSET pagination when no matching sale returns exist
            $countSqlQ = clone $query;
            $countSqlQ->whereRaw('sa.total_sold_quantity > 0.0001');

            $listSqlQ = clone $countSqlQ;
            $listSqlQ->select($selectColumns);
            if ($sortBy === 'quantity') {
                $listSqlQ->orderByDesc('sa.total_sold_quantity')->orderByDesc('sa.total_sales_revenue');
            } else {
                $listSqlQ->orderByDesc('sa.total_sales_revenue')->orderByDesc('sa.total_sold_quantity');
            }

            if ($topCap > 0) {
                if (!$skipSummary) {
                    $cappedSub = (clone $listSqlQ)
                        ->select(['sa.product_id', 'sa.total_sales_revenue', 'sa.total_sold_quantity'])
                        ->limit($topCap);
                    $agg = DB::query()->fromSub($cappedSub, 'ts_cap')->selectRaw('
                        COUNT(*) as total_products,
                        COALESCE(SUM(total_sales_revenue), 0) as total_revenue,
                        COALESCE(SUM(total_sold_quantity), 0) as total_quantity
                    ')->first();
                    $totalCount = (int)($agg->total_products ?? 0);
                    $totalRevenue = (float)($agg->total_revenue ?? 0.0);
                    $totalQuantity = (float)($agg->total_quantity ?? 0.0);
                } elseif ($knownTotal !== null && $page > 1) {
                    $totalCount = min($knownTotal, $topCap);
                } else {
                    $totalCount = min((int)(clone $countSqlQ)->count(), $topCap);
                }
            } else {
                if (!$skipSummary) {
                    $agg = (clone $countSqlQ)->selectRaw('
                        COUNT(*) as total_products,
                        COALESCE(SUM(sa.total_sales_revenue), 0) as total_revenue,
                        COALESCE(SUM(sa.total_sold_quantity), 0) as total_quantity
                    ')->first();
                    $totalCount = (int)($agg->total_products ?? 0);
                    $totalRevenue = (float)($agg->total_revenue ?? 0.0);
                    $totalQuantity = (float)($agg->total_quantity ?? 0.0);
                } elseif ($knownTotal !== null && $page > 1) {
                    $totalCount = $knownTotal;
                } else {
                    $totalCount = (int)(clone $countSqlQ)->count();
                }
            }

            $remaining = $topCap > 0 ? max(0, $topCap - $offset) : $perPage;
            $fetchLimit = min($perPage, $remaining);
            if ($fetchLimit > 0) {
                $rows = $listSqlQ->offset($offset)->limit($fetchLimit + 1)->get();
                $hasMoreFromRows = $rows->count() > $fetchLimit;
                $slice = $hasMoreFromRows ? $rows->slice(0, $fetchLimit) : $rows;
                // Fallback name/code lookup only for deleted products without products row
                $missingPids = $slice->filter(fn($r) => empty($r->product_name))->pluck('product_id')->all();
                $fallbackByPid = [];
                if (!empty($missingPids)) {
                    $fallbackByPid = DB::table('sale_items')
                        ->whereIn('product_id', $missingPids)
                        ->select(['product_id', DB::raw('MIN(product_name) as pn'), DB::raw('MIN(product_code) as pc')])
                        ->groupBy('product_id')
                        ->get()
                        ->keyBy('product_id');
                }
                foreach ($slice as $p) {
                    if (empty($p->product_name) && isset($fallbackByPid[$p->product_id])) {
                        $p->product_name = $fallbackByPid[$p->product_id]->pn ?: 'Product';
                        if (empty($p->product_code)) {
                            $p->product_code = $fallbackByPid[$p->product_id]->pc ?: '';
                        }
                    } elseif (empty($p->product_name)) {
                        $p->product_name = 'Product';
                    }
                    $soldQty = round((float)$p->total_sold_quantity, 4);
                    $salesRev = round((float)$p->total_sales_revenue, 2);
                    $salesCogs = (float)($p->total_sales_cogs ?? 0);
                    $pagedTopProducts[] = $formatProductRow($p, $soldQty, $salesRev, $salesCogs);
                }
                $hasMore = ($topCap > 0)
                    ? (($offset + count($pagedTopProducts)) < $topCap && $hasMoreFromRows)
                    : (($offset + count($pagedTopProducts)) < $totalCount);
            }
        } else {
            // Lightweight numeric-only scan to compute net qty/revenue after returns before hydrating ONLY the <= 50 page products
            $aggRaw = (clone $query)->select([
                'sa.product_id',
                'sa.total_sold_quantity',
                'sa.total_sales_revenue',
                'sa.total_sales_cogs',
            ])->get();
            $rankedTuples = [];

            foreach ($aggRaw as $p) {
                $pid = (int)$p->product_id;
                $retQty = $retByProd[$pid]['qty'] ?? 0.0;
                $retRev = $retByProd[$pid]['rev'] ?? 0.0;
                $rawSoldQty = (float)$p->total_sold_quantity;
                $rawSalesRev = (float)$p->total_sales_revenue;
                $rawSalesCogs = (float)($p->total_sales_cogs ?? 0);
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
                $retCogs = (float)($retCogsByProd[$pid] ?? 0.0);
                if ($rawSalesCogs > 0 && $retCogs > 0) {
                    $netCogs = max(0.0, round($rawSalesCogs - $retCogs, 2));
                } elseif ($rawSoldQty > 0 && $rawSalesCogs > 0) {
                    $netCogs = ($rawSalesCogs * ($soldQty / $rawSoldQty));
                } else {
                    $netCogs = 0.0;
                }

                // [0]=pid, [1]=soldQty, [2]=salesRev, [3]=netCogs
                $rankedTuples[] = [$pid, $soldQty, $salesRev, $netCogs];
            }

            usort($rankedTuples, function ($a, $b) use ($sortBy) {
                if ($sortBy === 'quantity') {
                    $cmp = $b[1] <=> $a[1];
                    return $cmp !== 0 ? $cmp : ($b[2] <=> $a[2]);
                }
                $cmp = $b[2] <=> $a[2];
                return $cmp !== 0 ? $cmp : ($b[1] <=> $a[1]);
            });

            if ($topCap > 0 && count($rankedTuples) > $topCap) {
                $rankedTuples = array_slice($rankedTuples, 0, $topCap);
            }
            if (!$skipSummary) {
                foreach ($rankedTuples as $tp) {
                    $totalQuantity += $tp[1];
                    $totalRevenue += $tp[2];
                }
            }

            $totalCount = ($skipSummary && $knownTotal !== null && $page > 1 && $topCap === 0)
                ? $knownTotal
                : count($rankedTuples);
            $pageTuples = array_slice($rankedTuples, $offset, $perPage);
            $pagePids = array_values(array_unique(array_column($pageTuples, 0)));

            if (!empty($pagePids)) {
                // Direct PK-indexed product hydration (~2ms instead of re-running the full sales JOIN subquery!)
                $prodQ = DB::table('products')->whereIn('id', $pagePids);
                if ($useWhStock) {
                    $sbHydrateSub = DB::table('stock_balances')
                        ->select(['product_id', DB::raw('SUM(quantity) as wh_stock')])
                        ->whereIn('product_id', $pagePids)
                        ->groupBy('product_id');
                    if ($warehouseIdResolved !== null) {
                        $sbHydrateSub->where('warehouse_id', $warehouseIdResolved);
                    } elseif (!empty($allowedOutlets)) {
                        $sbHydrateSub->whereIn('warehouse_id', $allowedOutlets);
                    }
                    $prodQ->leftJoinSub($sbHydrateSub, 'wh_sb', fn($j) => $j->on('wh_sb.product_id', '=', 'products.id'));
                    $hydrateStockSel = DB::raw('COALESCE(wh_sb.wh_stock, 0) as resolved_stock_quantity');
                } else {
                    $hydrateStockSel = DB::raw('COALESCE(products.stock_quantity, 0) as resolved_stock_quantity');
                }

                $hydratedById = [];
                $prodRows = $prodQ->select([
                    'products.id as product_id',
                    'products.name as product_name',
                    'products.code as product_code',
                    'products.code as master_code',
                    'products.alternative_name',
                    'products.brand_name',
                    'products.category_name',
                    DB::raw('COALESCE(NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0) as master_cost_price'),
                    DB::raw('COALESCE(NULLIF(products.retail_price, 0), NULLIF(products.selling_price, 0), 0) as master_retail_price'),
                    $hydrateStockSel,
                    'products.alert_quantity',
                    'products.has_variants',
                    'products.image_url',
                ])->get();
                foreach ($prodRows as $pr) {
                    $hydratedById[(int)$pr->product_id] = $pr;
                }

                // Fallback only if any top-selling product record was hard-deleted from products table
                $missingIds = array_values(array_diff($pagePids, array_keys($hydratedById)));
                if (!empty($missingIds)) {
                    $siFallbacks = DB::table('sale_items')
                        ->whereIn('product_id', $missingIds)
                        ->select(['product_id', DB::raw('MIN(product_name) as pn'), DB::raw('MIN(product_code) as pc')])
                        ->groupBy('product_id')
                        ->get();
                    foreach ($siFallbacks as $sf) {
                        $hydratedById[(int)$sf->product_id] = (object)[
                            'product_id' => (int)$sf->product_id,
                            'product_name' => $sf->pn ?: 'Product',
                            'product_code' => $sf->pc ?: '',
                            'master_code' => $sf->pc ?: '',
                            'alternative_name' => '',
                            'brand_name' => 'Generic',
                            'category_name' => 'General',
                            'master_cost_price' => 0.0,
                            'master_retail_price' => 0.0,
                            'resolved_stock_quantity' => 0.0,
                            'alert_quantity' => 0.0,
                            'has_variants' => false,
                            'image_url' => '',
                        ];
                    }
                }

                foreach ($pageTuples as $tp) {
                    $row = $hydratedById[$tp[0]] ?? null;
                    if ($row !== null) {
                        $pagedTopProducts[] = $formatProductRow($row, $tp[1], $tp[2], $tp[3]);
                    }
                }
            }
            $hasMore = ($offset + count($pagedTopProducts)) < $totalCount;
        }

        $lastPage = max(1, (int)ceil($totalCount / max(1, $perPage)));
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
        $statusFilter = trim((string)$request->query('status', 'All'));
        if (strcasecmp($statusFilter, 'Deficit') === 0) {
            $statusFilter = 'Loss';
        }
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
        $warehouseId = $this->resolveWarehouseIdParam($warehouse);

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
        $this->applyWarehouseFilter($salesQuery, $warehouse, $warehouseId, 'sales.warehouse_name', 'sales.warehouse_id');
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
        $cogsMonthlyMap = [];
        $taxMonthlyMap = [];

        foreach ($salesMonthly as $sm) {
            $ordersCount += (int)$sm->orders_count;
            $grossSales += (float)$sm->gross_sales;
            $smTax = (float)($sm->tax_collected ?? 0);
            $taxCollected += $smTax;
            $smCogs = (float)$sm->total_cogs;
            $cogs += $smCogs;
            $mKey = $sm->month_period . '_' . $sm->w_name;
            $cogsMonthlyMap[$mKey] = ($cogsMonthlyMap[$mKey] ?? 0.0) + $smCogs;
            $taxMonthlyMap[$mKey] = ($taxMonthlyMap[$mKey] ?? 0.0) + $smTax;
        }

        // 2. Sales Returns Monthly & Global Total (Tax-Exclusive & ex-Shipping matching P&L Revenue, with unlayered COGS + line-tax support)
        $srAdj = $this->computeSaleReturnAdjustments(
            $startDate,
            $endDate,
            $warehouse,
            $warehouseId,
            $allowedOutlets,
            true,
            false
        );
        $returnsMonthly = collect($srAdj['by_month_warehouse'])->map(fn($r) => (object)[
            'month_period' => $r['month_period'],
            'w_name' => $r['w_name'],
            'returns_total' => $r['returns_total'],
            'returns_tax_total' => $r['returns_tax'],
            'returned_cogs_total' => $r['returned_cogs'],
        ]);

        $saleReturns = (float)$srAdj['returns_total'];
        $taxCollected = round(max(0.0, $taxCollected - (float)$srAdj['returns_tax']), 2);
        $returnedCogs = (float)$srAdj['returned_cogs'];
        foreach ($returnsMonthly as $rKey => $rRow) {
            $retCogs = (float)($rRow->returned_cogs_total ?? 0);
            if ($retCogs != 0.0) {
                $cogsMonthlyMap[$rKey] = round(($cogsMonthlyMap[$rKey] ?? 0.0) - $retCogs, 2);
            }
            $retTax = (float)($rRow->returns_tax_total ?? 0);
            if ($retTax != 0.0) {
                $taxMonthlyMap[$rKey] = max(0.0, round(($taxMonthlyMap[$rKey] ?? 0.0) - $retTax, 2));
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
        $this->applyWarehouseFilter($purchQuery, $warehouse, $warehouseId, 'purchases.warehouse_name', 'purchases.warehouse_id');
        if (!empty($allowedOutlets)) {
            $purchQuery->whereIn('purchases.warehouse_id', $allowedOutlets);
        }
        $applyDateScope($purchQuery, 'purchases.date');

        $grossPurchasesMonthly = (clone $purchQuery)
            ->selectRaw("SUBSTR(purchases.date, 1, 7) as month_period, COALESCE(purchases.warehouse_name, 'Main Branch Outlet') as w_name, COALESCE(SUM({$netPurchSql}), 0) as gross_purchases")
            ->groupBy(DB::raw("SUBSTR(purchases.date, 1, 7)"), 'purchases.warehouse_name')
            ->get()
            ->keyBy(fn($r) => $r->month_period . '_' . $r->w_name);
        $grossPurchases = (float)$grossPurchasesMonthly->sum('gross_purchases');

        $prQuery = DB::table('purchase_returns')->whereIn('status', ['Completed', 'Received']);
        $this->applyWarehouseFilter($prQuery, $warehouse, $warehouseId, 'warehouse_name', 'warehouse_id');
        if (!empty($allowedOutlets)) {
            $prQuery->whereIn('warehouse_id', $allowedOutlets);
        }
        $applyDateScope($prQuery, 'date');
        $purchaseAccounting = $this->accounting();
        $prMonthlyAgg = (clone $prQuery)
            ->selectRaw("
                SUBSTR(date, 1, 7) as month_period,
                COALESCE(warehouse_name, 'Main Branch Outlet') as w_name,
                COALESCE(SUM(CASE WHEN COALESCE(is_tax_recoverable, 0) = 0 THEN grand_total ELSE 0 END), 0) as non_rec_total,
                COALESCE(SUM(COALESCE(cost_variance, 0)), 0) as pr_variance
            ")
            ->groupBy(DB::raw("SUBSTR(date, 1, 7)"), 'warehouse_name')
            ->get()
            ->keyBy(fn($r) => $r->month_period . '_' . $r->w_name);

        $prReturnsMonthlyMap = [];
        $purchaseReturns = 0.0;
        $purchaseReturnVariance = 0.0;
        foreach ($prMonthlyAgg as $mKey => $row) {
            $nonRec = (float)($row->non_rec_total ?? 0.0);
            $prReturnsMonthlyMap[$mKey] = ($prReturnsMonthlyMap[$mKey] ?? 0.0) + $nonRec;
            $purchaseReturns += $nonRec;
            $purchaseReturnVariance += (float)($row->pr_variance ?? 0.0);
        }
        $purchaseReturnVariance = round($purchaseReturnVariance, 2);
        foreach ((clone $prQuery)->where('is_tax_recoverable', 1)->select(['date', 'warehouse_name', 'grand_total', 'tax_amount', 'is_tax_recoverable', 'items_payload'])->cursor() as $prRow) {
            $netPr = $purchaseAccounting->netPurchaseReturnAmount($prRow);
            $purchaseReturns += $netPr;
            $mKey = substr((string)($prRow->date ?? ''), 0, 7) . '_' . (!empty($prRow->warehouse_name) ? $prRow->warehouse_name : 'Main Branch Outlet');
            $prReturnsMonthlyMap[$mKey] = ($prReturnsMonthlyMap[$mKey] ?? 0.0) + $netPr;
        }
        $purchaseReturns = round($purchaseReturns, 2);
        $prVarianceMonthly = $prMonthlyAgg;
        $netPurchases = round($grossPurchases - $purchaseReturns, 2);

        // 4. Fallback Cost of Goods Sold (COGS) via FIFO Cost Snapshot / Base Quantities (handles both uncosted & mixed invoices)
        $missingCogsRes = $this->computeMixedInvoiceMissingCogs(
            $startDate,
            $endDate,
            $warehouse,
            $warehouseId,
            $allowedOutlets,
            true,
            false
        );
        foreach ($missingCogsRes['by_month_warehouse'] ?? [] as $mKey => $mcCogs) {
            $mcVal = (float)$mcCogs;
            $cogs += $mcVal;
            $cogsMonthlyMap[$mKey] = ($cogsMonthlyMap[$mKey] ?? 0.0) + $mcVal;
        }
        $cogs = round($cogs, 2);

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
        $this->applyWarehouseFilter($expQuery, $warehouse, $warehouseId, 'warehouse_name', 'warehouse_id');
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
        $this->applyWarehouseFilter($adjBaseQuery, $warehouse, $warehouseId, 'stock_adjustments.warehouse_name', 'stock_adjustments.warehouse_id');
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

        // Reconcile top KPI cards & secondary aggregates whenever statusFilter or search narrows the monthly ledger
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

            $matchedKeys = [];
            foreach ($periods as $pRow) {
                $matchedKeys[$pRow['period'] . '_' . $pRow['warehouse_name']] = true;
            }

            $taxCollected = 0.0;
            $grossPurchases = 0.0;
            $purchaseReturns = 0.0;
            foreach ($matchedKeys as $mk => $_) {
                $taxCollected += (float)($taxMonthlyMap[$mk] ?? 0.0);
                $gpObj = $grossPurchasesMonthly->get($mk);
                $grossPurchases += $gpObj ? (float)$gpObj->gross_purchases : 0.0;
                $purchaseReturns += (float)($prReturnsMonthlyMap[$mk] ?? 0.0);
            }
            $taxCollected = round(max(0.0, $taxCollected), 2);
            $grossPurchases = round($grossPurchases, 2);
            $purchaseReturns = round($purchaseReturns, 2);
            $netPurchases = round($grossPurchases - $purchaseReturns, 2);

            if (empty($matchedKeys)) {
                $expenseCategories = [];
            } else {
                $filteredExpCatRows = (clone $expQuery)
                    ->selectRaw("SUBSTR(date, 1, 7) as month_period, COALESCE(warehouse_name, 'Main Branch Outlet') as w_name, COALESCE(expense_category_name, 'General Expenses') as category, COALESCE(SUM(amount), 0) as total_amount, COUNT(id) as count")
                    ->groupBy(DB::raw("SUBSTR(date, 1, 7)"), 'warehouse_name', DB::raw("COALESCE(expense_category_name, 'General Expenses')"))
                    ->get();
                $catMap = [];
                foreach ($filteredExpCatRows as $fec) {
                    $mk = $fec->month_period . '_' . $fec->w_name;
                    if (!isset($matchedKeys[$mk])) {
                        continue;
                    }
                    $cat = $fec->category;
                    if (!isset($catMap[$cat])) {
                        $catMap[$cat] = ['category' => $cat, 'amount' => 0.0, 'count' => 0];
                    }
                    $catMap[$cat]['amount'] += (float)$fec->total_amount;
                    $catMap[$cat]['count'] += (int)$fec->count;
                }
                usort($catMap, fn($a, $b) => $b['amount'] <=> $a['amount']);
                $expenseCategories = array_map(fn($c) => [
                    'category' => $c['category'],
                    'amount' => round((float)$c['amount'], 2),
                    'count' => (int)$c['count'],
                ], array_values($catMap));
            }
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

        // Compute authoritative effective stock from stock_balances (variant table has no stock_quantity column)
        if ($warehouseStocks->isNotEmpty()) {
            $effectiveStockQty = round((float)$warehouseStocks->sum('quantity'), 4);
        } elseif ($warehouseId !== null || !empty($allowedOutlets)) {
            $effectiveStockQty = 0.0;
        } elseif ($selectedVariantId !== null) {
            $effectiveStockQty = (float)DB::table('stock_balances')
                ->where('product_id', $product->id)
                ->where('product_variant_id', $selectedVariantId)
                ->sum('quantity');
        } else {
            $hasAnyBalance = DB::table('stock_balances')->where('product_id', $product->id)->exists();
            $effectiveStockQty = $hasAnyBalance
                ? (float)DB::table('stock_balances')->where('product_id', $product->id)->sum('quantity')
                : (float)($product->stock_quantity ?? 0.0);
        }

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
                            'warehouse_name' => $matched ? $matched->name : ($warehouseName ?: ('Warehouse #' . $warehouseId)),
                            'quantity' => 0.0,
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
                        'warehouse_id' => $warehouseId ?? 1,
                        'warehouse_name' => $warehouseName ?: 'Default Warehouse',
                        'quantity' => $warehouseId !== null ? 0.0 : $effectiveStockQty,
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

        // 2. Movements: Sales (Active completed sales only, normalized to canonical base units)
        $salesQuery = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id');
        $this->applyActiveDocumentFilter($salesQuery, 'sales.status');

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
            DB::raw('COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity * COALESCE(NULLIF(sale_items.conversion_rate, 0), 1)) as quantity'),
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
        if ($product->variants && $product->variants->isNotEmpty()) {
            $vsQ = DB::table('stock_balances')
                ->where('product_id', $product->id)
                ->whereNotNull('product_variant_id');
            if ($warehouseId !== null) {
                $vsQ->where('warehouse_id', $warehouseId);
            } elseif (!empty($allowedOutlets)) {
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
                $vStock = (float)($variantStockMap[$v->id] ?? 0.0);
                $mappedVariants[] = [
                    'id' => (int)$v->id,
                    'name' => (string)$v->name,
                    'sku' => (string)($v->sku ?? ''),
                    'barcode' => (string)($v->barcode ?? ''),
                    'cost_price' => $canViewCost ? (float)($v->cost_price ?: $product->cost_price ?: 0.0) : 0.0,
                    'retail_price' => (float)($v->selling_price ?: $product->retail_price ?: $product->selling_price ?: 0.0),
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
                    'cost_price' => $canViewCost ? (float)($selectedVariant ? ($selectedVariant->cost_price ?: $product->cost_price) : $product->cost_price) : 0.0,
                    'retail_price' => (float)($selectedVariant ? ($selectedVariant->selling_price ?: $product->retail_price ?: $product->selling_price) : ($product->retail_price ?: $product->selling_price)),
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
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;
        $scopeWhIds = $warehouseId !== null ? [$warehouseId] : $allowedOutlets;
        $hasVariantsTable = $this->accounting()->schemaHasTable('product_variants');
        $varNameExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "(products.name || ' (' || product_variants.name || ')')"
            : "CONCAT(products.name, ' (', product_variants.name, ')')";

        $buildAlertQuery = function () use ($threshold, $scopeWhIds, $category, $search, $hasVariantsTable, $varNameExpr) {
            $effProdThreshold = 'COALESCE(NULLIF(products.alert_quantity, 0), NULLIF(products.stock_alert, 0), NULLIF(products.low_qty, 0), ?)';
            $effSimpleThreshold = 'COALESCE(NULLIF(alert_quantity, 0), NULLIF(stock_alert, 0), NULLIF(low_qty, 0), ?)';
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
                        {$effProdThreshold} as min_stock,
                        COALESCE(products.unit_name, 'pcs') as unit,
                        0 as is_variant
                    ", [$threshold])
                    ->groupBy(
                        'products.id', 'products.name', 'products.code', 'products.sku', 'products.category_name',
                        'products.unit_name', 'products.alert_quantity', 'products.stock_alert', 'products.low_qty'
                    )
                    ->havingRaw("COALESCE(SUM(stock_balances.quantity), 0) <= {$effProdThreshold}", [$threshold]);
            } else {
                $singleQuery = DB::table('products')
                    ->where(function ($q) {
                        $q->where('has_variants', false)->orWhereNull('has_variants');
                    })
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($threshold, $effSimpleThreshold) {
                        $q->whereRaw("COALESCE(stock_quantity, 0) <= {$effSimpleThreshold}", [$threshold])
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
                    {$effSimpleThreshold} as min_stock,
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
                        {$effProdThreshold} as min_stock,
                        COALESCE(products.unit_name, 'pcs') as unit,
                        1 as is_variant
                    ", [$threshold])
                    ->groupBy(
                        'product_variants.id', 'products.name', 'product_variants.name', 'product_variants.sku',
                        'products.code', 'products.category_name', 'products.unit_name',
                        'products.alert_quantity', 'products.stock_alert', 'products.low_qty'
                    )
                    ->havingRaw("COALESCE(SUM(stock_balances.quantity), 0) <= {$effProdThreshold}", [$threshold]);

                return $singleQuery->unionAll($varQ);
            }

            return $singleQuery;
        };

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
        if (!$hasMore && ($page === 1 || $knownTotal === null)) {
            $totalCount = (($page - 1) * $perPage) + $pageRows->count();
        } elseif ($knownTotal !== null && ($skipSummary || $page > 1)) {
            $totalCount = $knownTotal;
        } elseif ($knownTotal !== null) {
            $totalCount = $knownTotal;
        } else {
            $totalCount = (int)DB::query()->fromSub($buildAlertQuery(), 'alerts')->count();
        }
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
        $accounting = $this->accounting();
        $warehouse = $request->input('warehouse', 'All');
        $customer = $request->input('customer', 'All');
        $status = $request->input('status', 'All');
        $paymentStatus = $request->input('payment_status', 'All');
        $paymentType = $request->input('payment_type') ?? $request->input('refund_method', 'All');
        $userName = $request->input('user_name', 'All');
        $startDate = $request->input('start_date') ?? $request->input('date_from');
        $endDate = $request->input('end_date') ?? $request->input('date_to');
        $search = trim((string)$request->input('search', ''));
        $page = max(1, (int)$request->input('page', 1));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        // Enforce outlet branch isolation (403) before executing queries
        $requestedWhId = null;
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $requestedWhId = $this->resolveWarehouseIdParam((string)$warehouse);
            if (!empty($allowedOutlets)) {
                if ($requestedWhId !== null && !in_array($requestedWhId, $allowedOutlets, true)) {
                    return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized outlet access.'], 403);
                }
                if ($requestedWhId === null && is_numeric($warehouse) && !in_array((int)$warehouse, $allowedOutlets, true)) {
                    return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized outlet access.'], 403);
                }
                if ($requestedWhId === null && !is_numeric($warehouse)) {
                    $allowedNames = DB::table('outlets')
                        ->whereIn('id', $allowedOutlets)
                        ->pluck('name')
                        ->map(fn ($n) => strtolower(trim((string)$n)))
                        ->all();
                    if (!in_array(strtolower(trim((string)$warehouse)), $allowedNames, true)) {
                        return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized outlet access.'], 403);
                    }
                }
            }
        }
        if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'All') {
            $explicitWhId = (int)$request->input('warehouse_id');
            if (!empty($allowedOutlets) && !in_array($explicitWhId, $allowedOutlets, true)) {
                return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized outlet access.'], 403);
            }
        }

        $query = DB::table('sale_returns');
        if (!empty($allowedOutlets)) {
            $query->whereIn('warehouse_id', $allowedOutlets);
        }

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $whId = $requestedWhId;
            if ($whId !== null) {
                $query->where(function ($q) use ($warehouse, $whId) {
                    $q->where('warehouse_id', $whId)
                      ->orWhere('warehouse_name', $warehouse);
                });
            } elseif (is_numeric($warehouse)) {
                $query->where('warehouse_id', -1);
            } else {
                $query->where('warehouse_name', $warehouse);
            }
        }

        if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'All') {
            $query->where('warehouse_id', (int)$request->input('warehouse_id'));
        }

        if ($customer !== 'All' && !empty($customer)) {
            $query->where(function ($q) use ($customer) {
                if (is_numeric($customer)) {
                    $q->where('customer_id', (int)$customer)
                      ->orWhere('customer_name', (string)$customer);
                } else {
                    $q->where('customer_name', $customer);
                }
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

        $hasPaymentTypeFilter = $paymentType !== 'All' && !empty($paymentType);
        $isSplitFilter = $hasPaymentTypeFilter && strcasecmp((string)$paymentType, 'Split Payment') === 0;
        $isStoreCreditFilter = $hasPaymentTypeFilter && strcasecmp((string)$paymentType, 'Store Credit') === 0;

        if ($hasPaymentTypeFilter) {
            $query->where(function ($q) use ($paymentType, $isSplitFilter, $isStoreCreditFilter) {
                $q->where('payment_type', $paymentType)
                  ->orWhere('refund_tender', $paymentType);
                if ($isStoreCreditFilter) {
                    $q->orWhere('due_amount', '>', 0.005);
                } elseif (!$isSplitFilter) {
                    $q->orWhere(function ($sq) use ($paymentType) {
                        $sq->where('payment_type', 'Split Payment')
                           ->where('payments_payload', 'like', '%' . addcslashes((string)$paymentType, '%_\\') . '%');
                    });
                }
            });
        }

        if ($userName !== 'All' && !empty($userName)) {
            $query->where(function ($q) use ($userName) {
                $q->where('created_by', $userName)
                  ->orWhere('user_name', $userName);
            });
        }

        $this->applyReportDateRange($query, $startDate, $endDate, 'date');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('sale_reference_no', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('return_reason', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $totalReturnsCount = 0;
        $totalReturnedAmt = 0.0;
        $totalPaidAmt = 0.0;
        $totalDueAmt = 0.0;
        $cashRefunded = 0.0;
        $digitalRefunded = 0.0;
        $storeCreditIssued = 0.0;
        $arCreditOffsetAmt = 0.0;
        $avgReturnAmt = 0.0;
        $byPaymentMethodList = [];
        $byProductList = [];

        $isSingleTenderFilter = $hasPaymentTypeFilter && !$isSplitFilter;
        $hasSrAppliedCreditCol = $accounting->schemaHasColumn('sale_returns', 'applied_credit_amount');

        if (!$skipSummary && $page === 1) {
            // Single streaming pass computes KPI totals + tender reconciliation + by-product breakdown together (avoids double scan)
            $tenderMap = [];
            $productMap = [];
            $srSummaryCols = [
                'id',
                'grand_total',
                'paid_amount',
                'due_amount',
                'payment_type',
                'refund_tender',
                DB::raw("CASE WHEN payment_type = 'Split Payment' THEN payments_payload ELSE NULL END as payments_payload"),
                'items_payload',
            ];
            if ($hasSrAppliedCreditCol) {
                $srSummaryCols[] = 'applied_credit_amount';
            }
            $payloadCursor = (clone $query)
                ->select($srSummaryCols)
                ->cursor();

            $filteredTenderPaidSum = 0.0;
            foreach ($payloadCursor as $row) {
                $rowId = (int)$row->id;
                $rowGrand = (float)($row->grand_total ?? 0);
                $rowPaid = (float)($row->paid_amount ?? 0);
                $rowDue = (float)($row->due_amount ?? 0);
                $rowAppliedCredit = $hasSrAppliedCreditCol ? max(0.0, (float)($row->applied_credit_amount ?? 0)) : 0.0;
                $ptRaw = trim((string)($row->payment_type ?? ''));
                $rtRaw = trim((string)($row->refund_tender ?? ''));
                $fallbackMethod = ($ptRaw !== '' && ($rtRaw === '' || (strcasecmp($rtRaw, 'Cash') === 0 && strcasecmp($ptRaw, 'Cash') !== 0)))
                    ? $ptRaw
                    : ($rtRaw !== '' ? $rtRaw : 'Cash');

                $totalReturnsCount++;
                $totalReturnedAmt += $rowGrand;
                $totalPaidAmt += $rowPaid;
                $totalDueAmt += $rowDue;
                $arCreditOffsetAmt += $rowAppliedCredit;

                $matchedTenderPaid = $this->accumulateReturnTenderRow(
                    $tenderMap,
                    $rowId,
                    $rowGrand,
                    $rowPaid,
                    $fallbackMethod,
                    $row->payments_payload ?? null,
                    'Store Credit',
                    $rowAppliedCredit,
                    $isSingleTenderFilter ? (string)$paymentType : null
                );
                if ($isSingleTenderFilter) {
                    $filteredTenderPaidSum += $matchedTenderPaid;
                }

                if (!empty($row->items_payload) && $row->items_payload !== '[]') {
                    $decoded = is_string($row->items_payload)
                        ? json_decode($row->items_payload, true)
                        : $row->items_payload;
                    if (is_array($decoded)) {
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
                }
            }

            $totalReturnedAmt = round($totalReturnedAmt, 2);
            $totalPaidAmt = round($totalPaidAmt, 2);
            $filteredTenderPaidSum = round($filteredTenderPaidSum, 2);
            $totalDueAmt = round($totalDueAmt, 2);
            $arCreditOffsetAmt = round($arCreditOffsetAmt, 2);
            $avgReturnAmt = $totalReturnsCount > 0 ? round($totalReturnedAmt / $totalReturnsCount, 2) : 0.0;

            $tenderPercentDenominator = $totalReturnedAmt > 0 ? $totalReturnedAmt : $totalPaidAmt;
            $paidStoreCreditAmt = 0.0;
            foreach ($tenderMap as $methodName => $pb) {
                $pRefunded = round((float)$pb['total_refunded'], 2);
                $pReturned = round((float)$pb['total_returned'], 2);
                $mLower = strtolower(trim($methodName));
                if ($accounting->isCashPaymentType($methodName)) {
                    $cashRefunded += $pRefunded;
                } elseif (in_array($mLower, ['store credit', 'credit note'], true)) {
                    $paidStoreCreditAmt += $pRefunded;
                } elseif (!in_array($mLower, ['', 'ar credit offset', 'credit settled', 'unpaid', 'n/a', 'split payment'], true)) {
                    $digitalRefunded += $pRefunded;
                }
                $shareBasis = $totalReturnedAmt > 0 ? $pReturned : $pRefunded;
                $byPaymentMethodList[] = [
                    'payment_type' => $methodName,
                    'count' => count($pb['voucher_ids']),
                    'total' => $pRefunded,
                    'total_amount' => $pRefunded,
                    'total_returned' => $pReturned,
                    'percentage' => $tenderPercentDenominator > 0
                        ? round(($shareBasis / $tenderPercentDenominator) * 100, 2)
                        : 0.0,
                ];
            }
            $storeCreditIssued = round($totalDueAmt + $paidStoreCreditAmt, 2);
            usort($byPaymentMethodList, static function ($a, $b) {
                $cmp = $b['total_amount'] <=> $a['total_amount'];
                return $cmp !== 0 ? $cmp : ($b['total_returned'] <=> $a['total_returned']);
            });

            foreach ($productMap as $prod) {
                $amt = round((float)$prod['total_amount'], 2);
                $byProductList[] = [
                    'product_name' => $prod['product_name'],
                    'product_code' => $prod['product_code'],
                    'quantity' => (float)$prod['quantity'],
                    'total_amount' => $amt,
                    'total' => $amt,
                    'percentage' => $totalReturnedAmt > 0 ? round(($amt / $totalReturnedAmt) * 100, 2) : 0.0,
                ];
            }
            usort($byProductList, static fn($a, $b) => $b['total_amount'] <=> $a['total_amount']);
        } elseif ($knownTotal !== null) {
            $totalReturnsCount = $knownTotal;
        } else {
            $totalReturnsCount = (int)(clone $query)->count();
        }

        // Paginated Results (Explicit projection excluding bulky payloads)
        $listQuery = (clone $query)->select([
            'id', 'reference_no', 'sale_reference_no', 'customer_id', 'customer_name',
            'warehouse_id', 'warehouse_name', 'date', 'status', 'grand_total', 'paid_amount',
            'due_amount', 'payment_status', 'payment_type', 'return_reason', 'notes', 'created_by',
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
                'return_reason' => (string)($r->return_reason ?? ''),
                'notes' => $r->notes ?? '',
                'created_by' => $r->created_by ?? ($r->user_name ?? 'Super Admin'),
                'user_id' => $r->user_id ?? null,
                'user_name' => $r->user_name ?? ($r->created_by ?? 'Super Admin'),
                'user_role' => $r->user_role ?? 'Super Admin',
                'created_at' => $r->created_at ?? null,
                'updated_at' => $r->updated_at ?? null,
            ];
        })->all();

        $availableCustomers = [];
        $availablePaymentTypes = [];
        $availableUsers = [];
        if (!$skipSummary && $page === 1) {
            // Index-fast lookups instead of unindexed full-table scans on sale_returns.customer_name / coalesce(user_name, created_by)
            $customerNames = DB::table('customers')
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->orderBy('name')
                ->limit(500)
                ->pluck('name')
                ->merge(
                    DB::table('sale_returns')
                        ->whereNull('customer_id')
                        ->whereNotNull('customer_name')
                        ->where('customer_name', '!=', '')
                        ->distinct()
                        ->limit(50)
                        ->pluck('customer_name')
                )
                ->map(fn ($v) => trim((string)$v))
                ->filter(fn ($v) => $v !== '')
                ->unique()
                ->values()
                ->all();
            natcasesort($customerNames);
            $availableCustomers = array_values($customerNames);

            $availablePaymentTypes = DB::table('sale_returns')
                ->whereNotNull('payment_type')
                ->where('payment_type', '!=', '')
                ->distinct()
                ->limit(50)
                ->pluck('payment_type')
                ->merge(
                    DB::table('sale_returns')
                        ->whereNotNull('refund_tender')
                        ->where('refund_tender', '!=', '')
                        ->distinct()
                        ->limit(50)
                        ->pluck('refund_tender')
                )
                ->unique()
                ->sort()
                ->values()
                ->all();

            $userNamesList = DB::table('sale_returns')
                ->whereNotNull('created_by')
                ->where('created_by', '!=', '')
                ->distinct()
                ->limit(200)
                ->pluck('created_by')
                ->merge(
                    DB::table('users')
                        ->whereNotNull('name')
                        ->where('name', '!=', '')
                        ->limit(200)
                        ->pluck('name')
                )
                ->map(fn ($v) => trim((string)$v))
                ->filter(fn ($v) => $v !== '')
                ->unique()
                ->values()
                ->all();
            natcasesort($userNamesList);
            $availableUsers = array_values($userNamesList);
        }

        return $this->noStoreJson([
            'status' => 'success',
            'summary' => [
                'total_returns' => $totalReturnsCount,
                'total_returned_amount' => $totalReturnedAmt,
                'total_paid_amount' => $totalPaidAmt,
                'total_due_amount' => $totalDueAmt,
                'cash_refunded' => round($cashRefunded, 2),
                'digital_refunded' => round($digitalRefunded, 2),
                'store_credit_issued' => round($storeCreditIssued, 2),
                'ar_credit_offset' => round($arCreditOffsetAmt, 2),
                'filtered_tender_amount' => $isSingleTenderFilter ? round($filteredTenderPaidSum, 2) : $totalPaidAmt,
                'average_return_amount' => $avgReturnAmt,
            ],
            'available_customers' => $availableCustomers,
            'available_payment_types' => $availablePaymentTypes,
            'available_users' => $availableUsers,
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
        $paymentType = $request->input('payment_type') ?? $request->input('refund_method', 'All');
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
        $resolvedWarehouseId = $this->resolveWarehouseIdParam(is_scalar($warehouse) ? (string)$warehouse : null);
        if (!empty($allowedOutlets)) {
            if ($resolvedWarehouseId !== null && !in_array($resolvedWarehouseId, $allowedOutlets, true)) {
                return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                if ($resolvedWarehouseId === null && is_numeric($warehouse) && !in_array((int)$warehouse, $allowedOutlets, true)) {
                    return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
                }
                if ($resolvedWarehouseId === null && !is_numeric($warehouse)) {
                    $allowedNames = DB::table('outlets')
                        ->whereIn('id', $allowedOutlets)
                        ->pluck('name')
                        ->map(fn ($n) => strtolower(trim((string)$n)))
                        ->all();
                    if (!in_array(strtolower(trim((string)$warehouse)), $allowedNames, true)) {
                        return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
                    }
                }
            }
        }

        $query = DB::table('purchase_returns');
        if (!empty($allowedOutlets)) {
            $query->whereIn('warehouse_id', $allowedOutlets);
        }

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $query->where(function ($q) use ($warehouse, $resolvedWarehouseId) {
                $q->where('warehouse_name', (string)$warehouse);
                if ($resolvedWarehouseId !== null) {
                    $q->orWhere('warehouse_id', $resolvedWarehouseId)
                      ->orWhere('outlet_id', $resolvedWarehouseId);
                }
            });
        }

        if ($supplier !== 'All' && !empty($supplier)) {
            $query->where(function ($q) use ($supplier) {
                if (is_numeric($supplier)) {
                    $q->where('supplier_id', (int)$supplier)
                      ->orWhere('supplier_name', (string)$supplier);
                } else {
                    $q->where('supplier_name', (string)$supplier);
                }
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
                if (strcasecmp((string)$paymentType, 'Credit Note') === 0) {
                    $q->orWhere('due_amount', '>', 0.005);
                } elseif ($paymentType !== 'Split Payment') {
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
        $cashRefunded = 0.0;
        $digitalRefunded = 0.0;
        $creditNoteIssued = 0.0;
        $avgReturnAmt = 0.0;
        $byPaymentMethodList = [];
        $byProductList = [];

        $hasPrPayFilter = $paymentType !== 'All' && !empty($paymentType);
        $isPrSingleTenderFilter = $hasPrPayFilter && strcasecmp((string)$paymentType, 'Split Payment') !== 0;

        if (!$skipSummary && $page === 1) {
            // Single streaming pass computes KPI totals + Split Payment & Product breakdowns together (avoids double scan)
            $tenderMap = [];
            $productMap = [];

            $payloadCursor = (clone $query)
                ->select([
                    'id',
                    'grand_total',
                    'paid_amount',
                    'due_amount',
                    'payment_type',
                    DB::raw("CASE WHEN payment_type = 'Split Payment' THEN payments_payload ELSE NULL END as payments_payload"),
                    'items_payload',
                ])
                ->cursor();

            $filteredPrTenderPaidSum = 0.0;
            foreach ($payloadCursor as $row) {
                $rowId = (int)$row->id;
                $rowGrand = (float)($row->grand_total ?? 0);
                $rowPaid = (float)($row->paid_amount ?? 0);
                $rowDue = (float)($row->due_amount ?? 0);
                $fallbackMethod = trim((string)($row->payment_type ?? ''));
                if ($fallbackMethod === '') {
                    $fallbackMethod = 'Cash';
                }

                $totalReturnsCount++;
                $totalReturnedAmt += $rowGrand;
                $totalPaidAmt += $rowPaid;
                $totalDueAmt += $rowDue;

                // A. Settlement / Refund Tender attribution (splitting Split Payment payloads by leg)
                $matchedPrTenderPaid = $this->accumulateReturnTenderRow(
                    $tenderMap,
                    $rowId,
                    $rowGrand,
                    $rowPaid,
                    $fallbackMethod,
                    $row->payments_payload ?? null,
                    'Credit Note',
                    0.0,
                    $isPrSingleTenderFilter ? (string)$paymentType : null
                );
                if ($isPrSingleTenderFilter) {
                    $filteredPrTenderPaidSum += $matchedPrTenderPaid;
                }

                // B. Product Return Breakdown
                if (!empty($row->items_payload) && $row->items_payload !== '[]') {
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

            $totalReturnedAmt = round($totalReturnedAmt, 2);
            $totalPaidAmt = round($totalPaidAmt, 2);
            $filteredPrTenderPaidSum = round($filteredPrTenderPaidSum, 2);
            $totalDueAmt = round($totalDueAmt, 2);
            $avgReturnAmt = $totalReturnsCount > 0 ? round($totalReturnedAmt / $totalReturnsCount, 2) : 0.0;

            $tenderPercentDenominator = $totalReturnedAmt > 0 ? $totalReturnedAmt : $totalPaidAmt;
            $paidCreditNoteAmt = 0.0;
            foreach ($tenderMap as $methodName => $pb) {
                $pRefunded = round((float)$pb['total_refunded'], 2);
                $pReturned = round((float)$pb['total_returned'], 2);
                $mLower = strtolower(trim($methodName));
                if ($accounting->isCashPaymentType($methodName)) {
                    $cashRefunded += $pRefunded;
                } elseif (in_array($mLower, ['credit note', 'store credit'], true)) {
                    $paidCreditNoteAmt += $pRefunded;
                } elseif (!in_array($mLower, ['', 'ap credit offset', 'credit settled', 'unpaid', 'n/a', 'split payment'], true)) {
                    $digitalRefunded += $pRefunded;
                }
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
            $creditNoteIssued = round($totalDueAmt + $paidCreditNoteAmt, 2);
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
            'cash_refunded' => round($cashRefunded, 2),
            'digital_refunded' => round($digitalRefunded, 2),
            'credit_note_issued' => round($creditNoteIssued, 2),
            'filtered_tender_amount' => $isPrSingleTenderFilter ? round($filteredPrTenderPaidSum, 2) : $totalPaidAmt,
            'average_return_amount' => $avgReturnAmt,
        ], $canViewCost, ['total_returns']);

        $availableSuppliers = [];
        $availablePaymentTypes = [];
        $availableUsers = [];
        if (!$skipSummary && $page === 1) {
            $availableSuppliers = DB::table('purchase_returns')
                ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('warehouse_id', $allowedOutlets))
                ->whereNotNull('supplier_name')
                ->where('supplier_name', '!=', '')
                ->distinct()
                ->orderBy('supplier_name')
                ->pluck('supplier_name')
                ->values()
                ->all();

            $availablePaymentTypes = DB::table('purchase_returns')
                ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('warehouse_id', $allowedOutlets))
                ->whereNotNull('payment_type')
                ->where('payment_type', '!=', '')
                ->distinct()
                ->limit(50)
                ->pluck('payment_type')
                ->unique()
                ->sort()
                ->values()
                ->all();

            // Index-only scans on idx_pr_user_name + idx_pr_created_by instead of unindexed COALESCE full scan
            $userNamesCol = DB::table('purchase_returns')
                ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('warehouse_id', $allowedOutlets))
                ->whereNotNull('user_name')
                ->where('user_name', '!=', '')
                ->distinct()
                ->limit(200)
                ->pluck('user_name')
                ->merge(
                    DB::table('purchase_returns')
                        ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('warehouse_id', $allowedOutlets))
                        ->whereNotNull('created_by')
                        ->where('created_by', '!=', '')
                        ->distinct()
                        ->limit(200)
                        ->pluck('created_by')
                )
                ->map(fn ($v) => trim((string)$v))
                ->filter(fn ($v) => $v !== '')
                ->unique()
                ->values()
                ->all();
            natcasesort($userNamesCol);
            $availableUsers = array_values($userNamesCol);
        }

        return $this->noStoreJson([
            'status' => 'success',
            'summary' => $maskedSummary,
            'cost_masked' => !$canViewCost,
            'available_suppliers' => $availableSuppliers,
            'available_payment_types' => $availablePaymentTypes,
            'available_users' => $availableUsers,
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
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;
        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $resolvedWarehouseId = $this->resolveWarehouseIdParam(is_scalar($warehouseFilter) ? (string)$warehouseFilter : null);
        if (!empty($allowedOutlets) && $warehouseFilter !== 'All' && !empty($warehouseFilter)) {
            if ($resolvedWarehouseId === null || !in_array($resolvedWarehouseId, $allowedOutlets, true)) {
                return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
            }
        }

        $effStartDate = !empty($singleDate) ? (string)$singleDate : ($startDate ? (string)$startDate : null);
        $effEndDate = !empty($singleDate) ? (string)$singleDate : ($endDate ? (string)$endDate : null);

        $applyBucketFilters = function ($q, string $dateCol, ?string $wNameCol = 'warehouse_name', string $whIdCol = 'warehouse_id') use ($warehouseFilter, $resolvedWarehouseId, &$effStartDate, &$effEndDate, $allowedOutlets) {
            if (!empty($allowedOutlets)) {
                $q->whereIn($whIdCol, $allowedOutlets);
            }
            if ($warehouseFilter !== 'All' && !empty($warehouseFilter)) {
                $q->where(function ($wq) use ($warehouseFilter, $resolvedWarehouseId, $wNameCol, $whIdCol) {
                    if (is_numeric($warehouseFilter)) {
                        $whNum = (int)$warehouseFilter;
                        if ($whNum === 1) {
                            $wq->where($whIdCol, 1)->orWhereNull($whIdCol);
                        } else {
                            $wq->where($whIdCol, $whNum);
                        }
                    } elseif ($resolvedWarehouseId !== null && $wNameCol !== null) {
                        $wq->where(function ($inner) use ($whIdCol, $resolvedWarehouseId, $wNameCol, $warehouseFilter) {
                            $inner->where($whIdCol, $resolvedWarehouseId)
                                  ->orWhere(function ($sub) use ($whIdCol, $wNameCol, $warehouseFilter) {
                                      $sub->whereNull($whIdCol)
                                          ->where($wNameCol, $warehouseFilter);
                                  });
                        });
                    } elseif ($wNameCol !== null) {
                        $wq->where($wNameCol, $warehouseFilter);
                    }
                });
            }
            $this->applyReportDateRange($q, $effStartDate, $effEndDate, $dateCol);
            return $q;
        };

        $hasSpTable = $accounting->schemaHasTable('sale_payments');
        $hasEpTable = $accounting->schemaHasTable('expense_payments');
        $hasSrCostLayers = $accounting->schemaHasTable('sale_return_cost_layers') && DB::table('sale_return_cost_layers')->exists();
        $hasLateOfflineCol = $accounting->schemaHasColumn('sales', 'late_offline_reconciliation');

        $cashCondSql = static function (string $col): string {
            $lc = "LOWER(TRIM(COALESCE({$col}, '')))";
            return "({$lc} LIKE '%cash%' AND {$lc} NOT LIKE '%non-cash%' AND {$lc} NOT LIKE '%non cash%' AND {$lc} NOT LIKE '%cashless%')";
        };
        $cardCondSql = static function (string $col): string {
            $lc = "LOWER(TRIM(COALESCE({$col}, '')))";
            return "({$lc} IN ('card', 'pos card', 'visa', 'mastercard', 'credit card', 'debit card') OR {$lc} LIKE '%card%')";
        };
        $nonEmptyActiveTenderSql = static function (string $col): string {
            $lc = "LOWER(TRIM(COALESCE({$col}, '')))";
            return "{$lc} NOT IN ('', 'unpaid', 'n/a', 'split payment', 'credit', 'credit note', 'store credit')";
        };

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

        // Lightweight date window slicing when skip_summary=1 & no post-SQL filters apply
        $canSlicePageEarly = $skipSummary
            && $knownTotal !== null
            && ($registerFilter === 'All' || empty($registerFilter))
            && ($statusFilter === 'All' || empty($statusFilter))
            && ($paymentStatusFilter === 'All' || empty($paymentStatusFilter))
            && empty($search)
            && empty($singleDate);

        if ($canSlicePageEarly) {
            $pageOffset = ($page - 1) * $perPage;
            $keyQueries = [];
            $sqKeys = DB::table('sales')->whereNotNull('date');
            $this->applyActiveDocumentFilter($sqKeys, 'sales.status');
            $applyBucketFilters($sqKeys, 'sales.date', 'sales.warehouse_name', 'sales.warehouse_id');
            $keyQueries[] = $sqKeys->selectRaw("SUBSTRING(sales.date, 1, 10) as d_date, COALESCE(sales.warehouse_id, 1) as w_id")
                ->groupBy(DB::raw("SUBSTRING(sales.date, 1, 10)"), DB::raw("COALESCE(sales.warehouse_id, 1)"));

            $sliceSourceDefs = [
                ['table' => 'purchases', 'dateCol' => 'date', 'statuses' => ['Received', 'Completed']],
                ['table' => 'purchase_returns', 'dateCol' => 'date', 'statuses' => ['Completed', 'Received']],
                ['table' => 'sale_returns', 'dateCol' => 'date', 'statuses' => null, 'activeDoc' => true],
                ['table' => 'expenses', 'dateCol' => 'date', 'statuses' => null, 'activeExp' => true],
            ];
            if ($accounting->schemaHasTable('cash_registers')) {
                $sliceSourceDefs[] = ['table' => 'cash_registers', 'dateCol' => 'opened_at', 'statuses' => null];
            }
            if ($hasSpTable) {
                $spKeys = DB::table('sale_payments')
                    ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
                    ->where('sale_payments.amount', '>', 0.0001)
                    ->whereNotNull('sale_payments.payment_date');
                $this->applyActiveDocumentFilter($spKeys, 'sales.status');
                $applyBucketFilters($spKeys, 'sale_payments.payment_date', 'sales.warehouse_name', 'sales.warehouse_id');
                $keyQueries[] = $spKeys->selectRaw("SUBSTRING(sale_payments.payment_date, 1, 10) as d_date, COALESCE(sales.warehouse_id, 1) as w_id")
                    ->groupBy(DB::raw("SUBSTRING(sale_payments.payment_date, 1, 10)"), DB::raw("COALESCE(sales.warehouse_id, 1)"));
            }
            if ($hasEpTable) {
                $epKeys = DB::table('expense_payments')
                    ->join('expenses', 'expense_payments.expense_id', '=', 'expenses.id')
                    ->where('expense_payments.amount', '>', 0.0001)
                    ->whereNotNull('expense_payments.payment_date')
                    ->where(function ($q) {
                        $q->whereNull('expenses.payment_status')
                          ->orWhereNotIn('expenses.payment_status', [
                              'Pending', 'pending', 'PENDING',
                              'Draft', 'draft', 'DRAFT',
                              'Voided', 'voided', 'VOIDED',
                          ]);
                    });
                $applyBucketFilters($epKeys, 'expense_payments.payment_date', 'expenses.warehouse_name', 'expenses.warehouse_id');
                $keyQueries[] = $epKeys->selectRaw("SUBSTRING(expense_payments.payment_date, 1, 10) as d_date, COALESCE(expenses.warehouse_id, 1) as w_id")
                    ->groupBy(DB::raw("SUBSTRING(expense_payments.payment_date, 1, 10)"), DB::raw("COALESCE(expenses.warehouse_id, 1)"));
            }
            $bucketUnion = null;
            foreach ($sliceSourceDefs as $src) {
                if (!$accounting->schemaHasTable($src['table'])) {
                    continue;
                }
                $eq = DB::table($src['table'])->whereNotNull($src['dateCol']);
                if ($src['statuses'] !== null) {
                    $eq->whereIn('status', $src['statuses']);
                }
                if ($src['table'] === 'purchases') {
                    $this->excludeOpeningStockPurchases($eq, 'payment_type');
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
                $applyBucketFilters($eq, $src['dateCol'], 'warehouse_name', 'warehouse_id');
                $sub = $eq->selectRaw("SUBSTRING({$src['dateCol']}, 1, 10) as d_date, COALESCE(warehouse_id, 1) as w_id")
                    ->groupBy(DB::raw("SUBSTRING({$src['dateCol']}, 1, 10)"), DB::raw("COALESCE(warehouse_id, 1)"));
                $keyQueries[] = $sub;
            }
            $baseUnion = array_shift($keyQueries);
            foreach ($keyQueries as $kq) {
                $baseUnion->union($kq);
            }
            $pagedKeys = DB::query()
                ->fromSub($baseUnion, 'zb')
                ->orderByDesc('d_date')
                ->orderBy('w_id')
                ->offset($pageOffset)
                ->limit($perPage)
                ->get();

            if ($pagedKeys->isEmpty()) {
                return $this->noStoreJson([
                    'status' => 'success',
                    'cost_masked' => !$canViewCost,
                    'available_registers' => [],
                    'summary' => [
                        'cost_masked' => !$canViewCost,
                        'total_reports' => $knownTotal,
                        'total_gross_sales' => 0.0,
                        'total_net_sales' => 0.0,
                        'total_closing_cash' => 0.0,
                        'total_discrepancy' => 0.0,
                    ],
                    'data' => [],
                    'current_day' => null,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => $knownTotal,
                        'last_page' => max(1, (int)ceil($knownTotal / max(1, $perPage))),
                        'has_more' => false,
                    ],
                ]);
            }
            $effStartDate = (string)$pagedKeys->min('d_date');
            $effEndDate = (string)$pagedKeys->max('d_date');
            $allowedPageBucketSet = [];
            foreach ($pagedKeys as $pk) {
                $allowedPageBucketSet[((string)$pk->d_date) . '_' . (int)$pk->w_id] = true;
            }
        } else {
            $allowedPageBucketSet = null;
        }

        // 1. SQL-aggregated Sales + COGS + Line Tax grouped by (day, warehouse_id) — fast index scan without joining products when total_cogs > 0
        $cogsAndLineTaxQ = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id');
        $this->applyActiveDocumentFilter($cogsAndLineTaxQ, 'sales.status');
        $applyBucketFilters($cogsAndLineTaxQ, 'sales.date', 'sales.warehouse_name', 'sales.warehouse_id');
        $cogsAndLineTaxRows = $cogsAndLineTaxQ->selectRaw("
                SUBSTRING(sales.date, 1, 10) as sale_date,
                COALESCE(sales.warehouse_id, 1) as w_id,
                COALESCE(SUM(COALESCE(sale_items.tax, 0)), 0) as line_tax,
                COALESCE(SUM(CASE WHEN COALESCE(sale_items.total_cogs, 0) > 0 THEN sale_items.total_cogs ELSE 0 END), 0) as total_cogs,
                COALESCE(SUM(CASE WHEN COALESCE(sale_items.total_cogs, 0) <= 0 THEN 1 ELSE 0 END), 0) as missing_cogs_cnt
            ")
            ->groupBy(DB::raw("SUBSTRING(sales.date, 1, 10)"), DB::raw("COALESCE(sales.warehouse_id, 1)"))
            ->get();

        $cogsAgg = [];
        $lineTaxByBucket = [];
        $missingCogsCnt = 0;
        foreach ($cogsAndLineTaxRows as $cr) {
            $bKey = $cr->sale_date . '_' . (int)$cr->w_id;
            $cogsAgg[$bKey] = (float)$cr->total_cogs;
            $lineTaxByBucket[$bKey] = (float)$cr->line_tax;
            $missingCogsCnt += (int)($cr->missing_cogs_cnt ?? 0);
        }

        // Fallback lookup ONLY when any sale_item row lacks total_cogs (avoids joining 2 product tables across all rows)
        if ($missingCogsCnt > 0) {
            $missingCogsQ = DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->leftJoin('product_variants', 'sale_items.product_variant_id', '=', 'product_variants.id')
                ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
                ->whereRaw('COALESCE(sale_items.total_cogs, 0) <= 0');
            $this->applyActiveDocumentFilter($missingCogsQ, 'sales.status');
            $applyBucketFilters($missingCogsQ, 'sales.date', 'sales.warehouse_name', 'sales.warehouse_id');
            foreach ($missingCogsQ->selectRaw("
                    SUBSTRING(sales.date, 1, 10) as sale_date,
                    COALESCE(sales.warehouse_id, 1) as w_id,
                    COALESCE(SUM(
                        COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity, 1) * COALESCE(NULLIF(product_variants.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)
                    ), 0) as fb_cogs
                ")
                ->groupBy(DB::raw("SUBSTRING(sales.date, 1, 10)"), DB::raw("COALESCE(sales.warehouse_id, 1)"))
                ->get() as $mc) {
                $bKey = $mc->sale_date . '_' . (int)$mc->w_id;
                $cogsAgg[$bKey] = ($cogsAgg[$bKey] ?? 0.0) + (float)$mc->fb_cogs;
            }
        }

        $sCashExpr = $cashCondSql("COALESCE(sales.payment_type, 'cash')");
        $sCardExpr = $cardCondSql("COALESCE(sales.payment_type, 'cash')");
        $sActiveTenderExpr = $nonEmptyActiveTenderSql("COALESCE(sales.payment_type, 'cash')");

        $salesGroupQ = DB::table('sales');
        $this->applyActiveDocumentFilter($salesGroupQ, 'sales.status');
        $applyBucketFilters($salesGroupQ, 'sales.date', 'sales.warehouse_name', 'sales.warehouse_id');
        $salesAggRows = $salesGroupQ->selectRaw("
            SUBSTRING(sales.date, 1, 10) as sale_date,
            COALESCE(sales.warehouse_id, 1) as w_id,
            MAX(COALESCE(sales.warehouse_name, 'Main Branch Outlet')) as w_name,
            COUNT(*) as tx_count,
            COALESCE(SUM(CASE WHEN (COALESCE(sales.grand_total, 0) - COALESCE(sales.tax_amount, 0) - COALESCE(sales.shipping, 0)) > 0 THEN (COALESCE(sales.grand_total, 0) - COALESCE(sales.tax_amount, 0) - COALESCE(sales.shipping, 0)) ELSE 0 END), 0) as pre_line_tax_sales,
            COALESCE(SUM(COALESCE(sales.tax_amount, 0)), 0) as order_tax,
            COALESCE(SUM(COALESCE(sales.discount, 0)), 0) as discount_sum,
            MAX(sales.date) as last_date,
            MAX(COALESCE(sales.created_by, 'Super Admin')) as creator,
            MAX(COALESCE(sales.user_name, sales.created_by, 'Super Admin')) as u_name,
            MAX(COALESCE(sales.user_role, 'Cashier')) as u_role
        ")
            ->groupBy(DB::raw("SUBSTRING(sales.date, 1, 10)"), DB::raw("COALESCE(sales.warehouse_id, 1)"))
            ->get();

        // Separate fallback query for legacy sales with zero sale_payments rows (ONLY_FULL_GROUP_BY safe)
        $fbByBucket = [];
        $salesFbQ = DB::table('sales')
            ->where('sales.paid_amount', '>', 0.0001)
            ->when($hasSpTable, fn ($q) => $q->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))->from('sale_payments')->whereColumn('sale_payments.sale_id', 'sales.id');
            }));
        $this->applyActiveDocumentFilter($salesFbQ, 'sales.status');
        if ($hasLateOfflineCol) {
            $salesFbQ->where(function ($sub) {
                $sub->whereNull('sales.late_offline_reconciliation')
                    ->orWhere('sales.late_offline_reconciliation', false);
            });
        }
        $applyBucketFilters($salesFbQ, 'sales.date', 'sales.warehouse_name', 'sales.warehouse_id');
        foreach ($salesFbQ->selectRaw("
            SUBSTRING(sales.date, 1, 10) as sale_date,
            COALESCE(sales.warehouse_id, 1) as w_id,
            COALESCE(SUM(CASE WHEN {$sCashExpr} THEN sales.paid_amount ELSE 0 END), 0) as fb_cash,
            COALESCE(SUM(CASE WHEN {$sCashExpr} THEN 1 ELSE 0 END), 0) as fb_cash_cnt,
            COALESCE(SUM(CASE WHEN NOT {$sCashExpr} AND {$sCardExpr} THEN sales.paid_amount ELSE 0 END), 0) as fb_card,
            COALESCE(SUM(CASE WHEN NOT {$sCashExpr} AND {$sCardExpr} THEN 1 ELSE 0 END), 0) as fb_card_cnt,
            COALESCE(SUM(CASE WHEN NOT {$sCashExpr} AND NOT {$sCardExpr} AND {$sActiveTenderExpr} THEN sales.paid_amount ELSE 0 END), 0) as fb_digital,
            COALESCE(SUM(CASE WHEN NOT {$sCashExpr} AND NOT {$sCardExpr} AND {$sActiveTenderExpr} THEN 1 ELSE 0 END), 0) as fb_digital_cnt
        ")
            ->groupBy(DB::raw("SUBSTRING(sales.date, 1, 10)"), DB::raw("COALESCE(sales.warehouse_id, 1)"))
            ->get() as $fb) {
            $fbByBucket[$fb->sale_date . '_' . (int)$fb->w_id] = $fb;
        }

        $dayBuckets = [];
        foreach ($salesAggRows as $sr) {
            $d = trim((string)$sr->sale_date);
            if ($d === '') continue;
            $wId = (int)$sr->w_id;
            $bKey = $d . '_' . $wId;
            $b = $initZBucket($d, $wId, (string)$sr->w_name);
            $b->tx_count = (int)$sr->tx_count;
            $lineTax = (float)($lineTaxByBucket[$bKey] ?? 0.0);
            $b->gross_sales = max(0.0, (float)$sr->pre_line_tax_sales - $lineTax);
            $b->tax_amount = (float)$sr->order_tax + $lineTax;
            $b->discount = (float)$sr->discount_sum;
            $fb = $fbByBucket[$bKey] ?? null;
            if ($fb) {
                $b->cash_sales = (float)$fb->fb_cash;
                $b->cash_count = (int)$fb->fb_cash_cnt;
                $b->card_sales = (float)$fb->fb_card;
                $b->card_count = (int)$fb->fb_card_cnt;
                $b->digital_sales = (float)$fb->fb_digital;
                $b->digital_count = (int)$fb->fb_digital_cnt;
            }
            $b->last_date = (string)($sr->last_date ?: ($d . ' 22:00:00'));
            $b->creator = (string)($sr->creator ?: 'Super Admin');
            $b->u_name = (string)($sr->u_name ?: $b->creator);
            $b->u_role = (string)($sr->u_role ?: 'Cashier');
            $dayBuckets[$bKey] = $b;
        }

        // Attribute actual payment collections strictly by sale_payments.payment_date in SQL
        if ($hasSpTable) {
            $spQ = DB::table('sale_payments')
                ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
                ->where('sale_payments.amount', '>', 0.0001);
            $this->applyActiveDocumentFilter($spQ, 'sales.status');
            $applyBucketFilters($spQ, 'sale_payments.payment_date', 'sales.warehouse_name', 'sales.warehouse_id');
            if ($hasLateOfflineCol) {
                $spQ->where(function ($sub) {
                    $sub->whereNull('sales.late_offline_reconciliation')
                        ->orWhere('sales.late_offline_reconciliation', false);
                });
            }
            $spCashExpr = $cashCondSql('sale_payments.payment_type');
            $spCardExpr = $cardCondSql('sale_payments.payment_type');
            $spActiveExpr = $nonEmptyActiveTenderSql('sale_payments.payment_type');
            $spAggRows = $spQ->selectRaw("
                SUBSTRING(COALESCE(sale_payments.payment_date, sales.date), 1, 10) as pay_date,
                COALESCE(sales.warehouse_id, 1) as w_id,
                MAX(COALESCE(sales.warehouse_name, 'Main Branch Outlet')) as w_name,
                COALESCE(SUM(CASE WHEN {$spCashExpr} THEN sale_payments.amount ELSE 0 END), 0) as cash_amt,
                COALESCE(SUM(CASE WHEN {$spCashExpr} THEN 1 ELSE 0 END), 0) as cash_cnt,
                COALESCE(SUM(CASE WHEN NOT {$spCashExpr} AND {$spCardExpr} THEN sale_payments.amount ELSE 0 END), 0) as card_amt,
                COALESCE(SUM(CASE WHEN NOT {$spCashExpr} AND {$spCardExpr} THEN 1 ELSE 0 END), 0) as card_cnt,
                COALESCE(SUM(CASE WHEN NOT {$spCashExpr} AND NOT {$spCardExpr} AND {$spActiveExpr} THEN sale_payments.amount ELSE 0 END), 0) as dig_amt,
                COALESCE(SUM(CASE WHEN NOT {$spCashExpr} AND NOT {$spCardExpr} AND {$spActiveExpr} THEN 1 ELSE 0 END), 0) as dig_cnt
            ")
                ->groupBy(DB::raw("SUBSTRING(COALESCE(sale_payments.payment_date, sales.date), 1, 10)"), DB::raw("COALESCE(sales.warehouse_id, 1)"))
                ->get();

            foreach ($spAggRows as $sp) {
                $d = trim((string)($sp->pay_date ?? ''));
                if ($d === '') continue;
                $wId = (int)$sp->w_id;
                $bKey = $d . '_' . $wId;
                if (!isset($dayBuckets[$bKey])) {
                    $dayBuckets[$bKey] = $initZBucket($d, $wId, (string)$sp->w_name);
                }
                $bucket = $dayBuckets[$bKey];
                $bucket->cash_sales += (float)$sp->cash_amt;
                $bucket->cash_count += (int)$sp->cash_cnt;
                $bucket->card_sales += (float)$sp->card_amt;
                $bucket->card_count += (int)$sp->card_cnt;
                $bucket->digital_sales += (float)$sp->dig_amt;
                $bucket->digital_count += (int)$sp->dig_cnt;
            }
        }

        $hasCashRegisters = $accounting->schemaHasTable('cash_registers');

        // 2. Aggregate Active Returns (single SQL scan + unlayered catalog COGS fallback, return tax deduction & payment-date cash refunds)
        $returnsMap = [];
        $cashRefundsByPayBucket = [];
        $hasItemsTaxExpr = "COALESCE(sale_returns.items_payload, '') LIKE '%\"tax\"%'";
        $hasSplitPayExpr = "(sale_returns.payments_payload IS NOT NULL AND sale_returns.payments_payload != '' AND sale_returns.payments_payload != '[]')";
        $retCogsExpr = $hasSrCostLayers
            ? "COALESCE(NULLIF(sale_returns.return_cost_total, 0), COALESCE(srcl.layer_cost, 0))"
            : "COALESCE(sale_returns.return_cost_total, 0)";
        $needsUnlayeredCogsExpr = "({$retCogsExpr} <= 0 AND sale_returns.items_payload IS NOT NULL AND sale_returns.items_payload != '' AND sale_returns.items_payload != '[]')";
        $needsSplitRetParseExpr = "({$hasItemsTaxExpr} OR {$needsUnlayeredCogsExpr} OR {$hasSplitPayExpr} OR LOWER(TRIM(COALESCE(sale_returns.payment_type, ''))) LIKE '%split%' OR LOWER(TRIM(COALESCE(sale_returns.refund_tender, ''))) LIKE '%split%')";

        $stdRetQ = DB::table('sale_returns');
        if ($hasSrCostLayers) {
            $srLayerSub = DB::table('sale_return_cost_layers')
                ->select('sale_return_id', DB::raw('COALESCE(SUM(total_cost), 0) as layer_cost'))
                ->groupBy('sale_return_id');
            $stdRetQ->leftJoinSub($srLayerSub, 'srcl', 'srcl.sale_return_id', '=', 'sale_returns.id');
        }
        $this->applyActiveDocumentFilter($stdRetQ, 'sale_returns.status');
        $applyBucketFilters($stdRetQ, 'sale_returns.date', 'sale_returns.warehouse_name', 'sale_returns.warehouse_id');
        $stdRetQ->whereRaw("NOT {$needsSplitRetParseExpr}");
        $retCashCond = $cashCondSql("COALESCE(NULLIF(sale_returns.refund_tender, ''), sale_returns.payment_type, 'cash')");
        $retExTaxSql = '(COALESCE(sale_returns.grand_total, 0) - COALESCE(sale_returns.tax_amount, 0) - COALESCE(sale_returns.shipping, 0))';
        foreach ($stdRetQ->selectRaw("
            SUBSTRING(sale_returns.date, 1, 10) as ret_date,
            COALESCE(sale_returns.warehouse_id, 1) as w_id,
            MAX(COALESCE(sale_returns.warehouse_name, 'Main Branch Outlet')) as w_name,
            COALESCE(SUM(CASE WHEN {$retExTaxSql} > 0 THEN {$retExTaxSql} ELSE 0 END), 0) as total_returns,
            COALESCE(SUM(COALESCE(sale_returns.tax_amount, 0)), 0) as returns_tax,
            COALESCE(SUM(CASE WHEN COALESCE(sale_returns.paid_amount, 0) > 0 AND {$retCashCond} THEN sale_returns.paid_amount ELSE 0 END), 0) as cash_refunds,
            COALESCE(SUM({$retCogsExpr}), 0) as returned_cogs
        ")
            ->groupBy(DB::raw("SUBSTRING(sale_returns.date, 1, 10)"), DB::raw("COALESCE(sale_returns.warehouse_id, 1)"))
            ->get() as $srAgg) {
            $d = trim((string)($srAgg->ret_date ?? ''));
            if ($d === '') continue;
            $wId = (int)$srAgg->w_id;
            $rKey = $d . '_' . $wId;
            if (!isset($dayBuckets[$rKey])) {
                $bucket = $initZBucket($d, $wId, (string)$srAgg->w_name);
                $bucket->u_role = 'Super Admin';
                $dayBuckets[$rKey] = $bucket;
            }
            $returnsMap[$rKey] = (object)[
                'total_returns' => (float)$srAgg->total_returns,
                'returns_tax'   => (float)($srAgg->returns_tax ?? 0.0),
                'cash_refunds'  => (float)$srAgg->cash_refunds,
                'returned_cogs' => (float)$srAgg->returned_cogs,
            ];
        }

        $splitRetQ = DB::table('sale_returns');
        if ($hasSrCostLayers) {
            $srLayerSub2 = DB::table('sale_return_cost_layers')
                ->select('sale_return_id', DB::raw('COALESCE(SUM(total_cost), 0) as layer_cost'))
                ->groupBy('sale_return_id');
            $splitRetQ->leftJoinSub($srLayerSub2, 'srcl', 'srcl.sale_return_id', '=', 'sale_returns.id');
        }
        $this->applyActiveDocumentFilter($splitRetQ, 'sale_returns.status');
        $applyBucketFilters($splitRetQ, 'sale_returns.date', 'sale_returns.warehouse_name', 'sale_returns.warehouse_id');
        $splitRetQ->whereRaw($needsSplitRetParseExpr);
        $splitRetRows = $splitRetQ->selectRaw("
                sale_returns.id,
                SUBSTRING(sale_returns.date, 1, 10) as ret_date,
                COALESCE(sale_returns.warehouse_id, 1) as w_id,
                sale_returns.warehouse_name as w_name,
                sale_returns.grand_total,
                COALESCE(sale_returns.tax_amount, 0) as tax_amount,
                COALESCE(sale_returns.shipping, 0) as shipping,
                sale_returns.paid_amount,
                sale_returns.refund_tender,
                sale_returns.payment_type,
                sale_returns.items_payload,
                sale_returns.payments_payload,
                {$retCogsExpr} as ret_cogs
            ")->get();

        $zNeedProdIds = [];
        $zNeedVarIds = [];
        $zParsedItems = [];
        foreach ($splitRetRows as $rRow) {
            if ((float)$rRow->ret_cogs <= 0.00001 && !empty($rRow->items_payload)) {
                $decoded = json_decode((string)$rRow->items_payload, true);
                if (is_array($decoded)) {
                    $zParsedItems[(int)$rRow->id] = $decoded;
                    foreach ($decoded as $ri) {
                        if (!is_array($ri)) continue;
                        if (!empty($ri['product_id'])) $zNeedProdIds[(int)$ri['product_id']] = true;
                        if (!empty($ri['product_variant_id'])) $zNeedVarIds[(int)$ri['product_variant_id']] = true;
                    }
                }
            }
        }
        $zProdCostMap = [];
        if (!empty($zNeedProdIds)) {
            foreach (array_chunk(array_keys($zNeedProdIds), 400) as $chk) {
                foreach (DB::table('products')->whereIn('id', $chk)->get(['id', 'cost_price', 'purchase_price']) as $p) {
                    $c = (float)($p->cost_price ?? 0);
                    $zProdCostMap[(int)$p->id] = $c > 0 ? $c : (float)($p->purchase_price ?? 0);
                }
            }
        }
        $zVarCostMap = [];
        if (!empty($zNeedVarIds)) {
            foreach (array_chunk(array_keys($zNeedVarIds), 400) as $chk) {
                foreach (DB::table('product_variants')->whereIn('id', $chk)->get(['id', 'cost_price']) as $v) {
                    $zVarCostMap[(int)$v->id] = (float)($v->cost_price ?? 0);
                }
            }
        }

        foreach ($splitRetRows as $rRow) {
            $d = trim((string)($rRow->ret_date ?? ''));
            if ($d === '') continue;
            $wId = (int)$rRow->w_id;
            $wNameStr = (string)($rRow->w_name ?? 'Main Branch Outlet');
            $rKey = $d . '_' . $wId;
            if (!isset($dayBuckets[$rKey])) {
                $bucket = $initZBucket($d, $wId, $wNameStr);
                $bucket->u_role = 'Super Admin';
                $dayBuckets[$rKey] = $bucket;
            }
            if (!isset($returnsMap[$rKey])) {
                $returnsMap[$rKey] = (object)[
                    'total_returns' => 0.0,
                    'returns_tax'   => 0.0,
                    'cash_refunds'  => 0.0,
                    'returned_cogs' => 0.0,
                ];
            }
            $srLineTax = (!empty($rRow->items_payload) && str_contains((string)$rRow->items_payload, '"tax"'))
                ? $accounting->extractPayloadLineTax($rRow->items_payload)
                : 0.0;
            $rowRetTax = max(0.0, (float)$rRow->tax_amount) + $srLineTax;
            $retExTaxMerch = max(0.0, (float)$rRow->grand_total - $rowRetTax - (float)$rRow->shipping);
            $returnsMap[$rKey]->total_returns += $retExTaxMerch;
            $returnsMap[$rKey]->returns_tax += $rowRetTax;

            $rowCogs = (float)$rRow->ret_cogs;
            if ($rowCogs <= 0.00001 && isset($zParsedItems[(int)$rRow->id])) {
                foreach ($zParsedItems[(int)$rRow->id] as $ri) {
                    if (!is_array($ri)) continue;
                    $qty = (float)($ri['quantity'] ?? 0);
                    $cr = (float)($ri['conversion_rate'] ?? 1);
                    if ($cr <= 0) $cr = 1.0;
                    $bq = (float)($ri['base_quantity'] ?? 0);
                    if ($bq <= 0) $bq = $qty * $cr;
                    $itc = (float)($ri['total_cost'] ?? 0);
                    $ibc = (float)($ri['base_unit_cost'] ?? 0);
                    $iuc = (float)($ri['unit_cost'] ?? $ri['cost_price'] ?? 0);
                    if ($itc > 0) $rowCogs += $itc;
                    elseif ($ibc > 0) $rowCogs += $bq * $ibc;
                    elseif ($iuc > 0) $rowCogs += $qty * $iuc;
                    elseif ($bq > 0) {
                        $vid = isset($ri['product_variant_id']) ? (int)$ri['product_variant_id'] : 0;
                        $pid = isset($ri['product_id']) ? (int)$ri['product_id'] : 0;
                        $uf = ($vid > 0 && ($zVarCostMap[$vid] ?? 0) > 0) ? $zVarCostMap[$vid] : ($zProdCostMap[$pid] ?? 0.0);
                        if ($uf > 0) $rowCogs += $bq * $uf;
                    }
                }
            }
            $returnsMap[$rKey]->returned_cogs += $rowCogs;

            $legs = !empty($rRow->payments_payload) ? json_decode((string)$rRow->payments_payload, true) : null;
            if (is_array($legs) && !empty($legs)) {
                foreach ($legs as $leg) {
                    $amt = (float)($leg['amount'] ?? 0);
                    $ptRaw = (string)($leg['payment_type'] ?? ($rRow->refund_tender ?? ($rRow->payment_type ?? '')));
                    if ($amt > 0 && $accounting->isCashPaymentType($ptRaw)) {
                        $legDay = !empty($leg['payment_date']) ? substr((string)$leg['payment_date'], 0, 10) : $d;
                        if ($effStartDate && $legDay < substr((string)$effStartDate, 0, 10)) continue;
                        if ($effEndDate && $legDay > substr((string)$effEndDate, 0, 10)) continue;
                        $payKey = $legDay . '_' . $wId;
                        if (!isset($dayBuckets[$payKey])) {
                            $bucket = $initZBucket($legDay, $wId, $wNameStr);
                            $bucket->u_role = 'Super Admin';
                            $dayBuckets[$payKey] = $bucket;
                        }
                        if (!isset($returnsMap[$payKey])) {
                            $returnsMap[$payKey] = (object)['total_returns' => 0.0, 'returns_tax' => 0.0, 'cash_refunds' => 0.0, 'returned_cogs' => 0.0];
                        }
                        $returnsMap[$payKey]->cash_refunds += $amt;
                    }
                }
            } elseif ((float)$rRow->paid_amount > 0) {
                $ptRaw = (string)($rRow->refund_tender ?: ($rRow->payment_type ?: 'cash'));
                if ($accounting->isCashPaymentType($ptRaw)) {
                    $returnsMap[$rKey]->cash_refunds += (float)$rRow->paid_amount;
                }
            }
        }

        // Expenses: pure SQL GROUP BY (total_expenses + cash_expenses via expense_payments & single-record fallback)
        $expensesMap = [];
        $expAccrualQ = DB::table('expenses')
            ->where(function ($q) {
                $q->whereNull('payment_status')
                  ->orWhereNotIn('payment_status', [
                      'Pending', 'pending', 'PENDING',
                      'Draft', 'draft', 'DRAFT',
                      'Voided', 'voided', 'VOIDED',
                  ]);
            });
        $applyBucketFilters($expAccrualQ, 'date', 'warehouse_name', 'warehouse_id');
        foreach ($expAccrualQ->selectRaw("
            SUBSTRING(expenses.date, 1, 10) as exp_date,
            COALESCE(expenses.warehouse_id, 1) as w_id,
            MAX(COALESCE(expenses.warehouse_name, 'Main Branch Outlet')) as w_name,
            COALESCE(SUM(expenses.amount), 0) as total_expenses
        ")
            ->groupBy(DB::raw("SUBSTRING(expenses.date, 1, 10)"), DB::raw("COALESCE(expenses.warehouse_id, 1)"))
            ->get() as $eRow) {
            $d = trim((string)($eRow->exp_date ?? ''));
            if ($d === '') continue;
            $wId = (int)$eRow->w_id;
            $eKey = $d . '_' . $wId;
            if (!isset($dayBuckets[$eKey])) {
                $bucket = $initZBucket($d, $wId, (string)$eRow->w_name);
                $bucket->u_role = 'Super Admin';
                $dayBuckets[$eKey] = $bucket;
            }
            $expensesMap[$eKey] = (object)[
                'total_expenses' => (float)$eRow->total_expenses,
                'cash_expenses'  => 0.0,
            ];
        }

        $expPmCashExpr = $cashCondSql("COALESCE(expenses.payment_method, 'cash')");
        $expFallbackCashExpr = "CASE WHEN COALESCE(expenses.paid_amount, 0) > 0.0001 THEN expenses.paid_amount WHEN COALESCE(expenses.due_amount, 0) <= 0.0001 AND LOWER(TRIM(COALESCE(expenses.payment_status, ''))) IN ('', 'paid') THEN expenses.amount ELSE 0 END";
        $expFbCashQ = DB::table('expenses')
            ->when($hasEpTable, fn ($q) => $q->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))->from('expense_payments')->whereColumn('expense_payments.expense_id', 'expenses.id');
            }))
            ->whereRaw("LOWER(TRIM(COALESCE(expenses.payment_status, ''))) != 'unpaid'")
            ->where(function ($q) {
                $q->whereNull('payment_status')
                  ->orWhereNotIn('payment_status', [
                      'Pending', 'pending', 'PENDING',
                      'Draft', 'draft', 'DRAFT',
                      'Voided', 'voided', 'VOIDED',
                  ]);
            })
            ->whereRaw($expPmCashExpr);
        $applyBucketFilters($expFbCashQ, 'date', 'warehouse_name', 'warehouse_id');
        foreach ($expFbCashQ->selectRaw("
            SUBSTRING(expenses.date, 1, 10) as exp_date,
            COALESCE(expenses.warehouse_id, 1) as w_id,
            COALESCE(SUM({$expFallbackCashExpr}), 0) as fb_cash_expenses
        ")
            ->groupBy(DB::raw("SUBSTRING(expenses.date, 1, 10)"), DB::raw("COALESCE(expenses.warehouse_id, 1)"))
            ->get() as $efRow) {
            $eKey = $efRow->exp_date . '_' . (int)$efRow->w_id;
            if (!isset($expensesMap[$eKey])) {
                $expensesMap[$eKey] = (object)['total_expenses' => 0.0, 'cash_expenses' => 0.0];
            }
            $expensesMap[$eKey]->cash_expenses += (float)$efRow->fb_cash_expenses;
        }
        if ($hasEpTable) {
            $epDateExpr = 'COALESCE(expense_payments.payment_date, expenses.date)';
            $epCashQ = DB::table('expense_payments')
                ->join('expenses', 'expense_payments.expense_id', '=', 'expenses.id')
                ->where('expense_payments.amount', '>', 0)
                ->where(function ($q) {
                    $q->whereNull('expenses.payment_status')
                      ->orWhereNotIn('expenses.payment_status', [
                          'Pending', 'pending', 'PENDING',
                          'Draft', 'draft', 'DRAFT',
                          'Voided', 'voided', 'VOIDED',
                      ]);
                })
                ->whereRaw($cashCondSql('expense_payments.payment_type'));
            if (!empty($allowedOutlets)) {
                $epCashQ->whereIn('expenses.warehouse_id', $allowedOutlets);
            }
            $this->applyWarehouseFilter($epCashQ, $warehouseFilter, $resolvedWarehouseId, 'expenses.warehouse_name', 'expenses.warehouse_id');
            if (!empty($effStartDate)) {
                $epCashQ->whereRaw("{$epDateExpr} >= ?", [$accounting->normalizeFilterBoundaryDate((string)$effStartDate, false)]);
            }
            if (!empty($effEndDate)) {
                $epCashQ->whereRaw("{$epDateExpr} <= ?", [$accounting->normalizeFilterBoundaryDate((string)$effEndDate, true)]);
            }
            foreach ($epCashQ->selectRaw("
                SUBSTRING({$epDateExpr}, 1, 10) as exp_date,
                COALESCE(expenses.warehouse_id, 1) as w_id,
                MAX(COALESCE(expenses.warehouse_name, 'Main Branch Outlet')) as w_name,
                COALESCE(SUM(expense_payments.amount), 0) as cash_paid
            ")
                ->groupBy(DB::raw("SUBSTRING({$epDateExpr}, 1, 10)"), DB::raw("COALESCE(expenses.warehouse_id, 1)"))
                ->get() as $epRow) {
                $d = trim((string)($epRow->exp_date ?? ''));
                if ($d === '') continue;
                $wId = (int)$epRow->w_id;
                $eKey = $d . '_' . $wId;
                if (!isset($dayBuckets[$eKey])) {
                    $bucket = $initZBucket($d, $wId, (string)($epRow->w_name ?? 'Main Branch Outlet'));
                    $bucket->u_role = 'Super Admin';
                    $dayBuckets[$eKey] = $bucket;
                }
                if (!isset($expensesMap[$eKey])) {
                    $expensesMap[$eKey] = (object)['total_expenses' => 0.0, 'cash_expenses' => 0.0];
                }
                $expensesMap[$eKey]->cash_expenses += (float)$epRow->cash_paid;
            }
        }

        // Operating Inventory Adjustments & Purchase Return Cost Variance grouped by (day, warehouse_id) for P&L parity
        $adjNetByKey = [];
        try {
            $effAdjTypeSqlZ = $this->effectiveAdjustmentTypeSql();
            $zAdjQ = DB::table('stock_adjustment_items')
                ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id');
            $this->applyOperatingAdjustmentScope($zAdjQ, true);
            $applyBucketFilters($zAdjQ, 'stock_adjustments.date', 'stock_adjustments.warehouse_name', 'stock_adjustments.warehouse_id');
            foreach ($zAdjQ->selectRaw("
                SUBSTRING(stock_adjustments.date, 1, 10) as d_date,
                COALESCE(stock_adjustments.warehouse_id, 1) as w_id,
                COALESCE(SUM(CASE WHEN {$effAdjTypeSqlZ} = 'addition' THEN stock_adjustment_items.subtotal ELSE -stock_adjustment_items.subtotal END), 0) as adj_net
            ")
                ->groupBy(DB::raw("SUBSTRING(stock_adjustments.date, 1, 10)"), DB::raw("COALESCE(stock_adjustments.warehouse_id, 1)"))
                ->get() as $ar) {
                $adjNetByKey[$ar->d_date . '_' . (int)$ar->w_id] = (float)$ar->adj_net;
            }
        } catch (\Throwable $e) {}

        $prVarByKey = [];
        try {
            if ($accounting->schemaHasTable('purchase_returns') && $accounting->schemaHasColumn('purchase_returns', 'cost_variance')) {
                $zPrVarQ = DB::table('purchase_returns')
                    ->whereIn('status', ['Completed', 'Received']);
                $applyBucketFilters($zPrVarQ, 'date', 'warehouse_name', 'warehouse_id');
                foreach ($zPrVarQ->selectRaw("
                    SUBSTRING(date, 1, 10) as d_date,
                    COALESCE(warehouse_id, 1) as w_id,
                    COALESCE(SUM(COALESCE(cost_variance, 0)), 0) as var_sum
                ")
                    ->groupBy(DB::raw("SUBSTRING(date, 1, 10)"), DB::raw("COALESCE(warehouse_id, 1)"))
                    ->get() as $pvr) {
                    $prVarByKey[$pvr->d_date . '_' . (int)$pvr->w_id] = (float)$pvr->var_sum;
                }
            }
        } catch (\Throwable $e) {}

        // Pre-fetch ALL cash_registers, register transactions, linked PO/PR cash flows, and unlinked PO/PR cash flows ONCE
        $regSessionsByKey = [];
        $allRegIds = [];
        if ($hasCashRegisters) {
            $regListQ = DB::table('cash_registers')->whereNotNull('opened_at');
            $applyBucketFilters($regListQ, 'opened_at', 'warehouse_name', 'warehouse_id');
            foreach ($regListQ->orderBy('id')->get() as $rRow) {
                $d = substr((string)$rRow->opened_at, 0, 10);
                $wId = (int)($rRow->warehouse_id ?? 1);
                $rKey = $d . '_' . $wId;
                if ($d !== '' && !isset($dayBuckets[$rKey])) {
                    $bucket = $initZBucket($d, $wId, (string)($rRow->warehouse_name ?? 'Main Branch Outlet'));
                    $bucket->u_role = 'Super Admin';
                    $dayBuckets[$rKey] = $bucket;
                }
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

        // Single-pass purchase / purchase_return bucket discovery + unlinked cash aggregation (conditional split cursor)
        $batchUnlinkedCashDocs = function (string $table, array $statuses, bool $exclOpening = false) use ($applyBucketFilters, $accounting, $cashCondSql, &$dayBuckets, $initZBucket): array {
            if (!$accounting->schemaHasTable($table)) {
                return [];
            }
            $totalsByKey = [];
            $hasSplitCol = $accounting->schemaHasColumn($table, 'has_register_split');

            $unlinkedPlainCashCond = "paid_amount > 0 AND cash_register_id IS NULL AND {$cashCondSql("COALESCE(payment_type, '')")} AND LOWER(TRIM(COALESCE(payment_type, ''))) NOT LIKE '%split%'";
            if ($hasSplitCol) {
                $unlinkedPlainCashCond .= " AND (has_register_split IS NULL OR has_register_split = 0)";
            }
            $splitOrBlankCond = "paid_amount > 0 AND (LOWER(TRIM(COALESCE(payment_type, ''))) LIKE '%split%' OR payment_type IS NULL OR payment_type = ''" . ($hasSplitCol ? " OR has_register_split = 1" : "") . ")";

            $aggQ = DB::table($table)
                ->whereNotNull('date')
                ->whereIn('status', $statuses);
            if ($exclOpening) {
                $this->excludeOpeningStockPurchases($aggQ, 'payment_type');
            }
            $applyBucketFilters($aggQ, 'date', 'warehouse_name', 'warehouse_id');

            $splitDocCnt = 0;
            foreach ($aggQ->selectRaw("
                SUBSTRING(date, 1, 10) as d_date,
                COALESCE(warehouse_id, 1) as w_id,
                MAX(COALESCE(warehouse_name, 'Main Branch Outlet')) as w_name,
                COALESCE(SUM(CASE WHEN {$unlinkedPlainCashCond} THEN paid_amount ELSE 0 END), 0) as unlinked_cash_amt,
                COALESCE(SUM(CASE WHEN {$splitOrBlankCond} THEN 1 ELSE 0 END), 0) as split_doc_cnt
            ")
                ->groupBy(DB::raw("SUBSTRING(date, 1, 10)"), DB::raw("COALESCE(warehouse_id, 1)"))
                ->get() as $row) {
                $splitDocCnt += (int)($row->split_doc_cnt ?? 0);
                $d = trim((string)($row->d_date ?? ''));
                if ($d === '') continue;
                $wId = (int)$row->w_id;
                $k = $d . '_' . $wId;
                if (!isset($dayBuckets[$k])) {
                    $bucket = $initZBucket($d, $wId, (string)$row->w_name);
                    $bucket->u_role = 'Super Admin';
                    $dayBuckets[$k] = $bucket;
                }
                $amt = (float)($row->unlinked_cash_amt ?? 0);
                if ($amt > 0.0001) {
                    $totalsByKey[$k] = round(($totalsByKey[$k] ?? 0.0) + $amt, 2);
                }
            }

            if ($splitDocCnt <= 0) {
                return $totalsByKey;
            }

            // Targeted PHP parsing ONLY when Split Payment or has_register_split rows exist
            $docsQ = DB::table($table)
                ->whereNotNull('date')
                ->whereIn('status', $statuses)
                ->where('paid_amount', '>', 0)
                ->where(function ($q) use ($hasSplitCol) {
                    $q->whereRaw("LOWER(TRIM(COALESCE(payment_type, ''))) LIKE '%split%'")
                      ->orWhereNull('payment_type')
                      ->orWhere('payment_type', '');
                    if ($hasSplitCol) {
                        $q->orWhere('has_register_split', true);
                    }
                });
            if ($exclOpening) {
                $this->excludeOpeningStockPurchases($docsQ, 'payment_type');
            }
            $applyBucketFilters($docsQ, 'date', 'warehouse_name', 'warehouse_id');
            $docs = $docsQ->select(['id', 'date', 'warehouse_id', 'cash_register_id', 'payment_type', 'paid_amount', 'payments_payload'])->cursor();
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

        $unlinkedPoByKey = $batchUnlinkedCashDocs('purchases', ['Received', 'Completed'], true);
        $unlinkedPrByKey = $batchUnlinkedCashDocs('purchase_returns', ['Completed', 'Received'], false);

        if ($allowedPageBucketSet !== null) {
            foreach (array_keys($dayBuckets) as $bk) {
                if (!isset($allowedPageBucketSet[$bk])) {
                    unset($dayBuckets[$bk]);
                }
            }
        }
        $salesGroups = array_values($dayBuckets);
        usort($salesGroups, function ($a, $b) {
            $cmp = strcmp((string)$b->sale_date, (string)$a->sale_date);
            return $cmp !== 0 ? $cmp : ((int)$a->w_id <=> (int)$b->w_id);
        });

        // 3. Assemble Sessions (Zero SQL inside loop)
        $items = [];
        $availRegisters = [];
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

            $ret = $returnsMap[$key] ?? null;
            $exp = $expensesMap[$key] ?? null;
            $cogsRaw = (float)($cogsAgg[$key] ?? 0.0);

            $gross = (float)$s->gross_sales;
            $returns = $ret ? (float)$ret->total_returns : 0.0;
            $returnsTax = $ret ? (float)($ret->returns_tax ?? 0.0) : 0.0;
            $returnedCogs = $ret ? (float)$ret->returned_cogs : 0.0;
            $expenses = $exp ? (float)$exp->total_expenses : 0.0;
            $adjNet = (float)($adjNetByKey[$key] ?? 0.0);
            $prVar = (float)($prVarByKey[$key] ?? 0.0);
            $cogs = round($cogsRaw - $returnedCogs, 2);
            $netSales = round($gross - $returns, 2);
            $grossProfit = round($netSales - $cogs, 2);
            $netProfit = round($grossProfit - $expenses + $adjNet + $prVar, 2);
            $tax = max(0.0, round((float)$s->tax_amount - $returnsTax, 2));
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
            $availRegisters[$registerId] = true;

            if ($registerFilter !== 'All' && !empty($registerFilter) && strcasecmp($registerId, (string)$registerFilter) !== 0) {
                continue;
            }

            $hasOpenReg = $regSessions->isNotEmpty() && $regSessions->contains(function ($r) {
                return strtolower(trim((string)($r->status ?? ''))) === 'open'
                    || strtolower(trim((string)($r->status ?? ''))) === 'active';
            });
            $status = $hasOpenReg ? 'Open' : 'Closed';
            if ($statusFilter !== 'All' && !empty($statusFilter)) {
                $normStatFilter = strtolower(trim((string)$statusFilter));
                if ($normStatFilter === 'audited' || $normStatFilter === 'closed') {
                    if ($status !== 'Closed') continue;
                } elseif (strcasecmp($status, (string)$statusFilter) !== 0) {
                    continue;
                }
            }

            $paymentStatus = abs($discrepancy) < 0.01 ? 'Reconciled' : 'Discrepancy';
            if ($paymentStatusFilter !== 'All' && !empty($paymentStatusFilter)) {
                $normPsFilter = strtolower(trim((string)$paymentStatusFilter));
                if (in_array($normPsFilter, ['balanced', 'reconciled'], true)) {
                    if ($paymentStatus !== 'Reconciled') continue;
                } elseif (strcasecmp($paymentStatus, (string)$paymentStatusFilter) !== 0) {
                    continue;
                }
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
                $primaryTender = 'Digital / Mobile';
            } elseif ($cardSales > $cashSales) {
                $primaryTender = 'Card';
            } elseif ($cashSales > 0 && ($digitalSales > 0 || $cardSales > 0)) {
                $primaryTender = 'Mixed';
            }

            $paymentBreakdown = [
                ['method' => 'Cash', 'amount' => round($cashSales, 2), 'count' => (int)$s->cash_count],
                ['method' => 'Card (Visa/MC)', 'amount' => round($cardSales, 2), 'count' => (int)$s->card_count],
                ['method' => 'Digital / Mobile Payments', 'amount' => round($digitalSales, 2), 'count' => (int)$s->digital_count],
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
            if (!$skipSummary) {
                $totalGrossSales += $gross;
                $totalNetSales += $netSales;
                $totalClosingCash += $closingCash;
                $totalDiscrepancy += $discrepancy;
            }
        }

        $totalReportsCount = ($skipSummary && $knownTotal !== null) ? $knownTotal : count($items);
        $lastPage = max(1, (int)ceil($totalReportsCount / max(1, $perPage)));
        if ($allowedPageBucketSet !== null) {
            $pagedItems = $items;
            $hasMore = (($page - 1) * $perPage + count($pagedItems)) < $totalReportsCount;
        } else {
            $offset = ($page - 1) * $perPage;
            $pagedItems = array_slice($items, $offset, $perPage);
            $hasMore = ($offset + count($pagedItems)) < $totalReportsCount;
        }

        $availableRegisters = [];
        if (!$skipSummary && $page === 1) {
            $availableRegisters = array_keys($availRegisters);
            sort($availableRegisters, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'available_registers' => $availableRegisters,
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
        $warehouse = $request->query('warehouse') ?? $request->query('warehouse_id') ?? $request->query('outlet_id') ?? 'All';
        $cashier = $request->query('cashier', $request->query('user_name', 'All'));
        $registerFilter = $request->query('register', 'All');
        $statusFilter = $request->query('status', 'All');
        $discrepancyFilter = $request->query('discrepancy', 'All');
        $paymentTypeFilter = $request->query('payment_type', $request->query('payment_method', 'All'));
        $startDate = (string)$request->query('start_date', $request->query('date_from', ''));
        $endDate = (string)$request->query('end_date', $request->query('date_to', ''));
        $search = trim((string)$request->query('search', ''));
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;
        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
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
                $outletObj = DB::table('outlets')->where('name', $targetWarehouseName)->first()
                    ?? ($accounting->schemaHasTable('warehouses') ? DB::table('warehouses')->where('name', $targetWarehouseName)->first() : null);
                if ($outletObj) {
                    $targetWarehouseId = (int)$outletObj->id;
                }
            }
        }
        if (!empty($allowedOutlets) && $warehouse !== 'All' && !empty($warehouse)) {
            $isAllowedById = $targetWarehouseId !== null && in_array($targetWarehouseId, $allowedOutlets, true);
            $isAllowedByName = $targetWarehouseId === null && $targetWarehouseName !== null && in_array($targetWarehouseName, $allowedOutletNames, true);
            if (!$isAllowedById && !$isAllowedByName) {
                return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
            }
        }

        $matchesDiscrepancy = static function (float $variance, string $filter): bool {
            $norm = strtolower(trim($filter));
            if ($norm === '' || $norm === 'all') {
                return true;
            }
            if ($norm === 'balanced') {
                return abs($variance) <= 0.009;
            }
            return abs($variance) > 0.009;
        };

        $matchesPaymentFilter = static function (string $primary, array $breakdown, string $filter): bool {
            $norm = trim($filter);
            if ($norm === '' || strcasecmp($norm, 'All') === 0) {
                return true;
            }
            if (strcasecmp($primary, $norm) === 0) {
                return true;
            }
            $activeTenders = 0;
            foreach ($breakdown as $pb) {
                if (!is_array($pb)) {
                    continue;
                }
                $amt = (float)($pb['amount'] ?? 0);
                if ($amt > 0.0001) {
                    $activeTenders++;
                    if (strcasecmp((string)($pb['method'] ?? ''), $norm) === 0) {
                        return true;
                    }
                }
            }
            if (strcasecmp($norm, 'Split Payment') === 0 && $activeTenders > 1) {
                return true;
            }
            return false;
        };

        $availRegisters = [];
        $availCashiers = [];
        $availPayTypes = [];

        $accountedSaleIds = [];
        $items = [];
        $realRegIds = [];
        $skippedRealRegsCount = 0;
        $hasSpTable = $accounting->schemaHasTable('sale_payments');
        $hasRegTxTable = $accounting->schemaHasTable('cash_register_transactions');

        // 1. Process Real Cash Registers (Separated index-seeking queries — zero OR full-table scans)
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
            if ($targetWarehouseId !== null && $targetWarehouseName !== null) {
                $regQuery->where(function ($q) use ($targetWarehouseId, $targetWarehouseName) {
                    $q->where('warehouse_id', $targetWarehouseId)
                        ->orWhere(function ($inner) use ($targetWarehouseName) {
                            $inner->whereNull('warehouse_id')->where('warehouse_name', $targetWarehouseName);
                        });
                });
            } elseif ($targetWarehouseId !== null) {
                $regQuery->where('warehouse_id', $targetWarehouseId);
            } elseif ($targetWarehouseName !== null) {
                $regQuery->where('warehouse_name', $targetWarehouseName);
            }
            if ($cashier !== 'All' && !empty($cashier)) {
                $regQuery->where('user_name', 'like', "%{$cashier}%");
            }
            $this->applyReportDateRange($regQuery, $startDate !== '' ? $startDate : null, $endDate !== '' ? $endDate : null, 'opened_at');

            $realRegs = $regQuery->orderByDesc('id')->get();
            $realRegIds = $realRegs->pluck('id')->map(fn($v) => (int)$v)->all();

            // Fast-path for Page 2+ (skip_summary=1 & offset >= real register count): only collect accounted sale IDs cleanly
            $offset = ($page - 1) * $perPage;
            $canSkipRealRegHydration = $skipSummary
                && $knownTotal !== null
                && $registerFilter === 'All'
                && $paymentTypeFilter === 'All'
                && strtolower(trim((string)$discrepancyFilter)) === 'all'
                && $search === ''
                && !in_array(strtolower($statusFilter), ['open', 'active'], true)
                && $offset >= $realRegs->count();

            if ($canSkipRealRegHydration) {
                $skippedRealRegsCount = $realRegs->count();
                if (!empty($realRegIds)) {
                    foreach (array_chunk($realRegIds, 400) as $idChunk) {
                        foreach (DB::table('sales')->whereIn('cash_register_id', $idChunk)->pluck('id') as $sid) {
                            $accountedSaleIds[(int)$sid] = true;
                        }
                    }
                }
                $realRegs = collect();
                $realRegIds = [];
            }

            // Pre-fetch drawer transactions (float_in, drop, void_refund) in SQL SUM per chunk
            $regTxAgg = [];
            if ($hasRegTxTable && !empty($realRegIds)) {
                foreach (array_chunk($realRegIds, 400) as $idChunk) {
                    $txAggRows = DB::table('cash_register_transactions')
                        ->whereIn('cash_register_id', $idChunk)
                        ->selectRaw("
                            cash_register_id,
                            COALESCE(SUM(CASE WHEN type = 'float_in' THEN amount ELSE 0 END), 0) as float_in,
                            COALESCE(SUM(CASE WHEN type IN ('drop', 'safe_drop') THEN amount ELSE 0 END), 0) as drop_amt,
                            COALESCE(SUM(CASE WHEN type = 'void_refund' THEN amount ELSE 0 END), 0) as void_refund
                        ")
                        ->groupBy('cash_register_id')
                        ->get();
                    foreach ($txAggRows as $tx) {
                        $rid = (int)$tx->cash_register_id;
                        $regTxAgg[$rid] = [
                            'float_in' => (float)$tx->float_in,
                            'drop' => (float)$tx->drop_amt,
                            'void_refund' => (float)$tx->void_refund,
                        ];
                    }
                }
            }

            // Batch purchase cash flow across all shifts
            $purchaseCashByReg = !empty($realRegIds)
                ? $accounting->summarizeRegisterPurchaseCashFlowBatch($realRegIds)
                : [];

            $nowStr = (string)now();
            $minOpenedAt = null;
            $maxClosedAt = null;
            $regMeta = [];
            $regWhIds = [];
            $regWhNames = [];
            foreach ($realRegs as $r) {
                $rid = (int)$r->id;
                $oAt = (string)$r->opened_at;
                $cAt = (string)($r->closed_at ?: $nowStr);
                if ($minOpenedAt === null || $oAt < $minOpenedAt) $minOpenedAt = $oAt;
                if ($maxClosedAt === null || $cAt > $maxClosedAt) $maxClosedAt = $cAt;
                if (!empty($r->warehouse_id)) {
                    $regWhIds[(int)$r->warehouse_id] = true;
                }
                if (!empty($r->warehouse_name)) {
                    $regWhNames[(string)$r->warehouse_name] = true;
                }
                $regMeta[$rid] = ['opened_at' => $oAt, 'closed_at' => $cAt];
            }
            $regWhIdList = array_keys($regWhIds);
            $regWhNameList = array_keys($regWhNames);

            // Batch fetch shift sales cleanly: linked query + separate unlinked window query (index-friendly!)
            $hasLateOfflineColReg = $accounting->schemaHasColumn('sales', 'late_offline_reconciliation');
            $shiftSalesByReg = [];
            $allShiftSaleIds = [];
            if (!empty($realRegIds)) {
                $seenShiftSaleIds = [];
                $collectShiftSale = function ($ss) use (&$seenShiftSaleIds, &$shiftSalesByReg, &$accountedSaleIds, &$allShiftSaleIds, $realRegs, $regMeta) {
                    $sid = (int)$ss->id;
                    if (isset($seenShiftSaleIds[$sid])) return;
                    $seenShiftSaleIds[$sid] = true;
                    if (!empty($ss->cash_register_id)) {
                        $rid = (int)$ss->cash_register_id;
                        $shiftSalesByReg[$rid][] = $ss;
                        $accountedSaleIds[$sid] = true;
                        $allShiftSaleIds[$sid] = true;
                    } else {
                        // Attribute unlinked sale to at most ONE matching register shift to prevent double-counting overlapping shifts
                        $matchedRid = null;
                        foreach ($realRegs as $r) {
                            $rid = (int)$r->id;
                            if ($r->warehouse_id && $ss->warehouse_id) {
                                if ((int)$ss->warehouse_id !== (int)$r->warehouse_id) continue;
                            } elseif ((string)$ss->warehouse_name !== (string)$r->warehouse_name) {
                                continue;
                            }
                            if ($r->user_id && (int)$ss->user_id !== (int)$r->user_id) continue;
                            $sCreated = (string)($ss->created_at ?? '');
                            if ($sCreated < $regMeta[$rid]['opened_at'] || $sCreated > $regMeta[$rid]['closed_at']) continue;
                            $matchedRid = $rid;
                            break;
                        }
                        if ($matchedRid !== null) {
                            $shiftSalesByReg[$matchedRid][] = $ss;
                            $accountedSaleIds[$sid] = true;
                            $allShiftSaleIds[$sid] = true;
                        }
                    }
                };
                $saleCols = ['id', 'cash_register_id', 'warehouse_id', 'warehouse_name', 'user_id', 'grand_total', 'paid_amount', 'payment_type', 'created_at'];
                foreach (array_chunk($realRegIds, 400) as $idChunk) {
                    $sq = DB::table('sales')->whereIn('cash_register_id', $idChunk);
                    $this->applyActiveDocumentFilter($sq, 'status');
                    if ($hasLateOfflineColReg) {
                        $sq->where(function ($sub) {
                            $sub->whereNull('late_offline_reconciliation')->orWhere('late_offline_reconciliation', false);
                        });
                    }
                    foreach ($sq->get($saleCols) as $ss) {
                        $collectShiftSale($ss);
                    }
                }
                if (!empty($regWhNameList) && $minOpenedAt !== null && $maxClosedAt !== null) {
                    $hasPosFlagCol = $accounting->schemaHasColumn('sales', 'pos_flag');
                    $unlinkedSq = DB::table('sales')
                        ->whereNull('cash_register_id')
                        ->when($hasPosFlagCol, fn ($q) => $q->where('pos_flag', true))
                        ->whereIn('warehouse_name', $regWhNameList)
                        ->whereBetween('created_at', [$minOpenedAt, $maxClosedAt]);
                    $this->applyActiveDocumentFilter($unlinkedSq, 'status');
                    if ($hasLateOfflineColReg) {
                        $unlinkedSq->where(function ($sub) {
                            $sub->whereNull('late_offline_reconciliation')->orWhere('late_offline_reconciliation', false);
                        });
                    }
                    if (!empty($allowedOutlets)) {
                        $unlinkedSq->whereIn('warehouse_id', $allowedOutlets);
                    }
                    foreach ($unlinkedSq->get($saleCols) as $ss) {
                        $collectShiftSale($ss);
                    }
                }
            }

            // Batch fetch sale_payments for shift sales AND cross-shift AR collections tied to register ID
            $salePaymentsBySale = [];
            $crossShiftPaymentsByReg = [];
            $allPaymentIdsSeen = [];
            $uniqueShiftSaleIds = array_keys($allShiftSaleIds);
            if ($hasSpTable) {
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
                        foreach (DB::table('sale_payments')->whereIn('sale_id', $idChunk)->select(['id', 'sale_id', 'cash_register_id', 'payment_type', 'amount', 'payment_date', 'created_at'])->get() as $sp) {
                            if (isset($allPaymentIdsSeen[(int)$sp->id])) continue;
                            $allPaymentIdsSeen[(int)$sp->id] = true;
                            $salePaymentsBySale[(int)$sp->sale_id][] = $sp;
                        }
                    }
                }
            }

            // Batch fetch shift expenses + expense_payments (split linked vs unlinked for index speed)
            $shiftExpensesByReg = [];
            $allShiftExpIds = [];
            $hasExpensesTable = $accounting->schemaHasTable('expenses');
            $hasExpRegCol = $hasExpensesTable && $accounting->schemaHasColumn('expenses', 'cash_register_id');
            if ($hasExpensesTable && !empty($realRegIds)) {
                $seenExpIds = [];
                $expCols = ['id', 'warehouse_name', 'amount', 'paid_amount', 'due_amount', 'payment_status', 'payment_method', 'created_at'];
                if ($hasExpRegCol) {
                    $expCols[] = 'cash_register_id';
                    foreach (array_chunk($realRegIds, 400) as $idChunk) {
                        $eq = DB::table('expenses')
                            ->whereIn('cash_register_id', $idChunk)
                            ->where(function ($q) {
                                $q->whereNull('payment_status')
                                  ->orWhereNotIn('payment_status', [
                                      'Pending', 'pending', 'PENDING',
                                      'Draft', 'draft', 'DRAFT',
                                      'Voided', 'voided', 'VOIDED',
                                  ]);
                            });
                        foreach ($eq->get($expCols) as $ex) {
                            $eid = (int)$ex->id;
                            if (isset($seenExpIds[$eid])) continue;
                            $seenExpIds[$eid] = true;
                            $shiftExpensesByReg[(int)$ex->cash_register_id][] = $ex;
                            $allShiftExpIds[$eid] = true;
                        }
                    }
                }
                if (!empty($regWhNameList) && $minOpenedAt !== null && $maxClosedAt !== null) {
                    $expCols[] = 'warehouse_id';
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
                    if ($hasExpRegCol) {
                        $eq->whereNull('cash_register_id');
                    }
                    if (!empty($allowedOutlets)) {
                        $eq->whereIn('warehouse_id', $allowedOutlets);
                    }
                    foreach ($eq->get($expCols) as $ex) {
                        $eid = (int)$ex->id;
                        if (isset($seenExpIds[$eid])) continue;
                        $seenExpIds[$eid] = true;
                        foreach ($realRegs as $r) {
                            $rid = (int)$r->id;
                            if ($r->warehouse_id && isset($ex->warehouse_id) && $ex->warehouse_id) {
                                if ((int)$ex->warehouse_id !== (int)$r->warehouse_id) continue;
                            } elseif ((string)$ex->warehouse_name !== (string)$r->warehouse_name) {
                                continue;
                            }
                            $eCreated = (string)($ex->created_at ?? '');
                            if ($eCreated < $regMeta[$rid]['opened_at'] || $eCreated > $regMeta[$rid]['closed_at']) continue;
                            $shiftExpensesByReg[$rid][] = $ex;
                            $allShiftExpIds[$eid] = true;
                            break;
                        }
                    }
                }
            }

            $expPayByExp = [];
            $crossShiftExpPayByReg = [];
            $uniqueShiftExpIds = array_keys($allShiftExpIds);
            if ($accounting->schemaHasTable('expense_payments')) {
                $seenEpIds = [];
                if (!empty($realRegIds) && $accounting->schemaHasColumn('expense_payments', 'cash_register_id')) {
                    foreach (array_chunk($realRegIds, 400) as $idChunk) {
                        $crossEps = DB::table('expense_payments')
                            ->join('expenses', 'expense_payments.expense_id', '=', 'expenses.id')
                            ->whereIn('expense_payments.cash_register_id', $idChunk)
                            ->where(function ($q) {
                                $q->whereNull('expenses.payment_status')
                                  ->orWhereNotIn('expenses.payment_status', [
                                      'Pending', 'pending', 'PENDING',
                                      'Draft', 'draft', 'DRAFT',
                                      'Voided', 'voided', 'VOIDED',
                                  ]);
                            })
                            ->select(['expense_payments.id', 'expense_payments.expense_id', 'expense_payments.cash_register_id', 'expense_payments.amount', 'expense_payments.payment_type'])
                            ->get();
                        foreach ($crossEps as $ep) {
                            $seenEpIds[(int)$ep->id] = true;
                            if (!isset($allShiftExpIds[(int)$ep->expense_id])) {
                                $crossShiftExpPayByReg[(int)$ep->cash_register_id][] = $ep;
                            } else {
                                $expPayByExp[(int)$ep->expense_id][] = $ep;
                            }
                        }
                    }
                }
                if (!empty($uniqueShiftExpIds)) {
                    foreach (array_chunk($uniqueShiftExpIds, 400) as $idChunk) {
                        foreach (DB::table('expense_payments')->whereIn('expense_id', $idChunk)->select(['id', 'expense_id', 'cash_register_id', 'amount', 'payment_type'])->get() as $ep) {
                            if (isset($seenEpIds[(int)$ep->id])) continue;
                            $seenEpIds[(int)$ep->id] = true;
                            $expPayByExp[(int)$ep->expense_id][] = $ep;
                        }
                    }
                }
            }

            // Batch fetch shift sale_returns (linked, split-register legs, and unlinked window)
            $shiftReturnsByReg = [];
            $hasSaleReturnsTable = $accounting->schemaHasTable('sale_returns');
            $hasSrRegCol = $hasSaleReturnsTable && $accounting->schemaHasColumn('sale_returns', 'cash_register_id');
            $hasSrSplitCol = $hasSaleReturnsTable && $accounting->schemaHasColumn('sale_returns', 'has_register_split');
            if ($hasSaleReturnsTable && !empty($realRegIds)) {
                $seenSrIds = [];
                $realRegIdSet = array_flip($realRegIds);
                $srCols = ['id', 'warehouse_name', 'grand_total', 'paid_amount', 'refund_tender', 'payment_type', 'payments_payload', 'created_at'];
                if ($hasSrRegCol) {
                    $srCols[] = 'cash_register_id';
                    foreach (array_chunk($realRegIds, 400) as $idChunk) {
                        $srQ = DB::table('sale_returns')->whereIn('cash_register_id', $idChunk);
                        $this->applyActiveDocumentFilter($srQ, 'status');
                        foreach ($srQ->get($srCols) as $ret) {
                            $retId = (int)$ret->id;
                            $seenSrIds[$retId] = true;
                            $shiftReturnsByReg[(int)$ret->cash_register_id][$retId] = $ret;
                        }
                    }
                }
                if ($hasSrSplitCol) {
                    $splitSrQ = DB::table('sale_returns')->where('has_register_split', true);
                    $this->applyActiveDocumentFilter($splitSrQ, 'status');
                    if (!empty($allowedOutlets)) {
                        $splitSrQ->whereIn('warehouse_id', $allowedOutlets);
                    }
                    foreach ($splitSrQ->get($srCols) as $ret) {
                        $retId = (int)$ret->id;
                        $legs = !empty($ret->payments_payload) ? json_decode((string)$ret->payments_payload, true) : null;
                        if (!is_array($legs)) continue;
                        foreach ($legs as $leg) {
                            $legReg = isset($leg['cash_register_id']) && $leg['cash_register_id'] !== '' ? (int)$leg['cash_register_id'] : null;
                            if ($legReg !== null && isset($realRegIdSet[$legReg])) {
                                $shiftReturnsByReg[$legReg][$retId] = $ret;
                                $seenSrIds[$retId] = true;
                            }
                        }
                    }
                }
                if (!empty($regWhNameList) && $minOpenedAt !== null && $maxClosedAt !== null) {
                    $srCols[] = 'warehouse_id';
                    $srQ = DB::table('sale_returns')
                        ->whereIn('warehouse_name', $regWhNameList)
                        ->whereBetween('created_at', [$minOpenedAt, $maxClosedAt]);
                    if ($hasSrRegCol) {
                        $srQ->whereNull('cash_register_id');
                    }
                    $this->applyActiveDocumentFilter($srQ, 'status');
                    if (!empty($allowedOutlets)) {
                        $srQ->whereIn('warehouse_id', $allowedOutlets);
                    }
                    foreach ($srQ->get($srCols) as $ret) {
                        $retId = (int)$ret->id;
                        if (isset($seenSrIds[$retId])) continue;
                        $seenSrIds[$retId] = true;
                        foreach ($realRegs as $r) {
                            $rid = (int)$r->id;
                            if ($r->warehouse_id && isset($ret->warehouse_id) && $ret->warehouse_id) {
                                if ((int)$ret->warehouse_id !== (int)$r->warehouse_id) continue;
                            } elseif ((string)$ret->warehouse_name !== (string)$r->warehouse_name) {
                                continue;
                            }
                            $rCreated = (string)($ret->created_at ?? '');
                            if ($rCreated < $regMeta[$rid]['opened_at'] || $rCreated > $regMeta[$rid]['closed_at']) continue;
                            $shiftReturnsByReg[$rid][$retId] = $ret;
                            break;
                        }
                    }
                }
            }

            foreach ($realRegs as $r) {
                $rid = (int)$r->id;
                $isClosedShift = strtolower((string)($r->status ?? '')) === 'closed' && !empty($r->closed_at);
                $shiftClosedAt = $isClosedShift ? (string)$r->closed_at : null;
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
                            // Prevent unlinked future back-office payments (cash_register_id IS NULL) from leaking retroactively into a closed shift
                            if ($spReg === null && $shiftClosedAt !== null) {
                                $spTs = (string)(($sp->payment_date ?? '') ?: ($sp->created_at ?? ''));
                                if ($spTs !== '' && $spTs > $shiftClosedAt) {
                                    continue;
                                }
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

                $floatsIn = (float)($regTxAgg[$rid]['float_in'] ?? 0.0);
                $cashDrops = (float)($regTxAgg[$rid]['drop'] ?? 0.0);

                // Count strictly ACTUAL paid expenses attributed to this shift (never unpaid accrued amounts)
                $shiftExpenses = $shiftExpensesByReg[$rid] ?? [];
                $totalExpenses = 0.0;
                $cashExpenses = 0.0;
                foreach ($shiftExpenses as $exp) {
                    $legs = $expPayByExp[(int)$exp->id] ?? [];
                    if (!empty($legs)) {
                        foreach ($legs as $leg) {
                            $legAmt = (float)($leg->amount ?? 0);
                            $legReg = $leg->cash_register_id ?? null;
                            if ($legAmt > 0 && ($legReg === null || (int)$legReg === $rid)) {
                                $totalExpenses += $legAmt;
                                if ($accounting->isCashPaymentType($leg->payment_type ?? null)) {
                                    $cashExpenses += $legAmt;
                                }
                            }
                        }
                    } else {
                        $pStat = strtolower(trim((string)($exp->payment_status ?? '')));
                        $pmRaw = (string)($exp->payment_method ?: 'cash');
                        if ($pStat !== 'unpaid') {
                            $paid = (float)($exp->paid_amount ?? 0);
                            $due = (float)($exp->due_amount ?? 0);
                            $effPaid = 0.0;
                            if ($paid > 0.0001) {
                                $effPaid = $paid;
                            } elseif ($due <= 0.0001 && in_array($pStat, ['', 'paid'], true)) {
                                $effPaid = (float)$exp->amount;
                            }
                            if ($effPaid > 0.0001) {
                                $totalExpenses += $effPaid;
                                if ($accounting->isCashPaymentType($pmRaw)) {
                                    $cashExpenses += $effPaid;
                                }
                            }
                        }
                    }
                }
                foreach (($crossShiftExpPayByReg[$rid] ?? []) as $cep) {
                    $legAmt = (float)($cep->amount ?? 0);
                    if ($legAmt > 0) {
                        $totalExpenses += $legAmt;
                        if ($accounting->isCashPaymentType($cep->payment_type ?? null)) {
                            $cashExpenses += $legAmt;
                        }
                    }
                }

                // Count strictly ACTUAL refunded disbursements attributed to this shift (exclude non-cash AR credit offsets)
                $shiftReturns = array_values($shiftReturnsByReg[$rid] ?? []);
                $totalRefunds = (float)($regTxAgg[$rid]['void_refund'] ?? 0.0);
                $cashRefunds = (float)($regTxAgg[$rid]['void_refund'] ?? 0.0);
                foreach ($shiftReturns as $ret) {
                    $legs = !empty($ret->payments_payload) ? json_decode((string)$ret->payments_payload, true) : null;
                    if (is_array($legs) && !empty($legs)) {
                        foreach ($legs as $leg) {
                            $amt = (float)($leg['amount'] ?? 0);
                            $ptRaw = (string)($leg['payment_type'] ?? ($ret->refund_tender ?? ($ret->payment_type ?? '')));
                            $legReg = array_key_exists('cash_register_id', $leg) && $leg['cash_register_id'] !== '' ? $leg['cash_register_id'] : ($ret->cash_register_id ?? null);
                            if ($amt > 0 && !$accounting->isNonCashSettlementType($ptRaw) && ($legReg === null || (int)$legReg === $rid)) {
                                $totalRefunds += $amt;
                                if ($accounting->isCashPaymentType($ptRaw)) {
                                    $cashRefunds += $amt;
                                }
                            }
                        }
                    } elseif ((float)($ret->paid_amount ?? 0) > 0) {
                        $tenderRaw = (string)($ret->refund_tender ?: ($ret->payment_type ?: 'cash'));
                        if (!$accounting->isNonCashSettlementType($tenderRaw)) {
                            $totalRefunds += (float)$ret->paid_amount;
                            if ($accounting->isCashPaymentType($tenderRaw)) {
                                $cashRefunds += (float)$ret->paid_amount;
                            }
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

                $availRegisters[$registerName] = true;
                $availCashiers[$cashierName] = true;
                foreach ($paymentBreakdown as $pb) {
                    if (!empty($pb['method']) && ((float)($pb['amount'] ?? 0) > 0 || (int)($pb['count'] ?? 0) > 0)) {
                        $availPayTypes[(string)$pb['method']] = true;
                    }
                }

                if ($registerFilter !== 'All' && !empty($registerFilter) && strcasecmp($registerName, $registerFilter) !== 0) continue;
                if ($statusFilter !== 'All' && !empty($statusFilter)) {
                    $normFilter = strtolower($statusFilter) === 'active' ? 'open' : strtolower($statusFilter);
                    $normStatus = strtolower($status) === 'active' ? 'open' : strtolower($status);
                    if ($normFilter !== $normStatus) continue;
                }
                if (!$matchesDiscrepancy($variance, (string)$discrepancyFilter)) continue;
                if (!$matchesPaymentFilter($primaryPayment, $paymentBreakdown, (string)$paymentTypeFilter)) continue;

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

        // 2. Process Legacy Sales (Single-pass streaming + selective sale_payments tender resolution — zero multi-pass GROUP BY temp tables!)
        $applyLegacyScope = function ($q) use ($allowedOutlets, $allowedOutletNames, $targetWarehouseId, $targetWarehouseName, $cashier, $startDate, $endDate) {
            $this->applyActiveDocumentFilter($q, 'status');
            if (!empty($allowedOutlets)) {
                $q->where(function ($sub) use ($allowedOutlets, $allowedOutletNames) {
                    $sub->whereIn('warehouse_id', $allowedOutlets);
                    if (!empty($allowedOutletNames)) {
                        $sub->orWhere(function ($inner) use ($allowedOutletNames) {
                            $inner->whereNull('warehouse_id')->whereIn('warehouse_name', $allowedOutletNames);
                        });
                    }
                });
            }
            if ($targetWarehouseId !== null && $targetWarehouseName !== null) {
                $q->where(function ($sub) use ($targetWarehouseId, $targetWarehouseName) {
                    $sub->where('warehouse_id', $targetWarehouseId)
                        ->orWhere(function ($inner) use ($targetWarehouseName) {
                            $inner->whereNull('warehouse_id')->where('warehouse_name', $targetWarehouseName);
                        });
                });
            } elseif ($targetWarehouseId !== null) {
                $q->where('warehouse_id', $targetWarehouseId);
            } elseif ($targetWarehouseName !== null) {
                $q->where('warehouse_name', $targetWarehouseName);
            }
            if ($cashier !== 'All' && !empty($cashier)) {
                $q->where('created_by', 'like', "%{$cashier}%");
            }
            $this->applyReportDateRange($q, $startDate !== '' ? $startDate : null, $endDate !== '' ? $endDate : null, 'date');
        };

        $canSliceLegacyBeforeTenders = $skipSummary
            && $knownTotal !== null
            && $registerFilter === 'All'
            && $paymentTypeFilter === 'All'
            && strtolower(trim((string)$discrepancyFilter)) === 'all'
            && $search === ''
            && !in_array(strtolower($statusFilter), ['open', 'active'], true);

        $legacySalesQuery = DB::table('sales');
        $applyLegacyScope($legacySalesQuery);
        if ($accounting->schemaHasColumn('sales', 'late_offline_reconciliation')) {
            $legacySalesQuery->where(function ($sub) {
                $sub->whereNull('late_offline_reconciliation')->orWhere('late_offline_reconciliation', false);
            });
        }

        $groupedMap = [];
        $legacySalesMeta = [];
        foreach ($legacySalesQuery->select(['id', 'date', 'warehouse_id', 'warehouse_name', 'created_by', 'grand_total', 'paid_amount', 'payment_type'])->cursor() as $lsRow) {
            $sid = (int)$lsRow->id;
            if (isset($accountedSaleIds[$sid])) {
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
                    'tender_totals' => [],
                    'tender_counts' => [],
                ];
            }
            $grp = $groupedMap[$gKey];
            $grp->tx_count++;
            $grp->total_sales += (float)$lsRow->grand_total;
            if ((string)$lsRow->date < $grp->opened_at_raw) $grp->opened_at_raw = (string)$lsRow->date;
            if ((string)$lsRow->date > $grp->closed_at_raw) $grp->closed_at_raw = (string)$lsRow->date;

            $legacySalesMeta[$sid] = [
                'gKey' => $gKey,
                'paid' => (float)$lsRow->paid_amount,
                'pt' => trim((string)($lsRow->payment_type ?: 'Cash')) ?: 'Cash',
            ];
        }

        // Sort legacy groups newest date first before optional skip_summary slice
        $legacyGroupsList = array_values($groupedMap);
        usort($legacyGroupsList, fn ($a, $b) => strcmp((string)$b->session_date, (string)$a->session_date));

        $legacySliceOffset = 0;
        if ($canSliceLegacyBeforeTenders && !empty($legacyGroupsList)) {
            $neededStart = max(0, (($page - 1) * $perPage) - (count($items) + $skippedRealRegsCount));
            $neededLen = $perPage + 10;
            $legacySliceOffset = $neededStart;
            $legacyGroupsList = array_slice($legacyGroupsList, $neededStart, $neededLen);
        }

        // Map kept group keys so we ONLY resolve sale_payments for groups we will actually process
        $keptGroupKeys = [];
        foreach ($legacyGroupsList as $lg) {
            $keptKey = $lg->session_date . '|' . $lg->w_id . '|' . $lg->w_name . '|' . $lg->cashier_name;
            $keptGroupKeys[$keptKey] = $lg;
        }
        $keptSaleMeta = [];
        foreach ($legacySalesMeta as $sid => $meta) {
            if (isset($keptGroupKeys[$meta['gKey']])) {
                $keptSaleMeta[$sid] = $meta;
            }
        }
        unset($legacySalesMeta);

        $accumulateLegacyTenderRow = function (object $grp, string $rawType, int $cnt, float $amt) use ($accounting): void {
            if ($amt <= 0.0001 && $cnt <= 0) {
                return;
            }
            $mName = $this->normalizePaymentMethodName($rawType !== '' ? $rawType : 'Cash');
            $grp->tender_totals[$mName] = ($grp->tender_totals[$mName] ?? 0.0) + $amt;
            $grp->tender_counts[$mName] = ($grp->tender_counts[$mName] ?? 0) + $cnt;
            $mLower = strtolower($mName);
            if ($accounting->isCashPaymentType($mName)) {
                $grp->cash_sales = (float)$grp->cash_sales + $amt;
            } elseif (str_contains($mLower, 'card') || in_array($mLower, ['visa', 'mastercard'], true)) {
                $grp->card_sales = (float)$grp->card_sales + $amt;
            } else {
                $grp->digital_sales = (float)$grp->digital_sales + $amt;
            }
        };

        $salesWithSpRows = [];
        if ($hasSpTable && !empty($keptSaleMeta)) {
            $keptIds = array_keys($keptSaleMeta);
            if (count($keptIds) <= 2000) {
                foreach (array_chunk($keptIds, 500) as $idChunk) {
                    foreach (DB::table('sale_payments')->whereIn('sale_id', $idChunk)->select(['sale_id', 'payment_type', 'amount'])->get() as $sp) {
                        $sid = (int)$sp->sale_id;
                        $salesWithSpRows[$sid] = true;
                        $grp = $keptGroupKeys[$keptSaleMeta[$sid]['gKey']];
                        $accumulateLegacyTenderRow($grp, trim((string)($sp->payment_type ?? '')) ?: 'Cash', 1, max(0.0, (float)$sp->amount));
                    }
                }
            } else {
                foreach (DB::table('sale_payments')->select(['sale_id', 'payment_type', 'amount'])->cursor() as $sp) {
                    $sid = (int)$sp->sale_id;
                    if (!isset($keptSaleMeta[$sid])) {
                        continue;
                    }
                    $salesWithSpRows[$sid] = true;
                    $grp = $keptGroupKeys[$keptSaleMeta[$sid]['gKey']];
                    $accumulateLegacyTenderRow($grp, trim((string)($sp->payment_type ?? '')) ?: 'Cash', 1, max(0.0, (float)$sp->amount));
                }
            }
        }
        foreach ($keptSaleMeta as $sid => $meta) {
            if (isset($salesWithSpRows[$sid])) {
                continue;
            }
            if ($meta['paid'] > 0.0001) {
                $accumulateLegacyTenderRow($keptGroupKeys[$meta['gKey']], $meta['pt'], 1, $meta['paid']);
            }
        }
        unset($keptSaleMeta, $salesWithSpRows);

        $realItemsCount = count($items) + $skippedRealRegsCount;
        $seq = $realItemsCount + 1 + $legacySliceOffset;
        foreach ($legacyGroupsList as $lg) {
            $sDate = $lg->session_date;
            $cName = trim(explode('(', (string)($lg->cashier_name ?: 'Super Admin'))[0]) ?: 'Super Admin';
            $refNo = 'REG-' . str_replace('-', '', $sDate) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
            $regName = 'Register ' . str_pad(($seq % 3) + 1, 2, '0', STR_PAD_LEFT);
            $curSeq = $seq++;

            $availRegisters[$regName] = true;
            $availCashiers[$cName] = true;

            $cSales = (float)$lg->cash_sales;
            $cdSales = (float)$lg->card_sales;
            $dgSales = (float)$lg->digital_sales;
            $totSales = (float)$lg->total_sales;
            $tTotals = is_array($lg->tender_totals ?? null) ? $lg->tender_totals : [];
            $tCounts = is_array($lg->tender_counts ?? null) ? $lg->tender_counts : [];

            $paymentBreakdown = [];
            $topMethod = 'Cash';
            $topAmt = -1.0;
            $nonZeroMethods = 0;
            foreach ($tTotals as $m => $amt) {
                $fAmt = round((float)$amt, 2);
                $cnt = (int)($tCounts[$m] ?? 0);
                if ($fAmt > 0.009 || $cnt > 0) {
                    $paymentBreakdown[] = [
                        'method' => (string)$m,
                        'amount' => $fAmt,
                        'count' => $cnt,
                    ];
                    $availPayTypes[(string)$m] = true;
                    if ($fAmt > 0.009) {
                        $nonZeroMethods++;
                        if ($fAmt > $topAmt) {
                            $topAmt = $fAmt;
                            $topMethod = (string)$m;
                        }
                    }
                }
            }
            if (empty($paymentBreakdown)) {
                $paymentBreakdown[] = ['method' => 'Cash', 'amount' => round($cSales, 2), 'count' => (int)$lg->tx_count];
            }
            $prim = $nonZeroMethods > 1 ? 'Split Payment' : ($topAmt > 0 ? $topMethod : 'Cash');

            if ($registerFilter !== 'All' && !empty($registerFilter) && strcasecmp($regName, $registerFilter) !== 0) continue;
            if ($statusFilter !== 'All' && !empty($statusFilter)) {
                $normFilter = strtolower($statusFilter) === 'active' ? 'open' : strtolower($statusFilter);
                if ($normFilter !== 'closed') continue;
            }
            if (!$matchesDiscrepancy(0.0, (string)$discrepancyFilter)) continue;
            if (!$matchesPaymentFilter($prim, $paymentBreakdown, (string)$paymentTypeFilter)) continue;

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
                'id' => $curSeq,
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
                'payment_breakdown' => $paymentBreakdown,
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

        // Calculate summary and drawer flow aggregates (skippable when skip_summary=1 & known_total given)
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
        $byPaymentTender = [];

        if (!$skipSummary) {
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
            usort($byPaymentTender, fn ($a, $b) => $b['total_amount'] <=> $a['total_amount']);
        }

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

        $totalSessions = ($skipSummary && $knownTotal !== null) ? $knownTotal : count($items);
        $offset = ($page - 1) * $perPage;
        $sliceOffset = $canSliceLegacyBeforeTenders ? max(0, $offset - $skippedRealRegsCount - $legacySliceOffset) : $offset;
        $pagedRaw = array_slice($items, $sliceOffset, $perPage);
        $hasMore = ($offset + count($pagedRaw)) < $totalSessions;

        // Only mask the 50 paged items (not all 3,200!)
        $pagedSessions = !$canViewCost
            ? array_map(function ($row) {
                $masked = $this->maskAssocNumericKeys(
                    $row,
                    ['opening_cash', 'cash_purchases_paid', 'cash_purchase_refunds', 'expected_cash', 'closing_cash', 'difference'],
                    0.0
                );
                $masked['cost_masked'] = true;
                return $masked;
            }, $pagedRaw)
            : array_map(function ($row) {
                $row['cost_masked'] = false;
                return $row;
            }, $pagedRaw);

        $availableRegisters = [];
        $availableCashiers = [];
        $availablePaymentTypes = [];
        if (!$skipSummary && $page === 1) {
            $availableRegisters = array_keys($availRegisters);
            if ($accounting->schemaHasTable('cash_registers')) {
                foreach (DB::table('cash_registers')
                    ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('warehouse_id', $allowedOutlets))
                    ->whereNotNull('register_name')->where('register_name', '!=', '')
                    ->distinct()->pluck('register_name') as $rn) {
                    $availRegisters[(string)$rn] = true;
                }
            }
            $availableRegisters = array_keys($availRegisters);
            sort($availableRegisters, SORT_NATURAL | SORT_FLAG_CASE);

            $cashierList = $availCashiers;
            $hasCashierNarrowingFilter = ($targetWarehouseId !== null || $targetWarehouseName !== null || ($cashier !== 'All' && !empty($cashier)) || $startDate !== '' || $endDate !== '');
            if ($hasCashierNarrowingFilter || empty($cashierList)) {
                foreach (DB::table('sales')
                    ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('warehouse_id', $allowedOutlets))
                    ->whereNotNull('created_by')->where('created_by', '!=', '')
                    ->distinct()->pluck('created_by') as $cb) {
                    $cleanName = trim(explode('(', (string)$cb)[0]);
                    if ($cleanName !== '') {
                        $cashierList[$cleanName] = true;
                    }
                }
            }
            $availableCashiers = array_keys($cashierList);
            sort($availableCashiers, SORT_NATURAL | SORT_FLAG_CASE);

            $ptMap = $availPayTypes;
            if (!empty($byPaymentTender)) {
                foreach ($byPaymentTender as $bt) {
                    if (!empty($bt['payment_type'])) {
                        $ptMap[(string)$bt['payment_type']] = true;
                    }
                }
            }
            if (count($ptMap) > 1) {
                $ptMap['Split Payment'] = true;
            }
            $availablePaymentTypes = array_keys($ptMap);
            sort($availablePaymentTypes, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'available_registers' => $availableRegisters,
            'available_cashiers' => $availableCashiers,
            'available_payment_types' => $availablePaymentTypes,
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
        $isHistoricalAsOf = $asOfDate < Carbon::today()->toDateString();
        $search         = trim((string)$request->input('search', ''));
        $rawRisk        = strtolower(trim((string)($request->input('risk_filter') ?? $request->input('bucket', 'All'))));
        $riskFilter     = match ($rawRisk) {
            'healthy', 'current'                         => 'healthy',
            'moderate', 'watchlist', 'days_31_60', '31-60' => 'moderate',
            'urgent', 'days_61_90', '61-90'              => 'urgent',
            'critical', 'days_90_plus', '90+'            => 'critical',
            default                                      => 'All',
        };
        $skipSummary    = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $page           = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage        = $this->resolveReportPerPage($request, 50, 10000);
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $warehouseId    = $this->resolveWarehouseIdParam(is_scalar($warehouse) ? (string)$warehouse : null);
        if (!empty($allowedOutlets) && $warehouse !== 'All' && !empty($warehouse)) {
            if ($warehouseId === null || !in_array($warehouseId, $allowedOutlets, true)) {
                return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
            }
        }

        $applyWh = static function ($q, string $idCol, string $nameCol) use ($warehouse, $warehouseId): void {
            if ($warehouse === 'All' || empty($warehouse)) {
                return;
            }
            if ($warehouseId !== null) {
                $q->where(function ($sub) use ($idCol, $nameCol, $warehouse, $warehouseId) {
                    $sub->where($idCol, $warehouseId);
                    if (!is_numeric($warehouse)) {
                        $sub->orWhere(function ($legacy) use ($idCol, $nameCol, $warehouse) {
                            $legacy->whereNull($idCol)->where($nameCol, $warehouse);
                        });
                    }
                });
            } else {
                $q->where($nameCol, $warehouse);
            }
        };

        $hasSpTable = $accounting->schemaHasTable('sale_payments');

        // Reconstruct As-Of Receivables <= $asOfEnd: only inspect sales that are open today,
        // had return credits applied, or received payments after $asOfEnd.
        $query = DB::table('sales')
            ->where('sales.date', '<=', $asOfEnd);
        $this->applyActiveDocumentFilter($query, 'sales.status');
        if (!empty($allowedOutlets)) {
            $query->whereIn('sales.warehouse_id', $allowedOutlets);
        }
        $applyWh($query, 'sales.warehouse_id', 'sales.warehouse_name');
        $query->where(function ($q) use ($hasSpTable, $asOfEnd) {
            $q->where('sales.due_amount', '>', 0.001)
                ->orWhere('sales.returned_credit_amount', '>', 0.001);
            if ($hasSpTable) {
                $q->orWhereExists(function ($sub) use ($asOfEnd) {
                    $sub->select(DB::raw(1))
                        ->from('sale_payments')
                        ->whereColumn('sale_payments.sale_id', 'sales.id')
                        ->where('sale_payments.payment_date', '>', $asOfEnd);
                });
            }
        });

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

        // Fetch payments strictly for candidate open sales (SQLite-safe chunking)
        $salePayBySale = [];
        if ($sales->isNotEmpty() && $hasSpTable) {
            $saleIds = $sales->pluck('id')->all();
            foreach (array_chunk($saleIds, 400) as $idChunk) {
                foreach (DB::table('sale_payments')
                    ->whereIn('sale_id', $idChunk)
                    ->select(['sale_id', 'amount', 'payment_date'])
                    ->cursor() as $sp) {
                    $salePayBySale[(int)$sp->sale_id][] = $sp;
                }
            }
        }

        $applyReturnScope = function ($q, bool $includePaidForHistorical = false) use ($allowedOutlets, $applyWh): void {
            $this->applyActiveDocumentFilter($q, 'sale_returns.status');
            if (!empty($allowedOutlets)) {
                $q->whereIn('sale_returns.warehouse_id', $allowedOutlets);
            }
            $applyWh($q, 'sale_returns.warehouse_id', 'sale_returns.warehouse_name');
            $q->where(function ($sub) use ($includePaidForHistorical) {
                $sub->where('sale_returns.due_amount', '>', 0.001)
                    ->orWhere('sale_returns.applied_credit_amount', '>', 0.001);
                if ($includePaidForHistorical) {
                    $sub->orWhere('sale_returns.paid_amount', '>', 0.001);
                }
            });
        };

        $activeReturnsQ = DB::table('sale_returns')->where('sale_returns.date', '<=', $asOfEnd);
        $applyReturnScope($activeReturnsQ, $isHistoricalAsOf);
        $srAgingCols = [
            'id', 'sale_id', 'customer_id', 'customer_name', 'date',
            'grand_total', 'paid_amount', 'due_amount', 'applied_credit_amount',
        ];
        if ($isHistoricalAsOf && $accounting->schemaHasColumn('sale_returns', 'payments_payload')) {
            $srAgingCols[] = 'payments_payload';
        }
        $activeReturnsAsOf = $activeReturnsQ->select($srAgingCols)->get();

        // Merge customer_id = NULL Walk-in records with canonical Walk-in customer ID
        $walkInCustIdAr = DB::table('customers')
            ->where(function ($q) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%walk-in%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%walkin%'])
                  ->orWhere('customer_id', 'CUST-001');
            })
            ->orderBy('id')
            ->value('id');
        $resolveArKey = static function ($rawCid, ?string $rawName) use ($walkInCustIdAr): array {
            $cId = (int)($rawCid ?: 0);
            $cName = trim((string)($rawName ?? '')) ?: 'Walk-in Customer';
            if ($cId <= 0 && $walkInCustIdAr && (stripos($cName, 'walk-in') !== false || stripos($cName, 'walkin') !== false)) {
                $cId = (int)$walkInCustIdAr;
            }
            $key = $cId > 0 ? (string)$cId : ('name_' . strtolower($cName));
            return [$cId, $cName, $key];
        };

        $creditsAppliedBySale = [];
        foreach ($activeReturnsAsOf as $ar) {
            if (!empty($ar->sale_id) && (float)($ar->applied_credit_amount ?? 0) > 0) {
                $sid = (int)$ar->sale_id;
                $creditsAppliedBySale[$sid] = ($creditsAppliedBySale[$sid] ?? 0.0) + (float)$ar->applied_credit_amount;
            }
        }

        // Track returns with credit applied AFTER $asOfEnd so historical As-Of queries don't prematurely deduct them
        $futureCreditsBySale = [];
        $futureReturnsQ = DB::table('sale_returns')
            ->where('sale_returns.date', '>', $asOfEnd)
            ->whereNotNull('sale_returns.sale_id')
            ->where('sale_returns.applied_credit_amount', '>', 0.001);
        $applyReturnScope($futureReturnsQ);
        foreach ($futureReturnsQ->select(['sale_id', 'applied_credit_amount'])->cursor() as $fr) {
            $sid = (int)$fr->sale_id;
            $futureCreditsBySale[$sid] = ($futureCreditsBySale[$sid] ?? 0.0) + (float)$fr->applied_credit_amount;
        }

        $customerMap     = [];
        $asOfEpoch = strtotime($asOfDate) ?: time();
        $dayDiffCache = [];
        $calcDaysOverdue = static function (string $docDate) use ($asOfEpoch, &$dayDiffCache): int {
            $day = substr($docDate, 0, 10);
            if (!isset($dayDiffCache[$day])) {
                $ts = strtotime($day);
                $dayDiffCache[$day] = $ts !== false ? max(0, (int)floor(($asOfEpoch - $ts) / 86400)) : 0;
            }
            return $dayDiffCache[$day];
        };

        foreach ($sales as $s) {
            $sid = (int)$s->id;
            $payments = $salePayBySale[$sid] ?? [];
            if (!empty($payments)) {
                $paidAsOf     = 0.0;
                $totalRecorded = 0.0;
                foreach ($payments as $p) {
                    $amt = (float)($p->amount ?? 0);
                    if ($amt <= 0) {
                        continue;
                    }
                    $totalRecorded += $amt;
                    $rawPayDt = (string)($p->payment_date ?? $s->date);
                    $pDateStr = (strlen($rawPayDt) === 19 && $rawPayDt[4] === '-' && $rawPayDt[10] === ' ')
                        ? $rawPayDt
                        : $accounting->normalizeTransactionDate($rawPayDt, (string)$s->date);
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

            if (array_key_exists($sid, $creditsAppliedBySale)) {
                $creditAsOf = (float)$creditsAppliedBySale[$sid];
            } elseif (isset($futureCreditsBySale[$sid])) {
                $creditAsOf = max(0.0, (float)($s->returned_credit_amount ?? 0.0) - (float)$futureCreditsBySale[$sid]);
            } else {
                $creditAsOf = (float)($s->returned_credit_amount ?? 0.0);
            }

            $due = round(max(0.0, (float)$s->grand_total - $paidAsOf - $creditAsOf), 2);
            if ($due <= 0.001) {
                continue;
            }

            [$cId, $cName, $key] = $resolveArKey($s->customer_id, $s->customer_name);

            $days = $calcDaysOverdue((string)$s->date);

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
                    'applied_credit_notes' => 0.0,
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

        // Deduct unrefunded active Customer Credit Notes as-of $asOfEnd per customer cleanly
        // (rolling back post-cutoff cash refunds on historical as_of_date queries!)
        $totalOpenCreditNotes = 0.0;
        $appliedCreditToDebtors = 0.0;
        foreach ($activeReturnsAsOf as $ar) {
            if ($isHistoricalAsOf) {
                $refundedAsOf = $accounting->sumPayloadPaymentsAsOf(
                    $ar->payments_payload ?? null,
                    (float)($ar->paid_amount ?? 0),
                    (string)$ar->date,
                    $asOfEnd
                );
                $appliedAsOf = max(0.0, (float)($ar->applied_credit_amount ?? 0));
                $cnDue = round(max(0.0, (float)($ar->grand_total ?? 0) - $refundedAsOf - $appliedAsOf), 2);
            } else {
                $cnDue = round((float)($ar->due_amount ?? 0), 2);
            }
            if ($cnDue <= 0.001) {
                continue;
            }
            $totalOpenCreditNotes += $cnDue;
            [$cId, $cName, $key] = $resolveArKey($ar->customer_id, $ar->customer_name);
            if (!isset($customerMap[$key])) {
                continue;
            }
            $custRow = &$customerMap[$key];
            $custRow['open_credit_notes'] += $cnDue;
            $remCredit = min($cnDue, (float)$custRow['total_due']);
            $custRow['applied_credit_notes'] += $remCredit;
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
        $customerMap = array_filter($customerMap, static fn ($row) => $row['total_due'] > 0.001);

        // Compute normalized risk status & Enrich with customer details (chunked SQLite-safe)
        $customerIds = array_values(array_unique(array_filter(array_column($customerMap, 'customer_id'))));
        $customerDetails = collect();
        if (!empty($customerIds)) {
            foreach (array_chunk($customerIds, 400) as $idChunk) {
                $chunkRows = Customer::whereIn('id', $idChunk)
                    ->select(['id', 'phone', 'email', 'tier', 'credit_limit'])
                    ->get();
                foreach ($chunkRows as $cd) {
                    $customerDetails->put($cd->id, $cd);
                }
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
            if ((float)$cust['days_90_plus'] > 0.001) {
                $cust['risk_status'] = 'critical';
            } elseif ((float)$cust['days_61_90'] > 0.001) {
                $cust['risk_status'] = 'urgent';
            } elseif ((float)$cust['days_31_60'] > 0.001) {
                $cust['risk_status'] = 'moderate';
            } else {
                $cust['risk_status'] = 'healthy';
            }
        }
        unset($cust);

        $items = array_values($customerMap);

        if ($riskFilter !== 'All') {
            $items = array_values(array_filter($items, static fn ($it) => ($it['risk_status'] ?? 'healthy') === $riskFilter));
        }

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
        $filteredAppliedCreditNotes = 0.0;
        foreach ($items as $row) {
            $totalReceivable += $row['total_due'];
            $totalCurrent    += $row['current'];
            $totalDays31_60  += $row['days_31_60'];
            $totalDays61_90  += $row['days_61_90'];
            $totalDays90Plus += $row['days_90_plus'];
            $filteredOpenCreditNotes += (float)($row['open_credit_notes'] ?? 0);
            $filteredAppliedCreditNotes += (float)($row['applied_credit_notes'] ?? 0);
        }
        $hasSubFilter = !empty($search) || $riskFilter !== 'All';
        $effectiveTotalOpenCn = $hasSubFilter ? $filteredOpenCreditNotes : $totalOpenCreditNotes;
        $effectiveAppliedCredit = $hasSubFilter
            ? $filteredAppliedCreditNotes
            : $appliedCreditToDebtors;

        usort($items, function ($a, $b) {
            return $b['total_due'] <=> $a['total_due'];
        });

        foreach ($items as &$it) {
            $it['gross_due'] = round((float)($it['gross_due'] ?? $it['total_due']), 2);
            $it['open_credit_notes'] = round((float)($it['open_credit_notes'] ?? 0), 2);
            $it['applied_credit_notes'] = round((float)($it['applied_credit_notes'] ?? 0), 2);
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

        $unappliedCreditNotes = round(max(0.0, $effectiveTotalOpenCn - $effectiveAppliedCredit), 2);
        $payload = [
            'status' => 'success',
            'summary' => [
                'total_receivable' => round($totalReceivable, 2),
                'total_receivables' => round($totalReceivable, 2),
                'total_open_credit_notes' => round($effectiveTotalOpenCn, 2),
                'unapplied_open_credit_notes' => $unappliedCreditNotes,
                'net_receivable_after_all_credits' => round($totalReceivable - $unappliedCreditNotes, 2),
                'current' => round($totalCurrent, 2),
                'days_31_60' => round($totalDays31_60, 2),
                'days_61_90' => round($totalDays61_90, 2),
                'days_90_plus' => round($totalDays90Plus, 2),
                'total_overdue' => round($totalOverdue, 2),
                'total_customers' => $totalCustomers,
                'as_of_date' => $asOfDate,
                'warehouse' => $warehouse,
                'risk_filter' => $riskFilter,
            ],
            'data' => $pagedItems,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalCustomers,
                'last_page' => $lastPage,
                'has_more' => $hasMore,
            ],
        ];
        if (!$skipSummary) {
            $whList = $accounting->schemaHasTable('outlets')
                ? DB::table('outlets')
                    ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('id', $allowedOutlets))
                    ->orderBy('name')
                    ->pluck('name')
                    ->filter(fn ($v) => trim((string)$v) !== '')
                    ->values()
                    ->all()
                : [];
            $payload['available_filters'] = [
                'warehouses' => array_values(array_unique($whList)),
                'risk_levels' => ['healthy', 'moderate', 'urgent', 'critical'],
            ];
        }
        return $this->noStoreJson($payload);
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
        $isHistoricalAsOf = $asOfDate < Carbon::today()->toDateString();
        $search = trim((string)$request->input('search', ''));
        $rawRisk = strtolower(trim((string)($request->input('risk_filter') ?? $request->input('bucket', 'All'))));
        $riskFilter = match ($rawRisk) {
            'healthy', 'current'                         => 'healthy',
            'moderate', 'watchlist', 'days_31_60', '31-60' => 'moderate',
            'urgent', 'days_61_90', '61-90'              => 'urgent',
            'critical', 'days_90_plus', '90+'            => 'critical',
            default                                      => 'All',
        };
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $warehouseId = $this->resolveWarehouseIdParam(is_scalar($warehouse) ? (string)$warehouse : null);
        if (!empty($allowedOutlets) && $warehouse !== 'All' && !empty($warehouse)) {
            if ($warehouseId === null || !in_array($warehouseId, $allowedOutlets, true)) {
                return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
            }
        }

        $applyWh = static function ($q, string $idCol, string $nameCol) use ($warehouse, $warehouseId): void {
            if ($warehouse === 'All' || empty($warehouse)) {
                return;
            }
            if ($warehouseId !== null) {
                $q->where(function ($sub) use ($idCol, $nameCol, $warehouse, $warehouseId) {
                    $sub->where($idCol, $warehouseId);
                    if (!is_numeric($warehouse)) {
                        $sub->orWhere(function ($legacy) use ($idCol, $nameCol, $warehouse) {
                            $legacy->whereNull($idCol)->where($nameCol, $warehouse);
                        });
                    }
                });
            } else {
                $q->where($nameCol, $warehouse);
            }
        };

        $query = DB::table('purchases')
            ->whereIn('purchases.status', ['Received', 'Completed'])
            ->where('purchases.date', '<=', $asOfEnd);
        $this->excludeOpeningStockPurchases($query, 'purchases.payment_type');
        if (!empty($allowedOutlets)) {
            $query->whereIn('purchases.warehouse_id', $allowedOutlets);
        }
        $applyWh($query, 'purchases.warehouse_id', 'purchases.warehouse_name');

        // On today/current view, skip fully settled single-payment POs cleanly using index;
        // on historical As-Of queries, scan payload POs as well for exact point-in-time reconstruction.
        $query->where(function ($q) use ($isHistoricalAsOf) {
            $q->where('purchases.due_amount', '>', 0.001)
                ->orWhere('purchases.returned_credit_amount', '>', 0.001)
                ->orWhereColumn('purchases.updated_at', '>', 'purchases.created_at');
            if ($isHistoricalAsOf) {
                $q->orWhere('purchases.payments_payload', 'LIKE', '%},%')
                    ->orWhere(function ($sub) {
                        $sub->whereNotNull('purchases.payments_payload')
                            ->where('purchases.payments_payload', '!=', '')
                            ->where('purchases.payments_payload', '!=', '[]');
                    });
            }
        });

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

        $asOfEpoch = strtotime($asOfDate) ?: time();
        $dayDiffCache = [];
        $calcDaysOverdue = static function (string $docDate) use ($asOfEpoch, &$dayDiffCache): int {
            $day = substr($docDate, 0, 10);
            if (!isset($dayDiffCache[$day])) {
                $ts = strtotime($day);
                $dayDiffCache[$day] = $ts !== false ? max(0, (int)floor(($asOfEpoch - $ts) / 86400)) : 0;
            }
            return $dayDiffCache[$day];
        };
        $fastPayloadPaidAsOf = static function (?string $payload, float $fallbackPaid, string $docDate) use ($accounting, $asOfEnd): float {
            if ($fallbackPaid <= 0.0001 && (empty($payload) || $payload === '[]')) {
                return 0.0;
            }
            if (empty($payload) || $payload === '[]') {
                return round(max(0.0, $fallbackPaid), 2);
            }
            $decoded = json_decode($payload, true);
            if (!is_array($decoded) || empty($decoded)) {
                return round(max(0.0, $fallbackPaid), 2);
            }
            $sum = 0.0;
            foreach ($decoded as $leg) {
                if (!is_array($leg)) continue;
                $amt = (float)($leg['amount'] ?? 0);
                if ($amt <= 0) continue;
                $rawDate = trim((string)($leg['payment_date'] ?? $docDate));
                if ($rawDate === '') {
                    $sum += $amt;
                } elseif (strlen($rawDate) === 19 && $rawDate[4] === '-' && $rawDate[10] === ' ') {
                    if ($rawDate <= $asOfEnd) $sum += $amt;
                } else {
                    if ($accounting->normalizeTransactionDate($rawDate, $docDate) <= $asOfEnd) $sum += $amt;
                }
            }
            return round($sum, 2);
        };

        $supIdByNameAp = [];
        foreach (DB::table('suppliers')->select(['id', 'name'])->get() as $supLookup) {
            $nk = strtolower(trim((string)($supLookup->name ?? '')));
            if ($nk !== '' && !isset($supIdByNameAp[$nk])) {
                $supIdByNameAp[$nk] = (int)$supLookup->id;
            }
        }
        $resolveApSupplier = static function ($rawSid, ?string $rawName) use ($supIdByNameAp): array {
            $sId = (int)($rawSid ?: 0);
            $sName = trim((string)($rawName ?? '')) ?: 'General Supplier';
            if ($sId <= 0) {
                $matched = $supIdByNameAp[strtolower($sName)] ?? 0;
                if ($matched > 0) {
                    $sId = $matched;
                }
            }
            $key = $sId > 0 ? (string)$sId : ('name_' . strtolower($sName));
            return [$sId, $sName, $key];
        };

        foreach ($purchases as $p) {
            $paidAsOf = $fastPayloadPaidAsOf($p->payments_payload ?? null, (float)$p->paid_amount, (string)$p->date);
            $creditAsOf = isset($poHasAnyAlloc[$p->id])
                ? (float)($poAllocAsOf[$p->id] ?? 0.0)
                : (float)($p->returned_credit_amount ?? 0.0);
            $due = round(max(0.0, (float)$p->grand_total - $paidAsOf - $creditAsOf), 2);
            if ($due <= 0.001) {
                continue;
            }

            [$sId, $sName, $key] = $resolveApSupplier($p->supplier_id, $p->supplier_name);

            $days = $calcDaysOverdue((string)$p->date);

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
                    'unallocated_credit' => 0.0,
                    'credit_balance' => 0.0,
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
                $openDays = $calcDaysOverdue($openDateStr);

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
                        'unallocated_credit' => 0.0,
                        'credit_balance' => 0.0,
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
        $applyWh($unallocPrQuery, 'warehouse_id', 'warehouse_name');
        if (!$isHistoricalAsOf) {
            $unallocPrQuery->where(function ($q) {
                $q->where('due_amount', '>', 0.001)
                    ->orWhereColumn('updated_at', '>', 'created_at');
            });
        }
        $openCredits = $unallocPrQuery->select('id', 'supplier_id', 'supplier_name', 'date', 'grand_total', 'paid_amount', 'due_amount', 'payments_payload')->get();
        if ($openCredits->isNotEmpty()) {
            $openCreditSet = array_flip($openCredits->pluck('id')->map(fn ($v) => (int)$v)->all());
            $allocByPr = [];
            if ($accounting->schemaHasTable('purchase_return_allocations') && DB::table('purchase_return_allocations')->exists()) {
                $hasReleasedAt = $accounting->schemaHasColumn('purchase_return_allocations', 'released_at');
                $allocQuery = DB::table('purchase_return_allocations')
                    ->join('purchases', 'purchase_return_allocations.purchase_id', '=', 'purchases.id')
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
                $applyWh($allocQuery, 'purchases.warehouse_id', 'purchases.warehouse_name');
                foreach ($allocQuery
                    ->select('purchase_return_allocations.purchase_return_id', DB::raw('SUM(purchase_return_allocations.amount) as allocated'))
                    ->groupBy('purchase_return_allocations.purchase_return_id')
                    ->pluck('allocated', 'purchase_return_id') as $prId => $allocVal) {
                    if (isset($openCreditSet[(int)$prId])) {
                        $allocByPr[(int)$prId] = (float)$allocVal;
                    }
                }
            }

            foreach ($openCredits as $cr) {
                $refundedAsOf = $fastPayloadPaidAsOf($cr->payments_payload ?? null, (float)$cr->paid_amount, (string)$cr->date);
                $crDueAsOf = round(max(0.0, (float)$cr->grand_total - $refundedAsOf), 2);
                $unalloc = round(max(0.0, $crDueAsOf - (float)($allocByPr[$cr->id] ?? 0.0)), 2);
                if ($unalloc <= 0.001) {
                    continue;
                }
                [$cSupId, $cSupName, $cKey] = $resolveApSupplier($cr->supplier_id, $cr->supplier_name);
                if (!isset($supplierMap[$cKey])) {
                    $supplierMap[$cKey] = [
                        'supplier_id' => $cSupId,
                        'supplier_name' => $cSupName,
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
                        $supplierMap[$cKey][$bucket] = max(0.0, round($avail - $deduct, 2));
                        $rem = round($rem - $deduct, 2);
                    }
                }
                $netRemaining = round((float)$supplierMap[$cKey]['total_due'] - $unalloc, 2);
                if ($netRemaining >= 0) {
                    $supplierMap[$cKey]['total_due'] = $netRemaining;
                } else {
                    $supplierMap[$cKey]['total_due'] = 0.0;
                    $supplierMap[$cKey]['credit_balance'] = round(($supplierMap[$cKey]['credit_balance'] ?? 0.0) + abs($netRemaining), 2);
                }
            }
        }

        // Track unapplied supplier credit balances across all suppliers, then exclude $0-payable credit-only rows from active AP Aging
        $totalCreditBalance = 0.0;
        foreach ($supplierMap as $entry) {
            $totalCreditBalance += (float)($entry['credit_balance'] ?? 0.0);
        }
        $supplierMap = array_filter($supplierMap, static fn ($row) => (float)($row['total_due'] ?? 0.0) > 0.001);

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
                if ((float)$supp['days_90_plus'] > 0.001) {
                    $supp['risk_status'] = 'critical';
                } elseif ((float)$supp['days_61_90'] > 0.001) {
                    $supp['risk_status'] = 'urgent';
                } elseif ((float)$supp['days_31_60'] > 0.001) {
                    $supp['risk_status'] = 'moderate';
                } else {
                    $supp['risk_status'] = 'healthy';
                }
            }
            unset($supp);
        } else {
            foreach ($supplierMap as $k => &$supp) {
                if ((float)$supp['days_90_plus'] > 0.001) {
                    $supp['risk_status'] = 'critical';
                } elseif ((float)$supp['days_61_90'] > 0.001) {
                    $supp['risk_status'] = 'urgent';
                } elseif ((float)$supp['days_31_60'] > 0.001) {
                    $supp['risk_status'] = 'moderate';
                } else {
                    $supp['risk_status'] = 'healthy';
                }
            }
            unset($supp);
        }

        $items = array_values($supplierMap);

        if ($riskFilter !== 'All') {
            $items = array_values(array_filter($items, static fn ($it) => ($it['risk_status'] ?? 'healthy') === $riskFilter));
        }

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

        if (!empty($search) || $riskFilter !== 'All') {
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

        $payload = [
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'summary' => [
                'cost_masked' => !$canViewCost,
                'total_payable' => $canViewCost ? round($totalPayable, 2) : 0.0,
                'total_credit_balance' => $canViewCost ? round($totalCreditBalance, 2) : 0.0,
                'current' => $canViewCost ? round($totalCurrent, 2) : 0.0,
                'days_31_60' => $canViewCost ? round($totalDays31_60, 2) : 0.0,
                'days_61_90' => $canViewCost ? round($totalDays61_90, 2) : 0.0,
                'days_90_plus' => $canViewCost ? round($totalDays90Plus, 2) : 0.0,
                'total_overdue' => $canViewCost ? round($totalOverdue, 2) : 0.0,
                'total_suppliers' => $totalSuppliers,
                'as_of_date' => $asOfDate,
                'warehouse' => $warehouse,
                'risk_filter' => $riskFilter,
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
        ];
        if (!$skipSummary) {
            $whList = $accounting->schemaHasTable('outlets')
                ? DB::table('outlets')
                    ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('id', $allowedOutlets))
                    ->orderBy('name')
                    ->pluck('name')
                    ->filter(fn ($v) => trim((string)$v) !== '')
                    ->values()
                    ->all()
                : [];
            $payload['available_filters'] = [
                'warehouses' => array_values(array_unique($whList)),
                'risk_levels' => ['healthy', 'moderate', 'urgent', 'critical'],
            ];
        }
        return $this->noStoreJson($payload);
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
        $activityFilter = trim((string)($request->input('activity') ?? $request->input('flow_type', 'All')));
        $paymentFilter = trim((string)($request->input('payment_method') ?? $request->input('payment_type', 'All')));
        $userFilter = trim((string)($request->input('user_name') ?? $request->input('created_by', 'All')));
        $search = trim((string)$request->input('search', ''));
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;
        $knownClosingBalance = ($request->has('known_closing_balance') && is_numeric($request->query('known_closing_balance')))
            ? (float)$request->query('known_closing_balance')
            : null;
        $fastPageOnly = ($skipSummary && $knownTotal !== null && $knownClosingBalance !== null);

        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $warehouseId = $this->resolveWarehouseIdParam(is_scalar($warehouse) ? (string)$warehouse : null);
        if (!empty($allowedOutlets) && $warehouse !== 'All' && !empty($warehouse)) {
            if ($warehouseId === null || !in_array($warehouseId, $allowedOutlets, true)) {
                return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
            }
        }

        $startDt = !empty($startDate) ? $accounting->normalizeFilterBoundaryDate((string)$startDate, false) : null;
        $endDt = !empty($endDate) ? $accounting->normalizeFilterBoundaryDate((string)$endDate, true) : null;
        $priorStartDateTime = $startDt;

        // Normalize activity / flow_type filter into exact source flags
        $actLow = strtolower($activityFilter);
        $includeSales = true;
        $includePurchases = true;
        $includeExpenses = true;
        $includePurchaseReturns = true;
        $includeSaleReturns = true;
        $includeFloatIn = true;
        $includeDrops = true;
        $includeVoidRefunds = true;
        if ($actLow === 'operating inflow' || $actLow === 'inflow') {
            $includePurchases = false;
            $includeExpenses = false;
            $includeSaleReturns = false;
            $includeDrops = false;
            $includeVoidRefunds = false;
        } elseif ($actLow === 'operating outflow' || $actLow === 'outflow') {
            $includeSales = false;
            $includePurchaseReturns = false;
            $includeFloatIn = false;
        } elseif ($actLow === 'sales' || $actLow === 'sale') {
            $includePurchases = false;
            $includeExpenses = false;
            $includePurchaseReturns = false;
            $includeSaleReturns = false;
            $includeFloatIn = false;
            $includeDrops = false;
            $includeVoidRefunds = false;
        } elseif ($actLow === 'purchases' || $actLow === 'purchase') {
            $includeSales = false;
            $includeExpenses = false;
            $includePurchaseReturns = false;
            $includeSaleReturns = false;
            $includeFloatIn = false;
            $includeDrops = false;
            $includeVoidRefunds = false;
        } elseif ($actLow === 'expenses' || $actLow === 'expense') {
            $includeSales = false;
            $includePurchases = false;
            $includePurchaseReturns = false;
            $includeSaleReturns = false;
            $includeFloatIn = false;
            $includeDrops = false;
            $includeVoidRefunds = false;
        } elseif ($actLow === 'refunds' || $actLow === 'refund' || $actLow === 'operating flow refund') {
            $includeSales = false;
            $includePurchases = false;
            $includeExpenses = false;
            $includeFloatIn = false;
            $includeDrops = false;
        } elseif (in_array($actLow, ['financing', 'financing capital', 'financing & capital', 'capital', 'investing'], true)) {
            $includeSales = false;
            $includePurchases = false;
            $includeExpenses = false;
            $includePurchaseReturns = false;
            $includeSaleReturns = false;
            $includeVoidRefunds = false;
        }

        $pmLower = ($paymentFilter !== '' && strtolower($paymentFilter) !== 'all') ? strtolower($paymentFilter) : null;
        $userLower = ($userFilter !== '' && strtolower($userFilter) !== 'all') ? strtolower($userFilter) : null;
        $searchLower = ($search !== '') ? strtolower($search) : null;

        $nonCashTypes = [
            'credit note',
            'store credit',
            'credit',
            'unpaid',
            'points',
            'loyalty points',
            'opening stock',
            'none',
            'n/a',
            'void',
            'voided',
        ];
        $applyCashSettlementFilter = static function ($q, string $col) use ($nonCashTypes): void {
            $q->where(function ($sub) use ($col, $nonCashTypes) {
                $sub->whereNull($col)
                    ->orWhereRaw("LOWER(TRIM({$col})) NOT IN (" . implode(',', array_fill(0, count($nonCashTypes), '?')) . ")", $nonCashTypes);
            });
        };
        $applyWh = static function ($q, string $whNameCol = 'warehouse_name', string $whIdCol = 'warehouse_id') use ($warehouse, $warehouseId, $allowedOutlets): void {
            if ($warehouse !== 'All' && !empty($warehouse)) {
                if (is_numeric($warehouse) && $warehouseId !== null) {
                    if ($warehouseId === 1) {
                        $q->where(fn ($sub) => $sub->where($whIdCol, 1)->orWhereNull($whIdCol));
                    } else {
                        $q->where($whIdCol, $warehouseId);
                    }
                } elseif ($warehouseId !== null) {
                    $q->where(function ($sub) use ($whIdCol, $warehouseId, $whNameCol, $warehouse) {
                        $sub->where($whIdCol, $warehouseId)
                            ->orWhere(fn ($inner) => $inner->whereNull($whIdCol)->where($whNameCol, $warehouse));
                    });
                } else {
                    $q->where($whNameCol, $warehouse);
                }
            }
            if (!empty($allowedOutlets)) {
                $q->whereIn($whIdCol, $allowedOutlets);
            }
        };
        $applyPaymentSql = static function ($q, string $col, ?string $fallbackCol = null) use ($pmLower): void {
            if ($pmLower === null) {
                return;
            }
            $expr = $fallbackCol !== null
                ? "COALESCE(NULLIF(TRIM({$col}), ''), NULLIF(TRIM({$fallbackCol}), ''), 'Cash')"
                : "COALESCE(NULLIF(TRIM({$col}), ''), 'Cash')";
            $q->whereRaw("LOWER(TRIM({$expr})) = ?", [$pmLower]);
        };
        $applyUserSql = static function ($q, array $cols, bool $defaultSuperAdmin = true) use ($userLower): void {
            if ($userLower === null) {
                return;
            }
            $like = '%' . $userLower . '%';
            $includeSuperFallback = $defaultSuperAdmin && str_contains('super admin', $userLower);
            $q->where(function ($sub) use ($cols, $like, $includeSuperFallback) {
                foreach ($cols as $idx => $c) {
                    if ($idx === 0) {
                        $sub->whereRaw("LOWER(COALESCE({$c}, '')) LIKE ?", [$like]);
                    } else {
                        $sub->orWhereRaw("LOWER(COALESCE({$c}, '')) LIKE ?", [$like]);
                    }
                }
                if ($includeSuperFallback && count($cols) >= 2) {
                    $primaryCheck = implode(' AND ', array_map(fn ($c) => "({$c} IS NULL OR TRIM({$c}) = '')", array_slice($cols, 0, 2)));
                    $sub->orWhereRaw("({$primaryCheck})");
                }
            });
        };
        $applySearchSql = static function ($q, array $cols) use ($searchLower): void {
            if ($searchLower === null) {
                return;
            }
            $like = '%' . $searchLower . '%';
            $q->where(function ($sub) use ($cols, $like) {
                foreach ($cols as $idx => $c) {
                    if ($idx === 0) {
                        $sub->whereRaw("LOWER(COALESCE({$c}, '')) LIKE ?", [$like]);
                    } else {
                        $sub->orWhereRaw("LOWER(COALESCE({$c}, '')) LIKE ?", [$like]);
                    }
                }
            });
        };

        $hasSpTable = $accounting->schemaHasTable('sale_payments');
        $hasEpTable = $accounting->schemaHasTable('expense_payments');

        $salesCashReceived = 0.0;
        $tenderTotalsMap = [];
        $tenderCountsMap = [];
        $openingBalance = 0.0;
        $totalExpenses = 0.0;
        $dailyInflowsArr = [];
        $dailyExpArr = [];
        $expCatAgg = [];
        $purchCashPaid = 0.0;
        $supplierRefundsReceived = 0.0;
        $refundsPaid = 0.0;
        $financingInflows = 0.0;
        $financingOutflows = 0.0;
        $dailyPurchArr = [];
        $dailySupplierRefundsArr = [];
        $dailyRefundsArr = [];
        $dailyFinInArr = [];
        $dailyFinOutArr = [];
        $sqlBranches = [];
        $precomputedSqlCount = 0;

        // 1. Sales Cash Inflows (pure SQL, SARGable date branches — zero COALESCE filesort)
        // Compute Opening Balance prior sales inflows ALWAYS (invariant across activity/user/search filters)
        $buildLegacySalesBase = function (bool $applyPeriodFilters) use ($hasSpTable, $applyCashSettlementFilter, $applyWh, $applyPaymentSql, $applyUserSql, $applySearchSql) {
            $q = DB::table('sales')
                ->where('paid_amount', '>', 0)
                ->when($hasSpTable, fn ($subQ) => $subQ->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))->from('sale_payments')->whereColumn('sale_payments.sale_id', 'sales.id');
                }));
            $this->applyActiveDocumentFilter($q, 'status');
            $applyCashSettlementFilter($q, 'payment_type');
            $applyWh($q, 'warehouse_name', 'warehouse_id');
            $applyPaymentSql($q, 'payment_type');
            if ($applyPeriodFilters) {
                $applyUserSql($q, ['created_by', 'user_name', 'user_role']);
                $applySearchSql($q, ['reference_no', 'customer_name', 'warehouse_name', 'payment_type', 'created_by', 'user_name', 'notes']);
            }
            return $q;
        };

        $spPeriodBranch = function (bool $isPrior, bool $datedOnly) use (
            $warehouse,
            $warehouseId,
            $allowedOutlets,
            $startDt,
            $endDt,
            $priorStartDateTime,
            $applyCashSettlementFilter,
            $applyPaymentSql,
            $applyUserSql,
            $applySearchSql
        ) {
            $q = DB::table('sale_payments')
                ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
                ->where('sale_payments.amount', '>', 0);
            $this->applyActiveDocumentFilter($q, 'sales.status');
            $applyCashSettlementFilter($q, 'sale_payments.payment_type');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                if (is_numeric($warehouse) && $warehouseId !== null) {
                    if ($warehouseId === 1) {
                        $q->where(fn ($sub) => $sub->where('sales.warehouse_id', 1)->orWhereNull('sales.warehouse_id'));
                    } else {
                        $q->where('sales.warehouse_id', $warehouseId);
                    }
                } elseif ($warehouseId !== null) {
                    $q->where(function ($sub) use ($warehouseId, $warehouse) {
                        $sub->where('sales.warehouse_id', $warehouseId)
                            ->orWhere(fn ($inner) => $inner->whereNull('sales.warehouse_id')->where('sales.warehouse_name', $warehouse));
                    });
                } else {
                    $q->where('sales.warehouse_name', $warehouse);
                }
            }
            if (!empty($allowedOutlets)) {
                $q->whereIn('sales.warehouse_id', $allowedOutlets);
            }
            $applyPaymentSql($q, 'sale_payments.payment_type');
            // Opening Balance (< startDt) stays invariant across user & search filters
            if (!$isPrior) {
                $applyUserSql($q, ['sale_payments.created_by', 'sale_payments.user_name', 'sales.created_by', 'sale_payments.user_role', 'sales.user_role']);
                $applySearchSql($q, ['sales.reference_no', 'sales.customer_name', 'sales.warehouse_name', 'sale_payments.payment_type', 'sale_payments.created_by', 'sale_payments.user_name', 'sales.created_by', 'sale_payments.notes', 'sales.notes']);
            }

            if ($datedOnly) {
                $q->whereNotNull('sale_payments.payment_date');
                if ($isPrior) {
                    $q->where('sale_payments.payment_date', '<', $priorStartDateTime);
                } else {
                    if ($startDt !== null) $q->where('sale_payments.payment_date', '>=', $startDt);
                    if ($endDt !== null) $q->where('sale_payments.payment_date', '<=', $endDt);
                }
            } else {
                $q->whereNull('sale_payments.payment_date');
                if ($isPrior) {
                    $q->where('sales.date', '<', $priorStartDateTime);
                } else {
                    if ($startDt !== null) $q->where('sales.date', '>=', $startDt);
                    if ($endDt !== null) $q->where('sales.date', '<=', $endDt);
                }
            }
            return $q;
        };

        if (!$fastPageOnly && $priorStartDateTime !== null) {
            $priorIn = (float)$buildLegacySalesBase(false)->where('date', '<', $priorStartDateTime)->sum('paid_amount');
            if ($hasSpTable) {
                $priorIn += (float)$spPeriodBranch(true, true)->sum('sale_payments.amount');
                $priorIn += (float)$spPeriodBranch(true, false)->sum('sale_payments.amount');
            }
            $openingBalance += $priorIn;
        }

        if ($includeSales) {
            $legacyNoPaySalesQ = $buildLegacySalesBase(true);
            $legacySalesPeriodQ = (clone $legacyNoPaySalesQ);
            if ($startDt !== null) {
                $legacySalesPeriodQ->where('date', '>=', $startDt);
            }
            if ($endDt !== null) {
                $legacySalesPeriodQ->where('date', '<=', $endDt);
            }

            $salesSqlCount = 0;
            if (!$fastPageOnly) {
                if (!$skipSummary) {
                    foreach ((clone $legacySalesPeriodQ)
                        ->selectRaw("payment_type, SUBSTRING(date, 1, 10) as day_date, SUM(paid_amount) as amount, COUNT(*) as count")
                        ->groupBy('payment_type', DB::raw("SUBSTRING(date, 1, 10)"))
                        ->get() as $lt) {
                        $amt = (float)$lt->amount;
                        $cnt = (int)$lt->count;
                        $salesCashReceived += $amt;
                        $salesSqlCount += $cnt;
                        $precomputedSqlCount += $cnt;
                        $pt = $lt->payment_type ?: 'Cash';
                        $tenderTotalsMap[$pt] = ($tenderTotalsMap[$pt] ?? 0.0) + $amt;
                        $tenderCountsMap[$pt] = ($tenderCountsMap[$pt] ?? 0) + $cnt;
                        $dKey = (string)$lt->day_date;
                        $dailyInflowsArr[$dKey] = ($dailyInflowsArr[$dKey] ?? 0.0) + $amt;
                    }
                } else {
                    $salesCashReceived += (float)(clone $legacySalesPeriodQ)->sum('paid_amount');
                }
                if ($hasSpTable) {
                    foreach ([true, false] as $datedOnly) {
                        $spBranchQ = $spPeriodBranch(false, $datedOnly);
                        if (!$skipSummary) {
                            $dateExpr = $datedOnly ? 'sale_payments.payment_date' : 'sales.date';
                            foreach ((clone $spBranchQ)
                                ->selectRaw("sale_payments.payment_type, SUBSTRING({$dateExpr}, 1, 10) as day_date, SUM(sale_payments.amount) as amount, COUNT(*) as count")
                                ->groupBy('sale_payments.payment_type', DB::raw("SUBSTRING({$dateExpr}, 1, 10)"))
                                ->get() as $spt) {
                                $amt = (float)$spt->amount;
                                $cnt = (int)$spt->count;
                                $salesCashReceived += $amt;
                                $salesSqlCount += $cnt;
                                $precomputedSqlCount += $cnt;
                                $pt = $spt->payment_type ?: 'Cash';
                                $tenderTotalsMap[$pt] = ($tenderTotalsMap[$pt] ?? 0.0) + $amt;
                                $tenderCountsMap[$pt] = ($tenderCountsMap[$pt] ?? 0) + $cnt;
                                $dKey = (string)$spt->day_date;
                                $dailyInflowsArr[$dKey] = ($dailyInflowsArr[$dKey] ?? 0.0) + $amt;
                            }
                        } else {
                            $salesCashReceived += (float)(clone $spBranchQ)->sum('sale_payments.amount');
                        }
                    }
                }
            }

            $sqlBranches[] = [
                'q' => (clone $legacySalesPeriodQ)->select([
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
                ]),
                'dateCol' => 'date',
                'idCol' => 'id',
                'groupKey' => 'sales',
            ];
            if ($hasSpTable) {
                $sqlBranches[] = [
                    'q' => $spPeriodBranch(false, true)->select([
                        'sales.id',
                        'sales.reference_no',
                        'sale_payments.payment_date as date',
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
                    ]),
                    'dateCol' => 'sale_payments.payment_date',
                    'idCol' => null,
                    'groupKey' => 'sales',
                ];
                $sqlBranches[] = [
                    'q' => $spPeriodBranch(false, false)->select([
                        'sales.id',
                        'sales.reference_no',
                        'sales.date as date',
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
                    ]),
                    'dateCol' => 'sales.date',
                    'idCol' => 'sales.id',
                    'groupKey' => 'sales',
                ];
            }
        }

        // 2. Expenses Paid (pure SQL via SARGable JOIN expense_payments + legacy fallback)
        $expFilter = static function ($q, string $alias = '') {
            $p = $alias !== '' ? $alias . '.' : '';
            $q->where(function ($sub) use ($p) {
                $sub->whereNull("{$p}payment_status")
                    ->orWhereNotIn("{$p}payment_status", [
                        'Pending', 'pending', 'PENDING',
                        'Draft', 'draft', 'DRAFT',
                        'Voided', 'voided', 'VOIDED',
                        'Cancelled', 'cancelled', 'CANCELLED',
                    ]);
            });
        };

        $epBranch = function (bool $isPrior, bool $datedOnly) use (
            $expFilter,
            $applyCashSettlementFilter,
            $applyWh,
            $applyPaymentSql,
            $applyUserSql,
            $applySearchSql,
            $priorStartDateTime,
            $startDt,
            $endDt
        ) {
            $q = DB::table('expense_payments')
                ->join('expenses', 'expense_payments.expense_id', '=', 'expenses.id')
                ->where('expense_payments.amount', '>', 0.0001);
            $expFilter($q, 'expenses');
            $applyCashSettlementFilter($q, 'expense_payments.payment_type');
            $applyWh($q, 'expenses.warehouse_name', 'expenses.warehouse_id');
            $applyPaymentSql($q, 'expense_payments.payment_type', 'expenses.payment_method');
            if (!$isPrior) {
                $applyUserSql($q, ['expense_payments.user_name', 'expense_payments.created_by', 'expenses.created_by', 'expenses.user_name', 'expense_payments.user_role', 'expenses.user_role']);
                $applySearchSql($q, ['expense_payments.reference_no', 'expenses.reference_no', 'expenses.title', 'expenses.expense_category_name', 'expenses.warehouse_name', 'expense_payments.payment_type', 'expense_payments.user_name', 'expense_payments.created_by', 'expenses.created_by', 'expense_payments.notes', 'expenses.details']);
            }

            if ($datedOnly) {
                $q->whereNotNull('expense_payments.payment_date');
                if ($isPrior) {
                    $q->where('expense_payments.payment_date', '<', $priorStartDateTime);
                } else {
                    if ($startDt !== null) $q->where('expense_payments.payment_date', '>=', $startDt);
                    if ($endDt !== null) $q->where('expense_payments.payment_date', '<=', $endDt);
                }
            } else {
                $q->whereNull('expense_payments.payment_date');
                if ($isPrior) {
                    $q->where('expenses.date', '<', $priorStartDateTime);
                } else {
                    if ($startDt !== null) $q->where('expenses.date', '>=', $startDt);
                    if ($endDt !== null) $q->where('expenses.date', '<=', $endDt);
                }
            }
            return $q;
        };

        $expEffPaidExpr = "CASE WHEN COALESCE(paid_amount, 0) > 0.0001 THEN paid_amount WHEN COALESCE(due_amount, 0) <= 0.0001 AND LOWER(TRIM(COALESCE(payment_status, ''))) IN ('', 'paid') THEN amount ELSE 0 END";
        $buildExpLegacyBase = function (bool $applyPeriodFilters) use ($hasEpTable, $expFilter, $applyCashSettlementFilter, $applyWh, $applyPaymentSql, $applyUserSql, $applySearchSql) {
            $q = DB::table('expenses')
                ->when($hasEpTable, fn ($subQ) => $subQ->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))->from('expense_payments')->whereColumn('expense_payments.expense_id', 'expenses.id');
                }))
                ->whereRaw("LOWER(TRIM(COALESCE(payment_status, ''))) != 'unpaid'");
            $expFilter($q, '');
            $applyCashSettlementFilter($q, 'payment_method');
            $applyWh($q, 'warehouse_name', 'warehouse_id');
            $applyPaymentSql($q, 'payment_method');
            if ($applyPeriodFilters) {
                $applyUserSql($q, ['created_by', 'user_name', 'user_role']);
                $applySearchSql($q, ['reference_no', 'title', 'expense_category_name', 'warehouse_name', 'payment_method', 'created_by', 'user_name', 'details']);
            }
            return $q;
        };

        if (!$fastPageOnly && $priorStartDateTime !== null) {
            if ($hasEpTable) {
                $openingBalance -= (float)$epBranch(true, true)->sum('expense_payments.amount');
                $openingBalance -= (float)$epBranch(true, false)->sum('expense_payments.amount');
            }
            $priorLegacyExpOut = (float)$buildExpLegacyBase(false)
                ->where('date', '<', $priorStartDateTime)
                ->selectRaw("COALESCE(SUM({$expEffPaidExpr}), 0) as s")
                ->value('s');
            $openingBalance -= $priorLegacyExpOut;
        }

        if ($includeExpenses) {
            if ($hasEpTable) {
                if (!$fastPageOnly) {
                    foreach ([true, false] as $datedOnly) {
                        $epQ = $epBranch(false, $datedOnly);
                        if (!$skipSummary) {
                            $dateExpr = $datedOnly ? 'expense_payments.payment_date' : 'expenses.date';
                            foreach ((clone $epQ)
                                ->selectRaw("COALESCE(expenses.expense_category_name, 'General Expenses') as cat_name, SUBSTRING({$dateExpr}, 1, 10) as day_date, SUM(expense_payments.amount) as amt, COUNT(*) as cnt")
                                ->groupBy(DB::raw("COALESCE(expenses.expense_category_name, 'General Expenses')"), DB::raw("SUBSTRING({$dateExpr}, 1, 10)"))
                                ->get() as $epRow) {
                                $amt = (float)$epRow->amt;
                                $cnt = (int)$epRow->cnt;
                                $totalExpenses += $amt;
                                $precomputedSqlCount += $cnt;
                                $dKey = (string)$epRow->day_date;
                                $dailyExpArr[$dKey] = ($dailyExpArr[$dKey] ?? 0.0) + $amt;
                                $cat = (string)$epRow->cat_name;
                                if (!isset($expCatAgg[$cat])) $expCatAgg[$cat] = ['amount' => 0.0, 'count' => 0];
                                $expCatAgg[$cat]['amount'] += $amt;
                                $expCatAgg[$cat]['count'] += $cnt;
                            }
                        } else {
                            $totalExpenses += (float)(clone $epQ)->sum('expense_payments.amount');
                        }
                    }
                }

                $sqlBranches[] = [
                    'q' => $epBranch(false, true)->select([
                        'expenses.id',
                        DB::raw('COALESCE(expense_payments.reference_no, expenses.reference_no) as reference_no'),
                        'expense_payments.payment_date as date',
                        DB::raw("'expense' as type"),
                        DB::raw("'Operating Outflow (Expense)' as type_label"),
                        DB::raw("'Operating' as activity"),
                        DB::raw("COALESCE(expenses.title, expenses.expense_category_name, 'General Expense') as party_name"),
                        'expenses.warehouse_name',
                        DB::raw("COALESCE(expense_payments.payment_type, expenses.payment_method, 'Cash') as payment_method"),
                        DB::raw('0 as amount_in'),
                        'expense_payments.amount as amount_out',
                        DB::raw("COALESCE(expense_payments.user_name, expense_payments.created_by, expenses.created_by, expenses.user_name, 'Super Admin') as created_by"),
                        DB::raw("COALESCE(expense_payments.user_role, expenses.user_role, 'Super Admin') as user_role"),
                        DB::raw("COALESCE(expenses.payment_status, 'Paid') as status"),
                        DB::raw("COALESCE(expense_payments.notes, expenses.details, '') as notes"),
                    ]),
                    'dateCol' => 'expense_payments.payment_date',
                    'idCol' => null,
                ];
                $sqlBranches[] = [
                    'q' => $epBranch(false, false)->select([
                        'expenses.id',
                        DB::raw('COALESCE(expense_payments.reference_no, expenses.reference_no) as reference_no'),
                        'expenses.date as date',
                        DB::raw("'expense' as type"),
                        DB::raw("'Operating Outflow (Expense)' as type_label"),
                        DB::raw("'Operating' as activity"),
                        DB::raw("COALESCE(expenses.title, expenses.expense_category_name, 'General Expense') as party_name"),
                        'expenses.warehouse_name',
                        DB::raw("COALESCE(expense_payments.payment_type, expenses.payment_method, 'Cash') as payment_method"),
                        DB::raw('0 as amount_in'),
                        'expense_payments.amount as amount_out',
                        DB::raw("COALESCE(expense_payments.user_name, expense_payments.created_by, expenses.created_by, expenses.user_name, 'Super Admin') as created_by"),
                        DB::raw("COALESCE(expense_payments.user_role, expenses.user_role, 'Super Admin') as user_role"),
                        DB::raw("COALESCE(expenses.payment_status, 'Paid') as status"),
                        DB::raw("COALESCE(expense_payments.notes, expenses.details, '') as notes"),
                    ]),
                    'dateCol' => 'expenses.date',
                    'idCol' => 'expenses.id',
                ];
            }

            $expLegacyQ = $buildExpLegacyBase(true);
            $expLegacyPeriodQ = (clone $expLegacyQ);
            if ($startDt !== null) $expLegacyPeriodQ->where('date', '>=', $startDt);
            if ($endDt !== null) $expLegacyPeriodQ->where('date', '<=', $endDt);
            $expLegacyPeriodQ->whereRaw("({$expEffPaidExpr}) > 0.0001");

            if (!$fastPageOnly) {
                if (!$skipSummary) {
                    foreach ((clone $expLegacyPeriodQ)
                        ->selectRaw("COALESCE(expense_category_name, 'General Expenses') as cat_name, SUBSTRING(date, 1, 10) as day_date, SUM({$expEffPaidExpr}) as amt, COUNT(*) as cnt")
                        ->groupBy(DB::raw("COALESCE(expense_category_name, 'General Expenses')"), DB::raw("SUBSTRING(date, 1, 10)"))
                        ->get() as $elRow) {
                        $amt = (float)$elRow->amt;
                        $cnt = (int)$elRow->cnt;
                        $totalExpenses += $amt;
                        $precomputedSqlCount += $cnt;
                        $dKey = (string)$elRow->day_date;
                        $dailyExpArr[$dKey] = ($dailyExpArr[$dKey] ?? 0.0) + $amt;
                        $cat = (string)$elRow->cat_name;
                        if (!isset($expCatAgg[$cat])) $expCatAgg[$cat] = ['amount' => 0.0, 'count' => 0];
                        $expCatAgg[$cat]['amount'] += $amt;
                        $expCatAgg[$cat]['count'] += $cnt;
                    }
                } else {
                    $totalExpenses += (float)(clone $expLegacyPeriodQ)->selectRaw("COALESCE(SUM({$expEffPaidExpr}), 0) as s")->value('s');
                }
            }

            $sqlBranches[] = [
                'q' => (clone $expLegacyPeriodQ)->select([
                    'id',
                    'reference_no',
                    'date',
                    DB::raw("'expense' as type"),
                    DB::raw("'Operating Outflow (Expense)' as type_label"),
                    DB::raw("'Operating' as activity"),
                    DB::raw("COALESCE(title, expense_category_name, 'General Expense') as party_name"),
                    'warehouse_name',
                    DB::raw("COALESCE(payment_method, 'Cash') as payment_method"),
                    DB::raw('0 as amount_in'),
                    DB::raw("({$expEffPaidExpr}) as amount_out"),
                    DB::raw("COALESCE(created_by, user_name, 'Super Admin') as created_by"),
                    DB::raw("COALESCE(user_role, 'Super Admin') as user_role"),
                    DB::raw("COALESCE(payment_status, 'Paid') as status"),
                    DB::raw("COALESCE(details, '') as notes"),
                ]),
                'dateCol' => 'date',
                'idCol' => 'id',
            ];
        }

        // 3. Payment-dated Purchase Outflows, Purchase Return Refunds, and Sale Return Refunds
        $offset = ($page - 1) * $perPage;
        $needTopN = $offset + $perPage + 1;
        $payloadTxnIndex = []; // compact tuples: [date, id, srcType, amountIn, amountOut, legIdx]
        $payloadNeedMetaFilter = ($pmLower !== null || $userLower !== null || $searchLower !== null);

        $scanPayloadDocs = function (
            $baseQ,
            string $srcType,
            bool $isInflow,
            ?string $defaultTenderCol = null,
            bool $excludeCreditDefault = true,
            bool $includeInPeriod = true
        ) use (
            $accounting,
            $priorStartDateTime,
            $startDt,
            $endDt,
            &$openingBalance,
            &$purchCashPaid,
            &$supplierRefundsReceived,
            &$refundsPaid,
            &$tenderTotalsMap,
            &$tenderCountsMap,
            &$dailyPurchArr,
            &$dailySupplierRefundsArr,
            &$dailyRefundsArr,
            &$payloadTxnIndex,
            $skipSummary,
            $fastPageOnly,
            $needTopN,
            $pmLower,
            $userLower,
            $searchLower,
            $payloadNeedMetaFilter
        ): void {
            if (!$includeInPeriod && ($fastPageOnly || $priorStartDateTime === null)) {
                return;
            }
            $cols = ['id', 'date', 'payment_type', 'paid_amount', 'payments_payload'];
            if ($defaultTenderCol !== null) {
                $cols[] = $defaultTenderCol;
            }
            if ($includeInPeriod && $payloadNeedMetaFilter) {
                $partyCol = ($srcType === 'sale_return') ? 'customer_name' : 'supplier_name';
                $cols = array_merge($cols, ['reference_no', $partyCol, 'warehouse_name', 'created_by', 'user_name', 'user_role', 'notes']);
            }
            if ($pmLower !== null && $pmLower !== 'cash') {
                $pmLike = '%' . $pmLower . '%';
                $baseQ->where(function ($sub) use ($pmLike, $pmLower, $defaultTenderCol) {
                    $sub->whereRaw("LOWER(COALESCE(payments_payload, '')) LIKE ?", [$pmLike])
                        ->orWhereRaw("LOWER(TRIM(COALESCE(payment_type, ''))) = ?", [$pmLower]);
                    if ($defaultTenderCol !== null) {
                        $sub->orWhereRaw("LOWER(TRIM(COALESCE({$defaultTenderCol}, ''))) = ?", [$pmLower]);
                    }
                });
            }

            // Fast slice when skip_summary=1 with known_total + known_closing_balance: top N by date + any multi-leg/later-paid docs
            if ($fastPageOnly) {
                $topIds = (clone $baseQ)
                    ->orderByDesc('date')
                    ->orderByDesc('id')
                    ->limit($needTopN)
                    ->pluck('id')
                    ->all();
                $laterIds = (clone $baseQ)
                    ->where(function ($sub) {
                        $sub->where('payments_payload', 'LIKE', '%},%')
                            ->orWhereColumn('updated_at', '>', 'created_at');
                    })
                    ->pluck('id')
                    ->all();
                $targetIds = array_values(array_unique(array_merge($topIds, $laterIds)));
                if (empty($targetIds)) {
                    return;
                }
                $cursor = (clone $baseQ)->whereIn('id', $targetIds)->select($cols)->cursor();
            } else {
                $cursor = $baseQ->select($cols)->cursor();
            }

            $srcPartyCol = ($srcType === 'sale_return') ? 'customer_name' : 'supplier_name';
            $recordLeg = function (string $rawDt, int $docId, float $amt, int $legIdx, string $ptRaw, ?array $leg = null, ?object $doc = null) use (
                $srcType,
                $isInflow,
                $includeInPeriod,
                $priorStartDateTime,
                $startDt,
                $endDt,
                $fastPageOnly,
                $skipSummary,
                $pmLower,
                $userLower,
                $searchLower,
                $srcPartyCol,
                &$openingBalance,
                &$purchCashPaid,
                &$supplierRefundsReceived,
                &$refundsPaid,
                &$tenderTotalsMap,
                &$tenderCountsMap,
                &$dailyPurchArr,
                &$dailySupplierRefundsArr,
                &$dailyRefundsArr,
                &$payloadTxnIndex
            ): void {
                $pmEff = $ptRaw !== '' ? $ptRaw : 'Cash';
                if ($pmLower !== null && strtolower(trim($pmEff)) !== $pmLower) {
                    return;
                }
                // Opening Balance (< startDt) stays invariant across activity, user, and search filters
                if ($priorStartDateTime !== null && $rawDt < $priorStartDateTime) {
                    if (!$fastPageOnly) {
                        $openingBalance += ($isInflow ? $amt : -$amt);
                    }
                    return;
                }
                if (!$includeInPeriod) {
                    return;
                }
                if ($userLower !== null && $doc !== null) {
                    $by = (string)(($leg['user_name'] ?? null) ?: (($doc->created_by ?? null) ?: ($doc->user_name ?? 'Super Admin')));
                    $role = (string)(($leg['user_role'] ?? null) ?: ($doc->user_role ?? 'Super Admin'));
                    if (!str_contains(strtolower($by), $userLower) && !str_contains(strtolower($role), $userLower)) {
                        return;
                    }
                }
                if ($searchLower !== null && $doc !== null) {
                    $ref = (string)(($leg['reference_no'] ?? null) ?: ($doc->reference_no ?? ''));
                    $party = (string)($doc->$srcPartyCol ?? '');
                    $wh = (string)($doc->warehouse_name ?? '');
                    $by = (string)(($leg['user_name'] ?? null) ?: (($doc->created_by ?? null) ?: ($doc->user_name ?? 'Super Admin')));
                    $notes = (string)(($leg['notes'] ?? null) ?: ($doc->notes ?? ''));
                    if (
                        !str_contains(strtolower($ref), $searchLower) &&
                        !str_contains(strtolower($party), $searchLower) &&
                        !str_contains(strtolower($wh), $searchLower) &&
                        !str_contains(strtolower($pmEff), $searchLower) &&
                        !str_contains(strtolower($by), $searchLower) &&
                        !str_contains(strtolower($notes), $searchLower)
                    ) {
                        return;
                    }
                }
                if (($startDt !== null && $rawDt < $startDt) || ($endDt !== null && $rawDt > $endDt)) {
                    return;
                }
                if (!$fastPageOnly) {
                    $dayKey = substr($rawDt, 0, 10);
                    if ($srcType === 'purchase') {
                        $purchCashPaid += $amt;
                        if (!$skipSummary) $dailyPurchArr[$dayKey] = ($dailyPurchArr[$dayKey] ?? 0.0) + $amt;
                    } elseif ($srcType === 'purchase_return') {
                        $supplierRefundsReceived += $amt;
                        if (!$skipSummary) {
                            $dailySupplierRefundsArr[$dayKey] = ($dailySupplierRefundsArr[$dayKey] ?? 0.0) + $amt;
                            $tenderTotalsMap[$pmEff] = ($tenderTotalsMap[$pmEff] ?? 0.0) + $amt;
                            $tenderCountsMap[$pmEff] = ($tenderCountsMap[$pmEff] ?? 0) + 1;
                        }
                    } else {
                        $refundsPaid += $amt;
                        if (!$skipSummary) $dailyRefundsArr[$dayKey] = ($dailyRefundsArr[$dayKey] ?? 0.0) + $amt;
                    }
                }
                $payloadTxnIndex[] = [
                    $rawDt,
                    $docId,
                    $srcType,
                    $isInflow ? $amt : 0.0,
                    $isInflow ? 0.0 : $amt,
                    $legIdx,
                ];
            };

            foreach ($cursor as $doc) {
                $rawPayload = (string)($doc->payments_payload ?? '');
                $legs = ($rawPayload !== '' && $rawPayload !== '[]') ? json_decode($rawPayload, true) : null;
                if (is_array($legs) && !empty($legs)) {
                    foreach ($legs as $legIdx => $leg) {
                        if (!is_array($leg)) continue;
                        $amt = (float)($leg['amount'] ?? 0);
                        if ($amt <= 0.0001) continue;
                        $ptRaw = (string)($leg['payment_type'] ?? ($defaultTenderCol ? ($doc->$defaultTenderCol ?? '') : '') ?: ($doc->payment_type ?? ''));
                        if ($excludeCreditDefault && $accounting->isNonCashSettlementType($ptRaw)) {
                            continue;
                        }
                        $rawCand = (string)($leg['payment_date'] ?? $doc->date);
                        $rawDt = (strlen($rawCand) === 19 && $rawCand[4] === '-' && $rawCand[10] === ' ')
                            ? $rawCand
                            : $accounting->normalizeTransactionDate($rawCand, (string)$doc->date);
                        $recordLeg($rawDt, (int)$doc->id, $amt, (int)$legIdx, $ptRaw, $leg, $doc);
                    }
                } else {
                    $ptRaw = (string)(($defaultTenderCol ? ($doc->$defaultTenderCol ?? '') : '') ?: ($doc->payment_type ?? ''));
                    if ($excludeCreditDefault && $accounting->isNonCashSettlementType($ptRaw)) {
                        continue;
                    }
                    $amt = (float)$doc->paid_amount;
                    if ($amt <= 0.0001) continue;
                    $docDateStr = (string)$doc->date;
                    $rawDt = (strlen($docDateStr) === 19 && $docDateStr[4] === '-' && $docDateStr[10] === ' ')
                        ? $docDateStr
                        : $accounting->normalizeTransactionDate($docDateStr, $docDateStr);
                    $recordLeg($rawDt, (int)$doc->id, $amt, -1, $ptRaw, null, $doc);
                }
            }
        };

        $skipSingleNonCashSql = static function ($q, string $nonCashLower, ?string $tenderCol = null): void {
            $tenderExpr = $tenderCol !== null
                ? "LOWER(TRIM(COALESCE(NULLIF({$tenderCol}, ''), payment_type, '')))"
                : "LOWER(TRIM(COALESCE(payment_type, '')))";
            $q->whereRaw("NOT (
                {$tenderExpr} = ?
                AND (
                    payments_payload IS NULL
                    OR payments_payload = ''
                    OR payments_payload = '[]'
                    OR (payments_payload NOT LIKE '%},%' AND LOWER(payments_payload) LIKE ?)
                )
            )", [$nonCashLower, '%"payment_type":"' . $nonCashLower . '"%']);
        };

        if ($includePurchases || (!$fastPageOnly && $priorStartDateTime !== null)) {
            $purchBaseQ = DB::table('purchases')
                ->whereIn('status', ['Received', 'Completed'])
                ->where('paid_amount', '>', 0);
            $this->excludeOpeningStockPurchases($purchBaseQ, 'payment_type');
            $applyWh($purchBaseQ, 'warehouse_name', 'warehouse_id');
            $scanPayloadDocs($purchBaseQ, 'purchase', false, null, true, $includePurchases);
        }

        if ($includePurchaseReturns || (!$fastPageOnly && $priorStartDateTime !== null)) {
            $prBaseQ = DB::table('purchase_returns')
                ->whereIn('status', ['Completed', 'Received'])
                ->where('paid_amount', '>', 0);
            $skipSingleNonCashSql($prBaseQ, 'credit note');
            $applyWh($prBaseQ, 'warehouse_name', 'warehouse_id');
            $scanPayloadDocs($prBaseQ, 'purchase_return', true, null, true, $includePurchaseReturns);
        }

        if ($includeSaleReturns || (!$fastPageOnly && $priorStartDateTime !== null)) {
            $retQuery = DB::table('sale_returns')->where('paid_amount', '>', 0);
            $this->applyActiveDocumentFilter($retQuery, 'status');
            $skipSingleNonCashSql($retQuery, 'store credit', 'refund_tender');
            $applyWh($retQuery, 'warehouse_name', 'warehouse_id');
            $scanPayloadDocs($retQuery, 'sale_return', false, 'refund_tender', true, $includeSaleReturns);
        }

        // 3b. Cash Register Drawer Flows (float_in, drop/safe_drop -> Financing; void_refund -> Operating Refund)
        if ($accounting->schemaHasTable('cash_register_transactions') && $accounting->schemaHasTable('cash_registers') && ($pmLower === null || $pmLower === 'cash')) {
            $hasCrtUserId = $accounting->schemaHasColumn('cash_register_transactions', 'user_id');
            $hasCrtAuthorizedBy = $accounting->schemaHasColumn('cash_register_transactions', 'authorized_by');
            $crtUserCols = array_values(array_filter([
                $hasCrtUserId ? 'u.name' : null,
                $hasCrtAuthorizedBy ? 'crt.authorized_by' : null,
                'cr.user_name',
            ]));
            $crtCreatedByExpr = $hasCrtUserId
                ? "COALESCE(u.name, " . ($hasCrtAuthorizedBy ? "NULLIF(TRIM(crt.authorized_by), ''), " : '') . "cr.user_name, 'Super Admin')"
                : ($hasCrtAuthorizedBy
                    ? "COALESCE(NULLIF(TRIM(crt.authorized_by), ''), cr.user_name, 'Super Admin')"
                    : "COALESCE(cr.user_name, 'Super Admin')");
            $buildCrtQ = function (bool $isPrior, array $types) use ($applyWh, $applyUserSql, $applySearchSql, $priorStartDateTime, $startDt, $endDt, $hasCrtUserId, $crtUserCols) {
                $q = DB::table('cash_register_transactions as crt')
                    ->join('cash_registers as cr', 'crt.cash_register_id', '=', 'cr.id')
                    ->where('crt.amount', '>', 0.0001)
                    ->whereIn('crt.type', $types);
                if ($hasCrtUserId) {
                    $q->leftJoin('users as u', 'crt.user_id', '=', 'u.id');
                }
                $applyWh($q, 'cr.warehouse_name', 'cr.warehouse_id');
                if ($isPrior) {
                    $q->where('crt.created_at', '<', $priorStartDateTime);
                } else {
                    if ($startDt !== null) $q->where('crt.created_at', '>=', $startDt);
                    if ($endDt !== null) $q->where('crt.created_at', '<=', $endDt);
                    $applyUserSql($q, $crtUserCols);
                    $applySearchSql($q, array_merge(['cr.reference_no', 'cr.warehouse_name', 'crt.reason', 'crt.type'], $crtUserCols));
                }
                return $q;
            };

            if (!$fastPageOnly && $priorStartDateTime !== null) {
                $priorCrt = $buildCrtQ(true, ['float_in', 'drop', 'safe_drop', 'void_refund'])
                    ->selectRaw("
                        COALESCE(SUM(CASE WHEN crt.type = 'float_in' THEN crt.amount ELSE 0 END), 0) as fin_in,
                        COALESCE(SUM(CASE WHEN crt.type IN ('drop', 'safe_drop', 'void_refund') THEN crt.amount ELSE 0 END), 0) as fin_out
                    ")->first();
                $openingBalance += ((float)($priorCrt->fin_in ?? 0) - (float)($priorCrt->fin_out ?? 0));
            }

            $activeCrtTypes = [];
            if ($includeFloatIn) $activeCrtTypes[] = 'float_in';
            if ($includeDrops) {
                $activeCrtTypes[] = 'drop';
                $activeCrtTypes[] = 'safe_drop';
            }
            if ($includeVoidRefunds) $activeCrtTypes[] = 'void_refund';

            if (!empty($activeCrtTypes)) {
                $crtPeriodQ = $buildCrtQ(false, $activeCrtTypes);
                if (!$fastPageOnly) {
                    if (!$skipSummary) {
                        foreach ((clone $crtPeriodQ)
                            ->selectRaw("crt.type, SUBSTRING(crt.created_at, 1, 10) as day_date, SUM(crt.amount) as amt, COUNT(*) as cnt")
                            ->groupBy('crt.type', DB::raw("SUBSTRING(crt.created_at, 1, 10)"))
                            ->get() as $crtRow) {
                            $amt = (float)$crtRow->amt;
                            $cnt = (int)$crtRow->cnt;
                            $dKey = (string)$crtRow->day_date;
                            $precomputedSqlCount += $cnt;
                            if ($crtRow->type === 'float_in') {
                                $financingInflows += $amt;
                                $dailyFinInArr[$dKey] = ($dailyFinInArr[$dKey] ?? 0.0) + $amt;
                                $tenderTotalsMap['Cash'] = ($tenderTotalsMap['Cash'] ?? 0.0) + $amt;
                                $tenderCountsMap['Cash'] = ($tenderCountsMap['Cash'] ?? 0) + $cnt;
                            } elseif ($crtRow->type === 'void_refund') {
                                $refundsPaid += $amt;
                                $dailyRefundsArr[$dKey] = ($dailyRefundsArr[$dKey] ?? 0.0) + $amt;
                            } else {
                                $financingOutflows += $amt;
                                $dailyFinOutArr[$dKey] = ($dailyFinOutArr[$dKey] ?? 0.0) + $amt;
                            }
                        }
                    } else {
                        $crtAgg = (clone $crtPeriodQ)->selectRaw("
                            COALESCE(SUM(CASE WHEN crt.type = 'float_in' THEN crt.amount ELSE 0 END), 0) as fin_in,
                            COALESCE(SUM(CASE WHEN crt.type IN ('drop', 'safe_drop') THEN crt.amount ELSE 0 END), 0) as fin_out,
                            COALESCE(SUM(CASE WHEN crt.type = 'void_refund' THEN crt.amount ELSE 0 END), 0) as v_ref
                        ")->first();
                        $financingInflows += (float)($crtAgg->fin_in ?? 0);
                        $financingOutflows += (float)($crtAgg->fin_out ?? 0);
                        $refundsPaid += (float)($crtAgg->v_ref ?? 0);
                    }
                }

                $sqlBranches[] = [
                    'q' => (clone $crtPeriodQ)->select([
                        'crt.id',
                        DB::raw("COALESCE(cr.reference_no, 'CR-TXN') as reference_no"),
                        'crt.created_at as date',
                        DB::raw("CASE WHEN crt.type = 'void_refund' THEN 'sale_return' ELSE 'capital' END as type"),
                        DB::raw("CASE WHEN crt.type = 'float_in' THEN 'Cash Float In' WHEN crt.type = 'void_refund' THEN 'Void Cash Refund' ELSE 'Cash / Safe Drop' END as type_label"),
                        DB::raw("CASE WHEN crt.type = 'void_refund' THEN 'Operating' ELSE 'Financing' END as activity"),
                        DB::raw("COALESCE(NULLIF(TRIM(crt.reason), ''), cr.register_name, 'Cash Drawer') as party_name"),
                        'cr.warehouse_name',
                        DB::raw("'Cash' as payment_method"),
                        DB::raw("CASE WHEN crt.type = 'float_in' THEN crt.amount ELSE 0 END as amount_in"),
                        DB::raw("CASE WHEN crt.type = 'float_in' THEN 0 ELSE crt.amount END as amount_out"),
                        DB::raw("{$crtCreatedByExpr} as created_by"),
                        DB::raw("'Cashier' as user_role"),
                        DB::raw("'Completed' as status"),
                        DB::raw("COALESCE(crt.reason, '') as notes"),
                    ]),
                    'dateCol' => 'crt.created_at',
                    'idCol' => 'crt.id',
                ];
            }
        }

        $totalInflows = $salesCashReceived + $supplierRefundsReceived + $financingInflows;
        $totalOutflows = $purchCashPaid + $totalExpenses + $refundsPaid + $financingOutflows;
        $netCashFlow = $totalInflows - $totalOutflows;
        $closingBalance = $fastPageOnly ? $knownClosingBalance : ($openingBalance + $netCashFlow);

        // 4. Tender Breakdown, Expense Categories, Daily Timeline & Filter Metadata (Page 1 only)
        $tenderInflows = collect();
        $expenseCategories = collect();
        $timeline = [];
        $availableFilters = null;
        if (!$skipSummary) {
            arsort($tenderTotalsMap);
            $tenderInflows = collect($tenderTotalsMap)->map(fn ($amt, $pt) => [
                'payment_type' => $pt,
                'amount' => round((float)$amt, 2),
                'count' => (int)($tenderCountsMap[$pt] ?? 0),
            ])->values();

            uasort($expCatAgg, static fn ($a, $b) => $b['amount'] <=> $a['amount']);
            $expenseCategories = collect($expCatAgg)->map(fn ($row, $cat) => [
                'category' => $cat,
                'amount'   => round((float)$row['amount'], 2),
                'count'    => (int)$row['count'],
            ])->values();

            $allDates = array_unique(array_merge(
                array_keys($dailyInflowsArr),
                array_keys($dailySupplierRefundsArr),
                array_keys($dailyFinInArr),
                array_keys($dailyPurchArr),
                array_keys($dailyExpArr),
                array_keys($dailyRefundsArr),
                array_keys($dailyFinOutArr)
            ));
            sort($allDates);

            $runningBalance = $openingBalance;
            foreach ($allDates as $d) {
                $in = (float)($dailyInflowsArr[$d] ?? 0.0) + (float)($dailySupplierRefundsArr[$d] ?? 0.0) + (float)($dailyFinInArr[$d] ?? 0.0);
                $out = (float)($dailyPurchArr[$d] ?? 0.0) + (float)($dailyExpArr[$d] ?? 0.0) + (float)($dailyRefundsArr[$d] ?? 0.0) + (float)($dailyFinOutArr[$d] ?? 0.0);
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

            // Collect filter options so dropdowns stay complete regardless of active filters
            $whList = $accounting->schemaHasTable('outlets')
                ? DB::table('outlets')
                    ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('id', $allowedOutlets))
                    ->orderBy('name')
                    ->pluck('name')
                    ->filter(fn ($v) => trim((string)$v) !== '')
                    ->values()
                    ->all()
                : [];
            $pmSet = ['cash' => 'Cash'];
            foreach (array_keys($tenderTotalsMap) as $tp) {
                $clean = trim((string)$tp);
                if ($clean !== '') $pmSet[strtolower($clean)] = $clean;
            }
            if ($hasSpTable) {
                foreach (DB::table('sale_payments')->distinct()->limit(30)->pluck('payment_type') as $spPm) {
                    $clean = trim((string)$spPm);
                    if ($clean !== '' && !$accounting->isNonCashSettlementType($clean)) {
                        $pmSet[strtolower($clean)] = $pmSet[strtolower($clean)] ?? $clean;
                    }
                }
            }
            if ($hasEpTable) {
                foreach (DB::table('expense_payments')->distinct()->limit(30)->pluck('payment_type') as $epPm) {
                    $clean = trim((string)$epPm);
                    if ($clean !== '' && !$accounting->isNonCashSettlementType($clean)) {
                        $pmSet[strtolower($clean)] = $pmSet[strtolower($clean)] ?? $clean;
                    }
                }
            }
            $userSet = [];
            foreach (DB::table('sales')->distinct()->limit(50)->pluck('created_by') as $uName) {
                $clean = trim((string)$uName);
                if ($clean !== '') $userSet[strtolower($clean)] = $clean;
            }
            if ($accounting->schemaHasTable('expenses')) {
                foreach (DB::table('expenses')->distinct()->limit(50)->pluck('created_by') as $uName) {
                    $clean = trim((string)$uName);
                    if ($clean !== '') $userSet[strtolower($clean)] = $userSet[strtolower($clean)] ?? $clean;
                }
            }
            if ($accounting->schemaHasTable('users')) {
                foreach (DB::table('users')->where('status', 'active')->orderBy('name')->limit(50)->pluck('name') as $uName) {
                    $clean = trim((string)$uName);
                    if ($clean !== '') $userSet[strtolower($clean)] = $userSet[strtolower($clean)] ?? $clean;
                }
            }
            $availableFilters = [
                'warehouses' => array_values(array_unique($whList)),
                'payment_methods' => array_values($pmSet),
                'users' => array_values($userSet),
                'activities' => ['Operating Inflow', 'Operating Outflow', 'Purchases', 'Expenses', 'Refunds', 'Financing'],
            ];
        }

        // 5. SQL-Paginated Journal Slice merged cleanly with payload tuples
        $sqlCount = 0;
        if ($skipSummary && $knownTotal !== null) {
            $sqlCount = max(0, $knownTotal - count($payloadTxnIndex));
        } elseif (!$skipSummary) {
            $sqlCount = $precomputedSqlCount;
        } else {
            foreach ($sqlBranches as $b) {
                $sqlCount += (int)(clone $b['q'])->count();
            }
        }
        $totalRecords = ($skipSummary && $knownTotal !== null)
            ? $knownTotal
            : ($sqlCount + count($payloadTxnIndex));
        $lastPage = max(1, (int)ceil($totalRecords / max(1, $perPage)));

        // Sort compact payload tuples newest-first: [0]=d, [1]=id, [2]=src, [3]=in, [4]=out, [5]=legIdx
        usort($payloadTxnIndex, static function ($a, $b) {
            $dComp = strcmp((string)$b[0], (string)$a[0]);
            return $dComp !== 0 ? $dComp : ((int)$b[1] <=> (int)$a[1]);
        });
        $topPayload = array_slice($payloadTxnIndex, 0, $needTopN);
        unset($payloadTxnIndex);

        $topSqlRows = [];
        foreach ($sqlBranches as $b) {
            $qOrdered = (clone $b['q'])->orderByDesc($b['dateCol']);
            if (!empty($b['idCol'])) {
                $qOrdered->orderByDesc($b['idCol']);
            }
            $branchLimit = $needTopN + ($b['idCol'] === null ? 16 : 0);
            foreach ($qOrdered->limit($branchLimit)->get() as $sr) {
                $topSqlRows[] = $sr;
            }
        }
        usort($topSqlRows, static function ($a, $b) {
            $dComp = strcmp((string)$b->date, (string)$a->date);
            return $dComp !== 0 ? $dComp : ((int)$b->id <=> (int)$a->id);
        });
        $topSqlRows = array_slice($topSqlRows, 0, $needTopN);

        // K=2 descending merge of top $needTopN sorted arrays
        $mergedTop = [];
        $si = 0;
        $pi = 0;
        $sLen = count($topSqlRows);
        $pLen = count($topPayload);
        while (count($mergedTop) < $needTopN && ($si < $sLen || $pi < $pLen)) {
            $takeSql = false;
            if ($si < $sLen && $pi < $pLen) {
                $cmp = strcmp((string)$topSqlRows[$si]->date, (string)$topPayload[$pi][0]);
                if ($cmp > 0) {
                    $takeSql = true;
                } elseif ($cmp === 0 && (int)$topSqlRows[$si]->id >= (int)$topPayload[$pi][1]) {
                    $takeSql = true;
                }
            } elseif ($si < $sLen) {
                $takeSql = true;
            }
            if ($takeSql) {
                $mergedTop[] = ['kind' => 'sql', 'row' => $topSqlRows[$si++]];
            } else {
                $mergedTop[] = ['kind' => 'pay', 'row' => $topPayload[$pi++]];
            }
        }

        $hasMore = count($mergedTop) > ($offset + $perPage) || ($offset + $perPage) < $totalRecords;
        $pageEntries = array_slice($mergedTop, $offset, $perPage);

        // Compute exact running_balance working backwards from closingBalance using the preceding $offset newer entries
        $runningTxnBal = $closingBalance;
        for ($k = 0; $k < $offset && $k < count($mergedTop); $k++) {
            $e = $mergedTop[$k];
            if ($e['kind'] === 'sql') {
                $runningTxnBal -= ((float)$e['row']->amount_in - (float)$e['row']->amount_out);
            } else {
                $runningTxnBal -= ((float)$e['row'][3] - (float)$e['row'][4]);
            }
        }

        // Hydrate full display metadata strictly for <= 50 payload rows landing on this page
        $needPoIds = [];
        $needPrIds = [];
        $needSrIds = [];
        foreach ($pageEntries as $e) {
            if ($e['kind'] !== 'pay') continue;
            $r = $e['row'];
            if ($r[2] === 'purchase') $needPoIds[$r[1]] = true;
            elseif ($r[2] === 'purchase_return') $needPrIds[$r[1]] = true;
            else $needSrIds[$r[1]] = true;
        }
        $poMeta = !empty($needPoIds)
            ? DB::table('purchases')->whereIn('id', array_keys($needPoIds))->get(['id', 'reference_no', 'supplier_name', 'warehouse_name', 'payment_type', 'payments_payload', 'created_by', 'user_name', 'user_role', 'status', 'notes'])->keyBy('id')
            : collect();
        $prMeta = !empty($needPrIds)
            ? DB::table('purchase_returns')->whereIn('id', array_keys($needPrIds))->get(['id', 'reference_no', 'supplier_name', 'warehouse_name', 'payment_type', 'payments_payload', 'created_by', 'user_name', 'user_role', 'status', 'notes'])->keyBy('id')
            : collect();
        $srMeta = !empty($needSrIds)
            ? DB::table('sale_returns')->whereIn('id', array_keys($needSrIds))->get(['id', 'reference_no', 'customer_name', 'warehouse_name', 'payment_type', 'refund_tender', 'payments_payload', 'created_by', 'user_name', 'user_role', 'status', 'notes'])->keyBy('id')
            : collect();

        $pagedTransactions = [];
        foreach ($pageEntries as $e) {
            if ($e['kind'] === 'sql') {
                $t = $e['row'];
                $in = (float)($t->amount_in ?? 0);
                $out = (float)($t->amount_out ?? 0);
                $balAfter = $runningTxnBal;
                $runningTxnBal -= ($in - $out);
                $pagedTransactions[] = [
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
                    'running_balance' => round($balAfter, 2),
                    'created_by' => (string)($t->created_by ?: 'Super Admin'),
                    'user_role' => (string)($t->user_role ?: 'Super Admin'),
                    'status' => (string)($t->status ?: 'Completed'),
                    'notes' => (string)($t->notes ?: ''),
                ];
            } else {
                $r = $e['row'];
                $docDate = (string)$r[0];
                $docId = (int)$r[1];
                $srcType = (string)$r[2];
                $in = (float)$r[3];
                $out = (float)$r[4];
                $legIdx = (int)$r[5];
                $balAfter = $runningTxnBal;
                $runningTxnBal -= ($in - $out);
                $defaultTender = 'Cash';
                if ($srcType === 'purchase') {
                    $m = $poMeta->get($docId);
                    $typeLabel = 'Operating Outflow (Purchase)';
                    $party = $m ? ($m->supplier_name ?: 'Supplier') : 'Supplier';
                    $defStatus = 'Received';
                    $defaultTender = (string)($m->payment_type ?? '');
                } elseif ($srcType === 'purchase_return') {
                    $m = $prMeta->get($docId);
                    $typeLabel = 'Operating Inflow (Supplier Refund)';
                    $party = $m ? ($m->supplier_name ?: 'Supplier') : 'Supplier';
                    $defStatus = 'Completed';
                    $defaultTender = (string)($m->payment_type ?? '');
                } else {
                    $m = $srMeta->get($docId);
                    $typeLabel = 'Operating Outflow (Refund)';
                    $party = $m ? ($m->customer_name ?: 'Walk-in Customer') : 'Walk-in Customer';
                    $defStatus = 'Completed';
                    $defaultTender = (string)(($m->refund_tender ?? '') ?: ($m->payment_type ?? ''));
                }
                $leg = null;
                if ($legIdx >= 0 && $m && !empty($m->payments_payload)) {
                    $decodedLegs = json_decode((string)$m->payments_payload, true);
                    if (is_array($decodedLegs) && isset($decodedLegs[$legIdx]) && is_array($decodedLegs[$legIdx])) {
                        $leg = $decodedLegs[$legIdx];
                    }
                }
                $pmVal = (string)(($leg['payment_type'] ?? '') ?: $defaultTender ?: 'Cash');
                $refVal = (string)(($leg['reference_no'] ?? '') ?: ($m->reference_no ?? ''));
                $byVal = (string)(($leg['user_name'] ?? '') ?: (($m->created_by ?? null) ?: (($m->user_name ?? null) ?: 'Super Admin')));
                $roleVal = (string)(($leg['user_role'] ?? '') ?: (($m->user_role ?? null) ?: 'Super Admin'));
                $notesVal = (string)(($leg['notes'] ?? '') ?: ($m->notes ?? ''));

                $pagedTransactions[] = [
                    'id' => $docId,
                    'reference_no' => $refVal,
                    'date' => $docDate,
                    'type' => $srcType,
                    'type_label' => $typeLabel,
                    'activity' => 'Operating',
                    'party_name' => (string)$party,
                    'warehouse_name' => (string)(($m->warehouse_name ?? '') ?: 'Main Branch Outlet'),
                    'payment_method' => $pmVal !== '' ? $pmVal : 'Cash',
                    'amount_in' => round($in, 2),
                    'amount_out' => round($out, 2),
                    'running_balance' => round($balAfter, 2),
                    'created_by' => $byVal,
                    'user_role' => $roleVal,
                    'status' => (string)(($m->status ?? null) ?: $defStatus),
                    'notes' => $notesVal,
                ];
            }
        }

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

        $payload = [
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'summary' => [
                'cost_masked' => !$canViewCost,
                'opening_balance' => $canViewCost ? round($openingBalance, 2) : 0.0,
                'total_inflow' => $canViewCost ? round($totalInflows, 2) : 0.0,
                'total_outflow' => $canViewCost ? round($totalOutflows, 2) : 0.0,
                'net_cash_flow' => $canViewCost ? round($netCashFlow, 2) : 0.0,
                'financing_inflows' => round($financingInflows, 2),
                'financing_outflows' => round($financingOutflows, 2),
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
                'activity' => $activityFilter,
                'payment_method' => $paymentFilter,
                'user_name' => $userFilter,
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
        ];
        if ($availableFilters !== null) {
            $payload['available_filters'] = $availableFilters;
        }
        return $this->noStoreJson($payload);
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
        $movementTypeRaw = trim((string)$request->input('movement_type', 'All'));
        $movementTypeLower = strtolower($movementTypeRaw);
        $movementType = ($movementTypeLower === '' || $movementTypeLower === 'all') ? 'All' : $movementTypeLower;
        $category = $request->input('category', 'All');
        $search = trim((string)$request->input('search', ''));
        $section = strtolower(trim((string)$request->input('section', 'all')));
        if (!in_array($section, ['all', 'journal', 'bin_card'], true)) {
            $section = 'all';
        }
        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $knownTotal = $request->has('known_total') ? max(0, (int)$request->query('known_total')) : null;
        $binPage = max(1, (int)($request->input('bin_page') ?? $request->query('bin_page', $page)));

        $accounting = $this->accounting();
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);
        $warehouseId = $this->resolveWarehouseIdParam(is_scalar($warehouse) ? (string)$warehouse : null);
        if (!empty($allowedOutlets) && $warehouse !== 'All' && $warehouse !== '') {
            if ($warehouseId === null || !in_array($warehouseId, $allowedOutlets, true)) {
                return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
            }
        }

        $fromDateTime = $accounting->normalizeFilterBoundaryDate((string)$startDate, false);
        $toDateTime = $accounting->normalizeFilterBoundaryDate((string)$endDate, true);

        $effAdjTypeSql = $this->effectiveAdjustmentTypeSql();
        $saleBaseQtySql = 'COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity * COALESCE(NULLIF(sale_items.conversion_rate, 0), 1))';
        $purchBaseQtySql = 'COALESCE(NULLIF(purchase_items.base_quantity, 0), purchase_items.quantity * COALESCE(NULLIF(purchase_items.conversion_rate, 0), 1))';
        $adjBaseQtySql = 'COALESCE(NULLIF(stock_adjustment_items.base_quantity, 0), stock_adjustment_items.quantity * COALESCE(NULLIF(stock_adjustment_items.conversion_rate, 0), 1))';
        $trfBaseQtySql = 'COALESCE(NULLIF(stock_transfer_items.base_quantity, 0), stock_transfer_items.quantity * COALESCE(NULLIF(stock_transfer_items.conversion_rate, 0), 1))';

        // Prune UNION branches upfront based on normalized movement_type filter
        $wantAll = ($movementType === 'All');
        $wantInOnly = ($movementType === 'in');
        $wantOutOnly = ($movementType === 'out');
        $wantSale = $wantAll || $wantOutOnly || in_array($movementType, ['sale', 'sales'], true);
        $wantPurchase = $wantAll || $wantInOnly || in_array($movementType, ['purchase', 'purchases', 'opening_stock'], true);
        $wantAdjust = $wantAll || $wantInOnly || $wantOutOnly || in_array($movementType, ['adjustment', 'adjustments', 'adjustment_in', 'adjustment_out'], true);
        $wantTransferOut = $wantAll || $wantOutOnly || in_array($movementType, ['transfer', 'transfers', 'transfer_out'], true);
        $wantTransferIn = $wantAll || $wantInOnly || in_array($movementType, ['transfer', 'transfers', 'transfer_in'], true);
        $wantSaleReturn = $wantAll || $wantInOnly || $wantOutOnly || in_array($movementType, ['return', 'returns', 'sale_return', 'sale_returns', 'sale_return_void'], true);
        $wantPurchReturn = $wantAll || $wantInOnly || $wantOutOnly || in_array($movementType, ['return', 'returns', 'purchase_return', 'purchase_returns', 'purchase_return_void'], true);
        $recognizedType = $wantSale || $wantPurchase || $wantAdjust || $wantTransferOut || $wantTransferIn || $wantSaleReturn || $wantPurchReturn;
        if (!$recognizedType) {
            $wantSale = $wantPurchase = $wantAdjust = $wantTransferOut = $wantTransferIn = $wantSaleReturn = $wantPurchReturn = true;
        }

        $hasCategory = !empty($category) && $category !== 'All';
        $hasSearch = !empty($search);

        $applyWhScope = static function ($q, string $whNameCol, string $whIdCol) use ($warehouse, $warehouseId, $allowedOutlets): void {
            if (!empty($allowedOutlets)) {
                $q->whereIn($whIdCol, $allowedOutlets);
            }
            if ($warehouse !== 'All' && !empty($warehouse)) {
                if ($warehouseId !== null) {
                    $q->where($whIdCol, $warehouseId);
                } else {
                    $q->where($whNameCol, $warehouse);
                }
            }
        };

        $applyBranchCatSearch = static function (
            $q,
            string $prodNameCol,
            string $prodCodeCol,
            string $refCol,
            string $whNameCol,
            ?string $createdByCol,
            ?string $userNameCol
        ) use ($hasCategory, $category, $hasSearch, $search): void {
            if ($hasCategory) {
                $q->where('products.category_name', $category);
            }
            if ($hasSearch) {
                $like = "%{$search}%";
                $q->where(function ($sub) use ($like, $prodNameCol, $prodCodeCol, $refCol, $whNameCol, $createdByCol, $userNameCol) {
                    $sub->where($refCol, 'like', $like)
                        ->orWhere($prodNameCol, 'like', $like)
                        ->orWhere($prodCodeCol, 'like', $like)
                        ->orWhere('products.alternative_name', 'like', $like)
                        ->orWhere($whNameCol, 'like', $like);
                    if ($createdByCol !== null) {
                        $sub->orWhere($createdByCol, 'like', $like);
                    }
                    if ($userNameCol !== null) {
                        $sub->orWhere($userNameCol, 'like', $like);
                    }
                });
            }
        };

        $totalMovements = 0;
        $totalInflowQty = 0.0;
        $totalOutflowQty = 0.0;
        $totalMovementValue = 0.0;
        $offset = ($page - 1) * $perPage;
        $hasMoreMovements = false;
        $allCandidates = [];
        $pageMvRows = collect();

        if ($section !== 'bin_card') {
            $smTypesPresent = [];
            if (($wantSaleReturn || $wantPurchReturn || $wantPurchase) && $accounting->schemaHasTable('stock_movements')) {
                foreach (DB::table('stock_movements')->distinct()->pluck('movement_type') as $mt) {
                    $smTypesPresent[(string)$mt] = true;
                }
            }
            $hasSmSaleRet = ($wantAll || $wantInOnly || !in_array($movementType, ['sale_return_void'], true)) && isset($smTypesPresent['sale_return']);
            $hasSmSaleRetVoid = ($wantAll || $wantOutOnly || in_array($movementType, ['return', 'returns', 'sale_return', 'sale_returns', 'sale_return_void'], true))
                && (isset($smTypesPresent['sale_return_void']) || isset($smTypesPresent['sale_return_edit_reverse']));
            $hasSmPurchRet = ($wantAll || $wantOutOnly || !in_array($movementType, ['purchase_return_void'], true)) && isset($smTypesPresent['purchase_return']);
            $hasSmPurchRetVoid = ($wantAll || $wantInOnly || in_array($movementType, ['return', 'returns', 'purchase_return', 'purchase_returns', 'purchase_return_void'], true))
                && isset($smTypesPresent['purchase_return_void']);

            $isSqlite = DB::connection()->getDriverName() === 'sqlite';
            $refIntCast = $isSqlite ? 'CAST(stock_movements.reference_id AS INTEGER)' : 'CAST(stock_movements.reference_id AS UNSIGNED)';
            $prRefFallback = $isSqlite ? "('PR-' || stock_movements.reference_id)" : "CONCAT('PR-', stock_movements.reference_id)";
            $srRefFallback = $isSqlite ? "('SR-' || stock_movements.reference_id)" : "CONCAT('SR-', stock_movements.reference_id)";
            $hasMovementUnitCost = $accounting->schemaHasColumn('stock_movements', 'unit_cost');
            $smUnitCostPrefix = $hasMovementUnitCost ? 'NULLIF(stock_movements.unit_cost, 0), ' : '';
            $hasAdjVariantCol = $accounting->schemaHasColumn('stock_adjustment_items', 'product_variant_id');
            $hasTrfVariantCol = $accounting->schemaHasColumn('stock_transfer_items', 'product_variant_id');
            $adjVariantExpr = $hasAdjVariantCol ? 'stock_adjustment_items.product_variant_id as variant_id' : 'NULL as variant_id';
            $trfVariantExpr = $hasTrfVariantCol ? 'stock_transfer_items.product_variant_id as variant_id' : 'NULL as variant_id';

            $branches = [];

            if ($wantSale) {
                $saleUnitCostExpr = "CASE WHEN {$saleBaseQtySql} > 0 AND COALESCE(sale_items.total_cogs, 0) > 0 THEN sale_items.total_cogs / {$saleBaseQtySql} ELSE COALESCE(NULLIF(product_variants.cost_price, 0), products.cost_price, products.purchase_price, 0) END";
                $buildSalesQ = function (bool $forSummary) use (
                    $fromDateTime, $toDateTime, $applyWhScope, $applyBranchCatSearch,
                    $saleBaseQtySql, $saleUnitCostExpr, $canViewCost, $hasCategory, $hasSearch
                ) {
                    $q = DB::table('sale_items')
                        ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                        ->where('sales.date', '>=', $fromDateTime)
                        ->where('sales.date', '<=', $toDateTime);
                    $this->applyActiveDocumentFilter($q, 'sales.status');
                    $applyWhScope($q, 'sales.warehouse_name', 'sales.warehouse_id');
                    if (!$forSummary || $hasCategory || $hasSearch || $canViewCost) {
                        $q->leftJoin('products', 'sale_items.product_id', '=', 'products.id');
                    }
                    if (!$forSummary || $canViewCost) {
                        $q->leftJoin('product_variants', 'sale_items.product_variant_id', '=', 'product_variants.id');
                    }
                    $applyBranchCatSearch($q, 'sale_items.product_name', 'sale_items.product_code', 'sales.reference_no', 'sales.warehouse_name', 'sales.created_by', 'sales.user_name');
                    return $q;
                };

                $branches[] = [
                    'sumQuery' => $buildSalesQ(true),
                    'qtyInExpr' => '0',
                    'qtyOutExpr' => $saleBaseQtySql,
                    'unitCostExpr' => $saleUnitCostExpr,
                    'rowQuery' => $buildSalesQ(false)->select([
                        'sales.id as source_id',
                        DB::raw('sale_items.id as item_row_id'),
                        DB::raw('sale_items.product_variant_id as variant_id'),
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
                        DB::raw("{$saleUnitCostExpr} as unit_cost"),
                        'sales.created_by',
                        'sales.user_name',
                        'sales.user_role',
                        'sales.date as date',
                    ]),
                    'dateCol' => 'sales.date',
                    'statusSplit' => null,
                    'statusCol' => null,
                ];
            }

            if ($wantPurchase) {
                $purchBatchCostSub = $accounting->schemaHasTable('inventory_batches')
                    ? "NULLIF((SELECT CASE WHEN SUM(ib.initial_quantity) > 0 THEN SUM(ib.initial_quantity * ib.unit_cost) / SUM(ib.initial_quantity) ELSE MAX(ib.unit_cost) END FROM inventory_batches ib WHERE ib.purchase_item_id = purchase_items.id), 0), "
                    : '';
                $purchUnitCostExpr = "COALESCE("
                    . $purchBatchCostSub
                    . "NULLIF(purchase_items.base_unit_cost, 0), "
                    . "CASE WHEN COALESCE(purchase_items.conversion_rate, 1) > 0 THEN purchase_items.unit_cost / purchase_items.conversion_rate ELSE purchase_items.unit_cost END, "
                    . "products.cost_price, 0)";
                $buildPurchQ = function (bool $forSummary, ?string $exactStatus = null) use (
                    $fromDateTime, $toDateTime, $applyWhScope, $applyBranchCatSearch,
                    $movementType, $canViewCost, $hasCategory, $hasSearch
                ) {
                    $q = DB::table('purchase_items')
                        ->join('purchases', 'purchase_items.purchase_id', '=', 'purchases.id')
                        ->where('purchases.date', '>=', $fromDateTime)
                        ->where('purchases.date', '<=', $toDateTime);
                    if ($exactStatus !== null) {
                        $q->where('purchases.status', $exactStatus);
                    } else {
                        $q->whereIn('purchases.status', ['Received', 'Completed']);
                    }
                    if ($movementType === 'opening_stock') {
                        $q->whereRaw("COALESCE(LOWER(TRIM(purchases.payment_type)), '') = 'opening stock'");
                    }
                    $applyWhScope($q, 'purchases.warehouse_name', 'purchases.warehouse_id');
                    if (!$forSummary || $hasCategory || $hasSearch || $canViewCost) {
                        $q->leftJoin('products', 'purchase_items.product_id', '=', 'products.id');
                    }
                    $applyBranchCatSearch($q, 'purchase_items.product_name', 'purchase_items.product_code', 'purchases.reference_no', 'purchases.warehouse_name', 'purchases.created_by', 'purchases.user_name');
                    return $q;
                };

                $purchSelect = [
                    'purchases.id as source_id',
                    DB::raw('purchase_items.id as item_row_id'),
                    DB::raw('purchase_items.product_variant_id as variant_id'),
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
                    DB::raw("{$purchUnitCostExpr} as unit_cost"),
                    'purchases.created_by',
                    'purchases.user_name',
                    'purchases.user_role',
                    'purchases.date as date',
                ];

                $branches[] = [
                    'sumQuery' => $buildPurchQ(true),
                    'qtyInExpr' => $purchBaseQtySql,
                    'qtyOutExpr' => '0',
                    'unitCostExpr' => $purchUnitCostExpr,
                    'rowQuery' => $buildPurchQ(false)->select($purchSelect),
                    'rowBuilder' => (!$hasCategory && !$hasSearch)
                        ? static fn (string $st) => $buildPurchQ(false, $st)->select($purchSelect)
                        : null,
                    'dateCol' => 'purchases.date',
                    'statusSplit' => (!$hasCategory && !$hasSearch) ? ['Received', 'Completed'] : null,
                    'statusCol' => 'purchases.status',
                ];

                // Variant-addition opening stock movements (reference_type = 'product_update') lack a Purchase header
                if ($accounting->schemaHasTable('stock_movements') && isset($smTypesPresent['opening_stock'])) {
                    $opRefExpr = $isSqlite ? "('OPEN-' || stock_movements.id)" : "CONCAT('OPEN-', stock_movements.id)";
                    $opUnitCostExpr = "COALESCE({$smUnitCostPrefix}NULLIF(product_variants.cost_price, 0), products.cost_price, products.purchase_price, 0)";
                    $opUpdQ = DB::table('stock_movements')
                        ->leftJoin('products', 'stock_movements.product_id', '=', 'products.id')
                        ->leftJoin('product_variants', 'stock_movements.product_variant_id', '=', 'product_variants.id')
                        ->leftJoin('outlets', 'stock_movements.warehouse_id', '=', 'outlets.id')
                        ->where('stock_movements.movement_type', 'opening_stock')
                        ->where('stock_movements.reference_type', 'product_update')
                        ->where('stock_movements.created_at', '>=', $fromDateTime)
                        ->where('stock_movements.created_at', '<=', $toDateTime);
                    if (!empty($allowedOutlets)) {
                        $opUpdQ->whereIn('stock_movements.warehouse_id', $allowedOutlets);
                    }
                    if ($warehouse !== 'All' && !empty($warehouse)) {
                        if ($warehouseId !== null) {
                            $opUpdQ->where('stock_movements.warehouse_id', $warehouseId);
                        } else {
                            $opUpdQ->where('outlets.name', $warehouse);
                        }
                    }
                    if ($hasCategory) {
                        $opUpdQ->where('products.category_name', $category);
                    }
                    if ($hasSearch) {
                        $like = "%{$search}%";
                        $opUpdQ->where(function ($sub) use ($like, $opRefExpr) {
                            $sub->whereRaw("{$opRefExpr} like ?", [$like])
                                ->orWhere('products.name', 'like', $like)
                                ->orWhere('products.code', 'like', $like)
                                ->orWhere('products.alternative_name', 'like', $like)
                                ->orWhereRaw("COALESCE(outlets.name, '') like ?", [$like]);
                        });
                    }
                    $branches[] = [
                        'sumQuery' => clone $opUpdQ,
                        'qtyInExpr' => 'stock_movements.quantity_in',
                        'qtyOutExpr' => '0',
                        'unitCostExpr' => $opUnitCostExpr,
                        'rowQuery' => (clone $opUpdQ)->select([
                            'stock_movements.id as source_id',
                            DB::raw('0 as item_row_id'),
                            'stock_movements.product_variant_id as variant_id',
                            DB::raw("{$opRefExpr} as reference_no"),
                            DB::raw("'opening_stock' as movement_type"),
                            DB::raw("'Opening Stock In' as type_label"),
                            'stock_movements.product_id',
                            'products.name as product_name',
                            'products.code as product_code',
                            DB::raw("COALESCE(products.alternative_name, '') as alternative_name"),
                            'products.category_name',
                            DB::raw("COALESCE(outlets.name, 'Main Outlet') as warehouse_name"),
                            DB::raw('COALESCE(stock_movements.warehouse_id, 1) as warehouse_id'),
                            'stock_movements.quantity_in as quantity_in',
                            DB::raw('0 as quantity_out'),
                            DB::raw('COALESCE(stock_movements.balance_after, products.stock_quantity, 0) as balance_qty'),
                            DB::raw("{$opUnitCostExpr} as unit_cost"),
                            DB::raw("'System' as created_by"),
                            DB::raw("'System' as user_name"),
                            DB::raw("'' as user_role"),
                            'stock_movements.created_at as date',
                        ]),
                        'dateCol' => 'stock_movements.created_at',
                        'statusSplit' => null,
                        'statusCol' => null,
                    ];
                }
            }

            if ($wantAdjust) {
                $adjUnitCostExpr = 'COALESCE(NULLIF(stock_adjustment_items.base_unit_cost, 0), CASE WHEN COALESCE(stock_adjustment_items.conversion_rate, 1) > 0 THEN stock_adjustment_items.unit_cost / stock_adjustment_items.conversion_rate ELSE stock_adjustment_items.unit_cost END, products.cost_price, 0)';
                $adjInFilter = ($wantInOnly || $movementType === 'adjustment_in');
                $adjOutFilter = ($wantOutOnly || $movementType === 'adjustment_out');
                $adjQtyInExpr = "CASE WHEN {$effAdjTypeSql} = 'addition' THEN {$adjBaseQtySql} ELSE 0 END";
                $adjQtyOutExpr = "CASE WHEN {$effAdjTypeSql} = 'addition' THEN 0 ELSE {$adjBaseQtySql} END";

                $buildAdjQ = function (bool $forSummary) use (
                    $fromDateTime, $toDateTime, $applyWhScope, $applyBranchCatSearch,
                    $effAdjTypeSql, $adjInFilter, $adjOutFilter, $canViewCost, $hasCategory, $hasSearch
                ) {
                    $q = DB::table('stock_adjustment_items')
                        ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id')
                        ->where('stock_adjustments.date', '>=', $fromDateTime)
                        ->where('stock_adjustments.date', '<=', $toDateTime);
                    if ($adjInFilter && !$adjOutFilter) {
                        $q->whereRaw("{$effAdjTypeSql} = 'addition'");
                    } elseif ($adjOutFilter && !$adjInFilter) {
                        $q->whereRaw("{$effAdjTypeSql} <> 'addition'");
                    }
                    $applyWhScope($q, 'stock_adjustments.warehouse_name', 'stock_adjustments.warehouse_id');
                    if (!$forSummary || $hasCategory || $hasSearch || $canViewCost) {
                        $q->leftJoin('products', 'stock_adjustment_items.product_id', '=', 'products.id');
                    }
                    $applyBranchCatSearch($q, 'stock_adjustment_items.product_name', 'stock_adjustment_items.product_code', 'stock_adjustments.reference_no', 'stock_adjustments.warehouse_name', 'stock_adjustments.created_by', 'stock_adjustments.user_name');
                    return $q;
                };

                $branches[] = [
                    'sumQuery' => $buildAdjQ(true),
                    'qtyInExpr' => $adjQtyInExpr,
                    'qtyOutExpr' => $adjQtyOutExpr,
                    'unitCostExpr' => $adjUnitCostExpr,
                    'rowQuery' => $buildAdjQ(false)->select([
                        'stock_adjustments.id as source_id',
                        DB::raw('0 as item_row_id'),
                        DB::raw($adjVariantExpr),
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
                        DB::raw("{$adjQtyInExpr} as quantity_in"),
                        DB::raw("{$adjQtyOutExpr} as quantity_out"),
                        DB::raw('COALESCE(products.stock_quantity, 0) as balance_qty'),
                        DB::raw("{$adjUnitCostExpr} as unit_cost"),
                        'stock_adjustments.created_by',
                        'stock_adjustments.user_name',
                        'stock_adjustments.user_role',
                        'stock_adjustments.date as date',
                    ]),
                    'dateCol' => 'stock_adjustments.date',
                    'statusSplit' => null,
                    'statusCol' => null,
                ];
            }

            if ($wantTransferOut) {
                $trfUnitCostExpr = 'COALESCE(NULLIF(stock_transfer_items.base_unit_cost, 0), stock_transfer_items.unit_cost, products.cost_price, 0)';
                $buildTrfOutQ = function (bool $forSummary, ?string $exactStatus = null) use (
                    $fromDateTime, $toDateTime, $applyWhScope, $applyBranchCatSearch,
                    $canViewCost, $hasCategory, $hasSearch
                ) {
                    $q = DB::table('stock_transfer_items')
                        ->join('stock_transfers', 'stock_transfer_items.stock_transfer_id', '=', 'stock_transfers.id')
                        ->where('stock_transfers.date', '>=', $fromDateTime)
                        ->where('stock_transfers.date', '<=', $toDateTime);
                    if ($exactStatus !== null) {
                        $q->where('stock_transfers.status', $exactStatus);
                    } else {
                        $q->whereIn('stock_transfers.status', ['Sent', 'Completed']);
                    }
                    $applyWhScope($q, 'stock_transfers.from_warehouse', 'stock_transfers.from_warehouse_id');
                    if (!$forSummary || $hasCategory || $hasSearch || $canViewCost) {
                        $q->leftJoin('products', 'stock_transfer_items.product_id', '=', 'products.id');
                    }
                    $applyBranchCatSearch($q, 'stock_transfer_items.product_name', 'stock_transfer_items.product_code', 'stock_transfers.reference_no', 'stock_transfers.from_warehouse', 'stock_transfers.created_by', 'stock_transfers.user_name');
                    return $q;
                };

                $trfOutSelect = [
                    'stock_transfers.id as source_id',
                    DB::raw('0 as item_row_id'),
                    DB::raw($trfVariantExpr),
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
                    DB::raw("{$trfUnitCostExpr} as unit_cost"),
                    'stock_transfers.created_by',
                    'stock_transfers.user_name',
                    DB::raw("'' as user_role"),
                    'stock_transfers.date as date',
                ];

                $branches[] = [
                    'sumQuery' => $buildTrfOutQ(true),
                    'qtyInExpr' => '0',
                    'qtyOutExpr' => $trfBaseQtySql,
                    'unitCostExpr' => $trfUnitCostExpr,
                    'rowQuery' => $buildTrfOutQ(false)->select($trfOutSelect),
                    'rowBuilder' => (!$hasCategory && !$hasSearch)
                        ? static fn (string $st) => $buildTrfOutQ(false, $st)->select($trfOutSelect)
                        : null,
                    'dateCol' => 'stock_transfers.date',
                    'statusSplit' => (!$hasCategory && !$hasSearch) ? ['Sent', 'Completed'] : null,
                    'statusCol' => 'stock_transfers.status',
                ];
            }

            if ($wantTransferIn) {
                $trfUnitCostExpr = 'COALESCE(NULLIF(stock_transfer_items.base_unit_cost, 0), stock_transfer_items.unit_cost, products.cost_price, 0)';
                $buildTrfInQ = function (bool $forSummary) use (
                    $fromDateTime, $toDateTime, $applyWhScope, $applyBranchCatSearch,
                    $canViewCost, $hasCategory, $hasSearch
                ) {
                    $q = DB::table('stock_transfer_items')
                        ->join('stock_transfers', 'stock_transfer_items.stock_transfer_id', '=', 'stock_transfers.id')
                        ->where('stock_transfers.status', 'Completed')
                        ->where('stock_transfers.date', '>=', $fromDateTime)
                        ->where('stock_transfers.date', '<=', $toDateTime);
                    $applyWhScope($q, 'stock_transfers.to_warehouse', 'stock_transfers.to_warehouse_id');
                    if (!$forSummary || $hasCategory || $hasSearch || $canViewCost) {
                        $q->leftJoin('products', 'stock_transfer_items.product_id', '=', 'products.id');
                    }
                    $applyBranchCatSearch($q, 'stock_transfer_items.product_name', 'stock_transfer_items.product_code', 'stock_transfers.reference_no', 'stock_transfers.to_warehouse', 'stock_transfers.created_by', 'stock_transfers.user_name');
                    return $q;
                };

                $branches[] = [
                    'sumQuery' => $buildTrfInQ(true),
                    'qtyInExpr' => $trfBaseQtySql,
                    'qtyOutExpr' => '0',
                    'unitCostExpr' => $trfUnitCostExpr,
                    'rowQuery' => $buildTrfInQ(false)->select([
                        'stock_transfers.id as source_id',
                        DB::raw('0 as item_row_id'),
                        DB::raw($trfVariantExpr),
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
                        DB::raw("{$trfUnitCostExpr} as unit_cost"),
                        'stock_transfers.created_by',
                        'stock_transfers.user_name',
                        DB::raw("'' as user_role"),
                        'stock_transfers.date as date',
                    ]),
                    'dateCol' => 'stock_transfers.date',
                    'statusSplit' => null,
                    'statusCol' => null,
                ];
            }

            if ($wantSaleReturn && ($hasSmSaleRet || $hasSmSaleRetVoid)) {
                $srHistUnitCostExpr = "COALESCE({$smUnitCostPrefix}"
                    . "NULLIF((SELECT SUM(srcl.total_cost) / NULLIF(SUM(srcl.base_quantity), 0) FROM sale_return_cost_layers srcl WHERE srcl.sale_return_id = sale_returns.id AND srcl.product_id = stock_movements.product_id AND (stock_movements.product_variant_id IS NULL OR srcl.product_variant_id = stock_movements.product_variant_id)), 0), "
                    . "NULLIF((SELECT SUM(srcl2.total_cost) / NULLIF(SUM(srcl2.base_quantity), 0) FROM sale_return_cost_layers srcl2 WHERE srcl2.sale_return_id = sale_returns.id AND srcl2.product_id = stock_movements.product_id), 0), "
                    . "products.cost_price, products.purchase_price, 0)";

                $applySmSrFilters = function ($q, array $types, bool $useCreatedAt = false) use (
                    $refIntCast, $srRefFallback, $fromDateTime, $toDateTime,
                    $allowedOutlets, $warehouse, $warehouseId, $hasCategory, $category, $hasSearch, $search
                ) {
                    $dtExpr = $useCreatedAt ? 'stock_movements.created_at' : 'COALESCE(sale_returns.date, stock_movements.created_at)';
                    $q->leftJoin('sale_returns', DB::raw($refIntCast), '=', 'sale_returns.id')
                        ->leftJoin('outlets', 'stock_movements.warehouse_id', '=', 'outlets.id')
                        ->leftJoin('products', 'stock_movements.product_id', '=', 'products.id')
                        ->whereIn('stock_movements.movement_type', $types)
                        ->whereRaw("{$dtExpr} >= ?", [$fromDateTime])
                        ->whereRaw("{$dtExpr} <= ?", [$toDateTime]);
                    if (!empty($allowedOutlets)) {
                        $q->whereRaw('COALESCE(sale_returns.warehouse_id, stock_movements.warehouse_id) IN (' . implode(',', array_map('intval', $allowedOutlets)) . ')');
                    }
                    if ($warehouse !== 'All' && !empty($warehouse)) {
                        if ($warehouseId !== null) {
                            $q->whereRaw('COALESCE(sale_returns.warehouse_id, stock_movements.warehouse_id) = ?', [$warehouseId]);
                        } else {
                            $q->whereRaw('COALESCE(sale_returns.warehouse_name, outlets.name) = ?', [$warehouse]);
                        }
                    }
                    if ($hasCategory) {
                        $q->where('products.category_name', $category);
                    }
                    if ($hasSearch) {
                        $like = "%{$search}%";
                        $q->where(function ($sub) use ($like, $srRefFallback) {
                            $sub->whereRaw("COALESCE(sale_returns.reference_no, {$srRefFallback}) like ?", [$like])
                                ->orWhere('products.name', 'like', $like)
                                ->orWhere('products.code', 'like', $like)
                                ->orWhere('products.alternative_name', 'like', $like)
                                ->orWhereRaw("COALESCE(sale_returns.warehouse_name, outlets.name, '') like ?", [$like])
                                ->orWhereRaw("COALESCE(sale_returns.created_by, 'System') like ?", [$like])
                                ->orWhereRaw("COALESCE(sale_returns.user_name, sale_returns.created_by, 'System') like ?", [$like]);
                        });
                    }
                    return $q;
                };

                if ($hasSmSaleRet) {
                    $srQ = $applySmSrFilters(DB::table('stock_movements'), ['sale_return']);
                    $branches[] = [
                        'sumQuery' => clone $srQ,
                        'qtyInExpr' => 'stock_movements.quantity_in',
                        'qtyOutExpr' => '0',
                        'unitCostExpr' => $srHistUnitCostExpr,
                        'rowQuery' => (clone $srQ)->select([
                            DB::raw("COALESCE(sale_returns.id, {$refIntCast}, 0) as source_id"),
                            DB::raw('0 as item_row_id'),
                            'stock_movements.product_variant_id as variant_id',
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
                            DB::raw("{$srHistUnitCostExpr} as unit_cost"),
                            DB::raw("COALESCE(sale_returns.created_by, 'System') as created_by"),
                            DB::raw("COALESCE(sale_returns.user_name, sale_returns.created_by, 'System') as user_name"),
                            DB::raw("COALESCE(sale_returns.user_role, '') as user_role"),
                            DB::raw('COALESCE(sale_returns.date, stock_movements.created_at) as date'),
                        ]),
                        'dateCol' => DB::raw('COALESCE(sale_returns.date, stock_movements.created_at)'),
                        'statusSplit' => null,
                        'statusCol' => null,
                    ];
                }

                if ($hasSmSaleRetVoid) {
                    $srvQ = $applySmSrFilters(DB::table('stock_movements'), ['sale_return_void', 'sale_return_edit_reverse'], true);
                    $branches[] = [
                        'sumQuery' => clone $srvQ,
                        'qtyInExpr' => '0',
                        'qtyOutExpr' => 'stock_movements.quantity_out',
                        'unitCostExpr' => $srHistUnitCostExpr,
                        'rowQuery' => (clone $srvQ)->select([
                            DB::raw("COALESCE(sale_returns.id, {$refIntCast}, 0) as source_id"),
                            DB::raw('0 as item_row_id'),
                            'stock_movements.product_variant_id as variant_id',
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
                            DB::raw("{$srHistUnitCostExpr} as unit_cost"),
                            DB::raw("COALESCE(sale_returns.created_by, 'System') as created_by"),
                            DB::raw("COALESCE(sale_returns.user_name, sale_returns.created_by, 'System') as user_name"),
                            DB::raw("COALESCE(sale_returns.user_role, '') as user_role"),
                            'stock_movements.created_at as date',
                        ]),
                        'dateCol' => 'stock_movements.created_at',
                        'statusSplit' => null,
                        'statusCol' => null,
                    ];
                }
            }

            if ($wantPurchReturn && ($hasSmPurchRet || $hasSmPurchRetVoid)) {
                $prHistUnitCostExpr = "COALESCE({$smUnitCostPrefix}"
                    . "NULLIF((SELECT SUM(prcl.total_cost) / NULLIF(SUM(prcl.base_quantity), 0) FROM purchase_return_cost_layers prcl WHERE prcl.purchase_return_id = purchase_returns.id AND prcl.product_id = stock_movements.product_id AND (stock_movements.product_variant_id IS NULL OR prcl.product_variant_id = stock_movements.product_variant_id)), 0), "
                    . "NULLIF((SELECT SUM(prcl2.total_cost) / NULLIF(SUM(prcl2.base_quantity), 0) FROM purchase_return_cost_layers prcl2 WHERE prcl2.purchase_return_id = purchase_returns.id AND prcl2.product_id = stock_movements.product_id), 0), "
                    . "NULLIF(CASE WHEN COALESCE(purchase_returns.return_cost_total, 0) > 0 AND (SELECT COALESCE(SUM(sm2.quantity_out), 0) FROM stock_movements sm2 WHERE sm2.movement_type = 'purchase_return' AND sm2.reference_id = stock_movements.reference_id) > 0 THEN purchase_returns.return_cost_total / (SELECT SUM(sm2.quantity_out) FROM stock_movements sm2 WHERE sm2.movement_type = 'purchase_return' AND sm2.reference_id = stock_movements.reference_id) ELSE 0 END, 0), "
                    . "products.cost_price, products.purchase_price, 0)";

                $prVoidHistUnitCostExpr = "COALESCE({$smUnitCostPrefix}"
                    . "NULLIF((SELECT CASE WHEN SUM(ib.initial_quantity) > 0 THEN SUM(ib.initial_quantity * ib.unit_cost) / SUM(ib.initial_quantity) ELSE MAX(ib.unit_cost) END FROM inventory_batches ib WHERE ib.source_type = 'purchase_return_void' AND ib.source_reference = purchase_returns.reference_no AND ib.product_id = stock_movements.product_id AND (stock_movements.product_variant_id IS NULL OR ib.product_variant_id = stock_movements.product_variant_id)), 0), "
                    . ($hasMovementUnitCost ? "NULLIF((SELECT smOrig.unit_cost FROM stock_movements smOrig WHERE smOrig.movement_type = 'purchase_return' AND smOrig.reference_id = stock_movements.reference_id AND smOrig.product_id = stock_movements.product_id ORDER BY smOrig.id DESC LIMIT 1), 0), " : "")
                    . "NULLIF(CASE WHEN COALESCE(purchase_returns.return_cost_total, 0) > 0 AND (SELECT COALESCE(SUM(sm2.quantity_in), 0) FROM stock_movements sm2 WHERE sm2.movement_type = 'purchase_return_void' AND sm2.reference_id = stock_movements.reference_id) > 0 THEN purchase_returns.return_cost_total / (SELECT SUM(sm2.quantity_in) FROM stock_movements sm2 WHERE sm2.movement_type = 'purchase_return_void' AND sm2.reference_id = stock_movements.reference_id) ELSE 0 END, 0), "
                    . "products.cost_price, products.purchase_price, 0)";

                $applySmPrFilters = function ($q, string $type, bool $useCreatedAt = false) use (
                    $refIntCast, $prRefFallback, $fromDateTime, $toDateTime,
                    $allowedOutlets, $warehouse, $warehouseId, $hasCategory, $category, $hasSearch, $search
                ) {
                    $dtExpr = $useCreatedAt ? 'stock_movements.created_at' : 'COALESCE(purchase_returns.date, stock_movements.created_at)';
                    $q->leftJoin('purchase_returns', DB::raw($refIntCast), '=', 'purchase_returns.id')
                        ->leftJoin('outlets', 'stock_movements.warehouse_id', '=', 'outlets.id')
                        ->leftJoin('products', 'stock_movements.product_id', '=', 'products.id')
                        ->where('stock_movements.movement_type', $type)
                        ->whereRaw("{$dtExpr} >= ?", [$fromDateTime])
                        ->whereRaw("{$dtExpr} <= ?", [$toDateTime]);
                    if (!empty($allowedOutlets)) {
                        $q->whereRaw('COALESCE(purchase_returns.warehouse_id, stock_movements.warehouse_id) IN (' . implode(',', array_map('intval', $allowedOutlets)) . ')');
                    }
                    if ($warehouse !== 'All' && !empty($warehouse)) {
                        if ($warehouseId !== null) {
                            $q->whereRaw('COALESCE(purchase_returns.warehouse_id, stock_movements.warehouse_id) = ?', [$warehouseId]);
                        } else {
                            $q->whereRaw('COALESCE(purchase_returns.warehouse_name, outlets.name) = ?', [$warehouse]);
                        }
                    }
                    if ($hasCategory) {
                        $q->where('products.category_name', $category);
                    }
                    if ($hasSearch) {
                        $like = "%{$search}%";
                        $q->where(function ($sub) use ($like, $prRefFallback) {
                            $sub->whereRaw("COALESCE(purchase_returns.reference_no, {$prRefFallback}) like ?", [$like])
                                ->orWhere('products.name', 'like', $like)
                                ->orWhere('products.code', 'like', $like)
                                ->orWhere('products.alternative_name', 'like', $like)
                                ->orWhereRaw("COALESCE(purchase_returns.warehouse_name, outlets.name, '') like ?", [$like])
                                ->orWhereRaw("COALESCE(purchase_returns.created_by, '') like ?", [$like])
                                ->orWhereRaw("COALESCE(purchase_returns.user_name, purchase_returns.created_by, '') like ?", [$like]);
                        });
                    }
                    return $q;
                };

                if ($hasSmPurchRet) {
                    $prQ = $applySmPrFilters(DB::table('stock_movements'), 'purchase_return');
                    $branches[] = [
                        'sumQuery' => clone $prQ,
                        'qtyInExpr' => '0',
                        'qtyOutExpr' => 'stock_movements.quantity_out',
                        'unitCostExpr' => $prHistUnitCostExpr,
                        'rowQuery' => (clone $prQ)->select([
                            DB::raw("COALESCE(purchase_returns.id, {$refIntCast}, 0) as source_id"),
                            DB::raw('0 as item_row_id'),
                            'stock_movements.product_variant_id as variant_id',
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
                            DB::raw("{$prHistUnitCostExpr} as unit_cost"),
                            DB::raw("COALESCE(purchase_returns.created_by, '') as created_by"),
                            DB::raw("COALESCE(purchase_returns.user_name, purchase_returns.created_by, '') as user_name"),
                            DB::raw("COALESCE(purchase_returns.user_role, '') as user_role"),
                            DB::raw('COALESCE(purchase_returns.date, stock_movements.created_at) as date'),
                        ]),
                        'dateCol' => DB::raw('COALESCE(purchase_returns.date, stock_movements.created_at)'),
                        'statusSplit' => null,
                        'statusCol' => null,
                    ];
                }

                if ($hasSmPurchRetVoid) {
                    $prvQ = $applySmPrFilters(DB::table('stock_movements'), 'purchase_return_void', true);
                    $branches[] = [
                        'sumQuery' => clone $prvQ,
                        'qtyInExpr' => 'stock_movements.quantity_in',
                        'qtyOutExpr' => '0',
                        'unitCostExpr' => $prVoidHistUnitCostExpr,
                        'rowQuery' => (clone $prvQ)->select([
                            DB::raw("COALESCE(purchase_returns.id, {$refIntCast}, 0) as source_id"),
                            DB::raw('0 as item_row_id'),
                            'stock_movements.product_variant_id as variant_id',
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
                            DB::raw("{$prVoidHistUnitCostExpr} as unit_cost"),
                            DB::raw("COALESCE(purchase_returns.created_by, 'System') as created_by"),
                            DB::raw("COALESCE(purchase_returns.user_name, purchase_returns.created_by, 'System') as user_name"),
                            DB::raw("COALESCE(purchase_returns.user_role, '') as user_role"),
                            'stock_movements.created_at as date',
                        ]),
                        'dateCol' => 'stock_movements.created_at',
                        'statusSplit' => null,
                        'statusCol' => null,
                    ];
                }
            }

            if (!empty($branches)) {
                $needTopN = $offset + $perPage + 1;
                $allCandidates = [];

                foreach ($branches as $br) {
                    $sq = $br['sumQuery'];
                    if (!$skipSummary) {
                        $valExpr = $canViewCost
                            ? "COALESCE(SUM((({$br['qtyInExpr']}) + ({$br['qtyOutExpr']})) * ({$br['unitCostExpr']})), 0)"
                            : '0';
                        $bs = (clone $sq)->selectRaw("
                            COUNT(*) as total_movements,
                            COALESCE(SUM({$br['qtyInExpr']}), 0) as total_inflow_qty,
                            COALESCE(SUM({$br['qtyOutExpr']}), 0) as total_outflow_qty,
                            {$valExpr} as total_movement_value
                        ")->first();
                        $totalMovements += (int)($bs->total_movements ?? 0);
                        $totalInflowQty += (float)($bs->total_inflow_qty ?? 0);
                        $totalOutflowQty += (float)($bs->total_outflow_qty ?? 0);
                        $totalMovementValue += (float)($bs->total_movement_value ?? 0);
                    } elseif ($knownTotal === null || $page <= 1) {
                        $totalMovements += (int)(clone $sq)->count();
                    }

                    $dateCol = $br['dateCol'];
                    if (!empty($br['statusSplit']) && !empty($br['rowBuilder'])) {
                        $builder = $br['rowBuilder'];
                        foreach ($br['statusSplit'] as $st) {
                            foreach ($builder($st)->orderByDesc($dateCol)->limit($needTopN)->get() as $r) {
                                $allCandidates[] = $r;
                            }
                        }
                    } else {
                        foreach ((clone $br['rowQuery'])->orderByDesc($dateCol)->limit($needTopN)->get() as $r) {
                            $allCandidates[] = $r;
                        }
                    }
                }
                if ($skipSummary && $knownTotal !== null && $page > 1) {
                    $totalMovements = $knownTotal;
                }

                usort($allCandidates, static fn ($a, $b) => strcmp((string)$b->date, (string)$a->date));
                $hasMoreMovements = count($allCandidates) > ($offset + $perPage) || ($offset + $perPage) < $totalMovements;
                $pageMvRows = collect(array_slice($allCandidates, $offset, $perPage));

                // Refine exact FIFO/landed batch unit_cost strictly on the <=50 paged purchase rows
                if ($canViewCost && $pageMvRows->isNotEmpty()) {
                    $piIds = $pageMvRows
                        ->filter(fn ($r) => $r->movement_type === 'purchase' && (int)($r->item_row_id ?? 0) > 0)
                        ->pluck('item_row_id')
                        ->map(fn ($v) => (int)$v)
                        ->all();
                    if (!empty($piIds) && $accounting->schemaHasTable('inventory_batches')) {
                        $batchCostByPi = DB::table('inventory_batches')
                            ->whereIn('purchase_item_id', $piIds)
                            ->selectRaw('purchase_item_id, CASE WHEN SUM(initial_quantity) > 0 THEN SUM(initial_quantity * unit_cost) / SUM(initial_quantity) ELSE MAX(unit_cost) END as b_cost')
                            ->groupBy('purchase_item_id')
                            ->pluck('b_cost', 'purchase_item_id');
                        foreach ($pageMvRows as $r) {
                            $piId = (int)($r->item_row_id ?? 0);
                            if ($r->movement_type === 'purchase' && $piId > 0 && isset($batchCostByPi[$piId]) && (float)$batchCostByPi[$piId] > 0) {
                                $r->unit_cost = (float)$batchCostByPi[$piId];
                            }
                        }
                    }
                }
            }
        }
        $netMovementQty = round($totalInflowQty - $totalOutflowQty, 2);
        $lastPage = max(1, (int)ceil($totalMovements / max(1, $perPage)));

        // Paginated Section 2: Product-Level Bin Card Summary (50-ID scoped, single movement pass)
        $totalProductsCount = 0;
        $hasMoreProducts = false;
        $products = collect();
        $pageProductIds = [];

        if ($section !== 'journal') {
            $prodQuery = DB::table('products')->whereNull('deleted_at')->select([
                'id', 'code', 'name', 'alternative_name', 'category_name', 'unit_name', 'cost_price', 'selling_price', 'stock_quantity'
            ]);
            if ($hasCategory) {
                $prodQuery->where('category_name', $category);
            }
            if ($hasSearch) {
                $prodQuery->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%")
                      ->orWhere('alternative_name', 'like', "%{$search}%");
                });
            }

            $totalProductsCount = (!$skipSummary || $binPage <= 1 || $knownTotal === null)
                ? (int)(clone $prodQuery)->count()
                : $knownTotal;
            $binOffset = ($binPage - 1) * $perPage;
            $rawProducts = $prodQuery->orderBy('id')->offset($binOffset)->limit($perPage + 1)->get();
            $hasMoreProducts = $rawProducts->count() > $perPage;
            $products = $hasMoreProducts ? $rawProducts->slice(0, $perPage)->values() : $rawProducts->values();
            $pageProductIds = $products->pluck('id')->map(fn($v) => (int)$v)->all();
        }

        // Scope warehouse stock balances strictly to product IDs present on this page (movements + bin cards)
        $neededProdIds = array_values(array_unique(array_merge(
            $pageProductIds,
            $pageMvRows->pluck('product_id')->filter()->map(fn($v) => (int)$v)->all()
        )));
        $warehouseBalances = [];
        $hasBalanceRowsByProd = [];
        if (!empty($neededProdIds)) {
            foreach (array_chunk($neededProdIds, 400) as $idChunk) {
                $wbQuery = DB::table('stock_balances')->whereIn('product_id', $idChunk);
                if ($warehouseId !== null) {
                    $wbQuery->where('warehouse_id', $warehouseId);
                } elseif (!empty($allowedOutlets)) {
                    $wbQuery->whereIn('warehouse_id', $allowedOutlets);
                }
                foreach ($wbQuery->select('product_id', DB::raw('SUM(quantity) as qty'))
                    ->groupBy('product_id')
                    ->pluck('qty', 'product_id') as $pid => $qty) {
                    $warehouseBalances[(int)$pid] = (float)$qty;
                    $hasBalanceRowsByProd[(int)$pid] = true;
                }
            }
        }

        // Compute true historical running balance_qty for Movement Journal rows by stepping backwards
        // from each product's exact closing stock at $toDateTime through descending chronological movements.
        $runningBalanceByCandidateIdx = [];
        if ($pageMvRows->isNotEmpty()) {
            $mvProdIds = $pageMvRows->pluck('product_id')->filter()->map(fn($v) => (int)$v)->unique()->values()->all();
            $liveStockByMvProd = [];
            if (!empty($mvProdIds)) {
                $prodLiveFallback = DB::table('products')
                    ->whereIn('id', $mvProdIds)
                    ->pluck('stock_quantity', 'id');
                foreach ($mvProdIds as $mpid) {
                    if ($warehouseId !== null || !empty($allowedOutlets)) {
                        $liveStockByMvProd[$mpid] = (float)($warehouseBalances[$mpid] ?? 0.0);
                    } elseif (isset($hasBalanceRowsByProd[$mpid])) {
                        $liveStockByMvProd[$mpid] = (float)$warehouseBalances[$mpid];
                    } else {
                        $liveStockByMvProd[$mpid] = (float)($prodLiveFallback[$mpid] ?? 0.0);
                    }
                }
                $postOnlyMovs = $this->aggregateBinCardMovementsByProduct(
                    $toDateTime,
                    null,
                    (string)$warehouse,
                    false,
                    $allowedOutlets,
                    $mvProdIds,
                    false
                );
                $runningByProd = [];
                foreach ($mvProdIds as $mpid) {
                    $postIn = (float)($postOnlyMovs['purchases'][$mpid] ?? 0)
                        + (float)($postOnlyMovs['sale_returns'][$mpid] ?? 0)
                        + (float)($postOnlyMovs['transfers_in'][$mpid] ?? 0)
                        + (float)($postOnlyMovs['adjust_in'][$mpid] ?? 0);
                    $postOut = (float)($postOnlyMovs['sales'][$mpid] ?? 0)
                        + (float)($postOnlyMovs['purchase_returns'][$mpid] ?? 0)
                        + (float)($postOnlyMovs['transfers_out'][$mpid] ?? 0)
                        + (float)($postOnlyMovs['adjust_out'][$mpid] ?? 0);
                    $runningByProd[$mpid] = round($liveStockByMvProd[$mpid] - ($postIn - $postOut), 4);
                }

                $canStepExact = ($movementType === 'All' && !$hasSearch);
                $walkLimit = min(count($allCandidates), $offset + $perPage);
                for ($ci = 0; $ci < $walkLimit; $ci++) {
                    $cand = $allCandidates[$ci];
                    $mpid = (int)($cand->product_id ?? 0);
                    if (!isset($runningByProd[$mpid])) {
                        continue;
                    }
                    if ($ci >= $offset) {
                        $runningBalanceByCandidateIdx[$ci - $offset] = round($runningByProd[$mpid], 4);
                    }
                    if ($canStepExact) {
                        $runningByProd[$mpid] = round(
                            $runningByProd[$mpid] - (float)($cand->quantity_in ?? 0) + (float)($cand->quantity_out ?? 0),
                            4
                        );
                    }
                }
            }
        }

        $movementsData = $pageMvRows->map(function ($row, $idx) use ($offset, $canViewCost, $warehouseId, $allowedOutlets, $warehouseBalances, $runningBalanceByCandidateIdx) {
            $qtyIn = (float)$row->quantity_in;
            $qtyOut = (float)$row->quantity_out;
            $unitCost = (float)$row->unit_cost;
            $qty = $qtyIn > 0 ? $qtyIn : $qtyOut;
            $val = round($qty * $unitCost, 2);
            $userName = !empty($row->user_name) ? (string)$row->user_name : (!empty($row->created_by) ? (string)$row->created_by : 'Super Admin');
            $userRole = !empty($row->user_role) ? (string)$row->user_role : 'Super Admin';
            $balanceQty = array_key_exists($idx, $runningBalanceByCandidateIdx)
                ? (float)$runningBalanceByCandidateIdx[$idx]
                : (($warehouseId !== null || !empty($allowedOutlets))
                    ? (float)($warehouseBalances[(int)($row->product_id ?? 0)] ?? 0.0)
                    : (float)$row->balance_qty);
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

        if ($section !== 'journal' && !empty($pageProductIds)) {
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
                    + (float)($postMovs['adjust_in'][$pid] ?? 0);
                $postOut = (float)($postMovs['sales'][$pid] ?? 0)
                    + (float)($postMovs['purchase_returns'][$pid] ?? 0)
                    + (float)($postMovs['transfers_out'][$pid] ?? 0)
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
                    : (isset($hasBalanceRowsByProd[$pId]) ? (float)$warehouseBalances[$pId] : (float)($p->stock_quantity ?? 0.0));
                $cost = (float)($p->cost_price ?? 0);
                $selling = (float)($p->selling_price ?? 0);

                $purchQty = (float)($periodMovs['purchases'][$pId] ?? 0);
                $saleRetQty = (float)($periodMovs['sale_returns'][$pId] ?? 0);
                $trfInQty = (float)($periodMovs['transfers_in'][$pId] ?? 0);
                $adjustIn = (float)($periodMovs['adjust_in'][$pId] ?? 0);

                $soldQty = (float)($periodMovs['sales'][$pId] ?? 0);
                $purchRetQty = (float)($periodMovs['purchase_returns'][$pId] ?? 0);
                $trfOutQty = (float)($periodMovs['transfers_out'][$pId] ?? 0);
                $adjustOut = (float)($periodMovs['adjust_out'][$pId] ?? 0);

                $totalInflow = $purchQty + $saleRetQty + $trfInQty + $adjustIn;
                $totalOutflow = $soldQty + $purchRetQty + $trfOutQty + $adjustOut;
                $netMv = $totalInflow - $totalOutflow;

                $postInflow = (float)($postMovs['purchases'][$pId] ?? 0)
                    + (float)($postMovs['sale_returns'][$pId] ?? 0)
                    + (float)($postMovs['transfers_in'][$pId] ?? 0)
                    + (float)($postMovs['adjust_in'][$pId] ?? 0);
                $postOutflow = (float)($postMovs['sales'][$pId] ?? 0)
                    + (float)($postMovs['purchase_returns'][$pId] ?? 0)
                    + (float)($postMovs['transfers_out'][$pId] ?? 0)
                    + (float)($postMovs['adjust_out'][$pId] ?? 0);
                $postNet = $postInflow - $postOutflow;

                // Preserve exact accounting identity: Opening + Inflow - Outflow == Closing (no zero-clamping distortion)
                $closingStock = round($liveStock - $postNet, 4);
                $openingStock = round($closingStock - $netMv, 4);

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
                    'adjustments_in' => round($adjustIn, 2),
                    'total_inflow' => round($totalInflow, 2),
                    'sales_out' => round($soldQty, 2),
                    'purchase_returns_out' => round($purchRetQty, 2),
                    'transfers_out' => round($trfOutQty, 2),
                    'adjustments_out' => round($adjustOut, 2),
                    'total_outflow' => round($totalOutflow, 2),
                    'net_movement' => round($netMv, 2),
                    'closing_stock' => round($closingStock, 2),
                    'closing_cost_value' => $canViewCost ? round($closingCostVal, 2) : 0.0,
                    'closing_retail_value' => round($closingStock * $selling, 2),
                ];
            }

            // Compute catalog-wide Bin Card summary totals across all filtered products when paginated (> perPage items)
            if (!$skipSummary && $totalProductsCount > count($items)) {
                $applyCatProdFilter = static function ($q) use ($hasCategory, $category, $hasSearch, $search): void {
                    $q->whereNull('products.deleted_at');
                    if ($hasCategory) {
                        $q->where('products.category_name', $category);
                    }
                    if ($hasSearch) {
                        $like = "%{$search}%";
                        $q->where(function ($sub) use ($like) {
                            $sub->where('products.code', 'like', $like)
                                ->orWhere('products.name', 'like', $like)
                                ->orWhere('products.alternative_name', 'like', $like);
                        });
                    }
                };

                $hasVariantsTbl = $accounting->schemaHasTable('product_variants');
                $filterByProductSubset = $hasCategory || $hasSearch;

                // Build reusable product-ID subquery when category/search narrows the catalog
                $catProdSubQuery = null;
                if ($filterByProductSubset) {
                    $catProdSubQuery = DB::table('products')->select('products.id');
                    $applyCatProdFilter($catProdSubQuery);
                }

                // Authoritative live valuation using variant-aware costs + FIFO layers (matches Stock Report & Dashboard 100%)
                $liveValuationSummary = $canViewCost
                    ? app(InventoryReconciliationService::class)->computeValuationSummary(
                        $warehouseId !== null ? (int)$warehouseId : null,
                        !empty($allowedOutlets) ? $allowedOutlets : null,
                        null,
                        $filterByProductSubset ? null : $totalProductsCount,
                        false,
                        $catProdSubQuery
                    )
                    : ['effective_valuation' => 0.0, 'master_valuation' => 0.0];
                $liveEffectiveVal = (float)($liveValuationSummary['effective_valuation'] ?? 0.0);
                $liveMasterVal = (float)($liveValuationSummary['master_valuation'] ?? 0.0);

                // Aggregate qty per (product_id, product_variant_id) first before joining products/variants,
                // avoiding tens of thousands of per-line PK lookups while keeping variant-exact cost valuation.
                $sumGroupedCostDelta = function ($groupedSubQ) use ($applyCatProdFilter, $hasVariantsTbl): array {
                    $q = DB::query()
                        ->fromSub($groupedSubQ, 'agg')
                        ->join('products', 'agg.product_id', '=', 'products.id');
                    if ($hasVariantsTbl) {
                        $q->leftJoin('product_variants as pv', 'agg.product_variant_id', '=', 'pv.id');
                        $costExpr = 'COALESCE(NULLIF(pv.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)';
                    } else {
                        $costExpr = 'COALESCE(NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)';
                    }
                    $applyCatProdFilter($q);
                    $row = $q->selectRaw(
                        'COALESCE(SUM(agg.period_qty), 0) as period_qty, '
                        . 'COALESCE(SUM(agg.post_qty), 0) as post_qty, '
                        . "COALESCE(SUM(agg.period_qty * {$costExpr}), 0) as period_cost, "
                        . "COALESCE(SUM(agg.post_qty * {$costExpr}), 0) as post_cost"
                    )->first();
                    return [
                        'period_qty' => (float)($row->period_qty ?? 0),
                        'post_qty' => (float)($row->post_qty ?? 0),
                        'period_cost' => (float)($row->period_cost ?? 0),
                        'post_cost' => (float)($row->post_cost ?? 0),
                    ];
                };

                $purchCatalogQ = DB::table('purchase_items')
                    ->join('purchases', 'purchase_items.purchase_id', '=', 'purchases.id')
                    ->whereIn('purchases.status', ['Received', 'Completed'])
                    ->where('purchases.date', '>=', $fromDateTime);
                $applyWhScope($purchCatalogQ, 'purchases.warehouse_name', 'purchases.warehouse_id');
                if ($filterByProductSubset) {
                    $purchCatalogQ->join('products', 'purchase_items.product_id', '=', 'products.id');
                    if ($hasVariantsTbl) {
                        $purchCatalogQ->leftJoin('product_variants as pv', 'purchase_items.product_variant_id', '=', 'pv.id');
                    }
                    $applyCatProdFilter($purchCatalogQ);
                    $pCostSql = $hasVariantsTbl
                        ? 'COALESCE(NULLIF(pv.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)'
                        : 'COALESCE(NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)';
                    $purchRow = $purchCatalogQ->selectRaw(
                        "COALESCE(SUM(CASE WHEN purchases.date <= ? THEN {$purchBaseQtySql} ELSE 0 END), 0) as period_qty, "
                        . "COALESCE(SUM(CASE WHEN purchases.date > ? THEN {$purchBaseQtySql} ELSE 0 END), 0) as post_qty, "
                        . "COALESCE(SUM(CASE WHEN purchases.date <= ? THEN ({$purchBaseQtySql}) * {$pCostSql} ELSE 0 END), 0) as period_cost, "
                        . "COALESCE(SUM(CASE WHEN purchases.date > ? THEN ({$purchBaseQtySql}) * {$pCostSql} ELSE 0 END), 0) as post_cost",
                        [$toDateTime, $toDateTime, $toDateTime, $toDateTime]
                    )->first();
                    $purchStats = [
                        'period_qty' => (float)($purchRow->period_qty ?? 0),
                        'post_qty' => (float)($purchRow->post_qty ?? 0),
                        'period_cost' => (float)($purchRow->period_cost ?? 0),
                        'post_cost' => (float)($purchRow->post_cost ?? 0),
                    ];
                } else {
                    $purchCatalogQ->selectRaw(
                        "purchase_items.product_id, purchase_items.product_variant_id, "
                        . "SUM(CASE WHEN purchases.date <= ? THEN {$purchBaseQtySql} ELSE 0 END) as period_qty, "
                        . "SUM(CASE WHEN purchases.date > ? THEN {$purchBaseQtySql} ELSE 0 END) as post_qty",
                        [$toDateTime, $toDateTime]
                    )->groupBy('purchase_items.product_id', 'purchase_items.product_variant_id');
                    $purchStats = $sumGroupedCostDelta($purchCatalogQ);
                }
                $totalPurchasesIn = $purchStats['period_qty'];

                $salesCatalogQ = DB::table('sale_items')
                    ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                    ->where('sales.date', '>=', $fromDateTime);
                $this->applyActiveDocumentFilter($salesCatalogQ, 'sales.status');
                $applyWhScope($salesCatalogQ, 'sales.warehouse_name', 'sales.warehouse_id');
                if ($filterByProductSubset) {
                    $salesCatalogQ->join('products', 'sale_items.product_id', '=', 'products.id');
                    if ($hasVariantsTbl) {
                        $salesCatalogQ->leftJoin('product_variants as pv', 'sale_items.product_variant_id', '=', 'pv.id');
                    }
                    $applyCatProdFilter($salesCatalogQ);
                    $sCostSql = $hasVariantsTbl
                        ? 'COALESCE(NULLIF(pv.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)'
                        : 'COALESCE(NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)';
                    $salesRow = $salesCatalogQ->selectRaw(
                        "COALESCE(SUM(CASE WHEN sales.date <= ? THEN {$saleBaseQtySql} ELSE 0 END), 0) as period_qty, "
                        . "COALESCE(SUM(CASE WHEN sales.date > ? THEN {$saleBaseQtySql} ELSE 0 END), 0) as post_qty, "
                        . "COALESCE(SUM(CASE WHEN sales.date <= ? THEN ({$saleBaseQtySql}) * {$sCostSql} ELSE 0 END), 0) as period_cost, "
                        . "COALESCE(SUM(CASE WHEN sales.date > ? THEN ({$saleBaseQtySql}) * {$sCostSql} ELSE 0 END), 0) as post_cost",
                        [$toDateTime, $toDateTime, $toDateTime, $toDateTime]
                    )->first();
                    $salesStats = [
                        'period_qty' => (float)($salesRow->period_qty ?? 0),
                        'post_qty' => (float)($salesRow->post_qty ?? 0),
                        'period_cost' => (float)($salesRow->period_cost ?? 0),
                        'post_cost' => (float)($salesRow->post_cost ?? 0),
                    ];
                } else {
                    $salesCatalogQ->selectRaw(
                        "sale_items.product_id, sale_items.product_variant_id, "
                        . "SUM(CASE WHEN sales.date <= ? THEN {$saleBaseQtySql} ELSE 0 END) as period_qty, "
                        . "SUM(CASE WHEN sales.date > ? THEN {$saleBaseQtySql} ELSE 0 END) as post_qty",
                        [$toDateTime, $toDateTime]
                    )->groupBy('sale_items.product_id', 'sale_items.product_variant_id');
                    $salesStats = $sumGroupedCostDelta($salesCatalogQ);
                }
                $totalSalesOut = $salesStats['period_qty'];

                if ($canViewCost) {
                    $periodNetCost = $purchStats['period_cost'] - $salesStats['period_cost'];
                    $postNetCost = $purchStats['post_cost'] - $salesStats['post_cost'];
                    $hasPostMovements = (abs($purchStats['post_qty']) > 0.0001 || abs($salesStats['post_qty']) > 0.0001);

                    $evalCatalogDelta = function ($q, string $dateExpr, string $signedQtySql) use ($toDateTime, $filterByProductSubset, $applyCatProdFilter, $sumGroupedCostDelta, $hasVariantsTbl): array {
                        $itemTbl = $q->from;
                        if ($filterByProductSubset) {
                            $q->join('products', $itemTbl . '.product_id', '=', 'products.id');
                            if ($hasVariantsTbl) {
                                $q->leftJoin('product_variants as pv', $itemTbl . '.product_variant_id', '=', 'pv.id');
                                $vCostSql = 'COALESCE(NULLIF(pv.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)';
                            } else {
                                $vCostSql = 'COALESCE(NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)';
                            }
                            $applyCatProdFilter($q);
                            $r = $q->selectRaw(
                                "COALESCE(SUM(CASE WHEN {$dateExpr} <= ? THEN ({$signedQtySql}) * {$vCostSql} ELSE 0 END), 0) as period_cost, "
                                . "COALESCE(SUM(CASE WHEN {$dateExpr} > ? THEN ({$signedQtySql}) * {$vCostSql} ELSE 0 END), 0) as post_cost, "
                                . "COALESCE(SUM(CASE WHEN {$dateExpr} > ? THEN ABS({$signedQtySql}) ELSE 0 END), 0) as post_abs_qty",
                                [$toDateTime, $toDateTime, $toDateTime]
                            )->first();
                            return [(float)($r->period_cost ?? 0), (float)($r->post_cost ?? 0), (float)($r->post_abs_qty ?? 0)];
                        }
                        $q->selectRaw(
                            "{$itemTbl}.product_id, {$itemTbl}.product_variant_id, "
                            . "SUM(CASE WHEN {$dateExpr} <= ? THEN ({$signedQtySql}) ELSE 0 END) as period_qty, "
                            . "SUM(CASE WHEN {$dateExpr} > ? THEN ({$signedQtySql}) ELSE 0 END) as post_qty",
                            [$toDateTime, $toDateTime]
                        )->groupBy("{$itemTbl}.product_id", "{$itemTbl}.product_variant_id");
                        $s = $sumGroupedCostDelta($q);
                        return [$s['period_cost'], $s['post_cost'], abs($s['post_qty'])];
                    };

                    // Adjustments
                    $adjCatalogQ = DB::table('stock_adjustment_items')
                        ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id')
                        ->where('stock_adjustments.date', '>=', $fromDateTime);
                    $applyWhScope($adjCatalogQ, 'stock_adjustments.warehouse_name', 'stock_adjustments.warehouse_id');
                    $adjSignSql = "CASE WHEN {$effAdjTypeSql} = 'addition' THEN ({$adjBaseQtySql}) ELSE -({$adjBaseQtySql}) END";
                    [$adjPer, $adjPost, $adjPostQty] = $evalCatalogDelta($adjCatalogQ, 'stock_adjustments.date', $adjSignSql);
                    $periodNetCost += $adjPer;
                    $postNetCost += $adjPost;
                    if ($adjPostQty > 0.0001) $hasPostMovements = true;

                    // Transfers Out & In
                    $trfOutCatalogQ = DB::table('stock_transfer_items')
                        ->join('stock_transfers', 'stock_transfer_items.stock_transfer_id', '=', 'stock_transfers.id')
                        ->whereIn('stock_transfers.status', ['Sent', 'Completed'])
                        ->where('stock_transfers.date', '>=', $fromDateTime);
                    $applyWhScope($trfOutCatalogQ, 'stock_transfers.from_warehouse', 'stock_transfers.from_warehouse_id');
                    [$trfOutPer, $trfOutPost, $trfOutPostQty] = $evalCatalogDelta($trfOutCatalogQ, 'stock_transfers.date', $trfBaseQtySql);
                    $periodNetCost -= $trfOutPer;
                    $postNetCost -= $trfOutPost;
                    if ($trfOutPostQty > 0.0001) $hasPostMovements = true;

                    $trfInCatalogQ = DB::table('stock_transfer_items')
                        ->join('stock_transfers', 'stock_transfer_items.stock_transfer_id', '=', 'stock_transfers.id')
                        ->where('stock_transfers.status', 'Completed')
                        ->where('stock_transfers.date', '>=', $fromDateTime);
                    $applyWhScope($trfInCatalogQ, 'stock_transfers.to_warehouse', 'stock_transfers.to_warehouse_id');
                    [$trfInPer, $trfInPost, $trfInPostQty] = $evalCatalogDelta($trfInCatalogQ, 'stock_transfers.date', $trfBaseQtySql);
                    $periodNetCost += $trfInPer;
                    $postNetCost += $trfInPost;
                    if ($trfInPostQty > 0.0001) $hasPostMovements = true;

                    // Sale Returns, Purchase Returns & Variant-Addition Opening Stock (reference_type = 'product_update')
                    if ($accounting->schemaHasTable('stock_movements')) {
                        $smNetQ = DB::table('stock_movements')
                            ->where(function ($q) {
                                $q->whereIn('stock_movements.movement_type', ['sale_return', 'sale_return_void', 'sale_return_edit_reverse', 'purchase_return', 'purchase_return_void'])
                                  ->orWhere(function ($op) {
                                      $op->where('stock_movements.movement_type', 'opening_stock')
                                         ->where('stock_movements.reference_type', 'product_update');
                                  });
                            })
                            ->where('stock_movements.created_at', '>=', $fromDateTime);
                        if (!empty($allowedOutlets)) {
                            $smNetQ->whereIn('stock_movements.warehouse_id', $allowedOutlets);
                        }
                        if ($warehouseId !== null) {
                            $smNetQ->where('stock_movements.warehouse_id', $warehouseId);
                        }
                        if ($filterByProductSubset) {
                            $smNetQ->join('products', 'stock_movements.product_id', '=', 'products.id');
                            if ($hasVariantsTbl) {
                                $smNetQ->leftJoin('product_variants as pv', 'stock_movements.product_variant_id', '=', 'pv.id');
                                $smCostSql = 'COALESCE(NULLIF(pv.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)';
                            } else {
                                $smCostSql = 'COALESCE(NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)';
                            }
                            $applyCatProdFilter($smNetQ);
                            $smNetRow = $smNetQ->selectRaw(
                                "COALESCE(SUM(CASE WHEN stock_movements.created_at <= ? THEN (stock_movements.quantity_in - stock_movements.quantity_out) * {$smCostSql} ELSE 0 END), 0) as period_cost, "
                                . "COALESCE(SUM(CASE WHEN stock_movements.created_at > ? THEN (stock_movements.quantity_in - stock_movements.quantity_out) * {$smCostSql} ELSE 0 END), 0) as post_cost, "
                                . 'COALESCE(SUM(CASE WHEN stock_movements.created_at > ? THEN ABS(stock_movements.quantity_in - stock_movements.quantity_out) ELSE 0 END), 0) as post_abs_qty',
                                [$toDateTime, $toDateTime, $toDateTime]
                            )->first();
                            $periodNetCost += (float)($smNetRow->period_cost ?? 0);
                            $postNetCost += (float)($smNetRow->post_cost ?? 0);
                            if ((float)($smNetRow->post_abs_qty ?? 0) > 0.0001) $hasPostMovements = true;
                        } else {
                            $smNetQ->selectRaw(
                                'stock_movements.product_id, stock_movements.product_variant_id, '
                                . 'SUM(CASE WHEN stock_movements.created_at <= ? THEN (stock_movements.quantity_in - stock_movements.quantity_out) ELSE 0 END) as period_qty, '
                                . 'SUM(CASE WHEN stock_movements.created_at > ? THEN (stock_movements.quantity_in - stock_movements.quantity_out) ELSE 0 END) as post_qty',
                                [$toDateTime, $toDateTime]
                            )->groupBy('stock_movements.product_id', 'stock_movements.product_variant_id');
                            $smStats = $sumGroupedCostDelta($smNetQ);
                            $periodNetCost += $smStats['period_cost'];
                            $postNetCost += $smStats['post_cost'];
                            if (abs($smStats['post_qty']) > 0.0001) $hasPostMovements = true;
                        }
                    }

                    // For current-date cutoff (no post-cutoff movements), use exact FIFO + unbatched valuation matching Stock Report & Dashboard.
                    // For historical cutoffs (post-cutoff movements exist), roll back variant-aware master valuation without applying live FIFO delta!
                    if (!$hasPostMovements) {
                        $totalClosingCost = max(0.0, round($liveEffectiveVal, 2));
                    } else {
                        $totalClosingCost = max(0.0, round($liveMasterVal - $postNetCost, 2));
                    }
                    $totalOpeningCost = max(0.0, round($totalClosingCost - $periodNetCost, 2));
                }
            }
        }

        $binLastPage = max(1, (int)ceil(($totalProductsCount ?: count($items)) / max(1, $perPage)));

        $availableFilters = null;
        if (!$skipSummary) {
            $whListQuery = DB::table('outlets')->orderBy('name');
            if (!empty($allowedOutlets)) {
                $whListQuery->whereIn('id', $allowedOutlets);
            }
            $availWarehouses = $whListQuery->pluck('name')->filter(fn ($v) => !empty($v))->values()->all();
            $availCategories = DB::table('products')
                ->whereNull('deleted_at')
                ->whereNotNull('category_name')
                ->where('category_name', '!=', '')
                ->distinct()
                ->orderBy('category_name')
                ->pluck('category_name')
                ->values()
                ->all();
            $availableFilters = [
                'warehouses' => $availWarehouses,
                'categories' => $availCategories,
                'movement_types' => [
                    'All',
                    'sale',
                    'purchase',
                    'opening_stock',
                    'adjustment_in',
                    'adjustment_out',
                    'transfer',
                    'sale_return',
                    'purchase_return',
                    'in',
                    'out',
                ],
            ];
        }

        $payload = [
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
        ];
        if ($availableFilters !== null) {
            $payload['available_filters'] = $availableFilters;
        }

        return $this->noStoreJson($payload);
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
        $marginHealthRaw = strtolower(trim((string)$request->input('margin_health', 'All')));
        $marginHealth = match ($marginHealthRaw) {
            '', 'all' => 'All',
            'high', 'healthy', 'high_margin' => 'high',
            'moderate', 'medium' => 'moderate',
            'low', 'low_margin' => 'low',
            'negative', 'loss', 'loss_margin' => 'negative',
            default => 'All',
        };
        $page = max(1, (int)($request->input('page') ?? $request->query('page', 1)));
        $perPage = $this->resolveReportPerPage($request, 50, 10000);
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        $warehouseId = $this->resolveWarehouseIdParam(is_scalar($warehouse) ? (string)$warehouse : null);
        if (!empty($allowedOutlets) && $warehouse !== 'All' && $warehouse !== '') {
            if ($warehouseId === null || !in_array($warehouseId, $allowedOutlets, true)) {
                return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
            }
        }

        $saleBaseQtyExpr = 'COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity * COALESCE(NULLIF(sale_items.conversion_rate, 0), 1), 1)';
        $explicitCogsExpr = "COALESCE(NULLIF(sale_items.total_cogs, 0), NULLIF(sale_items.base_unit_cost, 0) * {$saleBaseQtyExpr}, 0)";
        $uncostedQtyExpr = "CASE WHEN COALESCE(sale_items.total_cogs, 0) = 0 AND COALESCE(sale_items.base_unit_cost, 0) = 0 THEN {$saleBaseQtyExpr} ELSE 0 END";
        $hasPointsDiscountCol = $accounting->schemaHasColumn('sales', 'points_discount');
        $orderDiscSql = $hasPointsDiscountCol
            ? '(COALESCE(sales.discount, 0) + COALESCE(sales.points_discount, 0))'
            : 'COALESCE(sales.discount, 0)';
        $netLineRevExpr = "(COALESCE(sale_items.subtotal, 0) - COALESCE(sale_items.tax, 0) - (CASE WHEN COALESCE(sales.subtotal, 0) > 0 THEN {$orderDiscSql} * (COALESCE(sale_items.subtotal, 0) / sales.subtotal) ELSE 0 END))";

        // Keep sales aggregation strictly 2-table (sale_items + sales) so MariaDB uses idx_si_pm_cover;
        // category & search filters are applied against resolved product metadata & fallback names below.
        $query = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id');
        $this->applyActiveDocumentFilter($query, 'sales.status');
        if (!empty($allowedOutlets)) {
            $query->whereIn('sales.warehouse_id', $allowedOutlets);
        }

        $this->applyReportDateRange($query, $startDate, $endDate, 'sales.date');

        if ($warehouse !== 'All' && !empty($warehouse)) {
            if ($warehouseId !== null) {
                $query->where('sales.warehouse_id', $warehouseId);
            } else {
                $query->where('sales.warehouse_name', $warehouse);
            }
        }

        // Pure numeric GROUP BY sale_items.product_id without joining product_variants/products across all rows
        // Use canonical base units so multi-unit sales & base-unit returns reconcile cleanly
        $aggItems = $query->selectRaw("
            sale_items.product_id as product_id,
            SUM({$saleBaseQtyExpr}) as units_sold,
            SUM({$netLineRevExpr}) as gross_revenue,
            SUM({$explicitCogsExpr}) as total_cogs,
            SUM({$uncostedQtyExpr}) as uncosted_qty
        ")
        ->groupBy('sale_items.product_id')
        ->get();

        // Only look up product_variant fallback costs for products that actually have uncosted lines
        $uncostedProdIds = [];
        foreach ($aggItems as $ar) {
            if ((float)($ar->uncosted_qty ?? 0) > 0.0001 && (int)$ar->product_id > 0) {
                $uncostedProdIds[] = (int)$ar->product_id;
            }
        }
        $uncostedVarCogsByProd = [];
        $uncostedCatalogQtyByProd = [];
        if (!empty($uncostedProdIds)) {
            $uq = DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->leftJoin('product_variants', 'sale_items.product_variant_id', '=', 'product_variants.id')
                ->whereIn('sale_items.product_id', $uncostedProdIds)
                ->whereRaw('COALESCE(sale_items.total_cogs, 0) = 0 AND COALESCE(sale_items.base_unit_cost, 0) = 0');
            $this->applyActiveDocumentFilter($uq, 'sales.status');
            if (!empty($allowedOutlets)) {
                $uq->whereIn('sales.warehouse_id', $allowedOutlets);
            }
            $this->applyReportDateRange($uq, $startDate, $endDate, 'sales.date');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                if ($warehouseId !== null) {
                    $uq->where('sales.warehouse_id', $warehouseId);
                } else {
                    $uq->where('sales.warehouse_name', $warehouse);
                }
            }
            foreach ($uq->selectRaw("
                sale_items.product_id,
                SUM(CASE WHEN COALESCE(product_variants.cost_price, 0) > 0 THEN ({$saleBaseQtyExpr}) * product_variants.cost_price ELSE 0 END) as var_cost,
                SUM(CASE WHEN COALESCE(product_variants.cost_price, 0) = 0 THEN ({$saleBaseQtyExpr}) ELSE 0 END) as catalog_qty
            ")->groupBy('sale_items.product_id')->get() as $ur) {
                $upid = (int)$ur->product_id;
                $uncostedVarCogsByProd[$upid] = (float)($ur->var_cost ?? 0);
                if ((float)($ur->catalog_qty ?? 0) > 0.0001) {
                    $uncostedCatalogQtyByProd[$upid] = (float)$ur->catalog_qty;
                }
            }
        }

        // Deduct active completed Sale Returns (returned qty, net revenue, and restored COGS) per product
        $retByProd = [];
        $retCogsByProd = [];
        $srclByRetAndProd = [];
        $hasSrcl = $accounting->schemaHasTable('sale_return_cost_layers') && DB::table('sale_return_cost_layers')->exists();
        if ($hasSrcl) {
            $srclQ = DB::table('sale_return_cost_layers')
                ->join('sale_returns', 'sale_return_cost_layers.sale_return_id', '=', 'sale_returns.id');
            $this->applyActiveDocumentFilter($srclQ, 'sale_returns.status');
            if (!empty($allowedOutlets)) {
                $srclQ->whereIn('sale_returns.warehouse_id', $allowedOutlets);
            }
            $this->applyReportDateRange($srclQ, $startDate, $endDate, 'sale_returns.date');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                if ($warehouseId !== null) {
                    $srclQ->where('sale_returns.warehouse_id', $warehouseId);
                } else {
                    $srclQ->where('sale_returns.warehouse_name', $warehouse);
                }
            }
            foreach ($srclQ->select(
                'sale_return_cost_layers.sale_return_id',
                'sale_return_cost_layers.product_id',
                DB::raw('SUM(sale_return_cost_layers.total_cost) as ret_cogs')
            )
                ->groupBy('sale_return_cost_layers.sale_return_id', 'sale_return_cost_layers.product_id')
                ->get() as $srcl) {
                $rid = (int)$srcl->sale_return_id;
                $pid = (int)$srcl->product_id;
                $rc = (float)$srcl->ret_cogs;
                $srclByRetAndProd[$rid][$pid] = ($srclByRetAndProd[$rid][$pid] ?? 0.0) + $rc;
                $retCogsByProd[$pid] = ($retCogsByProd[$pid] ?? 0.0) + $rc;
            }
        }

        $retQ = DB::table('sale_returns');
        $this->applyActiveDocumentFilter($retQ, 'status');
        if (!empty($allowedOutlets)) {
            $retQ->whereIn('warehouse_id', $allowedOutlets);
        }
        $this->applyReportDateRange($retQ, $startDate, $endDate, 'date');
        if ($warehouse !== 'All' && !empty($warehouse)) {
            if ($warehouseId !== null) {
                $retQ->where('warehouse_id', $warehouseId);
            } else {
                $retQ->where('warehouse_name', $warehouse);
            }
        }
        $srSelectCols = ['id', 'subtotal', 'discount', 'items_payload'];
        if ($accounting->schemaHasColumn('sale_returns', 'return_cost_total')) {
            $srSelectCols[] = 'return_cost_total';
        }
        if ($accounting->schemaHasColumn('sale_returns', 'points_discount')) {
            $srSelectCols[] = 'points_discount';
        }
        $unlayeredRetQtyByProd = [];
        $unlayeredRetVarQtyByProd = [];
        $unlayeredExplicitCogsByProd = [];
        foreach ($retQ->select($srSelectCols)->cursor() as $rRow) {
            $itemsArr = !empty($rRow->items_payload) ? json_decode((string)$rRow->items_payload, true) : null;
            if (!is_array($itemsArr)) {
                continue;
            }
            $rId = (int)$rRow->id;
            $rSub  = (float)($rRow->subtotal ?? 0);
            $rDisc = (float)($rRow->discount ?? 0) + (float)($rRow->points_discount ?? 0);
            $rCostTotal = (float)($rRow->return_cost_total ?? 0);
            foreach ($itemsArr as $ri) {
                $pid = isset($ri['product_id']) ? (int)$ri['product_id'] : 0;
                if ($pid <= 0) {
                    continue;
                }
                $vid = !empty($ri['product_variant_id']) ? (int)$ri['product_variant_id'] : 0;
                $qty  = (float)($ri['quantity'] ?? 0);
                $convRate = (float)($ri['conversion_rate'] ?? 1);
                if ($convRate <= 0) {
                    $convRate = 1.0;
                }
                $baseQty = (float)($ri['base_quantity'] ?? 0);
                if ($baseQty <= 0) {
                    $baseQty = $qty * $convRate;
                }
                $sub  = (float)($ri['subtotal'] ?? ($qty * (float)($ri['unit_price'] ?? 0)));
                $lTax = (float)($ri['tax'] ?? 0);
                $discShare = $rSub > 0.0001 ? ($rDisc * ($sub / $rSub)) : 0.0;
                $netRetRev = max(0.0, $sub - $lTax - $discShare);
                if (!isset($retByProd[$pid])) {
                    $retByProd[$pid] = ['qty' => 0.0, 'rev' => 0.0];
                }
                $retByProd[$pid]['qty'] += $baseQty;
                $retByProd[$pid]['rev'] += $netRetRev;

                if (!isset($srclByRetAndProd[$rId][$pid])) {
                    $itemTotalCost = (float)($ri['total_cost'] ?? 0);
                    $itemBaseUnitCost = (float)($ri['base_unit_cost'] ?? 0);
                    $itemUnitCost = (float)($ri['unit_cost'] ?? $ri['cost_price'] ?? 0);
                    if ($itemTotalCost > 0) {
                        $unlayeredExplicitCogsByProd[$pid] = ($unlayeredExplicitCogsByProd[$pid] ?? 0.0) + $itemTotalCost;
                    } elseif ($itemBaseUnitCost > 0) {
                        $unlayeredExplicitCogsByProd[$pid] = ($unlayeredExplicitCogsByProd[$pid] ?? 0.0) + ($baseQty * $itemBaseUnitCost);
                    } elseif ($itemUnitCost > 0) {
                        $unlayeredExplicitCogsByProd[$pid] = ($unlayeredExplicitCogsByProd[$pid] ?? 0.0) + ($qty * $itemUnitCost);
                    } elseif ($rCostTotal > 0 && $rSub > 0.0001) {
                        $unlayeredExplicitCogsByProd[$pid] = ($unlayeredExplicitCogsByProd[$pid] ?? 0.0) + ($rCostTotal * ($sub / $rSub));
                    } elseif ($baseQty > 0) {
                        if ($vid > 0) {
                            $unlayeredRetVarQtyByProd[$vid] = [
                                'pid' => $pid,
                                'qty' => ($unlayeredRetVarQtyByProd[$vid]['qty'] ?? 0.0) + $baseQty,
                            ];
                        } else {
                            $unlayeredRetQtyByProd[$pid] = ($unlayeredRetQtyByProd[$pid] ?? 0.0) + $baseQty;
                        }
                    }
                }
            }
        }

        // Resolve variant-specific cost_price for unlayered variant returns before falling back to product cost
        if (!empty($unlayeredRetVarQtyByProd)) {
            $varRetCostMap = [];
            if ($accounting->schemaHasTable('product_variants')) {
                foreach (array_chunk(array_keys($unlayeredRetVarQtyByProd), 400) as $vChunk) {
                    foreach (DB::table('product_variants')->whereIn('id', $vChunk)->pluck('cost_price', 'id') as $kVid => $vCost) {
                        if ((float)$vCost > 0) {
                            $varRetCostMap[(int)$kVid] = (float)$vCost;
                        }
                    }
                }
            }
            foreach ($unlayeredRetVarQtyByProd as $kVid => $vInfo) {
                $vPid = (int)$vInfo['pid'];
                $vQty = (float)$vInfo['qty'];
                if (isset($varRetCostMap[$kVid])) {
                    $unlayeredExplicitCogsByProd[$vPid] = ($unlayeredExplicitCogsByProd[$vPid] ?? 0.0) + ($vQty * $varRetCostMap[$kVid]);
                } else {
                    $unlayeredRetQtyByProd[$vPid] = ($unlayeredRetQtyByProd[$vPid] ?? 0.0) + $vQty;
                }
            }
        }

        // Batch-fetch product display metadata + cost fallback in 1 slim query
        $allProdIds = array_values(array_unique(array_merge(
            $aggItems->pluck('product_id')->map(fn ($v) => (int)$v)->filter(fn ($v) => $v > 0)->all(),
            array_keys($retByProd),
            array_keys($retCogsByProd)
        )));
        $prodMetaMap = [];
        if (!empty($allProdIds)) {
            $metaCols = ['id', 'code', 'name', 'alternative_name', 'category_name', 'cost_price', 'purchase_price'];
            if (count($allProdIds) <= 400) {
                foreach (DB::table('products')->whereIn('id', $allProdIds)->get($metaCols) as $pm) {
                    $prodMetaMap[(int)$pm->id] = $pm;
                }
            } else {
                foreach (DB::table('products')->get($metaCols) as $pm) {
                    $prodMetaMap[(int)$pm->id] = $pm;
                }
            }
        }

        // Apply unlayered return COGS fallback using explicit payload/header cost or product catalog cost
        foreach ($unlayeredExplicitCogsByProd as $pid => $exCost) {
            $retCogsByProd[$pid] = ($retCogsByProd[$pid] ?? 0.0) + $exCost;
        }
        foreach ($unlayeredRetQtyByProd as $pid => $uQty) {
            $pm = $prodMetaMap[$pid] ?? null;
            $unitCostFallback = $pm
                ? ((float)($pm->cost_price ?? 0) > 0 ? (float)$pm->cost_price : (float)($pm->purchase_price ?? 0))
                : 0.0;
            if ($unitCostFallback > 0) {
                $retCogsByProd[$pid] = ($retCogsByProd[$pid] ?? 0.0) + ($uQty * $unitCostFallback);
            }
        }

        // Fetch fallback display names ONLY for deleted/unlinked product IDs missing from products table
        $missingMetaIds = [];
        foreach ($aggItems as $row) {
            $pid = (int)$row->product_id;
            if ($pid <= 0 || !isset($prodMetaMap[$pid])) {
                $missingMetaIds[] = $pid;
            }
        }
        $fallbackMetaMap = [];
        if (!empty($missingMetaIds)) {
            foreach (DB::table('sale_items')
                ->whereIn('product_id', array_values(array_unique($missingMetaIds)))
                ->selectRaw("product_id, MAX(COALESCE(product_code, 'N/A')) as fallback_code, MAX(COALESCE(product_name, 'Unnamed Product')) as fallback_name")
                ->groupBy('product_id')
                ->get() as $fm) {
                $fallbackMetaMap[(int)$fm->product_id] = $fm;
            }
        }

        $rawItems = $aggItems->map(function ($row) use ($prodMetaMap, $fallbackMetaMap, $uncostedVarCogsByProd, $uncostedCatalogQtyByProd) {
            $pid = (int)$row->product_id;
            $pm = $pid > 0 ? ($prodMetaMap[$pid] ?? null) : null;
            $fm = $fallbackMetaMap[$pid] ?? null;
            $catalogUnitCost = $pm
                ? ((float)($pm->cost_price ?? 0) > 0 ? (float)$pm->cost_price : (float)($pm->purchase_price ?? 0))
                : 0.0;
            $fallbackCost = ($uncostedVarCogsByProd[$pid] ?? 0.0)
                + (($uncostedCatalogQtyByProd[$pid] ?? 0.0) * $catalogUnitCost);
            return (object)[
                'product_id'       => $pid,
                'code'             => $pm ? ($pm->code ?: ($fm->fallback_code ?? 'N/A')) : ($fm->fallback_code ?? 'N/A'),
                'name'             => $pm ? ($pm->name ?: ($fm->fallback_name ?? 'Unnamed Product')) : ($fm->fallback_name ?? 'Unnamed Product'),
                'alternative_name' => $pm ? (string)($pm->alternative_name ?? '') : '',
                'category_name'    => $pm ? ($pm->category_name ?: 'General') : 'General',
                'units_sold'       => $row->units_sold,
                'gross_revenue'    => $row->gross_revenue,
                'total_cogs'       => (float)$row->total_cogs + $fallbackCost,
            ];
        });

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
            foreach ($retOnlyIds as $rPid) {
                $pObj = $prodMetaMap[$rPid] ?? null;
                if (!empty($search) && $pObj) {
                    $sq = strtolower($search);
                    if (!str_contains(strtolower((string)$pObj->name), $sq)
                        && !str_contains(strtolower((string)$pObj->code), $sq)
                        && !str_contains(strtolower((string)($pObj->alternative_name ?? '')), $sq)) {
                        continue;
                    }
                }
                if ($category !== 'All' && !empty($category) && (!$pObj || (string)$pObj->category_name !== (string)$category)) {
                    continue;
                }
                $rInfo = $retByProd[$rPid];
                $rCg = round((float)($retCogsByProd[$rPid] ?? 0.0), 2);
                $uSold = -round((float)$rInfo['qty'], 4);
                $rev = -round((float)$rInfo['rev'], 2);
                $cg = -$rCg;
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

        // Filter by margin_health if requested & accumulate filtered accounting totals
        $totalRevenue = 0.0;
        $totalCogs = 0.0;
        $totalProfit = 0.0;
        $totalUnits = 0.0;
        $preItems = [];
        foreach ($adjustedRaw as $it) {
            if ($category !== 'All' && !empty($category) && (string)$it->category_name !== (string)$category) {
                continue;
            }
            if (!empty($search)) {
                $sq = mb_strtolower($search);
                $hay = mb_strtolower((string)$it->name . ' ' . (string)$it->code . ' ' . (string)$it->alternative_name);
                if (!str_contains($hay, $sq)) {
                    continue;
                }
            }
            $rev = (float)$it->gross_revenue;
            $cg = (float)$it->total_cogs;
            $uSold = (float)$it->units_sold;
            $gp = $rev - $cg;
            // Signed ratios preserve true product unit economics on return-only rows (e.g. rev=-1000, cg=-600, gp=-400 => +40% margin, NOT negative)
            $marginPct = abs($rev) > 0.0001 ? round(($gp / $rev) * 100, 2) : 0.0;
            $markupPct = abs($cg) > 0.0001 ? round(($gp / $cg) * 100, 2) : 0.0;
            $isLoss = $rev < -0.0001 ? ($marginPct < -0.005) : ($gp < -0.005);

            if ($marginHealth === 'high' && ($isLoss || $marginPct < 30.0)) {
                continue;
            } elseif ($marginHealth === 'moderate' && ($isLoss || $marginPct < 15.0 || $marginPct >= 30.0)) {
                continue;
            } elseif ($marginHealth === 'low' && ($isLoss || $marginPct >= 15.0)) {
                continue;
            } elseif ($marginHealth === 'negative' && !$isLoss) {
                continue;
            }

            $totalRevenue += $rev;
            $totalCogs += $cg;
            $totalProfit += $gp;
            $totalUnits += $uSold;

            $preItems[] = [
                'product_id' => $it->product_id,
                'code' => $it->code,
                'name' => $it->name,
                'alternative_name' => (string)($it->alternative_name ?? ''),
                'category_name' => $it->category_name,
                'units_sold' => $uSold,
                'avg_selling_price' => abs($uSold) > 0.0001 ? round(abs($rev / $uSold), 2) : 0.0,
                'avg_cost_price' => abs($uSold) > 0.0001 ? round(abs($cg / $uSold), 2) : 0.0,
                'gross_revenue' => round($rev, 2),
                'cogs' => round($cg, 2),
                'gross_profit' => round($gp, 2),
                'gross_margin_pct' => $marginPct,
                'markup_pct' => $markupPct,
            ];
        }

        $items = [];
        foreach ($preItems as $row) {
            $row['contribution_pct'] = $totalProfit > 0 ? round(($row['gross_profit'] / $totalProfit) * 100, 2) : 0.0;
            $items[] = $row;
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

        $availableFilters = null;
        if (!$skipSummary) {
            $whListQ = DB::table('outlets')->orderBy('name');
            if (!empty($allowedOutlets)) {
                $whListQ->whereIn('id', $allowedOutlets);
            }
            $availWarehouses = $whListQ->pluck('name')->filter(fn ($v) => !empty($v))->values()->all();
            $availCategories = DB::table('products')
                ->whereNull('deleted_at')
                ->whereNotNull('category_name')
                ->where('category_name', '!=', '')
                ->distinct()
                ->orderBy('category_name')
                ->pluck('category_name')
                ->values()
                ->all();
            $availableFilters = [
                'warehouses' => $availWarehouses,
                'categories' => $availCategories,
                'health_levels' => ['All', 'high', 'moderate', 'low', 'negative'],
            ];
        }

        $payload = [
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
                'margin_health' => $marginHealth,
            ],
            'data' => $pagedItems,
            'pagination' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $totalProducts,
                'has_more'     => $hasMore,
            ],
        ];
        if ($availableFilters !== null) {
            $payload['available_filters'] = $availableFilters;
        }

        return $this->noStoreJson($payload);
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
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';
        $canViewCost = $accounting->canViewCost($request);
        $allowedOutlets = $this->extractAllowedOutletIds($request);

        // Resolve outlet/warehouse ID if specified
        $warehouseId = null;
        $unmatchedExplicitWh = false;
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $matchedOutlet = DB::table('outlets')
                ->where('name', $warehouse)
                ->when(is_numeric($warehouse), fn($q) => $q->orWhere('id', (int)$warehouse))
                ->first();
            if ($matchedOutlet) {
                $warehouseId = $matchedOutlet->id;
                $warehouse = $matchedOutlet->name;
            } else {
                $unmatchedExplicitWh = true;
            }
        }
        if (!empty($allowedOutlets) && ($unmatchedExplicitWh || ($warehouseId !== null && !in_array((int)$warehouseId, $allowedOutlets, true)))) {
            return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
        }

        $buildAvailableFilters = function () use ($allowedOutlets) {
            $whs = DB::table('outlets')
                ->when(!empty($allowedOutlets), fn($q) => $q->whereIn('id', $allowedOutlets))
                ->orderBy('name')
                ->pluck('name')
                ->map(fn($n) => trim((string)$n))
                ->filter(fn($n) => $n !== '')
                ->unique()
                ->values()
                ->all();
            $cats = DB::table('products')
                ->whereNull('deleted_at')
                ->whereNotNull('category_name')
                ->where('category_name', '!=', '')
                ->distinct()
                ->orderBy('category_name')
                ->pluck('category_name')
                ->map(fn($n) => trim((string)$n))
                ->filter(fn($n) => $n !== '')
                ->unique()
                ->values()
                ->all();
            return [
                'warehouses' => $whs,
                'categories' => $cats,
                'velocity_statuses' => ['All', 'dead', 'slow', 'critical'],
            ];
        };

        if ($unmatchedExplicitWh) {
            $emptyPayload = [
                'status' => 'success',
                'cost_masked' => !$canViewCost,
                'summary' => [
                    'cost_masked' => !$canViewCost,
                    'total_dead_products' => 0,
                    'total_dead_units' => 0.0,
                    'total_tied_up_capital' => 0.0,
                    'slow_moving_capital' => 0.0,
                    'dead_stock_capital' => 0.0,
                    'total_potential_revenue' => 0.0,
                    'threshold_days' => $daysInactive,
                    'as_of_date' => Carbon::today()->toDateString(),
                    'warehouse' => $warehouse,
                ],
                'data' => [],
                'pagination' => [
                    'current_page' => $page,
                    'last_page'    => 1,
                    'per_page'     => $perPage,
                    'total'        => 0,
                    'has_more'     => false,
                ],
            ];
            if (!$skipSummary) {
                $emptyPayload['available_filters'] = $buildAvailableFilters();
            }
            return $this->noStoreJson($emptyPayload);
        }

        // 1. Fetch ONLY compact numeric columns using covering index idx_prod_ds_cover (defer 8 VARCHAR columns to paged 50 rows!)
        $prodQuery = DB::table('products')
            ->whereNull('products.deleted_at');

        $selectFields = [
            'products.id',
            'products.has_variants',
            'products.cost_price',
            'products.purchase_price',
            'products.selling_price',
            'products.retail_price',
            'products.created_at',
        ];

        if ($warehouseId !== null || !empty($allowedOutlets)) {
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

        if ($products->isEmpty()) {
            $emptyPayload = [
                'status' => 'success',
                'cost_masked' => !$canViewCost,
                'summary' => [
                    'cost_masked' => !$canViewCost,
                    'total_dead_products' => 0,
                    'total_dead_units' => 0.0,
                    'total_tied_up_capital' => 0.0,
                    'slow_moving_capital' => 0.0,
                    'dead_stock_capital' => 0.0,
                    'total_potential_revenue' => 0.0,
                    'threshold_days' => $daysInactive,
                    'as_of_date' => Carbon::today()->toDateString(),
                    'warehouse' => $warehouse,
                ],
                'data' => [],
                'pagination' => [
                    'current_page' => $page,
                    'last_page'    => 1,
                    'per_page'     => $perPage,
                    'total'        => 0,
                    'has_more'     => false,
                ],
            ];
            if (!$skipSummary) {
                $emptyPayload['available_filters'] = $buildAvailableFilters();
            }
            return $this->noStoreJson($emptyPayload);
        }

        $allProductIds = [];
        $variantTargetIds = [];
        foreach ($products as $p) {
            $pid = (int)$p->id;
            $allProductIds[] = $pid;
            $needsVariantMeta = !empty($p->has_variants)
                || ((float)($p->cost_price ?? 0) <= 0 && (float)($p->purchase_price ?? 0) <= 0)
                || ((float)($p->selling_price ?? 0) <= 0 && (float)($p->retail_price ?? 0) <= 0);
            if ($needsVariantMeta) {
                $variantTargetIds[] = $pid;
            }
        }
        $narrowIds = count($allProductIds) <= 500 ? $allProductIds : null;

        // 2. Last sale date per product (completed active sales only; Draft/Pending/Voided must NOT reset idle timer)
        $saleQuery = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->select('sale_items.product_id', DB::raw('MAX(sales.date) as last_sale_date'));
        $this->applyActiveDocumentFilter($saleQuery, 'sales.status');
        if ($narrowIds !== null) {
            $saleQuery->whereIn('sale_items.product_id', $narrowIds);
        }
        if (!empty($allowedOutlets)) {
            $saleQuery->whereIn('sales.warehouse_id', $allowedOutlets);
        }
        if ($warehouseId !== null) {
            $saleQuery->where(function ($q) use ($warehouseId, $warehouse) {
                $q->where('sales.warehouse_id', $warehouseId)
                  ->orWhere(function ($legacy) use ($warehouse) {
                      $legacy->whereNull('sales.warehouse_id')->where('sales.warehouse_name', $warehouse);
                  });
            });
        }
        $lastSales = $saleQuery->groupBy('sale_items.product_id')->pluck('last_sale_date', 'product_id');

        // 3. Active non-deleted variant cost/retail fallback & quantity-weighted variant stock valuation (ONLY if needed!)
        $variantFallback = [];
        if (!empty($variantTargetIds) && $accounting->schemaHasTable('product_variants')) {
            $pvQ = DB::table('product_variants')->whereNull('deleted_at');
            if (count($variantTargetIds) <= 500) {
                $pvQ->whereIn('product_id', $variantTargetIds);
            }
            foreach ($pvQ
                ->selectRaw('product_id, AVG(CASE WHEN cost_price > 0 THEN cost_price ELSE NULL END) as avg_cost, AVG(CASE WHEN selling_price > 0 THEN selling_price ELSE NULL END) as avg_sell')
                ->groupBy('product_id')
                ->get() as $pvRow) {
                $variantFallback[(int)$pvRow->product_id] = [
                    'avg_cost' => (float)($pvRow->avg_cost ?? 0.0),
                    'avg_sell' => (float)($pvRow->avg_sell ?? 0.0),
                ];
            }

            if ($accounting->schemaHasTable('stock_balances')) {
                $wbVarQuery = DB::table('stock_balances as sb')
                    ->join('product_variants as pv', 'pv.id', '=', 'sb.product_variant_id')
                    ->whereNull('pv.deleted_at')
                    ->where('sb.quantity', '>', 0);
                if (count($variantTargetIds) <= 500) {
                    $wbVarQuery->whereIn('sb.product_id', $variantTargetIds);
                }
                if ($warehouseId !== null) {
                    $wbVarQuery->where('sb.warehouse_id', $warehouseId);
                } elseif (!empty($allowedOutlets)) {
                    $wbVarQuery->whereIn('sb.warehouse_id', $allowedOutlets);
                }
                foreach ($wbVarQuery
                    ->selectRaw('sb.product_id, SUM(sb.quantity) as v_qty, SUM(sb.quantity * COALESCE(NULLIF(pv.cost_price, 0), 0)) as v_cost_val, SUM(sb.quantity * COALESCE(NULLIF(pv.selling_price, 0), 0)) as v_sell_val')
                    ->groupBy('sb.product_id')
                    ->get() as $wvr) {
                    $vQty = (float)($wvr->v_qty ?? 0.0);
                    if ($vQty > 0.0001) {
                        $pid = (int)$wvr->product_id;
                        $wCost = (float)($wvr->v_cost_val ?? 0.0) / $vQty;
                        $wSell = (float)($wvr->v_sell_val ?? 0.0) / $vQty;
                        if ($wCost > 0) $variantFallback[$pid]['avg_cost'] = $wCost;
                        if ($wSell > 0) $variantFallback[$pid]['avg_sell'] = $wSell;
                    }
                }
            }
        }

        // 4. Authoritative IAS 2 FIFO remaining batch subledger per product (reconciles Tied-Up Capital with Stock Valuation)
        $fifoByProduct = [];
        if ($accounting->schemaHasTable('inventory_batches')) {
            $fifoQ = DB::table('inventory_batches')
                ->where('status', 'active')
                ->where('remaining_quantity', '>', 0.0001);
            if ($narrowIds !== null) {
                $fifoQ->whereIn('product_id', $narrowIds);
            }
            if ($warehouseId !== null) {
                $fifoQ->where('warehouse_id', $warehouseId);
            } elseif (!empty($allowedOutlets)) {
                $fifoQ->whereIn('warehouse_id', $allowedOutlets);
            }
            foreach ($fifoQ
                ->selectRaw('product_id, SUM(remaining_quantity) as fifo_qty, SUM(remaining_quantity * unit_cost) as fifo_val')
                ->groupBy('product_id')
                ->get() as $fb) {
                $fifoByProduct[(int)$fb->product_id] = [
                    'qty' => (float)$fb->fifo_qty,
                    'val' => (float)$fb->fifo_val,
                ];
            }
        }

        $items = [];
        $totalTiedUpCapital = 0.0;
        $totalDeadUnits = 0.0;
        $slowMovingCapital = 0.0;
        $deadStockCapital = 0.0;
        $totalPotentialRevenue = 0.0;
        $todayMidnight = Carbon::today();
        $nowDate = $todayMidnight->toDateString();
        $todayUtcEpoch = strtotime($nowDate . ' UTC');

        foreach ($products as $p) {
            $pId = (int)$p->id;
            $lastSaleRaw = $lastSales[$pId] ?? null;

            $daysSince = 999;
            $lastSaleFormatted = 'Never Sold';

            if ($lastSaleRaw) {
                $lastDateStr = substr((string)$lastSaleRaw, 0, 10);
                $lastSaleFormatted = $lastDateStr;
                $lastEpoch = strtotime($lastDateStr . ' UTC');
                $daysSince = $lastEpoch !== false ? max(0, (int)(($todayUtcEpoch - $lastEpoch) / 86400)) : 999;
            } else {
                $createDateStr = substr((string)($p->created_at ?: $nowDate), 0, 10);
                $createEpoch = strtotime($createDateStr . ' UTC');
                $daysSince = $createEpoch !== false ? max(0, (int)(($todayUtcEpoch - $createEpoch) / 86400)) : 999;
            }

            if ($daysSince >= $daysInactive) {
                $qty = (float)($p->stock_quantity ?? 0);
                $pvMeta = $variantFallback[$pId] ?? ['avg_cost' => 0.0, 'avg_sell' => 0.0];
                $pCost = (float)($p->cost_price ?? 0) > 0
                    ? (float)$p->cost_price
                    : ((float)($p->purchase_price ?? 0) > 0 ? (float)$p->purchase_price : $pvMeta['avg_cost']);
                if (!empty($p->has_variants) && $pvMeta['avg_cost'] > 0) {
                    $pCost = $pvMeta['avg_cost'];
                }

                $retail = (float)($p->selling_price ?? 0) > 0
                    ? (float)$p->selling_price
                    : ((float)($p->retail_price ?? 0) > 0 ? (float)$p->retail_price : $pvMeta['avg_sell']);
                if (!empty($p->has_variants) && $pvMeta['avg_sell'] > 0) {
                    $retail = $pvMeta['avg_sell'];
                }

                $fb = $fifoByProduct[$pId] ?? null;
                if ($fb !== null && $fb['qty'] > 0.0001 && $qty > 0.0001) {
                    $coveredQty = min($qty, $fb['qty']);
                    $unbatchedQty = max(0.0, $qty - $fb['qty']);
                    $avgFifoCost = $fb['val'] / $fb['qty'];
                    $capital = ($coveredQty * $avgFifoCost) + ($unbatchedQty * $pCost);
                    $cost = $capital / $qty;
                } else {
                    $cost = $pCost;
                    $capital = $qty * $cost;
                }
                $revenue = $qty * $retail;

                $isDead = ($daysSince >= 90);
                $isCritical = ($daysSince >= 180 || ($lastSaleRaw === null && $daysSince >= 120));
                $velocityStatus = $isDead ? 'dead' : 'slow';

                if ($velocityStatusFilter === 'dead' && !$isDead) {
                    continue;
                }
                if ($velocityStatusFilter === 'slow' && $isDead) {
                    continue;
                }
                if ($velocityStatusFilter === 'critical' && !$isCritical) {
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

                $action = 'Promotional Discount (15% Off)';
                if ($isCritical) {
                    $action = 'Clearance / Liquidation (50% Off)';
                } elseif ($daysSince >= 90) {
                    $action = 'Bundle Promo / Markdown (30% Off)';
                } elseif ($daysSince < 60) {
                    $action = 'Shelf Reposition / Transfer';
                }

                $items[] = [
                    'product_id' => $pId,
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
        $slicedItems = array_slice($items, $offset, $perPage);
        $hasMore = ($offset + count($slicedItems)) < $totalDeadProducts;

        // Hydrate VARCHAR display metadata ONLY for the <= 50 sliced page products
        $pagedIds = array_column($slicedItems, 'product_id');
        $prodMetaMap = !empty($pagedIds)
            ? DB::table('products')
                ->whereIn('id', $pagedIds)
                ->get(['id', 'code', 'sku', 'barcode', 'name', 'alternative_name', 'category_name', 'brand_name', 'unit_name'])
                ->keyBy('id')
            : collect();

        $whLabel = $warehouse !== 'All' ? $warehouse : 'All Outlets & Warehouses';
        $pagedItems = array_map(function ($row) use ($prodMetaMap, $whLabel, $canViewCost) {
            $meta = $prodMetaMap->get($row['product_id']);
            $code = (string)($meta->code ?? '');
            $hydrated = [
                'product_id' => $row['product_id'],
                'code' => $code,
                'sku' => !empty($meta->sku) ? (string)$meta->sku : $code,
                'barcode' => (string)($meta->barcode ?? ''),
                'name' => (string)($meta->name ?? ''),
                'alternative_name' => (string)($meta->alternative_name ?? ''),
                'category_name' => !empty($meta->category_name) ? (string)$meta->category_name : 'General',
                'brand_name' => (string)($meta->brand_name ?? ''),
                'warehouse_name' => $whLabel,
                'unit_name' => !empty($meta->unit_name) ? (string)$meta->unit_name : 'Pcs',
                'stock_quantity' => $row['stock_quantity'],
                'cost_price' => $row['cost_price'],
                'selling_price' => $row['selling_price'],
                'tied_up_capital' => $row['tied_up_capital'],
                'potential_revenue' => $row['potential_revenue'],
                'days_inactive' => $row['days_inactive'],
                'last_sale_date' => $row['last_sale_date'],
                'velocity_status' => $row['velocity_status'],
                'recommended_action' => $row['recommended_action'],
            ];
            if (!$canViewCost) {
                $masked = $this->maskAssocNumericKeys($hydrated, ['cost_price', 'tied_up_capital'], null);
                $masked['cost_masked'] = true;
                return $masked;
            }
            return $hydrated;
        }, $slicedItems);

        $payload = [
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
                'as_of_date' => $nowDate,
                'warehouse' => $warehouse,
                'velocity_status' => $velocityStatusFilter,
            ],
            'data' => $pagedItems,
            'pagination' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $totalDeadProducts,
                'has_more'     => $hasMore,
            ],
        ];
        if (!$skipSummary) {
            $payload['available_filters'] = $buildAvailableFilters();
        }
        return $this->noStoreJson($payload);
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
        $skipSummary = $request->boolean('skip_summary') || $request->query('skip_summary') === '1';

        $allowedOutlets = $this->extractAllowedOutletIds($request);

        $warehouseId = null;
        $unmatchedExplicitWh = false;
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $matchedOutlet = DB::table('outlets')
                ->where('name', $warehouse)
                ->when(is_numeric($warehouse), fn($q) => $q->orWhere('id', (int)$warehouse))
                ->first();
            if ($matchedOutlet) {
                $warehouseId = $matchedOutlet->id;
                $warehouse = $matchedOutlet->name;
            } else {
                $unmatchedExplicitWh = true;
            }
        }
        if (!empty($allowedOutlets) && ($unmatchedExplicitWh || ($warehouseId !== null && !in_array((int)$warehouseId, $allowedOutlets, true)))) {
            return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
        }

        $applyWhScope = function ($q, string $table = '') use ($warehouseId, $warehouse) {
            if ($warehouse === 'All' || empty($warehouse)) {
                return;
            }
            $idCol = $table !== '' ? "{$table}.warehouse_id" : 'warehouse_id';
            $nameCol = $table !== '' ? "{$table}.warehouse_name" : 'warehouse_name';
            $q->where(function ($sub) use ($idCol, $nameCol, $warehouseId, $warehouse) {
                if ($warehouseId !== null) {
                    $sub->where($idCol, $warehouseId)
                        ->orWhere(function ($legacy) use ($idCol, $nameCol, $warehouse) {
                            $legacy->whereNull($idCol)->where($nameCol, $warehouse);
                        });
                } else {
                    $sub->where($nameCol, $warehouse);
                }
            });
        };

        $query = DB::table('sales');
        $this->applyReportDateRange($query, $startDate, $endDate, 'date');
        $this->applyActiveDocumentFilter($query, 'sales.status');
        if (!empty($allowedOutlets)) {
            $query->whereIn('sales.warehouse_id', $allowedOutlets);
        }
        $applyWhScope($query, 'sales');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%");
            });
        }

        $saleCashCond = "(LOWER(TRIM(COALESCE(sales.payment_type, ''))) LIKE '%cash%' AND LOWER(TRIM(COALESCE(sales.payment_type, ''))) NOT LIKE '%non%cash%' AND LOWER(TRIM(COALESCE(sales.payment_type, ''))) NOT LIKE '%cashless%')";
        $noPayRowsSql = "NOT EXISTS (SELECT 1 FROM sale_payments sp WHERE sp.sale_id = sales.id)";
        $normNameWh = function (?string $userName, ?string $createdBy, ?string $warehouseName): array {
            $u = trim((string)($userName ?? ''));
            $c = trim((string)($createdBy ?? ''));
            $w = trim((string)($warehouseName ?? ''));
            return [
                $u !== '' ? $u : ($c !== '' ? $c : 'Unknown Staff'),
                $w !== '' ? $w : 'Main Branch Outlet',
            ];
        };

        $siDiscSub = DB::table('sale_items')
            ->where('discount', '>', 0)
            ->select('sale_id', DB::raw('COALESCE(SUM(discount), 0) as item_discount_sum'))
            ->groupBy('sale_id');

        $cashiers = collect();
        foreach ($query->leftJoinSub($siDiscSub, 'sid', 'sid.sale_id', '=', 'sales.id')->selectRaw("
            sales.user_name,
            sales.created_by,
            sales.warehouse_name,
            COALESCE(MAX(NULLIF(TRIM(sales.user_role), '')), 'Cashier') as role,
            COUNT(sales.id) as total_bills,
            SUM(sales.grand_total) as net_revenue,
            SUM(sales.paid_amount) as total_collected,
            SUM(sales.due_amount) as total_due,
            SUM(COALESCE(sales.discount, 0) + COALESCE(sid.item_discount_sum, 0)) as total_discount,
            SUM(sales.tax_amount) as total_tax,
            SUM(sales.subtotal) as total_subtotal,
            SUM(CASE WHEN {$noPayRowsSql} AND {$saleCashCond} THEN sales.paid_amount ELSE 0 END) as fallback_cash,
            SUM(CASE WHEN {$noPayRowsSql} AND NOT {$saleCashCond} THEN sales.paid_amount ELSE 0 END) as fallback_non_cash
        ")
        ->groupBy('sales.user_name', 'sales.created_by', 'sales.warehouse_name')
        ->get() as $r) {
            [$cName, $wName] = $normNameWh($r->user_name, $r->created_by, $r->warehouse_name);
            $cKey = $cName . '|' . $wName;
            if ($cashiers->has($cKey)) {
                $existing = $cashiers->get($cKey);
                if (($existing->role === 'Cashier' || empty($existing->role)) && !empty($r->role)) {
                    $existing->role = $r->role;
                }
                $existing->total_bills = (int)$existing->total_bills + (int)$r->total_bills;
                $existing->net_revenue = (float)$existing->net_revenue + (float)$r->net_revenue;
                $existing->total_collected = (float)$existing->total_collected + (float)$r->total_collected;
                $existing->total_due = (float)$existing->total_due + (float)$r->total_due;
                $existing->total_discount = (float)$existing->total_discount + (float)$r->total_discount;
                $existing->total_tax = (float)$existing->total_tax + (float)$r->total_tax;
                $existing->total_subtotal = (float)$existing->total_subtotal + (float)$r->total_subtotal;
                $existing->fallback_cash = (float)$existing->fallback_cash + (float)$r->fallback_cash;
                $existing->fallback_non_cash = (float)$existing->fallback_non_cash + (float)$r->fallback_non_cash;
            } else {
                $r->cashier_name = $cName;
                $r->warehouse_name = $wName;
                $cashiers->put($cKey, $r);
            }
        }

        // SARGable multi-tender split from sale_payments attributed to the COLLECTING cashier (sp.user_name / sp.created_by fallback to sales)
        $payFromDt = !empty($startDate) ? $accounting->normalizeFilterBoundaryDate((string)$startDate, false) : null;
        $payToDt = !empty($endDate) ? $accounting->normalizeFilterBoundaryDate((string)$endDate, true) : null;
        $payLegCashCond = "(LOWER(TRIM(COALESCE(sp.payment_type, ''))) LIKE '%cash%' AND LOWER(TRIM(COALESCE(sp.payment_type, ''))) NOT LIKE '%non%cash%' AND LOWER(TRIM(COALESCE(sp.payment_type, ''))) NOT LIKE '%cashless%')";
        $hasSpUserName = $accounting->schemaHasColumn('sale_payments', 'user_name');
        $hasSpCreatedBy = $accounting->schemaHasColumn('sale_payments', 'created_by');
        $spCollectorNameExpr = $hasSpUserName && $hasSpCreatedBy
            ? "COALESCE(NULLIF(TRIM(sp.user_name), ''), NULLIF(TRIM(sp.created_by), ''), NULLIF(TRIM(sales.user_name), ''), NULLIF(TRIM(sales.created_by), ''))"
            : ($hasSpUserName
                ? "COALESCE(NULLIF(TRIM(sp.user_name), ''), NULLIF(TRIM(sales.user_name), ''), NULLIF(TRIM(sales.created_by), ''))"
                : ($hasSpCreatedBy
                    ? "COALESCE(NULLIF(TRIM(sp.created_by), ''), NULLIF(TRIM(sales.user_name), ''), NULLIF(TRIM(sales.created_by), ''))"
                    : "COALESCE(NULLIF(TRIM(sales.user_name), ''), NULLIF(TRIM(sales.created_by), ''))"));

        $spQuery = DB::table('sale_payments as sp')
            ->join('sales', 'sales.id', '=', 'sp.sale_id');
        if ($payFromDt !== null) {
            $spQuery->where('sp.payment_date', '>=', $payFromDt);
        }
        if ($payToDt !== null) {
            $spQuery->where('sp.payment_date', '<=', $payToDt);
        }
        $this->applyActiveDocumentFilter($spQuery, 'sales.status');
        if (!empty($allowedOutlets)) {
            $spQuery->whereIn('sales.warehouse_id', $allowedOutlets);
        }
        $applyWhScope($spQuery, 'sales');
        if (!empty($search)) {
            $spQuery->where(function ($q) use ($search, $hasSpUserName, $hasSpCreatedBy) {
                $q->where('sales.created_by', 'like', "%{$search}%")
                  ->orWhere('sales.user_name', 'like', "%{$search}%")
                  ->orWhere('sales.warehouse_name', 'like', "%{$search}%")
                  ->orWhere('sales.user_role', 'like', "%{$search}%");
                if ($hasSpUserName) $q->orWhere('sp.user_name', 'like', "%{$search}%");
                if ($hasSpCreatedBy) $q->orWhere('sp.created_by', 'like', "%{$search}%");
            });
        }
        $spGroupCols = [];
        $spSelectCols = [];
        if ($hasSpUserName) {
            $spSelectCols[] = 'sp.user_name as sp_user_name';
            $spGroupCols[] = 'sp.user_name';
        }
        if ($hasSpCreatedBy) {
            $spSelectCols[] = 'sp.created_by as sp_created_by';
            $spGroupCols[] = 'sp.created_by';
        }
        $spSelectCols[] = 'sales.user_name as sale_user_name';
        $spSelectCols[] = 'sales.created_by as sale_created_by';
        $spSelectCols[] = 'sales.warehouse_name';
        $spGroupCols[] = 'sales.user_name';
        $spGroupCols[] = 'sales.created_by';
        $spGroupCols[] = 'sales.warehouse_name';
        $spSelectPrefix = implode(",\n            ", $spSelectCols);

        $tenderMap = collect();
        foreach ($spQuery->selectRaw("
            {$spSelectPrefix},
            SUM(CASE WHEN {$payLegCashCond} THEN sp.amount ELSE 0 END) as cash_collected,
            SUM(CASE WHEN NOT {$payLegCashCond} THEN sp.amount ELSE 0 END) as non_cash_collected
        ")
        ->groupBy($spGroupCols)
        ->get() as $sr) {
            $collRaw = trim((string)($sr->sp_user_name ?? ''));
            if ($collRaw === '') {
                $collRaw = trim((string)($sr->sp_created_by ?? ''));
            }
            [$cName, $wName] = $normNameWh(
                $collRaw !== '' ? $collRaw : ($sr->sale_user_name ?? null),
                $sr->sale_created_by ?? null,
                $sr->warehouse_name
            );
            $nk = $cName . '|' . $wName;
            if ($tenderMap->has($nk)) {
                $existing = $tenderMap->get($nk);
                $existing->cash_collected = (float)$existing->cash_collected + (float)$sr->cash_collected;
                $existing->non_cash_collected = (float)$existing->non_cash_collected + (float)$sr->non_cash_collected;
            } else {
                $sr->cashier_name = $cName;
                $sr->warehouse_name = $wName;
                $tenderMap->put($nk, $sr);
            }
        }

        if (DB::table('sale_payments')->whereNull('payment_date')->limit(1)->exists()) {
            $nullSpQ = DB::table('sale_payments as sp')
                ->join('sales', 'sales.id', '=', 'sp.sale_id')
                ->whereNull('sp.payment_date');
            $this->applyReportDateRange($nullSpQ, $startDate, $endDate, 'sales.date');
            $this->applyActiveDocumentFilter($nullSpQ, 'sales.status');
            if (!empty($allowedOutlets)) {
                $nullSpQ->whereIn('sales.warehouse_id', $allowedOutlets);
            }
            $applyWhScope($nullSpQ, 'sales');
            if (!empty($search)) {
                $nullSpQ->where(function ($q) use ($search, $hasSpUserName, $hasSpCreatedBy) {
                    $q->where('sales.created_by', 'like', "%{$search}%")
                      ->orWhere('sales.user_name', 'like', "%{$search}%")
                      ->orWhere('sales.warehouse_name', 'like', "%{$search}%")
                      ->orWhere('sales.user_role', 'like', "%{$search}%");
                    if ($hasSpUserName) $q->orWhere('sp.user_name', 'like', "%{$search}%");
                    if ($hasSpCreatedBy) $q->orWhere('sp.created_by', 'like', "%{$search}%");
                });
            }
            foreach ($nullSpQ->selectRaw("
                {$spSelectPrefix},
                SUM(CASE WHEN {$payLegCashCond} THEN sp.amount ELSE 0 END) as cash_collected,
                SUM(CASE WHEN NOT {$payLegCashCond} THEN sp.amount ELSE 0 END) as non_cash_collected
            ")->groupBy($spGroupCols)->get() as $nr) {
                $collRaw = trim((string)($nr->sp_user_name ?? ''));
                if ($collRaw === '') {
                    $collRaw = trim((string)($nr->sp_created_by ?? ''));
                }
                [$cName, $wName] = $normNameWh(
                    $collRaw !== '' ? $collRaw : ($nr->sale_user_name ?? null),
                    $nr->sale_created_by ?? null,
                    $nr->warehouse_name
                );
                $nk = $cName . '|' . $wName;
                if ($tenderMap->has($nk)) {
                    $existing = $tenderMap->get($nk);
                    $existing->cash_collected = (float)$existing->cash_collected + (float)$nr->cash_collected;
                    $existing->non_cash_collected = (float)$existing->non_cash_collected + (float)$nr->non_cash_collected;
                } else {
                    $nr->cashier_name = $cName;
                    $nr->warehouse_name = $wName;
                    $tenderMap->put($nk, $nr);
                }
            }
        }

        // Aggregate register sessions across the period FIRST (plus open-shift metadata for return attribution)
        $hasCashRegisters = $accounting->schemaHasTable('cash_registers');
        $regAgg = collect();
        $openRegIdsByKey = [];
        $openRegMeta = [];
        $allOpenRegIds = [];
        $latestClosedAtByKey = [];
        if ($hasCashRegisters) {
            $regFromDt = !empty($startDate) ? $accounting->normalizeFilterBoundaryDate((string)$startDate, false) : null;
            $regToDt = !empty($endDate) ? $accounting->normalizeFilterBoundaryDate((string)$endDate, true) : null;
            $regBaseQ = DB::table('cash_registers')
                ->when(!empty($allowedOutlets), fn($q) => $q->whereIn('warehouse_id', $allowedOutlets));
            $applyWhScope($regBaseQ);
            if (!empty($search)) {
                $regBaseQ->where(function ($q) use ($search) {
                    $q->where('user_name', 'like', "%{$search}%")
                      ->orWhere('warehouse_name', 'like', "%{$search}%")
                      ->orWhere('register_name', 'like', "%{$search}%");
                });
            }

            $regDateFilteredQ = (clone $regBaseQ);
            if ($regFromDt !== null && $regToDt !== null) {
                $regDateFilteredQ->where(function ($q) use ($regFromDt, $regToDt) {
                    $q->whereBetween('opened_at', [$regFromDt, $regToDt])
                      ->orWhereBetween('closed_at', [$regFromDt, $regToDt]);
                });
            } elseif ($regFromDt !== null) {
                $regDateFilteredQ->where(function ($q) use ($regFromDt) {
                    $q->where('opened_at', '>=', $regFromDt)
                      ->orWhere('closed_at', '>=', $regFromDt);
                });
            } elseif ($regToDt !== null) {
                $regDateFilteredQ->where(function ($q) use ($regToDt) {
                    $q->where('opened_at', '<=', $regToDt)
                      ->orWhere('closed_at', '<=', $regToDt);
                });
            }

            foreach ($regDateFilteredQ
                ->selectRaw("
                    user_name,
                    warehouse_name,
                    COUNT(id) as total_shifts,
                    SUM(CASE WHEN LOWER(status) = 'closed' THEN 1 ELSE 0 END) as closed_shifts,
                    SUM(CASE WHEN LOWER(status) IN ('open', 'opened') THEN 1 ELSE 0 END) as open_shifts,
                    SUM(CASE WHEN LOWER(status) IN ('open', 'opened') THEN opening_cash ELSE 0 END) as open_opening_cash,
                    SUM(opening_cash) as total_opening_cash,
                    SUM(CASE WHEN LOWER(status) = 'closed' THEN expected_cash ELSE 0 END) as total_expected_cash,
                    SUM(CASE WHEN LOWER(status) = 'closed' THEN closing_cash ELSE 0 END) as total_closing_cash,
                    SUM(CASE WHEN LOWER(status) = 'closed' THEN COALESCE(cash_difference, closing_cash - expected_cash) ELSE 0 END) as total_cash_difference,
                    MAX(CASE WHEN LOWER(status) = 'closed' THEN closed_at ELSE NULL END) as latest_closed_at,
                    MAX(register_name) as last_register_name
                ")
                ->groupBy('user_name', 'warehouse_name')
                ->get() as $rr) {
                [$rUser, $rWh] = $normNameWh($rr->user_name, null, $rr->warehouse_name);
                $rk = $rUser . '|' . $rWh;
                if (!empty($rr->latest_closed_at)) {
                    $prevClosed = $latestClosedAtByKey[$rk] ?? '';
                    if ($prevClosed === '' || (string)$rr->latest_closed_at > $prevClosed) {
                        $latestClosedAtByKey[$rk] = (string)$rr->latest_closed_at;
                    }
                }
                if ($regAgg->has($rk)) {
                    $existing = $regAgg->get($rk);
                    $existing->total_shifts = (int)$existing->total_shifts + (int)$rr->total_shifts;
                    $existing->closed_shifts = (int)$existing->closed_shifts + (int)$rr->closed_shifts;
                    $existing->open_shifts = (int)$existing->open_shifts + (int)$rr->open_shifts;
                    $existing->open_opening_cash = (float)$existing->open_opening_cash + (float)$rr->open_opening_cash;
                    $existing->total_opening_cash = (float)$existing->total_opening_cash + (float)$rr->total_opening_cash;
                    $existing->total_expected_cash = (float)$existing->total_expected_cash + (float)$rr->total_expected_cash;
                    $existing->total_closing_cash = (float)$existing->total_closing_cash + (float)$rr->total_closing_cash;
                    $existing->total_cash_difference = (float)$existing->total_cash_difference + (float)$rr->total_cash_difference;
                    if (!empty($rr->last_register_name)) {
                        $existing->last_register_name = $rr->last_register_name;
                    }
                } else {
                    $rr->cashier_name = $rUser;
                    $rr->warehouse_name = $rWh;
                    $regAgg->put($rk, $rr);
                }
            }

            foreach ((clone $regBaseQ)->whereRaw('LOWER(status) IN (?, ?)', ['open', 'opened'])->select(['id', 'user_name', 'warehouse_name', 'opened_at'])->get() as $or) {
                $rid = (int)$or->id;
                $uName = trim((string)($or->user_name ?? '')) ?: 'Unknown Staff';
                $wName = trim((string)($or->warehouse_name ?? '')) ?: 'Main Branch Outlet';
                $oKey = $uName . '|' . $wName;
                $openRegIdsByKey[$oKey][] = $rid;
                $openRegMeta[$rid] = ['key' => $oKey, 'opened_at' => (string)($or->opened_at ?? '')];
                $allOpenRegIds[] = $rid;
            }
        }

        // Batch completed Sale Returns & open-shift cash refunds per cashier & branch
        // Closed-shift refunds are already settled in closed expected_cash; only open-shift or post-close refunds affect open expected_cash.
        $retByCashier = [];
        $openCashRefundsByKey = [];
        $openCashRefundsByReg = [];
        if ($accounting->schemaHasTable('sale_returns')) {
            $retAuditQ = DB::table('sale_returns');
            $this->applyReportDateRange($retAuditQ, $startDate, $endDate, 'date');
            $this->applyActiveDocumentFilter($retAuditQ, 'status');
            if (!empty($allowedOutlets)) {
                $retAuditQ->whereIn('warehouse_id', $allowedOutlets);
            }
            $applyWhScope($retAuditQ);
            if (!empty($search)) {
                $retAuditQ->where(function ($q) use ($search) {
                    $q->where('created_by', 'like', "%{$search}%")
                      ->orWhere('user_name', 'like', "%{$search}%")
                      ->orWhere('warehouse_name', 'like', "%{$search}%");
                });
            }

            $hasAnySplitRet = false;
            $retEffTenderSql = "LOWER(TRIM(COALESCE(NULLIF(TRIM(refund_tender), ''), NULLIF(TRIM(payment_type), ''), 'cash')))";
            $retCashCond = "({$retEffTenderSql} LIKE '%cash%' AND {$retEffTenderSql} NOT LIKE '%non%cash%' AND {$retEffTenderSql} NOT LIKE '%cashless%')";
            // Exclude Store Credit / Credit Note / AR credit offsets from bank/digital non-cash refunds!
            $retNonCashSettlementCond = "({$retEffTenderSql} IN ('credit note', 'store credit', 'credit', 'unpaid', 'points', 'loyalty points', 'opening stock', 'none', 'n/a', 'void', 'voided', 'credit settled', 'ar credit', 'ap credit'))";
            foreach ((clone $retAuditQ)
                ->selectRaw("
                    user_name,
                    created_by,
                    warehouse_name,
                    COALESCE(SUM(CASE WHEN payments_payload IS NULL OR payments_payload = '' THEN grand_total ELSE 0 END), 0) as ret_total,
                    COALESCE(SUM(CASE WHEN payments_payload IS NULL OR payments_payload = '' THEN due_amount ELSE 0 END), 0) as ret_open_credit,
                    COALESCE(SUM(CASE WHEN (payments_payload IS NULL OR payments_payload = '') AND paid_amount > 0 AND NOT {$retNonCashSettlementCond} THEN paid_amount ELSE 0 END), 0) as ret_refunded,
                    COALESCE(SUM(CASE WHEN (payments_payload IS NULL OR payments_payload = '') AND paid_amount > 0 AND {$retCashCond} AND NOT {$retNonCashSettlementCond} THEN paid_amount ELSE 0 END), 0) as ret_cash_refunded,
                    COALESCE(SUM(CASE WHEN (payments_payload IS NULL OR payments_payload = '') AND paid_amount > 0 AND NOT {$retCashCond} AND NOT {$retNonCashSettlementCond} THEN paid_amount ELSE 0 END), 0) as ret_non_cash_refunded,
                    MAX(CASE WHEN payments_payload IS NOT NULL AND payments_payload != '' THEN 1 ELSE 0 END) as has_split
                ")->groupBy('user_name', 'created_by', 'warehouse_name')->get() as $srAgg) {
                [$primaryName, $whLabel] = $normNameWh($srAgg->user_name, $srAgg->created_by, $srAgg->warehouse_name);
                $rKey = $primaryName . '|' . $whLabel;
                if (!isset($retByCashier[$rKey])) {
                    $retByCashier[$rKey] = [
                        'cashier_name' => $primaryName,
                        'warehouse_name' => $whLabel,
                        'total' => 0.0,
                        'open_credit' => 0.0,
                        'refunded' => 0.0,
                        'cash_refunded' => 0.0,
                        'non_cash_refunded' => 0.0,
                    ];
                }
                $retByCashier[$rKey]['total'] += (float)$srAgg->ret_total;
                $retByCashier[$rKey]['open_credit'] += (float)($srAgg->ret_open_credit ?? 0.0);
                $retByCashier[$rKey]['refunded'] += (float)$srAgg->ret_refunded;
                $retByCashier[$rKey]['cash_refunded'] += (float)$srAgg->ret_cash_refunded;
                $retByCashier[$rKey]['non_cash_refunded'] += (float)$srAgg->ret_non_cash_refunded;
                if ((int)($srAgg->has_split ?? 0) === 1) {
                    $hasAnySplitRet = true;
                }
            }

            // Single combined cursor for split payments_payload rows and open-shift cash refund attribution
            $needOpenAttribution = !empty($allOpenRegIds);
            if ($hasAnySplitRet || $needOpenAttribution) {
                $openCashierNames = $needOpenAttribution
                    ? array_values(array_unique(array_map(fn($m) => explode('|', $m['key'])[0], $openRegMeta)))
                    : [];
                $retHasRegCol = $needOpenAttribution && $accounting->schemaHasColumn('sale_returns', 'cash_register_id');
                $extraRetCols = ['user_name', 'created_by', 'warehouse_name', 'grand_total', 'paid_amount', 'due_amount', 'refund_tender', 'payment_type', 'payments_payload', 'date', 'created_at'];
                if ($retHasRegCol) {
                    $extraRetCols[] = 'cash_register_id';
                }

                $combinedRetQ = (clone $retAuditQ)->where(function ($outer) use ($hasAnySplitRet, $needOpenAttribution, $openCashierNames) {
                    $hasAny = false;
                    if ($hasAnySplitRet) {
                        $outer->where(function ($sp) {
                            $sp->whereNotNull('payments_payload')->where('payments_payload', '!=', '');
                        });
                        $hasAny = true;
                    }
                    if ($needOpenAttribution) {
                        $method = $hasAny ? 'orWhere' : 'where';
                        $outer->$method(function ($oq) use ($openCashierNames) {
                            $oq->where('paid_amount', '>', 0)
                               ->when(!empty($openCashierNames) && !in_array('Unknown Staff', $openCashierNames, true), function ($q) use ($openCashierNames) {
                                   $q->where(fn($w) => $w->whereIn('user_name', $openCashierNames)->orWhereIn('created_by', $openCashierNames));
                               });
                        });
                    }
                });

                foreach ($combinedRetQ->select($extraRetCols)->cursor() as $sr) {
                    [$primaryName, $whLabel] = $normNameWh($sr->user_name, $sr->created_by, $sr->warehouse_name);
                    $uN = trim((string)($sr->user_name ?? ''));
                    $cN = trim((string)($sr->created_by ?? ''));
                    $rKey = $primaryName . '|' . $whLabel;
                    $isSplitRow = !empty($sr->payments_payload) && trim((string)$sr->payments_payload) !== '';

                    $cashPart = 0.0;
                    $nonCashPart = 0.0;
                    $legs = $isSplitRow ? json_decode((string)$sr->payments_payload, true) : null;
                    if (is_array($legs) && !empty($legs)) {
                        foreach ($legs as $leg) {
                            $lAmt = max(0.0, (float)($leg['amount'] ?? 0));
                            if ($lAmt <= 0.0001) continue;
                            $ptRaw = (string)($leg['payment_type'] ?? ($sr->refund_tender ?: ($sr->payment_type ?: 'cash')));
                            if ($accounting->isNonCashSettlementType($ptRaw)) {
                                continue;
                            }
                            if ($accounting->isCashPaymentType($ptRaw)) {
                                $cashPart += $lAmt;
                            } else {
                                $nonCashPart += $lAmt;
                            }
                        }
                    } elseif ((float)$sr->paid_amount > 0) {
                        $tenderRaw = (string)($sr->refund_tender ?: ($sr->payment_type ?: 'cash'));
                        if (!$accounting->isNonCashSettlementType($tenderRaw)) {
                            if ($accounting->isCashPaymentType($tenderRaw)) {
                                $cashPart = (float)$sr->paid_amount;
                            } else {
                                $nonCashPart = (float)$sr->paid_amount;
                            }
                        }
                    }

                    if ($isSplitRow) {
                        if (!isset($retByCashier[$rKey])) {
                            $retByCashier[$rKey] = [
                                'cashier_name' => $primaryName,
                                'warehouse_name' => $whLabel,
                                'total' => 0.0,
                                'open_credit' => 0.0,
                                'refunded' => 0.0,
                                'cash_refunded' => 0.0,
                                'non_cash_refunded' => 0.0,
                            ];
                        }
                        $retByCashier[$rKey]['total'] += (float)$sr->grand_total;
                        $retByCashier[$rKey]['open_credit'] += (float)($sr->due_amount ?? 0.0);
                        $retByCashier[$rKey]['refunded'] += ($cashPart + $nonCashPart);
                        $retByCashier[$rKey]['cash_refunded'] += $cashPart;
                        $retByCashier[$rKey]['non_cash_refunded'] += $nonCashPart;
                    }

                    if (!$needOpenAttribution || $cashPart <= 0.0001) {
                        continue;
                    }

                    if ($retHasRegCol && !empty($sr->cash_register_id)) {
                        $rrid = (int)$sr->cash_register_id;
                        if (isset($openRegMeta[$rrid])) {
                            $openCashRefundsByReg[$rrid] = ($openCashRefundsByReg[$rrid] ?? 0.0) + $cashPart;
                        }
                        continue;
                    }

                    $retTs = trim((string)($sr->created_at ?? $sr->date ?? ''));
                    $closedCutoff = $latestClosedAtByKey[$rKey] ?? '';
                    if ($closedCutoff !== '' && $retTs !== '' && $retTs <= $closedCutoff) {
                        continue;
                    }
                    $openCashRefundsByKey[$rKey] = ($openCashRefundsByKey[$rKey] ?? 0.0) + $cashPart;
                    if ($uN !== '' && $cN !== '' && $uN !== $cN) {
                        $altK = $cN . '|' . $whLabel;
                        $openCashRefundsByKey[$altK] = ($openCashRefundsByKey[$altK] ?? 0.0) + $cashPart;
                    }
                }
            }
        }

        $openRegTxByReg = [];
        $openRegExpByReg = [];
        $openRegCashSalesByReg = [];
        $openRegPurchCashMap = [];
        if (!empty($allOpenRegIds)) {
            $openRegPurchCashMap = $accounting->summarizeRegisterPurchaseCashFlowBatch($allOpenRegIds);

            // Recompute open-register cash refunds unfiltered by report date range (matching CashRegisterController 1:1,
            // including cross-shift split-register refund legs in payments_payload!)
            if ($accounting->schemaHasTable('sale_returns') && $accounting->schemaHasColumn('sale_returns', 'cash_register_id')) {
                $openCashRefundsByReg = [];
                $openIdSet = array_flip($allOpenRegIds);
                $openRetQ = DB::table('sale_returns')
                    ->where('paid_amount', '>', 0)
                    ->where(function ($q) use ($allOpenRegIds) {
                        $q->whereIn('cash_register_id', $allOpenRegIds)
                          ->orWhere(function ($sp) {
                              $sp->whereNotNull('payments_payload')
                                 ->where('payments_payload', 'like', '%cash_register_id%');
                          });
                    });
                $this->applyActiveDocumentFilter($openRetQ, 'status');
                foreach ($openRetQ->select(['cash_register_id', 'paid_amount', 'refund_tender', 'payment_type', 'payments_payload'])->cursor() as $orRow) {
                    $hdrRid = !empty($orRow->cash_register_id) ? (int)$orRow->cash_register_id : null;
                    $legs = !empty($orRow->payments_payload) ? json_decode((string)$orRow->payments_payload, true) : null;
                    if (is_array($legs) && !empty($legs)) {
                        foreach ($legs as $leg) {
                            if (!is_array($leg)) continue;
                            $lAmt = max(0.0, (float)($leg['amount'] ?? 0));
                            if ($lAmt <= 0.0001) continue;
                            $ptRaw = (string)($leg['payment_type'] ?? ($orRow->refund_tender ?: ($orRow->payment_type ?: 'cash')));
                            if ($accounting->isNonCashSettlementType($ptRaw) || !$accounting->isCashPaymentType($ptRaw)) {
                                continue;
                            }
                            $legRid = !empty($leg['cash_register_id']) ? (int)$leg['cash_register_id'] : $hdrRid;
                            if ($legRid !== null && isset($openIdSet[$legRid])) {
                                $openCashRefundsByReg[$legRid] = ($openCashRefundsByReg[$legRid] ?? 0.0) + $lAmt;
                            }
                        }
                    } elseif ($hdrRid !== null && isset($openIdSet[$hdrRid])) {
                        $ptRaw = (string)($orRow->refund_tender ?: ($orRow->payment_type ?: 'cash'));
                        if (!$accounting->isNonCashSettlementType($ptRaw) && $accounting->isCashPaymentType($ptRaw)) {
                            $openCashRefundsByReg[$hdrRid] = ($openCashRefundsByReg[$hdrRid] ?? 0.0) + (float)$orRow->paid_amount;
                        }
                    }
                }
            }
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
            if ($accounting->schemaHasColumn('sales', 'cash_register_id')) {
                $spHasRegCol = $accounting->schemaHasColumn('sale_payments', 'cash_register_id');
                if ($spHasRegCol) {
                    foreach (array_chunk($allOpenRegIds, 400) as $idChunk) {
                        // Open drawer expected_cash includes ALL shift cash since opening (unfiltered by report date window)
                        foreach (DB::table('sale_payments as sp')
                            ->join('sales', 'sales.id', '=', 'sp.sale_id')
                            ->whereIn('sp.cash_register_id', $idChunk)
                            ->where(fn($q) => $this->applyActiveDocumentFilter($q, 'sales.status'))
                            ->selectRaw("sp.cash_register_id, COALESCE(SUM(CASE WHEN {$payLegCashCond} THEN sp.amount ELSE 0 END), 0) as cash_amt")
                            ->groupBy('sp.cash_register_id')
                            ->get() as $rCash) {
                            $openRegCashSalesByReg[(int)$rCash->cash_register_id] = ($openRegCashSalesByReg[(int)$rCash->cash_register_id] ?? 0.0) + (float)$rCash->cash_amt;
                        }
                    }
                }
                foreach (array_chunk($allOpenRegIds, 400) as $idChunk) {
                    $legacyOpenQ = DB::table('sales')
                        ->whereIn('cash_register_id', $idChunk)
                        ->whereRaw($saleCashCond)
                        ->whereNotExists(function ($sub) {
                            $sub->selectRaw('1')->from('sale_payments as sp')->whereColumn('sp.sale_id', 'sales.id');
                        });
                    $this->applyActiveDocumentFilter($legacyOpenQ, 'status');
                    foreach ($legacyOpenQ
                        ->selectRaw('cash_register_id, COALESCE(SUM(paid_amount), 0) as cash_amt')
                        ->groupBy('cash_register_id')
                        ->get() as $rLegacy) {
                        $openRegCashSalesByReg[(int)$rLegacy->cash_register_id] = ($openRegCashSalesByReg[(int)$rLegacy->cash_register_id] ?? 0.0) + (float)$rLegacy->cash_amt;
                    }
                }
            }
            if ($accounting->schemaHasTable('expense_payments') && $accounting->schemaHasColumn('expense_payments', 'cash_register_id')) {
                $hasExpStatusColAudit = $accounting->schemaHasColumn('expenses', 'status');
                $hasExpPayStatusColAudit = $accounting->schemaHasColumn('expenses', 'payment_status');
                foreach (array_chunk($allOpenRegIds, 400) as $idChunk) {
                    $expAuditQ = DB::table('expense_payments as ep')
                        ->join('expenses as e', 'ep.expense_id', '=', 'e.id')
                        ->whereIn('ep.cash_register_id', $idChunk)
                        ->whereRaw("((LOWER(TRIM(COALESCE(ep.payment_type, ''))) LIKE '%cash%' OR LOWER(TRIM(COALESCE(ep.payment_type, ''))) = 'cod') AND LOWER(TRIM(COALESCE(ep.payment_type, ''))) NOT LIKE '%non%cash%' AND LOWER(TRIM(COALESCE(ep.payment_type, ''))) NOT LIKE '%cashless%')");
                    if ($hasExpStatusColAudit) {
                        $expAuditQ->where(fn($s) => $s->whereNull('e.status')->orWhereNotIn('e.status', ['Voided', 'Draft', 'Pending', 'Cancelled']));
                    }
                    if ($hasExpPayStatusColAudit) {
                        $expAuditQ->where(fn($s) => $s->whereNull('e.payment_status')->orWhereNotIn('e.payment_status', ['Voided', 'Cancelled', 'Draft', 'Pending']));
                    }
                    foreach ($expAuditQ
                        ->selectRaw('ep.cash_register_id, COALESCE(SUM(ep.amount), 0) as amt')
                        ->groupBy('ep.cash_register_id')
                        ->pluck('amt', 'ep.cash_register_id') as $rid => $amt) {
                        $openRegExpByReg[(int)$rid] = (float)$amt;
                    }
                }
            }
        }

        // Resolve ACC-04 blind cash count permission server-side
        $auditUser = $request->user() ?? auth()->user();
        if ($auditUser === null && $request->bearerToken()) {
            $auditUser = User::resolveFromRequest($request);
        }
        $canViewExpectedCash = ($auditUser === null)
            || (method_exists($auditUser, 'isSuperAdmin') && $auditUser->isSuperAdmin())
            || (method_exists($auditUser, 'hasPermission') && $auditUser->hasPermission('cash_register.view_expected'));

        // Union all staff|warehouse keys across Sales, Payment Collectors, Sale Returns, and Register Shifts
        // so cashiers with AR collection, shift, or refund activity and 0 sales are never invisible to fraud audit!
        $allKeys = array_values(array_unique(array_merge(
            $cashiers->keys()->all(),
            $tenderMap->keys()->all(),
            array_keys($retByCashier),
            $regAgg->keys()->all()
        )));

        $items = [];
        $totalStoreSales = 0.0;
        $totalStoreBills = 0;
        $totalStoreDiscounts = 0.0;
        $totalStoreNetRevenue = 0.0;
        $totalStoreCollected = 0.0;
        $totalStoreDue = 0.0;
        $totalStoreCash = 0.0;
        $totalStoreNonCash = 0.0;
        $totalDiscrepancy = 0.0;

        $idx = 1;
        foreach ($allKeys as $key) {
            $c = $cashiers->get($key);
            $cRet = $retByCashier[$key] ?? ['total' => 0.0, 'open_credit' => 0.0, 'refunded' => 0.0, 'cash_refunded' => 0.0, 'non_cash_refunded' => 0.0];
            $reg = $regAgg->get($key);
            $tender = $tenderMap->get($key);

            $cashierName = $c ? $c->cashier_name : ($tender ? $tender->cashier_name : ($cRet['cashier_name'] ?? ($reg->cashier_name ?? 'Unknown Staff')));
            $warehouseName = $c ? $c->warehouse_name : ($tender ? $tender->warehouse_name : ($cRet['warehouse_name'] ?? ($reg->warehouse_name ?? 'Main Branch Outlet')));
            $role = $c ? $c->role : 'Cashier';

            $bills = $c ? (int)$c->total_bills : 0;
            $grossBilled = $c ? (float)$c->net_revenue : 0.0;
            $netRevenue = round($grossBilled - (float)$cRet['total'], 2);
            $discount = $c ? (float)$c->total_discount : 0.0;
            $grossSales = $grossBilled + $discount;
            $grossDue = $c ? (float)$c->total_due : 0.0;
            $due = max(0.0, round($grossDue - (float)($cRet['open_credit'] ?? 0.0), 2));
            $avgTicket = $bills > 0 ? round($grossSales / $bills, 2) : 0.0;
            $discountRate = $grossSales > 0 ? round(($discount / $grossSales) * 100, 1) : 0.0;

            // Tender reconciliation: signed net values ensure Cash + NonCash === Total Collected always (Store Credit excluded)
            $grossCash = ($tender ? (float)$tender->cash_collected : 0.0) + ($c ? (float)$c->fallback_cash : 0.0);
            $grossNonCash = ($tender ? (float)$tender->non_cash_collected : 0.0) + ($c ? (float)$c->fallback_non_cash : 0.0);
            $cash = round($grossCash - (float)$cRet['cash_refunded'], 2);
            $nonCash = round($grossNonCash - (float)$cRet['non_cash_refunded'], 2);
            $collected = round($cash + $nonCash, 2);

            $hasOpenShiftForRow = false;
            if ($reg) {
                $closedCount = (int)$reg->closed_shifts;
                $openCount = (int)$reg->open_shifts;
                $hasOpenShiftForRow = $openCount > 0;
                $closedExpected = (float)$reg->total_expected_cash;
                $closedClosing = (float)$reg->total_closing_cash;
                $diff = (float)$reg->total_cash_difference;

                $openExpected = 0.0;
                if ($openCount > 0) {
                    $openRegIds = $openRegIdsByKey[$key] ?? [];
                    $floatsIn = 0.0;
                    $cashDrops = 0.0;
                    $openCashExpenses = 0.0;
                    $openRegCashDirect = 0.0;
                    $openCashRefundsDirect = 0.0;
                    $openCashPoPaid = 0.0;
                    $openCashPrRefund = 0.0;
                    foreach ($openRegIds as $orid) {
                        $floatsIn += (float)($openRegTxByReg[$orid]['fi'] ?? 0.0);
                        $cashDrops += (float)($openRegTxByReg[$orid]['dr'] ?? 0.0);
                        $openCashExpenses += (float)($openRegExpByReg[$orid] ?? 0.0);
                        $openRegCashDirect += (float)($openRegCashSalesByReg[$orid] ?? 0.0);
                        $openCashRefundsDirect += (float)($openCashRefundsByReg[$orid] ?? 0.0);
                        $openCashPoPaid += (float)($openRegPurchCashMap[$orid]['cash_purchases_paid'] ?? 0.0);
                        $openCashPrRefund += (float)($openRegPurchCashMap[$orid]['cash_purchase_refunds'] ?? 0.0);
                    }
                    $openCashRefunds = $openCashRefundsDirect + (float)($openCashRefundsByKey[$key] ?? 0.0);
                    $openCashBase = $openRegCashDirect > 0.0001
                        ? $openRegCashDirect
                        : ($closedCount === 0 ? $grossCash : 0.0);
                    $openExpected = round((float)$reg->open_opening_cash + $openCashBase + $floatsIn + $openCashPrRefund - $cashDrops - $openCashRefunds - $openCashExpenses - $openCashPoPaid, 2);
                }

                if ($closedCount > 0 && $openCount > 0) {
                    $expectedCash = round($closedExpected + $openExpected, 2);
                    $closingCash = round($closedClosing + $openExpected, 2);
                    $regName = ($reg->last_register_name ?? 'Register 01') . " ({$closedCount} closed, {$openCount} open)";
                } elseif ($closedCount > 0) {
                    $expectedCash = $closedExpected;
                    $closingCash = $closedClosing;
                    $shiftLabel = $closedCount === 1 ? '1 shift' : $closedCount . ' shifts';
                    $regName = ($reg->last_register_name ?? 'Register 01') . ' (' . $shiftLabel . ')';
                } else {
                    $expectedCash = $openExpected;
                    $closingCash = $openExpected;
                    $regName = ($reg->last_register_name ?? 'Register 01') . ' (Open)';
                }
            } else {
                $expectedCash = round($cash, 2);
                $closingCash = round($cash, 2);
                $diff = 0.0;
                $regName = 'Register 01';
            }

            // Audit risk assessment: flag both large shortages AND large overages
            $risk = 'healthy';
            if (abs($diff) > 100.0) {
                $risk = 'critical';
            } elseif ($discountRate > 10.0 || abs($diff) > 0.01) {
                $risk = 'watch';
            }

            if ($riskFilter !== 'All' && $risk !== $riskFilter) {
                continue;
            }

            $maskBlindCount = !$canViewExpectedCash;

            $totalStoreSales += $grossSales;
            $totalStoreBills += $bills;
            $totalStoreDiscounts += $discount;
            $totalStoreNetRevenue += $netRevenue;
            $totalStoreCollected += $collected;
            $totalStoreDue += $due;
            $totalStoreCash += $cash;
            $totalStoreNonCash += $nonCash;
            $totalDiscrepancy += $diff;

            $items[] = [
                'staff_code' => 'STF-' . str_pad($idx++, 3, '0', STR_PAD_LEFT),
                'cashier_name' => $cashierName,
                'role' => $role,
                'warehouse_name' => $warehouseName,
                'register_name' => $regName,
                'total_bills' => $bills,
                'total_sales' => round($grossSales, 2),
                'total_collected' => $collected,
                'total_due' => round($due, 2),
                'total_discount' => round($discount, 2),
                'net_revenue' => $netRevenue,
                'discount_rate' => $discountRate,
                'average_bill_value' => $avgTicket,
                'cash_collected' => $cash,
                'non_cash_collected' => $nonCash,
                'expected_cash' => $maskBlindCount ? null : round($expectedCash, 2),
                'counted_cash' => ($maskBlindCount && $hasOpenShiftForRow) ? null : round($closingCash, 2),
                'cash_difference' => $maskBlindCount ? null : round($diff, 2),
                'blind_count_masked' => $maskBlindCount,
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

        // Batched recent transactions (up to 15) strictly for sliced page cashiers with bills > 0
        $pagedWithBills = array_filter($pagedItems, fn($it) => ($it['total_bills'] ?? 0) > 0);
        if (!empty($pagedWithBills)) {
            $pagedKeyIdx = [];
            $pagedNames = [];
            $bucketCaps = [];
            $remainingBuckets = 0;
            $includesUnknown = false;
            foreach ($pagedItems as $pIdx => $pItem) {
                $tb = (int)($pItem['total_bills'] ?? 0);
                if ($tb <= 0) continue;
                $cKey = $pItem['cashier_name'] . '|' . $pItem['warehouse_name'];
                $pagedKeyIdx[$cKey][] = $pIdx;
                $pagedNames[$pItem['cashier_name']] = true;
                if ($pItem['cashier_name'] === 'Unknown Staff') {
                    $includesUnknown = true;
                }
                $bucketCaps[$pIdx] = min(15, $tb);
                $remainingBuckets++;
            }
            $nameList = array_keys($pagedNames);
            $txsQuery = DB::table('sales');
            $this->applyReportDateRange($txsQuery, $startDate, $endDate, 'date');
            $this->applyActiveDocumentFilter($txsQuery, 'sales.status');
            if (!empty($allowedOutlets)) {
                $txsQuery->whereIn('sales.warehouse_id', $allowedOutlets);
            }
            $applyWhScope($txsQuery, 'sales');
            $txsQuery->where(function ($q) use ($nameList, $includesUnknown) {
                $q->whereIn('user_name', $nameList)->orWhereIn('created_by', $nameList);
                if ($includesUnknown) {
                    $q->orWhere(function ($u) {
                        $u->where(function ($u1) {
                            $u1->whereNull('user_name')->orWhereRaw("TRIM(user_name) = ''");
                        })->where(function ($u2) {
                            $u2->whereNull('created_by')->orWhereRaw("TRIM(created_by) = ''");
                        });
                    });
                }
            });

            foreach ($txsQuery
                ->orderBy('date', 'desc')
                ->orderBy('id', 'desc')
                ->select(['id', 'reference_no', 'date', 'customer_name', 'payment_type', 'grand_total', 'paid_amount', 'due_amount', 'discount', 'user_name', 'created_by', 'warehouse_name'])
                ->cursor() as $t) {
                [$tStaff, $tWh] = $normNameWh($t->user_name, $t->created_by, $t->warehouse_name);
                $mk = $tStaff . '|' . $tWh;
                if (!isset($pagedKeyIdx[$mk])) {
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
                foreach ($pagedKeyIdx[$mk] as $targetIdx) {
                    $cap = $bucketCaps[$targetIdx] ?? 15;
                    if (count($pagedItems[$targetIdx]['transactions']) < $cap) {
                        $pagedItems[$targetIdx]['transactions'][] = $txRow;
                        if (count($pagedItems[$targetIdx]['transactions']) === $cap) {
                            $remainingBuckets--;
                        }
                    }
                }
                if ($remainingBuckets <= 0) {
                    break;
                }
            }
        }

        $payload = [
            'status' => 'success',
            'summary' => [
                'total_cashiers' => $totalCashiers,
                'total_store_bills' => $totalStoreBills,
                'total_store_sales' => round($totalStoreSales, 2),
                'total_store_discounts' => round($totalStoreDiscounts, 2),
                'total_net_revenue' => round($totalStoreNetRevenue, 2),
                'total_collected' => round($totalStoreCollected, 2),
                'total_due' => round($totalStoreDue, 2),
                'total_cash_collected' => round($totalStoreCash, 2),
                'total_non_cash_collected' => round($totalStoreNonCash, 2),
                'average_store_bill' => $totalStoreBills > 0 ? round($totalStoreSales / $totalStoreBills, 2) : 0.0,
                'total_drawer_discrepancy' => $canViewExpectedCash ? round($totalDiscrepancy, 2) : null,
                'blind_count_masked' => !$canViewExpectedCash,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'warehouse' => $warehouse,
                'audit_risk' => $riskFilter,
            ],
            'data' => $pagedItems,
            'pagination' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $totalCashiers,
                'has_more'     => $hasMore,
            ],
        ];

        if (!$skipSummary) {
            $availWarehouses = DB::table('outlets')
                ->when(!empty($allowedOutlets), fn($q) => $q->whereIn('id', $allowedOutlets))
                ->orderBy('name')
                ->pluck('name')
                ->map(fn($n) => trim((string)$n))
                ->filter(fn($n) => $n !== '')
                ->unique()
                ->values()
                ->all();
            $payload['available_filters'] = [
                'warehouses' => $availWarehouses,
                'audit_risks' => ['All', 'healthy', 'watch', 'critical'],
            ];
        }

        return $this->noStoreJson($payload);
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
        $unmatchedExplicitWh = false;
        if ($warehouse !== 'All' && !empty($warehouse)) {
            $matchedOutlet = DB::table('outlets')
                ->where('name', $warehouse)
                ->when(is_numeric($warehouse), fn ($q) => $q->orWhere('id', (int)$warehouse))
                ->first();
            if ($matchedOutlet) {
                $warehouseId = (int)$matchedOutlet->id;
                $warehouse = $matchedOutlet->name;
            } else {
                $unmatchedExplicitWh = true;
            }
        }

        $allowedOutlets = $this->extractAllowedOutletIds($request);
        if (!empty($allowedOutlets) && ($unmatchedExplicitWh || ($warehouseId !== null && !in_array((int)$warehouseId, $allowedOutlets, true)))) {
            return $this->noStoreJson(['status' => 'error', 'message' => 'Unauthorized warehouse access.'], 403);
        }
        $canViewCost = $accounting->canViewCost($request);

        $hasNullWhByTable = [];
        $applyWhScope = function ($query, string $baseTable, string $table = '') use ($warehouseId, $warehouse, &$hasNullWhByTable) {
            if ($warehouse === 'All' || empty($warehouse)) {
                return;
            }
            $idCol = $table !== '' ? "{$table}.warehouse_id" : 'warehouse_id';
            $nameCol = $table !== '' ? "{$table}.warehouse_name" : 'warehouse_name';
            if ($warehouseId !== null) {
                if (!array_key_exists($baseTable, $hasNullWhByTable)) {
                    $hasNullWhByTable[$baseTable] = DB::table($baseTable)->whereNull('warehouse_id')->limit(1)->exists();
                }
                if (!$hasNullWhByTable[$baseTable]) {
                    $query->where($idCol, $warehouseId);
                    return;
                }
                $query->where(function ($q) use ($idCol, $nameCol, $warehouseId, $warehouse) {
                    $q->where($idCol, $warehouseId)
                      ->orWhere(function ($legacy) use ($idCol, $nameCol, $warehouse) {
                          $legacy->whereNull($idCol)->where($nameCol, $warehouse);
                      });
                });
            } else {
                $query->where($nameCol, $warehouse);
            }
        };

        // 1. Output Tax from Sales (collected from customers)
        $salesQuery = DB::table('sales');
        $this->applyActiveDocumentFilter($salesQuery, 'sales.status');
        if (!empty($allowedOutlets)) {
            $salesQuery->whereIn('sales.warehouse_id', $allowedOutlets);
        }
        $applyWhScope($salesQuery, 'sales', 'sales');
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
        $applyWhScope($purchQuery, 'purchases', 'purchases');
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
        $saleTaxableBaseById = [];
        if ($hasAnySaleLineTax) {
            $saleLineRows = (clone $salesQuery)
                ->join('sale_items as si', 'si.sale_id', '=', 'sales.id')
                ->where('si.tax', '>', 0)
                ->selectRaw('sales.id, sales.tax_amount, sales.subtotal, sales.discount, sales.shipping, sales.grand_total, SUM(si.tax) as line_tax_sum, SUM(CASE WHEN (COALESCE(si.subtotal, 0) - COALESCE(si.tax, 0)) > 0 THEN (COALESCE(si.subtotal, 0) - COALESCE(si.tax, 0)) ELSE 0 END) as taxable_line_base, SUM(COALESCE(si.subtotal, 0)) as taxable_line_gross')
                ->groupBy('sales.id', 'sales.tax_amount', 'sales.subtotal', 'sales.discount', 'sales.shipping', 'sales.grand_total')
                ->get();
            foreach ($saleLineRows as $lr) {
                $sid = (int)$lr->id;
                $saleLineIds[] = $sid;
                $saleLineTaxById[$sid] = (float)$lr->line_tax_sum;
                // On mixed exempt/taxable invoices without order-level tax, ONLY tax-bearing lines belong in taxable_sales_amount
                if ((float)($lr->tax_amount ?? 0) <= 0.0001) {
                    $allSub = (float)($lr->subtotal ?? 0);
                    $ordDisc = (float)($lr->discount ?? 0);
                    $share = ($allSub > 0.0001 && $ordDisc > 0)
                        ? min(1.0, (float)$lr->taxable_line_gross / $allSub)
                        : 0.0;
                    $saleTaxableBaseById[$sid] = round(max(0.0, (float)$lr->taxable_line_base - ($ordDisc * $share)), 2);
                }
            }
        }

        $purchLineRows = collect();
        $purchLineIds = [];
        $purchLineTaxById = [];
        $purchTaxableBaseById = [];
        if ($hasAnyPurchLineTax) {
            $purchLineRows = (clone $purchQuery)
                ->join('purchase_items as pi', 'pi.purchase_id', '=', 'purchases.id')
                ->where('pi.tax', '>', 0)
                ->selectRaw('purchases.id, purchases.tax_amount, purchases.discount, purchases.shipping, purchases.grand_total, SUM(pi.tax) as line_tax_sum, SUM(CASE WHEN (COALESCE(pi.subtotal, 0) - COALESCE(pi.tax, 0)) > 0 THEN (COALESCE(pi.subtotal, 0) - COALESCE(pi.tax, 0)) ELSE 0 END) as taxable_line_base, SUM(COALESCE(pi.subtotal, 0)) as taxable_line_gross')
                ->groupBy('purchases.id', 'purchases.tax_amount', 'purchases.discount', 'purchases.shipping', 'purchases.grand_total')
                ->get();
            foreach ($purchLineRows as $pr) {
                $pid = (int)$pr->id;
                $purchLineIds[] = $pid;
                $purchLineTaxById[$pid] = (float)$pr->line_tax_sum;
                if ((float)($pr->tax_amount ?? 0) <= 0.0001) {
                    $grossExShipTax = max(0.0, (float)($pr->grand_total ?? 0) - (float)($pr->shipping ?? 0) - (float)$pr->line_tax_sum + (float)($pr->discount ?? 0));
                    $ordDisc = (float)($pr->discount ?? 0);
                    $share = ($grossExShipTax > 0.0001 && $ordDisc > 0)
                        ? min(1.0, (float)$pr->taxable_line_base / $grossExShipTax)
                        : 0.0;
                    $purchTaxableBaseById[$pid] = round(max(0.0, (float)$pr->taxable_line_base - ($ordDisc * $share)), 2);
                }
            }
        }

        $salesCount = 0;
        $taxableSales = 0.0;
        $grossOutputTax = 0.0;
        $grossSalesGrandTotal = 0.0;
        $refundedOutputTax = 0.0;
        $srReturnedTaxable = 0.0;
        $netOutputTax = 0.0;

        $purchCount = 0;
        $taxablePurchases = 0.0;
        $grossInputTax = 0.0;
        $grossPurchasesGrandTotal = 0.0;
        $recoveredInputTax = 0.0;
        $prTaxableTotal = 0.0;
        $netInputTax = 0.0;

        $netTaxPayable = 0.0;
        $taxCreditForward = 0.0;

        $driver = DB::getDriverName();

        if (!$skipSummary) {
            // Senior Accounting Fix: Aggregate ONLY taxable sales (header tax_amount > 0 OR line_tax > 0).
            // Non-taxable 0% sales MUST NEVER inflate Taxable Sales Amount or Taxable Sales Count.
            // Use whereNotExists instead of >500 bind-param whereNotIn so mixed-exempt taxable base isolation stays exact at any scale.
            $hdrSalesQuery = (clone $salesQuery)->where('sales.tax_amount', '>', 0);
            if ($hasAnySaleLineTax) {
                $hdrSalesQuery->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('sale_items as si_chk')
                        ->whereColumn('si_chk.sale_id', 'sales.id')
                        ->where('si_chk.tax', '>', 0);
                });
            }
            $hdrSalesAgg = $hdrSalesQuery
                ->selectRaw("
                    COUNT(*) as cnt,
                    COALESCE(SUM(CASE WHEN (COALESCE(sales.grand_total, 0) - COALESCE(sales.shipping, 0) - COALESCE(sales.tax_amount, 0)) > 0
                        THEN (COALESCE(sales.grand_total, 0) - COALESCE(sales.shipping, 0) - COALESCE(sales.tax_amount, 0)) ELSE 0 END), 0) as taxable_sales,
                    COALESCE(SUM(COALESCE(sales.tax_amount, 0)), 0) as output_tax,
                    COALESCE(SUM(COALESCE(sales.grand_total, 0)), 0) as gross_grand_total
                ")
                ->first();

            $salesCount = (int)($hdrSalesAgg->cnt ?? 0);
            $taxableSales = (float)($hdrSalesAgg->taxable_sales ?? 0);
            $grossOutputTax = (float)($hdrSalesAgg->output_tax ?? 0);
            $grossSalesGrandTotal = (float)($hdrSalesAgg->gross_grand_total ?? 0);
            foreach ($saleLineRows as $lr) {
                $sid = (int)$lr->id;
                $rowTax = (float)($lr->tax_amount ?? 0) + (float)$lr->line_tax_sum;
                if ($rowTax > 0) {
                    $salesCount++;
                    $rowGrandTotal = (float)($lr->grand_total ?? 0);
                    $rowTaxable = array_key_exists($sid, $saleTaxableBaseById)
                        ? (float)$saleTaxableBaseById[$sid]
                        : max(0.0, $rowGrandTotal - (float)($lr->shipping ?? 0) - $rowTax);
                    $taxableSales += $rowTaxable;
                    $grossOutputTax += $rowTax;
                    $grossSalesGrandTotal += $rowGrandTotal;
                }
            }

            // 2. Output Tax adjustment from Sale Returns (ONLY tax-bearing returns reduce taxable turnover & output tax)
            $srBaseQuery = DB::table('sale_returns');
            $this->applyActiveDocumentFilter($srBaseQuery, 'status');
            if (!empty($allowedOutlets)) {
                $srBaseQuery->whereIn('warehouse_id', $allowedOutlets);
            }
            $applyWhScope($srBaseQuery, 'sale_returns');
            $this->applyReportDateRange($srBaseQuery, $startDate, $endDate, 'date');
            if (!empty($search)) {
                $srBaseQuery->where(function ($q) use ($search) {
                    $q->where('reference_no', 'like', "%{$search}%")
                      ->orWhere('customer_name', 'like', "%{$search}%")
                      ->orWhere('warehouse_name', 'like', "%{$search}%");
                });
            }

            $saleReturnTaxRecords = [];
            $purchaseReturnTaxRecords = [];

            $sumReturnsTaxableAndTax = function ($rows, float &$taxableOut, float &$taxOut, ?array &$recordsOut = null, string $partyCol = 'customer_name') {
                foreach ($rows as $r) {
                    $lineTaxSum = 0.0;
                    $taxableLineBase = 0.0;
                    $taxableLineGross = 0.0;
                    $allLinesSub = 0.0;
                    if (!empty($r->items_payload)) {
                        $arr = json_decode((string)$r->items_payload, true);
                        if (is_array($arr)) {
                            foreach ($arr as $it) {
                                $lt = max(0.0, (float)($it['tax'] ?? 0));
                                $lSub = max(0.0, (float)($it['subtotal'] ?? 0));
                                $allLinesSub += $lSub;
                                $lineTaxSum += $lt;
                                if ($lt > 0) {
                                    $taxableLineGross += $lSub;
                                    $taxableLineBase += max(0.0, $lSub - $lt);
                                }
                            }
                        }
                    }
                    $hdrTax = (float)($r->tax_amount ?? 0);
                    $rowTax = $hdrTax + $lineTaxSum;
                    if ($rowTax > 0) {
                        if ($hdrTax <= 0.0001 && $taxableLineBase > 0) {
                            $ordDisc = max(0.0, (float)($r->discount ?? 0));
                            $denom = (float)($r->subtotal ?? 0) > 0.0001 ? (float)$r->subtotal : $allLinesSub;
                            $share = ($denom > 0.0001 && $ordDisc > 0) ? min(1.0, $taxableLineGross / $denom) : 0.0;
                            $rowTaxable = round(max(0.0, $taxableLineBase - ($ordDisc * $share)), 2);
                        } else {
                            $rowTaxable = max(0.0, (float)($r->grand_total ?? 0) - (float)($r->shipping ?? 0) - $rowTax);
                        }
                        $taxableOut += $rowTaxable;
                        $taxOut += $rowTax;
                        if ($recordsOut !== null) {
                            $recordsOut[] = [
                                'id' => (int)($r->id ?? 0),
                                'reference_no' => (string)($r->reference_no ?? ''),
                                'date' => (string)($r->date ?? ''),
                                $partyCol => (string)($r->$partyCol ?? ''),
                                'warehouse_name' => (string)($r->warehouse_name ?? 'Main Warehouse'),
                                'tax_rate' => $rowTaxable > 0 ? round(($rowTax / $rowTaxable) * 100.0, 2) : (float)($r->tax_rate ?? 0),
                                'tax_amount' => -round($rowTax, 2),
                                'taxable_amount' => -round($rowTaxable, 2),
                                'grand_total' => -round((float)($r->grand_total ?? 0), 2),
                                'is_credit_note' => true,
                            ];
                        }
                    }
                }
            };

            // Fast indexed scan for header-taxed Sale Returns
            $srHdrRows = (clone $srBaseQuery)
                ->where('tax_amount', '>', 0)
                ->select('id', 'reference_no', 'date', 'customer_name', 'warehouse_name', 'tax_rate', 'tax_amount', 'subtotal', 'discount', 'shipping', 'grand_total', 'items_payload')
                ->orderByDesc('date')
                ->get();
            $sumReturnsTaxableAndTax($srHdrRows, $srReturnedTaxable, $refundedOutputTax, $saleReturnTaxRecords, 'customer_name');

            // Narrow scan for returns with tax_amount = 0 that might hold item-level tax in JSON (supports string-encoded "tax":"5.00")
            $srJsonQuery = (clone $srBaseQuery)
                ->where(function ($q) {
                    $q->whereNull('tax_amount')->orWhere('tax_amount', '<=', 0);
                })
                ->where('items_payload', 'like', '%"tax":%');
            if ($driver === 'mysql') {
                $srJsonQuery->whereRaw('items_payload REGEXP ?', ['"tax":[[:space:]]*"?([1-9]|0\\.[0-9]*[1-9])']);
            }
            $sumReturnsTaxableAndTax(
                $srJsonQuery->select('id', 'reference_no', 'date', 'customer_name', 'warehouse_name', 'tax_rate', 'tax_amount', 'subtotal', 'discount', 'shipping', 'grand_total', 'items_payload')->orderByDesc('date')->cursor(),
                $srReturnedTaxable,
                $refundedOutputTax,
                $saleReturnTaxRecords,
                'customer_name'
            );

            // 3. Input Tax from Taxable Recoverable Purchases (ONLY tax > 0)
            $hdrPurchQuery = (clone $purchQuery)->where('purchases.tax_amount', '>', 0);
            if ($hasAnyPurchLineTax) {
                $hdrPurchQuery->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('purchase_items as pi_chk')
                        ->whereColumn('pi_chk.purchase_id', 'purchases.id')
                        ->where('pi_chk.tax', '>', 0);
                });
            }
            $hdrPurchAgg = $hdrPurchQuery
                ->selectRaw("
                    COUNT(*) as cnt,
                    COALESCE(SUM(CASE WHEN (COALESCE(purchases.grand_total, 0) - COALESCE(purchases.shipping, 0) - COALESCE(purchases.tax_amount, 0)) > 0
                        THEN (COALESCE(purchases.grand_total, 0) - COALESCE(purchases.shipping, 0) - COALESCE(purchases.tax_amount, 0)) ELSE 0 END), 0) as taxable_purchases,
                    COALESCE(SUM(COALESCE(purchases.tax_amount, 0)), 0) as input_tax,
                    COALESCE(SUM(COALESCE(purchases.grand_total, 0)), 0) as gross_grand_total
                ")
                ->first();

            $purchCount = (int)($hdrPurchAgg->cnt ?? 0);
            $taxablePurchases = (float)($hdrPurchAgg->taxable_purchases ?? 0);
            $grossInputTax = (float)($hdrPurchAgg->input_tax ?? 0);
            $grossPurchasesGrandTotal = (float)($hdrPurchAgg->gross_grand_total ?? 0);
            foreach ($purchLineRows as $pr) {
                $pid = (int)$pr->id;
                $rowTax = (float)($pr->tax_amount ?? 0) + (float)$pr->line_tax_sum;
                if ($rowTax > 0) {
                    $purchCount++;
                    $rowGrandTotal = (float)($pr->grand_total ?? 0);
                    $rowTaxable = array_key_exists($pid, $purchTaxableBaseById)
                        ? (float)$purchTaxableBaseById[$pid]
                        : max(0.0, $rowGrandTotal - (float)($pr->shipping ?? 0) - $rowTax);
                    $taxablePurchases += $rowTaxable;
                    $grossInputTax += $rowTax;
                    $grossPurchasesGrandTotal += $rowGrandTotal;
                }
            }

            // 4. Input Tax adjustment from Recoverable Purchase Returns (ONLY tax-bearing returns)
            $prBaseQuery = DB::table('purchase_returns')
                ->whereIn('status', ['Completed', 'Received'])
                ->where('is_tax_recoverable', 1);
            if (!empty($allowedOutlets)) {
                $prBaseQuery->whereIn('warehouse_id', $allowedOutlets);
            }
            $applyWhScope($prBaseQuery, 'purchase_returns');
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
                ->select('id', 'reference_no', 'date', 'supplier_name', 'warehouse_name', 'tax_rate', 'tax_amount', 'subtotal', 'discount', 'shipping', 'grand_total', 'items_payload')
                ->orderByDesc('date')
                ->get();
            $sumReturnsTaxableAndTax($prHdrRows, $prTaxableTotal, $recoveredInputTax, $purchaseReturnTaxRecords, 'supplier_name');

            $prJsonQuery = (clone $prBaseQuery)
                ->where(function ($q) {
                    $q->whereNull('tax_amount')->orWhere('tax_amount', '<=', 0);
                })
                ->where('items_payload', 'like', '%"tax":%');
            if ($driver === 'mysql') {
                $prJsonQuery->whereRaw('items_payload REGEXP ?', ['"tax":[[:space:]]*"?([1-9]|0\\.[0-9]*[1-9])']);
            }
            $sumReturnsTaxableAndTax(
                $prJsonQuery->select('id', 'reference_no', 'date', 'supplier_name', 'warehouse_name', 'tax_rate', 'tax_amount', 'subtotal', 'discount', 'shipping', 'grand_total', 'items_payload')->orderByDesc('date')->cursor(),
                $prTaxableTotal,
                $recoveredInputTax,
                $purchaseReturnTaxRecords,
                'supplier_name'
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

            $taxableSalesList = $pageSaleRows->map(function ($row) use ($lineTaxForPage, $saleTaxableBaseById) {
                $sid = (int)$row->id;
                $subtotal = (float)($row->subtotal ?? 0);
                $discount = (float)($row->discount ?? 0);
                $shipping = (float)($row->shipping ?? 0);
                $grandTotal = (float)($row->grand_total ?? 0);
                $lineTax = (float)($lineTaxForPage[$sid] ?? 0.0);
                $taxAmount = round((float)($row->tax_amount ?? 0) + $lineTax, 2);
                $taxableAmount = array_key_exists($sid, $saleTaxableBaseById)
                    ? (float)$saleTaxableBaseById[$sid]
                    : round(max(0.0, $grandTotal - $shipping - $taxAmount), 2);
                $taxRate = (float)($row->tax_rate ?? 0);
                if (($taxRate <= 0 || $lineTax > 0.0001) && $taxAmount > 0 && $taxableAmount > 0) {
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

            $taxablePurchasesList = $pagePurchRows->map(function ($row) use ($lineTaxForPurchPage, $purchTaxableBaseById) {
                $pid = (int)$row->id;
                $grandTotal = (float)($row->grand_total ?? 0);
                $lineTax = (float)($lineTaxForPurchPage[$pid] ?? 0.0);
                $taxAmount = round((float)($row->tax_amount ?? 0) + $lineTax, 2);
                $shipping = (float)($row->shipping ?? 0);
                $taxableAmount = array_key_exists($pid, $purchTaxableBaseById)
                    ? (float)$purchTaxableBaseById[$pid]
                    : round(max(0.0, $grandTotal - $shipping - $taxAmount), 2);
                $taxRate = (float)($row->tax_rate ?? 0);
                if (($taxRate <= 0 || $lineTax > 0.0001) && $taxAmount > 0 && $taxableAmount > 0) {
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

        $availableWarehouses = [];
        if (!$skipSummary) {
            $availableWarehouses = DB::table('outlets')
                ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('id', $allowedOutlets))
                ->orderBy('name')
                ->pluck('name')
                ->values()
                ->all();
        }

        return $this->noStoreJson([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'available_filters' => [
                'warehouses' => $availableWarehouses,
            ],
            'period' => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'warehouse'  => $warehouse,
            ],
            'output_tax' => [
                'gross_sales_tax'      => round($grossOutputTax, 2),
                'gross_taxable_sales'  => round($taxableSales, 2),
                'gross_sales_total'    => round($grossSalesGrandTotal, 2),
                'returns_tax_refund'   => round($refundedOutputTax, 2),
                'net_output_tax'       => round($netOutputTax, 2),
                'taxable_sales_amount' => round($taxableSales - $srReturnedTaxable, 2),
                'sales_count'          => $salesCount,
            ],
            'input_tax' => [
                'cost_masked'              => !$canViewCost,
                'gross_purchases_tax'      => $canViewCost ? round($grossInputTax, 2) : 0.0,
                'gross_taxable_purchases'  => $canViewCost ? round($taxablePurchases, 2) : 0.0,
                'gross_purchases_total'    => $canViewCost ? round($grossPurchasesGrandTotal, 2) : 0.0,
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
            'sales_tax_records'            => $taxableSalesList,
            'purchases_tax_records'        => $maskedPurchasesTaxRecords,
            'sale_return_tax_records'      => $saleReturnTaxRecords ?? [],
            'purchase_return_tax_records'  => $canViewCost ? ($purchaseReturnTaxRecords ?? []) : [],
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
        if ($lower === 'cash' || $this->accounting()->isCashPaymentType($trimmed)) return 'Cash';
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

    /**
     * Attribute a sale or purchase return voucher's paid and unallocated credit amounts
     * into tender breakdown buckets, handling multi-leg Split Payment payloads accurately.
     */
    private function accumulateReturnTenderRow(
        array &$tenderMap,
        int $rowId,
        float $rowGrand,
        float $rowPaid,
        string $fallbackMethod,
        mixed $paymentsPayload,
        string $unpaidFallbackTender = 'Credit Note',
        float $appliedCreditAmt = 0.0,
        ?string $singleTenderFilter = null
    ): float {
        $allocatedFromLegs = false;
        $matchedTenderPaid = 0.0;
        $matchesFilter = static function (string $m) use ($singleTenderFilter): bool {
            return $singleTenderFilter === null || strcasecmp(trim($m), trim($singleTenderFilter)) === 0;
        };

        $hasPayload = is_array($paymentsPayload)
            ? !empty($paymentsPayload)
            : (is_string($paymentsPayload) && $paymentsPayload !== '' && $paymentsPayload !== '[]');

        if ($hasPayload) {
            $decodedPayments = is_string($paymentsPayload)
                ? json_decode($paymentsPayload, true)
                : $paymentsPayload;
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
                        $legPaidAmt = $vLeg['amount'] * $paidScale;
                        if (!isset($tenderMap[$m])) {
                            $tenderMap[$m] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                        }
                        $tenderMap[$m]['voucher_ids'][$rowId] = true;
                        $tenderMap[$m]['total_refunded'] += $legPaidAmt;
                        $tenderMap[$m]['total_returned'] += $legPaidAmt;
                        if ($matchesFilter($m)) {
                            $matchedTenderPaid += $legPaidAmt;
                        }
                    }
                    if ($rowPaid - $legsSum > 0.01) {
                        $residualMethod = $fallbackMethod === 'Split Payment' ? 'Cash' : $fallbackMethod;
                        $resAmt = $rowPaid - $legsSum;
                        if (!isset($tenderMap[$residualMethod])) {
                            $tenderMap[$residualMethod] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                        }
                        $tenderMap[$residualMethod]['voucher_ids'][$rowId] = true;
                        $tenderMap[$residualMethod]['total_refunded'] += $resAmt;
                        $tenderMap[$residualMethod]['total_returned'] += $resAmt;
                        if ($matchesFilter($residualMethod)) {
                            $matchedTenderPaid += $resAmt;
                        }
                    }
                    if ($appliedCreditAmt > 0.01) {
                        if (!isset($tenderMap['AR Credit Offset'])) {
                            $tenderMap['AR Credit Offset'] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                        }
                        $tenderMap['AR Credit Offset']['voucher_ids'][$rowId] = true;
                        $tenderMap['AR Credit Offset']['total_returned'] += $appliedCreditAmt;
                        if ($matchesFilter('AR Credit Offset')) {
                            $matchedTenderPaid += $appliedCreditAmt;
                        }
                    }
                    $unpaidCreditAmt = max(0.0, $rowGrand - $rowPaid - $appliedCreditAmt);
                    if ($unpaidCreditAmt > 0.01) {
                        if (!isset($tenderMap[$unpaidFallbackTender])) {
                            $tenderMap[$unpaidFallbackTender] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                        }
                        $tenderMap[$unpaidFallbackTender]['voucher_ids'][$rowId] = true;
                        $tenderMap[$unpaidFallbackTender]['total_returned'] += $unpaidCreditAmt;
                        if ($matchesFilter($unpaidFallbackTender)) {
                            $matchedTenderPaid += $unpaidCreditAmt;
                        }
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
                if ($matchesFilter($paidTargetMethod)) {
                    $matchedTenderPaid += $rowPaid;
                }
                if ($appliedCreditAmt > 0.01) {
                    if (!isset($tenderMap['AR Credit Offset'])) {
                        $tenderMap['AR Credit Offset'] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                    }
                    $tenderMap['AR Credit Offset']['voucher_ids'][$rowId] = true;
                    $tenderMap['AR Credit Offset']['total_returned'] += $appliedCreditAmt;
                    if ($matchesFilter('AR Credit Offset')) {
                        $matchedTenderPaid += $appliedCreditAmt;
                    }
                }
                $unpaidCreditAmt = max(0.0, $rowGrand - $rowPaid - $appliedCreditAmt);
                if ($unpaidCreditAmt > 0.01) {
                    if (!isset($tenderMap[$unpaidFallbackTender])) {
                        $tenderMap[$unpaidFallbackTender] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                    }
                    $tenderMap[$unpaidFallbackTender]['voucher_ids'][$rowId] = true;
                    $tenderMap[$unpaidFallbackTender]['total_returned'] += $unpaidCreditAmt;
                    if ($matchesFilter($unpaidFallbackTender)) {
                        $matchedTenderPaid += $unpaidCreditAmt;
                    }
                }
            } else {
                // Zero direct refund paid: separate AR Credit Offset from open Store Credit / Credit Note
                if ($appliedCreditAmt > 0.01) {
                    if (!isset($tenderMap['AR Credit Offset'])) {
                        $tenderMap['AR Credit Offset'] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                    }
                    $tenderMap['AR Credit Offset']['voucher_ids'][$rowId] = true;
                    $tenderMap['AR Credit Offset']['total_returned'] += min($rowGrand, $appliedCreditAmt);
                    if ($matchesFilter('AR Credit Offset')) {
                        $matchedTenderPaid += min($rowGrand, $appliedCreditAmt);
                    }
                    $remOpenCredit = max(0.0, $rowGrand - $appliedCreditAmt);
                    if ($remOpenCredit > 0.01) {
                        if (!isset($tenderMap[$unpaidFallbackTender])) {
                            $tenderMap[$unpaidFallbackTender] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                        }
                        $tenderMap[$unpaidFallbackTender]['voucher_ids'][$rowId] = true;
                        $tenderMap[$unpaidFallbackTender]['total_returned'] += $remOpenCredit;
                        if ($matchesFilter($unpaidFallbackTender)) {
                            $matchedTenderPaid += $remOpenCredit;
                        }
                    }
                } else {
                    $targetMethod = in_array(strtolower($fallbackMethod), ['', 'split payment', 'credit settled', 'unpaid', 'n/a'], true)
                        ? $unpaidFallbackTender
                        : $fallbackMethod;
                    if (!isset($tenderMap[$targetMethod])) {
                        $tenderMap[$targetMethod] = ['voucher_ids' => [], 'total_refunded' => 0.0, 'total_returned' => 0.0];
                    }
                    $tenderMap[$targetMethod]['voucher_ids'][$rowId] = true;
                    $tenderMap[$targetMethod]['total_returned'] += $rowGrand;
                    if ($matchesFilter($targetMethod)) {
                        $matchedTenderPaid += $rowGrand;
                    }
                }
            }
        }

        return $matchedTenderPaid;
    }
}