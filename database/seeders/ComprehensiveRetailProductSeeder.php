<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\SaleUnit;
use App\Models\PurchaseUnit;
use App\Models\Brand;
use App\Models\Outlet;
use App\Models\StockBalance;
use App\Models\InventoryBatch;
use App\Models\Supplier;

class ComprehensiveRetailProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command?->info('Seeding Comprehensive Retail Products, Categories, Brands, and Inventory...');

        // 1. Ensure Default Supplier exists
        $supplier = Supplier::firstOrCreate(
            ['name' => 'Walk-in Supplier'],
            ['phone' => '09-12345678', 'email' => 'supplier@retailpos.com', 'is_active' => true]
        );

        // 2. Outlets / Warehouses to seed stock into
        $outlets = Outlet::all();
        if ($outlets->isEmpty()) {
            $defaultWh = Outlet::create([
                'name' => 'defaultwarehouse',
                'phone' => '+95 9 1234 5678',
                'email' => 'warehouse@retailpos.com',
                'address' => 'Yangon, Myanmar',
                'is_active' => true,
            ]);
            $shop1 = Outlet::create([
                'name' => 'ဆိုင် (၁)',
                'phone' => '09898394839',
                'email' => 'shop1@retailpos.com',
                'address' => 'Yangon Downtown',
                'is_active' => true,
            ]);
            $outlets = collect([$defaultWh, $shop1]);
        }

        // 3. Product Units
        $unitsData = [
            ['name' => 'ခု', 'description' => 'Piece / Item'],
            ['name' => 'ဘူး', 'description' => 'Bottle / Can / Jar'],
            ['name' => 'ထည်', 'description' => 'Garment / Piece (Fashion)'],
            ['name' => 'ထုပ်', 'description' => 'Pack / Packet / Sachet'],
            ['name' => 'ကတ်', 'description' => 'Strip / Box / Card'],
        ];

        $unitsMap = [];
        foreach ($unitsData as $u) {
            $unitModel = ProductUnit::firstOrCreate(
                ['name' => $u['name']],
                ['description' => $u['description'], 'status' => 'Active']
            );
            $unitsMap[$u['name']] = $unitModel;

            SaleUnit::firstOrCreate(
                ['name' => $u['name']],
                ['base_unit_id' => $unitModel->id, 'base_unit_name' => $unitModel->name, 'status' => 'Active']
            );

            PurchaseUnit::firstOrCreate(
                ['name' => $u['name']],
                ['base_unit_id' => $unitModel->id, 'base_unit_name' => $unitModel->name, 'status' => 'Active']
            );
        }

        // 4. Product Categories
        $categoriesData = [
            [
                'name' => '📱 ဖုန်းနှင့် အီလက်ထရောနစ်',
                'description' => 'Mobile Phones, Gadgets & Accessories',
            ],
            [
                'name' => '👗 အဝတ်အထည်နှင့် ဖက်ရှင်',
                'description' => 'Clothing, Apparels & Fashion Accessories',
            ],
            [
                'name' => '💄 အလှကုန်နှင့် အသားအရေထိန်းသိမ်းမှု',
                'description' => 'Beauty, Cosmetics & Personal Skincare',
            ],
            [
                'name' => '☕ စားသောက်ကုန်နှင့် အဖျော်ယမကာ',
                'description' => 'Food, Snacks, Coffee, Tea & Beverages',
            ],
            [
                'name' => '🏠 လူသုံးကုန်နှင့် အိမ်သုံးပစ္စည်း',
                'description' => 'Daily Household Essentials & Hygiene Products',
            ],
        ];

        $categoriesMap = [];
        foreach ($categoriesData as $c) {
            $cat = ProductCategory::firstOrCreate(
                ['name' => $c['name']],
                ['description' => $c['description'], 'status' => 'Active']
            );
            $categoriesMap[$c['name']] = $cat;
        }

        // Also alias the legacy category if exists
        $legacyCosmetics = ProductCategory::where('name', 'အလှကုန်')->first();
        if ($legacyCosmetics) {
            $legacyCosmetics->update(['status' => 'Active']);
        }

        // 5. Brands
        $brandsData = [
            ['name' => 'Remax', 'code' => 'BR-RMX', 'description' => 'Remax Smart Accessories & Gadgets'],
            ['name' => 'Xiaomi', 'code' => 'BR-XMI', 'description' => 'Xiaomi Smart Electronics & Lifestyle'],
            ['name' => 'Samsung', 'code' => 'BR-SAM', 'description' => 'Samsung Electronics'],
            ['name' => 'Uniqlo', 'code' => 'BR-UNQ', 'description' => 'Uniqlo Everyday Modern Casual Wear'],
            ['name' => 'Giordano', 'code' => 'BR-GIO', 'description' => 'Giordano Classic Apparels & Jeans'],
            ['name' => 'CeraVe', 'code' => 'BR-CRV', 'description' => 'Dermatologist Recommended Skincare'],
            ['name' => 'Bella', 'code' => 'BR-BEL', 'description' => 'Bella Cosmetics Myanmar'],
            ['name' => 'Royal Myanmar', 'code' => 'BR-RYL', 'description' => 'Royal Myanmar Traditional Teamix'],
            ['name' => 'Premier', 'code' => 'BR-PRM', 'description' => 'Premier Coffee & Food Products'],
            ['name' => 'Dettol', 'code' => 'BR-DTL', 'description' => 'Dettol Disinfection & Hygiene'],
            ['name' => 'Sunlight', 'code' => 'BR-SUN', 'description' => 'Sunlight Cleaning & Dishwash Essentials'],
            ['name' => 'imax', 'code' => 'BR-IMX', 'description' => 'imax Footwear & Sports Shoes'],
        ];

        $brandsMap = [];
        foreach ($brandsData as $b) {
            $brand = Brand::firstOrCreate(
                ['name' => $b['name']],
                ['code' => $b['code'], 'description' => $b['description'], 'status' => 'Active']
            );
            $brandsMap[$b['name']] = $brand;
        }

        // 6. Comprehensive Product Catalog Definition
        $catalog = [
            // ==========================================
            // Category: 📱 ဖုန်းနှင့် အီလက်ထရောနစ်
            // ==========================================
            [
                'code' => 'ELEC-001',
                'sku' => 'ELEC-001',
                'barcode' => '893450000101',
                'name' => 'Remax 20000mAh Fast Charging Powerbank (RPP-291)',
                'alternative_name' => 'Remax 20000mAh Powerbank',
                'category' => '📱 ဖုန်းနှင့် အီလက်ထရောနစ်',
                'brand' => 'Remax',
                'unit' => 'ခု',
                'cost_price' => 28000,
                'retail_price' => 42000,
                'bot_price' => 39000,
                'stock' => 50,
                'description' => "22.5W Super Fast Charging ပံ့ပိုးပေးပြီး Type-C + USB 2-port ပါဝင်သော 20,000mAh Powerbank ဖြစ်ပါသည်။\nLED Digital Battery Display ပါဝင်ပြီး ခရီးသွားရန်နှင့် အရေးပေါ်သုံးရန် အထူးသင့်လျော်ပါသည်။",
                'image_url' => 'https://images.unsplash.com/photo-1609091839311-d5365f9ff1c5?w=600&auto=format&fit=crop&q=80',
                'has_variants' => false,
                'variants' => [],
            ],
            [
                'code' => 'ELEC-002',
                'sku' => 'ELEC-002',
                'barcode' => '893450000102',
                'name' => 'Xiaomi Redmi Buds 5 Pro Wireless Earbuds',
                'alternative_name' => 'Redmi Buds 5 Pro ANC',
                'category' => '📱 ဖုန်းနှင့် အီလက်ထရောနစ်',
                'brand' => 'Xiaomi',
                'unit' => 'ခု',
                'cost_price' => 75000,
                'retail_price' => 115000,
                'bot_price' => 108000,
                'stock' => 35,
                'description' => "52dB Active Noise Cancelling (ANC) အသံဆူညံမှုဖျောက်စနစ်ပါဝင်ပြီး ဘက်ထရီခံချိန် ၃၈ နာရီအထိ အသုံးပြုနိုင်သော အဆင့်မြင့် ကြိုးမဲ့နားကြပ်။\nHi-Res Audio ကြည်လင်ပြတ်သားသော အသံထွက်ပေးစွမ်းပါသည်။",
                'image_url' => 'https://images.unsplash.com/photo-1590658268037-6bf12165a8df?w=600&auto=format&fit=crop&q=80',
                'has_variants' => false,
                'variants' => [],
            ],
            [
                'code' => 'ELEC-003',
                'sku' => 'ELEC-003',
                'barcode' => '893450000103',
                'name' => 'Remax 65W GaN Fast Charger (3-Port)',
                'alternative_name' => 'Remax GaN 65W Quick Charger',
                'category' => '📱 ဖုန်းနှင့် အီလက်ထရောနစ်',
                'brand' => 'Remax',
                'unit' => 'ခု',
                'cost_price' => 32000,
                'retail_price' => 49000,
                'bot_price' => 45000,
                'stock' => 40,
                'description' => "Laptop, MacBook, iPad, iPhone နှင့် Android ဖုန်းအားလုံးကို တစ်ပြိုင်နက် အားအမြန်သွင်းနိုင်သော GaN နည်းပညာသုံး 65W အားသွင်းခေါင်း။",
                'image_url' => 'https://images.unsplash.com/photo-1583863788434-e58a36330cf0?w=600&auto=format&fit=crop&q=80',
                'has_variants' => false,
                'variants' => [],
            ],
            [
                'code' => 'ELEC-004',
                'sku' => 'ELEC-004',
                'barcode' => '893450000104',
                'name' => 'Samsung 45W Type-C Super Fast Charging Cable (1.8m)',
                'alternative_name' => 'Samsung Type-C to Type-C 5A Cable',
                'category' => '📱 ဖုန်းနှင့် အီလက်ထရောနစ်',
                'brand' => 'Samsung',
                'unit' => 'ခု',
                'cost_price' => 9000,
                'retail_price' => 16000,
                'bot_price' => 14000,
                'stock' => 60,
                'description' => "5A Current ပံ့ပိုးပေးသော ခိုင်ခံ့ထူထဲသည့် Type-C to Type-C အားသွင်းကြိုး။ အရှည် ၁.၈ မီတာ ပါရှိသည်။",
                'image_url' => 'https://images.unsplash.com/photo-1588508065123-287b28e013da?w=600&auto=format&fit=crop&q=80',
                'has_variants' => false,
                'variants' => [],
            ],

            // ==========================================
            // Category: 👗 အဝတ်အထည်နှင့် ဖက်ရှင် (Variable & Single)
            // ==========================================
            [
                'code' => 'FASH-001',
                'sku' => 'FASH-001',
                'barcode' => '893450000201',
                'name' => 'Uniqlo AIRism Cotton Crew Neck T-Shirt (တီရှပ်)',
                'alternative_name' => 'Uniqlo AIRism T-Shirt',
                'category' => '👗 အဝတ်အထည်နှင့် ဖက်ရှင်',
                'brand' => 'Uniqlo',
                'unit' => 'ထည်',
                'cost_price' => 22000,
                'retail_price' => 38000,
                'bot_price' => 35000,
                'stock' => 150,
                'description' => "AIRism နည်းပညာဖြင့် ပြုလုပ်ထားသဖြင့် ပေါ့ပါးအေးမြပြီး ချွေးစုပ်ယူမှု အထူးကောင်းမွန်သော ပရီမီယံတီရှပ်။ နေ့စဉ်ဝတ်ရန် အထူးသင့်လျော်ပါသည်။",
                'image_url' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?w=600&auto=format&fit=crop&q=80',
                'has_variants' => true,
                'variants' => [
                    ['name' => 'Size M (အဖြူ - White)', 'sku' => 'FASH-001-W-M', 'barcode' => '893450000211', 'cost' => 22000, 'price' => 38000, 'stock' => 25],
                    ['name' => 'Size L (အဖြူ - White)', 'sku' => 'FASH-001-W-L', 'barcode' => '893450000212', 'cost' => 22000, 'price' => 38000, 'stock' => 25],
                    ['name' => 'Size XL (အဖြူ - White)', 'sku' => 'FASH-001-W-XL', 'barcode' => '893450000213', 'cost' => 22000, 'price' => 38000, 'stock' => 20],
                    ['name' => 'Size M (အနက် - Black)', 'sku' => 'FASH-001-B-M', 'barcode' => '893450000214', 'cost' => 22000, 'price' => 38000, 'stock' => 30],
                    ['name' => 'Size L (အနက် - Black)', 'sku' => 'FASH-001-B-L', 'barcode' => '893450000215', 'cost' => 22000, 'price' => 38000, 'stock' => 30],
                    ['name' => 'Size XL (အနက် - Black)', 'sku' => 'FASH-001-B-XL', 'barcode' => '893450000216', 'cost' => 22000, 'price' => 38000, 'stock' => 20],
                ],
            ],
            [
                'code' => 'FASH-002',
                'sku' => 'FASH-002',
                'barcode' => '893450000202',
                'name' => 'Giordano Classic Oxford Long Sleeve Shirt (လက်ရှည်ရှပ်)',
                'alternative_name' => 'Giordano Oxford Shirt',
                'category' => '👗 အဝတ်အထည်နှင့် ဖက်ရှင်',
                'brand' => 'Giordano',
                'unit' => 'ထည်',
                'cost_price' => 35000,
                'retail_price' => 58000,
                'bot_price' => 52000,
                'stock' => 100,
                'description' => "100% သဘာဝဝါဂွမ်းစစ်စစ်ဖြင့် ရက်လုပ်ထားပြီး ရုံးတက်၊ ပွဲတက်အတွက် သပ်ရပ်ခန့်ညားစေသော Oxford လက်ရှည်ရှပ်အင်္ကျီ။",
                'image_url' => 'https://images.unsplash.com/photo-1596755094514-f87e34085b2c?w=600&auto=format&fit=crop&q=80',
                'has_variants' => true,
                'variants' => [
                    ['name' => 'Size M (အပြာနု - Light Blue)', 'sku' => 'FASH-002-BL-M', 'barcode' => '893450000221', 'cost' => 35000, 'price' => 58000, 'stock' => 20],
                    ['name' => 'Size L (အပြာနု - Light Blue)', 'sku' => 'FASH-002-BL-L', 'barcode' => '893450000222', 'cost' => 35000, 'price' => 58000, 'stock' => 25],
                    ['name' => 'Size XL (အပြာနု - Light Blue)', 'sku' => 'FASH-002-BL-XL', 'barcode' => '893450000223', 'cost' => 35000, 'price' => 58000, 'stock' => 15],
                    ['name' => 'Size M (အဖြူ - Classic White)', 'sku' => 'FASH-002-WH-M', 'barcode' => '893450000224', 'cost' => 35000, 'price' => 58000, 'stock' => 20],
                    ['name' => 'Size L (အဖြူ - Classic White)', 'sku' => 'FASH-002-WH-L', 'barcode' => '893450000225', 'cost' => 35000, 'price' => 58000, 'stock' => 20],
                ],
            ],
            [
                'code' => 'FASH-003',
                'sku' => 'FASH-003',
                'barcode' => '893450000203',
                'name' => 'Giordano Regular Fit Denim Jeans (ဂျင်းဘောင်းဘီရှည်)',
                'alternative_name' => 'Giordano Denim Jeans',
                'category' => '👗 အဝတ်အထည်နှင့် ဖက်ရှင်',
                'brand' => 'Giordano',
                'unit' => 'ထည်',
                'cost_price' => 42000,
                'retail_price' => 68000,
                'bot_price' => 63000,
                'stock' => 50,
                'description' => "အရည်အသွေးမြင့် ဂျင်းသားဖြင့် ချုပ်လုပ်ထားပြီး ဝတ်ဆင်ရသက်တောင့်သက်သာရှိစေသော Regular Fit ဂျင်းဘောင်းဘီရှည်။",
                'image_url' => 'https://images.unsplash.com/photo-1542272604-780c96856592?w=600&auto=format&fit=crop&q=80',
                'has_variants' => true,
                'variants' => [
                    ['name' => 'Waist 30', 'sku' => 'FASH-003-30', 'barcode' => '893450000231', 'cost' => 42000, 'price' => 68000, 'stock' => 15],
                    ['name' => 'Waist 32', 'sku' => 'FASH-003-32', 'barcode' => '893450000232', 'cost' => 42000, 'price' => 68000, 'stock' => 20],
                    ['name' => 'Waist 34', 'sku' => 'FASH-003-34', 'barcode' => '893450000233', 'cost' => 42000, 'price' => 68000, 'stock' => 15],
                ],
            ],

            // ==========================================
            // Category: 💄 အလှကုန်နှင့် အသားအရေထိန်းသိမ်းမှု
            // ==========================================
            [
                'code' => 'BEAU-001',
                'sku' => 'BEAU-001',
                'barcode' => '893450000301',
                'name' => 'CeraVe Daily Moisturizing Lotion (လိုးရှင်း - 473ml)',
                'alternative_name' => 'CeraVe Moisturizing Lotion',
                'category' => '💄 အလှကုန်နှင့် အသားအရေထိန်းသိမ်းမှု',
                'brand' => 'CeraVe',
                'unit' => 'ဘူး',
                'cost_price' => 38000,
                'retail_price' => 56000,
                'bot_price' => 52000,
                'stock' => 45,
                'description' => "မရှိမဖြစ် Ceramide ၃ မျိုးနှင့် Hyaluronic Acid ပါဝင်ပြီး အသားအရေစိုပြေမှု ၂၄ နာရီ ထိန်းသိမ်းပေးသော အရေပြားဆရာဝန်ညွှန် လိုးရှင်း။",
                'image_url' => 'https://images.unsplash.com/photo-1556228720-195a672e8a03?w=600&auto=format&fit=crop&q=80',
                'has_variants' => false,
                'variants' => [],
            ],
            [
                'code' => 'BEAU-002',
                'sku' => 'BEAU-002',
                'barcode' => '893450000302',
                'name' => 'Bella Velvet Matte Lip Tint (ဘယ်လာ နှုတ်ခမ်းနီ)',
                'alternative_name' => 'Bella Lip Tint Velvet',
                'category' => '💄 အလှကုန်နှင့် အသားအရေထိန်းသိမ်းမှု',
                'brand' => 'Bella',
                'unit' => 'ဘူး',
                'cost_price' => 6500,
                'retail_price' => 11500,
                'bot_price' => 10000,
                'stock' => 90,
                'description' => "နှုတ်ခမ်းသားခြောက်သွေ့ခြင်းမရှိဘဲ တစ်နေ့တာလုံး တာရှည်ခံစေသော Velvet Matte ဖော်မြူလာ နှုတ်ခမ်းနီ။",
                'image_url' => 'https://images.unsplash.com/photo-1586495777744-4413f21062fa?w=600&auto=format&fit=crop&q=80',
                'has_variants' => true,
                'variants' => [
                    ['name' => '#01 Rose Coral (နှင်းဆီနီ)', 'sku' => 'BEAU-002-01', 'barcode' => '893450000321', 'cost' => 6500, 'price' => 11500, 'stock' => 30],
                    ['name' => '#02 Brick Orange (အုတ်နီရောင်)', 'sku' => 'BEAU-002-02', 'barcode' => '893450000322', 'cost' => 6500, 'price' => 11500, 'stock' => 35],
                    ['name' => '#03 Deep Berry (ဘယ်ရီရင့်ရောင်)', 'sku' => 'BEAU-002-03', 'barcode' => '893450000323', 'cost' => 6500, 'price' => 11500, 'stock' => 25],
                ],
            ],
            [
                'code' => 'BEAU-003',
                'sku' => 'BEAU-003',
                'barcode' => '893450000303',
                'name' => 'Bella Daily Sunscreen SPF 50+ PA++++ (နေလောင်ကာခရင်မ် - 50ml)',
                'alternative_name' => 'Bella Sunscreen SPF 50+',
                'category' => '💄 အလှကုန်နှင့် အသားအရေထိန်းသိမ်းမှု',
                'brand' => 'Bella',
                'unit' => 'ဘူး',
                'cost_price' => 9000,
                'retail_price' => 15500,
                'bot_price' => 14000,
                'stock' => 50,
                'description' => "UVA/UVB ရောင်ခြည်များကို အပြည့်အဝကာကွယ်ပေးပြီး စေးကပ်မှုမရှိဘဲ အသားထဲ စိမ့်ဝင်လွယ်သော မိတ်ကပ်အခံ သုံးနိုင်သည့် Sunscreen။",
                'image_url' => 'https://images.unsplash.com/photo-1598440947619-2c35fc9aa908?w=600&auto=format&fit=crop&q=80',
                'has_variants' => false,
                'variants' => [],
            ],

            // ==========================================
            // Category: ☕ စားသောက်ကုန်နှင့် အဖျော်ယမကာ
            // ==========================================
            [
                'code' => 'FOOD-001',
                'sku' => 'FOOD-001',
                'barcode' => '893450000401',
                'name' => 'Royal Myanmar Teamix (တော်ဝင်မြန်မာ လက်ဖက်ရည်ထုပ် - 30 ထုပ်ပါ)',
                'alternative_name' => 'Royal Myanmar Tea Mix 30s',
                'category' => '☕ စားသောက်ကုန်နှင့် အဖျော်ယမကာ',
                'brand' => 'Royal Myanmar',
                'unit' => 'ထုပ်',
                'cost_price' => 7800,
                'retail_price' => 10500,
                'bot_price' => 9800,
                'stock' => 100,
                'description' => "မြန်မာ့ရိုးရာ လက်ဖက်ရည် အရသာစစ်စစ်ကို အလွယ်တကူ သောက်သုံးနိုင်သော နာမည်ကျော် အသင့်ဖျော်လက်ဖက်ရည်ထုပ်ကြီး။",
                'image_url' => 'https://images.unsplash.com/photo-1576092768241-dec231879fc3?w=600&auto=format&fit=crop&q=80',
                'has_variants' => false,
                'variants' => [],
            ],
            [
                'code' => 'FOOD-002',
                'sku' => 'FOOD-002',
                'barcode' => '893450000402',
                'name' => 'Premier Coffee 3-in-1 (ပရီးမီးယား ကော်ဖီမစ် - 30 ထုပ်ပါ)',
                'alternative_name' => 'Premier Coffee 3-in-1 Pack',
                'category' => '☕ စားသောက်ကုန်နှင့် အဖျော်ယမကာ',
                'brand' => 'Premier',
                'unit' => 'ထုပ်',
                'cost_price' => 7500,
                'retail_price' => 10000,
                'bot_price' => 9500,
                'stock' => 120,
                'description' => "မွှေးပျံ့သော ကော်ဖီစေ့အနံ့နှင့် သဘာဝအရသာကို ရရှိစေသော လူကြိုက်အများဆုံး Premier Coffee 3-in-1။",
                'image_url' => 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd?w=600&auto=format&fit=crop&q=80',
                'has_variants' => false,
                'variants' => [],
            ],
            [
                'code' => 'FOOD-003',
                'sku' => 'FOOD-003',
                'barcode' => '893450000403',
                'name' => 'Nescafe Gold Blend Rich Instant Coffee (ရွှေရောင် ကော်ဖီဘူး - 200g)',
                'alternative_name' => 'Nescafe Gold Instant Coffee 200g',
                'category' => '☕ စားသောက်ကုန်နှင့် အဖျော်ယမကာ',
                'brand' => 'Premier',
                'unit' => 'ဘူး',
                'cost_price' => 26000,
                'retail_price' => 36000,
                'bot_price' => 33500,
                'stock' => 40,
                'description' => "အဆင့်မြင့် အာရေဘီကာ ကော်ဖီစေ့များဖြင့် ထုတ်လုပ်ထားသော Nescafe Gold အနံ့မွှေး ကော်ဖီမှုန့်။",
                'image_url' => 'https://images.unsplash.com/photo-1559056199-641a0ac8b55e?w=600&auto=format&fit=crop&q=80',
                'has_variants' => false,
                'variants' => [],
            ],
            [
                'code' => 'FOOD-004',
                'sku' => 'FOOD-004',
                'barcode' => '893450000404',
                'name' => 'Pringles Potato Crisps (ပရိုဂဲလ် အာလူးကြော်)',
                'alternative_name' => 'Pringles Potato Crisps',
                'category' => '☕ စားသောက်ကုန်နှင့် အဖျော်ယမကာ',
                'brand' => 'Premier',
                'unit' => 'ဘူး',
                'cost_price' => 4500,
                'retail_price' => 7000,
                'bot_price' => 6500,
                'stock' => 130,
                'description' => "ကြွပ်ကြွပ်ရွရွ အာလူးကြော်စစ်စစ် အရသာစုံလင်စွာ ရွေးချယ်နိုင်သော Pringles Canisters။",
                'image_url' => 'https://images.unsplash.com/photo-1566478989037-eec170784d0b?w=600&auto=format&fit=crop&q=80',
                'has_variants' => true,
                'variants' => [
                    ['name' => 'Original Flavor (မူရင်းအရသာ)', 'sku' => 'FOOD-004-ORI', 'barcode' => '893450000421', 'cost' => 4500, 'price' => 7000, 'stock' => 40],
                    ['name' => 'Sour Cream & Onion (ကြက်သွန်ချဉ်)', 'sku' => 'FOOD-004-SC', 'barcode' => '893450000422', 'cost' => 4500, 'price' => 7000, 'stock' => 50],
                    ['name' => 'Hot & Spicy (စပ်စပ်အရသာ)', 'sku' => 'FOOD-004-HS', 'barcode' => '893450000423', 'cost' => 4500, 'price' => 7000, 'stock' => 40],
                ],
            ],

            // ==========================================
            // Category: 🏠 လူသုံးကုန်နှင့် အိမ်သုံးပစ္စည်း
            // ==========================================
            [
                'code' => 'HOME-001',
                'sku' => 'HOME-001',
                'barcode' => '893450000501',
                'name' => 'Dettol Antiseptic Liquid (ဒက်တော ပိုးသတ်ဆေးရည် - 500ml)',
                'alternative_name' => 'Dettol Liquid 500ml',
                'category' => '🏠 လူသုံးကုန်နှင့် အိမ်သုံးပစ္စည်း',
                'brand' => 'Dettol',
                'unit' => 'ဘူး',
                'cost_price' => 11000,
                'retail_price' => 16500,
                'bot_price' => 15000,
                'stock' => 60,
                'description' => "ဘက်တီးရီးယားနှင့် ပိုးမွှား ၉၉.၉% ကို သေစေနိုင်သော တစ်ကိုယ်ရေနှင့် အိမ်သုံး ပိုးသတ်ဆေးရည်။",
                'image_url' => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=600&auto=format&fit=crop&q=80',
                'has_variants' => false,
                'variants' => [],
            ],
            [
                'code' => 'HOME-002',
                'sku' => 'HOME-002',
                'barcode' => '893450000502',
                'name' => 'Sunlight Lemon Dishwash Liquid (ဆန်းလိုက် ပန်းကန်ဆေးရည် - 750ml)',
                'alternative_name' => 'Sunlight Dishwash Lemon 750ml',
                'category' => '🏠 လူသုံးကုန်နှင့် အိမ်သုံးပစ္စည်း',
                'brand' => 'Sunlight',
                'unit' => 'ဘူး',
                'cost_price' => 4800,
                'retail_price' => 7500,
                'bot_price' => 6800,
                'stock' => 80,
                'description' => "အဆီအကျက်များကို အလွယ်တကူ ဖယ်ရှားပေးပြီး သံပရာရနံ့ သင်းပျံ့စေသော Sunlight ပန်းကန်ဆေးဆပ်ပြာရည်။",
                'image_url' => 'https://images.unsplash.com/photo-1585421514738-01798e348b17?w=600&auto=format&fit=crop&q=80',
                'has_variants' => false,
                'variants' => [],
            ],
            [
                'code' => 'HOME-003',
                'sku' => 'HOME-003',
                'barcode' => '893450000503',
                'name' => 'Dettol Disinfectant Multi-Action Spray (ဒက်တော ဖြန်းဆေးရည် - 500ml)',
                'alternative_name' => 'Dettol Spray 500ml',
                'category' => '🏠 လူသုံးကုန်နှင့် အိမ်သုံးပစ္စည်း',
                'brand' => 'Dettol',
                'unit' => 'ဘူး',
                'cost_price' => 12500,
                'retail_price' => 18500,
                'bot_price' => 17000,
                'stock' => 40,
                'description' => "အိမ်သုံးပရိဘောဂ၊ စားပွဲ၊ တံခါးလက်ကိုင်များအား ပိုးသတ်သန့်စင်ရန် အလွယ်တကူ ဖြန်းနိုင်သော Spray။",
                'image_url' => 'https://images.unsplash.com/photo-1585421512140-5a50785055b8?w=600&auto=format&fit=crop&q=80',
                'has_variants' => false,
                'variants' => [],
            ],
        ];

        // 7. Seed / Update Catalog Items
        DB::beginTransaction();
        try {
            foreach ($catalog as $item) {
                $categoryModel = $categoriesMap[$item['category']] ?? null;
                $brandModel = $brandsMap[$item['brand']] ?? null;
                $unitModel = $unitsMap[$item['unit']] ?? null;

                $product = Product::updateOrCreate(
                    ['code' => $item['code']],
                    [
                        'name' => $item['name'],
                        'alternative_name' => $item['alternative_name'],
                        'sku' => $item['sku'],
                        'barcode' => $item['barcode'],
                        'category_id' => $categoryModel?->id,
                        'category_name' => $categoryModel?->name,
                        'brand_id' => $brandModel?->id,
                        'brand_name' => $brandModel?->name,
                        'base_unit_id' => $unitModel?->id,
                        'unit_name' => $unitModel?->name,
                        'purchase_unit_id' => $unitModel?->id,
                        'purchase_unit' => $unitModel?->name,
                        'sale_unit_id' => $unitModel?->id,
                        'sale_unit' => $unitModel?->name,
                        'supplier_id' => $supplier->id,
                        'supplier_name' => $supplier->name,
                        'purchase_price' => $item['cost_price'],
                        'cost_price' => $item['cost_price'],
                        'retail_price' => $item['retail_price'],
                        'selling_price' => $item['retail_price'],
                        'stock_quantity' => $item['stock'],
                        'alert_quantity' => 5,
                        'stock_alert' => 5,
                        'low_qty' => 5,
                        'has_variants' => (bool)$item['has_variants'],
                        'image_url' => $item['image_url'],
                        'description' => $item['description'],
                        'is_active' => true,
                        'status' => 'Active',
                    ]
                );

                if (!empty($item['has_variants']) && !empty($item['variants'])) {
                    $totalVariantStock = 0;
                    $sortOrder = 1;
                    $createdVariantIds = [];

                    foreach ($item['variants'] as $vData) {
                        $variant = ProductVariant::updateOrCreate(
                            ['sku' => $vData['sku']],
                            [
                                'product_id' => $product->id,
                                'name' => $vData['name'],
                                'barcode' => $vData['barcode'] ?? null,
                                'cost_price' => $vData['cost'],
                                'selling_price' => $vData['price'],
                                'is_active' => true,
                                'sort_order' => $sortOrder++,
                                'image_url' => $item['image_url'],
                            ]
                        );
                        $createdVariantIds[] = $variant->id;
                        $totalVariantStock += $vData['stock'];

                        // Seed stock balances and inventory batches across all active outlets
                        foreach ($outlets as $wh) {
                            StockBalance::updateOrCreate(
                                [
                                    'warehouse_id' => $wh->id,
                                    'product_id' => $product->id,
                                    'product_variant_id' => $variant->id,
                                ],
                                ['quantity' => $vData['stock']]
                            );

                            InventoryBatch::updateOrCreate(
                                [
                                    'batch_number' => 'BATCH-' . $vData['sku'] . '-WH' . $wh->id,
                                    'product_id' => $product->id,
                                    'product_variant_id' => $variant->id,
                                ],
                                [
                                    'warehouse_id' => $wh->id,
                                    'source_type' => 'initial_stock',
                                    'source_reference' => 'INIT-' . $vData['sku'],
                                    'received_date' => now()->format('Y-m-d'),
                                    'unit_cost' => $vData['cost'],
                                    'initial_quantity' => $vData['stock'],
                                    'remaining_quantity' => $vData['stock'],
                                    'status' => 'active',
                                ]
                            );
                        }
                    }

                    // Update parent product stock quantity to sum of variants
                    $product->update(['stock_quantity' => $totalVariantStock]);
                } else {
                    // Single product stock balances and batches across all active outlets
                    foreach ($outlets as $wh) {
                        StockBalance::updateOrCreate(
                            [
                                'warehouse_id' => $wh->id,
                                'product_id' => $product->id,
                                'product_variant_id' => null,
                            ],
                            ['quantity' => $item['stock']]
                        );

                        InventoryBatch::updateOrCreate(
                            [
                                'batch_number' => 'BATCH-' . $item['code'] . '-WH' . $wh->id,
                                'product_id' => $product->id,
                                'product_variant_id' => null,
                            ],
                            [
                                'warehouse_id' => $wh->id,
                                'source_type' => 'initial_stock',
                                'source_reference' => 'INIT-' . $item['code'],
                                'received_date' => now()->format('Y-m-d'),
                                'unit_cost' => $item['cost_price'],
                                'initial_quantity' => $item['stock'],
                                'remaining_quantity' => $item['stock'],
                                'status' => 'active',
                            ]
                        );
                    }
                }
            }

            // 8. Update and reinforce existing products (PROD-001, PROD-002, 10008, IMAX-001)
            $existingProducts = [
                'PROD-001' => [
                    'brand' => 'Bella',
                    'category' => '💄 အလှကုန်နှင့် အသားအရေထိန်းသိမ်းမှု',
                    'image_url' => 'https://images.unsplash.com/photo-1596755094514-f87e34085b2c?w=600&auto=format&fit=crop&q=80',
                    'stock' => 97,
                ],
                'PROD-002' => [
                    'brand' => 'CeraVe',
                    'category' => '💄 အလှကုန်နှင့် အသားအရေထိန်းသိမ်းမှု',
                    'image_url' => 'https://images.unsplash.com/photo-1556228720-195a672e8a03?w=600&auto=format&fit=crop&q=80',
                    'stock' => 97,
                ],
                '10008' => [
                    'brand' => 'Bella',
                    'category' => '💄 အလှကုန်နှင့် အသားအရေထိန်းသိမ်းမှု',
                    'image_url' => 'https://images.unsplash.com/photo-1620916566398-39f1143ab7be?w=600&auto=format&fit=crop&q=80',
                    'stock' => 24,
                ],
                'IMAX-001' => [
                    'brand' => 'imax',
                    'category' => '👗 အဝတ်အထည်နှင့် ဖက်ရှင်',
                    'image_url' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=600&auto=format&fit=crop&q=80',
                    'stock' => 30,
                ],
            ];

            foreach ($existingProducts as $code => $exData) {
                $p = Product::where('code', $code)->first();
                if ($p) {
                    $cModel = $categoriesMap[$exData['category']] ?? null;
                    $bModel = $brandsMap[$exData['brand']] ?? null;
                    
                    $updatePayload = [
                        'is_active' => true,
                        'status' => 'Active',
                    ];
                    if ($cModel) {
                        $updatePayload['category_id'] = $cModel->id;
                        $updatePayload['category_name'] = $cModel->name;
                    }
                    if ($bModel) {
                        $updatePayload['brand_id'] = $bModel->id;
                        $updatePayload['brand_name'] = $bModel->name;
                    }
                    if (empty($p->image_url)) {
                        $updatePayload['image_url'] = $exData['image_url'];
                    }

                    $p->update($updatePayload);

                    // Ensure stock is available in ALL outlets including outlet 3 (Telegram Bot warehouse)
                    if ($p->has_variants) {
                        foreach ($p->variants as $variant) {
                            foreach ($outlets as $wh) {
                                StockBalance::firstOrCreate(
                                    [
                                        'warehouse_id' => $wh->id,
                                        'product_id' => $p->id,
                                        'product_variant_id' => $variant->id,
                                    ],
                                    ['quantity' => 10]
                                );

                                InventoryBatch::firstOrCreate(
                                    [
                                        'batch_number' => 'BATCH-' . $variant->sku . '-WH' . $wh->id,
                                        'product_id' => $p->id,
                                        'product_variant_id' => $variant->id,
                                    ],
                                    [
                                        'warehouse_id' => $wh->id,
                                        'source_type' => 'initial_stock',
                                        'source_reference' => 'INIT-' . $variant->sku,
                                        'received_date' => now()->format('Y-m-d'),
                                        'unit_cost' => $variant->cost_price ?: 30000,
                                        'initial_quantity' => 10,
                                        'remaining_quantity' => 10,
                                        'status' => 'active',
                                    ]
                                );
                            }
                        }
                    } else {
                        foreach ($outlets as $wh) {
                            StockBalance::firstOrCreate(
                                [
                                    'warehouse_id' => $wh->id,
                                    'product_id' => $p->id,
                                    'product_variant_id' => null,
                                ],
                                ['quantity' => $exData['stock']]
                            );

                            InventoryBatch::firstOrCreate(
                                [
                                    'batch_number' => 'BATCH-' . $p->code . '-WH' . $wh->id,
                                    'product_id' => $p->id,
                                    'product_variant_id' => null,
                                ],
                                [
                                    'warehouse_id' => $wh->id,
                                    'source_type' => 'initial_stock',
                                    'source_reference' => 'INIT-' . $p->code,
                                    'received_date' => now()->format('Y-m-d'),
                                    'unit_cost' => $p->cost_price ?: 10000,
                                    'initial_quantity' => $exData['stock'],
                                    'remaining_quantity' => $exData['stock'],
                                    'status' => 'active',
                                ]
                            );
                        }
                    }
                }
            }

            DB::commit();
            $this->command?->info('✅ Successfully seeded comprehensive retail products, variants, and stock balances!');
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->command?->error('❌ Seeder failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
