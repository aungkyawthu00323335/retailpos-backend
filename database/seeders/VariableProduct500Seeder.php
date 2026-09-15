<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Variation;

class VariableProduct500Seeder extends Seeder
{
    public function run(): void
    {
        $startTime = microtime(true);
        echo "===============================================================\n";
        echo "   500 VARIABLE PRODUCTS ENTERPRISE SEEDER STARTING            \n";
        echo "===============================================================\n";

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        $now = Carbon::now();
        $startDate = Carbon::now()->subMonths(6);

        // =====================================================================
        // STEP 1: ENSURE MASTER VARIATIONS (SIZE & COLOR)
        // =====================================================================
        echo "\n[1/5] Initializing Master Variations (Size & Color)...\n";
        $sizeVariation = Variation::firstOrCreate(
            ['name' => 'Size'],
            [
                'name' => 'Size',
                'variation_types' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
                'status' => 'Active',
            ]
        );

        $colorVariation = Variation::firstOrCreate(
            ['name' => 'Color'],
            [
                'name' => 'Color',
                'variation_types' => ['Black', 'White', 'Navy Blue', 'Charcoal Gray', 'Warm Beige', 'Olive Green', 'Crimson Red', 'Royal Blue'],
                'status' => 'Active',
            ]
        );

        // Fetch active outlets (6 Outlets)
        $outlets = DB::table('outlets')->whereIn('id', [1, 3, 5, 7, 8, 9])->get();
        if ($outlets->isEmpty()) {
            $outlets = DB::table('outlets')->get();
        }
        echo "   -> " . $outlets->count() . " Active Outlets loaded for Multi-Outlet allocation!\n";

        // Fetch Categories, Brands, Units
        $categories = DB::table('product_categories')->get();
        $brands = DB::table('brands')->get();
        $units = DB::table('product_units')->get();
        $suppliers = DB::table('suppliers')->get();

        $pieceUnit = $units->firstWhere('name', 'Piece') ?? $units->first();
        $packUnit = $units->firstWhere('name', 'Pack') ?? $units->first();
        $dozenUnit = $units->firstWhere('name', 'Dozen') ?? $units->first();
        $boxUnit = $units->firstWhere('name', 'Box') ?? $units->first();
        $cartonUnit = $units->firstWhere('name', 'Carton') ?? $units->first();
        $pairUnit = $units->firstWhere('name', 'Pair') ?? $pieceUnit;

        // Clean any previously seeded 500 variable products (IDs 1001 to 1500 or code 'VAR-%')
        echo "\n[2/5] Cleaning existing variable products (1001 - 1500) if re-running...\n";
        $existingVarIds = DB::table('products')
            ->where('id', '>', 1000)
            ->orWhere('code', 'LIKE', 'VAR-%')
            ->pluck('id');

        if ($existingVarIds->isNotEmpty()) {
            $varIds = DB::table('product_variants')->whereIn('product_id', $existingVarIds)->pluck('id');
            if ($varIds->isNotEmpty()) {
                DB::table('product_variant_values')->whereIn('product_variant_id', $varIds)->delete();
                DB::table('stock_movements')->whereIn('product_variant_id', $varIds)->delete();
                DB::table('stock_balances')->whereIn('product_variant_id', $varIds)->delete();
                DB::table('product_variants')->whereIn('id', $varIds)->delete();
            }
            DB::table('stock_movements')->whereIn('product_id', $existingVarIds)->delete();
            DB::table('stock_balances')->whereIn('product_id', $existingVarIds)->delete();
            DB::table('product_unit_links')->whereIn('product_id', $existingVarIds)->delete();
            DB::table('products')->whereIn('id', $existingVarIds)->delete();
            echo "   -> Cleared " . $existingVarIds->count() . " existing variable products for fresh seeding.\n";
        }

        // Also clean any orphan variants pointing to products with has_variants = false
        DB::table('product_variants')->whereIn('product_id', function ($q) {
            $q->select('id')->from('products')->where('has_variants', false);
        })->delete();

        // =====================================================================
        // STEP 3: PREPARE 500 VARIABLE PRODUCTS & VARIANT DEFINITIONS
        // =====================================================================
        echo "\n[3/5] Generating 500 Variable Products with Multi-Tier Pricing & Multi-Units...\n";

        $productTypes = [
            [
                'name' => 'Slim Fit Cotton T-Shirt',
                'alt' => 'ဂျပန်ချည်စတိုင် တီရှပ်',
                'cat' => "Men's Fashion & Apparel",
                'type' => 'Size',
                'variants' => ['S', 'M', 'L', 'XL'],
                'unit' => $pieceUnit,
                'cost' => 12.00,
            ],
            [
                'name' => 'Waterproof Windbreaker Jacket',
                'alt' => 'ရေစိုခံ အပြေးဂျာကင်',
                'cat' => 'Fashion & Apparel',
                'type' => 'Color',
                'variants' => ['Black', 'Navy Blue', 'Olive Green', 'Crimson Red'],
                'unit' => $pieceUnit,
                'cost' => 28.00,
            ],
            [
                'name' => 'Stretch Denim Slim Jeans',
                'alt' => 'အကြောဆန့် ဂျင်းဘောင်းဘီရှည်',
                'cat' => "Men's Fashion & Apparel",
                'type' => 'Size',
                'variants' => ['30W', '32W', '34W', '36W'],
                'unit' => $pieceUnit,
                'cost' => 22.00,
            ],
            [
                'name' => 'Breathable Sport Running Shoes',
                'alt' => 'လေဝင်လေထွက်ကောင်း အပြေးဖိနပ်',
                'cat' => 'Footwear & Shoes',
                'type' => 'Size',
                'variants' => ['EU-40', 'EU-41', 'EU-42', 'EU-43'],
                'unit' => $pairUnit,
                'cost' => 45.00,
            ],
            [
                'name' => 'Polarized Sunglasses Titanium Frame',
                'alt' => 'နေကာမျက်မှန် တိုက်တေနီယမ်ဘောင်',
                'cat' => 'Eyewear & Fashion',
                'type' => 'Color',
                'variants' => ['Black Polarized', 'Silver Mirror', 'Gold Tint'],
                'unit' => $pieceUnit,
                'cost' => 18.00,
            ],
            [
                'name' => 'Wireless Noise-Canceling Earbuds',
                'alt' => 'ကြိုးမဲ့ အသံထိန်းနားကြပ်',
                'cat' => 'Audio & Gadgets',
                'type' => 'Color',
                'variants' => ['Matte Black', 'Pearl White', 'Midnight Blue'],
                'unit' => $pieceUnit,
                'cost' => 35.00,
            ],
            [
                'name' => 'Genuine Cowhide Leather Wallet',
                'alt' => 'စစ်မှန်သော နွားသားရေပိုက်ဆံအိတ်',
                'cat' => 'Bags & Luggage',
                'type' => 'Color',
                'variants' => ['Dark Brown', 'Classic Black', 'Tan Camel'],
                'unit' => $pieceUnit,
                'cost' => 16.00,
            ],
            [
                'name' => 'Oxford Formal Leather Dress Shoes',
                'alt' => 'ရုံးတက် သားရေဖိနပ်',
                'cat' => 'Footwear & Shoes',
                'type' => 'Size',
                'variants' => ['EU-40', 'EU-41', 'EU-42', 'EU-43'],
                'unit' => $pairUnit,
                'cost' => 52.00,
            ],
            [
                'name' => 'Multi-Compartment Laptop Backpack',
                'alt' => 'ကွန်ပျူတာ ကျောပိုးအိတ် အကန့်စုံ',
                'cat' => 'Bags & Luggage',
                'type' => 'Color',
                'variants' => ['Charcoal Gray', 'Deep Navy', 'Stealth Black'],
                'unit' => $pieceUnit,
                'cost' => 26.00,
            ],
            [
                'name' => 'Classic Pique Cotton Polo Shirt',
                'alt' => 'ဂန္တဝင် ပိုလိုရှပ်အင်္ကျီ',
                'cat' => "Men's Fashion & Apparel",
                'type' => 'Size',
                'variants' => ['S', 'M', 'L', 'XL'],
                'unit' => $pieceUnit,
                'cost' => 14.50,
            ],
            [
                'name' => 'Fitness Tracker Smartband AMOLED',
                'alt' => 'အားကစား စမတ်လက်ပတ်',
                'cat' => 'Wearables & Smartwatches',
                'type' => 'Color',
                'variants' => ['Obsidian Black', 'Space Gray', 'Rose Gold'],
                'unit' => $pieceUnit,
                'cost' => 24.00,
            ],
            [
                'name' => 'Thermal Insulated Stainless Bottle 750ml',
                'alt' => 'အပူအအေးထိန်း ရေဘူးစတီး',
                'cat' => 'Home & Kitchen Living',
                'type' => 'Color',
                'variants' => ['Matte Black', 'Arctic White', 'Emerald Green', 'Cobalt Blue'],
                'unit' => $pieceUnit,
                'cost' => 9.50,
            ],
        ];

        $images = [
            'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?w=400',
            'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=400',
            'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=400',
            'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=400',
            'https://images.unsplash.com/photo-1572635196237-14b3f281503f?w=400',
            'https://images.unsplash.com/photo-1560343090-f0409e92791a?w=400',
            'https://images.unsplash.com/photo-1583394838336-acd977736f90?w=400',
            'https://images.unsplash.com/photo-1526170375885-4d8ecf77b99f?w=400',
            'https://images.unsplash.com/photo-1491553895911-0055eca6402d?w=400',
            'https://images.unsplash.com/photo-1608231387042-66d1773070a5?w=400',
        ];

        $productsBatch = [];
        $unitLinksBatch = [];
        $variantsBatch = [];
        $variantValuesBatch = [];
        $stockBalancesBatch = [];
        $stockMovementsBatch = [];

        $variantIdCounter = 1001; // Start above existing variants

        for ($i = 1; $i <= 500; $i++) {
            $productId = 1000 + $i;
            $tpl = $productTypes[($i - 1) % count($productTypes)];
            $brand = $brands[($i - 1) % $brands->count()];
            $category = $categories->firstWhere('name', $tpl['cat']) ?? $categories[($i - 1) % $categories->count()];
            $supplier = $suppliers[($i - 1) % $suppliers->count()];

            $name = "{$brand->name} {$tpl['name']} #{$i}";
            $altName = "{$brand->name} {$tpl['alt']} (#{$i})";
            $code = 'VAR-' . str_pad($i, 5, '0', STR_PAD_LEFT);
            $sku = 'SKU-VAR-' . str_pad($i, 5, '0', STR_PAD_LEFT);
            $barcode = '8935' . str_pad($i, 9, '0', STR_PAD_LEFT);

            $baseCost = round($tpl['cost'] + (($i * 3) % 20) * 1.5, 2);

            // Multiple Retail Prices (3 Tiers: Normal, Member/Promo, Bulk Retail)
            $rTier1 = round($baseCost * 1.55, 2);
            $rTier2 = round($baseCost * 1.42, 2);
            $rTier3 = round($baseCost * 1.32, 2);
            $retailPrices = [$rTier1, $rTier2, $rTier3];

            // Multiple Wholesale Prices (3 Tiers: Small Wholesale, Medium Wholesale, Master Bulk)
            $wTier1 = round($baseCost * 1.25, 2);
            $wTier2 = round($baseCost * 1.18, 2);
            $wTier3 = round($baseCost * 1.12, 2);
            $wholesalePrices = [$wTier1, $wTier2, $wTier3];

            $img = $images[($i - 1) % count($images)];
            $baseUnit = $tpl['unit'];

            // -----------------------------------------------------------------
            // MULTI-UNIT LINKING (Multiple Sale Units & Multiple Purchase Units)
            // -----------------------------------------------------------------
            // Multiple Sale Units: Base Unit, Pack, Dozen, Box
            $unitLinksBatch[] = [
                'product_id' => $productId,
                'product_variant_id' => null,
                'unit_type' => 'sale',
                'unit_id' => $baseUnit->id,
                'unit_name' => $baseUnit->name,
                'conversion_rate' => 1.0,
                'is_default' => true,
                'is_active' => true,
                'created_at' => $startDate,
                'updated_at' => $now,
            ];
            $unitLinksBatch[] = [
                'product_id' => $productId,
                'product_variant_id' => null,
                'unit_type' => 'sale',
                'unit_id' => $packUnit->id,
                'unit_name' => 'Pack (6 Pcs)',
                'conversion_rate' => 6.0,
                'is_default' => false,
                'is_active' => true,
                'created_at' => $startDate,
                'updated_at' => $now,
            ];
            $unitLinksBatch[] = [
                'product_id' => $productId,
                'product_variant_id' => null,
                'unit_type' => 'sale',
                'unit_id' => $dozenUnit->id,
                'unit_name' => 'Dozen (12 Pcs)',
                'conversion_rate' => 12.0,
                'is_default' => false,
                'is_active' => true,
                'created_at' => $startDate,
                'updated_at' => $now,
            ];
            $unitLinksBatch[] = [
                'product_id' => $productId,
                'product_variant_id' => null,
                'unit_type' => 'sale',
                'unit_id' => $boxUnit->id,
                'unit_name' => 'Box (24 Pcs)',
                'conversion_rate' => 24.0,
                'is_default' => false,
                'is_active' => true,
                'created_at' => $startDate,
                'updated_at' => $now,
            ];

            // Multiple Purchase Units: Base Unit, Box, Carton
            $unitLinksBatch[] = [
                'product_id' => $productId,
                'product_variant_id' => null,
                'unit_type' => 'purchase',
                'unit_id' => $baseUnit->id,
                'unit_name' => $baseUnit->name,
                'conversion_rate' => 1.0,
                'is_default' => true,
                'is_active' => true,
                'created_at' => $startDate,
                'updated_at' => $now,
            ];
            $unitLinksBatch[] = [
                'product_id' => $productId,
                'product_variant_id' => null,
                'unit_type' => 'purchase',
                'unit_id' => $boxUnit->id,
                'unit_name' => 'Box (24 Pcs)',
                'conversion_rate' => 24.0,
                'is_default' => false,
                'is_active' => true,
                'created_at' => $startDate,
                'updated_at' => $now,
            ];
            $unitLinksBatch[] = [
                'product_id' => $productId,
                'product_variant_id' => null,
                'unit_type' => 'purchase',
                'unit_id' => $cartonUnit->id,
                'unit_name' => 'Carton (72 Pcs)',
                'conversion_rate' => 72.0,
                'is_default' => false,
                'is_active' => true,
                'created_at' => $startDate,
                'updated_at' => $now,
            ];

            // -----------------------------------------------------------------
            // VARIANTS & MULTI-OUTLET STOCK BALANCES
            // -----------------------------------------------------------------
            $varNames = $tpl['variants'];
            $attributeName = $tpl['type'];
            $attrId = ($attributeName === 'Size') ? $sizeVariation->id : $colorVariation->id;

            $productTotalStock = 0.0;

            foreach ($varNames as $vIdx => $vName) {
                $variantId = $variantIdCounter++;
                $vSku = $sku . '-' . Str::slug($vName);
                $vBarcode = '8936' . str_pad($variantId, 9, '0', STR_PAD_LEFT);

                // Variant multi-tier prices (slight delta based on size/color tier)
                $vDelta = ($vIdx * 0.75);
                $vCost = round($baseCost + $vDelta, 2);
                $vRetailPrices = [
                    round($rTier1 + $vDelta * 1.5, 2),
                    round($rTier2 + $vDelta * 1.4, 2),
                    round($rTier3 + $vDelta * 1.3, 2),
                ];
                $vWholesalePrices = [
                    round($wTier1 + $vDelta * 1.25, 2),
                    round($wTier2 + $vDelta * 1.18, 2),
                    round($wTier3 + $vDelta * 1.12, 2),
                ];

                $variantsBatch[] = [
                    'id' => $variantId,
                    'product_id' => $productId,
                    'name' => $vName,
                    'sku' => $vSku,
                    'barcode' => $vBarcode,
                    'cost_price' => $vCost,
                    'selling_price' => $vRetailPrices[0],
                    'retail_prices' => json_encode($vRetailPrices),
                    'wholesale_price' => $vWholesalePrices[0],
                    'wholesale_prices' => json_encode($vWholesalePrices),
                    'is_active' => true,
                    'sort_order' => $vIdx,
                    'created_at' => $startDate,
                    'updated_at' => $now,
                ];

                // Variant value attribute link
                $variantValuesBatch[] = [
                    'product_variant_id' => $variantId,
                    'variation_attribute_id' => $attrId,
                    'variation_value_id' => $vIdx + 1,
                    'attribute_name' => $attributeName,
                    'attribute_value' => $vName,
                    'created_at' => $startDate,
                    'updated_at' => $now,
                ];

                // MULTI-OUTLET STOCK ALLOCATION: Stock for each of the 6 active outlets
                foreach ($outlets as $outlet) {
                    $vQty = 10.0 + (($i * 7 + $variantId * 11 + $outlet->id * 13) % 35);
                    $productTotalStock += $vQty;

                    $stockBalancesBatch[] = [
                        'warehouse_id' => $outlet->id,
                        'product_id' => $productId,
                        'product_variant_id' => $variantId,
                        'quantity' => $vQty,
                        'created_at' => $startDate,
                        'updated_at' => $now,
                    ];

                    $stockMovementsBatch[] = [
                        'warehouse_id' => $outlet->id,
                        'product_id' => $productId,
                        'product_variant_id' => $variantId,
                        'movement_type' => 'opening_stock',
                        'reference_type' => 'initial_seed',
                        'reference_id' => (string)$variantId,
                        'quantity_in' => $vQty,
                        'quantity_out' => 0.0,
                        'balance_after' => $vQty,
                        'created_by' => 1,
                        'note' => "Initial multi-outlet opening stock for {$vName} at {$outlet->name}",
                        'created_at' => $startDate,
                    ];
                }
            }

            // Master Product Record
            $productsBatch[] = [
                'id' => $productId,
                'uuid' => (string)Str::uuid(),
                'code' => $code,
                'sku' => $sku,
                'barcode' => $barcode,
                'barcode_symbology' => 'CODE128',
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'base_unit_id' => $baseUnit->id,
                'sale_unit_id' => $baseUnit->id,
                'purchase_unit_id' => $baseUnit->id,
                'name' => $name,
                'brand_name' => $brand->name,
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'alternative_name' => $altName,
                'category_name' => $category->name,
                'sub_category_name' => null,
                'sub_category_id' => null,
                'unit_name' => $baseUnit->name,
                'purchase_unit' => $baseUnit->name,
                'sale_unit' => $baseUnit->name,
                'purchase_price' => $baseCost,
                'cost_price' => $baseCost,
                'retail_price' => $rTier1,
                'retail_prices' => json_encode($retailPrices),
                'selling_price' => $rTier1,
                'wholesale_price' => $wTier1,
                'wholesale_prices' => json_encode($wholesalePrices),
                'stock_quantity' => $productTotalStock, // Exact reconciled sum across all variants & outlets
                'alert_quantity' => 15,
                'stock_alert' => 15,
                'has_variants' => true,
                'order_tax' => 0.0,
                'tax_type' => 1,
                'low_qty' => 5.0,
                'dine_in_price' => $rTier1,
                'take_away_price' => $rTier1,
                'delivery_price' => round($rTier1 * 1.05, 2),
                'image_url' => $img,
                'description' => "Premium enterprise variable product with multi-tier retail & wholesale prices and multi-unit support: {$name}.",
                'note' => 'Variable product collection with full multi-outlet stock allocation.',
                'status' => 'Active',
                'is_active' => true,
                'created_by' => 1,
                'updated_by' => 1,
                'added_by' => 'Super Admin',
                'created_at' => $startDate,
                'updated_at' => $now,
            ];
        }

        // =====================================================================
        // STEP 4: BULK INSERTIONS IN OPTIMIZED CHUNKS
        // =====================================================================
        echo "\n[4/5] Performing high-speed bulk database insertions...\n";

        // Insert 500 Products
        DB::table('products')->insert($productsBatch);
        echo "   -> 500 Variable Products inserted successfully!\n";

        // Insert Unit Links (approx. 3,500 links: 7 per product)
        foreach (array_chunk($unitLinksBatch, 1000) as $chunk) {
            DB::table('product_unit_links')->insert($chunk);
        }
        echo "   -> " . count($unitLinksBatch) . " Multi-Unit Links inserted (Multiple Sale & Purchase Units)!\n";

        // Insert Variants (approx. 1,750 variants)
        foreach (array_chunk($variantsBatch, 500) as $chunk) {
            DB::table('product_variants')->insert($chunk);
        }
        echo "   -> " . count($variantsBatch) . " Product Variants inserted!\n";

        // Insert Variant Values
        foreach (array_chunk($variantValuesBatch, 500) as $chunk) {
            DB::table('product_variant_values')->insert($chunk);
        }
        echo "   -> " . count($variantValuesBatch) . " Variant Values linked!\n";

        // Insert Stock Balances (approx. 10,500 balances across 6 active outlets)
        foreach (array_chunk($stockBalancesBatch, 1000) as $chunk) {
            DB::table('stock_balances')->insert($chunk);
        }
        echo "   -> " . count($stockBalancesBatch) . " Multi-Outlet Stock Balances inserted across 6 active outlets!\n";

        // Insert Stock Movements
        foreach (array_chunk($stockMovementsBatch, 1000) as $chunk) {
            DB::table('stock_movements')->insert($chunk);
        }
        echo "   -> " . count($stockMovementsBatch) . " Opening Stock Movements logged!\n";

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $duration = round(microtime(true) - $startTime, 2);
        echo "\n===============================================================\n";
        echo "   ALL 500 VARIABLE PRODUCTS SEEDED IN {$duration} SECONDS!    \n";
        echo "===============================================================\n";
    }
}
