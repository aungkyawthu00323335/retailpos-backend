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
use App\Http\Controllers\Api\AdjustmentController;
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

// Authentication & Session
Route::post('login', [AuthController::class, 'login']);
Route::post('logout', [AuthController::class, 'logout']);
Route::get('me', [AuthController::class, 'me']);

// Outlets & Warehouses
Route::apiResource('outlets', OutletController::class);
Route::patch('outlets/{id}/toggle-status', [OutletController::class, 'toggleStatus']);

// Brands
Route::apiResource('brands', BrandController::class);

// Product Categories, Sub Categories, Variations & Units
Route::apiResource('product-categories', ProductCategoryController::class);
Route::apiResource('sub-categories', SubCategoryController::class);
Route::apiResource('product-units', ProductUnitController::class);
Route::apiResource('sale-units', SaleUnitController::class);
Route::apiResource('purchase-units', PurchaseUnitController::class);
Route::apiResource('variations', VariationController::class);

// Products & Barcode
Route::get('products/create-options', [ProductController::class, 'createOptions']);
Route::get('products/check-identifier', [ProductController::class, 'checkIdentifier']);
Route::post('products/generate-sku', [ProductController::class, 'generateSku']);
Route::post('products/generate-barcode', [ProductController::class, 'generateBarcode']);
Route::get('products/template', [ProductController::class, 'downloadTemplate']);
Route::get('products/export', [ProductController::class, 'export']);
Route::post('products/bulk-import', [ProductController::class, 'bulkImport']);
Route::get('products/barcode', [ProductController::class, 'findByBarcode']);
Route::get('categories/{category}/subcategories', [SubCategoryController::class, 'index']);
Route::apiResource('products', ProductController::class);

// Stock Adjustments & Transfers
Route::get('adjustments/generate-code', [AdjustmentController::class, 'generateCode']);
Route::apiResource('adjustments', AdjustmentController::class);
Route::get('transfers/generate-code', [TransferController::class, 'generateCode']);
Route::apiResource('transfers', TransferController::class);

// Purchases & Purchase Returns
Route::post('purchases/{id}/payments', [PurchaseController::class, 'addPayment']);
Route::apiResource('purchases', PurchaseController::class);
Route::apiResource('purchase-returns', PurchaseReturnController::class);

// Cash Register & POS Shift Management
Route::get('cash-register/status', [CashRegisterController::class, 'status']);
Route::post('cash-register/open', [CashRegisterController::class, 'open']);
Route::get('cash-register/details/{id?}', [CashRegisterController::class, 'details']);
Route::post('cash-register/close/{id?}', [CashRegisterController::class, 'close']);

// Sales, POS Checkout & Sales Returns
Route::post('sales/{id}/payments', [SaleController::class, 'addPayment']);
Route::apiResource('sales', SaleController::class);
Route::apiResource('sale-returns', SaleReturnController::class);

// Quotations
Route::post('quotations/{id}/convert-to-sale', [QuotationController::class, 'convertToSale']);
Route::apiResource('quotations', QuotationController::class);

// Expenses & Expense Categories
Route::apiResource('expense-categories', ExpenseCategoryController::class);
Route::get('expenses/generate-code', [ExpenseController::class, 'generateCode']);
Route::apiResource('expenses', ExpenseController::class);

// Payment Methods (InfyPOS /app/payment-methods)
Route::patch('payment-methods/{id}/toggle-status', [PaymentMethodController::class, 'toggleStatus']);
Route::apiResource('payment-methods', PaymentMethodController::class);

// Customers & Customer Groups (CRM)
Route::apiResource('customers', CustomerController::class);
Route::apiResource('customer-groups', CustomerGroupController::class);

// Suppliers & Supplier Categories
Route::get('suppliers/template', [SupplierController::class, 'downloadTemplate']);
Route::get('suppliers/export', [SupplierController::class, 'export']);
Route::post('suppliers/bulk-import', [SupplierController::class, 'bulkImport']);
Route::apiResource('suppliers', SupplierController::class);
Route::apiResource('supplier-categories', SupplierCategoryController::class);

// Users & RBAC Roles
Route::get('users/generate-id', [UserController::class, 'generateUserId']);
Route::apiResource('users', UserController::class);
Route::apiResource('roles', RoleController::class);

// Reports & Executive Analytics (InfyPOS Reports Suite)
Route::get('reports/dashboard-summary', [ReportController::class, 'dashboardSummary']);
Route::get('reports/profit-loss', [ReportController::class, 'profitLoss']);
Route::get('reports/stock', [ReportController::class, 'stockReport']);
Route::get('reports/stock/export', [ReportController::class, 'exportStock']);
Route::get('reports/stock/{id}/details', [ReportController::class, 'stockProductDetails']);
Route::get('reports/stock-alert', [ReportController::class, 'stockAlertReport']);
Route::get('reports/sales', [ReportController::class, 'salesReport']);
Route::get('reports/purchases', [ReportController::class, 'purchasesReport']);
Route::get('reports/sale-returns', [ReportController::class, 'saleReturnsReport']);
Route::get('reports/purchase-returns', [ReportController::class, 'purchaseReturnsReport']);
Route::get('reports/top-selling', [ReportController::class, 'topSellingReport']);
Route::get('reports/customers', [ReportController::class, 'customerReport']);
Route::get('reports/suppliers', [ReportController::class, 'supplierReport']);
Route::get('reports/expenses', [ReportController::class, 'expenseReport']);
Route::get('reports/register', [ReportController::class, 'registerReport']);
Route::get('reports/z-report', [ReportController::class, 'zReport']);

// General Settings & Multi-Currency
Route::get('settings', [SettingController::class, 'getSettings']);
Route::post('settings', [SettingController::class, 'updateSettings']);
Route::apiResource('currencies', CurrencyController::class);
