<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAndPermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $cashier;
    private Role $cashierRole;
    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::create([
            'name' => 'Main Flagship Store',
            'code' => 'OUT-001',
        ]);

        // Create Cashier Role with restricted permissions
        $this->cashierRole = Role::create([
            'name' => 'Cashier',
            'slug' => 'cashier',
            'description' => 'Front desk point of sale operator',
            'color' => '#10B981',
            'permissions' => [
                'dashboard.view',
                'pos.view',
                'pos.checkout',
                'products.view',
                'sales.view',
                'sales.create',
                'customers.view',
                'customers.create',
                'cash_register.view',
                'cash_register.open_close',
            ],
            'is_default' => false,
            'status' => 'active',
        ]);

        // Create Super Admin User
        $this->superAdmin = User::create([
            'user_id' => 'USR-ADMIN-01',
            'name' => 'Root Administrator',
            'email' => 'admin@retailpos.test',
            'role' => 'Super Admin',
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'super_admin_test_token_secret_12345',
        ]);

        // Create Cashier User
        $this->cashier = User::create([
            'user_id' => 'USR-CASHIER-01',
            'name' => 'John Cashier',
            'email' => 'cashier@retailpos.test',
            'role' => 'Cashier',
            'role_id' => $this->cashierRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'cashier_test_token_secret_67890',
        ]);
    }

    public function test_super_admin_has_unrestricted_access_to_guarded_routes(): void
    {
        // 1. Users list
        $resUsers = $this->withToken($this->superAdmin->api_token)->getJson('/api/users');
        $resUsers->assertStatus(200);

        // 2. Roles list
        $resRoles = $this->withToken($this->superAdmin->api_token)->getJson('/api/roles');
        $resRoles->assertStatus(200);

        // 3. Settings
        $resSettings = $this->withToken($this->superAdmin->api_token)->getJson('/api/settings');
        $resSettings->assertStatus(200);

        // 4. Executive Profit & Loss Report
        $resPl = $this->withToken($this->superAdmin->api_token)->getJson('/api/reports/profit-loss');
        $resPl->assertStatus(200);
    }

    public function test_cashier_is_forbidden_from_admin_modules(): void
    {
        // 1. Cashier cannot access /api/users
        $resUsers = $this->withToken($this->cashier->api_token)->getJson('/api/users');
        $resUsers->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertSee('Access Denied');

        // 2. Cashier cannot access /api/roles
        $resRoles = $this->withToken($this->cashier->api_token)->getJson('/api/roles');
        $resRoles->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertSee('Access Denied');

        // 3. Cashier cannot access /api/settings
        $resSettings = $this->withToken($this->cashier->api_token)->getJson('/api/settings');
        $resSettings->assertStatus(403)
            ->assertJsonPath('status', 'error');

        // 4. Cashier cannot access confidential P&L reports
        $resPl = $this->withToken($this->cashier->api_token)->getJson('/api/reports/profit-loss');
        $resPl->assertStatus(403);

        // 5. Cashier cannot void sales
        $resVoid = $this->withToken($this->cashier->api_token)->postJson('/api/sales/999/void', [
            'reason' => 'Unauthorized attempt',
        ]);
        $resVoid->assertStatus(403);
    }

    public function test_cashier_is_allowed_to_view_products_and_create_sales(): void
    {
        Product::create([
            'name' => 'Coca Cola 330ml',
            'code' => 'COKE-330',
            'cost_price' => 500,
            'retail_price' => 1000,
            'stock_quantity' => 50,
            'category_name' => 'Beverages',
        ]);

        // Cashier can read products
        $resProducts = $this->withToken($this->cashier->api_token)->getJson('/api/products');
        $resProducts->assertStatus(200);
    }

    public function test_invalid_bearer_token_returns_401(): void
    {
        $res = $this->withToken('totally_invalid_nonexistent_token')->getJson('/api/users');
        $res->assertStatus(401)
            ->assertJsonPath('status', 'error');
    }

    public function test_privilege_escalation_prevention_on_users(): void
    {
        // Store Manager role with users.* permission
        $managerRole = Role::create([
            'name' => 'Store Manager',
            'slug' => 'store_manager',
            'permissions' => ['users.view', 'users.create', 'users.update', 'users.delete', 'roles.view', 'roles.update'],
            'is_default' => false,
            'status' => 'active',
        ]);

        $manager = User::create([
            'user_id' => 'USR-MGR-01',
            'name' => 'Store Manager',
            'email' => 'manager@retailpos.test',
            'role' => 'Store Manager',
            'role_id' => $managerRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'manager_token_123',
        ]);

        // 1. Non-super-admin cannot create a Super Admin user
        $createRes = $this->withToken($manager->api_token)->postJson('/api/users', [
            'name' => 'Hacker Admin',
            'email' => 'hacker@retailpos.test',
            'role' => 'Super Admin',
            'password' => 'secret123',
        ]);
        $createRes->assertStatus(403);

        // 2. Non-super-admin cannot modify the Super Admin account
        $updateRes = $this->withToken($manager->api_token)->putJson("/api/users/{$this->superAdmin->id}", [
            'name' => 'Tampered Admin',
        ]);
        $updateRes->assertStatus(403);

        // 3. Non-super-admin cannot elevate another user to Super Admin
        $elevateRes = $this->withToken($manager->api_token)->putJson("/api/users/{$this->cashier->id}", [
            'role' => 'Super Admin',
        ]);
        $elevateRes->assertStatus(403);
    }

    public function test_privilege_escalation_prevention_on_roles(): void
    {
        $superAdminRole = Role::create([
            'name' => 'Super Admin',
            'slug' => 'super_admin',
            'permissions' => ['*'],
            'is_default' => true,
            'status' => 'active',
        ]);

        $managerRole = Role::create([
            'name' => 'Store Manager',
            'slug' => 'store_manager',
            'permissions' => ['roles.view', 'roles.update', 'roles.delete'],
            'is_default' => false,
            'status' => 'active',
        ]);

        $manager = User::create([
            'user_id' => 'USR-MGR-02',
            'name' => 'Manager Two',
            'email' => 'mgr2@retailpos.test',
            'role' => 'Store Manager',
            'role_id' => $managerRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'manager_token_456',
        ]);

        // 1. Non-super-admin cannot modify the Super Admin role
        $updateRoleRes = $this->withToken($manager->api_token)->putJson("/api/roles/{$superAdminRole->id}", [
            'name' => 'Demoted Admin',
        ]);
        $updateRoleRes->assertStatus(403);

        // 2. Non-super-admin cannot delete the Super Admin role
        $deleteRoleRes = $this->withToken($manager->api_token)->deleteJson("/api/roles/{$superAdminRole->id}");
        $deleteRoleRes->assertStatus(403);
    }

    public function test_cost_price_masking_for_cashier_without_view_cost_permission(): void
    {
        Product::create([
            'name' => 'Confidential Cost Product',
            'code' => 'CONF-001',
            'cost_price' => 25000.0,
            'retail_price' => 50000.0,
            'stock_quantity' => 10,
            'category_name' => 'Confidential Items',
        ]);

        // 1. Cashier without products.view_cost gets null for cost_price and purchase_price
        $cashierRes = $this->withToken($this->cashier->api_token)->getJson('/api/products?search=CONF-001');
        $cashierRes->assertStatus(200);
        $products = $cashierRes->json('data');
        $this->assertNotEmpty($products);
        $this->assertNull($products[0]['cost_price']);
        $this->assertNull($products[0]['purchase_price']);
        $this->assertEquals(50000.0, (float)$products[0]['selling_price']);

        // 2. Super Admin can view the confidential cost_price
        $adminRes = $this->withToken($this->superAdmin->api_token)->getJson('/api/products?search=CONF-001');
        $adminRes->assertStatus(200);
        $adminProducts = $adminRes->json('data');
        $this->assertEquals(25000.0, (float)$adminProducts[0]['cost_price']);
        $this->assertEquals(25000.0, (float)$adminProducts[0]['purchase_price']);
    }

    public function test_blind_cash_reconciliation_hides_expected_cash_on_open_registers(): void
    {
        // Open a register
        $openRes = $this->withToken($this->cashier->api_token)->postJson('/api/cash-register/open', [
            'opening_cash' => 100000.0,
            'warehouse_id' => $this->outlet->id,
            'warehouse_name' => $this->outlet->name,
            'register_name' => 'POS Till 01',
            'user_id' => $this->cashier->id,
            'user_name' => $this->cashier->name,
        ]);
        $openRes->assertStatus(200);

        // 1. Cashier querying status on open register gets null for expected_cash (blind close enforcement)
        $cashierStatus = $this->withToken($this->cashier->api_token)->getJson('/api/cash-register/status');
        $cashierStatus->assertStatus(200);
        $this->assertNull($cashierStatus->json('register.expected_cash'));
        $this->assertNull($cashierStatus->json('register.difference'));

        // 2. Super Admin querying status on open register can see expected_cash
        $adminStatus = $this->withToken($this->superAdmin->api_token)->getJson('/api/cash-register/status?warehouse=' . urlencode($this->outlet->name) . '&user_name=' . urlencode($this->cashier->name));
        $adminStatus->assertStatus(200);
        $this->assertEquals(100000.0, (float)$adminStatus->json('register.expected_cash'));
    }
}