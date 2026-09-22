<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductExcelImportExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper to create a temporary .xlsx UploadedFile from an array of rows.
     */
    protected function createExcelFile(array $rows, string $filename = 'test.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');

        $tempPath = tempnam(sys_get_temp_dir(), 'excel_test_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return new UploadedFile($tempPath, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * 1. Test downloading Excel templates.
     */
    public function test_download_excel_templates(): void
    {
        // Import template
        $response = $this->get('/api/products/excel-template?type=import');
        $response->assertStatus(200);
        $this->assertStringContainsString('product_import_sample_template.xlsx', $response->headers->get('Content-Disposition'));

        // Price change template
        $response2 = $this->get('/api/products/excel-template?type=price_change');
        $response2->assertStatus(200);
        $this->assertStringContainsString('product_price_change_template.xlsx', $response2->headers->get('Content-Disposition'));
    }

    /**
     * 2. Test importing new products successfully.
     */
    public function test_import_new_products_success(): void
    {
        $rows = [
            ['Product Type', 'Product Code', 'Product Name', 'Alternative Name', 'Category', 'Brand', 'Base Unit', 'Purchase Unit', 'Sale Unit', 'Purchase Unit Links', 'Sale Unit Links', 'Product Cost', 'Retail Price', 'Wholesale Price', 'Stock Alert', 'Stock Qty', 'Status'],
            ['Single', 'P-1001', 'Coca Cola 330ml', 'ကိုကာကိုလာ', 'Beverages', 'Coca-Cola', 'Can', 'Pack', 'Can', 'Pack:24', '', '800', '1200', '1000', '20', '100', 'Active'],
            ['Single', 'P-1002', 'Sprite 330ml', 'စပရိုက်', 'Beverages', 'Coca-Cola', 'Can', 'Pack', 'Can', 'Pack:24', '', '750', '1100', '950', '15', '50', 'Active'],
        ];

        $file = $this->createExcelFile($rows, 'products_import.xlsx');

        $response = $this->post('/api/products/excel-import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'imported_count' => 2,
        ]);

        $this->assertDatabaseHas('products', [
            'code' => 'P-1001',
            'name' => 'Coca Cola 330ml',
            'retail_price' => 1200,
            'cost_price' => 800,
        ]);

        $this->assertDatabaseHas('products', [
            'code' => 'P-1002',
            'name' => 'Sprite 330ml',
            'retail_price' => 1100,
            'cost_price' => 750,
        ]);
    }

    /**
     * 3. Test in-file duplicate product code rejection (Strict All-or-Nothing: 0 imported).
     */
    public function test_import_fails_on_in_file_duplicate_code(): void
    {
        $rows = [
            ['Product Type', 'Product Code', 'Product Name', 'Category', 'Base Unit', 'Product Cost', 'Retail Price'],
            ['Single', 'DUP-001', 'Product Alpha', 'Beverages', 'Piece', '100', '150'],
            ['Single', 'DUP-001', 'Product Alpha Duplicate', 'Beverages', 'Piece', '100', '150'],
        ];

        $file = $this->createExcelFile($rows);

        $response = $this->post('/api/products/excel-import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
        ]);
        $response->assertJsonFragment([
            'field' => 'Product Code',
        ]);

        // Zero products should be in DB
        $this->assertEquals(0, Product::count());
    }

    /**
     * 4. Test database duplicate product code rejection (Strict All-or-Nothing: 0 imported).
     */
    public function test_import_fails_on_existing_db_code(): void
    {
        // Existing product in DB
        Product::create([
            'code' => 'EXIST-999',
            'sku' => 'EXIST-999',
            'name' => 'Already Existing Product',
            'cost_price' => 500,
            'purchase_price' => 500,
            'retail_price' => 700,
            'selling_price' => 700,
            'unit_name' => 'Piece',
            'category_name' => 'General',
            'is_active' => true,
        ]);

        $rows = [
            ['Product Type', 'Product Code', 'Product Name', 'Category', 'Base Unit', 'Product Cost', 'Retail Price'],
            ['Single', 'NEW-001', 'Fresh Product 1', 'Beverages', 'Piece', '100', '150'],
            ['Single', 'EXIST-999', 'Clashing Product', 'Beverages', 'Piece', '200', '250'],
            ['Single', 'NEW-002', 'Fresh Product 2', 'Beverages', 'Piece', '300', '350'],
        ];

        $file = $this->createExcelFile($rows);

        $response = $this->post('/api/products/excel-import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'code' => 'EXIST-999',
            'field' => 'Product Code',
        ]);

        // Only the initial product should exist, none of the 3 in the Excel
        $this->assertEquals(1, Product::count());
        $this->assertDatabaseMissing('products', ['code' => 'NEW-001']);
        $this->assertDatabaseMissing('products', ['code' => 'NEW-002']);
    }

    /**
     * 5. Test missing fields and invalid negative price (All-or-Nothing atomicity).
     */
    public function test_import_fails_on_missing_fields_or_invalid_values(): void
    {
        $rows = [
            ['Product Type', 'Product Code', 'Product Name', 'Category', 'Base Unit', 'Product Cost', 'Retail Price'],
            ['Single', 'INV-001', '', 'Beverages', 'Piece', '100', '150'], // Missing name
            ['Single', 'INV-002', 'Product Two', 'Beverages', 'Piece', '-50', '150'], // Negative cost
        ];

        $file = $this->createExcelFile($rows);

        $response = $this->post('/api/products/excel-import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $this->assertEquals(0, Product::count());
    }

    /**
     * 6. Test bulk price & cost changes via Excel.
     */
    public function test_bulk_price_change_success(): void
    {
        $p1 = Product::create([
            'code' => 'PRICE-101',
            'sku' => 'PRICE-101',
            'name' => 'Original Product 1',
            'cost_price' => 1000,
            'purchase_price' => 1000,
            'retail_price' => 1500,
            'selling_price' => 1500,
            'wholesale_price' => 1300,
            'unit_name' => 'Piece',
            'category_name' => 'General',
            'is_active' => true,
        ]);

        $p2 = Product::create([
            'code' => 'PRICE-102',
            'sku' => 'PRICE-102',
            'name' => 'Original Product 2',
            'cost_price' => 2000,
            'purchase_price' => 2000,
            'retail_price' => 2800,
            'selling_price' => 2800,
            'wholesale_price' => 2500,
            'unit_name' => 'Piece',
            'category_name' => 'General',
            'is_active' => true,
        ]);

        $rows = [
            ['Product Code', 'Product Name', 'Product Cost', 'Retail Price', 'Wholesale Price'],
            ['PRICE-101', 'Original Product 1', '1200', '1800', '1600'],
            ['PRICE-102', 'Original Product 2', '2200', '3100', '2900'],
        ];

        $file = $this->createExcelFile($rows, 'price_update.xlsx');

        $response = $this->post('/api/products/price-change-import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'updated_count' => 2,
        ]);

        $p1->refresh();
        $this->assertEquals(1200.0, (float)$p1->cost_price);
        $this->assertEquals(1800.0, (float)$p1->retail_price);
        $this->assertEquals(1600.0, (float)$p1->wholesale_price);

        $p2->refresh();
        $this->assertEquals(2200.0, (float)$p2->cost_price);
        $this->assertEquals(3100.0, (float)$p2->retail_price);
        $this->assertEquals(2900.0, (float)$p2->wholesale_price);
    }

    /**
     * 7. Test bulk price change fails if product code does not exist.
     */
    public function test_bulk_price_change_fails_on_nonexistent_product(): void
    {
        $rows = [
            ['Product Code', 'Product Name', 'Product Cost', 'Retail Price', 'Wholesale Price'],
            ['GHOST-999', 'Ghost Product', '1000', '1500', '1300'],
        ];

        $file = $this->createExcelFile($rows);

        $response = $this->post('/api/products/price-change-import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'code' => 'GHOST-999',
            'field' => 'Product Code',
        ]);
    }

    /**
     * 8. Test product export to genuine .xlsx.
     */
    public function test_export_products_excel(): void
    {
        Product::create([
            'code' => 'EXP-001',
            'sku' => 'EXP-001',
            'name' => 'Export Product',
            'cost_price' => 500,
            'purchase_price' => 500,
            'retail_price' => 800,
            'selling_price' => 800,
            'wholesale_price' => 700,
            'unit_name' => 'Piece',
            'category_name' => 'General',
            'is_active' => true,
        ]);

        $response = $this->get('/api/products/excel-export');

        $response->assertStatus(200);
        $this->assertEquals(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        $this->assertStringContainsString('products_export_', $response->headers->get('Content-Disposition'));
    }
}
