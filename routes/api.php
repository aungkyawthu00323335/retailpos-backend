<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OutletController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\SubCategoryController;
use App\Http\Controllers\Api\ProductUnitController;
use App\Http\Controllers\Api\SaleUnitController;
use App\Http\Controllers\Api\PurchaseUnitController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductExcelController;
use App\Http\Controllers\Api\AdjustmentController;
use App\Http\Controllers\Api\ProcessingController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\PurchaseReturnController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SaleReturnController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerGroupController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierCategoryController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\VariationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashRegisterController;

/*
|--------------------------------------------------------------------------
| Infy Retail POS API Routes
|--------------------------------------------------------------------------
*/

// Health & Connection Heartbeat Probe
Route::get('ping', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->timestamp,
    ])
    ->header('Connection', 'close')
    ->header('Cache-Control', 'no-cache, no-store');
});

// Authentication & Session
$crudResource = static function (string $uri, string $controller, string $permModule, string $extraReadPerms = '', array $only = ['index', 'store', 'show', 'update', 'destroy']): void {
    $readMiddleware = $extraReadPerms !== ''
        ? "permission:{$permModule}.view,{$extraReadPerms}"
        : "permission:{$permModule}.view";
    $has = array_flip($only);

    if (isset($has['index'])) {
        Route::get($uri, [$controller, 'index'])->middleware($readMiddleware);
    }
    if (isset($has['store'])) {
        Route::post($uri, [$controller, 'store'])->middleware("permission:{$permModule}.create");
    }
    if (isset($has['show'])) {
        Route::get("{$uri}/{id}", [$controller, 'show'])->middleware($readMiddleware);
    }
    if (isset($has['update'])) {
        Route::put("{$uri}/{id}", [$controller, 'update'])->middleware("permission:{$permModule}.update");
        Route::patch("{$uri}/{id}", [$controller, 'update'])->middleware("permission:{$permModule}.update");
    }
    if (isset($has['destroy'])) {
        Route::delete("{$uri}/{id}", [$controller, 'destroy'])->middleware("permission:{$permModule}.delete");
    }
};

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:15,1');
Route::post('logout', [AuthController::class, 'logout']);
Route::get('me', [AuthController::class, 'me']);
Route::post('change-password', [AuthController::class, 'changePassword']);
Route::post('update-profile', [AuthController::class, 'updateProfile']);

// Outlets & Warehouses
$outletReadPerms = 'dashboard.view,pos.view,cash_register.view,sales.view,sales.create,purchases.view,purchases.create,purchase_returns.view,sale_returns.view,adjustments.view,adjustments.create,transfers.view,transfers.create,processings.view,processings.create,quotations.view,quotations.create,expenses.view,expenses.create,products.view,products.create,products.update,users.view,users.create,users.update,reports.view,report_sales.view,report_purchases.view,report_stock.view,report_stock_alert.view,report_stock_ledger.view,report_profit_loss.view,report_cashier_audit.view,report_expense.view,report_top_selling.view,report_dead_stock.view,report_cash_flow.view,report_register.view,report_z_report.view,report_ar_aging.view,report_ap_aging.view,report_product_margins.view,report_sales_return.view,report_purchase_return.view,report_commercial_tax.view,report_customer.view,report_supplier.view';
$crudResource('outlets', OutletController::class, 'outlets', $outletReadPerms);
Route::patch('outlets/{id}/toggle-status', [OutletController::class, 'toggleStatus'])->middleware('permission:outlets.update');

// Brands
$catalogReadPerms = 'products.view,products.create,products.update,pos.view,barcode.view,purchases.create,sales.create,adjustments.create,transfers.create,processings.create,quotations.create,reports.view,report_stock.view,report_stock_alert.view,report_dead_stock.view,report_top_selling.view,report_product_margins.view';
Route::patch('brands/{id}/toggle-status', [BrandController::class, 'toggleStatus'])->middleware('permission:brands.update');
$crudResource('brands', BrandController::class, 'brands', $catalogReadPerms);

// Product Categories, Sub Categories, Variations & Units
$crudResource('product-categories', ProductCategoryController::class, 'product_categories', $catalogReadPerms);
Route::patch('sub-categories/{id}/toggle-status', [SubCategoryController::class, 'toggleStatus'])->middleware('permission:sub_categories.update');
$crudResource('sub-categories', SubCategoryController::class, 'sub_categories', $catalogReadPerms);
Route::patch('product-units/{id}/toggle-status', [ProductUnitController::class, 'toggleStatus'])->middleware('permission:units.update');
$crudResource('product-units', ProductUnitController::class, 'units', $catalogReadPerms);
Route::patch('sale-units/{id}/toggle-status', [SaleUnitController::class, 'toggleStatus'])->middleware('permission:units.update');
$crudResource('sale-units', SaleUnitController::class, 'units', $catalogReadPerms);
Route::patch('purchase-units/{id}/toggle-status', [PurchaseUnitController::class, 'toggleStatus'])->middleware('permission:units.update');
$crudResource('purchase-units', PurchaseUnitController::class, 'units', $catalogReadPerms);
Route::patch('variations/{id}/toggle-status', [VariationController::class, 'toggleStatus'])->middleware('permission:variations.update');
$crudResource('variations', VariationController::class, 'variations', $catalogReadPerms);

// Products & Barcode
$productReadPerms = 'pos.view,barcode.view,sales.create,sales.update,purchases.create,purchases.update,sale_returns.create,sale_returns.update,purchase_returns.create,purchase_returns.update,quotations.create,quotations.update,adjustments.create,adjustments.update,transfers.create,transfers.update,processings.create';
Route::get('products/create-options', [ProductController::class, 'createOptions'])->middleware('permission:products.view,products.create,products.update');
Route::get('products/check-identifier', [ProductController::class, 'checkIdentifier'])->middleware('permission:products.view,products.create,products.update');
Route::post('products/generate-sku', [ProductController::class, 'generateSku'])->middleware('permission:products.create,products.update');
Route::post('products/generate-barcode', [ProductController::class, 'generateBarcode'])->middleware('permission:products.create,products.update');
Route::post('products/upload-image', [ProductController::class, 'uploadImage'])->middleware('permission:products.create,products.update,brands.create,brands.update,product_categories.create,product_categories.update,users.create,users.update,payment_methods.create,payment_methods.update,general_settings.update');
Route::post('upload-image', [ProductController::class, 'uploadImage'])->middleware('permission:products.create,products.update,brands.create,brands.update,product_categories.create,product_categories.update,users.create,users.update,payment_methods.create,payment_methods.update,general_settings.update');
Route::get('products/template', [ProductController::class, 'downloadTemplate'])->middleware('permission:products.import');
Route::get('products/export', [ProductController::class, 'export'])->middleware('permission:products.export');
Route::post('products/bulk-import', [ProductController::class, 'bulkImport'])->middleware('permission:products.import');
Route::get('products/excel-template', [ProductExcelController::class, 'downloadTemplate'])->middleware('permission:products.import,products.update');
Route::get('products/excel-export', [ProductExcelController::class, 'exportProducts'])->middleware('permission:products.export');
Route::post('products/excel-import', [ProductExcelController::class, 'importNewProducts'])->middleware('permission:products.import');
Route::post('products/price-change-import', [ProductExcelController::class, 'importPriceChanges'])->middleware('permission:products.update');
Route::get('products/quick-search', [ProductController::class, 'quickSearch'])->middleware("permission:products.view,{$productReadPerms}");
Route::get('products/barcode', [ProductController::class, 'findByBarcode'])->middleware("permission:products.view,{$productReadPerms}");
Route::get('products/sync', [ProductController::class, 'sync'])->middleware("permission:products.view,{$productReadPerms}");
Route::patch('products/{id}/toggle-status', [ProductController::class, 'toggleStatus'])->middleware('permission:products.update');
Route::get('categories/{category}/subcategories', [SubCategoryController::class, 'index'])->middleware("permission:sub_categories.view,{$catalogReadPerms}");
$crudResource('products', ProductController::class, 'products', $productReadPerms);

// Stock Adjustments & Transfers
Route::get('adjustments/reasons', [AdjustmentController::class, 'reasonCatalog'])->middleware('permission:adjustments.view,adjustments.create,adjustments.update,report_profit_loss.view');
Route::get('adjustments/export', [AdjustmentController::class, 'export'])->middleware('permission:adjustments.export');
Route::get('adjustments/generate-code', [AdjustmentController::class, 'generateCode'])->middleware('permission:adjustments.create');
Route::get('adjustments', [AdjustmentController::class, 'index'])->middleware('permission:adjustments.view');
Route::post('adjustments', [AdjustmentController::class, 'store'])->middleware('permission:adjustments.create');
Route::get('adjustments/{id}', [AdjustmentController::class, 'show'])->middleware('permission:adjustments.view');
Route::put('adjustments/{id}', [AdjustmentController::class, 'update'])->middleware('permission:adjustments.update,adjustments.approve');
Route::patch('adjustments/{id}', [AdjustmentController::class, 'update'])->middleware('permission:adjustments.update,adjustments.approve');
Route::delete('adjustments/{id}', [AdjustmentController::class, 'destroy'])->middleware('permission:adjustments.delete');

Route::get('transfers/export', [TransferController::class, 'export'])->middleware('permission:transfers.export');
Route::get('transfers/generate-code', [TransferController::class, 'generateCode'])->middleware('permission:transfers.create');
Route::post('transfers/{id}/receive', [TransferController::class, 'receiveTransfer'])->middleware('permission:transfers.receive');
Route::get('transfers/{id}/payments', [TransferController::class, 'getPayments'])->middleware('permission:transfers.view,transfers.pay');
Route::post('transfers/{id}/payments', [TransferController::class, 'addPayment'])->middleware('permission:transfers.pay');
Route::delete('transfers/{id}/payments/{paymentId}', [TransferController::class, 'deletePayment'])->middleware('permission:transfers.delete');
$crudResource('transfers', TransferController::class, 'transfers');

// Stock Processing / Repackaging (ထုတ်လုပ်/ခွဲထုတ်ပြုပြင်ခြင်း)
Route::get('processings/export', [ProcessingController::class, 'export'])->middleware('permission:processings.export');
Route::get('processings/generate-code', [ProcessingController::class, 'generateCode'])->middleware('permission:processings.create');
$crudResource('processings', ProcessingController::class, 'processings', '', ['index', 'store', 'show', 'destroy']);

// Purchases & Purchase Returns
Route::get('purchases/export', [PurchaseController::class, 'export'])->middleware('permission:purchases.export');
Route::get('purchases/generate-code', [PurchaseController::class, 'generateCode'])->middleware('permission:purchases.create');
Route::post('purchases/{id}/payments', [PurchaseController::class, 'addPayment'])->middleware('permission:purchases.pay');
Route::delete('purchases/{id}/payments/{paymentId}', [PurchaseController::class, 'deletePayment'])->middleware('permission:purchases.delete');
Route::delete('purchases/payments/{paymentId}', [PurchaseController::class, 'deletePayment'])->middleware('permission:purchases.delete');
$crudResource('purchases', PurchaseController::class, 'purchases', 'purchase_returns.create,purchase_returns.update');
Route::get('purchase-returns/generate-code', [PurchaseReturnController::class, 'generateCode'])->middleware('permission:purchase_returns.create');
Route::get('purchase-returns/export', [PurchaseReturnController::class, 'export'])->middleware('permission:purchase_returns.export');
Route::post('purchase-returns/{id}/payments', [PurchaseReturnController::class, 'addPayment'])->middleware('permission:purchase_returns.pay');
Route::delete('purchase-returns/{returnId}/payments/{paymentId}', [PurchaseReturnController::class, 'deletePayment'])->middleware('permission:purchase_returns.delete');
Route::delete('purchase-returns/payments/{paymentId}', [PurchaseReturnController::class, 'deletePayment'])->middleware('permission:purchase_returns.delete');
$crudResource('purchase-returns', PurchaseReturnController::class, 'purchase_returns');

// Sales, POS Checkout & Sales Returns
Route::get('sales/export', [SaleController::class, 'export'])->middleware('permission:sales.export');
Route::get('sales/generate-code', [SaleController::class, 'generateCode'])->middleware('permission:sales.create,pos.create,pos.checkout');
Route::post('sales/bulk-sync', [SaleController::class, 'bulkSync'])->middleware('permission:sales.create,pos.create,pos.checkout');
Route::post('sales/{id}/payments', [SaleController::class, 'addPayment'])->middleware('permission:sales.pay');
Route::delete('sales/{saleId}/payments/{paymentId}', [SaleController::class, 'deletePayment'])->middleware('permission:sales.delete');
Route::delete('sales/payments/{paymentId}', [SaleController::class, 'deletePayment'])->middleware('permission:sales.delete');
Route::post('sales/{id}/void', [SaleController::class, 'voidSale'])->middleware('permission:sales.void');
$crudResource('sales', SaleController::class, 'sales', 'sale_returns.create,sale_returns.update', ['index', 'show', 'update', 'destroy']);
Route::post('sales', [SaleController::class, 'store'])->middleware('permission:sales.create,pos.create,pos.checkout,quotations.convert_sale');

Route::get('sale-returns/export', [SaleReturnController::class, 'export'])->middleware('permission:sale_returns.export');
Route::post('sale-returns/{id}/payments', [SaleReturnController::class, 'addPayment'])->middleware('permission:sale_returns.pay,sales.refund');
Route::delete('sale-returns/{returnId}/payments/{paymentId}', [SaleReturnController::class, 'deletePayment'])->middleware('permission:sale_returns.delete');
Route::delete('sale-returns/payments/{paymentId}', [SaleReturnController::class, 'deletePayment'])->middleware('permission:sale_returns.delete');
Route::get('sale-returns/generate-code', [SaleReturnController::class, 'generateCode'])->middleware('permission:sale_returns.create');
$crudResource('sale-returns', SaleReturnController::class, 'sale_returns');

// Quotations
Route::get('quotations/export', [QuotationController::class, 'export'])->middleware('permission:quotations.export');
Route::get('quotations/generate-code', [QuotationController::class, 'generateCode'])->middleware('permission:quotations.create');
Route::post('quotations/{id}/convert-to-sale', [QuotationController::class, 'convertToSale'])->middleware('permission:quotations.convert_sale');
$crudResource('quotations', QuotationController::class, 'quotations');

// Expenses & Expense Categories
$crudResource('expense-categories', ExpenseCategoryController::class, 'expense_categories', 'expenses.view,expenses.create,expenses.update,report_expense.view,reports.view', ['index', 'store', 'update', 'destroy']);
Route::get('expenses/export', [ExpenseController::class, 'export'])->middleware('permission:expenses.export');
Route::get('expenses/generate-code', [ExpenseController::class, 'generateCode'])->middleware('permission:expenses.create');
Route::post('expenses/{id}/payments', [ExpenseController::class, 'addPayment'])->middleware('permission:expenses.pay');
Route::delete('expenses/{expenseId}/payments/{paymentId}', [ExpenseController::class, 'deletePayment'])->middleware('permission:expenses.delete');
Route::delete('expenses/payments/{paymentId}', [ExpenseController::class, 'deletePayment'])->middleware('permission:expenses.delete');
$crudResource('expenses', ExpenseController::class, 'expenses');

// Payment Methods (InfyPOS /app/payment-methods)
$paymentMethodReadPerms = 'pos.view,sales.view,sales.create,sales.pay,purchases.view,purchases.create,purchases.pay,sale_returns.view,sale_returns.create,sale_returns.pay,purchase_returns.view,purchase_returns.create,purchase_returns.pay,expenses.view,expenses.create,expenses.pay,transfers.view,transfers.create,transfers.pay,reports.view';
Route::post('payment-methods/upload-image', [PaymentMethodController::class, 'uploadQrImage'])->middleware('permission:payment_methods.create,payment_methods.update');
Route::patch('payment-methods/{id}/toggle-status', [PaymentMethodController::class, 'toggleStatus'])->middleware('permission:payment_methods.update');
$crudResource('payment-methods', PaymentMethodController::class, 'payment_methods', $paymentMethodReadPerms, ['index', 'store', 'update', 'destroy']);

// Customers & Customer Groups (CRM)
$customerReadPerms = 'pos.view,pos.checkout,sales.view,sales.create,sales.update,sale_returns.view,sale_returns.create,quotations.view,quotations.create,report_customer.view,report_ar_aging.view,reports.view';
Route::get('customers/generate-id', [CustomerController::class, 'generateCustomerId'])->middleware('permission:customers.create,customers.view');
Route::get('customers/template', [CustomerController::class, 'downloadTemplate'])->middleware('permission:customers.import');
Route::get('customers/export', [CustomerController::class, 'export'])->middleware('permission:customers.export');
Route::post('customers/bulk-import', [CustomerController::class, 'bulkImport'])->middleware('permission:customers.import');
Route::patch('customers/{id}/toggle-status', [CustomerController::class, 'toggleStatus'])->middleware('permission:customers.update');
$crudResource('customers', CustomerController::class, 'customers', $customerReadPerms);
Route::patch('customer-groups/{id}/toggle-status', [CustomerGroupController::class, 'toggleStatus'])->middleware('permission:customer_groups.update');
$crudResource('customer-groups', CustomerGroupController::class, 'customer_groups', 'customers.view,customers.create,customers.update,pos.view,sales.create,quotations.create');

// Suppliers & Supplier Categories
$supplierReadPerms = 'purchases.view,purchases.create,purchases.update,purchase_returns.view,purchase_returns.create,products.create,products.update,report_supplier.view,report_ap_aging.view,reports.view';
Route::get('suppliers/template', [SupplierController::class, 'downloadTemplate'])->middleware('permission:suppliers.import');
Route::get('suppliers/export', [SupplierController::class, 'export'])->middleware('permission:suppliers.export');
Route::post('suppliers/bulk-import', [SupplierController::class, 'bulkImport'])->middleware('permission:suppliers.import');
Route::patch('suppliers/{id}/toggle-status', [SupplierController::class, 'toggleStatus'])->middleware('permission:suppliers.update');
$crudResource('suppliers', SupplierController::class, 'suppliers', $supplierReadPerms);
Route::patch('supplier-categories/{id}/toggle-status', [SupplierCategoryController::class, 'toggleStatus'])->middleware('permission:supplier_categories.update');
$crudResource('supplier-categories', SupplierCategoryController::class, 'supplier_categories', 'suppliers.view,suppliers.create,suppliers.update');

// Users & RBAC Roles
Route::get('users/generate-id', [UserController::class, 'generateUserId'])->middleware('permission:users.create,users.view');
Route::patch('users/{id}/toggle-status', [UserController::class, 'toggleStatus'])->middleware('permission:users.update');
Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
Route::get('users/{id}', [UserController::class, 'show'])->middleware('permission:users.view');
Route::put('users/{id}', [UserController::class, 'update'])->middleware('permission:users.update');
Route::patch('users/{id}', [UserController::class, 'update'])->middleware('permission:users.update');
Route::delete('users/{id}', [UserController::class, 'destroy'])->middleware('permission:users.delete');

Route::get('roles/permissions', [RoleController::class, 'getAvailablePermissions'])->middleware('permission:roles.view,roles.create,roles.update');
Route::get('permissions', [RoleController::class, 'getAvailablePermissions'])->middleware('permission:roles.view,roles.create,roles.update');
Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
Route::get('roles/{id}', [RoleController::class, 'show'])->middleware('permission:roles.view');
Route::put('roles/{id}', [RoleController::class, 'update'])->middleware('permission:roles.update');
Route::patch('roles/{id}', [RoleController::class, 'update'])->middleware('permission:roles.update');
Route::delete('roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');

// Reports & Executive Analytics (InfyPOS Reports Suite)
Route::get('reports/profit-loss', [ReportController::class, 'profitLoss'])->middleware('permission:report_profit_loss.view,reports.profit_loss');
Route::get('reports/commercial-tax', [ReportController::class, 'commercialTaxReport'])->middleware('permission:report_commercial_tax.view,reports.commercial_tax');
Route::get('reports/dashboard-summary', [ReportController::class, 'dashboardSummary'])->middleware('permission:dashboard.view,reports.view');
Route::get('reports/stock', [ReportController::class, 'stockReport'])->middleware('permission:report_stock.view,reports.view');
Route::get('reports/stock/export', [ReportController::class, 'exportStock'])->middleware('permission:report_stock.export,reports.export');
Route::get('reports/stock/{id}/details', [ReportController::class, 'stockProductDetails'])->middleware('permission:report_stock.view,reports.view');
Route::get('reports/stock-alert', [ReportController::class, 'stockAlertReport'])->middleware('permission:report_stock_alert.view,reports.view');
Route::get('reports/sales', [ReportController::class, 'salesReport'])->middleware('permission:report_sales.view,reports.view');
Route::get('reports/purchases', [ReportController::class, 'purchasesReport'])->middleware('permission:report_purchases.view,reports.view');
Route::get('reports/sale-returns', [ReportController::class, 'saleReturnsReport'])->middleware('permission:report_sales_return.view,reports.view');
Route::get('reports/purchase-returns', [ReportController::class, 'purchaseReturnsReport'])->middleware('permission:report_purchase_return.view,reports.view');
Route::get('reports/top-selling', [ReportController::class, 'topSellingReport'])->middleware('permission:report_top_selling.view,reports.view');
Route::get('reports/customers', [ReportController::class, 'customerReport'])->middleware('permission:report_customer.view,reports.view');
Route::get('reports/customers/{id}/statement', [ReportController::class, 'customerStatement'])->middleware('permission:report_customer.view,reports.view');
Route::get('reports/suppliers', [ReportController::class, 'supplierReport'])->middleware('permission:report_supplier.view,reports.view');
Route::get('reports/suppliers/{id}/statement', [ReportController::class, 'supplierStatement'])->middleware('permission:report_supplier.view,reports.view');
Route::get('reports/expenses', [ReportController::class, 'expenseReport'])->middleware('permission:report_expense.view,reports.view');
Route::get('reports/register', [ReportController::class, 'registerReport'])->middleware('permission:report_register.view,reports.view');
Route::get('reports/z-report', [ReportController::class, 'zReport'])->middleware('permission:report_z_report.view,reports.view');
Route::get('reports/ar-aging', [ReportController::class, 'arAgingReport'])->middleware('permission:report_ar_aging.view,reports.view');
Route::get('reports/ap-aging', [ReportController::class, 'apAgingReport'])->middleware('permission:report_ap_aging.view,reports.view');
Route::get('reports/cash-flow', [ReportController::class, 'cashFlowReport'])->middleware('permission:report_cash_flow.view,reports.view');
Route::get('reports/stock-ledger', [ReportController::class, 'stockLedgerReport'])->middleware('permission:report_stock_ledger.view,reports.view');
Route::get('reports/inventory-reconciliation', [ReportController::class, 'inventoryReconciliation'])->middleware('permission:report_stock.view,report_stock_ledger.view,report_profit_loss.view,adjustments.view,reports.view');
Route::get('reports/product-margins', [ReportController::class, 'productMarginsReport'])->middleware('permission:report_product_margins.view,reports.view');
Route::get('reports/dead-stock', [ReportController::class, 'deadStockReport'])->middleware('permission:report_dead_stock.view,reports.view');
Route::get('reports/cashier-audit', [ReportController::class, 'cashierAuditReport'])->middleware('permission:report_cashier_audit.view,reports.view');

// Cash Register Till & Shift Management
Route::get('cash-register/status', [CashRegisterController::class, 'status'])->middleware('permission:cash_register.view,cash_register.open_close,pos.view,pos.checkout');
Route::post('cash-register/open', [CashRegisterController::class, 'open'])->middleware('permission:cash_register.open_close');
Route::get('cash-register/details/{id?}', [CashRegisterController::class, 'details'])->middleware('permission:cash_register.view');
Route::post('cash-register/close/{id?}', [CashRegisterController::class, 'close'])->middleware('permission:cash_register.open_close');
Route::post('cash-register/transaction', [CashRegisterController::class, 'recordTransaction'])->middleware('permission:cash_register.safe_drop');

// General Settings, Multi-Currency, Voucher & Prefix Settings
Route::get('settings', [SettingController::class, 'getSettings'])->middleware('permission:general_settings.view,voucher_settings.view,prefix_settings.view');
Route::post('settings', [SettingController::class, 'updateSettings'])->middleware('permission:general_settings.update,voucher_settings.update,prefix_settings.update');
Route::post('currencies/{id}/set-default', [CurrencyController::class, 'setDefault'])->middleware('permission:currencies.update');
$crudResource('currencies', CurrencyController::class, 'currencies', 'dashboard.view,pos.view,sales.view,purchases.view,expenses.view,quotations.view,general_settings.view,reports.view,products.view', ['index', 'store', 'update', 'destroy']);


