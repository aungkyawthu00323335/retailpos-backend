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
        $this->cashier->outlets()->sync([$this->outlet->id]);
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

    public function test_get_available_permissions_catalog_returns_all_modules(): void
    {
        $res = $this->withToken($this->superAdmin->api_token)->getJson('/api/roles/permissions');
        $res->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $catalog = $res->json('data');
        $this->assertIsArray($catalog);
        $this->assertNotEmpty($catalog);

        // Verify key ERP modules are represented
        $moduleIds = array_column($catalog, 'id');
        $this->assertContains('products', $moduleIds);
        $this->assertContains('sales', $moduleIds);
        $this->assertContains('purchases', $moduleIds);
        $this->assertContains('adjustments', $moduleIds);
        $this->assertContains('transfers', $moduleIds);
        $this->assertNotContains('processings', $moduleIds);
        $this->assertContains('expenses', $moduleIds);
        $this->assertContains('customers', $moduleIds);
        $this->assertContains('suppliers', $moduleIds);
        $this->assertContains('roles', $moduleIds);
        $this->assertContains('dashboard', $moduleIds);
    }

    public function test_create_and_update_role_with_granular_module_permissions(): void
    {
        $permissions = [
            'products.view',
            'products.create',
            'sales.view',
            'sales.create',
            'customers.view',
        ];

        // 1. Create Role
        $createRes = $this->withToken($this->superAdmin->api_token)->postJson('/api/roles', [
            'name' => 'Junior Sales Associate',
            'description' => 'Store front sales associate with limited catalog access',
            'permissions' => $permissions,
            'status' => 'Active',
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.name', 'Junior Sales Associate');

        $roleId = $createRes->json('data.id');
        $this->assertNotNull($roleId);

        // 2. Verify stored permissions
        $role = Role::find($roleId);
        $this->assertEquals($permissions, $role->permissions);

        // 3. Update permissions to add more modules
        $updatedPermissions = array_merge($permissions, ['quotations.view', 'quotations.create']);
        $updateRes = $this->withToken($this->superAdmin->api_token)->putJson("/api/roles/{$roleId}", [
            'permissions' => $updatedPermissions,
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $role->refresh();
        $this->assertEquals($updatedPermissions, $role->permissions);
    }

    public function test_non_super_admin_cannot_grant_unpossessed_permissions(): void
    {
        // Manager only has 'users.view' and 'roles.create'
        $limitedRole = Role::create([
            'name' => 'Limited Role Admin',
            'slug' => 'limited_role_admin',
            'permissions' => ['roles.view', 'roles.create', 'products.view'],
            'is_default' => false,
            'status' => 'active',
        ]);

        $limitedUser = User::create([
            'user_id' => 'USR-LIM-01',
            'name' => 'Limited User',
            'email' => 'limited@retailpos.test',
            'role' => 'Limited Role Admin',
            'role_id' => $limitedRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'limited_user_token_abc123',
        ]);

        // Attempt to create a role with 'sales.delete' which limitedUser does NOT possess
        $res = $this->withToken($limitedUser->api_token)->postJson('/api/roles', [
            'name' => 'Privilege Escalation Role',
            'permissions' => ['products.view', 'sales.delete'],
        ]);

        $res->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertSee('Unauthorized. You cannot grant permission');
    }

    public function test_user_with_only_roles_view_cannot_delete_or_create_roles(): void
    {
        $viewerRole = Role::create([
            'name' => 'Auditor',
            'slug' => 'auditor',
            'permissions' => ['roles.view'],
            'is_default' => false,
            'status' => 'active',
        ]);
        $viewerUser = User::create([
            'user_id' => 'USR-AUD-01',
            'name' => 'Auditor User',
            'email' => 'auditor@retailpos.test',
            'role' => 'Auditor',
            'role_id' => $viewerRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'auditor_token_xyz',
        ]);
        $targetRole = Role::create([
            'name' => 'Deletable Role',
            'slug' => 'deletable_role',
            'permissions' => ['products.view'],
            'is_default' => false,
            'status' => 'active',
        ]);

        // 1. Cannot delete role
        $resDelete = $this->withToken($viewerUser->api_token)->deleteJson("/api/roles/{$targetRole->id}");
        $resDelete->assertStatus(403);

        // 2. Cannot create role
        $resCreate = $this->withToken($viewerUser->api_token)->postJson('/api/roles', [
            'name' => 'Rogue Role',
            'permissions' => ['roles.view'],
        ]);
        $resCreate->assertStatus(403);

        // 3. Cannot update role
        $resUpdate = $this->withToken($viewerUser->api_token)->putJson("/api/roles/{$targetRole->id}", [
            'name' => 'Renamed Role',
        ]);
        $resUpdate->assertStatus(403);
    }

    public function test_cannot_delete_role_assigned_to_active_users(): void
    {
        $customRole = Role::create([
            'name' => 'Assigned Custom Role',
            'slug' => 'assigned_custom_role',
            'permissions' => ['products.view'],
            'is_default' => false,
            'status' => 'active',
        ]);

        $assignedUser = User::create([
            'user_id' => 'USR-ASSIGN-01',
            'name' => 'Assigned User',
            'email' => 'assigned@retailpos.test',
            'role' => 'Assigned Custom Role',
            'role_id' => $customRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
        ]);

        // Super Admin attempts to delete role that still has an assigned user
        $res = $this->withToken($this->superAdmin->api_token)->deleteJson("/api/roles/{$customRole->id}");
        $res->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertSee('currently assigned');

        $this->assertDatabaseHas('roles', ['id' => $customRole->id]);
    }

    public function test_non_super_admin_cannot_spoof_super_admin_role_creation(): void
    {
        $creatorRole = Role::create([
            'name' => 'Role Creator',
            'slug' => 'role_creator',
            'permissions' => ['roles.create', 'roles.view'],
            'is_default' => false,
            'status' => 'active',
        ]);

        $creatorUser = User::create([
            'user_id' => 'USR-CREATE-01',
            'name' => 'Role Creator User',
            'email' => 'creator@retailpos.test',
            'role' => 'Role Creator',
            'role_id' => $creatorRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'creator_token_abc',
        ]);

        $res = $this->withToken($creatorUser->api_token)->postJson('/api/roles', [
            'name' => 'Super Admin',
            'permissions' => ['roles.view'],
        ]);

        $res->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertSee('Unauthorized. Cannot create role with name Super Admin.');
    }

    public function test_duplicate_role_name_returns_422(): void
    {
        $res = $this->withToken($this->superAdmin->api_token)->postJson('/api/roles', [
            'name' => 'Cashier',
            'permissions' => ['pos.view'],
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertSee('already exists');
    }

    public function test_view_only_report_user_cannot_view_confidential_profit_loss(): void
    {
        $reportViewerRole = Role::create([
            'name' => 'Basic Report Viewer',
            'slug' => 'basic_report_viewer',
            'permissions' => ['reports.view'],
            'is_default' => false,
            'status' => 'active',
        ]);

        $reportViewer = User::create([
            'user_id' => 'USR-REP-01',
            'name' => 'Report Viewer User',
            'email' => 'repview@retailpos.test',
            'role' => 'Basic Report Viewer',
            'role_id' => $reportViewerRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'report_viewer_token_777',
        ]);

        // Regular report view passes
        $resGeneral = $this->withToken($reportViewer->api_token)->getJson('/api/reports/dashboard-summary');
        $resGeneral->assertStatus(200);

        // Confidential Profit & Loss is strictly forbidden
        $resPl = $this->withToken($reportViewer->api_token)->getJson('/api/reports/profit-loss');
        $resPl->assertStatus(403);

        // Commercial Tax is strictly forbidden
        $resTax = $this->withToken($reportViewer->api_token)->getJson('/api/reports/commercial-tax');
        $resTax->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected_with_401_when_rbac_users_exist(): void
    {
        $this->getJson('/api/products')->assertStatus(401);
        $this->getJson('/api/sales')->assertStatus(401);
        $this->getJson('/api/settings')->assertStatus(401);
    }

    public function test_granular_crud_and_special_module_routes_enforce_rbac_across_all_modules(): void
    {
        // Cashier cannot access catalog mutations, inventory ops, procurement, accounting, settings, or bot ops
        $forbiddenForCashier = [
            ['GET', '/api/purchases'],
            ['POST', '/api/purchases'],
            ['GET', '/api/purchase-returns'],
            ['POST', '/api/adjustments'],
            ['GET', '/api/transfers'],
            ['GET', '/api/expenses'],
            ['GET', '/api/expense-categories'],
            ['POST', '/api/quotations'],
            ['GET', '/api/suppliers'],
            ['GET', '/api/supplier-categories'],
            ['POST', '/api/customer-groups'],
            ['POST', '/api/brands'],
            ['POST', '/api/product-categories'],
            ['POST', '/api/sub-categories'],
            ['POST', '/api/variations'],
            ['POST', '/api/product-units'],
            ['POST', '/api/currencies'],
            ['POST', '/api/payment-methods'],
            ['POST', '/api/outlets'],
            ['GET', '/api/reports/stock'],
            ['GET', '/api/reports/stock/export'],
            ['GET', '/api/reports/profit-loss'],
            ['GET', '/api/reports/commercial-tax'],
        ];

        foreach ($forbiddenForCashier as [$method, $uri]) {
            $res = $this->withToken($this->cashier->api_token)->json($method, $uri);
            $this->assertSame(
                403,
                $res->status(),
                "Expected 403 for Cashier calling {$method} {$uri}, got {$res->status()}."
            );
        }

        // Custom role with explicit special action permissions succeeds for its granted routes
        $specialistRole = Role::create([
            'name' => 'Transfer & Currency Officer',
            'slug' => 'transfer_currency_officer',
            'permissions' => [
                'transfers.view',
                'transfers.pay',
                'currencies.view',
                'outlets.view',
                'report_stock.view',
            ],
            'status' => 'active',
        ]);

        $specialist = User::create([
            'user_id' => 'USR-SPEC-01',
            'name' => 'Specialist Staff',
            'email' => 'specialist@retailpos.test',
            'role' => 'Transfer & Currency Officer',
            'role_id' => $specialistRole->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'specialist_test_token_999',
        ]);

        $this->withToken($specialist->api_token)->getJson('/api/transfers')->assertStatus(200);
        $this->withToken($specialist->api_token)->getJson('/api/currencies')->assertStatus(200);
        $this->withToken($specialist->api_token)->getJson('/api/outlets')->assertStatus(200);
        $this->withToken($specialist->api_token)->getJson('/api/reports/stock')->assertStatus(200);

        // Still blocked on ungranted write/delete/confidential actions
        $this->withToken($specialist->api_token)->postJson('/api/currencies', ['code' => 'MMK'])->assertStatus(403);
        $this->withToken($specialist->api_token)->getJson('/api/reports/stock/export')->assertStatus(403);
        $this->withToken($specialist->api_token)->getJson('/api/reports/profit-loss')->assertStatus(403);
    }

    public function test_user_permission_cache_respects_o1_aliases_and_live_role_updates(): void
    {
        $role = Role::create([
            'name' => 'Dynamic Tester',
            'slug' => 'dynamic_tester',
            'permissions' => ['outlet:view', 'report_sales.view', 'sale_return.update'],
            'status' => 'active',
        ]);

        $user = User::create([
            'user_id' => 'USR-DYN-01',
            'name' => 'Dynamic User',
            'email' => 'dynamic@retailpos.test',
            'role' => 'Dynamic Tester',
            'role_id' => $role->id,
            'status' => 'active',
            'password' => bcrypt('password123'),
            'api_token' => 'dynamic_token_123',
        ]);

        // Verify colon & singular/plural & report aliases work cleanly
        $this->assertTrue($user->hasPermission('outlets.view'));
        $this->assertTrue($user->hasPermission('reports.sales'));
        $this->assertTrue($user->hasPermission('sale_returns.update'));
        $this->assertFalse($user->hasPermission('transfers.pay'));

        // Update role permissions in DB and reload relation; cache must invalidate immediately
        $role->update(['permissions' => ['transfers.pay', 'pos.discount']]);
        $user->load('roleModel');

        $this->assertFalse($user->hasPermission('outlets.view'));
        $this->assertTrue($user->hasPermission('transfers.pay'));
        $this->assertTrue($user->hasPermission('pos.discount'));
    }

    public function test_role_permission_catalog_includes_all_granular_actions(): void
    {
        $res = $this->withToken($this->superAdmin->api_token)->getJson('/api/roles/permissions');
        $res->assertStatus(200);

        $flatKeys = [];
        foreach (($res->json('data') ?? []) as $item) {
            $id = $item['id'] ?? '';
            foreach (($item['actions'] ?? []) as $act) {
                $flatKeys[] = "{$id}.{$act}";
            }
            foreach (($item['special_actions'] ?? []) as $sp) {
                if (is_array($sp) && !empty($sp['key'])) {
                    $flatKeys[] = "{$id}.{$sp['key']}";
                }
            }
        }

        $this->assertNotEmpty($flatKeys);
        $this->assertContains('pos.discount', $flatKeys);
        $this->assertContains('transfers.pay', $flatKeys);
    }

    public function test_role_and_user_seeder_populates_complete_rbac_roles_with_sod(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);

        $manager = Role::where('slug', 'store_manager')->first();
        $accountant = Role::where('slug', 'accountant')->first();
        $inventory = Role::where('slug', 'inventory_clerk')->first();

        $this->assertNotNull($manager);
        $this->assertNotNull($accountant);
        $this->assertNotNull($inventory);

        $this->assertContains('pos.discount', $manager->permissions ?? []);
        $this->assertContains('transfers.pay', $accountant->permissions ?? []);
        $this->assertContains('sales.pay', $accountant->permissions ?? []);
        $this->assertNotContains('products.view_cost', $inventory->permissions ?? []);
        $this->assertNotContains('processings.view', $inventory->permissions ?? []);
    }
}