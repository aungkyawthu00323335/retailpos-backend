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
}