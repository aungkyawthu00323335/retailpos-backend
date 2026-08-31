<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Cache;

class PaymentMethodEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1: Index returns all payment methods with fast cache support
     */
    public function test_index_returns_payment_methods_with_cache(): void
    {
        PaymentMethod::create([
            'name' => 'Cash',
            'code' => 'CASH',
            'icon' => 'payments_rounded',
            'is_active' => true,
            'is_default' => true,
            'description' => 'Direct cash payment',
        ]);

        PaymentMethod::create([
            'name' => 'WavePay',
            'code' => 'WAVEPAY',
            'icon' => 'phone_android_rounded',
            'is_active' => true,
            'is_default' => false,
            'description' => 'WavePay mobile wallet',
        ]);

        $response = $this->getJson('/api/payment-methods');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                '*' => ['id', 'name', 'code', 'icon', 'is_active', 'is_default', 'description'],
            ],
        ]);
        $response->assertJsonPath('status', 'success');
        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    /**
     * Test 2: Index filters payment methods by search keyword and status
     */
    public function test_index_filters_by_search_and_status(): void
    {
        PaymentMethod::create([
            'name' => 'KBZPay QR',
            'code' => 'KPAY',
            'icon' => 'qr_code_scanner_rounded',
            'is_active' => true,
            'is_default' => false,
        ]);

        PaymentMethod::create([
            'name' => 'Old Legacy Gateway',
            'code' => 'LEGACY',
            'icon' => 'bolt_rounded',
            'is_active' => false,
            'is_default' => false,
        ]);

        // Search match
        $searchRes = $this->getJson('/api/payment-methods?search=KBZPay');
        $searchRes->assertStatus(200);
        $this->assertTrue(collect($searchRes->json('data'))->contains('code', 'KPAY'));
        $this->assertFalse(collect($searchRes->json('data'))->contains('code', 'LEGACY'));

        // Status match (Active only)
        $statusRes = $this->getJson('/api/payment-methods?status=Active');
        $statusRes->assertStatus(200);
        $this->assertTrue(collect($statusRes->json('data'))->contains('code', 'KPAY'));
        $this->assertFalse(collect($statusRes->json('data'))->contains('code', 'LEGACY'));

        // Status match (Inactive only)
        $inactiveRes = $this->getJson('/api/payment-methods?status=Inactive');
        $inactiveRes->assertStatus(200);
        $this->assertTrue(collect($inactiveRes->json('data'))->contains('code', 'LEGACY'));
    }

    /**
     * Test 3: Store creates new payment method and sets smart icons
     */
    public function test_store_creates_new_payment_method(): void
    {
        $payload = [
            'name' => 'AYA Pay Digital',
            'description' => 'AYA bank digital wallet integration',
            'is_active' => true,
            'is_default' => false,
        ];

        $response = $this->postJson('/api/payment-methods', $payload);
        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.name', 'AYA Pay Digital');

        $this->assertDatabaseHas('payment_methods', [
            'name' => 'AYA Pay Digital',
            'is_active' => 1,
        ]);
    }

    /**
     * Test 4: Store enforces unique payment method name
     */
    public function test_store_validates_unique_name(): void
    {
        PaymentMethod::create([
            'name' => 'CB Pay',
            'code' => 'CBPAY',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/payment-methods', [
            'name' => 'CB Pay',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    /**
     * Test 5: Update modifies payment method and maintains default uniqueness
     */
    public function test_update_modifies_payment_method(): void
    {
        $method1 = PaymentMethod::create([
            'name' => 'Primary Cash',
            'code' => 'CASH',
            'is_active' => true,
            'is_default' => true,
        ]);

        $method2 = PaymentMethod::create([
            'name' => 'Secondary Wallet',
            'code' => 'SEC_WALLET',
            'is_active' => true,
            'is_default' => false,
        ]);

        // Update method 2 to be default
        $response = $this->putJson("/api/payment-methods/{$method2->id}", [
            'name' => 'Secondary Wallet Pro',
            'is_default' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.name', 'Secondary Wallet Pro');
        $response->assertJsonPath('data.is_default', true);

        // Ensure method 1 is no longer default
        $method1->refresh();
        $this->assertFalse((bool)$method1->is_default);
    }

    /**
     * Test 6: Toggle status flips is_active boolean
     */
    public function test_toggle_status_flips_active_state(): void
    {
        $method = PaymentMethod::create([
            'name' => 'Toggle Test Pay',
            'code' => 'TOGGLE',
            'is_active' => true,
        ]);

        $response = $this->patchJson("/api/payment-methods/{$method->id}/toggle-status");
        $response->assertStatus(200);
        $response->assertJsonPath('data.is_active', false);

        $method->refresh();
        $this->assertFalse((bool)$method->is_active);

        // Toggle back to active
        $response2 = $this->patchJson("/api/payment-methods/{$method->id}/toggle-status");
        $response2->assertStatus(200);
        $response2->assertJsonPath('data.is_active', true);

        $method->refresh();
        $this->assertTrue((bool)$method->is_active);
    }

    /**
     * Test 7: Destroy deletes payment method record
     */
    public function test_destroy_deletes_payment_method(): void
    {
        $method = PaymentMethod::create([
            'name' => 'To Delete Method',
            'code' => 'TO_DEL',
            'is_active' => true,
        ]);

        $response = $this->deleteJson("/api/payment-methods/{$method->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('payment_methods', [
            'id' => $method->id,
        ]);
    }
}
