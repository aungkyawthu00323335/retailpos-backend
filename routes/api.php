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
use App\Http\Controllers\Api\BotOrderController;
use App\Http\Controllers\Api\DeliveryRegionController;
use App\Http\Controllers\Api\DeliveryTownshipController;
use App\Http\Controllers\Api\MasterDataSyncController;
use App\Http\Controllers\Api\ProductSyncController;

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
    ]);
});

// Authentication & Session
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:15,1');
Route::post('logout', [AuthController::class, 'logout']);
Route::get('me', [AuthController::class, 'me']);
Route::post('change-password', [AuthController::class, 'changePassword']);
Route::post('update-profile', [AuthController::class, 'updateProfile']);

// Outlets & Warehouses
Route::apiResource('outlets', OutletController::class);
Route::patch('outlets/{id}/toggle-status', [OutletController::class, 'toggleStatus']);

// Brands
Route::patch('brands/{id}/toggle-status', [BrandController::class, 'toggleStatus']);
Route::apiResource('brands', BrandController::class);

// Product Categories, Sub Categories, Variations & Units
Route::apiResource('product-categories', ProductCategoryController::class);
Route::patch('sub-categories/{id}/toggle-status', [SubCategoryController::class, 'toggleStatus']);
Route::apiResource('sub-categories', SubCategoryController::class);
Route::patch('product-units/{id}/toggle-status', [ProductUnitController::class, 'toggleStatus']);
Route::apiResource('product-units', ProductUnitController::class);
Route::patch('sale-units/{id}/toggle-status', [SaleUnitController::class, 'toggleStatus']);
Route::apiResource('sale-units', SaleUnitController::class);
Route::patch('purchase-units/{id}/toggle-status', [PurchaseUnitController::class, 'toggleStatus']);
Route::apiResource('purchase-units', PurchaseUnitController::class);
Route::patch('variations/{id}/toggle-status', [VariationController::class, 'toggleStatus']);
Route::apiResource('variations', VariationController::class);

// Products & Barcode
Route::get('products/create-options', [ProductController::class, 'createOptions']);
Route::get('products/check-identifier', [ProductController::class, 'checkIdentifier']);
Route::post('products/generate-sku', [ProductController::class, 'generateSku']);
Route::post('products/generate-barcode', [ProductController::class, 'generateBarcode']);
Route::post('products/upload-image', [ProductController::class, 'uploadImage']);
Route::post('upload-image', [ProductController::class, 'uploadImage']);
Route::get('products/template', [ProductController::class, 'downloadTemplate']);
Route::get('products/export', [ProductController::class, 'export']);
Route::post('products/bulk-import', [ProductController::class, 'bulkImport']);
Route::get('products/excel-template', [ProductExcelController::class, 'downloadTemplate']);
Route::get('products/excel-export', [ProductExcelController::class, 'exportProducts']);
Route::post('products/excel-import', [ProductExcelController::class, 'importNewProducts']);
Route::post('products/price-change-import', [ProductExcelController::class, 'importPriceChanges']);
Route::get('products/barcode', [ProductController::class, 'findByBarcode']);
Route::patch('products/{id}/toggle-status', [ProductController::class, 'toggleStatus']);
Route::patch('products/{id}/toggle-bot-status', [ProductController::class, 'toggleBotStatus']);
Route::get('categories/{category}/subcategories', [SubCategoryController::class, 'index']);
Route::post('master-data/sync-batch', [MasterDataSyncController::class, 'syncBatch']);
Route::post('products/sync-batch', [ProductSyncController::class, 'syncBatch']);
Route::apiResource('products', ProductController::class);

// Stock Adjustments & Transfers
Route::get('adjustments/generate-code', [AdjustmentController::class, 'generateCode']);
Route::apiResource('adjustments', AdjustmentController::class);
Route::get('transfers/generate-code', [TransferController::class, 'generateCode']);
Route::post('transfers/{id}/receive', [TransferController::class, 'receiveTransfer']);
Route::get('transfers/{id}/payments', [TransferController::class, 'getPayments']);
Route::post('transfers/{id}/payments', [TransferController::class, 'addPayment']);
Route::delete('transfers/{id}/payments/{paymentId}', [TransferController::class, 'deletePayment']);
Route::apiResource('transfers', TransferController::class);

// Stock Processing / Repackaging (ထုတ်လုပ်/ခွဲထုတ်ပြုပြင်ခြင်း)
Route::get('processings/generate-code', [ProcessingController::class, 'generateCode']);
Route::apiResource('processings', ProcessingController::class);

// Purchases & Purchase Returns
Route::post('purchases/{id}/payments', [PurchaseController::class, 'addPayment']);
Route::delete('purchases/{id}/payments/{paymentId}', [PurchaseController::class, 'deletePayment']);
Route::delete('purchases/payments/{paymentId}', [PurchaseController::class, 'deletePayment']);
Route::apiResource('purchases', PurchaseController::class);
Route::post('purchase-returns/{id}/payments', [PurchaseReturnController::class, 'addPayment']);
Route::delete('purchase-returns/{returnId}/payments/{paymentId}', [PurchaseReturnController::class, 'deletePayment']);
Route::delete('purchase-returns/payments/{paymentId}', [PurchaseReturnController::class, 'deletePayment']);
Route::apiResource('purchase-returns', PurchaseReturnController::class);

// Cash Register & POS Shift Management
Route::get('cash-register/status', [CashRegisterController::class, 'status']);
Route::post('cash-register/open', [CashRegisterController::class, 'open']);
Route::get('cash-register/details/{id?}', [CashRegisterController::class, 'details']);
Route::post('cash-register/close/{id?}', [CashRegisterController::class, 'close']);

// Sales, POS Checkout & Sales Returns
Route::post('sales/{id}/payments', [SaleController::class, 'addPayment']);
Route::delete('sales/{saleId}/payments/{paymentId}', [SaleController::class, 'deletePayment']);
Route::delete('sales/payments/{paymentId}', [SaleController::class, 'deletePayment']);
Route::post('sales/{id}/void', [SaleController::class, 'voidSale'])->middleware('permission:sales.void');
Route::apiResource('sales', SaleController::class);
Route::post('sale-returns/{id}/payments', [SaleReturnController::class, 'addPayment']);
Route::delete('sale-returns/{returnId}/payments/{paymentId}', [SaleReturnController::class, 'deletePayment']);
Route::delete('sale-returns/payments/{paymentId}', [SaleReturnController::class, 'deletePayment']);
Route::apiResource('sale-returns', SaleReturnController::class);

// Quotations
Route::post('quotations/{id}/convert-to-sale', [QuotationController::class, 'convertToSale']);
Route::apiResource('quotations', QuotationController::class);

// Expenses & Expense Categories
Route::apiResource('expense-categories', ExpenseCategoryController::class);
Route::get('expenses/generate-code', [ExpenseController::class, 'generateCode']);
Route::post('expenses/{id}/payments', [ExpenseController::class, 'addPayment']);
Route::delete('expenses/{expenseId}/payments/{paymentId}', [ExpenseController::class, 'deletePayment']);
Route::delete('expenses/payments/{paymentId}', [ExpenseController::class, 'deletePayment']);
Route::apiResource('expenses', ExpenseController::class);

// Payment Methods (InfyPOS /app/payment-methods)
Route::post('payment-methods/upload-image', [PaymentMethodController::class, 'uploadQrImage']);
Route::patch('payment-methods/{id}/toggle-status', [PaymentMethodController::class, 'toggleStatus']);
Route::patch('payment-methods/{id}/toggle-bot', [PaymentMethodController::class, 'toggleBot']);
Route::apiResource('payment-methods', PaymentMethodController::class);

// Delivery Regions & Townships (Delivery Zones)
Route::patch('delivery-regions/{id}/toggle-status', [DeliveryRegionController::class, 'toggleStatus']);
Route::apiResource('delivery-regions', DeliveryRegionController::class);
Route::patch('delivery-townships/{id}/toggle-status', [DeliveryTownshipController::class, 'toggleStatus']);
Route::patch('delivery-townships/{id}/toggle-cod', [DeliveryTownshipController::class, 'toggleCod']);
Route::apiResource('delivery-townships', DeliveryTownshipController::class);

// Customers & Customer Groups (CRM)
Route::get('customers/generate-id', [CustomerController::class, 'generateCustomerId']);
Route::get('customers/template', [CustomerController::class, 'downloadTemplate']);
Route::get('customers/export', [CustomerController::class, 'export']);
Route::post('customers/bulk-import', [CustomerController::class, 'bulkImport']);
Route::patch('customers/{id}/toggle-status', [CustomerController::class, 'toggleStatus']);
Route::apiResource('customers', CustomerController::class);
Route::patch('customer-groups/{id}/toggle-status', [CustomerGroupController::class, 'toggleStatus']);
Route::apiResource('customer-groups', CustomerGroupController::class);

// Suppliers & Supplier Categories
Route::get('suppliers/template', [SupplierController::class, 'downloadTemplate']);
Route::get('suppliers/export', [SupplierController::class, 'export']);
Route::post('suppliers/bulk-import', [SupplierController::class, 'bulkImport']);
Route::patch('suppliers/{id}/toggle-status', [SupplierController::class, 'toggleStatus']);
Route::apiResource('suppliers', SupplierController::class);
Route::patch('supplier-categories/{id}/toggle-status', [SupplierCategoryController::class, 'toggleStatus']);
Route::apiResource('supplier-categories', SupplierCategoryController::class);

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
Route::get('reports/profit-loss', [ReportController::class, 'profitLoss'])->middleware('permission:reports.profit_loss');
Route::get('reports/commercial-tax', [ReportController::class, 'commercialTaxReport'])->middleware('permission:reports.commercial_tax');
Route::get('reports/dashboard-summary', [ReportController::class, 'dashboardSummary']);
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
Route::get('reports/customers/{id}/statement', [ReportController::class, 'customerStatement']);
Route::get('reports/suppliers', [ReportController::class, 'supplierReport']);
Route::get('reports/suppliers/{id}/statement', [ReportController::class, 'supplierStatement']);
Route::get('reports/expenses', [ReportController::class, 'expenseReport']);
Route::get('reports/register', [ReportController::class, 'registerReport']);
Route::get('reports/z-report', [ReportController::class, 'zReport']);
Route::get('reports/ar-aging', [ReportController::class, 'arAgingReport']);
Route::get('reports/ap-aging', [ReportController::class, 'apAgingReport']);
Route::get('reports/cash-flow', [ReportController::class, 'cashFlowReport']);
Route::get('reports/stock-ledger', [ReportController::class, 'stockLedgerReport']);
Route::get('reports/product-margins', [ReportController::class, 'productMarginsReport']);
Route::get('reports/dead-stock', [ReportController::class, 'deadStockReport']);
Route::get('reports/cashier-audit', [ReportController::class, 'cashierAuditReport']);

// Cash Register Till & Shift Management
Route::get('cash-register/status', [CashRegisterController::class, 'status']);
Route::post('cash-register/open', [CashRegisterController::class, 'open']);
Route::get('cash-register/details/{id?}', [CashRegisterController::class, 'details']);
Route::post('cash-register/close/{id?}', [CashRegisterController::class, 'close']);
Route::post('cash-register/transaction', [CashRegisterController::class, 'recordTransaction']);

// General Settings & Multi-Currency
Route::get('settings', [SettingController::class, 'getSettings'])->middleware('permission:general_settings.view');
Route::post('settings', [SettingController::class, 'updateSettings'])->middleware('permission:general_settings.update');
Route::post('currencies/{id}/set-default', [CurrencyController::class, 'setDefault']);
Route::apiResource('currencies', CurrencyController::class);

// 🤖 Telegram Bot Channel & Mini App (Public)
Route::prefix('bot')->group(function () {
    Route::get('config', [BotOrderController::class, 'getBotConfig']);
    Route::get('products', [BotOrderController::class, 'getProducts']);
    Route::post('orders', [BotOrderController::class, 'submitOrder']);
    Route::post('upload-slip', [BotOrderController::class, 'uploadSlip']);
    Route::post('webhook', [BotOrderController::class, 'handleWebhook']);
    Route::post('restock-subscribe', [BotOrderController::class, 'subscribeRestock']);
});

// 🤖 Telegram Bot Orders Management & Settings (Staff / POS)
Route::get('bot-orders', [BotOrderController::class, 'listBotOrders']);
Route::get('bot-orders/{id}', [BotOrderController::class, 'getBotOrder']);
Route::get('bot-orders/{id}/voucher', [BotOrderController::class, 'getVoucherImage']);
Route::post('bot-orders/{id}/approve', [BotOrderController::class, 'approveOrder']);
Route::post('bot-orders/{id}/reject', [BotOrderController::class, 'rejectOrder']);
Route::post('bot-orders/{id}/dispatch', [BotOrderController::class, 'dispatchOrder']);
// Aliases for hierarchical /api/bot/orders/{id}
Route::post('bot/orders/{id}/approve', [BotOrderController::class, 'approveOrder']);
Route::post('bot/orders/{id}/reject', [BotOrderController::class, 'rejectOrder']);
Route::post('bot/orders/{id}/dispatch', [BotOrderController::class, 'dispatchOrder']);
Route::get('bot-settings', [BotOrderController::class, 'getBotSettings']);
Route::post('bot-settings', [BotOrderController::class, 'updateBotSettings']);
Route::post('bot-settings/test-token', [BotOrderController::class, 'testBotToken']);
Route::post('bot-settings/sync-commands', [BotOrderController::class, 'syncBotCommands']);
Route::post('bot-settings/sync-menu-button', [BotOrderController::class, 'syncMenuButton']);
Route::post('bot-settings/set-webhook', [BotOrderController::class, 'setWebhook']);
Route::post('bot-settings/delete-webhook', [BotOrderController::class, 'deleteWebhook']);
Route::get('bot-settings/webhook-info', [BotOrderController::class, 'getWebhookInfo']);
Route::get('bot-restock-subscribers', [BotOrderController::class, 'listRestockSubscribers']);

// 🤖 AI Agent & POS Live Customer Chat Routes
Route::post('bot-settings/test-ai', [BotOrderController::class, 'testAiAgent']);
Route::post('bot-settings/simulate-ai', [BotOrderController::class, 'simulateAiChat']);
Route::get('bot-chats', [BotOrderController::class, 'listBotChats']);
Route::get('bot-chats/{chatId}/messages', [BotOrderController::class, 'getChatMessages']);
Route::post('bot-chats/{chatId}/send', [BotOrderController::class, 'sendStaffChatMessage']);
Route::post('bot-chats/{chatId}/toggle-mode', [BotOrderController::class, 'toggleChatAiMode']);
Route::post('bot-chats/{chatId}/mark-read', [BotOrderController::class, 'markChatAsRead']);
Route::post('bot-chats/{chatId}/mark-unread', [BotOrderController::class, 'markChatAsUnread']);
Route::post('bot-chats/{chatId}/toggle-needs-human', [BotOrderController::class, 'toggleChatNeedsHuman']);


