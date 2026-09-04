<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Supplier;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Ultra-fast High-Performance Executive Dashboard Analytics Pipeline
     * Single-pass SQL aggregation & sub-5ms caching execution
     */
    public function dashboardSummary(Request $request)
    {
        $warehouse = $request->query('warehouse', 'All');
        $year = (int)$request->query('year', Carbon::now()->year);
        $forceRefresh = $request->boolean('force_refresh') || $request->query('force_refresh') === 'true' || $request->query('force_refresh') === '1';

        $cacheKey = "pos_dashboard_summary_" . md5($warehouse . '_' . $year);
        if (!$forceRefresh) {
            try {
                $cachedData = Cache::get($cacheKey);
                if ($cachedData && is_array($cachedData)) {
                    return response()->json($cachedData);
                }
            } catch (\Throwable $e) {}
        }

        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();

        // 1. Base query scopes
        $salesQuery = Sale::query();
        $purchQuery = Purchase::query();
        $expQuery = Expense::query();

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $salesQuery->where('warehouse_name', $warehouse);
            $purchQuery->where('warehouse_name', $warehouse);
            $expQuery->where('warehouse_name', $warehouse);
        }

        // 2. Single-Pass Consolidated Sales Aggregation
        $salesAgg = (clone $salesQuery)
            ->selectRaw('
                COALESCE(SUM(grand_total), 0) as total_sales,
                COALESCE(SUM(due_amount), 0) as customer_due,
                COALESCE(SUM(CASE WHEN date = ? THEN grand_total ELSE 0 END), 0) as today_sales,
                COALESCE(SUM(CASE WHEN date = ? THEN 1 ELSE 0 END), 0) as today_sales_count,
                COALESCE(SUM(CASE WHEN date = ? THEN grand_total ELSE 0 END), 0) as yesterday_sales
            ', [$today, $today, $yesterday])
            ->first();

        $totalSales = (float)($salesAgg->total_sales ?? 0);
        $customerDue = (float)($salesAgg->customer_due ?? 0);
        $todaySales = (float)($salesAgg->today_sales ?? 0);
        $todaySalesCount = (int)($salesAgg->today_sales_count ?? 0);
        $yesterdaySales = (float)($salesAgg->yesterday_sales ?? 0);

        $salesGrowthPct = $yesterdaySales > 0
            ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100, 1)
            : ($todaySales > 0 ? 100.0 : 0.0);

        $totalSaleReturns = 0.0;
        try {
            $srQuery = DB::table('sale_returns');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $srQuery->where('warehouse_name', $warehouse);
            }
            $totalSaleReturns = (float)$srQuery->sum('grand_total');
        } catch (\Throwable $e) {}

        $netSales = max(0.0, $totalSales - $totalSaleReturns);

        // 3. Single-Pass Consolidated Purchases Aggregation
        $purchAgg = (clone $purchQuery)
            ->selectRaw('
                COALESCE(SUM(grand_total), 0) as total_purchases,
                COALESCE(SUM(due_amount), 0) as supplier_due,
                COALESCE(SUM(CASE WHEN date = ? THEN grand_total ELSE 0 END), 0) as today_purchases
            ', [$today])
            ->first();

        $totalPurchases = (float)($purchAgg->total_purchases ?? 0);
        $supplierDue = (float)($purchAgg->supplier_due ?? 0);
        $todayPurchases = (float)($purchAgg->today_purchases ?? 0);

        $totalPurchaseReturns = 0.0;
        try {
            $prQuery = DB::table('purchase_returns');
            if ($warehouse !== 'All' && !empty($warehouse)) {
                $prQuery->where('warehouse_name', $warehouse);
            }
            $totalPurchaseReturns = (float)$prQuery->sum('grand_total');
        } catch (\Throwable $e) {}

        $netPurchases = max(0.0, $totalPurchases - $totalPurchaseReturns);

        // 4. Single-Pass Consolidated Expenses Aggregation
        $expAgg = (clone $expQuery)
            ->selectRaw('
                COALESCE(SUM(amount), 0) as total_expenses,
                COALESCE(SUM(CASE WHEN date = ? THEN amount ELSE 0 END), 0) as today_expenses
            ', [$today])
            ->first();

        $totalExpenses = (float)($expAgg->total_expenses ?? 0);
        $todayExpenses = (float)($expAgg->today_expenses ?? 0);

        $netProfit = round($netSales - $netPurchases - $totalExpenses, 2);
        $profitMarginPct = $netSales > 0 ? round(($netProfit / $netSales) * 100, 2) : 0.0;
        $todayProfit = round($todaySales - $todayPurchases - $todayExpenses, 2);

        // 4. Monthly 12-Month Trends for Selected Year
        $monthlySales = array_fill(0, 12, 0.0);
        $monthlyPurchases = array_fill(0, 12, 0.0);
        $monthlyExpenses = array_fill(0, 12, 0.0);
        $monthlyProfit = array_fill(0, 12, 0.0);

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $monthExpr = $isSqlite ? DB::raw("CAST(strftime('%m', date) AS integer) as m") : DB::raw('MONTH(date) as m');
        $monthGroup = $isSqlite ? DB::raw("CAST(strftime('%m', date) AS integer)") : DB::raw('MONTH(date)');

        // Group sales by month
        $salesByMonth = (clone $salesQuery)
            ->whereYear('date', $year)
            ->select($monthExpr, DB::raw('SUM(grand_total) as total'))
            ->groupBy($monthGroup)
            ->pluck('total', 'm');

        foreach ($salesByMonth as $m => $tot) {
            $mIdx = (int)$m;
            if ($mIdx >= 1 && $mIdx <= 12) {
                $monthlySales[$mIdx - 1] = (float)$tot;
            }
        }

        // Group purchases by month
        $purchasesByMonth = (clone $purchQuery)
            ->whereYear('date', $year)
            ->select($monthExpr, DB::raw('SUM(grand_total) as total'))
            ->groupBy($monthGroup)
            ->pluck('total', 'm');

        foreach ($purchasesByMonth as $m => $tot) {
            $mIdx = (int)$m;
            if ($mIdx >= 1 && $mIdx <= 12) {
                $monthlyPurchases[$mIdx - 1] = (float)$tot;
            }
        }

        // Group expenses by month
        $expensesByMonth = (clone $expQuery)
            ->whereYear('date', $year)
            ->select($monthExpr, DB::raw('SUM(amount) as total'))
            ->groupBy($monthGroup)
            ->pluck('total', 'm');

        foreach ($expensesByMonth as $m => $tot) {
            $mIdx = (int)$m;
            if ($mIdx >= 1 && $mIdx <= 12) {
                $monthlyExpenses[$mIdx - 1] = (float)$tot;
            }
        }

        // Calculate monthly profit
        for ($i = 0; $i < 12; $i++) {
            $monthlyProfit[$i] = round($monthlySales[$i] - $monthlyPurchases[$i] - $monthlyExpenses[$i], 2);
        }

        // 5. Payment Methods Tender Mix
        $rawPaymentMethods = (clone $salesQuery)
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

        // 6. Real Top Selling Products (Single SQL JOIN without N+1)
        $topProducts = [];
        try {
            $topProductsRaw = DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
                ->when($warehouse !== 'All' && !empty($warehouse), function ($q) use ($warehouse) {
                    $q->where('sales.warehouse_name', $warehouse);
                })
                ->select(
                    'sale_items.product_id',
                    'sale_items.product_name',
                    'sale_items.product_code',
                    'products.alternative_name',
                    'products.brand_name',
                    'products.category_name',
                    'products.retail_price',
                    'products.stock_quantity',
                    'products.image_url',
                    DB::raw('SUM(sale_items.quantity) as total_qty'),
                    DB::raw('SUM(sale_items.subtotal) as total_rev')
                )
                ->groupBy(
                    'sale_items.product_id',
                    'sale_items.product_name',
                    'sale_items.product_code',
                    'products.alternative_name',
                    'products.brand_name',
                    'products.category_name',
                    'products.retail_price',
                    'products.stock_quantity',
                    'products.image_url'
                )
                ->orderByDesc('total_qty')
                ->limit(6)
                ->get();

            if ($topProductsRaw->isNotEmpty()) {
                foreach ($topProductsRaw as $tp) {
                    $topProducts[] = [
                        'id' => (int)$tp->product_id,
                        'name' => $tp->product_name ?? 'Product',
                        'code' => $tp->product_code ?? '',
                        'alternative_name' => $tp->alternative_name ?? '',
                        'brand' => $tp->brand_name ?? 'Generic',
                        'category' => $tp->category_name ?? 'General',
                        'price' => (float)($tp->retail_price ?? 0),
                        'stock_quantity' => (float)($tp->stock_quantity ?? 0),
                        'total_quantity' => (float)$tp->total_qty,
                        'total_revenue' => (float)$tp->total_rev,
                        'image_url' => $tp->image_url ?? '',
                    ];
                }
            }
        } catch (\Throwable $e) {}

        if (empty($topProducts)) {
            $topProducts = Product::orderBy('stock_quantity', 'desc')->limit(5)->get()->map(function ($prod) {
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
            });
        }

        // 7. Recent Sales Transactions
        $recentSales = (clone $salesQuery)
            ->orderBy('id', 'desc')
            ->limit(6)
            ->get()
            ->map(function ($s) {
                return [
                    'id' => $s->id,
                    'reference_no' => $s->reference_no,
                    'customer_name' => $s->customer_name ?? 'Walk-in Customer',
                    'date' => $s->date,
                    'grand_total' => (float)$s->grand_total,
                    'paid_amount' => (float)$s->paid_amount,
                    'due_amount' => (float)$s->due_amount,
                    'payment_type' => $s->payment_type ?? 'Cash',
                    'payment_status' => $s->payment_status ?? 'Paid',
                    'status' => $s->status ?? 'Completed',
                ];
            });

        // 8. Low Stock & Out of Stock Products (Single Products + Product Variants - Optimized selective columns)
        $singleLowStock = Product::where(function ($q) {
                $q->where('has_variants', false)->orWhereNull('has_variants');
            })
            ->where(function ($q) {
                $q->whereColumn('stock_quantity', '<=', 'alert_quantity')
                  ->orWhereColumn('stock_quantity', '<=', 'stock_alert')
                  ->orWhereColumn('stock_quantity', '<=', 'low_qty')
                  ->orWhere('stock_quantity', '<=', 0);
            })
            ->select([
                'id', 'name', 'code', 'sku', 'alternative_name',
                'category_name', 'brand_name', 'unit_name',
                'stock_quantity', 'alert_quantity', 'stock_alert', 'low_qty',
                'retail_price', 'selling_price'
            ])
            ->limit(15)
            ->get()
            ->map(function ($p) {
                $alertQty = (float)($p->alert_quantity ?: ($p->stock_alert ?: ($p->low_qty ?: 10)));
                $stockQty = (float)($p->stock_quantity ?? 0);
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
            $variantLowStock = DB::table('product_variants')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->leftJoin('stock_balances', 'product_variants.id', '=', 'stock_balances.product_variant_id')
                ->where('products.has_variants', true)
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
                    'products.retail_price as product_price',
                    DB::raw('COALESCE(SUM(stock_balances.quantity), 0) as current_stock')
                )
                ->groupBy(
                    'product_variants.id',
                    'products.id',
                    'products.name',
                    'product_variants.name',
                    'product_variants.sku',
                    'product_variants.barcode',
                    'products.code',
                    'products.alternative_name',
                    'products.category_name',
                    'products.brand_name',
                    'products.unit_name',
                    'products.alert_quantity',
                    'products.stock_alert',
                    'products.low_qty',
                    'product_variants.selling_price',
                    'products.retail_price'
                )
                ->havingRaw('current_stock <= COALESCE(products.alert_quantity, products.stock_alert, products.low_qty, 10)')
                ->limit(15)
                ->get()
                ->map(function ($v) {
                    $alertQty = (float)($v->alert_quantity ?: ($v->stock_alert ?: ($v->low_qty ?: 10)));
                    $stockQty = (float)$v->current_stock;
                    $displayName = $v->product_name . ' (' . $v->variant_name . ')';
                    return [
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
                    ];
                });
        } catch (\Throwable $e) {}

        // Combine both single & variation alerts, sort by stock ASC (out of stock first, then lowest stock)
        $allLowStock = $singleLowStock->concat($variantLowStock)
            ->sortBy('stock_quantity')
            ->values();

        $lowStockProducts = $allLowStock->take(10)->values();

        // 9. Operational & Inventory Metrics (Single Consolidated SQL Query)
        $inventoryAgg = DB::table('products')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('has_variants', false)->orWhereNull('has_variants');
            })
            ->selectRaw('COUNT(*) as total_products, COALESCE(SUM(stock_quantity), 0) as total_stock')
            ->first();

        $totalProducts = (int)($inventoryAgg->total_products ?? 0);
        $singleInStock = (float)($inventoryAgg->total_stock ?? 0);
        
        $totalVariants = 0;
        try {
            $totalVariants = DB::table('product_variants')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->whereNull('products.deleted_at')
                ->whereNull('product_variants.deleted_at')
                ->count();
        } catch (\Throwable $e) {}

        $variantInStock = 0.0;
        try {
            $variantInStock = (float)DB::table('stock_balances')
                ->whereNotNull('product_variant_id')
                ->sum('quantity');
        } catch (\Throwable $e) {}

        $totalInStockUnits = $singleInStock + $variantInStock;
        $lowStockCount = $allLowStock->count();
        $outOfStockCount = $allLowStock->where('stock_quantity', '<=', 0)->count();
        $totalCustomers = Customer::count();
        $totalSuppliers = Supplier::count();
        $totalOutlets = 1;
        try {
            $totalOutlets = DB::table('outlets')->count() ?: 1;
        } catch (\Throwable $e) {}

        $responsePayload = [
            'status' => 'success',
            'data' => [
                'total_sales' => $totalSales,
                'total_sale_returns' => $totalSaleReturns,
                'net_sales' => $netSales,
                'total_purchases' => $totalPurchases,
                'total_purchase_returns' => $totalPurchaseReturns,
                'net_purchases' => $netPurchases,
                'total_expenses' => $totalExpenses,
                'net_profit' => $netProfit,
                'profit_margin_pct' => $profitMarginPct,
                'customer_due' => $customerDue,
                'supplier_due' => $supplierDue,

                'today_sales' => $todaySales,
                'today_sales_count' => $todaySalesCount,
                'today_purchases' => $todayPurchases,
                'today_expenses' => $todayExpenses,
                'today_profit' => $todayProfit,
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
                    'purchases' => $monthlyPurchases,
                    'expenses' => $monthlyExpenses,
                    'net_profit' => $monthlyProfit,
                ],

                'payment_methods' => $paymentMethods,
                'top_products' => $topProducts,
                'recent_sales' => $recentSales,
                'low_stock_products' => $lowStockProducts,
                'top_categories' => [],
            ],
        ];

        try {
            Cache::put($cacheKey, $responsePayload, 60);
        } catch (\Throwable $e) {}

        return response()->json($responsePayload);
    }

    public function salesReport(Request $request)
    {
        $query = Sale::query();
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }
        if ($request->filled('warehouse_name') && $request->warehouse_name !== 'All') {
            $query->where('warehouse_name', $request->warehouse_name);
        }
        $totalRev = (float)(clone $query)->sum('grand_total');
        $totalPaid = (float)(clone $query)->sum('paid_amount');
        $sales = $query->orderBy('date', 'desc')->paginate($request->input('per_page', 10));
        return response()->json([
            'status' => 'success',
            'data' => [
                'total_revenue' => $totalRev,
                'total_paid' => $totalPaid,
                'sales' => $sales,
            ]
        ]);
    }

    public function purchasesReport(Request $request)
    {
        $query = Purchase::query();
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }
        if ($request->filled('warehouse_name') && $request->warehouse_name !== 'All') {
            $query->where('warehouse_name', $request->warehouse_name);
        }
        $totalCost = (float)(clone $query)->sum('grand_total');
        $totalPaid = (float)(clone $query)->sum('paid_amount');
        $purchases = $query->orderBy('date', 'desc')->paginate($request->input('per_page', 10));
        return response()->json([
            'status' => 'success',
            'data' => [
                'total_cost' => $totalCost,
                'total_paid' => $totalPaid,
                'purchases' => $purchases,
            ]
        ]);
    }

    public function expenseReport(Request $request)
    {
        $query = Expense::query();
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }
        if ($request->filled('warehouse_name') && $request->warehouse_name !== 'All') {
            $query->where('warehouse_name', $request->warehouse_name);
        }
        $totalExpense = (float)(clone $query)->sum('amount');
        $byCategory = (clone $query)->select('expense_category_name', DB::raw('SUM(amount) as total_amount'))->groupBy('expense_category_name')->get();
        $expenses = $query->orderBy('date', 'desc')->paginate($request->input('per_page', 10));
        return response()->json([
            'status' => 'success',
            'data' => [
                'total_expense' => $totalExpense,
                'by_category' => $byCategory,
                'expenses' => $expenses,
            ]
        ]);
    }

    public function customerReport(Request $request)
    {
        $customers = Customer::paginate($request->input('per_page', 10));
        return response()->json([
            'status' => 'success',
            'data' => $customers,
        ]);
    }

    public function supplierReport(Request $request)
    {
        $suppliers = Supplier::paginate($request->input('per_page', 10));
        return response()->json([
            'status' => 'success',
            'data' => $suppliers,
        ]);
    }

    public function stockReport(Request $request)
    {
        $totalProducts = Product::count();
        $totalQuantity = (float)Product::sum('stock_quantity');
        $totalCost = (float)Product::selectRaw('SUM(stock_quantity * COALESCE(cost_price, purchase_price, 0)) as total')->value('total');
        $totalRetail = (float)Product::selectRaw('SUM(stock_quantity * COALESCE(retail_price, selling_price, 0)) as total')->value('total');
        $products = Product::paginate($request->input('per_page', 10));
        return response()->json([
            'status' => 'success',
            'data' => [
                'total_products' => $totalProducts,
                'total_quantity' => $totalQuantity,
                'total_stock_value_cost' => $totalCost,
                'total_stock_value_retail' => $totalRetail,
                'products' => $products,
            ]
        ]);
    }

    public function topSellingReport(Request $request)
    {
        $limit = (int)$request->input('limit', 10);
        $warehouse = $request->input('warehouse_name', 'All');
        $query = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->when($warehouse !== 'All' && !empty($warehouse), function ($q) use ($warehouse) {
                $q->where('sales.warehouse_name', $warehouse);
            })
            ->select('sale_items.product_id', 'sale_items.product_name', 'sale_items.product_code', DB::raw('SUM(sale_items.quantity) as total_quantity'), DB::raw('SUM(sale_items.subtotal) as total_sales'))
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'sale_items.product_code')
            ->orderByDesc('total_quantity')
            ->limit($limit);
        $topProducts = $query->get();
        return response()->json([
            'status' => 'success',
            'data' => $topProducts,
        ]);
    }

    public function profitLoss(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $warehouse = $request->input('warehouse_name', 'All');

        $salesQuery = Sale::query();
        $purchasesQuery = Purchase::query();
        $expensesQuery = Expense::query();

        if ($startDate && $endDate) {
            $salesQuery->whereBetween('date', [$startDate, $endDate]);
            $purchasesQuery->whereBetween('date', [$startDate, $endDate]);
            $expensesQuery->whereBetween('date', [$startDate, $endDate]);
        }

        if ($warehouse !== 'All' && !empty($warehouse)) {
            $salesQuery->where('warehouse_name', $warehouse);
            $purchasesQuery->where('warehouse_name', $warehouse);
            $expensesQuery->where('warehouse_name', $warehouse);
        }

        $totalSales = (float)$salesQuery->sum('grand_total');
        $totalPurchases = (float)$purchasesQuery->sum('grand_total');
        $totalExpenses = (float)$expensesQuery->sum('amount');
        $netProfit = $totalSales - $totalPurchases - $totalExpenses;

        return response()->json([
            'status' => 'success',
            'data' => [
                'gross_sales' => $totalSales,
                'total_sales' => $totalSales,
                'sales_return' => 0.0,
                'net_sales' => $totalSales,
                'gross_purchases' => $totalPurchases,
                'total_purchases' => $totalPurchases,
                'purchase_return' => 0.0,
                'net_purchases' => $totalPurchases,
                'cogs' => $totalPurchases,
                'gross_profit' => $totalSales - $totalPurchases,
                'expenses' => $totalExpenses,
                'total_expenses' => $totalExpenses,
                'net_profit' => $netProfit,
            ]
        ]);
    }
}