<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase1AuthRbacSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Role $superAdminRole;
    private Role $cashierRole;
    private User $superAdmin;
    private User $cashier;
    private Outlet $outletA;
    private Outlet $outletB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outletA = Outlet::create([
            'name' => 'Yangon Main',
            'code' => 'YGN-01',
            'is_active' => true,
            'is_warehouse' => true,
        ]);

        $this->outletB = Outlet::create([
            'name' => 'Mandalay Branch',
            'code' => 'MDY-01',
            'is_active' => true,
            'is_warehouse' => true,
        ]);

        $this->superAdminRole = Role::create([
            'name' => 'Super Admin',
            'slug' => 'super_admin',
            'permissions' => ['*'],
            'is_default' => true,
            'status' => 'Active',
        ]);

        $this->cashierRole = Role::create([
            'name' => 'Cashier',
            'slug' => 'cashier',
            'permissions' => [
                'dashboard.view',
                'pos.view',
                'pos.checkout',
                'sales.view',
                'sales.create',
                'purchases.view',
                'purchases.create',
                'adjustments.view',
                'adjustments.create',
                'transfers.view',
                'transfers.create',
                'expenses.view',
                'expenses.create',
                'quotations.view',
                'quotations.create',
                'sale_returns.view',
                'sale_returns.create',
                'purchase_returns.view',
                'purchase_returns.create',
                'cash_register.view',
                'cash_register.open_close',
                'reports.view',
                'report_sales.view',
                'report_purchases.view',
                'report_stock.view',
                'report_stock_alert.view',
                'report_z_report.view',
                'report_cash_flow.view',
                'report_ar_aging.view',
                'report_ap_aging.view',
            ],
            'is_default' => false,
            'status' => 'Active',
        ]);

        $this->superAdmin = User::create([
            'user_id' => 'USR-100001',
            'name' => 'Primary Super Admin',
            'email' => 'superadmin@retailpos.test',
            'phone' => '091111111',
            'password' => bcrypt('secret123'),
            'role_id' => $this->superAdminRole->id,
            'role' => 'Super Admin',
            'status' => 'Active',
            'api_token' => 'token_super_admin_primary_123',
            'api_token_created_at' => now(),
        ]);

        $this->cashier = User::create([
            'user_id' => 'USR-100002',
            'name' => 'Branch A Cashier',
            'email' => 'cashier@retailpos.test',
            'phone' => '092222222',
            'password' => bcrypt('secret123'),
            'role_id' => $this->cashierRole->id,
            'role' => 'Cashier',
            'status' => 'Active',
            'api_token' => 'token_cashier_branch_a_456',
            'api_token_created_at' => now(),
        ]);
        $this->cashier->outlets()->sync([$this->outletA->id]);
    }

    public function test_r1_1_api_token_and_timestamp_are_hidden_from_user_and_role_payloads_while_login_returns_token(): void
    {
        // Login explicitly returns token, while nested user omits api_token
        $loginRes = $this->postJson('/api/login', [
            'email' => $this->cashier->email,
            'password' => 'secret123',
        ]);
        $loginRes->assertStatus(200)
            ->assertJsonStructure(['status', 'token', 'user'])
            ->assertJsonMissingPath('user.api_token')
            ->assertJsonMissingPath('user.api_token_created_at');

        $freshSuperToken = $this->superAdmin->api_token;

        $indexRes = $this->withToken($freshSuperToken)->getJson('/api/users');
        $indexRes->assertStatus(200);
        foreach ($indexRes->json('data') as $row) {
            $this->assertArrayNotHasKey('api_token', $row);
            $this->assertArrayNotHasKey('api_token_created_at', $row);
        }

        $compactRes = $this->withToken($freshSuperToken)->getJson('/api/users?compact=1');
        $compactRes->assertStatus(200);
        foreach ($compactRes->json('data') as $row) {
            $this->assertArrayNotHasKey('api_token', $row);
            $this->assertArrayNotHasKey('api_token_created_at', $row);
        }

        $showRes = $this->withToken($freshSuperToken)->getJson("/api/users/{$this->cashier->id}");
        $showRes->assertStatus(200)
            ->assertJsonMissingPath('data.api_token')
            ->assertJsonMissingPath('data.api_token_created_at');

        $storeRes = $this->withToken($freshSuperToken)->postJson('/api/users', [
            'name' => 'New Staff',
            'email' => 'newstaff@retailpos.test',
            'password' => 'secret123',
            'role_id' => $this->cashierRole->id,
        ]);
        $storeRes->assertStatus(201)
            ->assertJsonMissingPath('data.api_token')
            ->assertJsonMissingPath('data.api_token_created_at');
        $newId = $storeRes->json('data.id');

        $updateRes = $this->withToken($freshSuperToken)->putJson("/api/users/{$newId}", [
            'name' => 'New Staff Updated',
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonMissingPath('data.api_token')
            ->assertJsonMissingPath('data.api_token_created_at');

        $toggleRes = $this->withToken($freshSuperToken)->patchJson("/api/users/{$newId}/toggle-status");
        $toggleRes->assertStatus(200)
            ->assertJsonMissingPath('data.api_token')
            ->assertJsonMissingPath('data.api_token_created_at');

        $roleShowRes = $this->withToken($freshSuperToken)->getJson("/api/roles/{$this->cashierRole->id}");
        $roleShowRes->assertStatus(200);
        foreach ($roleShowRes->json('data.users') as $roleUser) {
            $this->assertArrayNotHasKey('api_token', $roleUser);
            $this->assertArrayNotHasKey('api_token_created_at', $roleUser);
        }
    }

    public function test_r1_2_inactive_user_and_inactive_role_are_rejected_and_tokens_revoked_on_deactivation(): void
    {
        // 1. Inactive user cannot login or use existing token
        $inactiveUser = User::create([
            'user_id' => 'USR-INACT-1',
            'name' => 'Inactive Staff',
            'email' => 'inactive@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $this->cashierRole->id,
            'role' => 'Cashier',
            'status' => 'Inactive',
            'api_token' => 'stale_inactive_user_token',
            'api_token_created_at' => now(),
        ]);

        $this->postJson('/api/login', [
            'email' => $inactiveUser->email,
            'password' => 'secret123',
        ])->assertStatus(403);

        $this->withToken('stale_inactive_user_token')->getJson('/api/me')->assertStatus(401);
        $inactiveUser->refresh();
        $this->assertNull($inactiveUser->api_token);
        $this->assertNull($inactiveUser->api_token_created_at);

        // Also rejected on guarded route & permission check
        $inactiveUser->api_token = 'stale_inactive_user_token_2';
        $inactiveUser->api_token_created_at = now();
        $inactiveUser->save();
        $this->withToken('stale_inactive_user_token_2')->getJson('/api/sales')->assertStatus(401);
        $this->assertFalse($inactiveUser->fresh()->hasPermission('sales.view'));

        // 2. Active user with inactive role cannot login, use token, or pass hasPermission
        $inactiveRole = Role::create([
            'name' => 'Suspended Supervisor',
            'slug' => 'suspended_supervisor',
            'permissions' => ['*'],
            'status' => 'Inactive',
        ]);
        $suspendedUser = User::create([
            'user_id' => 'USR-SUSP-1',
            'name' => 'Suspended Role User',
            'email' => 'suspended@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $inactiveRole->id,
            'role' => 'Suspended Supervisor',
            'status' => 'Active',
            'api_token' => 'token_suspended_role_user',
            'api_token_created_at' => now(),
        ]);

        $this->assertFalse($suspendedUser->hasPermission('sales.view'));
        $this->postJson('/api/login', [
            'email' => $suspendedUser->email,
            'password' => 'secret123',
        ])->assertStatus(403);

        $this->withToken('token_suspended_role_user')->getJson('/api/me')->assertStatus(401);
        $suspendedUser->refresh();
        $this->assertNull($suspendedUser->api_token);

        // 3. Deactivating via toggleStatus revokes token immediately
        $staffA = User::create([
            'user_id' => 'USR-REV-A',
            'name' => 'Staff A',
            'email' => 'staffa@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $this->cashierRole->id,
            'role' => 'Cashier',
            'status' => 'Active',
            'api_token' => 'tok_staff_a_123',
            'api_token_created_at' => now(),
        ]);
        $this->withToken($this->superAdmin->api_token)
            ->patchJson("/api/users/{$staffA->id}/toggle-status")
            ->assertStatus(200);
        $this->assertNull($staffA->fresh()->api_token);
        $this->withToken('tok_staff_a_123')->getJson('/api/me')->assertStatus(401);

        // 4. Deactivating via PUT /users/{id} revokes token immediately
        $staffB = User::create([
            'user_id' => 'USR-REV-B',
            'name' => 'Staff B',
            'email' => 'staffb@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $this->cashierRole->id,
            'role' => 'Cashier',
            'status' => 'Active',
            'api_token' => 'tok_staff_b_123',
            'api_token_created_at' => now(),
        ]);
        $this->withToken($this->superAdmin->api_token)
            ->putJson("/api/users/{$staffB->id}", ['status' => 'Inactive'])
            ->assertStatus(200);
        $this->assertNull($staffB->fresh()->api_token);
        $this->withToken('tok_staff_b_123')->getJson('/api/sales')->assertStatus(401);

        // 5. Deactivating a Role revokes all assigned users' tokens immediately, and super_admin role cannot be deactivated
        $this->withToken($this->superAdmin->api_token)
            ->putJson("/api/roles/{$this->superAdminRole->id}", ['status' => 'Inactive'])
            ->assertStatus(422);

        $cashierToken = $this->cashier->api_token;
        $this->withToken($this->superAdmin->api_token)
            ->putJson("/api/roles/{$this->cashierRole->id}", ['status' => 'Inactive'])
            ->assertStatus(200);
        $this->assertNull($this->cashier->fresh()->api_token);
        $this->withToken($cashierToken)->getJson('/api/me')->assertStatus(401);

        // 6. Unauthenticated /api/me must return 401 when token-bearing users exist (no testing fallback leak)
        $this->getJson('/api/me')->assertStatus(401);

        // 7. Lowercase 'active' status continues to work seamlessly
        $lowerRole = Role::create([
            'name' => 'Lowercase Active Role',
            'slug' => 'lowercase_active_role',
            'permissions' => ['sales.view'],
            'status' => 'active',
        ]);
        $lowerUser = User::create([
            'user_id' => 'USR-LOW-1',
            'name' => 'Lowercase Staff',
            'email' => 'lower@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $lowerRole->id,
            'role' => 'Lowercase Active Role',
            'status' => 'active',
            'api_token' => 'tok_lower_active_123',
            'api_token_created_at' => now(),
        ]);
        $this->withToken('tok_lower_active_123')->getJson('/api/me')->assertStatus(200);
        $this->withToken('tok_lower_active_123')->getJson('/api/sales')->assertStatus(200);
    }

    public function test_r1_2_acting_as_inactive_user_without_tokens_in_db_is_rejected(): void
    {
        // Remove all tokens to simulate no-token testing DB
        User::query()->update(['api_token' => null, 'api_token_created_at' => null]);

        $inactive = User::create([
            'user_id' => 'USR-ACT-INACT',
            'name' => 'ActingAs Inactive',
            'email' => 'actingas_inactive@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $this->cashierRole->id,
            'role' => 'Cashier',
            'status' => 'Inactive',
        ]);

        $this->actingAs($inactive)->getJson('/api/sales')->assertStatus(401);
        $this->actingAs($inactive)->getJson('/api/me')->assertStatus(401);
    }

    public function test_r1_3_zero_outlet_non_super_admin_is_denied_by_default_across_service_mutations_and_reports(): void
    {
        $accounting = app(PurchaseAccountingService::class);

        $zeroOutletUser = User::create([
            'user_id' => 'USR-ZERO-1',
            'name' => 'Zero Outlet Staff',
            'email' => 'zero@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $this->cashierRole->id,
            'role' => 'Cashier',
            'status' => 'Active',
            'api_token' => 'token_zero_outlet_staff',
            'api_token_created_at' => now(),
        ]);

        $inactiveOutlet = Outlet::create([
            'name' => 'Closed Outlet',
            'code' => 'CLS-01',
            'is_active' => false,
        ]);
        $inactiveOutletUser = User::create([
            'user_id' => 'USR-INACT-OUT',
            'name' => 'Inactive Outlet Staff',
            'email' => 'inact_outlet@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $this->cashierRole->id,
            'role' => 'Cashier',
            'status' => 'Active',
            'api_token' => 'token_inactive_outlet_staff',
            'api_token_created_at' => now(),
        ]);
        $inactiveOutletUser->outlets()->sync([$inactiveOutlet->id]);

        // Service contract checks
        $this->assertNull($accounting->extractAllowedOutletIds($this->superAdmin));
        $this->assertSame([-1], $accounting->extractAllowedOutletIds($zeroOutletUser));
        $this->assertSame([-1], $accounting->extractAllowedOutletIds($inactiveOutletUser));
        $this->assertSame([$this->outletA->id], $accounting->extractAllowedOutletIds($this->cashier));
        $this->assertSame([-1], $accounting->extractAllowedOutletIds(null));
        $this->assertSame('test_live_all', $accounting->scopedMetaCacheKey('test', null));
        $this->assertSame('test_live_-1', $accounting->scopedMetaCacheKey('test', [-1]));
        $this->assertSame('test_live_none', $accounting->scopedMetaCacheKey('test', []));
        $this->assertSame('test_live_1_2', $accounting->scopedMetaCacheKey('test', [1, 2]));

        // Seed multi-outlet operational & financial data
        $supplier = Supplier::create(['name' => 'Test Supplier', 'phone' => '0999999']);
        $customer = Customer::create(['name' => 'Test Customer', 'phone' => '0988888']);
        $expCat = ExpenseCategory::create(['name' => 'Rent']);
        $product = Product::create([
            'name' => 'Audit Widget',
            'code' => 'AUD-001',
            'cost_price' => 1000,
            'purchase_price' => 1000,
            'retail_price' => 2000,
            'selling_price' => 2000,
            'stock_quantity' => 5,
            'alert_quantity' => 10,
            'unit_name' => 'pcs',
        ]);
        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->outletA->id,
            'quantity' => 5,
        ]);

        $sale = Sale::create([
            'reference_no' => 'SL-OUTA-1',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => now()->toDateTimeString(),
            'status' => 'Completed',
            'grand_total' => 2000,
            'paid_amount' => 1000,
            'due_amount' => 1000,
            'payment_status' => 'Partial',
        ]);
        $purchase = Purchase::create([
            'reference_no' => 'PO-OUTA-1',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => now()->toDateTimeString(),
            'status' => 'Received',
            'grand_total' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'Unpaid',
        ]);
        $expense = Expense::create([
            'reference_no' => 'EXP-OUTA-1',
            'expense_category_id' => $expCat->id,
            'expense_category_name' => $expCat->name,
            'title' => 'Monthly Rent',
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => now()->toDateString(),
            'amount' => 500,
            'payment_status' => 'Paid',
        ]);
        $legacyNullExpense = Expense::create([
            'reference_no' => 'EXP-NULL-1',
            'expense_category_id' => $expCat->id,
            'expense_category_name' => $expCat->name,
            'title' => 'Legacy Rent',
            'warehouse_id' => null,
            'warehouse_name' => '',
            'date' => now()->toDateString(),
            'amount' => 200,
            'payment_status' => 'Paid',
        ]);
        $transfer = StockTransfer::create([
            'reference_no' => 'TRF-OUTA-1',
            'from_warehouse_id' => $this->outletA->id,
            'from_warehouse' => $this->outletA->name,
            'to_warehouse_id' => $this->outletB->id,
            'to_warehouse' => $this->outletB->name,
            'date' => now()->toDateTimeString(),
            'status' => 'Pending',
        ]);
        $legacyNullTransfer = StockTransfer::create([
            'reference_no' => 'TRF-NULL-1',
            'from_warehouse_id' => null,
            'from_warehouse' => '',
            'to_warehouse_id' => null,
            'to_warehouse' => '',
            'date' => now()->toDateTimeString(),
            'status' => 'Pending',
        ]);
        StockAdjustment::create([
            'reference_no' => 'ADJ-OUTA-1',
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => now()->toDateTimeString(),
            'type' => 'Addition',
            'total_products' => 1,
            'total_quantity' => 5,
        ]);
        Quotation::create([
            'reference_no' => 'QUO-OUTA-1',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => now()->toDateString(),
            'status' => 'Sent',
            'grand_total' => 2000,
        ]);
        SaleReturn::create([
            'reference_no' => 'SR-OUTA-1',
            'sale_reference_no' => $sale->reference_no,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => now()->toDateTimeString(),
            'status' => 'Completed',
            'grand_total' => 500,
        ]);
        PurchaseReturn::create([
            'reference_no' => 'PR-OUTA-1',
            'purchase_reference_no' => $purchase->reference_no,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => now()->toDateTimeString(),
            'status' => 'Completed',
            'grand_total' => 300,
        ]);

        // Zero-outlet user MUST receive 403 on mutations and single-record reads (including null-warehouse legacy rows!)
        $tok = $zeroOutletUser->api_token;
        $today = now()->toDateString();
        $this->withToken($tok)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => $today,
            'subtotal' => 2000,
            'grand_total' => 2000,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => 2000,
                'subtotal' => 2000,
            ]],
        ])->assertStatus(403);

        $this->withToken($tok)->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => $today,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_cost' => 1000,
                'subtotal' => 1000,
            ]],
        ])->assertStatus(403);

        $this->withToken($tok)->postJson('/api/adjustments', [
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => $today,
            'type' => 'Addition',
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1,
            ]],
        ])->assertStatus(403);

        $this->withToken($tok)->postJson('/api/transfers', [
            'from_warehouse_id' => $this->outletA->id,
            'from_warehouse' => $this->outletA->name,
            'to_warehouse_id' => $this->outletB->id,
            'to_warehouse' => $this->outletB->name,
            'date' => $today,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1,
            ]],
        ])->assertStatus(403);

        $this->withToken($tok)->postJson('/api/expenses', [
            'expense_category_id' => $expCat->id,
            'expense_category_name' => $expCat->name,
            'title' => 'Blocked Expense',
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => $today,
            'amount' => 100,
        ])->assertStatus(403);

        $this->withToken($tok)->postJson('/api/cash-register/open', [
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'opening_cash' => 1000,
        ])->assertStatus(403);

        $this->withToken($tok)->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => $today,
            'grand_total' => 2000,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => 2000,
                'subtotal' => 2000,
            ]],
        ])->assertStatus(403);

        $this->withToken($tok)->postJson('/api/sale-returns', [
            'sale_id' => $sale->id,
            'sale_reference_no' => $sale->reference_no,
            'customer_name' => $customer->name,
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => $today,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 2000,
            ]],
        ])->assertStatus(403);

        $this->withToken($tok)->postJson('/api/purchase-returns', [
            'purchase_id' => $purchase->id,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'warehouse_id' => $this->outletA->id,
            'warehouse_name' => $this->outletA->name,
            'date' => $today,
            'grand_total' => 1000,
            'items' => [[
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_cost' => 1000,
                'subtotal' => 1000,
            ]],
        ])->assertStatus(403);

        $this->withToken($tok)->getJson("/api/sales/{$sale->id}")->assertStatus(403);
        $this->withToken($tok)->getJson("/api/expenses/{$expense->id}")->assertStatus(403);
        $this->withToken($tok)->getJson("/api/expenses/{$legacyNullExpense->id}")->assertStatus(403);
        $this->withToken($tok)->getJson("/api/transfers/{$transfer->id}")->assertStatus(403);
        $this->withToken($tok)->getJson("/api/transfers/{$legacyNullTransfer->id}")->assertStatus(403);

        // Explicit warehouse query on reports returns 403
        $this->withToken($tok)->getJson("/api/reports/stock?warehouse_id={$this->outletA->id}")->assertStatus(403);
        $this->withToken($tok)->getJson("/api/reports/stock-alert?warehouse_id={$this->outletA->id}")->assertStatus(403);

        // List endpoints return 200 with 0 rows
        foreach ([
            '/api/sales',
            '/api/purchases',
            '/api/adjustments',
            '/api/transfers',
            '/api/expenses',
            '/api/quotations',
            '/api/sale-returns',
            '/api/purchase-returns',
        ] as $listUri) {
            $res = $this->withToken($tok)->getJson($listUri);
            $res->assertStatus(200);
            $this->assertCount(0, $res->json('data'), "Expected 0 rows for 0-outlet user on {$listUri}");
        }

        // Reports return 200 with 0 rows / 0 totals (crucially stock-alert returns 0 items!)
        $alertRes = $this->withToken($tok)->getJson('/api/reports/stock-alert');
        $alertRes->assertStatus(200);
        $this->assertSame(0, (int) $alertRes->json('data.total'));
        $this->assertCount(0, $alertRes->json('data.items'));

        $stockRes = $this->withToken($tok)->getJson('/api/reports/stock');
        $stockRes->assertStatus(200);
        $this->assertCount(0, $stockRes->json('data.items') ?? []);

        $dashRes = $this->withToken($tok)->getJson('/api/reports/dashboard-summary');
        $dashRes->assertStatus(200);
        $this->assertEquals(0.0, (float) ($dashRes->json('data.sales_total') ?? $dashRes->json('data.total_sales') ?? 0));

        $salesRep = $this->withToken($tok)->getJson('/api/reports/sales');
        $salesRep->assertStatus(200);
        $this->assertCount(0, $salesRep->json('data.items') ?? $salesRep->json('data.sales') ?? []);

        $purchRep = $this->withToken($tok)->getJson('/api/reports/purchases');
        $purchRep->assertStatus(200);
        $this->assertCount(0, $purchRep->json('data.items') ?? $purchRep->json('data.purchases') ?? []);

        $arRep = $this->withToken($tok)->getJson('/api/reports/ar-aging');
        $arRep->assertStatus(200);
        $this->assertEquals(0.0, (float) ($arRep->json('data.summary.total_ar') ?? 0));

        $apRep = $this->withToken($tok)->getJson('/api/reports/ap-aging');
        $apRep->assertStatus(200);
        $this->assertEquals(0.0, (float) ($apRep->json('data.summary.total_ap') ?? 0));

        $cfRep = $this->withToken($tok)->getJson('/api/reports/cash-flow');
        $cfRep->assertStatus(200);

        $zRep = $this->withToken($tok)->getJson('/api/reports/z-report');
        $zRep->assertStatus(200);

        // Super Admin (null) must NOT throw TypeError on any report
        $adminTok = $this->superAdmin->api_token;
        $this->withToken($adminTok)->getJson('/api/reports/dashboard-summary')->assertStatus(200);
        $adminAlert = $this->withToken($adminTok)->getJson('/api/reports/stock-alert');
        $adminAlert->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, (int) $adminAlert->json('data.total'));
        $this->withToken($adminTok)->getJson('/api/reports/profit-loss')->assertStatus(200);
        $this->withToken($adminTok)->getJson('/api/reports/stock')->assertStatus(200);
    }

    public function test_r1_5_sole_active_super_admin_protection_and_caller_guard(): void
    {
        $managerRole = Role::create([
            'name' => 'Store Manager',
            'slug' => 'store_manager',
            'permissions' => ['users.view', 'users.update'],
            'status' => 'Active',
        ]);
        $manager = User::create([
            'user_id' => 'USR-MGR-1',
            'name' => 'Manager',
            'email' => 'mgr_r15@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $managerRole->id,
            'role' => 'Store Manager',
            'status' => 'Active',
            'api_token' => 'token_mgr_r15',
            'api_token_created_at' => now(),
        ]);

        // Add an INACTIVE Super Admin row to prove SQL precedence bug is fixed (count must stay 1 active)
        User::create([
            'user_id' => 'USR-SA-INACT',
            'name' => 'Inactive Super Admin',
            'email' => 'inact_sa@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $this->superAdminRole->id,
            'role' => 'Super Admin',
            'status' => 'Inactive',
        ]);

        // 1. Non-Super-Admin with users.update cannot toggle Super Admin status -> 403
        $this->withToken($manager->api_token)
            ->patchJson("/api/users/{$this->superAdmin->id}/toggle-status")
            ->assertStatus(403);

        // 2. Sole active Super Admin cannot be deactivated via toggleStatus -> 422
        $this->withToken($this->superAdmin->api_token)
            ->patchJson("/api/users/{$this->superAdmin->id}/toggle-status")
            ->assertStatus(422);

        // 3. Sole active Super Admin cannot be deactivated via PUT /users/{id} -> 422
        $this->withToken($this->superAdmin->api_token)
            ->putJson("/api/users/{$this->superAdmin->id}", ['status' => 'Inactive'])
            ->assertStatus(422);

        // 4. Sole active Super Admin cannot be demoted by role_id alone -> 422
        $this->withToken($this->superAdmin->api_token)
            ->putJson("/api/users/{$this->superAdmin->id}", ['role_id' => $this->cashierRole->id])
            ->assertStatus(422);

        // 5. Sole active Super Admin cannot be demoted by role name -> 422
        $this->withToken($this->superAdmin->api_token)
            ->putJson("/api/users/{$this->superAdmin->id}", ['role' => 'Cashier'])
            ->assertStatus(422);

        // 6. When a 2nd active Super Admin exists, deactivating or demoting 1 succeeds (200)
        $secondSuper = User::create([
            'user_id' => 'USR-SA-2',
            'name' => 'Second Super Admin',
            'email' => 'sa2@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $this->superAdminRole->id,
            'role' => 'Super Admin',
            'status' => 'Active',
            'api_token' => 'token_second_sa',
            'api_token_created_at' => now(),
        ]);

        $this->withToken($this->superAdmin->api_token)
            ->patchJson("/api/users/{$secondSuper->id}/toggle-status")
            ->assertStatus(200);
        $this->assertSame('Inactive', $secondSuper->fresh()->status);

        $secondSuper->update(['status' => 'Active']);
        $this->withToken($this->superAdmin->api_token)
            ->putJson("/api/users/{$secondSuper->id}", ['role_id' => $this->cashierRole->id])
            ->assertStatus(200);
        $this->assertSame('Cashier', $secondSuper->fresh()->role);
    }

    public function test_r1_8_floor_supervisor_is_never_marked_is_super_admin(): void
    {
        $floorRole = Role::create([
            'name' => 'Floor Supervisor',
            'slug' => 'floor_supervisor',
            'permissions' => ['dashboard.view', 'sales.view'],
            'status' => 'Active',
        ]);
        $floorUser = User::create([
            'user_id' => 'USR-FLOOR-1',
            'name' => 'Floor Sup',
            'email' => 'floor@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $floorRole->id,
            'role' => 'Floor Supervisor',
            'status' => 'Active',
            'api_token' => 'token_floor_sup',
            'api_token_created_at' => now(),
        ]);

        $this->assertFalse($floorUser->isSuperAdmin());
        $this->assertTrue($this->superAdmin->isSuperAdmin());

        $loginRes = $this->postJson('/api/login', [
            'email' => $floorUser->email,
            'password' => 'secret123',
        ]);
        $loginRes->assertStatus(200)
            ->assertJsonPath('user.is_super_admin', false);
        $freshToken = $loginRes->json('token');

        $this->withToken($freshToken)->getJson('/api/me')
            ->assertStatus(200)
            ->assertJsonPath('user.is_super_admin', false);

        $this->withToken($freshToken)->postJson('/api/update-profile', ['name' => 'Floor Sup Updated'])
            ->assertStatus(200)
            ->assertJsonPath('user.is_super_admin', false);

        $compactRes = $this->withToken($freshToken)->getJson('/api/users?compact=1');
        $compactRes->assertStatus(200);
        $compactById = collect($compactRes->json('data'))->keyBy('id');
        $this->assertFalse($compactById[$floorUser->id]['is_super_admin']);
        $this->assertTrue($compactById[$this->superAdmin->id]['is_super_admin']);

        $this->withToken($this->superAdmin->api_token)->getJson("/api/users/{$floorUser->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_super_admin', false);

        $roleShow = $this->withToken($this->superAdmin->api_token)->getJson("/api/roles/{$floorRole->id}");
        $roleShow->assertStatus(200);
        $this->assertFalse($roleShow->json('data.users.0.is_super_admin'));
    }

    public function test_r1_10_privilege_escalation_role_sync_cascade_and_route_deadlocks(): void
    {
        // Setup Manager (has users.view/create/update + roles.view, lacks report_ar_aging.view)
        // and Accountant (has report_ar_aging.view)
        $accountantRole = Role::create([
            'name' => 'Accountant',
            'slug' => 'accountant',
            'permissions' => ['dashboard.view', 'report_ar_aging.view', 'report_ap_aging.view'],
            'status' => 'Active',
        ]);
        $accountantUser = User::create([
            'user_id' => 'USR-ACCT-1',
            'name' => 'Chief Accountant',
            'email' => 'acct@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $accountantRole->id,
            'role' => 'Accountant',
            'status' => 'Active',
        ]);

        $managerRole = Role::create([
            'name' => 'User Manager',
            'slug' => 'user_manager',
            'permissions' => ['users.view', 'users.create', 'users.update', 'roles.view', 'dashboard.view', 'sales.view', 'sales.create', 'pos.view', 'pos.checkout', 'purchases.view', 'purchases.create', 'adjustments.view', 'adjustments.create', 'transfers.view', 'transfers.create', 'expenses.view', 'expenses.create', 'quotations.view', 'quotations.create', 'sale_returns.view', 'sale_returns.create', 'purchase_returns.view', 'purchase_returns.create', 'cash_register.view', 'cash_register.open_close', 'reports.view', 'report_sales.view', 'report_purchases.view', 'report_stock.view', 'report_stock_alert.view', 'report_z_report.view', 'report_cash_flow.view'],
            'status' => 'Active',
        ]);
        $manager = User::create([
            'user_id' => 'USR-UMGR-1',
            'name' => 'User Manager',
            'email' => 'umgr@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $managerRole->id,
            'role' => 'User Manager',
            'status' => 'Active',
            'api_token' => 'token_user_manager_123',
            'api_token_created_at' => now(),
        ]);

        // 1. Manager cannot create user with Accountant role (contains report_ar_aging.view & report_ap_aging.view)
        $this->withToken($manager->api_token)->postJson('/api/users', [
            'name' => 'Escalated User',
            'email' => 'esc1@retailpos.test',
            'password' => 'secret123',
            'role_id' => $accountantRole->id,
        ])->assertStatus(403);

        // 2. Manager cannot update an existing user to Accountant role
        $this->withToken($manager->api_token)->putJson("/api/users/{$this->cashier->id}", [
            'role_id' => $accountantRole->id,
        ])->assertStatus(403);

        // 3. Manager cannot reset password or email of Accountant user (existing role has unpossessed permissions)
        $this->withToken($manager->api_token)->putJson("/api/users/{$accountantUser->id}", [
            'password' => 'hacked123',
        ])->assertStatus(403);
        $this->withToken($manager->api_token)->putJson("/api/users/{$accountantUser->id}", [
            'email' => 'hijacked@retailpos.test',
        ])->assertStatus(403);

        // 4. Manager CAN create a subset role user (e.g., subsetRole) and role_id syncs users.role
        $subsetRole = Role::create([
            'name' => 'Junior Clerk',
            'slug' => 'junior_clerk',
            'permissions' => ['dashboard.view', 'sales.view'],
            'status' => 'Active',
        ]);
        $createSubset = $this->withToken($manager->api_token)->postJson('/api/users', [
            'name' => 'Subset Staff',
            'email' => 'subset@retailpos.test',
            'password' => 'secret123',
            'role_id' => $subsetRole->id, // note: no 'role' string supplied!
        ]);
        $createSubset->assertStatus(201)
            ->assertJsonPath('data.role', 'Junior Clerk')
            ->assertJsonPath('data.role_id', $subsetRole->id);
        $subsetId = $createSubset->json('data.id');

        // 5. Updating only role_id syncs users.role
        $subsetRole2 = Role::create([
            'name' => 'Senior Clerk',
            'slug' => 'senior_clerk',
            'permissions' => ['dashboard.view', 'sales.view', 'sales.create'],
            'status' => 'Active',
        ]);
        $this->withToken($manager->api_token)->putJson("/api/users/{$subsetId}", [
            'role_id' => $subsetRole2->id,
        ])->assertStatus(200)
            ->assertJsonPath('data.role', 'Senior Clerk');
        $this->assertSame('Senior Clerk', User::find($subsetId)->role);

        // 6. Renaming a Role cascades to users.role
        $this->withToken($this->superAdmin->api_token)->putJson("/api/roles/{$subsetRole2->id}", [
            'name' => 'Lead Clerk',
        ])->assertStatus(200);
        $this->assertSame('Lead Clerk', User::find($subsetId)->role);

        // 7. Route deadlock checks: Cashier without users.view / roles.view / products.create
        $compactRes = $this->withToken($this->cashier->api_token)->getJson('/api/users?compact=1');
        $compactRes->assertStatus(200);
        $firstCompact = $compactRes->json('data.0');
        $this->assertArrayHasKey('id', $firstCompact);
        $this->assertArrayHasKey('name', $firstCompact);
        $this->assertArrayHasKey('role', $firstCompact);
        $this->assertArrayHasKey('role_id', $firstCompact);
        $this->assertArrayHasKey('is_super_admin', $firstCompact);
        $this->assertArrayNotHasKey('email', $firstCompact);
        $this->assertArrayNotHasKey('phone', $firstCompact);
        $this->assertArrayNotHasKey('api_token', $firstCompact);
        $this->assertArrayNotHasKey('password', $firstCompact);
        $this->assertArrayNotHasKey('total_dependencies', $firstCompact);
        $this->assertArrayNotHasKey('sales_count', $firstCompact);

        $this->withToken($this->cashier->api_token)->getJson('/api/users')->assertStatus(403);
        $this->withToken($this->cashier->api_token)->getJson('/api/roles')->assertStatus(403);

        // 8. POST /api/upload-image: avatars allowed for any authenticated user; products/company/users denied without perm
        Storage::fake('public');
        $avatarFile = UploadedFile::fake()->image('avatar.png', 100, 100);
        $this->withToken($this->cashier->api_token)->post('/api/upload-image', [
            'image' => $avatarFile,
            'folder' => 'avatars',
        ])->assertStatus(200);

        $selfAvatarFile = UploadedFile::fake()->image('self.png', 100, 100);
        $this->withToken($this->cashier->api_token)->post('/api/upload-image', [
            'image' => $selfAvatarFile,
            'folder' => 'users',
            'self_avatar' => 1,
        ])->assertStatus(200);

        $prodFile = UploadedFile::fake()->image('prod.png', 100, 100);
        $this->withToken($this->cashier->api_token)->post('/api/upload-image', [
            'image' => $prodFile,
            'folder' => 'products',
        ])->assertStatus(403);

        $compFile = UploadedFile::fake()->image('comp.png', 100, 100);
        $this->withToken($this->cashier->api_token)->post('/api/upload-image', [
            'image' => $compFile,
            'folder' => 'company',
        ])->assertStatus(403);

        // 9. GET /api/roles allowed for users.create/users.update without roles.view, while GET /api/roles/{id} stays 403
        $userCreatorRole = Role::create([
            'name' => 'HR Onboarding',
            'slug' => 'hr_onboarding',
            'permissions' => ['users.create'],
            'status' => 'Active',
        ]);
        $hrUser = User::create([
            'user_id' => 'USR-HR-1',
            'name' => 'HR Staff',
            'email' => 'hr@retailpos.test',
            'password' => bcrypt('secret123'),
            'role_id' => $userCreatorRole->id,
            'role' => 'HR Onboarding',
            'status' => 'Active',
            'api_token' => 'token_hr_staff',
            'api_token_created_at' => now(),
        ]);
        $this->withToken($hrUser->api_token)->getJson('/api/roles')->assertStatus(200);
        $this->withToken($hrUser->api_token)->getJson("/api/roles/{$this->cashierRole->id}")->assertStatus(403);
    }
}
