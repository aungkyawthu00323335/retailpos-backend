<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdjustmentAndQuickSearchOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_adjustment_clears_legacy_reason_code_when_null_and_export_uses_notes(): void
    {
        $product = Product::create([
            'code' => 'RSN-001',
            'name' => 'Reason Note Product',
            'cost_price' => 100.0,
            'price' => 150.0,
            'stock_quantity' => 20.0,
            'low_qty' => 2.0,
            'product_unit' => 'Piece',
            'barcode_symbol' => 'CODE128',
        ]);

        $createRes = $this->postJson('/api/adjustments', [
            'reference_no' => 'AD_RSN_001',
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-11-15 09:00:00',
            'type' => 'Addition',
            'reason_code' => 'Stocktake Surplus',
            'notes' => 'Broken carton on shelf',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 2.0,
                    'type' => 'Addition',
                    'unit_cost' => 100.0,
                ],
            ],
        ]);
        $createRes->assertStatus(201);
        $adjId = $createRes->json('data.id');

        $updateRes = $this->putJson("/api/adjustments/{$adjId}", [
            'warehouse_name' => 'Central Warehouse',
            'date' => '2026-11-15 09:30:00',
            'type' => 'Addition',
            'reason_code' => null,
            'notes' => 'Recount found extra packs',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'quantity' => 3.0,
                    'type' => 'Addition',
                    'unit_cost' => 100.0,
                ],
            ],
        ]);
        $updateRes->assertStatus(200);

        $adj = StockAdjustment::findOrFail($adjId);
        $this->assertNull($adj->reason_code);
        $this->assertSame('Recount found extra packs', $adj->notes);

        $exportRes = $this->get('/api/adjustments/export?type=All');
        $exportRes->assertStatus(200);
        ob_start();
        $exportRes->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('Recount found extra packs', $csv);
    }

    public function test_product_quick_search_matches_exact_prefix_alternative_name_variant_and_contains(): void
    {
        $p1 = Product::create([
            'code' => '99998888',
            'barcode' => '99998888',
            'sku' => 'SKU-EXACT-9999',
            'name' => 'Aung Alpha Tea',
            'alternative_name' => 'အောင်လက်ဖက်',
            'cost_price' => 500.0,
            'price' => 800.0,
            'stock_quantity' => 15.0,
            'product_unit' => 'Pack',
            'barcode_symbol' => 'CODE128',
        ]);

        $p2 = Product::create([
            'code' => 'VAR-200',
            'barcode' => 'VAR-200',
            'name' => 'Nike Running Shoes',
            'alternative_name' => 'မြန်မာ ဖိနပ်',
            'cost_price' => 25000.0,
            'price' => 35000.0,
            'stock_quantity' => 8.0,
            'has_variants' => true,
            'product_unit' => 'Pair',
            'barcode_symbol' => 'CODE128',
        ]);

        ProductVariant::create([
            'product_id' => $p2->id,
            'name' => 'Size 44 Neon',
            'sku' => 'NIKE-44-NEON',
            'barcode' => '8800440011',
            'cost_price' => 25000.0,
            'price' => 35000.0,
            'stock_quantity' => 8.0,
        ]);

        // Exact code/barcode match
        $resExact = $this->getJson('/api/products/quick-search?q=99998888');
        $resExact->assertStatus(200);
        $this->assertSame($p1->id, $resExact->json('data.0.id'));

        // Prefix name match
        $resPrefix = $this->getJson('/api/products/quick-search?q=Aung');
        $resPrefix->assertStatus(200);
        $this->assertSame($p1->id, $resPrefix->json('data.0.id'));

        // Prefix alternative_name match
        $resAlt = $this->getJson('/api/products/quick-search?q=' . urlencode('အောင်'));
        $resAlt->assertStatus(200);
        $this->assertSame($p1->id, $resAlt->json('data.0.id'));

        // Variant prefix match
        $resVariant = $this->getJson('/api/products/quick-search?q=Size+44');
        $resVariant->assertStatus(200);
        $this->assertSame($p2->id, $resVariant->json('data.0.id'));

        // Contains fallback when no prefix matches
        $resContains = $this->getJson('/api/products/quick-search?q=Running');
        $resContains->assertStatus(200);
        $this->assertSame($p2->id, $resContains->json('data.0.id'));
    }
}
