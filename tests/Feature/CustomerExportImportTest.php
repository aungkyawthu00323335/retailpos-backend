<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Customer;
use App\Models\CustomerGroup;

class CustomerExportImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Test downloading customer sample CSV template with UTF-8 BOM.
     */
    public function test_download_customer_template(): void
    {
        $response = $this->get('/api/customers/template');

        $response->assertStatus(200);
        $this->assertEquals('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('customer_import_template_utf8.csv', $response->headers->get('Content-Disposition'));

        $content = $response->streamedContent();
        // Check UTF-8 BOM
        $this->assertTrue(str_starts_with($content, "\xEF\xBB\xBF"));
        // Check columns
        $this->assertStringContainsString('Customer Name', $content);
        $this->assertStringContainsString('Phone', $content);
        $this->assertStringContainsString('Customer Group', $content);
        // Check sample rows
        $this->assertStringContainsString('Mr. David Aung', $content);
        $this->assertStringContainsString('VIP Platinum', $content);
    }

    /**
     * 2. Test exporting customers to CSV with UTF-8 BOM and search/filter.
     */
    public function test_export_customers(): void
    {
        Customer::create([
            'name' => 'Daw Thida Win',
            'phone' => '+95 9 111 222 333',
            'email' => 'thida@retail.mm',
            'tier' => 'VIP Platinum',
            'reward_points' => 1200,
            'total_spent' => 4500.00,
            'total_visits' => 20,
            'status' => 'Active',
            'notes' => 'VIP Gold regular',
        ]);

        Customer::create([
            'name' => 'Ko Kyaw Swar',
            'phone' => '+95 9 444 555 666',
            'email' => 'kyaw@buyer.mm',
            'tier' => 'Bronze Club',
            'reward_points' => 50,
            'total_spent' => 120.00,
            'total_visits' => 2,
            'status' => 'Active',
            'notes' => 'Bronze occasional buyer',
        ]);

        $response = $this->get('/api/customers/export?tier=VIP Platinum');

        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertTrue(str_starts_with($content, "\xEF\xBB\xBF"));
        $this->assertStringContainsString('Daw Thida Win', $content);
        $this->assertStringContainsString('VIP Platinum', $content);
        $this->assertStringNotContainsString('Ko Kyaw Swar', $content);
    }

    /**
     * 3. Test bulk import of customers (new, update, and skip duplicates).
     */
    public function test_bulk_import_customers(): void
    {
        // Pre-create an existing customer
        Customer::create([
            'name' => 'Original Customer',
            'phone' => '+95 9 123 456 789',
            'email' => 'original@test.com',
            'tier' => 'Silver Regular',
            'status' => 'Active',
        ]);

        // Scenario A: Update strategy
        $payload = [
            'duplicate_strategy' => 'update',
            'customers' => [
                [
                    'name' => 'Original Customer Updated',
                    'phone' => '+95 9 123 456 789',
                    'email' => 'updated@test.com',
                    'tier' => 'Gold VIP',
                    'reward_points' => 300,
                    'status' => 'Active',
                ],
                [
                    'name' => 'Brand New Customer',
                    'phone' => '+95 9 999 888 777',
                    'email' => 'new@test.com',
                    'tier' => 'VIP Platinum',
                    'reward_points' => 500,
                    'status' => 'Active',
                ],
            ],
        ];

        $response = $this->postJson('/api/customers/bulk-import', $payload);
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'summary' => [
                'imported' => 1,
                'updated' => 1,
                'skipped' => 0,
            ],
        ]);

        $this->assertDatabaseHas('customers', [
            'phone' => '+95 9 123 456 789',
            'name' => 'Original Customer Updated',
            'tier' => 'Gold VIP',
        ]);

        $this->assertDatabaseHas('customers', [
            'phone' => '+95 9 999 888 777',
            'name' => 'Brand New Customer',
            'tier' => 'VIP Platinum',
        ]);

        // Automatically created CustomerGroup
        $this->assertDatabaseHas('customer_groups', [
            'name' => 'VIP Platinum',
        ]);

        // Scenario B: Skip strategy
        $skipPayload = [
            'duplicate_strategy' => 'skip',
            'customers' => [
                [
                    'name' => 'Should Be Skipped',
                    'phone' => '+95 9 999 888 777',
                    'email' => 'skip@test.com',
                    'tier' => 'Silver Regular',
                ],
            ],
        ];

        $skipRes = $this->postJson('/api/customers/bulk-import', $skipPayload);
        $skipRes->assertStatus(200);
        $skipRes->assertJson([
            'status' => 'success',
            'summary' => [
                'imported' => 0,
                'updated' => 0,
                'skipped' => 1,
            ],
        ]);
    }
}
