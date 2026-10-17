<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\SubCategory;
use App\Models\Supplier;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MasterDataSyncBatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1: Ingest batch of offline master data across all entity types in dependency order.
     */
    public function test_master_data_batch_sync_creates_entities_and_returns_id_map(): void
    {
        $catUuid = (string) Str::uuid();
        $subCatUuid = (string) Str::uuid();
        $brandUuid = (string) Str::uuid();
        $unitUuid = (string) Str::uuid();
        $saleUnitUuid = (string) Str::uuid();
        $purchaseUnitUuid = (string) Str::uuid();
        $supplierUuid = (string) Str::uuid();
        $outletUuid = (string) Str::uuid();
        $variationUuid = (string) Str::uuid();

        $payload = [
            'branch_code' => 'B01',
            'units' => [
                [
                    'uuid' => $unitUuid,
                    'name' => 'Kilogram',
                    'code' => 'KG',
                    'is_active' => 1,
                ],
            ],
            'categories' => [
                [
                    'uuid' => $catUuid,
                    'name' => 'Beverages',
                    'description' => 'Cold drinks and sodas',
                    'is_active' => 1,
                ],
            ],
            'sale_units' => [
                [
                    'uuid' => $saleUnitUuid,
                    'base_unit_uuid' => $unitUuid,
                    'name' => 'Pack 500g',
                ],
            ],
            'purchase_units' => [
                [
                    'uuid' => $purchaseUnitUuid,
                    'base_unit_uuid' => $unitUuid,
                    'name' => 'Sack 25kg',
                ],
            ],
            'sub_categories' => [
                [
                    'uuid' => $subCatUuid,
                    'category_uuid' => $catUuid,
                    'name' => 'Soft Drinks',
                    'description' => 'Carbonated beverages',
                ],
            ],
            'brands' => [
                [
                    'uuid' => $brandUuid,
                    'name' => 'Coca Cola Co',
                    'code' => 'KO',
                    'is_active' => 1,
                ],
            ],
            'suppliers' => [
                [
                    'uuid' => $supplierUuid,
                    'name' => 'Pacific Beverage Supply',
                    'phone' => '+959111222333',
                    'address' => 'Yangon Industrial Zone',
                    'is_active' => 1,
                ],
            ],
            'variations' => [
                [
                    'uuid' => $variationUuid,
                    'name' => 'Size',
                    'variation_types' => ['S', 'M', 'L', 'XL'],
                    'is_active' => 1,
                ],
            ],
            'warehouses' => [
                [
                    'uuid' => $outletUuid,
                    'name' => 'Mandalay Depot',
                    'code' => 'MDY-01',
                    'is_active' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/master-data/sync-batch', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // Verify entities were created in database
        $this->assertDatabaseHas('product_units', [
            'uuid' => $unitUuid,
            'name' => 'Kilogram',
        ]);
        $this->assertDatabaseHas('product_categories', [
            'uuid' => $catUuid,
            'name' => 'Beverages',
        ]);
        $this->assertDatabaseHas('sub_categories', [
            'uuid' => $subCatUuid,
            'name' => 'Soft Drinks',
        ]);
        $this->assertDatabaseHas('brands', [
            'uuid' => $brandUuid,
            'name' => 'Coca Cola Co',
        ]);
        $this->assertDatabaseHas('suppliers', [
            'uuid' => $supplierUuid,
            'name' => 'Pacific Beverage Supply',
            'phone' => '+959111222333',
        ]);
        $this->assertDatabaseHas('variations', [
            'uuid' => $variationUuid,
            'name' => 'Size',
        ]);
        $this->assertDatabaseHas('outlets', [
            'uuid' => $outletUuid,
            'name' => 'Mandalay Depot',
            'code' => 'MDY-01',
        ]);

        // Verify id_map returns mappings for each uuid
        $unitId = ProductUnit::where('uuid', $unitUuid)->value('id');
        $catId = ProductCategory::where('uuid', $catUuid)->value('id');
        $subCatId = SubCategory::where('uuid', $subCatUuid)->value('id');
        $brandId = Brand::where('uuid', $brandUuid)->value('id');
        $supplierId = Supplier::where('uuid', $supplierUuid)->value('id');
        $variationId = Variation::where('uuid', $variationUuid)->value('id');

        $this->assertEquals($unitId, $response->json("id_map.units.{$unitUuid}"));
        $this->assertEquals($catId, $response->json("id_map.categories.{$catUuid}"));
        $this->assertEquals($subCatId, $response->json("id_map.sub_categories.{$subCatUuid}"));
        $this->assertEquals($brandId, $response->json("id_map.brands.{$brandUuid}"));
        $this->assertEquals($supplierId, $response->json("id_map.suppliers.{$supplierUuid}"));
        $this->assertEquals($variationId, $response->json("id_map.variations.{$variationUuid}"));

        // Verify subcategory linked to parent category
        $subCat = SubCategory::where('uuid', $subCatUuid)->first();
        $this->assertEquals($catId, $subCat->parent_category_id);
    }

    /**
     * Test 2: Idempotent replay does not duplicate records and returns existing server IDs.
     */
    public function test_master_data_batch_sync_is_idempotent_on_replay(): void
    {
        $catUuid = (string) Str::uuid();
        $brandUuid = (string) Str::uuid();
        $variationUuid = (string) Str::uuid();

        $payload = [
            'categories' => [
                ['uuid' => $catUuid, 'name' => 'Electronics'],
            ],
            'brands' => [
                ['uuid' => $brandUuid, 'name' => 'Sony'],
            ],
            'variations' => [
                ['uuid' => $variationUuid, 'name' => 'Color', 'variation_types' => ['Red', 'Blue']],
            ],
        ];

        // First submission
        $res1 = $this->postJson('/api/master-data/sync-batch', $payload);
        $res1->assertStatus(200);
        $catId1 = $res1->json("id_map.categories.{$catUuid}");
        $brandId1 = $res1->json("id_map.brands.{$brandUuid}");
        $variationId1 = $res1->json("id_map.variations.{$variationUuid}");

        $this->assertEquals(1, ProductCategory::where('name', 'Electronics')->count());
        $this->assertEquals(1, Brand::where('name', 'Sony')->count());
        $this->assertEquals(1, Variation::where('name', 'Color')->count());

        // Replay same payload
        $res2 = $this->postJson('/api/master-data/sync-batch', $payload);
        $res2->assertStatus(200);
        $catId2 = $res2->json("id_map.categories.{$catUuid}");
        $brandId2 = $res2->json("id_map.brands.{$brandUuid}");
        $variationId2 = $res2->json("id_map.variations.{$variationUuid}");

        // Must match original IDs
        $this->assertEquals($catId1, $catId2);
        $this->assertEquals($brandId1, $brandId2);
        $this->assertEquals($variationId1, $variationId2);

        // Counts must not have increased
        $this->assertEquals(1, ProductCategory::where('name', 'Electronics')->count());
        $this->assertEquals(1, Brand::where('name', 'Sony')->count());
        $this->assertEquals(1, Variation::where('name', 'Color')->count());
    }

    /**
     * Test 3: Deduplication by unique name when UUID was previously absent in cloud.
     */
    public function test_master_data_deduplicates_by_unique_name_and_backfills_uuid(): void
    {
        // Cloud already has a brand created manually without client UUID
        $existingBrand = Brand::create([
            'name' => 'Apple Inc',
            'code' => 'APPL',
            'status' => 'Active',
        ]);
        $this->assertNull($existingBrand->uuid);

        $clientUuid = (string) Str::uuid();
        $payload = [
            'brands' => [
                [
                    'uuid' => $clientUuid,
                    'name' => 'Apple Inc', // Exact name match
                ],
            ],
        ];

        $response = $this->postJson('/api/master-data/sync-batch', $payload);
        $response->assertStatus(200);

        // Assert existing brand was reused
        $this->assertEquals($existingBrand->id, $response->json("id_map.brands.{$clientUuid}"));
        $this->assertEquals(1, Brand::where('name', 'Apple Inc')->count());

        // Assert UUID was backfilled
        $existingBrand->refresh();
        $this->assertEquals($clientUuid, $existingBrand->uuid);
    }

    /**
     * Test 4: Master data sync updates properties when entity already exists by UUID.
     */
    public function test_master_data_batch_sync_updates_properties_on_existing_entity(): void
    {
        $catUuid = (string) Str::uuid();
        $brandUuid = (string) Str::uuid();
        $variationUuid = (string) Str::uuid();

        // Initial creation
        $payload1 = [
            'categories' => [
                ['uuid' => $catUuid, 'name' => 'Beverages', 'description' => 'Old Desc', 'status' => 'Active'],
            ],
            'brands' => [
                ['uuid' => $brandUuid, 'name' => 'Old Brand Name', 'code' => 'OBN'],
            ],
            'variations' => [
                ['uuid' => $variationUuid, 'name' => 'Color', 'variation_types' => ['Red']],
            ],
        ];
        $this->postJson('/api/master-data/sync-batch', $payload1)->assertStatus(200);

        // Update with new properties
        $payload2 = [
            'categories' => [
                ['uuid' => $catUuid, 'name' => 'Beverages & Drinks', 'description' => 'Updated Desc', 'status' => 'Inactive'],
            ],
            'brands' => [
                ['uuid' => $brandUuid, 'name' => 'New Brand Name', 'code' => 'NBN'],
            ],
            'variations' => [
                ['uuid' => $variationUuid, 'name' => 'Color', 'variation_types' => ['Red', 'Green', 'Blue']],
            ],
        ];
        $this->postJson('/api/master-data/sync-batch', $payload2)->assertStatus(200);

        $this->assertDatabaseHas('product_categories', [
            'uuid' => $catUuid,
            'name' => 'Beverages & Drinks',
            'description' => 'Updated Desc',
            'status' => 'Inactive',
        ]);
        $this->assertDatabaseHas('brands', [
            'uuid' => $brandUuid,
            'name' => 'New Brand Name',
            'code' => 'NBN',
        ]);
        $variation = Variation::where('uuid', $variationUuid)->first();
        $this->assertEquals(['Red', 'Green', 'Blue'], $variation->variation_types);
    }

    /**
     * Test 5: Master data sync handles deletion action when no dependent records exist.
     */
    public function test_master_data_batch_sync_handles_deletions(): void
    {
        $cat = ProductCategory::create(['name' => 'To Delete Cat', 'uuid' => (string) Str::uuid()]);
        $brand = Brand::create(['name' => 'To Delete Brand', 'uuid' => (string) Str::uuid()]);
        $unit = ProductUnit::create(['name' => 'To Delete Unit', 'uuid' => (string) Str::uuid()]);
        $variation = Variation::create(['name' => 'To Delete Variation', 'uuid' => (string) Str::uuid(), 'variation_types' => ['Option1']]);

        $payload = [
            'categories' => [
                ['uuid' => $cat->uuid, 'id' => $cat->id, 'action' => 'delete'],
            ],
            'brands' => [
                ['uuid' => $brand->uuid, 'id' => $brand->id, 'action' => 'delete'],
            ],
            'units' => [
                ['uuid' => $unit->uuid, 'id' => $unit->id, 'action' => 'delete'],
            ],
            'variations' => [
                ['uuid' => $variation->uuid, 'id' => $variation->id, 'action' => 'delete'],
            ],
        ];

        $response = $this->postJson('/api/master-data/sync-batch', $payload);
        $response->assertStatus(200);

        $this->assertDatabaseMissing('product_categories', ['id' => $cat->id]);
        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
        $this->assertDatabaseMissing('product_units', ['id' => $unit->id]);
        $this->assertDatabaseMissing('variations', ['id' => $variation->id]);
    }

    /**
     * Test 6: Deduplication by ID when legacy record had no UUID and name was renamed.
     */
    public function test_master_data_deduplicates_by_id_when_name_changed_and_uuid_missing(): void
    {
        $legacyBrand = Brand::create([
            'name' => 'Old Legacy Name',
            'code' => 'LEG',
            'status' => 'Active',
        ]);
        $this->assertNull($legacyBrand->uuid);

        $clientUuid = (string) Str::uuid();
        $payload = [
            'brands' => [
                [
                    'id' => $legacyBrand->id,
                    'uuid' => $clientUuid,
                    'name' => 'Renamed Brand Name',
                ],
            ],
        ];

        $response = $this->postJson('/api/master-data/sync-batch', $payload);
        $response->assertStatus(200);

        // Assert record was updated, not duplicated
        $this->assertEquals(1, Brand::where('id', $legacyBrand->id)->count());
        $this->assertEquals(1, Brand::count());

        $legacyBrand->refresh();
        $this->assertEquals('Renamed Brand Name', $legacyBrand->name);
        $this->assertEquals($clientUuid, $legacyBrand->uuid);
    }
}
