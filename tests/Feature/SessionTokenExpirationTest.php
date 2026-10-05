<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SessionTokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    private function createTestUser(): User
    {
        $role = Role::firstOrCreate(
            ['name' => 'Super Admin'],
            [
                'slug' => 'super-admin',
                'permissions' => ['*'],
            ]
        );

        return User::create([
            'user_id' => 'USR-TEST-001',
            'name' => 'Senior Cashier',
            'email' => 'cashier@retailpos.local',
            'password' => bcrypt('password123'),
            'role' => 'Super Admin',
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }

    public function test_login_issues_token_with_created_at_timestamp(): void
    {
        $user = $this->createTestUser();

        $response = $this->postJson('/api/login', [
            'email' => 'cashier@retailpos.local',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'token', 'user']);

        $user->refresh();
        $this->assertNotNull($user->api_token);
        $this->assertNotNull($user->api_token_created_at);
        $this->assertFalse($user->isApiTokenExpired());
    }

    public function test_token_within_24_hours_is_valid(): void
    {
        $user = $this->createTestUser();
        $user->api_token = 'valid_test_token_60_chars_abcdefghijklmnopqrstuvwxyz1234567890';
        $user->api_token_created_at = Carbon::now()->subHours(12); // 12 hours old
        $user->save();

        $this->assertFalse($user->isApiTokenExpired());

        $response = $this->withHeader('Authorization', 'Bearer ' . $user->api_token)
            ->getJson('/api/me');

        $response->assertStatus(200);
        $response->assertJsonPath('user.email', 'cashier@retailpos.local');
    }

    public function test_token_older_than_24_hours_expires_and_returns_401(): void
    {
        $user = $this->createTestUser();
        $token = 'expired_test_token_60_chars_abcdefghijklmnopqrstuvwxyz1234567890';
        $user->api_token = $token;
        $user->api_token_created_at = Carbon::now()->subHours(25); // 25 hours old (expired)
        $user->save();

        $this->assertTrue($user->isApiTokenExpired());

        // Calling /api/me should return 401 UNAUTHENTICATED
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/me');

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'error',
            'code' => 'UNAUTHENTICATED',
        ]);

        // Token in database must be revoked
        $user->refresh();
        $this->assertNull($user->api_token);
        $this->assertNull($user->api_token_created_at);
    }

    public function test_logout_revokes_token_and_timestamp(): void
    {
        $user = $this->createTestUser();
        $token = 'logout_test_token_60_chars_abcdefghijklmnopqrstuvwxyz1234567890';
        $user->api_token = $token;
        $user->api_token_created_at = Carbon::now();
        $user->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout');

        $response->assertStatus(200);

        $user->refresh();
        $this->assertNull($user->api_token);
        $this->assertNull($user->api_token_created_at);
    }
}
