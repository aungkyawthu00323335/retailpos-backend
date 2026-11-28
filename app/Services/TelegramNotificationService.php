<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\TelegramNotificationLog;
use App\Models\TelegramRecipient;
use App\Models\TelegramSetting;
use App\Support\AdjustmentReasonCatalog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TelegramNotificationService
{
    private const EXCLUDED_DOC_STATUSES = [
        'Voided', 'voided', 'VOIDED',
        'Cancelled', 'cancelled', 'CANCELLED',
        'Canceled', 'canceled', 'CANCELED',
        'Draft', 'draft', 'DRAFT',
        'Pending', 'pending', 'PENDING',
        'Ordered', 'ordered', 'ORDERED',
    ];

    private const EXCLUDED_EXPENSE_STATUSES = [
        'Pending', 'pending', 'PENDING',
        'Draft', 'draft', 'DRAFT',
        'Voided', 'voided', 'VOIDED',
    ];

    private const CACHE_KEY_STORE_META = 'telegram:store_meta_v1';
    private const CACHE_KEY_SCHEDULE_META = 'telegram:schedule_meta_v1';

    private PurchaseAccountingService $accounting;

    public function __construct(PurchaseAccountingService $accounting)
    {
        $this->accounting = $accounting;
    }

    public function clearCaches(): void
    {
        Cache::forget(self::CACHE_KEY_STORE_META);
        Cache::forget(self::CACHE_KEY_SCHEDULE_META);
    }

    /**
     * Load core store metadata (company name, currency symbol, timezone) in a single cached query.
     */
    public function getStoreMeta(): array
    {
        return Cache::remember(self::CACHE_KEY_STORE_META, 60, function (): array {
            $defaults = [
                'company_name' => 'Retail POS',
                'currency_symbol' => 'Ks',
                'timezone' => (string)(config('app.timezone') ?: 'Asia/Yangon'),
            ];

            try {
                $rows = Setting::whereIn('key', ['company_name', 'currency_symbol', 'timezone', 'default_timezone'])
                    ->pluck('value', 'key')
                    ->all();

                if (!empty($rows['company_name']) && is_string($rows['company_name'])) {
                    $defaults['company_name'] = trim($rows['company_name']);
                }
                if (!empty($rows['currency_symbol']) && is_string($rows['currency_symbol'])) {
                    $defaults['currency_symbol'] = trim($rows['currency_symbol']);
                }
                $tzCandidate = trim((string)($rows['timezone'] ?? $rows['default_timezone'] ?? ''));
                if ($tzCandidate !== '' && in_array($tzCandidate, timezone_identifiers_list(), true)) {
                    $defaults['timezone'] = $tzCandidate;
                }
            } catch (\Throwable $e) {
                // Keep safe defaults
            }

            return $defaults;
        });
    }

    public function getCurrencySymbol(): string
    {
        return $this->getStoreMeta()['currency_symbol'];
    }

    public function getCompanyName(): string
    {
        return $this->getStoreMeta()['company_name'];
    }

    public function getStoreTimezone(): string
    {
        return $this->getStoreMeta()['timezone'];
    }

    public function formatAmount(float $amount, ?string $currency = null): string
    {
        $sym = $currency ?? $this->getCurrencySymbol();
        $rounded = round($amount, 2);
        $decimals = (abs($rounded - round($rounded)) < 0.005) ? 0 : 2;
        return number_format($rounded, $decimals) . ' ' . $sym;
    }

    public function formatSignedAmount(float $amount, ?string $currency = null): string
    {
        $rounded = round($amount, 2);
        if (abs($rounded) < 0.005) {
            return '±' . $this->formatAmount(0.0, $currency);
        }
        $prefix = $rounded > 0 ? '+' : '-';
        return $prefix . $this->formatAmount(abs($rounded), $currency);
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function shortTime(?string $timestamp): string
    {
        $raw = trim((string)$timestamp);
        if ($raw === '') {
            return '-';
        }
        if (strlen($raw) >= 16) {
            return substr($raw, 11, 5);
        }
        return $raw;
    }

    private function formatDisplayClock(string $hhmm): string
    {
        if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $hhmm, $m)) {
            return $hhmm;
        }
        $hour = (int)$m[1];
        $min = $m[2];
        $suffix = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12;
        if ($hour12 === 0) {
            $hour12 = 12;
        }
        return sprintf('%02d:%s %s', $hour12, $min, $suffix);
    }

    /**
     * Build GAAP / IAS 2 reconciled Daily Business Summary + clean Telegram HTML & plain-text preview.
     * Reconciles 0.00 Ks parity with ReportController::profitLoss() and Admin Dashboard.
     */
    public function buildDailyBusinessSummary(
        string $date,
        ?int $outletId = null,
        ?array $allowedOutletIds = null,
        bool $canViewCost = true,
        bool $includeProfit = true,
        bool $includeRegisters = true,
        string $lang = 'my',
        string $triggeredBy = 'System'
    ): array {
        $allowedOutletIds = $allowedOutletIds ?? [];
        $meta = $this->getStoreMeta();
        $tz = $meta['timezone'];
        $dateStr = Carbon::parse($date, $tz)->toDateString();
        $dateEnd = $dateStr . ' 23:59:59';
        $currency = $meta['currency_symbol'];
        $companyName = $meta['company_name'];
        $showProfit = $canViewCost && $includeProfit;

        // Effective outlet filter
        $effectiveOutletIds = [];
        if ($outletId !== null && $outletId > 0) {
            $effectiveOutletIds = [$outletId];
        } elseif (!empty($allowedOutletIds)) {
            $effectiveOutletIds = array_values(array_unique(array_map('intval', $allowedOutletIds)));
        }

        $outletMap = Outlet::query()
            ->when(!empty($effectiveOutletIds), fn ($q) => $q->whereIn('id', $effectiveOutletIds))
            ->pluck('name', 'id')
            ->all();

        $scopeLabel = $outletId !== null && $outletId > 0
            ? (string)($outletMap[$outletId] ?? ('Outlet #' . $outletId))
            : ($lang === 'my' ? 'ဆိုင်ခွဲအားလုံး' : 'All Outlets');

        $outletsData = [];
        $ensureOutlet = function (?int $wId, ?string $wName) use (&$outletsData, $outletMap): string {
            $id = $wId ? (int)$wId : 0;
            $name = trim((string)$wName);
            if ($name === '' && $id > 0 && isset($outletMap[$id])) {
                $name = (string)$outletMap[$id];
            }
            if ($name === '') {
                $name = 'Main Branch Outlet';
            }
            $key = $id > 0 ? ('id:' . $id) : ('name:' . strtolower($name));
            if (!isset($outletsData[$key])) {
                $outletsData[$key] = [
                    'outlet_id' => $id > 0 ? $id : null,
                    'outlet_name' => $name,
                    'sales_count' => 0,
                    'invoiced_sales' => 0.0,
                    'gross_sales_ex_tax' => 0.0,
                    'tax_collected' => 0.0,
                    'shipping_collected' => 0.0,
                    'sales_paid' => 0.0,
                    'sales_due' => 0.0,
                    'sale_returns' => 0.0,
                    'sale_returns_count' => 0,
                    'cogs' => 0.0,
                    'purchases_count' => 0,
                    'gross_purchases' => 0.0,
                    'purchases_paid' => 0.0,
                    'purchases_due' => 0.0,
                    'purchase_returns' => 0.0,
                    'purchase_return_variance' => 0.0,
                    'expenses' => 0.0,
                    'expenses_count' => 0,
                    'inventory_loss' => 0.0,
                    'inventory_gain' => 0.0,
                    'sales_users' => [],
                    'purchase_users' => [],
                ];
            }
            return $key;
        };

        // 1. SALES (Active only; scoped sale_items lookup avoids full-table scans)
        $salesQuery = DB::table('sales')
            ->where(function ($q) {
                $q->whereNull('sales.status')
                    ->orWhereNotIn('sales.status', self::EXCLUDED_DOC_STATUSES);
            })
            ->where('sales.date', '>=', $dateStr)
            ->where('sales.date', '<=', $dateEnd);

        if (!empty($effectiveOutletIds)) {
            $salesQuery->whereIn('sales.warehouse_id', $effectiveOutletIds);
        }

        $salesRows = $salesQuery->select([
            'sales.id',
            'sales.warehouse_id',
            'sales.warehouse_name',
            'sales.grand_total',
            'sales.paid_amount',
            'sales.tax_amount',
            'sales.shipping',
            'sales.total_cogs',
            'sales.user_name',
            'sales.user_role',
            'sales.created_by',
        ])->get();

        // Single scoped sale_items aggregation for item-level tax + partial/missing COGS fallback
        $saleItemStatsBySaleId = [];
        $saleIds = $salesRows->pluck('id')->map(fn ($id) => (int)$id)->all();
        if (!empty($saleIds)) {
            foreach (array_chunk($saleIds, 400) as $idChunk) {
                $siQuery = DB::table('sale_items')->whereIn('sale_items.sale_id', $idChunk);
                if ($showProfit) {
                    $fallbackUnitCostSql = 'COALESCE(NULLIF(product_variants.cost_price, 0), NULLIF(products.cost_price, 0), NULLIF(products.purchase_price, 0), 0)';
                    $itemCogsSql = "COALESCE(NULLIF(sale_items.total_cogs, 0), CASE WHEN sale_items.product_id IS NOT NULL THEN COALESCE(NULLIF(sale_items.base_quantity, 0), sale_items.quantity, 1) * {$fallbackUnitCostSql} ELSE 0 END)";
                    $siRows = $siQuery
                        ->leftJoin('product_variants', 'sale_items.product_variant_id', '=', 'product_variants.id')
                        ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
                        ->groupBy('sale_items.sale_id')
                        ->selectRaw("
                            sale_items.sale_id,
                            COALESCE(SUM(sale_items.tax), 0) as item_tax_sum,
                            COALESCE(SUM({$itemCogsSql}), 0) as computed_cogs,
                            SUM(CASE WHEN sale_items.product_id IS NOT NULL AND (sale_items.total_cogs IS NULL OR sale_items.total_cogs <= 0) THEN 1 ELSE 0 END) as missing_cogs_items
                        ")
                        ->get();
                } else {
                    $siRows = $siQuery
                        ->groupBy('sale_items.sale_id')
                        ->selectRaw('sale_items.sale_id, COALESCE(SUM(sale_items.tax), 0) as item_tax_sum, 0 as computed_cogs, 0 as missing_cogs_items')
                        ->get();
                }

                foreach ($siRows as $sir) {
                    $saleItemStatsBySaleId[(int)$sir->sale_id] = [
                        'item_tax_sum' => (float)($sir->item_tax_sum ?? 0),
                        'computed_cogs' => (float)($sir->computed_cogs ?? 0),
                        'missing_cogs_items' => (int)($sir->missing_cogs_items ?? 0),
                    ];
                }
            }
        }

        foreach ($salesRows as $row) {
            $saleId = (int)$row->id;
            $key = $ensureOutlet($row->warehouse_id ? (int)$row->warehouse_id : null, $row->warehouse_name);
            $siStat = $saleItemStatsBySaleId[$saleId] ?? ['item_tax_sum' => 0.0, 'computed_cogs' => 0.0, 'missing_cogs_items' => 0];

            $grandTotal = (float)($row->grand_total ?? 0);
            $paid = min($grandTotal, max(0.0, (float)($row->paid_amount ?? 0)));
            $due = max(0.0, $grandTotal - $paid);
            $tax = (float)($row->tax_amount ?? 0) + $siStat['item_tax_sum'];
            $shipping = (float)($row->shipping ?? 0);
            $exTaxRev = max(0.0, $grandTotal - $tax - $shipping);

            $outletsData[$key]['sales_count']++;
            $outletsData[$key]['invoiced_sales'] += $grandTotal;
            $outletsData[$key]['gross_sales_ex_tax'] += $exTaxRev;
            $outletsData[$key]['tax_collected'] += $tax;
            $outletsData[$key]['shipping_collected'] += $shipping;
            $outletsData[$key]['sales_paid'] += $paid;
            $outletsData[$key]['sales_due'] += $due;

            if ($showProfit) {
                $headerCogs = (float)($row->total_cogs ?? 0);
                // Reconcile full OR partial missing COGS identically to ReportController
                if ($headerCogs > 0 && $siStat['missing_cogs_items'] === 0) {
                    $outletsData[$key]['cogs'] += $headerCogs;
                } else {
                    $outletsData[$key]['cogs'] += max($headerCogs, $siStat['computed_cogs']);
                }
            }

            $uName = trim((string)($row->user_name ?: $row->created_by ?: 'System'));
            $uRole = trim((string)($row->user_role ?? ''));
            $uLabel = ($uRole !== '' && stripos($uName, $uRole) === false) ? "{$uName} ({$uRole})" : $uName;
            if (!isset($outletsData[$key]['sales_users'][$uLabel])) {
                $outletsData[$key]['sales_users'][$uLabel] = ['name' => $uLabel, 'count' => 0, 'total' => 0.0];
            }
            $outletsData[$key]['sales_users'][$uLabel]['count']++;
            $outletsData[$key]['sales_users'][$uLabel]['total'] += $grandTotal;
        }

        // 2. SALE RETURNS (Active only; scoped cost-layer lookup)
        $srQuery = DB::table('sale_returns')
            ->where(function ($q) {
                $q->whereNull('sale_returns.status')
                    ->orWhereNotIn('sale_returns.status', self::EXCLUDED_DOC_STATUSES);
            })
            ->where('sale_returns.date', '>=', $dateStr)
            ->where('sale_returns.date', '<=', $dateEnd);

        if (!empty($effectiveOutletIds)) {
            $srQuery->whereIn('sale_returns.warehouse_id', $effectiveOutletIds);
        }

        $srRows = $srQuery->select([
            'sale_returns.id',
            'sale_returns.warehouse_id',
            'sale_returns.warehouse_name',
            'sale_returns.grand_total',
            'sale_returns.tax_amount',
            'sale_returns.shipping',
            'sale_returns.return_cost_total',
        ])->get();

        $layerCostBySrId = [];
        if ($showProfit && $srRows->isNotEmpty()) {
            $needLayerIds = $srRows
                ->filter(fn ($r) => (float)($r->return_cost_total ?? 0) <= 0)
                ->pluck('id')
                ->map(fn ($id) => (int)$id)
                ->all();
            if (!empty($needLayerIds)) {
                $layerCostBySrId = DB::table('sale_return_cost_layers')
                    ->whereIn('sale_return_id', $needLayerIds)
                    ->groupBy('sale_return_id')
                    ->selectRaw('sale_return_id, COALESCE(SUM(total_cost), 0) as layer_cost')
                    ->pluck('layer_cost', 'sale_return_id')
                    ->all();
            }
        }

        foreach ($srRows as $row) {
            $key = $ensureOutlet($row->warehouse_id ? (int)$row->warehouse_id : null, $row->warehouse_name);
            $retTax = (float)($row->tax_amount ?? 0);
            $retShipping = (float)($row->shipping ?? 0);
            $exTaxRet = max(0.0, (float)$row->grand_total - $retTax - $retShipping);

            $outletsData[$key]['sale_returns_count']++;
            $outletsData[$key]['sale_returns'] += $exTaxRet;
            $outletsData[$key]['tax_collected'] = max(0.0, $outletsData[$key]['tax_collected'] - $retTax);
            $outletsData[$key]['shipping_collected'] = max(0.0, $outletsData[$key]['shipping_collected'] - $retShipping);

            if ($showProfit) {
                $retCogs = (float)($row->return_cost_total ?? 0);
                if ($retCogs <= 0) {
                    $retCogs = (float)($layerCostBySrId[(int)$row->id] ?? 0);
                }
                $outletsData[$key]['cogs'] = round($outletsData[$key]['cogs'] - $retCogs, 4);
            }
        }

        // 3. PURCHASES (IAS 2: Received/Completed only, excluding Opening Stock, scoped tax lookup)
        $purchQuery = DB::table('purchases')
            ->whereIn('purchases.status', ['Received', 'Completed'])
            ->where(function ($q) {
                $q->whereNull('purchases.payment_type')
                    ->orWhere('purchases.payment_type', '!=', 'Opening Stock');
            })
            ->where('purchases.date', '>=', $dateStr)
            ->where('purchases.date', '<=', $dateEnd);

        if (!empty($effectiveOutletIds)) {
            $purchQuery->whereIn('purchases.warehouse_id', $effectiveOutletIds);
        }

        $purchRows = $purchQuery->select([
            'purchases.id',
            'purchases.warehouse_id',
            'purchases.warehouse_name',
            'purchases.grand_total',
            'purchases.paid_amount',
            'purchases.tax_amount',
            'purchases.is_tax_recoverable',
            'purchases.user_name',
            'purchases.user_role',
            'purchases.created_by',
        ])->get();

        $piTaxByPurchId = [];
        $recoverablePurchIds = $purchRows
            ->filter(fn ($r) => !empty($r->is_tax_recoverable))
            ->pluck('id')
            ->map(fn ($id) => (int)$id)
            ->all();
        if (!empty($recoverablePurchIds)) {
            $piTaxByPurchId = DB::table('purchase_items')
                ->whereIn('purchase_id', $recoverablePurchIds)
                ->groupBy('purchase_id')
                ->selectRaw('purchase_id, COALESCE(SUM(tax), 0) as item_tax_sum')
                ->pluck('item_tax_sum', 'purchase_id')
                ->all();
        }

        foreach ($purchRows as $row) {
            $key = $ensureOutlet($row->warehouse_id ? (int)$row->warehouse_id : null, $row->warehouse_name);
            $grandTotal = (float)$row->grand_total;
            $paid = min($grandTotal, max(0.0, (float)($row->paid_amount ?? 0)));
            $due = max(0.0, $grandTotal - $paid);

            $isRecoverable = !empty($row->is_tax_recoverable);
            $amt = $isRecoverable
                ? max(0.0, $grandTotal - (float)($row->tax_amount ?? 0) - (float)($piTaxByPurchId[(int)$row->id] ?? 0))
                : $grandTotal;

            $outletsData[$key]['purchases_count']++;
            $outletsData[$key]['gross_purchases'] += $amt;
            $outletsData[$key]['purchases_paid'] += $paid;
            $outletsData[$key]['purchases_due'] += $due;

            $uName = trim((string)($row->user_name ?: $row->created_by ?: 'System'));
            $uRole = trim((string)($row->user_role ?? ''));
            $uLabel = ($uRole !== '' && stripos($uName, $uRole) === false) ? "{$uName} ({$uRole})" : $uName;
            if (!isset($outletsData[$key]['purchase_users'][$uLabel])) {
                $outletsData[$key]['purchase_users'][$uLabel] = ['name' => $uLabel, 'count' => 0, 'total' => 0.0];
            }
            $outletsData[$key]['purchase_users'][$uLabel]['count']++;
            $outletsData[$key]['purchase_users'][$uLabel]['total'] += $amt;
        }

        // 4. PURCHASE RETURNS (Completed / Received + IAS 2 cost_variance)
        $prQuery = DB::table('purchase_returns')
            ->whereIn('status', ['Completed', 'Received'])
            ->where('date', '>=', $dateStr)
            ->where('date', '<=', $dateEnd);

        if (!empty($effectiveOutletIds)) {
            $prQuery->whereIn('warehouse_id', $effectiveOutletIds);
        }

        $prRows = $prQuery->select([
            'warehouse_id',
            'warehouse_name',
            'grand_total',
            'tax_amount',
            'is_tax_recoverable',
            'cost_variance',
            'items_payload',
        ])->get();

        foreach ($prRows as $row) {
            $key = $ensureOutlet($row->warehouse_id ? (int)$row->warehouse_id : null, $row->warehouse_name);
            $prAmt = !empty($row->is_tax_recoverable)
                ? $this->accounting->netPurchaseReturnAmount($row)
                : (float)$row->grand_total;
            $outletsData[$key]['purchase_returns'] += $prAmt;
            $outletsData[$key]['purchase_return_variance'] += (float)($row->cost_variance ?? 0);
        }

        // 5. OPERATING EXPENSES (Active only)
        $expQuery = DB::table('expenses')
            ->where(function ($q) {
                $q->whereNull('payment_status')
                    ->orWhereNotIn('payment_status', self::EXCLUDED_EXPENSE_STATUSES);
            })
            ->where('date', '>=', $dateStr)
            ->where('date', '<=', $dateEnd);

        if (!empty($effectiveOutletIds)) {
            $expQuery->whereIn('warehouse_id', $effectiveOutletIds);
        }

        $expRows = $expQuery->select(['warehouse_id', 'warehouse_name', 'amount'])->get();
        foreach ($expRows as $row) {
            $key = $ensureOutlet($row->warehouse_id ? (int)$row->warehouse_id : null, $row->warehouse_name);
            $outletsData[$key]['expenses_count']++;
            $outletsData[$key]['expenses'] += (float)$row->amount;
        }

        // 6. OPERATING STOCK ADJUSTMENTS (Shrinkage Loss / Overage Gain — excludes Opening Stock)
        if ($showProfit) {
            $effAdjTypeSql = "LOWER(COALESCE(NULLIF(TRIM(stock_adjustment_items.type), ''), NULLIF(TRIM(stock_adjustments.type), ''), 'addition'))";
            $nonPnlReasons = AdjustmentReasonCatalog::nonPnlReasonCodesLower();
            $placeholders = implode(', ', array_fill(0, count($nonPnlReasons), '?'));

            $adjQuery = DB::table('stock_adjustment_items')
                ->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id')
                ->where('stock_adjustments.date', '>=', $dateStr)
                ->where('stock_adjustments.date', '<=', $dateEnd)
                ->where(function ($q) use ($nonPnlReasons, $placeholders) {
                    $q->where('stock_adjustments.affects_pnl', true)
                        ->orWhere(function ($sub) use ($nonPnlReasons, $placeholders) {
                            $sub->whereNull('stock_adjustments.affects_pnl')
                                ->where(function ($r) use ($nonPnlReasons, $placeholders) {
                                    $r->whereNull('stock_adjustments.reason_code')
                                        ->orWhereRaw("LOWER(TRIM(stock_adjustments.reason_code)) NOT IN ({$placeholders})", $nonPnlReasons);
                                });
                        });
                });

            if (!empty($effectiveOutletIds)) {
                $adjQuery->whereIn('stock_adjustments.warehouse_id', $effectiveOutletIds);
            }

            $adjRows = $adjQuery
                ->groupBy('stock_adjustments.warehouse_id', 'stock_adjustments.warehouse_name')
                ->selectRaw("
                    stock_adjustments.warehouse_id,
                    stock_adjustments.warehouse_name,
                    COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'subtraction' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as inv_loss,
                    COALESCE(SUM(CASE WHEN {$effAdjTypeSql} = 'addition' THEN stock_adjustment_items.subtotal ELSE 0 END), 0) as inv_gain
                ")
                ->get();

            foreach ($adjRows as $ar) {
                $loss = (float)($ar->inv_loss ?? 0);
                $gain = (float)($ar->inv_gain ?? 0);
                if ($loss == 0.0 && $gain == 0.0) {
                    continue;
                }
                $key = $ensureOutlet($ar->warehouse_id ? (int)$ar->warehouse_id : null, $ar->warehouse_name);
                $outletsData[$key]['inventory_loss'] += $loss;
                $outletsData[$key]['inventory_gain'] += $gain;
            }
        }

        // Finalize per-outlet & grand totals (exact GAAP / ReportController::profitLoss parity)
        $grand = [
            'sales_count' => 0,
            'invoiced_sales' => 0.0,
            'gross_sales' => 0.0, // Tax-exclusive & ex-shipping Gross Sales (matches profitLoss)
            'tax_collected' => 0.0,
            'shipping_collected' => 0.0,
            'sales_paid' => 0.0,
            'sales_due' => 0.0,
            'sale_returns_count' => 0,
            'sale_returns' => 0.0,
            'net_sales' => 0.0,
            'cogs' => 0.0,
            'purchases_count' => 0,
            'gross_purchases' => 0.0,
            'purchases_paid' => 0.0,
            'purchases_due' => 0.0,
            'purchase_returns' => 0.0,
            'purchase_return_variance' => 0.0,
            'net_purchases' => 0.0,
            'expenses_count' => 0,
            'expenses' => 0.0,
            'inventory_loss' => 0.0,
            'inventory_gain' => 0.0,
            'gross_profit' => 0.0,
            'gross_margin_pct' => 0.0,
            'net_profit' => 0.0,
            'net_margin_pct' => 0.0,
            'cost_masked' => !$showProfit,
        ];

        $outletsList = [];
        foreach ($outletsData as $entry) {
            $grossSalesExTax = round($entry['gross_sales_ex_tax'], 2);
            $saleReturns = round($entry['sale_returns'], 2);
            $netSales = round($grossSalesExTax - $saleReturns, 2);
            $netPurchases = round($entry['gross_purchases'] - $entry['purchase_returns'], 2);
            $cogs = round($entry['cogs'], 2);
            $expenses = round($entry['expenses'], 2);
            $invLoss = round($entry['inventory_loss'], 2);
            $invGain = round($entry['inventory_gain'], 2);
            $prVar = round($entry['purchase_return_variance'], 2);

            $grossProfit = round($netSales - $cogs, 2);
            $netProfit = round($grossProfit - $expenses - $invLoss + $invGain + $prVar, 2);

            $salesUsers = array_values($entry['sales_users']);
            usort($salesUsers, fn ($a, $b) => $b['total'] <=> $a['total']);
            $purchaseUsers = array_values($entry['purchase_users']);
            usort($purchaseUsers, fn ($a, $b) => $b['total'] <=> $a['total']);

            $outletsList[] = [
                'outlet_id' => $entry['outlet_id'],
                'outlet_name' => $entry['outlet_name'],
                'sales_count' => $entry['sales_count'],
                'invoiced_sales' => round($entry['invoiced_sales'], 2),
                'gross_sales' => $grossSalesExTax,
                'tax_collected' => round($entry['tax_collected'], 2),
                'sales_paid' => round($entry['sales_paid'], 2),
                'sales_due' => round($entry['sales_due'], 2),
                'sale_returns_count' => $entry['sale_returns_count'],
                'sale_returns' => $saleReturns,
                'net_sales' => $netSales,
                'purchases_count' => $entry['purchases_count'],
                'gross_purchases' => round($entry['gross_purchases'], 2),
                'purchase_returns' => round($entry['purchase_returns'], 2),
                'net_purchases' => $netPurchases,
                'expenses_count' => $entry['expenses_count'],
                'expenses' => $expenses,
                'cogs' => $showProfit ? $cogs : null,
                'gross_profit' => $showProfit ? $grossProfit : null,
                'net_profit' => $showProfit ? $netProfit : null,
                'sales_users' => $salesUsers,
                'purchase_users' => $purchaseUsers,
            ];

            $grand['sales_count'] += $entry['sales_count'];
            $grand['invoiced_sales'] = round($grand['invoiced_sales'] + $entry['invoiced_sales'], 2);
            $grand['gross_sales'] = round($grand['gross_sales'] + $grossSalesExTax, 2);
            $grand['tax_collected'] = round($grand['tax_collected'] + $entry['tax_collected'], 2);
            $grand['shipping_collected'] = round($grand['shipping_collected'] + $entry['shipping_collected'], 2);
            $grand['sales_paid'] = round($grand['sales_paid'] + $entry['sales_paid'], 2);
            $grand['sales_due'] = round($grand['sales_due'] + $entry['sales_due'], 2);
            $grand['sale_returns_count'] += $entry['sale_returns_count'];
            $grand['sale_returns'] = round($grand['sale_returns'] + $saleReturns, 2);
            $grand['net_sales'] = round($grand['net_sales'] + $netSales, 2);
            $grand['purchases_count'] += $entry['purchases_count'];
            $grand['gross_purchases'] = round($grand['gross_purchases'] + $entry['gross_purchases'], 2);
            $grand['purchases_paid'] = round($grand['purchases_paid'] + $entry['purchases_paid'], 2);
            $grand['purchases_due'] = round($grand['purchases_due'] + $entry['purchases_due'], 2);
            $grand['purchase_returns'] = round($grand['purchase_returns'] + $entry['purchase_returns'], 2);
            $grand['purchase_return_variance'] = round($grand['purchase_return_variance'] + $prVar, 2);
            $grand['net_purchases'] = round($grand['net_purchases'] + $netPurchases, 2);
            $grand['expenses_count'] += $entry['expenses_count'];
            $grand['expenses'] = round($grand['expenses'] + $expenses, 2);
            $grand['inventory_loss'] = round($grand['inventory_loss'] + $invLoss, 2);
            $grand['inventory_gain'] = round($grand['inventory_gain'] + $invGain, 2);
            $grand['cogs'] = round($grand['cogs'] + $cogs, 2);
            $grand['gross_profit'] = round($grand['gross_profit'] + $grossProfit, 2);
            $grand['net_profit'] = round($grand['net_profit'] + $netProfit, 2);
        }

        usort($outletsList, fn ($a, $b) => $b['invoiced_sales'] <=> $a['invoiced_sales']);

        if ($showProfit) {
            $grand['gross_margin_pct'] = $grand['net_sales'] > 0
                ? round(($grand['gross_profit'] / $grand['net_sales']) * 100, 1)
                : 0.0;
            $grand['net_margin_pct'] = $grand['net_sales'] > 0
                ? round(($grand['net_profit'] / $grand['net_sales']) * 100, 1)
                : 0.0;
        } else {
            $grand['cogs'] = null;
            $grand['gross_profit'] = null;
            $grand['gross_margin_pct'] = null;
            $grand['net_profit'] = null;
            $grand['net_margin_pct'] = null;
            $grand['inventory_loss'] = null;
            $grand['inventory_gain'] = null;
        }

        // 7. BATCHED HIGH-SPEED CASH REGISTER SESSIONS (Replaces N+1 buildRegisterDetailsPayload loop)
        $registersList = $includeRegisters
            ? $this->batchLoadRegisterSummaries($dateStr, $dateEnd, $effectiveOutletIds, $tz)
            : [];

        // 8. FORMAT CLEAN EXECUTIVE TELEGRAM HTML MESSAGE
        $html = $this->formatDailySummaryHtml(
            $companyName,
            $dateStr,
            $scopeLabel,
            $triggeredBy,
            $grand,
            $outletsList,
            $registersList,
            $showProfit,
            $includeRegisters,
            $currency,
            $lang
        );

        return [
            'date' => $dateStr,
            'scope_label' => $scopeLabel,
            'language' => $lang,
            'currency' => $currency,
            'summary' => $grand,
            'outlets' => $outletsList,
            'registers' => $registersList,
            'message_html' => $html,
            'message_preview' => $this->htmlToPlainPreview($html),
        ];
    }

    /**
     * Load register session summaries in 4 batched indexed queries instead of 10+ N+1 queries per shift.
     */
    private function batchLoadRegisterSummaries(
        string $dateStr,
        string $dateEnd,
        array $effectiveOutletIds,
        string $tz
    ): array {
        $todayStr = Carbon::now($tz)->toDateString();

        $regQuery = CashRegister::query()
            ->where(function ($q) use ($dateStr, $dateEnd, $todayStr) {
                $q->whereBetween('opened_at', [$dateStr . ' 00:00:00', $dateEnd])
                    ->orWhereBetween('closed_at', [$dateStr . ' 00:00:00', $dateEnd]);
                if ($dateStr === $todayStr) {
                    $q->orWhere('status', 'open');
                }
            })
            ->orderByDesc('id');

        if (!empty($effectiveOutletIds)) {
            $regQuery->whereIn('warehouse_id', $effectiveOutletIds);
        }

        $registers = $regQuery->limit(25)->get();
        if ($registers->isEmpty()) {
            return [];
        }

        $regIds = $registers->pluck('id')->map(fn ($id) => (int)$id)->all();
        $userIds = $registers->pluck('user_id')->filter()->unique()->map(fn ($id) => (int)$id)->all();

        $userRolesById = !empty($userIds)
            ? DB::table('users')->whereIn('id', $userIds)->pluck('role', 'id')->all()
            : [];

        // Batched active sales per register
        $salesByReg = DB::table('sales')
            ->whereIn('cash_register_id', $regIds)
            ->where(function ($q) {
                $q->whereNull('status')->orWhereNotIn('status', self::EXCLUDED_DOC_STATUSES);
            })
            ->where(function ($q) {
                $q->whereNull('late_offline_reconciliation')->orWhere('late_offline_reconciliation', false);
            })
            ->groupBy('cash_register_id')
            ->selectRaw('cash_register_id, COUNT(id) as tx_count, COALESCE(SUM(grand_total), 0) as total_sales, COALESCE(SUM(paid_amount), 0) as total_paid')
            ->get()
            ->keyBy('cash_register_id');

        // Batched payment method breakdown from sale_payments
        $paymentRows = DB::table('sale_payments')
            ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
            ->whereIn('sale_payments.cash_register_id', $regIds)
            ->where(function ($q) {
                $q->whereNull('sales.status')->orWhereNotIn('sales.status', self::EXCLUDED_DOC_STATUSES);
            })
            ->where(function ($q) {
                $q->whereNull('sales.late_offline_reconciliation')->orWhere('sales.late_offline_reconciliation', false);
            })
            ->groupBy('sale_payments.cash_register_id', 'sale_payments.payment_type')
            ->selectRaw('sale_payments.cash_register_id, COALESCE(NULLIF(TRIM(sale_payments.payment_type), \'\'), \'Cash\') as method, COALESCE(SUM(sale_payments.amount), 0) as total_amount, COUNT(sale_payments.id) as tx_count')
            ->get();

        $paymentsByReg = [];
        foreach ($paymentRows as $pr) {
            $rId = (int)$pr->cash_register_id;
            $paymentsByReg[$rId][] = [
                'method' => (string)$pr->method,
                'amount' => round((float)$pr->total_amount, 2),
                'count' => (int)$pr->tx_count,
            ];
        }

        // Fallback payment breakdown for sales with paid_amount > 0 that lack sale_payments rows
        $fallbackPayRows = DB::table('sales')
            ->whereIn('sales.cash_register_id', $regIds)
            ->where('sales.paid_amount', '>', 0)
            ->where(function ($q) {
                $q->whereNull('sales.status')->orWhereNotIn('sales.status', self::EXCLUDED_DOC_STATUSES);
            })
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('sale_payments')
                    ->whereColumn('sale_payments.sale_id', 'sales.id');
            })
            ->groupBy('sales.cash_register_id', 'sales.payment_type')
            ->selectRaw('sales.cash_register_id, COALESCE(NULLIF(TRIM(sales.payment_type), \'\'), \'Cash\') as method, COALESCE(SUM(sales.paid_amount), 0) as total_amount, COUNT(sales.id) as tx_count')
            ->get();

        foreach ($fallbackPayRows as $fpr) {
            $rId = (int)$fpr->cash_register_id;
            $method = (string)$fpr->method;
            $merged = false;
            if (isset($paymentsByReg[$rId])) {
                foreach ($paymentsByReg[$rId] as &$existing) {
                    if (strcasecmp($existing['method'], $method) === 0) {
                        $existing['amount'] = round($existing['amount'] + (float)$fpr->total_amount, 2);
                        $existing['count'] += (int)$fpr->tx_count;
                        $merged = true;
                        break;
                    }
                }
                unset($existing);
            }
            if (!$merged) {
                $paymentsByReg[$rId][] = [
                    'method' => $method,
                    'amount' => round((float)$fpr->total_amount, 2),
                    'count' => (int)$fpr->tx_count,
                ];
            }
        }

        // Batched refunds per register
        $refundsByReg = DB::table('sale_returns')
            ->whereIn('cash_register_id', $regIds)
            ->where(function ($q) {
                $q->whereNull('status')->orWhereNotIn('status', self::EXCLUDED_DOC_STATUSES);
            })
            ->groupBy('cash_register_id')
            ->selectRaw('cash_register_id, COALESCE(SUM(COALESCE(NULLIF(paid_amount, 0), grand_total)), 0) as total_refunds')
            ->pluck('total_refunds', 'cash_register_id')
            ->all();

        $result = [];
        foreach ($registers as $reg) {
            $rId = (int)$reg->id;
            $saleRow = $salesByReg[$rId] ?? null;
            $totalSales = round((float)($saleRow->total_sales ?? 0), 2);
            $totalTx = (int)($saleRow->tx_count ?? 0);
            $totalRefunds = round((float)($refundsByReg[$rId] ?? 0), 2);
            $breakdown = $paymentsByReg[$rId] ?? [];
            $totalPayment = round(array_sum(array_column($breakdown, 'amount')), 2);
            if ($totalPayment <= 0 && $saleRow && (float)$saleRow->total_paid > 0) {
                $totalPayment = round((float)$saleRow->total_paid, 2);
            }

            $expected = $reg->expected_cash !== null ? round((float)$reg->expected_cash, 2) : null;
            $closing = $reg->closing_cash !== null ? round((float)$reg->closing_cash, 2) : null;
            $diff = $reg->cash_difference !== null
                ? round((float)$reg->cash_difference, 2)
                : ($expected !== null && $closing !== null ? round($closing - $expected, 2) : null);

            $result[] = [
                'id' => $rId,
                'reference_no' => (string)$reg->reference_no,
                'register_id' => (string)($reg->register_name ?: 'Register 01'),
                'warehouse_id' => $reg->warehouse_id ? (int)$reg->warehouse_id : null,
                'warehouse_name' => (string)($reg->warehouse_name ?: 'Main Branch Outlet'),
                'cashier_name' => (string)($reg->user_name ?: 'Admin'),
                'user_role' => (string)($userRolesById[(int)$reg->user_id] ?? 'Cashier'),
                'status' => strtolower((string)$reg->status) === 'open' ? 'Open' : 'Closed',
                'opened_at' => $reg->opened_at ? Carbon::parse($reg->opened_at)->toDateTimeString() : null,
                'closed_at' => $reg->closed_at ? Carbon::parse($reg->closed_at)->toDateTimeString() : null,
                'opening_cash' => round((float)($reg->opening_cash ?? 0), 2),
                'total_sales' => $totalSales,
                'total_transactions' => $totalTx,
                'total_refunds' => $totalRefunds,
                'total_payment' => $totalPayment,
                'expected_cash' => $expected,
                'closing_cash' => $closing,
                'difference' => $diff,
                'payment_breakdown' => $breakdown,
            ];
        }

        return $result;
    }

    /**
     * Format clean, scannable, accountant-reconciled Telegram HTML Daily Summary.
     */
    private function formatDailySummaryHtml(
        string $companyName,
        string $dateStr,
        string $scopeLabel,
        string $triggeredBy,
        array $grand,
        array $outlets,
        array $registers,
        bool $showProfit,
        bool $includeRegisters,
        string $currency,
        string $lang
    ): string {
        $isMy = ($lang === 'my');
        $sep = '━━━━━━━━━━━━━━━━━━━━';
        $lines = [];

        // ── HEADER ──────────────────────────────────────────────────────────
        $lines[] = $isMy
            ? "📊 <b>နေ့စဉ် လုပ်ငန်းစာရင်းချုပ်</b>"
            : "📊 <b>Daily Business Summary</b>";
        $lines[] = "🏢 <b>{$this->esc($companyName)}</b>";
        $lines[] = "📅 <code>{$this->esc($dateStr)}</code>  |  🏪 {$this->esc($scopeLabel)}";
        $lines[] = $sep;

        // ── 1. SALES & REVENUE ──────────────────────────────────────────────
        $lines[] = $isMy ? "💰 <b>အရောင်းနှင့် ဝင်ငွေစာရင်း</b>" : "💰 <b>Sales &amp; Revenue</b>";
        $invSales = (float)$grand['invoiced_sales'];
        $taxCol = (float)$grand['tax_collected'];
        $shipCol = (float)$grand['shipping_collected'];
        $saleRet = (float)$grand['sale_returns'];
        $netSales = (float)$grand['net_sales'];
        $salesPaid = (float)$grand['sales_paid'];
        $salesDue = (float)$grand['sales_due'];
        $ordersUnit = $isMy ? 'စောင်' : 'orders';

        $lines[] = "• " . ($isMy ? "ဘောင်ချာစုစုပေါင်း: " : "Invoiced Sales: ")
            . "<b>{$this->formatAmount($invSales, $currency)}</b> ({$grand['sales_count']} {$ordersUnit})";

        if ($taxCol > 0) {
            $lines[] = "• " . ($isMy ? "ကုန်သွယ်ခွန် (Tax): " : "Tax Collected: ")
                . "-{$this->formatAmount($taxCol, $currency)}";
        }
        if ($shipCol > 0) {
            $lines[] = "• " . ($isMy ? "ပို့ဆောင်ခ: " : "Shipping: ")
                . "-{$this->formatAmount($shipCol, $currency)}";
        }
        if ($saleRet > 0) {
            $lines[] = "• " . ($isMy ? "အရောင်းပြန်သွင်း: " : "Sale Returns: ")
                . "-{$this->formatAmount($saleRet, $currency)} ({$grand['sale_returns_count']})";
        }
        if ($taxCol > 0 || $shipCol > 0 || $saleRet > 0) {
            $lines[] = "• " . ($isMy ? "အသားတင် အရောင်းဝင်ငွေ: " : "Net Sales Revenue: ")
                . "<b>{$this->formatAmount($netSales, $currency)}</b>";
        }
        if ($invSales > 0 || $salesDue > 0) {
            $lines[] = "• " . ($isMy ? "ငွေရရှိပြီး: " : "Collected: ")
                . $this->formatAmount($salesPaid, $currency)
                . "  |  " . ($isMy ? "အကြွေးကျန်: " : "Credit Due: ")
                . $this->formatAmount($salesDue, $currency);
        }

        // ── 2. PURCHASES & OPERATING EXPENSES ───────────────────────────────
        $lines[] = "";
        $lines[] = $isMy ? "🛒 <b>အဝယ်နှင့် ကုန်ကျစရိတ်</b>" : "🛒 <b>Purchases &amp; Expenses</b>";
        $billsUnit = $isMy ? 'စောင်' : 'bills';
        $lines[] = "• " . ($isMy ? "စုစုပေါင်းအဝယ်: " : "Total Purchases: ")
            . "<b>{$this->formatAmount((float)$grand['gross_purchases'], $currency)}</b> ({$grand['purchases_count']} {$billsUnit})";
        if ((float)$grand['purchase_returns'] > 0) {
            $lines[] = "• " . ($isMy ? "အဝယ်ပြန်ပို့: " : "Purchase Returns: ")
                . "-{$this->formatAmount((float)$grand['purchase_returns'], $currency)}";
            $lines[] = "• " . ($isMy ? "အသားတင်အဝယ်: " : "Net Purchases: ")
                . "<b>{$this->formatAmount((float)$grand['net_purchases'], $currency)}</b>";
        }
        if ((float)$grand['purchases_due'] > 0) {
            $lines[] = "• " . ($isMy ? "ပေးရန်ကျန်ငွေ: " : "Supplier Payable: ")
                . $this->formatAmount((float)$grand['purchases_due'], $currency);
        }
        $lines[] = "• " . ($isMy ? "လုပ်ငန်းကုန်ကျစရိတ်: " : "Operating Expenses: ")
            . "<b>{$this->formatAmount((float)$grand['expenses'], $currency)}</b>"
            . ((int)$grand['expenses_count'] > 0 ? " ({$grand['expenses_count']})" : "");

        // ── 3. PROFIT & LOSS (EXACT ACCOUNTING RECONCILIATION) ──────────────
        if ($showProfit) {
            $cogs = (float)($grand['cogs'] ?? 0);
            $gp = (float)($grand['gross_profit'] ?? 0);
            $gm = (float)($grand['gross_margin_pct'] ?? 0);
            $exp = (float)$grand['expenses'];
            $invLoss = (float)($grand['inventory_loss'] ?? 0);
            $invGain = (float)($grand['inventory_gain'] ?? 0);
            $prVar = (float)($grand['purchase_return_variance'] ?? 0);
            $np = (float)($grand['net_profit'] ?? 0);
            $nm = (float)($grand['net_margin_pct'] ?? 0);
            $npIcon = $np >= 0 ? '📈' : '📉';

            $lines[] = "";
            $lines[] = "{$npIcon} <b>" . ($isMy ? 'အမြတ်အရှုံး အနှစ်ချုပ် (P&amp;L)' : 'Profit &amp; Loss Summary') . "</b>";
            $lines[] = "• " . ($isMy ? "အသားတင် အရောင်းဝင်ငွေ: " : "Net Sales Revenue: ")
                . $this->formatAmount($netSales, $currency);
            $cogsPrefix = $cogs > 0 ? '-' : '';
            $lines[] = "• " . ($isMy ? "ရောင်းရကုန်ကျစရိတ် (COGS): " : "Cost of Goods Sold (COGS): ")
                . "{$cogsPrefix}{$this->formatAmount($cogs, $currency)}";
            $lines[] = "• " . ($isMy ? "အကြမ်းအမြတ်: " : "Gross Profit: ")
                . "<b>{$this->formatAmount($gp, $currency)}</b>"
                . ($netSales > 0 ? " ({$gm}%)" : "");
            if ($exp > 0) {
                $lines[] = "• " . ($isMy ? "လုပ်ငန်းကုန်ကျစရိတ်: " : "Operating Expenses: ")
                    . "-{$this->formatAmount($exp, $currency)}";
            }
            if ($invLoss > 0) {
                $lines[] = "• " . ($isMy ? "စတော့လျော့နည်းဆုံးရှုံးမှု: " : "Inventory Shrinkage: ")
                    . "-{$this->formatAmount($invLoss, $currency)}";
            }
            if ($invGain > 0) {
                $lines[] = "• " . ($isMy ? "စတော့ပိုလျှံအမြတ်: " : "Inventory Overage: ")
                    . "+{$this->formatAmount($invGain, $currency)}";
            }
            if (abs($prVar) >= 0.01) {
                $lines[] = "• " . ($isMy ? "အဝယ်ပြန်ပို့ ကွာခြားချက်: " : "Purchase Return Variance: ")
                    . $this->formatSignedAmount($prVar, $currency);
            }
            $lines[] = "▸ " . ($isMy ? "အသားတင်အမြတ်: " : "Net Profit: ")
                . "<b>{$this->formatAmount($np, $currency)}</b>"
                . ($netSales > 0 ? " ({$nm}%)" : "");
        }

        // ── 4. PER-OUTLET BREAKDOWN ─────────────────────────────────────────
        $lines[] = "";
        $lines[] = $sep;
        $lines[] = $isMy ? "🏬 <b>ဆိုင်ခွဲအလိုက် အသေးစိတ်</b>" : "🏬 <b>Outlet Breakdown</b>";

        if (empty($outlets)) {
            $lines[] = $isMy
                ? "<i>ဤရက်စွဲအတွက် အရောင်း/အဝယ် မှတ်တမ်း မရှိသေးပါ။</i>"
                : "<i>No sales or purchase activity recorded for this date.</i>";
        } else {
            foreach ($outlets as $idx => $o) {
                if ($idx > 0) {
                    $lines[] = "";
                }
                $lines[] = "▸ <b>{$this->esc($o['outlet_name'])}</b>";

                $saleDetail = "<b>{$this->formatAmount((float)$o['invoiced_sales'], $currency)}</b>";
                if (abs((float)$o['invoiced_sales'] - (float)$o['net_sales']) >= 0.01) {
                    $saleDetail .= " (" . ($isMy ? "အသားတင် " : "Net ")
                        . $this->formatAmount((float)$o['net_sales'], $currency)
                        . " · {$o['sales_count']})";
                } else {
                    $saleDetail .= " ({$o['sales_count']})";
                }
                $lines[] = "  • " . ($isMy ? "အရောင်း: " : "Sales: ") . $saleDetail;

                $purchLine = "  • " . ($isMy ? "အဝယ်: " : "Purchases: ")
                    . $this->formatAmount((float)$o['gross_purchases'], $currency)
                    . " ({$o['purchases_count']})";
                if ((float)$o['expenses'] > 0) {
                    $purchLine .= "  |  " . ($isMy ? "ကုန်ကျစရိတ်: " : "Expenses: ")
                        . $this->formatAmount((float)$o['expenses'], $currency);
                }
                $lines[] = $purchLine;

                if ($showProfit) {
                    $lines[] = "  • " . ($isMy ? "အသားတင်အမြတ်: " : "Net Profit: ")
                        . "<b>{$this->formatAmount((float)($o['net_profit'] ?? 0), $currency)}</b>"
                        . " (COGS: {$this->formatAmount((float)($o['cogs'] ?? 0), $currency)})";
                }

                if (!empty($o['sales_users'])) {
                    $userParts = [];
                    foreach (array_slice($o['sales_users'], 0, 4) as $su) {
                        $userParts[] = $this->esc($su['name']) . " — " . $this->formatAmount((float)$su['total'], $currency) . " ({$su['count']})";
                    }
                    $lines[] = "  • " . ($isMy ? "အရောင်းဝန်ထမ်း: " : "Cashiers: ") . implode(', ', $userParts);
                }
                if (!empty($o['purchase_users'])) {
                    $pParts = [];
                    foreach (array_slice($o['purchase_users'], 0, 3) as $pu) {
                        $pParts[] = $this->esc($pu['name']) . " — " . $this->formatAmount((float)$pu['total'], $currency) . " ({$pu['count']})";
                    }
                    $lines[] = "  • " . ($isMy ? "အဝယ်ဝန်ထမ်း: " : "Buyers: ") . implode(', ', $pParts);
                }
            }
        }

        // ── 5. REGISTER / SHIFT ACCOUNTS ────────────────────────────────────
        if ($includeRegisters) {
            $lines[] = "";
            $lines[] = $sep;
            $lines[] = $isMy ? "🖥 <b>ကောင်တာ / Register စာရင်း</b>" : "🖥 <b>Register Accounts</b>";

            if (empty($registers)) {
                $lines[] = $isMy
                    ? "<i>ဤရက်စွဲအတွက် Register မှတ်တမ်း မရှိပါ။</i>"
                    : "<i>No register sessions found for this date.</i>";
            } else {
                foreach ($registers as $rIdx => $reg) {
                    if ($rIdx > 0) {
                        $lines[] = "";
                    }
                    $isOpen = strtolower((string)($reg['status'] ?? '')) === 'open';
                    $statusLabel = $isOpen
                        ? ($isMy ? '🟢 ဖွင့်ထားဆဲ' : '🟢 Open')
                        : ($isMy ? '🔒 ပိတ်ပြီး' : '🔒 Closed');
                    $regName = $this->esc((string)($reg['register_id'] ?? 'Register 01'));
                    $whName = $this->esc((string)($reg['warehouse_name'] ?? 'Main Branch Outlet'));
                    $cashier = $this->esc((string)($reg['cashier_name'] ?? 'Admin'));
                    $role = $this->esc((string)($reg['user_role'] ?? ''));
                    $openedShort = $this->esc($this->shortTime($reg['opened_at'] ?? null));
                    $closedShort = !empty($reg['closed_at'])
                        ? $this->esc($this->shortTime($reg['closed_at']))
                        : ($isMy ? 'ဖွင့်ထားဆဲ' : 'Active');

                    $lines[] = "▸ <b>{$regName}</b> [{$statusLabel}] — {$whName}";
                    $lines[] = "  • " . ($isMy ? "တာဝန်ခံ: " : "Cashier: ")
                        . $cashier . ($role !== '' ? " ({$role})" : "")
                        . "  |  <code>{$openedShort} → {$closedShort}</code>";

                    $payParts = [
                        ($isMy ? 'ဖွင့်ငွေ: ' : 'Opening: ') . $this->formatAmount((float)($reg['opening_cash'] ?? 0), $currency),
                    ];
                    foreach (($reg['payment_breakdown'] ?? []) as $pb) {
                        $m = $this->esc((string)($pb['method'] ?? 'Cash'));
                        $amt = (float)($pb['amount'] ?? 0);
                        if ($amt > 0 || strcasecmp($m, 'Cash') === 0) {
                            $payParts[] = "{$m}: <b>{$this->formatAmount($amt, $currency)}</b>";
                        }
                    }
                    $lines[] = "  • " . implode('  |  ', $payParts);

                    $lines[] = "  • " . ($isMy ? "အရောင်း: " : "Sales: ")
                        . "<b>{$this->formatAmount((float)($reg['total_sales'] ?? 0), $currency)}</b>"
                        . "  |  " . ($isMy ? "ပြန်အမ်း: " : "Refund: ")
                        . $this->formatAmount((float)($reg['total_refunds'] ?? 0), $currency);

                    if (isset($reg['expected_cash']) && $reg['expected_cash'] !== null) {
                        $expected = (float)$reg['expected_cash'];
                        $closing = isset($reg['closing_cash']) && $reg['closing_cash'] !== null ? (float)$reg['closing_cash'] : null;
                        $diff = isset($reg['difference']) && $reg['difference'] !== null ? (float)$reg['difference'] : null;

                        $reconLine = "  • " . ($isMy ? "မျှော်မှန်းငွေ: " : "Expected: ")
                            . $this->formatAmount($expected, $currency);
                        if ($closing !== null) {
                            $reconLine .= "  |  " . ($isMy ? "ပိတ်ငွေ: " : "Closing: ")
                                . "<b>{$this->formatAmount($closing, $currency)}</b>";
                        }
                        if ($diff !== null) {
                            $reconLine .= " ({$this->formatSignedAmount($diff, $currency)})";
                        }
                        $lines[] = $reconLine;
                    }
                }
            }
        }

        // ── FOOTER ──────────────────────────────────────────────────────────
        $lines[] = "";
        $lines[] = $sep;
        $lines[] = ($isMy ? "📤 ပေးပို့သူ: " : "📤 Sent By: ") . $this->esc($triggeredBy);

        return implode("\n", $lines);
    }

    /**
     * Build clean formatted Telegram HTML message for a single Register Session.
     */
    public function buildRegisterSessionMessage(
        array $reg,
        string $lang = 'my',
        string $triggeredBy = 'System',
        bool $isAutoClose = false
    ): string {
        $isMy = ($lang === 'my');
        $meta = $this->getStoreMeta();
        $currency = $meta['currency_symbol'];
        $companyName = $meta['company_name'];
        $sep = '━━━━━━━━━━━━━━━━━━━━';

        $isOpen = strtolower((string)($reg['status'] ?? 'Closed')) === 'open';
        $statusLabel = $isOpen
            ? ($isMy ? '🟢 ဖွင့်ထားဆဲ' : '🟢 Open')
            : ($isMy ? '🔒 ပိတ်ပြီး' : '🔒 Closed');
        $regName = $this->esc((string)($reg['register_id'] ?? 'Register 01'));
        $whName = $this->esc((string)($reg['warehouse_name'] ?? 'Main Branch Outlet'));
        $cashier = $this->esc((string)($reg['cashier_name'] ?? 'Admin'));
        $role = $this->esc((string)($reg['user_role'] ?? 'Cashier'));
        $opened = $this->esc((string)($reg['opened_at'] ?? '-'));
        $closed = !empty($reg['closed_at']) ? $this->esc((string)$reg['closed_at']) : ($isMy ? 'ဖွင့်ထားဆဲ' : 'Active');
        $dateLabel = $this->esc((string)($reg['date'] ?? substr($opened, 0, 10)));

        $header = $isAutoClose
            ? ($isMy ? "🔔 <b>ကောင်တာပိတ် စာရင်းချုပ်</b>" : "🔔 <b>Register Closed Summary</b>")
            : ($isMy ? "🧾 <b>ကောင်တာ အသေးစိတ် မှတ်တမ်း</b>" : "🧾 <b>Register Details Report</b>");

        $lines = [
            $header,
            "🏢 <b>{$this->esc($companyName)}</b>  |  <code>{$dateLabel}</code>",
            $sep,
            "• " . ($isMy ? "ကောင်တာ: " : "Register: ") . "<b>{$regName}</b> [{$statusLabel}]",
            "• " . ($isMy ? "ဆိုင်ခွဲ: " : "Outlet: ") . $whName,
            "• " . ($isMy ? "တာဝန်ခံ: " : "Cashier: ") . "{$cashier} ({$role})",
            "• " . ($isMy ? "အချိန်: " : "Timeline: ") . "<code>{$opened}</code> → <code>{$closed}</code>",
            $sep,
            $isMy ? "💳 <b>ငွေပေးချေမှု အသေးစိတ်</b>" : "💳 <b>Payment Breakdown</b>",
            "• " . ($isMy ? "ကောင်တာဖွင့်ငွေ: " : "Opening Cash: ") . "<b>{$this->formatAmount((float)($reg['opening_cash'] ?? 0), $currency)}</b>",
        ];

        foreach (($reg['payment_breakdown'] ?? []) as $pb) {
            $m = $this->esc((string)($pb['method'] ?? 'Cash'));
            $amt = (float)($pb['amount'] ?? 0);
            if ($amt > 0 || strcasecmp($m, 'Cash') === 0) {
                $lines[] = "• {$m}: <b>{$this->formatAmount($amt, $currency)}</b>";
            }
        }

        $lines[] = $sep;
        $lines[] = "• " . ($isMy ? "စုစုပေါင်းအရောင်း: " : "Total Sales: ")
            . "<b>{$this->formatAmount((float)($reg['total_sales'] ?? 0), $currency)}</b> (" . (int)($reg['total_transactions'] ?? 0) . " tx)";
        $lines[] = "• " . ($isMy ? "ပြန်အမ်းငွေ: " : "Total Refund: ")
            . $this->formatAmount((float)($reg['total_refunds'] ?? 0), $currency);
        $lines[] = "• " . ($isMy ? "စုစုပေါင်းငွေဝင်: " : "Total Payment: ")
            . "<b>{$this->formatAmount((float)($reg['total_payment'] ?? 0), $currency)}</b>";

        if (isset($reg['expected_cash']) && $reg['expected_cash'] !== null) {
            $diff = (float)($reg['difference'] ?? 0);
            $lines[] = $sep;
            $lines[] = $isMy ? "⚖️ <b>ငွေကိုက်ညှိမှု အနှစ်ချုပ်</b>" : "⚖️ <b>Cash Reconciliation</b>";
            $lines[] = "• " . ($isMy ? "မျှော်မှန်းငွေ: " : "Expected Cash: ") . "<b>{$this->formatAmount((float)$reg['expected_cash'], $currency)}</b>";
            $lines[] = "• " . ($isMy ? "ပိတ်ငွေ: " : "Closing Cash: ") . "<b>{$this->formatAmount((float)($reg['closing_cash'] ?? 0), $currency)}</b>";
            $lines[] = "• " . ($isMy ? "ကွာခြားချက်: " : "Variance: ") . "<b>{$this->formatSignedAmount($diff, $currency)}</b>";
        }

        $notes = trim((string)($reg['closing_notes'] ?? $reg['notes'] ?? ''));
        if ($notes !== '') {
            $lines[] = "• " . ($isMy ? "မှတ်ချက်: " : "Notes: ") . $this->esc($notes);
        }

        $lines[] = $sep;
        $lines[] = ($isMy ? "📤 ပေးပို့သူ: " : "📤 Sent By: ") . $this->esc($triggeredBy);

        return implode("\n", $lines);
    }

    public function htmlToPlainPreview(string $html): string
    {
        $stripped = strip_tags($html);
        return html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Evaluate custom daily schedule in the store's timezone and dispatch once per day when due.
     * Protected by fast 60s cache pre-check + atomic Cache::lock + DB idempotency date guard.
     */
    /**
     * Cached schedule metadata (1 hour TTL; explicitly invalidated on settings save & after auto-send).
     */
    public function getCachedScheduleMeta(): array
    {
        return Cache::remember(self::CACHE_KEY_SCHEDULE_META, 3600, function (): array {
            $s = TelegramSetting::current();
            return [
                'is_enabled' => (bool)$s->is_enabled,
                'auto_send_daily_enabled' => (bool)$s->auto_send_daily_enabled,
                'auto_send_daily_time' => $s->getNormalizedScheduleTime(),
                'last_auto_daily_sent_date' => $s->last_auto_daily_sent_date,
                'has_bot_token' => $s->getPlainBotToken() !== '',
            ];
        });
    }

    /**
     * Returns exact next-check delay so desktop/mobile clients sleep until the exact
     * scheduled time (or stay completely idle when disabled) instead of polling every minute.
     */
    public function getScheduleHint(?Carbon $nowOverride = null): array
    {
        $tz = $this->getStoreTimezone();
        $now = $nowOverride ? $nowOverride->copy()->setTimezone($tz) : Carbon::now($tz);
        $today = $now->toDateString();
        $meta = $this->getCachedScheduleMeta();

        $active = !empty($meta['is_enabled'])
            && !empty($meta['auto_send_daily_enabled'])
            && !empty($meta['has_bot_token']);

        $nextCheckInSeconds = null;
        if ($active) {
            [$hh, $mm] = array_pad(explode(':', (string)$meta['auto_send_daily_time']), 2, '00');
            $target = $now->copy()->setTime((int)$hh, (int)$mm, 5);

            if (($meta['last_auto_daily_sent_date'] ?? null) === $today) {
                $target->addDay();
                $nextCheckInSeconds = max(60, (int)$now->diffInSeconds($target, false));
            } elseif ($now->lt($target)) {
                $nextCheckInSeconds = max(5, (int)$now->diffInSeconds($target, false));
            } else {
                // Target time already passed today but send did not succeed (e.g. offline/no recipients): back off 15 min
                $nextCheckInSeconds = 900;
            }
        }

        return [
            'is_enabled' => (bool)$meta['is_enabled'],
            'auto_send_daily_enabled' => (bool)$meta['auto_send_daily_enabled'],
            'auto_send_daily_time' => (string)$meta['auto_send_daily_time'],
            'last_auto_daily_sent_date' => $meta['last_auto_daily_sent_date'],
            'has_bot_token' => (bool)$meta['has_bot_token'],
            'next_check_in_seconds' => $nextCheckInSeconds,
        ];
    }

    public function dispatchScheduledDailyIfDue(bool $force = false, ?Carbon $nowOverride = null): array
    {
        $tz = $this->getStoreTimezone();
        $now = $nowOverride ? $nowOverride->copy()->setTimezone($tz) : Carbon::now($tz);
        $today = $now->toDateString();
        $currentHHMM = $now->format('H:i');

        // Ultra-fast <1ms pre-check (0 DB queries when not due; invalidated immediately on settings save / send)
        if (!$force) {
            $schedMeta = $this->getCachedScheduleMeta();

            if (!$schedMeta['is_enabled'] || !$schedMeta['auto_send_daily_enabled'] || !$schedMeta['has_bot_token']) {
                return ['ok' => false, 'skipped' => true, 'reason' => 'schedule_disabled', 'sent_count' => 0, 'failed_count' => 0];
            }
            if ($schedMeta['last_auto_daily_sent_date'] === $today) {
                return ['ok' => false, 'skipped' => true, 'reason' => 'already_sent_today', 'sent_count' => 0, 'failed_count' => 0];
            }
            if ($currentHHMM < $schedMeta['auto_send_daily_time']) {
                return [
                    'ok' => false,
                    'skipped' => true,
                    'reason' => 'before_scheduled_time',
                    'scheduled_time' => $schedMeta['auto_send_daily_time'],
                    'current_time' => $currentHHMM,
                    'sent_count' => 0,
                    'failed_count' => 0,
                ];
            }
        }

        // Atomic lock prevents duplicate delivery if cron & desktop heartbeat overlap at the exact same second
        $lock = Cache::lock('telegram:auto_daily_dispatch_lock', 30);
        if (!$lock->get()) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'dispatch_in_progress', 'sent_count' => 0, 'failed_count' => 0];
        }

        try {
            $setting = TelegramSetting::current()->fresh() ?? TelegramSetting::current();
            $targetTime = $setting->getNormalizedScheduleTime();

            if (!$force) {
                if (!$setting->is_enabled || !$setting->auto_send_daily_enabled) {
                    return ['ok' => false, 'skipped' => true, 'reason' => 'schedule_disabled', 'sent_count' => 0, 'failed_count' => 0];
                }
                if ($setting->last_auto_daily_sent_date === $today) {
                    return ['ok' => false, 'skipped' => true, 'reason' => 'already_sent_today', 'sent_count' => 0, 'failed_count' => 0];
                }
                if ($currentHHMM < $targetTime) {
                    return ['ok' => false, 'skipped' => true, 'reason' => 'before_scheduled_time', 'sent_count' => 0, 'failed_count' => 0];
                }
            }

            $displayClock = $this->formatDisplayClock($targetTime);
            $triggeredBy = ($setting->message_language ?: 'my') === 'my'
                ? "အလိုအလျောက် အချိန်ဇယား ({$displayClock})"
                : "Auto Schedule ({$displayClock})";

            $result = $this->sendDailySummary(
                $today,
                null,
                [],
                true,
                $triggeredBy
            );

            if (($result['sent_count'] ?? 0) > 0) {
                $setting->last_auto_daily_sent_date = $today;
                $setting->last_auto_daily_sent_at = $now->copy();
                $setting->save();
                $this->clearCaches();
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function resolveCaBundle(): string|bool
    {
        $candidates = array_filter([
            (string)env('TELEGRAM_CA_BUNDLE', ''),
            (string)ini_get('curl.cainfo'),
            (string)ini_get('openssl.cafile'),
            storage_path('certs/cacert.pem'),
            'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
        ]);

        foreach ($candidates as $path) {
            $trimmed = trim($path);
            if ($trimmed !== '' && is_file($trimmed) && is_readable($trimmed)) {
                return $trimmed;
            }
        }

        return true;
    }

    private function sanitizeErrorMessage(string $message): string
    {
        return (string)preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot[REDACTED]', $message);
    }

    /**
     * Send an HTML message to a single Telegram chat_id (auto-chunks messages > 3900 chars).
     */
    public function sendMessage(string $botToken, string $chatId, string $htmlMessage): array
    {
        $token = trim($botToken);
        $target = trim($chatId);

        if ($token === '') {
            return ['ok' => false, 'error' => 'Telegram Bot Token is not configured.'];
        }
        if ($target === '') {
            return ['ok' => false, 'error' => 'Telegram Chat ID is empty.'];
        }

        $chunks = $this->splitMessageChunks($htmlMessage, 3900);
        $endpoint = "https://api.telegram.org/bot{$token}/sendMessage";
        $verifyOption = $this->resolveCaBundle();

        foreach ($chunks as $chunk) {
            try {
                $response = Http::connectTimeout(3)
                    ->timeout(5)
                    ->withOptions(['verify' => $verifyOption])
                    ->acceptJson()
                    ->post($endpoint, [
                        'chat_id' => $target,
                        'text' => $chunk,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);

                $body = $response->json();
                if (!$response->successful() || empty($body['ok'])) {
                    $desc = is_array($body) && !empty($body['description'])
                        ? (string)$body['description']
                        : ('HTTP ' . $response->status());
                    return ['ok' => false, 'error' => $this->sanitizeErrorMessage($desc)];
                }
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $this->sanitizeErrorMessage($e->getMessage())];
            }
        }

        return ['ok' => true, 'error' => null];
    }

    private function splitMessageChunks(string $text, int $maxLength = 3900): array
    {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return [$text];
        }

        $lines = explode("\n", $text);
        $chunks = [];
        $current = '';

        foreach ($lines as $line) {
            $candidate = $current === '' ? $line : ($current . "\n" . $line);
            if (mb_strlen($candidate, 'UTF-8') > $maxLength && $current !== '') {
                $chunks[] = $current;
                $current = $line;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Dispatch Daily Business Summary to all matching active recipients (respecting per-recipient outlet scope).
     */
    public function sendDailySummary(
        string $date,
        ?int $requestedOutletId,
        ?array $allowedOutletIds,
        bool $canViewCost,
        string $triggeredBy,
        ?array $recipientIds = null,
        ?bool $overrideIncludeProfit = null,
        ?bool $overrideIncludeRegisters = null,
        ?string $overrideLang = null
    ): array {
        $allowedOutletIds = $allowedOutletIds ?? [];
        $setting = TelegramSetting::current();
        $botToken = $setting->getPlainBotToken();
        if ($botToken === '') {
            return [
                'ok' => false,
                'message' => 'Telegram Bot Token is not configured. Please save your Bot Token in Telegram Settings first.',
                'sent_count' => 0,
                'failed_count' => 0,
                'results' => [],
            ];
        }

        $recipientsQuery = TelegramRecipient::query()
            ->where('is_active', true)
            ->where('notify_daily_summary', true)
            ->orderBy('id');

        if (!empty($recipientIds)) {
            $recipientsQuery->whereIn('id', $recipientIds);
        }

        $recipients = $recipientsQuery->get();
        if ($recipients->isEmpty()) {
            return [
                'ok' => false,
                'message' => 'No active Telegram recipients found for Daily Summary.',
                'sent_count' => 0,
                'failed_count' => 0,
                'results' => [],
            ];
        }

        $includeProfit = $overrideIncludeProfit ?? (bool)$setting->include_profit;
        $includeRegisters = $overrideIncludeRegisters ?? (bool)$setting->include_registers;
        $lang = $overrideLang ?: ((string)$setting->message_language ?: 'my');

        $payloadCache = [];
        $results = [];
        $sentCount = 0;
        $failedCount = 0;

        foreach ($recipients as $recipient) {
            $recipientOutletId = $recipient->outlet_id ? (int)$recipient->outlet_id : null;

            if ($requestedOutletId !== null && $requestedOutletId > 0) {
                if ($recipientOutletId !== null && $recipientOutletId !== $requestedOutletId) {
                    continue;
                }
                $targetOutletId = $requestedOutletId;
            } else {
                if ($recipientOutletId !== null && !empty($allowedOutletIds) && !in_array($recipientOutletId, $allowedOutletIds, true)) {
                    continue;
                }
                $targetOutletId = $recipientOutletId;
            }

            $cacheKey = (string)($targetOutletId ?? 'all');
            if (!isset($payloadCache[$cacheKey])) {
                $payloadCache[$cacheKey] = $this->buildDailyBusinessSummary(
                    $date,
                    $targetOutletId,
                    $allowedOutletIds,
                    $canViewCost,
                    $includeProfit,
                    $includeRegisters,
                    $lang,
                    $triggeredBy
                );
            }

            $summaryPayload = $payloadCache[$cacheKey];
            $sendRes = $this->sendMessage($botToken, $recipient->chat_id, $summaryPayload['message_html']);
            $status = $sendRes['ok'] ? 'sent' : 'failed';

            if ($sendRes['ok']) {
                $sentCount++;
            } else {
                $failedCount++;
            }

            TelegramNotificationLog::create([
                'event_type' => 'daily_summary',
                'recipient_name' => $recipient->name,
                'chat_id' => $recipient->chat_id,
                'outlet_name' => $summaryPayload['scope_label'],
                'triggered_by' => $triggeredBy,
                'status' => $status,
                'message_preview' => mb_substr($summaryPayload['message_preview'], 0, 1200, 'UTF-8'),
                'error_message' => $sendRes['error'],
                'sent_at' => now(),
            ]);

            $results[] = [
                'recipient_id' => (int)$recipient->id,
                'recipient_name' => $recipient->name,
                'chat_id' => $recipient->chat_id,
                'outlet_scope' => $summaryPayload['scope_label'],
                'status' => $status,
                'error' => $sendRes['error'],
            ];
        }

        if (empty($results)) {
            return [
                'ok' => false,
                'message' => 'No active recipients matched the selected outlet filter.',
                'sent_count' => 0,
                'failed_count' => 0,
                'results' => [],
            ];
        }

        return [
            'ok' => $sentCount > 0,
            'message' => $sentCount > 0
                ? "Sent Telegram notification to {$sentCount} recipient(s)" . ($failedCount > 0 ? " ({$failedCount} failed)." : ".")
                : "Failed to send Telegram notification ({$failedCount} failed).",
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }

    /**
     * Dispatch Register Session details to matching active recipients (manual send & auto-close hook).
     */
    public function sendRegisterNotification(
        array $registerPayload,
        string $triggeredBy,
        bool $isAutoClose = false
    ): array {
        $setting = TelegramSetting::current();
        if ($isAutoClose && (!$setting->is_enabled || !$setting->auto_send_register_close)) {
            return ['ok' => false, 'skipped' => true, 'sent_count' => 0, 'failed_count' => 0, 'results' => []];
        }

        $botToken = $setting->getPlainBotToken();
        if ($botToken === '') {
            return [
                'ok' => false,
                'message' => 'Telegram Bot Token is not configured.',
                'sent_count' => 0,
                'failed_count' => 0,
                'results' => [],
            ];
        }

        $regWarehouseId = (int)($registerPayload['warehouse_id'] ?? 0);
        $recipients = TelegramRecipient::query()
            ->where('is_active', true)
            ->when($isAutoClose, fn ($q) => $q->where('notify_register_close', true))
            ->where(function ($q) use ($regWarehouseId) {
                $q->whereNull('outlet_id');
                if ($regWarehouseId > 0) {
                    $q->orWhere('outlet_id', $regWarehouseId);
                }
            })
            ->orderBy('id')
            ->get();

        if ($recipients->isEmpty()) {
            return [
                'ok' => false,
                'message' => 'No active Telegram recipients configured for this outlet.',
                'sent_count' => 0,
                'failed_count' => 0,
                'results' => [],
            ];
        }

        $lang = (string)($setting->message_language ?: 'my');
        $html = $this->buildRegisterSessionMessage($registerPayload, $lang, $triggeredBy, $isAutoClose);
        $preview = mb_substr($this->htmlToPlainPreview($html), 0, 1200, 'UTF-8');
        $outletName = (string)($registerPayload['warehouse_name'] ?? 'Main Branch Outlet');

        $sentCount = 0;
        $failedCount = 0;
        $results = [];

        foreach ($recipients as $recipient) {
            $sendRes = $this->sendMessage($botToken, $recipient->chat_id, $html);
            $status = $sendRes['ok'] ? 'sent' : 'failed';
            if ($sendRes['ok']) {
                $sentCount++;
            } else {
                $failedCount++;
            }

            TelegramNotificationLog::create([
                'event_type' => 'register_close',
                'recipient_name' => $recipient->name,
                'chat_id' => $recipient->chat_id,
                'outlet_name' => $outletName,
                'triggered_by' => $triggeredBy,
                'status' => $status,
                'message_preview' => $preview,
                'error_message' => $sendRes['error'],
                'sent_at' => now(),
            ]);

            $results[] = [
                'recipient_id' => (int)$recipient->id,
                'recipient_name' => $recipient->name,
                'chat_id' => $recipient->chat_id,
                'status' => $status,
                'error' => $sendRes['error'],
            ];
        }

        return [
            'ok' => $sentCount > 0,
            'message' => $sentCount > 0
                ? "Register summary sent to {$sentCount} Telegram recipient(s)."
                : "Failed to send register summary to Telegram.",
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }

    /**
     * Send a connection test message to a specific chat_id or all active recipients.
     */
    public function sendTestMessage(
        ?string $chatId,
        ?string $recipientName,
        ?string $overrideBotToken,
        string $triggeredBy
    ): array {
        $setting = TelegramSetting::current();
        $token = trim((string)$overrideBotToken);
        if ($token === '' || str_contains($token, '****')) {
            $token = $setting->getPlainBotToken();
        }

        if ($token === '') {
            return [
                'ok' => false,
                'message' => 'Please enter and save a valid Telegram Bot Token first.',
                'sent_count' => 0,
                'failed_count' => 0,
                'results' => [],
            ];
        }

        $targets = [];
        if ($chatId !== null && trim($chatId) !== '') {
            $targets[] = [
                'name' => trim((string)($recipientName ?: 'Test Recipient')),
                'chat_id' => trim($chatId),
            ];
        } else {
            foreach (TelegramRecipient::where('is_active', true)->orderBy('id')->get() as $r) {
                $targets[] = [
                    'name' => $r->name,
                    'chat_id' => $r->chat_id,
                ];
            }
        }

        if (empty($targets)) {
            return [
                'ok' => false,
                'message' => 'Please add at least one Telegram User / Chat ID to test.',
                'sent_count' => 0,
                'failed_count' => 0,
                'results' => [],
            ];
        }

        $meta = $this->getStoreMeta();
        $companyName = $this->esc($meta['company_name']);
        $isMy = ($setting->message_language ?: 'my') === 'my';
        $timeStr = Carbon::now($meta['timezone'])->format('Y-m-d H:i:s');
        $html = $isMy
            ? "✅ <b>Telegram ချိတ်ဆက်မှု အောင်မြင်ပါသည်</b>\n🏢 {$companyName}\n👤 စမ်းသပ်သူ: {$this->esc($triggeredBy)}\n🕒 <code>{$timeStr}</code>\n\nနေ့စဉ် အရောင်း/အဝယ်/အမြတ် နှင့် ကောင်တာ စာရင်းချုပ်များကို ဤနေရာတွင် လက်ခံရရှိပါမည်။"
            : "✅ <b>Telegram Connection Successful</b>\n🏢 {$companyName}\n👤 Tested by: {$this->esc($triggeredBy)}\n🕒 <code>{$timeStr}</code>\n\nDaily sales, purchases, profit, and register shift reports will be delivered here.";

        $preview = $this->htmlToPlainPreview($html);
        $sentCount = 0;
        $failedCount = 0;
        $results = [];

        foreach ($targets as $t) {
            $sendRes = $this->sendMessage($token, $t['chat_id'], $html);
            $status = $sendRes['ok'] ? 'sent' : 'failed';
            if ($sendRes['ok']) {
                $sentCount++;
            } else {
                $failedCount++;
            }

            TelegramNotificationLog::create([
                'event_type' => 'test',
                'recipient_name' => $t['name'],
                'chat_id' => $t['chat_id'],
                'outlet_name' => null,
                'triggered_by' => $triggeredBy,
                'status' => $status,
                'message_preview' => mb_substr($preview, 0, 1200, 'UTF-8'),
                'error_message' => $sendRes['error'],
                'sent_at' => now(),
            ]);

            $results[] = [
                'recipient_name' => $t['name'],
                'chat_id' => $t['chat_id'],
                'status' => $status,
                'error' => $sendRes['error'],
            ];
        }

        return [
            'ok' => $sentCount > 0,
            'message' => $sentCount > 0
                ? "Test message sent successfully ({$sentCount} delivered)."
                : "Test message failed: " . ($results[0]['error'] ?? 'Unknown error'),
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }
}
