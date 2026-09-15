<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ComprehensiveMultiOutletSeeder extends Seeder
{
    public function run(): void
    {
        $startTime = microtime(true);
        echo "===============================================================\n";
        echo "   COMPREHENSIVE MULTI-OUTLET ENTERPRISE SEEDER STARTING       \n";
        echo "===============================================================\n";

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        $now = Carbon::now();
        $startDate = Carbon::now()->subMonths(6);

        // =====================================================================
        // STEP 1: OUTLETS CONFIGURATION (6 ACTIVE OUTLETS)
        // =====================================================================
        echo "\n[1/9] Configuring Multi-Outlet Master Data (6 Active Outlets)...\n";
        $outletsData = [
            [
                'id' => 1,
                'name' => 'Main Branch Outlet',
                'phone' => '+95 9 4500 1122',
                'email' => 'mainbranch@retailpos.com',
                'address' => 'No. 124, Pyay Road, Kamayut Township, Yangon',
                'manager_name' => 'U Kyaw Zayar',
                'logo' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'name' => 'Downtown Bistro Outlet',
                'phone' => '+95 9 4211 8899',
                'email' => 'downtown@retailpos.com',
                'address' => 'Level 2, Junction City, Pabedan Township, Yangon',
                'manager_name' => 'Daw Thida Lwin',
                'logo' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 5,
                'name' => 'Airport Express Outlet',
                'phone' => '+95 9 7900 5544',
                'email' => 'airport@retailpos.com',
                'address' => 'Terminal 1 Departure Hall, Yangon International Airport',
                'manager_name' => 'Ko Min Thu',
                'logo' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 7,
                'name' => 'Mall Food Court Outlet',
                'phone' => '+95 9 9600 3311',
                'email' => 'mallfoodcourt@retailpos.com',
                'address' => 'Food Court #4, Myanmar Plaza, Bahan Township, Yangon',
                'manager_name' => 'Daw Su Su Hlaing',
                'logo' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 8,
                'name' => 'Times City Premier Outlet',
                'phone' => '+95 9 7800 2233',
                'email' => 'timescity@retailpos.com',
                'address' => 'Building 3, Commercial Complex, Times City, Yangon',
                'manager_name' => 'U Zaw Zaw Aung',
                'logo' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 9,
                'name' => 'Mandalay 78th Outlet',
                'phone' => '+95 9 2500 7788',
                'email' => 'mandalay78@retailpos.com',
                'address' => '78th Street, Between 30th & 31st St, Chan Mya Thar Zi, Mandalay',
                'manager_name' => 'U Aung Myo Tun',
                'logo' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($outletsData as $oData) {
            DB::table('outlets')->updateOrInsert(['id' => $oData['id']], $oData);
        }

        $activeOutlets = DB::table('outlets')->whereIn('id', [1, 3, 5, 7, 8, 9])->get();
        echo "   -> " . $activeOutlets->count() . " Active Outlets Ready!\n";

        // =====================================================================
        // STEP 2: MASTER ENTITIES (CUSTOMERS, SUPPLIERS, EXPENSE CATEGORIES)
        // =====================================================================
        echo "\n[2/9] Seeding Auxiliary Master Entities (Customers, Suppliers, Expense Categories)...\n";
        
        // 50 Customers
        $cFirstNames = ['Aung', 'Kyaw', 'Zaw', 'Min', 'Thura', 'Hla', 'Myo', 'Ko', 'Thant', 'Tin', 'Nyi', 'Phyo', 'Zayar', 'Sai', 'Kaung', 'Hein', 'Wai', 'Lin', 'Nay', 'Soe'];
        $cLastNames = ['Lwin', 'Win', 'Myint', 'Naing', 'Tun', 'Thu', 'Swe', 'Htut', 'Kyaw', 'Oo', 'Zin', 'San', 'Lin', 'Aung', 'Maw', 'Yar', 'Han', 'Htet', 'Khant', 'Bo'];
        $cFemaleNames = ['Su Su', 'Hnin', 'May', 'Thida', 'Yin Yin', 'Aye Aye', 'Khin Khin', 'Phyu Phyu', 'Sandar', 'Mya Mya', 'Chit', 'Zin Mar', 'Ei Ei', 'Nan', 'Thazin'];
        $tiers = ['VIP Platinum', 'Gold VIP', 'Silver Regular', 'Standard Walk-in'];

        $customerBatch = [];
        for ($c = 1; $c <= 50; $c++) {
            if ($c === 1) {
                $name = 'Walk-in Customer';
                $phone = '0900000000';
            } elseif ($c % 2 === 0) {
                $name = $cFirstNames[$c % count($cFirstNames)] . ' ' . $cLastNames[($c * 3) % count($cLastNames)];
                $phone = '09' . rand(200000000, 999999999);
            } else {
                $name = 'Daw ' . $cFemaleNames[$c % count($cFemaleNames)] . ' ' . $cLastNames[($c * 2) % count($cLastNames)];
                $phone = '09' . rand(200000000, 999999999);
            }
            $customerBatch[] = [
                'id' => $c,
                'name' => $name,
                'phone' => $phone,
                'email' => 'customer' . $c . '@posretail.com',
                'tier' => $tiers[$c % count($tiers)],
                'reward_points' => rand(50, 2500),
                'total_spent' => round(rand(100, 2500) * 1.5, 2),
                'total_visits' => rand(2, 35),
                'avatar' => null,
                'status' => 'Active',
                'notes' => 'Registered retail regular client',
                'created_at' => $startDate,
                'updated_at' => $now,
            ];
        }
        foreach ($customerBatch as $cust) {
            DB::table('customers')->updateOrInsert(['id' => $cust['id']], $cust);
        }
        $customersList = DB::table('customers')->get();
        echo "   -> " . $customersList->count() . " Customers verified!\n";

        // 25 Suppliers
        $suppTypes = [
            'Apex Global Distribution Ltd', 'Pacific Tech Importers Co.', 'Sunlight Apparel Wholesale',
            'Golden Dragon Hardware Supplies', 'Yangon Beverage Depot', 'Myanmar Premium Foodstuffs',
            'Crown Electronics Logistics', 'Evergreen Living Products', 'Star Footwear Distribution',
            'Delta Packaging & Carton Solutions', 'Royal Silk & Garments Supply', 'Bagan Traditional Crafts Depot',
            'Mandalay Agri & Food Processing', 'United Pharma & Care Wholesale', 'Asia Pacific Sports Import',
            'Highland Coffee & Tea Traders', 'Summit Gadgets & Audio Ltd', 'Diamond Beauty & Cosmetics Co.',
            'Precision Tools & Instruments', 'Blue Ocean Oceanware Supply', 'Eastern Stationery Trading',
            'Global Fashion Hub', 'Zenith Smart Appliances Ltd', 'Metro General Wholesale', 'Pioneer Industrial Goods'
        ];
        $supplierBatch = [];
        foreach ($suppTypes as $sIdx => $sName) {
            $sId = $sIdx + 1;
            $supplierBatch[] = [
                'id' => $sId,
                'name' => $sName,
                'contact_person' => 'U Manager ' . $sId,
                'phone' => '09' . rand(200000000, 999999999),
                'email' => 'vendor' . $sId . '@supplypos.com',
                'category' => 'Wholesale Supplier',
                'country' => 'Myanmar',
                'city' => ($sId % 3 === 0) ? 'Mandalay' : 'Yangon',
                'address' => 'Industrial Zone #' . (($sId % 5) + 1) . ', Plot ' . $sId,
                'balance_due' => ($sId % 4 === 0) ? round(rand(500, 3000), 2) : 0.00,
                'payment_terms' => 'Net 30 Days',
                'status' => 'Active',
                'notes' => 'Registered official procurement supplier',
                'created_at' => $startDate,
                'updated_at' => $now,
            ];
        }
        foreach ($supplierBatch as $supp) {
            DB::table('suppliers')->updateOrInsert(['id' => $supp['id']], $supp);
        }
        $suppliersList = DB::table('suppliers')->get();
        echo "   -> " . $suppliersList->count() . " Suppliers verified!\n";

        // 10 Expense Categories
        $expCats = [
            ['id' => 1, 'name' => 'Store Rent & Lease', 'description' => 'Premises rental & lease fees'],
            ['id' => 2, 'name' => 'Electricity & Utilities', 'description' => 'Power, water and air conditioning utilities'],
            ['id' => 3, 'name' => 'Packaging & Bags', 'description' => 'Shopping bags, cartons, stickers & wrapping'],
            ['id' => 4, 'name' => 'Advertising & Marketing', 'description' => 'Social media ads, flyers & signage'],
            ['id' => 5, 'name' => 'Store Maintenance', 'description' => 'Lighting, shelf repairs & hardware maintenance'],
            ['id' => 6, 'name' => 'Staff Salaries & Welfare', 'description' => 'Store personnel allowances, meals & bonuses'],
            ['id' => 7, 'name' => 'Logistics & Inter-branch Freight', 'description' => 'Courier fees, transport & truck delivery'],
            ['id' => 8, 'name' => 'IT & POS Software Infrastructure', 'description' => 'Thermal receipt rolls, barcode ribbons & cloud licenses'],
            ['id' => 9, 'name' => 'Cleaning & Hygiene Supplies', 'description' => 'Disinfectants, towels & sanitizing materials'],
            ['id' => 10, 'name' => 'Taxes, Permits & City Licenses', 'description' => 'Municipal license renewals & retail taxes'],
        ];
        foreach ($expCats as $ec) {
            DB::table('expense_categories')->updateOrInsert(
                ['name' => $ec['name']],
                [
                    'code' => 'EXP-CAT-' . str_pad($ec['id'], 3, '0', STR_PAD_LEFT),
                    'description' => $ec['description'],
                    'status' => 'Active',
                    'created_at' => $startDate,
                    'updated_at' => $now,
                ]
            );
        }
        $expenseCategoriesList = DB::table('expense_categories')->get();
        echo "   -> " . $expenseCategoriesList->count() . " Expense Categories verified!\n";

        // =====================================================================
        // STEP 3: SEED 1,000 PRODUCTS & MULTI-OUTLET STOCK BALANCES
        // =====================================================================
        echo "\n[3/9] Seeding exactly 1,000 Products and 6,000 Multi-Outlet Stock Balances...\n";
        
        DB::table('product_unit_links')->truncate();
        DB::table('stock_balances')->truncate();
        DB::table('products')->truncate();

        $categories = DB::table('product_categories')->get();
        if ($categories->isEmpty()) {
            DB::table('product_categories')->insert([
                ['id' => 1, 'name' => 'Footwear & Shoes', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'name' => 'Mobile & Accessories', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 3, 'name' => 'Fashion & Apparel', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 4, 'name' => 'Audio & Gadgets', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 5, 'name' => 'Beverages & Packaged Food', 'created_at' => $now, 'updated_at' => $now],
            ]);
            $categories = DB::table('product_categories')->get();
        }

        $brands = DB::table('brands')->get();
        if ($brands->isEmpty()) {
            DB::table('brands')->insert([
                ['id' => 1, 'name' => 'Nike', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'name' => 'Adidas', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 3, 'name' => 'Apple', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 4, 'name' => 'Samsung', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 5, 'name' => 'Sony', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 6, 'name' => 'Zara', 'created_at' => $now, 'updated_at' => $now],
            ]);
            $brands = DB::table('brands')->get();
        }

        $units = DB::table('product_units')->get();
        if ($units->isEmpty()) {
            DB::table('product_units')->insert([
                ['id' => 1, 'name' => 'Piece', 'description' => 'Item piece', 'status' => 'Active', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'name' => 'Pair', 'description' => 'Item pair', 'status' => 'Active', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 3, 'name' => 'Box', 'description' => 'Box package', 'status' => 'Active', 'created_at' => $now, 'updated_at' => $now],
            ]);
            $units = DB::table('product_units')->get();
        }

        $prodAdjectives = [
            'Premium', 'Ultra-Slim', 'Pro', 'Classic', 'Sport', 'Wireless', 'Ergonomic',
            'Waterproof', 'Thermal', 'Fast-Charging', 'High-Fidelity', 'Vintage', 'Eco-Friendly',
            'Lightweight', 'Heavy-Duty', 'Breathable', 'Elite', 'Titanium', 'Magnetic', 'Compact'
        ];
        $prodNouns = [
            'Sneakers', 'Running Shoes', 'Leather Wallet', 'Backpack', 'Cotton T-Shirt',
            'Denim Jeans', 'Smart Watch', 'Bluetooth Earbuds', 'Noise Cancelling Headphones',
            'USB-C Cable', 'Phone Case', 'Power Bank 20000mAh', 'Stainless Water Bottle',
            'Polarized Sunglasses', 'Mechanical Keyboard', 'Wireless Gaming Mouse', 'Casual Jacket',
            'Oxford Shoes', 'Duffel Bag', 'Silk Necktie', 'Ceramic Coffee Mug', 'Fitness Band'
        ];
        $burmeseTerms = [
            'အဆင့်မြင့် ဖိနပ်', 'အပြေးဖိနပ်', 'စမတ်သားရေပိုက်ဆံအိတ်', 'ခရီးဆောင်ကျောပိုးအိတ်', 'တီရှပ်အင်္ကျီ',
            'ဂျင်းဘောင်းဘီရှည်', 'စမတ်လက်ပတ်နာရီ', 'ကြိုးမဲ့နားကြပ်', 'အသံထိန်းနားကြပ်ကြီး',
            'အားသွင်းကြိုးမြန်', 'ဖုန်းကာဗာ', 'ပါဝါဘဏ်', 'ရေဘူးစတီး',
            'နေကာမျက်မှန်', 'ကီးဘုတ်', 'ကြိုးမဲ့မောက်စ်', 'ပေါ့ပါးဂျာကင်',
            'သားရေဖိနပ်', 'ခရီးဆောင်အိတ်', 'လည်စည်းပုဝါ', 'ကြွေခွက်', 'အားကစားလက်ပတ်'
        ];
        $prodImages = [
            'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=400',
            'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=400',
            'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=400',
            'https://images.unsplash.com/photo-1572635196237-14b3f281503f?w=400',
            'https://images.unsplash.com/photo-1560343090-f0409e92791a?w=400',
            'https://images.unsplash.com/photo-1583394838336-acd977736f90?w=400',
            'https://images.unsplash.com/photo-1526170375885-4d8ecf77b99f?w=400',
            'https://images.unsplash.com/photo-1491553895911-0055eca6402d?w=400',
            'https://images.unsplash.com/photo-1608231387042-66d1773070a5?w=400',
            'https://images.unsplash.com/photo-1578932750294-f5075e85f44a?w=400',
        ];

        $productRecords = [];
        $stockBalances = [];
        $unitLinks = [];

        $pieceUnit = $units->firstWhere('name', 'Piece') ?? $units->first();

        // Pre-compute outlet stocks per product to maintain exact stock_quantity sum
        for ($p = 1; $p <= 1000; $p++) {
            $cat = $categories[$p % $categories->count()];
            $brand = $brands[$p % $brands->count()];
            $unit = ($p === 1) ? $pieceUnit : $units[$p % $units->count()];
            $supp = $suppliersList[$p % $suppliersList->count()];

            $adj = $prodAdjectives[$p % count($prodAdjectives)];
            $noun = $prodNouns[$p % count($prodNouns)];
            $name = "$adj $noun #$p";
            $altName = $burmeseTerms[$p % count($burmeseTerms)] . " (#$p)";
            $code = 'PRD-' . str_pad($p, 5, '0', STR_PAD_LEFT);
            $sku = 'SKU-' . str_pad($p, 5, '0', STR_PAD_LEFT);
            $barcode = '893' . str_pad($p, 10, '0', STR_PAD_LEFT);

            $cost = round(15.00 + (($p * 7) % 250) * 1.25, 2);
            $retail = round($cost * 1.45, 2);
            $wholesale = round($cost * 1.20, 2);
            $img = $prodImages[$p % count($prodImages)];

            // Multi-outlet distribution: assign stock to each of the 6 active outlets
            $totalStock = 0;
            foreach ($activeOutlets as $outlet) {
                // Generates between 10 and 120 stock units per outlet
                $outletStock = 10 + (($p * 13 + $outlet->id * 17) % 95);
                $totalStock += $outletStock;

                $stockBalances[] = [
                    'warehouse_id' => $outlet->id,
                    'product_id' => $p,
                    'product_variant_id' => null,
                    'quantity' => $outletStock,
                    'created_at' => $startDate,
                    'updated_at' => $now,
                ];
            }

            $productRecords[] = [
                'id' => $p,
                'uuid' => (string)Str::uuid(),
                'code' => $code,
                'sku' => $sku,
                'barcode' => $barcode,
                'barcode_symbology' => 'CODE128',
                'category_id' => $cat->id,
                'brand_id' => $brand->id,
                'base_unit_id' => $unit->id,
                'sale_unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'name' => $name,
                'brand_name' => $brand->name,
                'supplier_id' => $supp->id,
                'supplier_name' => $supp->name,
                'alternative_name' => $altName,
                'category_name' => $cat->name,
                'sub_category_name' => null,
                'sub_category_id' => null,
                'unit_name' => $unit->name,
                'purchase_unit' => $unit->name,
                'sale_unit' => $unit->name,
                'purchase_price' => $cost,
                'cost_price' => $cost,
                'retail_price' => $retail,
                'retail_prices' => null,
                'selling_price' => $retail,
                'wholesale_price' => $wholesale,
                'wholesale_prices' => null,
                'stock_quantity' => $totalStock,
                'alert_quantity' => 10,
                'stock_alert' => 10,
                'has_variants' => false,
                'order_tax' => 0.0,
                'tax_type' => 1,
                'low_qty' => 5.0,
                'dine_in_price' => $retail,
                'take_away_price' => $retail,
                'delivery_price' => round($retail * 1.05, 2),
                'image_url' => $img,
                'description' => "Official high quality retail item: $name. Guaranteed authenticity with enterprise barcode $barcode.",
                'note' => 'Enterprise standard multi-outlet inventory catalogue item.',
                'status' => 'Active',
                'is_active' => true,
                'created_by' => 1,
                'updated_by' => 1,
                'added_by' => 'Super Admin',
                'created_at' => $startDate,
                'updated_at' => $now,
            ];

            // Product Unit Links
            $unitLinks[] = [
                'product_id' => $p,
                'product_variant_id' => null,
                'unit_type' => 'sale',
                'unit_id' => $unit->id,
                'unit_name' => $unit->name,
                'conversion_rate' => 1.0,
                'is_default' => true,
                'is_active' => true,
                'created_at' => $startDate,
                'updated_at' => $now,
            ];
            $unitLinks[] = [
                'product_id' => $p,
                'product_variant_id' => null,
                'unit_type' => 'purchase',
                'unit_id' => $unit->id,
                'unit_name' => $unit->name,
                'conversion_rate' => 1.0,
                'is_default' => true,
                'is_active' => true,
                'created_at' => $startDate,
                'updated_at' => $now,
            ];

            if ($unit->name !== $pieceUnit->name) {
                $unitLinks[] = [
                    'product_id' => $p,
                    'product_variant_id' => null,
                    'unit_type' => 'sale',
                    'unit_id' => $pieceUnit->id,
                    'unit_name' => $pieceUnit->name,
                    'conversion_rate' => 1.0,
                    'is_default' => false,
                    'is_active' => true,
                    'created_at' => $startDate,
                    'updated_at' => $now,
                ];
                $unitLinks[] = [
                    'product_id' => $p,
                    'product_variant_id' => null,
                    'unit_type' => 'purchase',
                    'unit_id' => $pieceUnit->id,
                    'unit_name' => $pieceUnit->name,
                    'conversion_rate' => 1.0,
                    'is_default' => false,
                    'is_active' => true,
                    'created_at' => $startDate,
                    'updated_at' => $now,
                ];
            }
        }

        // Chunked insertion of 1,000 products
        foreach (array_chunk($productRecords, 500) as $chunk) {
            DB::table('products')->insert($chunk);
        }
        echo "   -> 1,000 Products inserted!\n";

        // Chunked insertion of 6,000 stock balances across 6 outlets
        foreach (array_chunk($stockBalances, 1000) as $chunk) {
            DB::table('stock_balances')->insert($chunk);
        }
        echo "   -> 6,000 Stock Balances inserted across 6 active outlets!\n";

        // Chunked insertion of 2,000 product unit links
        foreach (array_chunk($unitLinks, 1000) as $chunk) {
            DB::table('product_unit_links')->insert($chunk);
        }
        echo "   -> 2,000 Product Unit Links inserted!\n";

        // Reload products cache in memory
        $allProducts = DB::table('products')->get(['id', 'code', 'name', 'cost_price', 'selling_price', 'unit_name']);

        // =====================================================================
        // STEP 4: SEED 1,000 PURCHASES & PURCHASE ITEMS
        // =====================================================================
        echo "\n[4/9] Seeding exactly 1,000 Purchases and Purchase Items across Outlets...\n";
        DB::table('purchase_items')->truncate();
        DB::table('purchases')->truncate();

        $purchasesBatch = [];
        $purchaseItemsBatch = [];

        for ($po = 1; $po <= 1000; $po++) {
            $poId = ($po === 999) ? 1001 : $po;
            $ref = 'PO-2026-' . str_pad($po, 5, '0', STR_PAD_LEFT);
            $supp = $suppliersList[$po % $suppliersList->count()];
            $outlet = $activeOutlets[$po % $activeOutlets->count()];
            $date = $startDate->copy()->addDays(($po * 179) % 180)->addHours(($po * 7) % 24)->format('Y-m-d');
            
            $statusRoll = $po % 100;
            $status = ($statusRoll < 80) ? 'Received' : (($statusRoll < 92) ? 'Pending' : 'Ordered');

            // 1 to 3 items per PO
            $itemCount = 1 + ($po % 3);
            $poTotal = 0.0;
            $itemsForThisPo = [];

            for ($itemIdx = 0; $itemIdx < $itemCount; $itemIdx++) {
                $prod = $allProducts[($po * 3 + $itemIdx * 7) % $allProducts->count()];
                $qty = 10 + (($po * 5 + $itemIdx * 11) % 40);
                $cost = (float)$prod->cost_price;
                $subtotal = round($qty * $cost, 2);
                $poTotal += $subtotal;

                $itemsForThisPo[] = [
                    'purchase_id' => $poId,
                    'product_id' => $prod->id,
                    'product_variant_id' => null,
                    'unit_id' => null,
                    'unit_name' => $prod->unit_name ?: 'Piece',
                    'conversion_rate' => 1.0,
                    'product_code' => $prod->code,
                    'product_name' => $prod->name,
                    'unit_cost' => $cost,
                    'base_unit_cost' => $cost,
                    'quantity' => (float)$qty,
                    'base_quantity' => (float)$qty,
                    'tax' => 0.0,
                    'discount' => 0.0,
                    'subtotal' => $subtotal,
                    'created_at' => $date . ' 10:00:00',
                    'updated_at' => $date . ' 10:00:00',
                ];
            }

            $payRoll = $po % 10;
            if ($status === 'Received') {
                if ($payRoll < 8) {
                    $paymentStatus = 'Paid';
                    $paidAmount = $poTotal;
                    $dueAmount = 0.0;
                } else {
                    $paymentStatus = 'Partial';
                    $paidAmount = round($poTotal * 0.5, 2);
                    $dueAmount = round($poTotal - $paidAmount, 2);
                }
            } else {
                $paymentStatus = 'Unpaid';
                $paidAmount = 0.0;
                $dueAmount = $poTotal;
            }

            $payTypes = ['Bank Transfer', 'Cash', 'Credit Term 30 Days', 'Cheque'];
            $payType = $payTypes[$po % count($payTypes)];

            $purchasesBatch[] = [
                'id' => $poId,
                'reference_no' => $ref,
                'supplier_id' => $supp->id,
                'supplier_name' => $supp->name,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => $date,
                'status' => $status,
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'discount' => 0.0,
                'shipping' => 0.0,
                'grand_total' => $poTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
                'payment_type' => $payType,
                'notes' => 'Commercial stock replenishment consignment for ' . $outlet->name,
                'created_by' => 'Super Admin',
                'user_id' => 1,
                'user_name' => 'Super Admin',
                'user_role' => 'Super Admin',
                'created_at' => $date . ' 10:00:00',
                'updated_at' => $date . ' 10:00:00',
            ];

            foreach ($itemsForThisPo as $item) {
                $purchaseItemsBatch[] = $item;
            }
        }

        foreach (array_chunk($purchasesBatch, 500) as $chunk) {
            DB::table('purchases')->insert($chunk);
        }
        foreach (array_chunk($purchaseItemsBatch, 1000) as $chunk) {
            DB::table('purchase_items')->insert($chunk);
        }
        echo "   -> 1,000 Purchases and " . count($purchaseItemsBatch) . " Purchase Items inserted!\n";

        // =====================================================================
        // STEP 5: SEED 1,000 PURCHASE RETURNS
        // =====================================================================
        echo "\n[5/9] Seeding exactly 1,000 Purchase Returns linked to Multi-Outlet Orders...\n";
        DB::table('purchase_returns')->truncate();

        $purchaseReturnsBatch = [];
        for ($pr = 1; $pr <= 1000; $pr++) {
            $ref = 'PR-2026-' . str_pad($pr, 5, '0', STR_PAD_LEFT);
            $poRef = 'PO-2026-' . str_pad($pr, 5, '0', STR_PAD_LEFT);
            $supp = $suppliersList[$pr % $suppliersList->count()];
            $outlet = $activeOutlets[$pr % $activeOutlets->count()];
            $date = $startDate->copy()->addDays(($pr * 179) % 180 + 2)->format('Y-m-d');
            
            $status = ($pr % 10 === 0) ? 'Pending' : 'Completed';
            $retAmount = round(25.00 + (($pr * 13) % 250) * 1.5, 2);
            $isPaid = ($status === 'Completed');
            $paid = $isPaid ? $retAmount : 0.0;
            $due = round($retAmount - $paid, 2);

            $purchaseReturnsBatch[] = [
                'id' => $pr,
                'reference_no' => $ref,
                'purchase_reference_no' => $poRef,
                'supplier_id' => $supp->id,
                'supplier_name' => $supp->name,
                'warehouse_id' => $outlet->id,
                'outlet_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => $date,
                'status' => $status,
                'grand_total' => $retAmount,
                'paid_amount' => $paid,
                'due_amount' => $due,
                'payment_status' => $isPaid ? 'Paid' : 'Unpaid',
                'notes' => 'Supplier return dispatched from ' . $outlet->name . ' due to packaging damage or SKU discrepancy.',
                'created_by' => 'Super Admin',
                'user_id' => 1,
                'user_name' => 'Super Admin',
                'user_role' => 'Super Admin',
                'created_at' => $date . ' 14:00:00',
                'updated_at' => $date . ' 14:00:00',
            ];
        }

        foreach (array_chunk($purchaseReturnsBatch, 500) as $chunk) {
            DB::table('purchase_returns')->insert($chunk);
        }
        echo "   -> 1,000 Purchase Returns inserted!\n";

        // =====================================================================
        // STEP 6: SEED 1,000 SALES, SALE ITEMS & PAYMENTS
        // =====================================================================
        echo "\n[6/9] Seeding exactly 1,000 Sales, Sale Items, and Sale Payments...\n";
        DB::table('sale_payments')->truncate();
        DB::table('sale_items')->truncate();
        DB::table('sales')->truncate();

        $salesBatch = [];
        $saleItemsBatch = [];
        $salePaymentsBatch = [];

        $payModes = ['Cash', 'KBZPay', 'WavePay', 'Bank Transfer', 'Card'];

        for ($s = 1; $s <= 1000; $s++) {
            $ref = 'SA-' . str_pad(1200000 + $s, 7, '0', STR_PAD_LEFT);
            $customer = $customersList[$s % $customersList->count()];
            $outlet = $activeOutlets[$s % $activeOutlets->count()];
            $dateTime = $startDate->copy()->addDays(($s * 179) % 180)->addHours(8 + ($s % 14))->addMinutes(($s * 11) % 60);
            $dateStr = $dateTime->format('Y-m-d H:i:s');

            $status = ($s % 25 === 0) ? 'Pending' : 'Completed';
            $payType = $payModes[$s % count($payModes)];

            // 1 to 3 items per sale
            $itemCount = 1 + ($s % 3);
            $saleSubtotal = 0.0;
            $itemsForThisSale = [];

            for ($itemIdx = 0; $itemIdx < $itemCount; $itemIdx++) {
                $prod = $allProducts[($s * 5 + $itemIdx * 9) % $allProducts->count()];
                $qty = 1 + (($s * 3 + $itemIdx * 4) % 5);
                $unitPrice = (float)$prod->selling_price;
                $lineTotal = round($qty * $unitPrice, 2);
                $saleSubtotal += $lineTotal;

                $itemsForThisSale[] = [
                    'sale_id' => $s,
                    'product_id' => $prod->id,
                    'product_variant_id' => null,
                    'unit_id' => null,
                    'unit_name' => $prod->unit_name ?: 'Piece',
                    'conversion_rate' => 1.0,
                    'product_code' => $prod->code,
                    'product_name' => $prod->name,
                    'unit_price' => $unitPrice,
                    'base_unit_price' => $unitPrice,
                    'quantity' => (float)$qty,
                    'base_quantity' => (float)$qty,
                    'tax' => 0.0,
                    'discount' => 0.0,
                    'subtotal' => $lineTotal,
                    'created_at' => $dateStr,
                    'updated_at' => $dateStr,
                ];
            }

            $payRoll = $s % 20;
            if ($status === 'Completed') {
                if ($payRoll < 18) {
                    $paymentStatus = 'Paid';
                    $paidAmount = $saleSubtotal;
                    $dueAmount = 0.0;
                } else {
                    $paymentStatus = 'Partial';
                    $paidAmount = round($saleSubtotal * 0.6, 2);
                    $dueAmount = round($saleSubtotal - $paidAmount, 2);
                }
            } else {
                $paymentStatus = 'Unpaid';
                $paidAmount = 0.0;
                $dueAmount = $saleSubtotal;
            }

            $salesBatch[] = [
                'id' => $s,
                'reference_no' => $ref,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => $dateStr,
                'status' => $status,
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'discount' => 0.0,
                'shipping' => 0.0,
                'subtotal' => $saleSubtotal,
                'grand_total' => $saleSubtotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'change_amount' => 0.0,
                'payment_status' => $paymentStatus,
                'payment_type' => $payType,
                'notes' => 'POS transaction processed at counter in ' . $outlet->name,
                'pos_flag' => true,
                'created_by' => 'Super Admin',
                'user_id' => 1,
                'user_name' => 'Super Admin',
                'user_role' => 'Super Admin',
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            foreach ($itemsForThisSale as $item) {
                $saleItemsBatch[] = $item;
            }

            if ($paidAmount > 0) {
                $salePaymentsBatch[] = [
                    'sale_id' => $s,
                    'reference_no' => 'PAY-' . str_pad($s, 6, '0', STR_PAD_LEFT),
                    'payment_date' => $dateStr,
                    'payment_type' => $payType,
                    'amount' => $paidAmount,
                    'received_amount' => $paidAmount,
                    'change_amount' => 0.0,
                    'notes' => 'POS Payment via ' . $payType,
                    'created_by' => 'Super Admin',
                    'created_at' => $dateStr,
                    'updated_at' => $dateStr,
                ];
            }
        }

        foreach (array_chunk($salesBatch, 500) as $chunk) {
            DB::table('sales')->insert($chunk);
        }
        foreach (array_chunk($saleItemsBatch, 1000) as $chunk) {
            DB::table('sale_items')->insert($chunk);
        }
        foreach (array_chunk($salePaymentsBatch, 1000) as $chunk) {
            DB::table('sale_payments')->insert($chunk);
        }
        echo "   -> 1,000 Sales, " . count($saleItemsBatch) . " Sale Items, and " . count($salePaymentsBatch) . " Sale Payments inserted!\n";

        // =====================================================================
        // STEP 7: SEED 1,000 SALE RETURNS
        // =====================================================================
        echo "\n[7/9] Seeding exactly 1,000 Sale Returns linked to Sales & Outlets...\n";
        DB::table('sale_returns')->truncate();

        $saleReturnsBatch = [];
        for ($sr = 1; $sr <= 1000; $sr++) {
            $ref = 'SR-2026-' . str_pad($sr, 5, '0', STR_PAD_LEFT);
            $saleRef = 'SA-' . str_pad(1200000 + $sr, 7, '0', STR_PAD_LEFT);
            $customer = $customersList[$sr % $customersList->count()];
            $outlet = $activeOutlets[$sr % $activeOutlets->count()];
            $date = $startDate->copy()->addDays(($sr * 179) % 180 + 1)->format('Y-m-d');

            $status = ($sr % 12 === 0) ? 'Pending' : 'Completed';
            $retAmount = round(15.00 + (($sr * 11) % 180) * 1.35, 2);
            $isPaid = ($status === 'Completed');
            $paid = $isPaid ? $retAmount : 0.0;
            $due = round($retAmount - $paid, 2);

            $saleReturnsBatch[] = [
                'id' => $sr,
                'reference_no' => $ref,
                'sale_reference_no' => $saleRef,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => $date,
                'status' => $status,
                'grand_total' => $retAmount,
                'paid_amount' => $paid,
                'due_amount' => $due,
                'payment_status' => $isPaid ? 'Paid' : 'Pending',
                'notes' => 'Customer return request processed at ' . $outlet->name . ' counter.',
                'created_by' => 'Super Admin',
                'created_at' => $date . ' 16:30:00',
                'updated_at' => $date . ' 16:30:00',
            ];
        }

        foreach (array_chunk($saleReturnsBatch, 500) as $chunk) {
            DB::table('sale_returns')->insert($chunk);
        }
        echo "   -> 1,000 Sale Returns inserted!\n";

        // =====================================================================
        // STEP 8: SEED 1,000 EXPENSES
        // =====================================================================
        echo "\n[8/9] Seeding exactly 1,000 Expenses across Outlets & Categories...\n";
        DB::table('expenses')->truncate();

        $expenseTitles = [
            'Monthly Branch Electricity & AirCon Utility Bill',
            'POS Terminal Thermal Paper Rolls & Receipt Ribbons',
            'Store Cleaning Disinfectants, Mops & Sanitizing Supplies',
            'High-Speed Fiber Business Internet Subscription',
            'Social Media Promotional Ad Campaign & Local Billboard',
            'Inter-Branch Warehouse Freight & Logistics Van Courier',
            'Store Interior Shelf LED Lighting & Fixture Repair',
            'Staff Meal Subsidies, Drinking Water & Team Welfare',
            'Eco-Friendly Shopping Bags, Poly Bags & Seal Tapes',
            'Municipal Retail Business Operating Permit Renewal',
            'POS Barcode Scanner & Cash Drawer Servicing',
            'Emergency Fire Extinguisher Inspection & Maintenance',
        ];

        $expensesBatch = [];
        for ($e = 1; $e <= 1000; $e++) {
            $ref = 'EXP-2026-' . str_pad($e, 5, '0', STR_PAD_LEFT);
            $outlet = $activeOutlets[$e % $activeOutlets->count()];
            $cat = $expenseCategoriesList[$e % $expenseCategoriesList->count()];
            $date = $startDate->copy()->addDays(($e * 179) % 180)->format('Y-m-d');
            $title = $expenseTitles[$e % count($expenseTitles)];
            $amt = round(35.00 + (($e * 19) % 350) * 1.85, 2);

            $expensesBatch[] = [
                'id' => $e,
                'reference_no' => $ref,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'expense_category_id' => $cat->id,
                'expense_category_name' => $cat->name,
                'title' => $title,
                'amount' => $amt,
                'date' => $date,
                'details' => 'Authorized branch operating expense voucher for ' . $outlet->name,
                'created_by' => 'Super Admin',
                'user_id' => 1,
                'user_name' => 'Super Admin',
                'user_role' => 'Super Admin',
                'created_at' => $date . ' 11:00:00',
                'updated_at' => $date . ' 11:00:00',
            ];
        }

        foreach (array_chunk($expensesBatch, 500) as $chunk) {
            DB::table('expenses')->insert($chunk);
        }
        echo "   -> 1,000 Expenses inserted!\n";

        // =====================================================================
        // STEP 9: SEED 1,000 STOCK TRANSFERS & 1,000 STOCK ADJUSTMENTS
        // =====================================================================
        echo "\n[9/9] Seeding 1,000 Inter-Outlet Stock Transfers and 1,000 Stock Adjustments...\n";

        // A. 1,000 Stock Transfers
        DB::table('stock_transfer_items')->truncate();
        DB::table('stock_transfers')->truncate();

        $transfersBatch = [];
        $transferItemsBatch = [];

        $outletCount = $activeOutlets->count();
        for ($tr = 1; $tr <= 1000; $tr++) {
            $ref = 'TR-2026-' . str_pad($tr, 5, '0', STR_PAD_LEFT);
            
            // STRICT MULTI-OUTLET LOGIC: from_outlet != to_outlet
            $fromIdx = $tr % $outletCount;
            $toIdx = ($fromIdx + 1 + ($tr % ($outletCount - 1))) % $outletCount;
            
            $fromOutlet = $activeOutlets[$fromIdx];
            $toOutlet = $activeOutlets[$toIdx];

            $date = $startDate->copy()->addDays(($tr * 179) % 180)->format('Y-m-d');
            $time = sprintf('%02d:%02d:%02d', 8 + ($tr % 10), ($tr * 7) % 60, ($tr * 13) % 60);
            
            $statusRoll = $tr % 10;
            $status = ($statusRoll < 8) ? 'Completed' : (($statusRoll < 9) ? 'Sent' : 'Pending');
            $shippingCharge = round(($tr % 5) * 3.5, 2);

            // 1 to 3 transfer items
            $itemCount = 1 + ($tr % 3);
            $totalQty = 0.0;
            $itemsForTransfer = [];

            for ($tIdx = 0; $tIdx < $itemCount; $tIdx++) {
                $prod = $allProducts[($tr * 7 + $tIdx * 5) % $allProducts->count()];
                $qty = 5.0 + (($tr * 3 + $tIdx * 7) % 25);
                $cost = (float)$prod->cost_price;
                $lineSubtotal = round($qty * $cost, 2);
                $totalQty += $qty;

                $itemsForTransfer[] = [
                    'stock_transfer_id' => $tr,
                    'product_id' => $prod->id,
                    'product_variant_id' => null,
                    'product_code' => $prod->code,
                    'product_name' => $prod->name,
                    'variant_name' => null,
                    'unit_id' => null,
                    'unit_name' => $prod->unit_name ?: 'Piece',
                    'conversion_rate' => 1.0,
                    'quantity' => $qty,
                    'base_quantity' => $qty,
                    'unit_cost' => $cost,
                    'base_unit_cost' => $cost,
                    'subtotal' => $lineSubtotal,
                    'created_at' => $date . ' ' . $time,
                    'updated_at' => $date . ' ' . $time,
                ];
            }

            $transfersBatch[] = [
                'id' => $tr,
                'reference_no' => $ref,
                'from_warehouse' => $fromOutlet->name,
                'from_warehouse_id' => $fromOutlet->id,
                'to_warehouse' => $toOutlet->name,
                'to_warehouse_id' => $toOutlet->id,
                'date' => $date,
                'transfer_time' => $time,
                'items_count' => $itemCount,
                'total_quantity' => $totalQty,
                'shipping_charge' => $shippingCharge,
                'status' => $status,
                'notes' => 'Stock rebalancing dispatch from ' . $fromOutlet->name . ' to ' . $toOutlet->name,
                'created_by' => 'Super Admin',
                'user_id' => 1,
                'user_name' => 'Super Admin',
                'user_role' => 'Super Admin',
                'created_at' => $date . ' ' . $time,
                'updated_at' => $date . ' ' . $time,
            ];

            foreach ($itemsForTransfer as $it) {
                $transferItemsBatch[] = $it;
            }
        }

        foreach (array_chunk($transfersBatch, 500) as $chunk) {
            DB::table('stock_transfers')->insert($chunk);
        }
        foreach (array_chunk($transferItemsBatch, 1000) as $chunk) {
            DB::table('stock_transfer_items')->insert($chunk);
        }
        echo "   -> 1,000 Stock Transfers and " . count($transferItemsBatch) . " Transfer Items inserted!\n";

        // B. 1,000 Stock Adjustments
        DB::table('stock_adjustment_items')->truncate();
        DB::table('stock_adjustments')->truncate();

        $adjustmentsBatch = [];
        $adjustmentItemsBatch = [];

        for ($ad = 1; $ad <= 1000; $ad++) {
            $ref = 'AD-2026-' . str_pad($ad, 5, '0', STR_PAD_LEFT);
            $outlet = $activeOutlets[$ad % $outletCount];
            $date = $startDate->copy()->addDays(($ad * 179) % 180)->format('Y-m-d');
            $time = sprintf('%02d:%02d:%02d', 9 + ($ad % 10), ($ad * 11) % 60, ($ad * 17) % 60);

            $type = ($ad % 2 === 0) ? 'Addition' : 'Subtraction';
            $itemCount = 1 + ($ad % 3);
            $totalQty = 0.0;
            $itemsForAdjustment = [];

            for ($aIdx = 0; $aIdx < $itemCount; $aIdx++) {
                $prod = $allProducts[($ad * 9 + $aIdx * 4) % $allProducts->count()];
                $qty = 1.0 + (($ad * 2 + $aIdx * 3) % 8);
                $cost = (float)$prod->cost_price;
                $lineSubtotal = round($qty * $cost, 2);
                $totalQty += $qty;

                $itemsForAdjustment[] = [
                    'stock_adjustment_id' => $ad,
                    'product_id' => $prod->id,
                    'product_variant_id' => null,
                    'product_code' => $prod->code,
                    'product_name' => $prod->name,
                    'variant_name' => null,
                    'unit_id' => null,
                    'unit_name' => $prod->unit_name ?: 'Piece',
                    'conversion_rate' => 1.0,
                    'quantity' => $qty,
                    'base_quantity' => $qty,
                    'type' => $type,
                    'unit_cost' => $cost,
                    'base_unit_cost' => $cost,
                    'subtotal' => $lineSubtotal,
                    'created_at' => $date . ' ' . $time,
                    'updated_at' => $date . ' ' . $time,
                ];
            }

            $note = ($type === 'Addition')
                ? 'Inventory surplus discovered during periodic physical audit recount at ' . $outlet->name
                : 'Damaged packaging / expiry stock write-off clearance at ' . $outlet->name;

            $adjustmentsBatch[] = [
                'id' => $ad,
                'reference_no' => $ref,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => $date,
                'total_products' => $itemCount,
                'total_quantity' => $totalQty,
                'type' => $type,
                'notes' => $note,
                'user_id' => 1,
                'created_by' => 'Super Admin',
                'user_name' => 'Super Admin',
                'user_role' => 'Super Admin',
                'created_at' => $date . ' ' . $time,
                'updated_at' => $date . ' ' . $time,
            ];

            foreach ($itemsForAdjustment as $ait) {
                $adjustmentItemsBatch[] = $ait;
            }
        }

        foreach (array_chunk($adjustmentsBatch, 500) as $chunk) {
            DB::table('stock_adjustments')->insert($chunk);
        }
        foreach (array_chunk($adjustmentItemsBatch, 1000) as $chunk) {
            DB::table('stock_adjustment_items')->insert($chunk);
        }
        echo "   -> 1,000 Stock Adjustments and " . count($adjustmentItemsBatch) . " Adjustment Items inserted!\n";

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $duration = round(microtime(true) - $startTime, 2);
        echo "\n===============================================================\n";
        echo "   ALL 8,000 TARGET RECORDS SEEDED IN {$duration} SECONDS!    \n";
        echo "===============================================================\n";
    }
}
