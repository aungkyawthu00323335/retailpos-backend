<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Quotation;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Expense;
use App\Models\Customer;
use App\Models\Supplier;

class RetailPosTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Brand CRUD Testing
     */
    public function test_brand_crud(): void
    {
        // CREATE
        $createRes = $this->postJson('/api/brands', [
            'name' => 'Adidas Originals',
            'code' => 'BR-ADI',
            'description' => 'German athletic apparel brand',
            'status' => 'Active',
        ]);
        $createRes->assertStatus(201);
        $brandId = $createRes->json('data.id');

        // READ
        $readRes = $this->getJson("/api/brands/{$brandId}");
        $readRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Adidas Originals');

        // UPDATE
        $updateRes = $this->putJson("/api/brands/{$brandId}", [
            'name' => 'Adidas International',
            'status' => 'Active',
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Adidas International');

        // DELETE
        $deleteRes = $this->deleteJson("/api/brands/{$brandId}");
        $deleteRes->assertStatus(200);
    }

    /**
     * 2. Product Barcode & CRUD Testing
     */
    public function test_product_crud_and_barcode_lookup(): void
    {
        $code = '8934567999999';
        // CREATE
        $res = $this->postJson('/api/products', [
            'code' => $code,
            'barcode_symbology' => 'CODE128',
            'name' => 'Puma RS-X Geek Sneaker',
            'brand_name' => 'Puma',
            'category_name' => 'Footwear & Shoes',
            'unit_name' => 'Pair',
            'purchase_price' => 50.00,
            'retail_price' => 110.00,
            'stock_quantity' => 30.0,
            'alert_quantity' => 5.0,
            'status' => 'Active',
        ]);
        $res->assertStatus(201);
        $prodId = $res->json('data.id');

        // BARCODE LOOKUP
        $barcodeRes = $this->getJson("/api/products/barcode?code={$code}");
        $barcodeRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Puma RS-X Geek Sneaker');

        // UPDATE
        $updateRes = $this->putJson("/api/products/{$prodId}", [
            'name' => 'Puma RS-X Geek Sneaker Pro',
            'retail_price' => 125.00,
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Puma RS-X Geek Sneaker Pro');

        // DELETE
        $this->deleteJson("/api/products/{$prodId}")->assertStatus(200);
    }

    /**
     * 3. Payment Methods (InfyPOS Reference) Testing
     */
    public function test_payment_methods_crud_and_toggle(): void
    {
        // LIST
        $listRes = $this->getJson('/api/payment-methods');
        $listRes->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);

        // CREATE NEW CUSTOM METHOD
        $createRes = $this->postJson('/api/payment-methods', [
            'name' => 'AyaPay Wallet',
            'code' => 'AYAPAY',
            'icon' => 'account_balance_wallet_rounded',
            'is_active' => true,
            'is_default' => false,
        ]);
        $createRes->assertStatus(201);
        $pmId = $createRes->json('data.id');

        // TOGGLE STATUS
        $toggleRes = $this->patchJson("/api/payment-methods/{$pmId}/toggle-status");
        $toggleRes->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        // CLEANUP
        $this->deleteJson("/api/payment-methods/{$pmId}")->assertStatus(200);
    }

    /**
     * 4. Sales & POS Checkout Testing
     */
    public function test_sales_and_pos_checkout(): void
    {
        $customer = Customer::create([
            'name' => 'Retail POS Test Customer',
            'phone' => '09999888777',
            'status' => 'Active',
            'credit_limit' => 50000.00,
        ]);

        $saleData = [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_name' => 'Main Warehouse Branch',
            'date' => date('Y-m-d'),
            'status' => 'Completed',
            'subtotal' => 200.00,
            'tax_rate' => 5.0,
            'tax_amount' => 10.00,
            'discount' => 10.00,
            'shipping' => 0.00,
            'grand_total' => 200.00,
            'paid_amount' => 50.00,
            'due_amount' => 150.00,
            'payment_status' => 'Partial',
            'payment_type' => 'Cash',
            'pos_flag' => true,
            'items' => [
                [
                    'product_code' => '8934567890123',
                    'product_name' => 'Nike Air Zoom Pegasus 40',
                    'unit_price' => 130.00,
                    'quantity' => 1,
                    'tax_amount' => 5.00,
                    'discount_amount' => 5.00,
                    'subtotal' => 130.00,
                ]
            ],
            'payments' => [
                [
                    'payment_type' => 'Cash',
                    'amount' => 50.00,
                    'received_amount' => 50.00,
                    'change_amount' => 0.00,
                ]
            ]
        ];

        $res = $this->postJson('/api/sales', $saleData);
        $res->assertStatus(201);
        $saleId = $res->json('data.id');
        $remainingDue = (float)$res->json('data.due_amount');

        // Settle remaining balance; subsequent overpayment must be rejected (422)
        $payRes = $this->postJson("/api/sales/{$saleId}/payments", [
            'amount' => $remainingDue > 0 ? $remainingDue : 50.00,
            'payment_type' => 'KBZPay',
            'notes' => 'Additional deposit',
        ]);
        $payRes->assertStatus(200);

        $this->postJson("/api/sales/{$saleId}/payments", [
            'amount' => 10.00,
            'payment_type' => 'Cash',
        ])->assertStatus(422);

        // Fetch sales list
        $list = $this->getJson('/api/sales');
        $list->assertStatus(200);
    }

    /**
     * 4b. POS Offline Bulk Sync Testing (Phase 1 Enterprise Feature)
     */
    public function test_sales_bulk_sync(): void
    {
        $batch = [
            'sales' => [
                [
                    'reference_no' => 'OFFLINE-SYNC-TEST-001',
                    'customer_name' => 'Walk-in Customer',
                    'warehouse_name' => 'Main Warehouse Branch',
                    'date' => date('Y-m-d'),
                    'status' => 'Completed',
                    'subtotal' => 130.00,
                    'grand_total' => 130.00,
                    'paid_amount' => 130.00,
                    'due_amount' => 0.00,
                    'payment_status' => 'Paid',
                    'payment_type' => 'Cash',
                    'items' => [
                        [
                            'product_code' => '8934567890123',
                            'product_name' => 'Nike Air Zoom Pegasus 40',
                            'unit_price' => 130.00,
                            'quantity' => 1,
                            'subtotal' => 130.00,
                        ]
                    ],
                ],
                [
                    'reference_no' => 'OFFLINE-SYNC-TEST-002',
                    'customer_name' => 'Walk-in Customer',
                    'warehouse_name' => 'Main Warehouse Branch',
                    'date' => date('Y-m-d'),
                    'status' => 'Completed',
                    'subtotal' => 130.00,
                    'grand_total' => 130.00,
                    'paid_amount' => 130.00,
                    'due_amount' => 0.00,
                    'payment_status' => 'Paid',
                    'payment_type' => 'Cash',
                    'items' => [
                        [
                            'product_code' => '8934567890123',
                            'product_name' => 'Nike Air Zoom Pegasus 40',
                            'unit_price' => 130.00,
                            'quantity' => 1,
                            'subtotal' => 130.00,
                        ]
                    ],
                ],
            ]
        ];

        // 1. Initial Batch Sync
        $res = $this->postJson('/api/sales/bulk-sync', $batch);
        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('synced_count', 2)
            ->assertJsonPath('failed_count', 0);

        $this->assertDatabaseHas('sales', ['reference_no' => 'OFFLINE-SYNC-TEST-001']);
        $this->assertDatabaseHas('sales', ['reference_no' => 'OFFLINE-SYNC-TEST-002']);

        // 2. Idempotent Retry of same batch (should report already_synced without error or duplicate records)
        $retryRes = $this->postJson('/api/sales/bulk-sync', $batch);
        $retryRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('synced_count', 2)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('synced.0.status', 'already_synced')
            ->assertJsonPath('synced.1.status', 'already_synced');
    }

    /**
     * 5. Quotations Testing
     */
    public function test_quotations_crud(): void
    {
        $quoteData = [
            'customer_name' => 'Thura Zaw',
            'warehouse_name' => 'Main Warehouse Branch',
            'date' => date('Y-m-d'),
            'grand_total' => 500.00,
            'status' => 'Sent',
            'items' => [
                [
                    'product_code' => '8934567890125',
                    'product_name' => 'Sony WH-1000XM5 Noise Canceling Headphones',
                    'unit_price' => 399.00,
                    'quantity' => 1,
                    'subtotal' => 399.00,
                ]
            ]
        ];

        $res = $this->postJson('/api/quotations', $quoteData);
        $res->assertStatus(201);
        $quoteId = $res->json('data.id');

        $this->getJson("/api/quotations/{$quoteId}")->assertStatus(200);
    }

    /**
     * 6. Purchases & Procurement Testing
     */
    public function test_purchases_crud(): void
    {
        $purchaseData = [
            'supplier_name' => 'Apex Global Distribution Ltd',
            'warehouse_name' => 'Main Warehouse Branch',
            'date' => date('Y-m-d'),
            'status' => 'Received',
            'subtotal' => 1300.00,
            'grand_total' => 1300.00,
            'paid_amount' => 1300.00,
            'due_amount' => 0.00,
            'payment_status' => 'Paid',
            'items' => [
                [
                    'product_code' => '8934567890123',
                    'product_name' => 'Nike Air Zoom Pegasus 40',
                    'unit_cost' => 65.00,
                    'quantity' => 20,
                    'subtotal' => 1300.00,
                ]
            ]
        ];

        $res = $this->postJson('/api/purchases', $purchaseData);
        $res->assertStatus(201);
    }

    /**
     * 7. Stock Adjustments & Transfers Testing
     */
    public function test_adjustments_and_transfers(): void
    {
        // Stock Adjustment
        $adjRes = $this->postJson('/api/adjustments', [
            'warehouse_name' => 'Main Warehouse Branch',
            'date' => date('Y-m-d'),
            'type' => 'Addition',
            'notes' => 'Annual warehouse inventory physical audit',
            'items' => [
                [
                    'product_code' => '8934567890123',
                    'product_name' => 'Nike Air Zoom Pegasus 40',
                    'quantity' => 5,
                    'type' => 'Addition',
                    'unit_cost' => 65.00,
                ]
            ]
        ]);
        $adjRes->assertStatus(201);

        // Stock Transfer
        $transRes = $this->postJson('/api/transfers', [
            'from_warehouse' => 'Main Warehouse Branch',
            'to_warehouse' => 'Downtown Store Branch',
            'date' => date('Y-m-d'),
            'notes' => 'Replenish downtown retail stock',
            'items' => [
                [
                    'product_code' => '8934567890123',
                    'product_name' => 'Nike Air Zoom Pegasus 40',
                    'quantity' => 10,
                    'unit_cost' => 65.00,
                ]
            ]
        ]);
        $transRes->assertStatus(201);
    }

    /**
     * 8. Expenses & Categories Testing
     */
    public function test_expenses_crud(): void
    {
        $res = $this->postJson('/api/expenses', [
            'warehouse_name' => 'Main Warehouse Branch',
            'expense_category_name' => 'Electricity & Utilities',
            'title' => 'Main Showroom LED Lighting Upgrade',
            'amount' => 180.00,
            'date' => date('Y-m-d'),
            'details' => 'Energy saving bulbs replacement',
        ]);
        $res->assertStatus(201);
    }

    /**
     * 9. All InfyPOS Report Endpoints Testing
     */
    public function test_all_reports_endpoints(): void
    {
        // Dashboard Summary
        $this->getJson('/api/reports/dashboard-summary')
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => ['total_sales', 'total_purchases', 'net_profit']]);

        // Profit and Loss
        $this->getJson('/api/reports/profit-loss')
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => ['gross_sales', 'sales_return', 'net_sales', 'gross_profit', 'net_profit']]);

        // Stock Valuation Report
        $this->getJson('/api/reports/stock')
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => ['total_products', 'total_quantity', 'total_stock_value_cost', 'total_stock_value_retail']]);

        // Sales Report
        $this->getJson('/api/reports/sales')
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => ['total_revenue', 'total_paid', 'sales']]);

        // Purchases Report
        $this->getJson('/api/reports/purchases')
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => ['total_cost', 'total_paid', 'purchases']]);

        // Top Selling Products Report
        $this->getJson('/api/reports/top-selling')
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);

        // Customer Spending Report
        $this->getJson('/api/reports/customers')
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);

        // Supplier Report
        $this->getJson('/api/reports/suppliers')
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);

        // Expense Report
        $this->getJson('/api/reports/expenses')
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => ['total_expense', 'by_category']]);
    }

    /**
     * 10. Store Settings & Multi-Currency Testing
     */
    public function test_settings_and_currencies(): void
    {
        // Settings Get & Update
        $this->getJson('/api/settings')->assertStatus(200);

        $setRes = $this->postJson('/api/settings', [
            'company_name' => 'Infy Retail Enterprise POS',
            'tax_rate' => '7',
            'receipt_width' => '80mm',
        ]);
        $setRes->assertStatus(200);

        // Currencies List
        $this->getJson('/api/currencies')->assertStatus(200);
    }

    /**
     * 11. Users & Roles RBAC Testing
     */
    public function test_roles_and_users_crud(): void
    {
        // CREATE ROLE
        $roleRes = $this->postJson('/api/roles', [
            'name' => 'Store Supervisor',
            'description' => 'Shift supervisor for floor inventory and refunds',
            'color' => '#8B5CF6',
            'permissions' => ['Dashboard', 'POS Terminal', 'Sales & Returns', 'Customers & CRM'],
            'status' => 'Active',
        ]);
        $roleRes->assertStatus(201);
        $roleId = $roleRes->json('data.id');

        // CREATE USER
        $userRes = $this->postJson('/api/users', [
            'name' => 'Ko Thet Naing',
            'email' => 'thetnaing@infypos.com',
            'phone' => '09781234567',
            'password' => 'secret123',
            'role_id' => $roleId,
            'role' => 'Store Supervisor',
            'branch' => 'Downtown Store Branch',
            'status' => 'Active',
        ]);
        $userRes->assertStatus(201);
        $userId = $userRes->json('data.id');

        // GET USER
        $this->getJson("/api/users/{$userId}")
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Ko Thet Naing');

        // UPDATE USER
        $this->putJson("/api/users/{$userId}", [
            'name' => 'Ko Thet Naing Win',
            'status' => 'Active',
        ])->assertStatus(200);

        // DELETE USER & ROLE
        $this->deleteJson("/api/users/{$userId}")->assertStatus(200);
        $this->deleteJson("/api/roles/{$roleId}")->assertStatus(200);
    }

    /**
     * 12. Sub Category CRUD Testing
     */
    public function test_sub_categories_crud(): void
    {
        // CREATE PARENT CATEGORY
        $catRes = $this->postJson('/api/product-categories', [
            'name' => 'Footwear & Shoes',
            'description' => 'Retail footwear',
            'status' => 'Active',
        ]);
        $catRes->assertStatus(201);
        $catId = $catRes->json('data.id');

        // CREATE SUB CATEGORY
        $subRes = $this->postJson('/api/sub-categories', [
            'parent_category_id' => $catId,
            'parent_category_name' => 'Footwear & Shoes',
            'name' => 'Running Sneakers',
            'description' => 'High-performance marathon trainers',
            'status' => 'Active',
        ]);
        $subRes->assertStatus(201);
        $subId = $subRes->json('data.id');

        // LIST & FILTER BY PARENT
        $listRes = $this->getJson('/api/sub-categories?category=Footwear%20%26%20Shoes');
        $listRes->assertStatus(200);
        $this->assertNotEmpty($listRes->json('data'));

        // UPDATE
        $this->putJson("/api/sub-categories/{$subId}", [
            'name' => 'Pro Running Sneakers',
            'status' => 'Active',
        ])->assertStatus(200)
          ->assertJsonPath('data.name', 'Pro Running Sneakers');

        // DELETE
        $this->deleteJson("/api/sub-categories/{$subId}")->assertStatus(200);
    }
}
