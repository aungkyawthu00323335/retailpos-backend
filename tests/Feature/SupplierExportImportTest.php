<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Supplier;

class SupplierExportImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Test downloading supplier sample CSV template with UTF-8 BOM.
     */
    public function test_download_supplier_template(): void
    {
        $response = $this->get('/api/suppliers/template');

        $response->assertStatus(200);
        $this->assertEquals('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('supplier_import_template_utf8.csv', $response->headers->get('Content-Disposition'));

        $content = $response->streamedContent();
        // Check UTF-8 BOM
        $this->assertTrue(str_starts_with($content, "\xEF\xBB\xBF"));
        // Check columns
        $this->assertStringContainsString('Supplier Name', $content);
        $this->assertStringContainsString('Contact Person', $content);
        $this->assertStringContainsString('Phone', $content);
        // Check sample rows
        $this->assertStringContainsString('Metro Prime Meats Ltd.', $content);
        $this->assertStringContainsString('Golden Valley Dairy Co.', $content);
    }

    /**
     * 2. Test exporting suppliers to CSV with UTF-8 BOM and search/filter.
     */
    public function test_export_suppliers(): void
    {
        Supplier::create([
            'name' => 'Alpha Technology Suppliers',
            'contact_person' => 'U Maung Maung',
            'phone' => '+95 9 111 222 333',
            'email' => 'alpha@tech.mm',
            'category' => 'Retail Electronics',
            'country' => 'Myanmar',
            'city' => 'Yangon',
            'address' => 'Pansoedan Street, Kyauktada, Yangon',
            'balance_due' => 1500.50,
            'payment_terms' => 'Net 30 Days',
            'status' => 'Active',
            'notes' => 'Hardware supplier',
        ]);

        Supplier::create([
            'name' => 'Beta Fashion Fabric Ltd.',
            'contact_person' => 'Daw Mya Mya',
            'phone' => '+95 9 444 555 666',
            'email' => 'beta@fabric.mm',
            'category' => 'Fashion & Footwear',
            'country' => 'Myanmar',
            'city' => 'Mandalay',
            'address' => '78th Street, Mandalay',
            'balance_due' => 0.00,
            'payment_terms' => 'Cash on Delivery',
            'status' => 'Active',
            'notes' => 'Textile supplies',
        ]);

        $response = $this->get('/api/suppliers/export?category=Retail Electronics');

        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertTrue(str_starts_with($content, "\xEF\xBB\xBF"));
        $this->assertStringContainsString('Alpha Technology Suppliers', $content);
        $this->assertStringNotContainsString('Beta Fashion Fabric Ltd.', $content);
    }

    /**
     * 3. Test bulk import of suppliers (new, update, and skip duplicates).
     */
    public function test_bulk_import_suppliers(): void
    {
        // Pre-create an existing supplier
        Supplier::create([
            'name' => 'Original Supplier',
            'phone' => '+95 9 123 456 789',
            'email' => 'original@test.com',
            'category' => 'Retail Electronics',
            'status' => 'Active',
        ]);

        // Scenario A: Update strategy
        $payload = [
            'duplicate_strategy' => 'update',
            'suppliers' => [
                [
                    'name' => 'Original Supplier Updated',
                    'phone' => '+95 9 123 456 789',
                    'email' => 'updated@test.com',
                    'category' => 'Gadgets & Peripherals',
                    'city' => 'Yangon',
                ],
                [
                    'name' => 'Brand New Supplier',
                    'phone' => '+95 9 999 888 777',
                    'email' => 'new@test.com',
                    'category' => 'Packaging & Bags',
                    'city' => 'Mandalay',
                ],
            ],
        ];

        $res = $this->postJson('/api/suppliers/bulk-import', $payload);

        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('summary.imported', 1)
            ->assertJsonPath('summary.updated', 1);

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Original Supplier Updated',
            'phone' => '+95 9 123 456 789',
            'email' => 'updated@test.com',
        ]);

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Brand New Supplier',
            'phone' => '+95 9 999 888 777',
        ]);

        // Scenario B: Skip strategy
        $skipPayload = [
            'duplicate_strategy' => 'skip',
            'suppliers' => [
                [
                    'name' => 'Should Be Skipped',
                    'phone' => '+95 9 999 888 777',
                    'email' => 'skip@test.com',
                ],
                [
                    'name' => 'Second New Supplier',
                    'phone' => '+95 9 777 666 555',
                ],
            ],
        ];

        $skipRes = $this->postJson('/api/suppliers/bulk-import', $skipPayload);

        $skipRes->assertStatus(200)
            ->assertJsonPath('summary.imported', 1)
            ->assertJsonPath('summary.skipped', 1);

        $this->assertDatabaseMissing('suppliers', [
            'name' => 'Should Be Skipped',
        ]);
    }
}
