<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\SubCategory;
use App\Models\Supplier;
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

        $this->assertEquals($unitId, $response->json("id_map.units.{$unitUuid}"));
        $this->assertEquals($catId, $response->json("id_map.categories.{$catUuid}"));
        $this->assertEquals($subCatId, $response->json("id_map.sub_categories.{$subCatUuid}"));
        $this->assertEquals($brandId, $response->json("id_map.brands.{$brandUuid}"));
        $this->assertEquals($supplierId, $response->json("id_map.suppliers.{$supplierUuid}"));

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

        $payload = [
            'categories' => [
                ['uuid' => $catUuid, 'name' => 'Electronics'],
            ],
            'brands' => [
                ['uuid' => $brandUuid, 'name' => 'Sony'],
            ],
        ];

        // First submission
        $res1 = $this->postJson('/api/master-data/sync-batch', $payload);
        $res1->assertStatus(200);
        $catId1 = $res1->json("id_map.categories.{$catUuid}");
        $brandId1 = $res1->json("id_map.brands.{$brandUuid}");

        $this->assertEquals(1, ProductCategory::where('name', 'Electronics')->count());
        $this->assertEquals(1, Brand::where('name', 'Sony')->count());

        // Replay same payload
        $res2 = $this->postJson('/api/master-data/sync-batch', $payload);
        $res2->assertStatus(200);
        $catId2 = $res2->json("id_map.categories.{$catUuid}");
        $brandId2 = $res2->json("id_map.brands.{$brandUuid}");

        // Must match original IDs
        $this->assertEquals($catId1, $catId2);
        $this->assertEquals($brandId1, $brandId2);

        // Counts must not have increased
        $this->assertEquals(1, ProductCategory::where('name', 'Electronics')->count());
        $this->assertEquals(1, Brand::where('name', 'Sony')->count());
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
}
