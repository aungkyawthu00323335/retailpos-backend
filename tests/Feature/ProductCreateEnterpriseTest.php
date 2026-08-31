<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductCategory;
use App\Models\Brand;
use App\Models\ProductUnit;
use App\Models\Variation;
use App\Models\Outlet;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\ProductUnitLink;

class ProductCreateEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed basic fixtures
        ProductCategory::create(['name' => 'Footwear & Shoes']);
        Brand::create(['name' => 'Nike']);
        ProductUnit::create(['name' => 'Pair']);
        Variation::create(['name' => 'Size', 'variation_types' => ['40', '41', '42']]);
        Outlet::create(['name' => 'Main Warehouse', 'code' => 'WH-01', 'is_active' => true]);
    }

    /**
     * 1. Create Options Endpoint Test
     */
    public function test_create_options_endpoint_returns_expected_structure(): void
    {
        $res = $this->getJson('/api/products/create-options');
        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'categories',
                    'brands',
                    'units',
                    'variation_attributes',
                    'warehouses',
                    'barcode_symbologies',
                    'permissions',
                    'config',
                ],
            ]);
    }

    /**
     * 2. Check Identifier & Auto-Generation Endpoints
     */
    public function test_sku_and_barcode_generation_and_check(): void
    {
        // Generate SKU
        $skuRes = $this->postJson('/api/products/generate-sku');
        $skuRes->assertStatus(200)
            ->assertJsonPath('success', true);
        $sku = $skuRes->json('data.sku');
        $this->assertNotEmpty($sku);

        // Check availability (should be available)
        $checkRes = $this->getJson("/api/products/check-identifier?sku={$sku}");
        $checkRes->assertStatus(200)
            ->assertJsonPath('data.available', true);

        // Generate Barcode
        $bcRes = $this->postJson('/api/products/generate-barcode');
        $bcRes->assertStatus(200)
            ->assertJsonPath('success', true);
        $this->assertNotEmpty($bcRes->json('data.barcode'));
    }

    /**
     * 3. Simple Product Creation with Opening Stock
     */
    public function test_create_simple_product_with_opening_stock_and_audit(): void
    {
        $outlet = Outlet::first();

        $payload = [
            'name' => 'Nike Pegasus Trail 4',
            'sku' => 'NK-PEG-04',
            'barcode' => '8934567890111',
            'barcode_symbology' => 'CODE128',
            'category_name' => 'Footwear & Shoes',
            'brand_name' => 'Nike',
            'unit_name' => 'Pair',
            'cost_price' => 75.00,
            'selling_price' => 140.00,
            'stock_alert' => 5.0,
            'has_variants' => false,
            'opening_stocks' => [
                [
                    'warehouse_id' => $outlet->id,
                    'quantity' => 25.0,
                ],
            ],
            'note' => 'Trail running shoes',
            'is_active' => true,
        ];

        $res = $this->postJson('/api/products', $payload);
        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Nike Pegasus Trail 4')
            ->assertJsonPath('data.sku', 'NK-PEG-04');

        $this->assertEquals(75.00, (float)$res->json('data.cost_price'));
        $this->assertEquals(140.00, (float)$res->json('data.selling_price'));
        $this->assertEquals(25.0, (float)$res->json('data.stock_quantity'));

        $prodId = $res->json('data.id');

        // Verify StockBalance was created
        $balance = StockBalance::where('product_id', $prodId)->where('warehouse_id', $outlet->id)->first();
        $this->assertNotNull($balance);
        $this->assertEquals(25.0, (float)$balance->quantity);

        // Verify StockMovement audit was recorded
        $movement = StockMovement::where('product_id', $prodId)->where('movement_type', 'opening_stock')->first();
        $this->assertNotNull($movement);
        $this->assertEquals(25.0, (float)$movement->quantity_in);
        $this->assertEquals('product_create', $movement->reference_type);

        // Verify Response strictly does NOT expose tax or expiry keys
        $responseData = $res->json('data');
        $this->assertArrayNotHasKey('tax', $responseData);
        $this->assertArrayNotHasKey('tax_rate', $responseData);
        $this->assertArrayNotHasKey('tax_method', $responseData);
        $this->assertArrayNotHasKey('tax_type', $responseData);
        $this->assertArrayNotHasKey('TaxNet', $responseData);
        $this->assertArrayNotHasKey('expire_date', $responseData);
        $this->assertArrayNotHasKey('expiry_date', $responseData);
        $this->assertArrayNotHasKey('expired_at', $responseData);
    }

    /**
     * 4. Variable Product Creation with Variants & Stock
     */
    public function test_create_variable_product_with_variants(): void
    {
        $outlet = Outlet::first();

        $payload = [
            'name' => 'Nike Air Force 1 Multi-Size',
            'sku' => 'NK-AF1-BASE',
            'category_name' => 'Footwear & Shoes',
            'brand_name' => 'Nike',
            'unit_name' => 'Pair',
            'cost_price' => 60.00,
            'selling_price' => 110.00,
            'has_variants' => true,
            'variants' => [
                [
                    'name' => 'Size 40',
                    'sku' => 'NK-AF1-40',
                    'barcode' => '893456789040',
                    'cost_price' => 60.00,
                    'selling_price' => 110.00,
                    'is_active' => true,
                    'attribute_values' => [
                        ['attribute_name' => 'Size', 'attribute_value' => '40'],
                    ],
                ],
                [
                    'name' => 'Size 41',
                    'sku' => 'NK-AF1-41',
                    'barcode' => '893456789041',
                    'cost_price' => 60.00,
                    'selling_price' => 110.00,
                    'is_active' => true,
                    'attribute_values' => [
                        ['attribute_name' => 'Size', 'attribute_value' => '41'],
                    ],
                ],
            ],
            'opening_stocks' => [
                [
                    'warehouse_id' => $outlet->id,
                    'variant_sku' => 'NK-AF1-40',
                    'quantity' => 10.0,
                ],
                [
                    'warehouse_id' => $outlet->id,
                    'variant_sku' => 'NK-AF1-41',
                    'quantity' => 15.0,
                ],
            ],
        ];

        $res = $this->postJson('/api/products', $payload);
        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_variants', true)
            ->assertJsonPath('data.variants_count', 2);

        $this->assertEquals(25.0, (float)$res->json('data.stock_quantity'));

        $prodId = $res->json('data.id');

        // Check variant database records
        $variants = ProductVariant::where('product_id', $prodId)->get();
        $this->assertCount(2, $variants);
    }

    /**
     * 5. Hard Exclusion Rejection Tests (Tax & Expiry)
     */
    public function test_rejects_tax_and_expiry_fields(): void
    {
        // 5a. Tax field rejection
        $taxRes = $this->postJson('/api/products', [
            'name' => 'Invalid Tax Product',
            'tax' => 5.0,
        ]);
        $taxRes->assertStatus(422)
            ->assertJsonValidationErrors(['tax']);

        // 5b. Tax Rate field rejection
        $taxRateRes = $this->postJson('/api/products', [
            'name' => 'Invalid Tax Rate Product',
            'tax_rate' => 10.0,
        ]);
        $taxRateRes->assertStatus(422)
            ->assertJsonValidationErrors(['tax_rate']);

        // 5c. Tax Type field rejection
        $taxTypeRes = $this->postJson('/api/products', [
            'name' => 'Invalid Tax Type Product',
            'tax_type' => 'Inclusive',
        ]);
        $taxTypeRes->assertStatus(422)
            ->assertJsonValidationErrors(['tax_type']);

        // 5d. Expire Date field rejection
        $expRes = $this->postJson('/api/products', [
            'name' => 'Invalid Expire Product',
            'expire_date' => '2026-12-31',
        ]);
        $expRes->assertStatus(422)
            ->assertJsonValidationErrors(['expire_date']);

        // 5e. Expiry Date field rejection
        $expiryRes = $this->postJson('/api/products', [
            'name' => 'Invalid Expiry Product',
            'expiry_date' => '2026-12-31',
        ]);
        $expiryRes->assertStatus(422)
            ->assertJsonValidationErrors(['expiry_date']);
    }

    /**
     * 6. Duplicate SKU Validation Test
     */
    public function test_duplicate_sku_rejection(): void
    {
        Product::create([
            'name' => 'Existing Product',
            'code' => 'SKU-EXIST-01',
            'sku' => 'SKU-EXIST-01',
        ]);

        $res = $this->postJson('/api/products', [
            'name' => 'New Product with Duplicate SKU',
            'sku' => 'SKU-EXIST-01',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['sku']);
    }

    /**
     * 7. Product unit links normalize purchase and sale stock quantities.
     */
    public function test_product_unit_links_normalize_purchase_and_sale_stock(): void
    {
        $outlet = Outlet::first();
        $gram = ProductUnit::create(['name' => 'Gram', 'status' => 'Active']);

        $create = $this->postJson('/api/products', [
            'name' => 'Loose Rice',
            'sku' => 'RICE-UNIT-01',
            'unit_name' => 'Gram',
            'base_unit_id' => $gram->id,
            'purchase_unit' => 'Kg',
            'sale_unit' => 'Gram',
            'cost_price' => 10000,
            'selling_price' => 12,
            'unit_links' => [
                [
                    'unit_type' => 'purchase',
                    'unit_name' => 'Kg',
                    'conversion_rate' => 1000,
                    'is_default' => true,
                ],
                [
                    'unit_type' => 'purchase',
                    'unit_name' => 'Sack',
                    'conversion_rate' => 50000,
                    'is_default' => false,
                ],
                [
                    'unit_type' => 'sale',
                    'unit_name' => 'Gram',
                    'conversion_rate' => 1,
                    'is_default' => true,
                ],
            ],
            'opening_stocks' => [
                ['warehouse_id' => $outlet->id, 'quantity' => 10],
            ],
        ]);

        $create->assertStatus(201)
            ->assertJsonPath('data.unit_links.0.unit_name', 'Kg');
        $this->assertCount(3, ProductUnitLink::where('product_id', $create->json('data.id'))->get());

        $productId = $create->json('data.id');
        $purchase = $this->postJson('/api/purchases', [
            'supplier_name' => 'Rice Supplier',
            'warehouse_name' => $outlet->name,
            'date' => '2026-08-26 10:00:00',
            'status' => 'Received',
            'grand_total' => 20000,
            'paid_amount' => 20000,
            'items' => [[
                'product_id' => $productId,
                'product_code' => 'RICE-UNIT-01',
                'product_name' => 'Loose Rice',
                'unit_id' => null,
                'unit_name' => 'Kg',
                'unit_cost' => 10000,
                'quantity' => 2,
                'subtotal' => 20000,
            ]],
        ]);

        $purchase->assertStatus(201)
            ->assertJsonPath('data.items.0.unit_name', 'Kg');
        $this->assertEquals(2000.0, (float)$purchase->json('data.items.0.base_quantity'));

        $sale = $this->postJson('/api/sales', [
            'customer_name' => 'Walk-in Customer',
            'warehouse_name' => $outlet->name,
            'date' => '2026-08-26 11:00:00',
            'status' => 'Completed',
            'subtotal' => 5000,
            'grand_total' => 5000,
            'paid_amount' => 5000,
            'items' => [[
                'product_id' => $productId,
                'product_code' => 'RICE-UNIT-01',
                'product_name' => 'Loose Rice',
                'unit_name' => 'Gram',
                'unit_price' => 10,
                'quantity' => 500,
                'subtotal' => 5000,
            ]],
        ]);

        $sale->assertStatus(201)
            ->assertJsonPath('data.items.0.unit_name', 'Gram');
        $this->assertEquals(500.0, (float)$sale->json('data.items.0.base_quantity'));

        $balance = StockBalance::where('product_id', $productId)->where('warehouse_id', $outlet->id)->first();
        $this->assertNotNull($balance);
        $this->assertEquals(1510.0, (float)$balance->quantity);
    }

    /**
     * 8. Show / Read Product Details (Single & Variable)
     */
    public function test_show_single_and_variable_product_details(): void
    {
        $outlet = Outlet::first();

        // 8a. Create Single Product
        $createRes = $this->postJson('/api/products', [
            'name' => 'Details Test Single Product',
            'code' => '10050',
            'sku' => '10050',
            'category_name' => 'Footwear & Shoes',
            'brand_name' => 'Nike',
            'unit_name' => 'Pair',
            'cost_price' => 50.00,
            'selling_price' => 99.00,
            'has_variants' => false,
        ]);
        $createRes->assertStatus(201);
        $singleId = $createRes->json('data.id');

        // Fetch Single Product Details
        $showSingle = $this->getJson("/api/products/{$singleId}");
        $showSingle->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $singleId)
            ->assertJsonPath('data.code', '10050')
            ->assertJsonPath('data.has_variants', false);

        // 8b. Create Variable Product with 2 Variants
        $varRes = $this->postJson('/api/products', [
            'name' => 'Details Test Variable Product',
            'code' => '10060',
            'sku' => '10060',
            'category_name' => 'Footwear & Shoes',
            'brand_name' => 'Nike',
            'unit_name' => 'Pair',
            'cost_price' => 60.00,
            'selling_price' => 120.00,
            'has_variants' => true,
            'variants' => [
                [
                    'name' => 'Red',
                    'sku' => '1006001',
                    'cost_price' => 60.00,
                    'selling_price' => 120.00,
                    'is_active' => true,
                    'attribute_values' => [
                        ['attribute_name' => 'Color', 'attribute_value' => 'Red'],
                    ],
                ],
                [
                    'name' => 'Blue',
                    'sku' => '1006002',
                    'cost_price' => 60.00,
                    'selling_price' => 120.00,
                    'is_active' => true,
                    'attribute_values' => [
                        ['attribute_name' => 'Color', 'attribute_value' => 'Blue'],
                    ],
                ],
            ],
        ]);
        $varRes->assertStatus(201);
        $varId = $varRes->json('data.id');

        // Fetch Variable Product Details
        $showVar = $this->getJson("/api/products/{$varId}");
        $showVar->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $varId)
            ->assertJsonPath('data.has_variants', true)
            ->assertJsonPath('data.variants_count', 2);
    }

    /**
     * 9. Update Single Product (Prices, Stock Alert, Name)
     */
    public function test_update_single_product_pricing_and_attributes(): void
    {
        $createRes = $this->postJson('/api/products', [
            'name' => 'Original Product Name',
            'code' => '10070',
            'sku' => '10070',
            'category_name' => 'Footwear & Shoes',
            'cost_price' => 40.00,
            'selling_price' => 80.00,
            'has_variants' => false,
        ]);
        $prodId = $createRes->json('data.id');

        $updateRes = $this->putJson("/api/products/{$prodId}", [
            'name' => 'Updated Product Name',
            'code' => '10070',
            'sku' => '10070',
            'cost_price' => 45.00,
            'selling_price' => 95.00,
            'retail_prices' => [95.00, 90.00, 85.00],
            'wholesale_prices' => [80.00, 75.00],
            'stock_alert' => 12.0,
            'status' => 'Active',
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Updated Product Name');

        $this->assertEquals(45.00, (float)$updateRes->json('data.cost_price'));
        $this->assertEquals(95.00, (float)$updateRes->json('data.selling_price'));
    }

    /**
     * 10. Delete Product (Soft Delete & List Removal)
     */
    public function test_delete_product_removes_from_active_list(): void
    {
        $createRes = $this->postJson('/api/products', [
            'name' => 'Product To Be Deleted',
            'code' => '10080',
            'sku' => '10080',
            'has_variants' => false,
        ]);
        $prodId = $createRes->json('data.id');

        $deleteRes = $this->deleteJson("/api/products/{$prodId}");
        $deleteRes->assertStatus(200)
            ->assertJsonPath('success', true);

        // Product should be soft-deleted
        $this->assertSoftDeleted('products', ['id' => $prodId]);

        // List endpoint should no longer return it
        $listRes = $this->getJson('/api/products?search=10080');
        $listRes->assertStatus(200);
        $this->assertEmpty($listRes->json('data'));
    }

    /**
     * 11. Multi-Variation Combinations CRUD (red - 32, blue - 32)
     */
    public function test_create_and_update_multi_variation_product_with_combinations_red_32_blue_32(): void
    {
        $payload = [
            'name' => 'Nike Pegasus 39 Running Shoes',
            'code' => '10090',
            'sku' => '10090',
            'category_name' => 'Footwear & Shoes',
            'brand_name' => 'Nike',
            'unit_name' => 'Pair',
            'cost_price' => 50.00,
            'selling_price' => 110.00,
            'has_variants' => true,
            'variants' => [
                [
                    'name' => 'red - 32',
                    'sku' => '1009001',
                    'cost_price' => 50.00,
                    'selling_price' => 110.00,
                    'retail_prices' => [110.00, 105.00, 100.00],
                    'wholesale_prices' => [90.00, 85.00],
                    'is_active' => true,
                    'attribute_values' => [
                        ['attribute_name' => 'red', 'attribute_value' => '32'],
                    ],
                ],
                [
                    'name' => 'blue - 32',
                    'sku' => '1009002',
                    'cost_price' => 52.00,
                    'selling_price' => 115.00,
                    'retail_prices' => [115.00, 110.00],
                    'wholesale_prices' => [92.00],
                    'is_active' => true,
                    'attribute_values' => [
                        ['attribute_name' => 'blue', 'attribute_value' => '32'],
                    ],
                ],
            ],
        ];

        $res = $this->postJson('/api/products', $payload);
        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_variants', true);

        $prodId = $res->json('data.id');

        // Verify variants created in database
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $prodId,
            'name' => 'red - 32',
            'sku' => '1009001',
        ]);
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $prodId,
            'name' => 'blue - 32',
            'sku' => '1009002',
        ]);

        // Verify attribute values attached
        $this->assertDatabaseHas('product_variant_values', [
            'attribute_name' => 'red',
            'attribute_value' => '32',
        ]);
        $this->assertDatabaseHas('product_variant_values', [
            'attribute_name' => 'blue',
            'attribute_value' => '32',
        ]);

        // Read / Show Endpoint
        $showRes = $this->getJson("/api/products/{$prodId}");
        $showRes->assertStatus(200)
            ->assertJsonPath('data.variants_count', 2);
    }

    /**
     * 12. Cascade Variation Rename & Value Update to Connected Products (e.g. 32 -> 35)
     */
    public function test_update_variation_cascades_renamed_types_to_connected_products(): void
    {
        // 1. Create Variation 'blue' with types 32, 33, 34
        $variation = Variation::create([
            'name' => 'blue',
            'variation_types' => ['32', '33', '34'],
            'status' => 'Active',
        ]);

        // 2. Create Product with variant 'blue - 32'
        $createRes = $this->postJson('/api/products', [
            'name' => 'Blue Shoes',
            'code' => '10095',
            'sku' => '10095',
            'has_variants' => true,
            'variants' => [
                [
                    'name' => 'blue - 32',
                    'sku' => '1009501',
                    'cost_price' => 50.00,
                    'selling_price' => 100.00,
                    'attribute_values' => [
                        ['attribute_name' => 'blue', 'attribute_value' => '32'],
                    ],
                ],
            ],
        ]);
        $createRes->assertStatus(201);
        $prodId = $createRes->json('data.id');

        // 3. Update Variation: change 32 to 35
        $updateVarRes = $this->putJson("/api/variations/{$variation->id}", [
            'name' => 'blue',
            'variation_types' => ['35', '33', '34'],
            'type_renames' => ['32' => '35'],
            'status' => 'Active',
        ]);
        $updateVarRes->assertStatus(200);

        // 4. Verify Product Variant updated from 'blue - 32' to 'blue - 35'
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $prodId,
            'name' => 'blue - 35',
        ]);
        $this->assertDatabaseMissing('product_variants', [
            'product_id' => $prodId,
            'name' => 'blue - 32',
        ]);

        // 5. Verify ProductVariantValue updated from '32' to '35'
        $this->assertDatabaseHas('product_variant_values', [
            'attribute_name' => 'blue',
            'attribute_value' => '35',
        ]);
        $this->assertDatabaseMissing('product_variant_values', [
            'attribute_name' => 'blue',
            'attribute_value' => '32',
        ]);
    }

    /**
     * 13. Cascade Variation Across Multiple Colors (red - 32, blue - 32, green - 32, yellow - 32)
     */
    public function test_update_variation_cascades_across_multiple_colors_and_composite_variants(): void
    {
        // 1. Get or Create Size variation
        $sizeVar = Variation::firstOrCreate(
            ['name' => 'Size'],
            ['variation_types' => ['32', '33', '34'], 'status' => 'Active']
        );
        $sizeVar->update(['variation_types' => ['32', '33', '34']]);

        // 2. Create Multi-Color Product with 4 variants
        $prodRes = $this->postJson('/api/products', [
            'name' => 'Air Jordan High Top',
            'code' => '10099',
            'sku' => '10099',
            'has_variants' => true,
            'variants' => [
                ['name' => 'red - 32', 'sku' => '1009901', 'cost_price' => 70, 'selling_price' => 150, 'attribute_values' => [['attribute_name' => 'red', 'attribute_value' => '32']]],
                ['name' => 'blue - 32', 'sku' => '1009902', 'cost_price' => 70, 'selling_price' => 150, 'attribute_values' => [['attribute_name' => 'blue', 'attribute_value' => '32']]],
                ['name' => 'green - 32', 'sku' => '1009903', 'cost_price' => 70, 'selling_price' => 150, 'attribute_values' => [['attribute_name' => 'green', 'attribute_value' => '32']]],
                ['name' => 'yellow - 32', 'sku' => '1009904', 'cost_price' => 70, 'selling_price' => 150, 'attribute_values' => [['attribute_name' => 'yellow', 'attribute_value' => '32']]],
            ],
        ]);
        $prodRes->assertStatus(201);
        $prodId = $prodRes->json('data.id');

        // 3. Update Size: Change 32 to 35
        $updateRes = $this->putJson("/api/variations/{$sizeVar->id}", [
            'name' => 'Size',
            'variation_types' => ['35', '33', '34'],
            'type_renames' => ['32' => '35'],
            'status' => 'Active',
        ]);
        $updateRes->assertStatus(200);

        // 4. Verify ALL colors are now updated to 35
        $this->assertDatabaseHas('product_variants', ['product_id' => $prodId, 'name' => 'red - 35']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $prodId, 'name' => 'blue - 35']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $prodId, 'name' => 'green - 35']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $prodId, 'name' => 'yellow - 35']);

        $this->assertDatabaseMissing('product_variants', ['product_id' => $prodId, 'name' => 'red - 32']);
        $this->assertDatabaseMissing('product_variants', ['product_id' => $prodId, 'name' => 'blue - 32']);
        $this->assertDatabaseMissing('product_variants', ['product_id' => $prodId, 'name' => 'green - 32']);
        $this->assertDatabaseMissing('product_variants', ['product_id' => $prodId, 'name' => 'yellow - 32']);
    }

    /**
     * 14. Renaming Variation Name (blue -> cyan) and Variation Type (30 -> 22) Cascades to Product List
     */
    public function test_renaming_variation_name_blue_to_cyan_and_type_30_to_22_updates_product_list_variants(): void
    {
        // 1. Create Variation 'blue' with [30, 33, 34]
        $blueVar = Variation::create([
            'name' => 'blue',
            'variation_types' => ['30', '33', '34'],
            'status' => 'Active',
        ]);

        // 2. Create Product with variant 'blue - 30'
        $prodRes = $this->postJson('/api/products', [
            'name' => 'Summer Casual Sneakers',
            'code' => '10111',
            'sku' => '10111',
            'has_variants' => true,
            'variants' => [
                [
                    'name' => 'blue - 30',
                    'sku' => '1011101',
                    'cost_price' => 45,
                    'selling_price' => 90,
                    'attribute_values' => [
                        ['attribute_name' => 'blue', 'attribute_value' => '30'],
                    ],
                ],
            ],
        ]);
        $prodRes->assertStatus(201);
        $prodId = $prodRes->json('data.id');

        // 3. Update Variation: Rename 'blue' -> 'cyan' AND Rename '30' -> '22'
        $updateVarRes = $this->putJson("/api/variations/{$blueVar->id}", [
            'name' => 'cyan',
            'variation_types' => ['22', '33', '34'],
            'type_renames' => ['30' => '22'],
            'status' => 'Active',
        ]);
        $updateVarRes->assertStatus(200);

        // 4. Verify Database Records
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $prodId,
            'name' => 'cyan - 22',
        ]);
        $this->assertDatabaseMissing('product_variants', [
            'product_id' => $prodId,
            'name' => 'blue - 30',
        ]);

        $this->assertDatabaseHas('product_variant_values', [
            'attribute_name' => 'cyan',
            'attribute_value' => '22',
        ]);

        // 5. Verify Product Show / List API returns updated variant name
        $showRes = $this->getJson("/api/products/{$prodId}");
        $showRes->assertStatus(200)
            ->assertJsonPath('data.variants.0.name', 'cyan - 22');
    }

    /**
     * 15. Adding New Variant (e.g. cyan - 44) to Existing Variable Product in Edit Mode
     */
    public function test_add_new_variant_to_existing_variable_product_during_edit(): void
    {
        // 1. Create Variable Product with 2 initial variants
        $prodRes = $this->postJson('/api/products', [
            'name' => 'Anker Testing Product',
            'code' => '10410',
            'sku' => '10410',
            'has_variants' => true,
            'variants' => [
                [
                    'name' => 'cyan - 33',
                    'sku' => '1041001',
                    'cost_price' => 1000,
                    'selling_price' => 1000,
                    'retail_prices' => [1000, 200, 400],
                    'wholesale_prices' => [1000, 500, 600],
                    'attribute_values' => [
                        ['attribute_name' => 'cyan', 'attribute_value' => '33'],
                    ],
                ],
                [
                    'name' => 'dark - 32',
                    'sku' => '1041003',
                    'cost_price' => 1000,
                    'selling_price' => 1000,
                    'retail_prices' => [1000, 1300, 1400],
                    'wholesale_prices' => [1000, 1500, 1600],
                    'attribute_values' => [
                        ['attribute_name' => 'dark', 'attribute_value' => '32'],
                    ],
                ],
            ],
        ]);
        $prodRes->assertStatus(201);
        $prodId = $prodRes->json('data.id');

        // 2. Update Product: Add 3rd variant 'cyan - 44'
        $updateRes = $this->putJson("/api/products/{$prodId}", [
            'name' => 'Anker Testing Product',
            'code' => '10410',
            'sku' => '10410',
            'has_variants' => true,
            'variants' => [
                [
                    'name' => 'cyan - 33',
                    'sku' => '1041001',
                    'cost_price' => 1000,
                    'selling_price' => 1000,
                    'retail_prices' => [1000, 200, 400],
                    'wholesale_prices' => [1000, 500, 600],
                    'attribute_values' => [
                        ['attribute_name' => 'cyan', 'attribute_value' => '33'],
                    ],
                ],
                [
                    'name' => 'dark - 32',
                    'sku' => '1041003',
                    'cost_price' => 1000,
                    'selling_price' => 1000,
                    'retail_prices' => [1000, 1300, 1400],
                    'wholesale_prices' => [1000, 1500, 1600],
                    'attribute_values' => [
                        ['attribute_name' => 'dark', 'attribute_value' => '32'],
                    ],
                ],
                [
                    'name' => 'cyan - 44',
                    'sku' => '1041004',
                    'cost_price' => 1000,
                    'selling_price' => 1000,
                    'retail_prices' => [1000, 300, 500],
                    'wholesale_prices' => [1000, 600, 700],
                    'attribute_values' => [
                        ['attribute_name' => 'cyan', 'attribute_value' => '44'],
                    ],
                ],
            ],
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('data.variants_count', 3);

        // 3. Verify all 3 variants exist in database
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $prodId,
            'name' => 'cyan - 33',
        ]);
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $prodId,
            'name' => 'dark - 32',
        ]);
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $prodId,
            'name' => 'cyan - 44',
            'sku' => '1041004',
        ]);

        // 4. Verify attribute values attached for newly added variant
        $this->assertDatabaseHas('product_variant_values', [
            'attribute_name' => 'cyan',
            'attribute_value' => '44',
        ]);
    }
}
