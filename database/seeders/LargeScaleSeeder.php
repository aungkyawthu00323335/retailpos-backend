<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LargeScaleSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        $now = Carbon::now();

        // ==========================================
        // 1. SEED 30 OUTLETS
        // ==========================================
        echo "1. Seeding 30 Outlets...\n";
        $outletCities = [
            'Main Branch Outlet (Kamayut, Yangon)',
            'Downtown Bistro Outlet (Pabedan, Yangon)',
            'Airport Express Outlet (Mingaladon, Yangon)',
            'Hledan Centre Outlet (Hledan, Yangon)',
            'Sayarsan Gourmet Outlet (Bahan, Yangon)',
            'Kandawgyi Lakeside Outlet (Mingalar Taung Nyunt)',
            'Times City Premier Outlet (Kamayut, Yangon)',
            'Yankin Plaza Outlet (Yankin, Yangon)',
            'Myanmar Plaza Outlet (Bahan, Yangon)',
            'South Okkalapa Express (Okkalapa, Yangon)',
            'North Dagon Outlet (North Dagon, Yangon)',
            'Insein Station Bistro (Insein, Yangon)',
            'Mandalay Palace Outlet (78th Street, Mandalay)',
            'Mandalay Hill Resort Outlet (Aungmyethazan)',
            'Chan Mya Thar Zi Outlet (Mandalay)',
            'Pyin Oo Lwin Highland Bistro (Pyin Oo Lwin)',
            'Naypyidaw Capital Outlet (Zabuthiri, NPT)',
            'Naypyidaw Hotel Zone Bistro (Dekkhinathiri, NPT)',
            'Taunggyi Pine Hill Bistro (Taunggyi, Shan)',
            'Inle Lake View Outlet (Nyaungshwe, Shan)',
            'Kalaw Heritage Cafe & Outlet (Kalaw, Shan)',
            'Bagan Ancient View Bistro (Old Bagan)',
            'Nyaung-U Riverfront Outlet (Nyaung-U)',
            'Mawlamyine Strand Road Outlet (Mawlamyine)',
            'Pathein Delta Bistro (Pathein, Ayeyarwady)',
            'Pyay Shwesandaw Outlet (Pyay, Bago)',
            'Bago Heritage Outlet (Bago City)',
            'Monywa Chindwin Bistro (Monywa, Sagaing)',
            'Sittwe Coastal Outlet (Sittwe, Rakhine)',
            'Ngwe Saung Beachfront Bistro (Ngwe Saung)',
        ];

        $outletRecords = [];
        foreach ($outletCities as $idx => $name) {
            $outletRecords[] = [
                'name' => $name,
                'phone' => '+95 9 ' . rand(200, 999) . ' ' . rand(100, 999) . ' ' . rand(100, 999),
                'email' => 'outlet' . ($idx + 1) . '@dreamspos.com',
                'address' => 'Branch Location #' . ($idx + 1) . ', Myanmar',
                'manager_name' => 'Manager ' . ($idx + 1),
                'logo' => 'https://images.unsplash.com/photo-1555396273-367ea4eb4db5?w=150',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('outlets')->truncate();
        DB::table('outlets')->insert($outletRecords);
        echo "   -> 30 Outlets seeded successfully!\n";

        // ==========================================
        // 2. SEED 10,000 PRODUCTS
        // ==========================================
        echo "2. Seeding 10,000 Products in batches...\n";
        DB::table('products')->truncate();

        $categories = [
            'Main Dishes', 'Appetizers & Starters', 'Desserts & Sweets', 
            'Hot & Cold Beverages', 'Alcohol & Cocktails', 'Side Orders', 
            'Pasta & Noodles', 'Burgers & Sandwiches', 'Soups & Salads', 'Seafood Specials'
        ];
        $units = ['Plate', 'Bowl', 'Glass', 'Set', 'Portion', 'Box', 'Pcs', 'Bottle'];
        $adjectives = ['Classic', 'Crispy', 'Spicy', 'Golden', 'Truffle', 'Smoked', 'Artisan', 'Signature', 'Fresh', 'Savory', 'Grilled', 'Creamy', 'Zesty', 'Honey Glazed', 'Black Pepper', 'Garlic Butter'];
        $nouns = ['Cheeseburger', 'Chicken Steak', 'Fettuccine Pasta', 'Pork Ribs', 'French Fries', 'Caesar Salad', 'Caramel Macchiato', 'Matcha Latte', 'Chocolate Lava Cake', 'Seafood Paella', 'Garlic Bread', 'Wonton Soup', 'Spring Rolls', 'Beef Tacos', 'Fruit Smoothie'];
        $altBurmese = ['အရသာထူးကဲဘာဂါ', 'ကြက်သားစတိတ်', 'မှိုခေါက်ဆွဲဖြူ', 'ဝက်နံရိုးကင်', 'အာလူးချောင်းကြော်', 'လတ်ဆတ်ဆာလက်', 'ကာရာမဲလ်ကော်ဖီအေး', 'ဂျပန်မတ်ချာလက်တေး', 'လာဗာကိတ်', 'ပင်လယ်စာထမင်းကြော်', 'ကြက်သွန်ဖြူပေါင်မုန့်', 'ဝမ်တန်ဟင်းချို', 'ကော်ပြန့်ကြော်', 'အမဲသားတာကို့စ်', 'သစ်သီးဖျော်ရည်'];
        $sampleImages = [
            'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=400',
            'https://images.unsplash.com/photo-1621996346565-e3d5d6281691?w=400',
            'https://images.unsplash.com/photo-1576107232684-1279f3908594?w=400',
            'https://images.unsplash.com/photo-1517701604599-bb29b565090c?w=400',
            'https://images.unsplash.com/photo-1536256263959-770b48d82b0a?w=400',
            'https://images.unsplash.com/photo-1606313564200-e75d5e30476c?w=400',
            'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=400',
            'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=400',
            'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=400',
        ];

        $productBatch = [];
        for ($i = 1; $i <= 10000; $i++) {
            $adj = $adjectives[$i % count($adjectives)];
            $noun = $nouns[$i % count($nouns)];
            $name = "$adj $noun #$i";
            $alt = $altBurmese[$i % count($altBurmese)] . " (#$i)";
            $cat = $categories[$i % count($categories)];
            $unit = $units[$i % count($units)];
            $cost = round(2.0 + ($i % 30) * 0.75, 2);
            $dineIn = round($cost * 2.5, 2);
            $takeAway = round($dineIn * 1.05, 2);
            $delivery = round($dineIn * 1.15, 2);
            $img = $sampleImages[$i % count($sampleImages)];

            $productBatch[] = [
                'code' => 'PRD-' . (10000 + $i),
                'name' => $name,
                'alternative_name' => $alt,
                'category_name' => $cat,
                'unit_name' => $unit,
                'purchase_price' => $cost,
                'low_qty' => (float)(5 + ($i % 20)),
                'dine_in_price' => $dineIn,
                'take_away_price' => $takeAway,
                'delivery_price' => $delivery,
                'image_url' => $img,
                'description' => "Delicious freshly prepared $name made with premium restaurant grade ingredients.",
                'status' => ($i % 25 === 0) ? 'Inactive' : 'Active',
                'added_by' => 'Admin User',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($productBatch) === 1000) {
                DB::table('products')->insert($productBatch);
                $productBatch = [];
                echo "   -> Inserted $i / 10,000 Products...\n";
            }
        }
        if (!empty($productBatch)) {
            DB::table('products')->insert($productBatch);
        }
        echo "   -> 10,000 Products seeded successfully!\n";

        // ==========================================
        // 3. SEED 10,000 INGREDIENTS
        // ==========================================
        echo "3. Seeding 10,000 Ingredients in batches...\n";
        DB::table('ingredients')->truncate();

        $ingNames = [
            'Chicken Breast', 'Minced Beef', 'Pork Belly', 'Fresh Salmon', 'Tiger Prawns',
            'Button Mushrooms', 'Shiitake Mushrooms', 'Yellow Onions', 'Garlic Cloves', 'Fresh Ginger',
            'Green Chilies', 'Red Bell Pepper', 'Cherry Tomatoes', 'Romaine Lettuce', 'Fresh Basil',
            'Refined Cooking Oil', 'Extra Virgin Olive Oil', 'Pure Butter', 'Heavy Whipping Cream', 'Cheddar Cheese',
            'Mozzarella Cheese', 'Parmesan Powder', 'Wheat Flour', 'Corn Starch', 'Egg Noodles',
            'Fettuccine Pasta', 'Jasmine Rice', 'Refined White Sugar', 'Sea Salt', 'Black Peppercorns',
            'Soy Sauce', 'Oyster Sauce', 'Fish Sauce', 'Sesame Oil', 'Coconut Milk'
        ];
        $ingCategories = [
            'Meat & Poultry', 'Vegetables & Produce', 'Dairy & Eggs', 'Oil & Fats',
            'Dry Goods & Flour', 'Spices & Seasoning', 'Sauces & Condiments', 'Seafood', 'Beverages & Syrups', 'Others'
        ];
        $purchaseUnits = ['Kg', 'L', 'Box', 'Bag', 'Can', 'Pack'];
        $consumptionUnits = ['g', 'ml', 'Pcs', 'g', 'g', 'g'];

        $ingBatch = [];
        for ($i = 1; $i <= 10000; $i++) {
            $baseName = $ingNames[$i % count($ingNames)];
            $name = "$baseName Grade " . chr(65 + ($i % 5)) . " #$i";
            $cat = $ingCategories[$i % count($ingCategories)];
            $unitIdx = $i % count($purchaseUnits);
            $pUnit = $purchaseUnits[$unitIdx];
            $cUnit = $consumptionUnits[$unitIdx];
            $convRate = ($pUnit == 'Kg' || $pUnit == 'L') ? 1000.0 : (($pUnit == 'Box') ? 100.0 : 500.0);
            $pPrice = round(50.0 + ($i % 50) * 10.0, 2);
            $costPerUnit = round($pPrice / $convRate, 4);

            $ingBatch[] = [
                'code' => (string)(10000 + $i),
                'name' => $name,
                'category_name' => $cat,
                'purchase_unit' => $pUnit,
                'consumption_unit' => $cUnit,
                'conversion_rate' => $convRate,
                'purchase_price' => $pPrice,
                'cost_per_unit' => $costPerUnit,
                'current_stock_qty' => (float)(rand(10, 100)),
                'low_qty' => (float)(rand(2, 15)),
                'outlet_id' => null,
                'outlet_name' => 'All Outlets',
                'added_by' => 'Admin User',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($ingBatch) === 1000) {
                DB::table('ingredients')->insert($ingBatch);
                $ingBatch = [];
                echo "   -> Inserted $i / 10,000 Ingredients...\n";
            }
        }
        if (!empty($ingBatch)) {
            DB::table('ingredients')->insert($ingBatch);
        }
        echo "   -> 10,000 Ingredients seeded successfully!\n";

        // ==========================================
        // 4. SEED 10,000 CUSTOMERS
        // ==========================================
        echo "4. Seeding 10,000 Customers in batches...\n";
        DB::table('customers')->truncate();

        $firstNames = ['Aung', 'Kyaw', 'Zaw', 'Min', 'Thura', 'Hla', 'Myo', 'Ko', 'Thant', 'Tin', 'Nyi', 'Phyo', 'Zayar', 'Sai', 'Kaung'];
        $lastNames = ['Lwin', 'Win', 'Myint', 'Naing', 'Tun', 'Thu', 'Swe', 'Htut', 'Kyaw', 'Oo', 'Zin', 'San', 'Lin', 'Aung', 'Maw'];
        $femaleNames = ['Su Su', 'Hnin', 'May', 'Thida', 'Yin Yin', 'Aye Aye', 'Khin Khin', 'Phyu Phyu', 'Sandar', 'Mya Mya', 'Chit', 'Zin Mar'];
        $tiers = ['VIP Platinum', 'Gold VIP', 'Silver Regular', 'Bronze Member', 'Standard Diner'];

        $custBatch = [];
        for ($i = 1; $i <= 10000; $i++) {
            if ($i % 2 === 0) {
                $cName = $firstNames[$i % count($firstNames)] . ' ' . $lastNames[($i * 3) % count($lastNames)];
            } else {
                $cName = 'Daw ' . $femaleNames[$i % count($femaleNames)] . ' ' . $lastNames[($i * 2) % count($lastNames)];
            }
            $name = "$cName ($i)";
            $tier = $tiers[$i % count($tiers)];
            $spent = round(50.0 + ($i % 500) * 8.5, 2);
            $points = (int)($spent / 2);
            $visits = 1 + ($i % 40);

            $custBatch[] = [
                'name' => $name,
                'phone' => '+95 9 ' . rand(200, 999) . ' ' . rand(100, 999) . ' ' . str_pad($i % 1000, 3, '0', STR_PAD_LEFT),
                'email' => 'customer' . $i . '@example.com',
                'tier' => $tier,
                'reward_points' => $points,
                'total_spent' => $spent,
                'total_visits' => $visits,
                'avatar' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150',
                'status' => ($i % 30 === 0) ? 'Inactive' : 'Active',
                'notes' => 'Valued customer profile #' . $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($custBatch) === 1000) {
                DB::table('customers')->insert($custBatch);
                $custBatch = [];
                echo "   -> Inserted $i / 10,000 Customers...\n";
            }
        }
        if (!empty($custBatch)) {
            DB::table('customers')->insert($custBatch);
        }
        echo "   -> 10,000 Customers seeded successfully!\n";

        // ==========================================
        // 5. SEED 10,000 SUPPLIERS
        // ==========================================
        echo "5. Seeding 10,000 Suppliers in batches...\n";
        DB::table('suppliers')->truncate();

        $supplierTypes = [
            'Meat & Poultry Farms Ltd', 'Highland Dairy & Cheese Co', 'Green Valley Farm Harvest',
            'Yangon Beverage Distribution', 'Eco Packaging & Cutlery', 'Golden Grain Flour Mills',
            'Fresh Seafood Harbor Depot', 'Royal Spices & Seasoning Trading', 'Delta Organic Produce', 'Bakery Ingredients Corp'
        ];
        $contactNames = ['U Kyaw Min', 'Daw Hla Hla', 'Ko Tun Tun', 'U Lin Naing', 'Daw Thida Win', 'Ko Zaw Zaw', 'U Than Htike', 'Daw Ni Ni'];
        $paymentTermsList = ['Net 15 Days', 'Net 30 Days', 'Net 45 Days', 'Cash on Delivery', 'Advance Payment'];

        $suppBatch = [];
        for ($i = 1; $i <= 10000; $i++) {
            $baseCompany = $supplierTypes[$i % count($supplierTypes)];
            $companyName = "$baseCompany (Branch #$i)";
            $cat = $ingCategories[$i % count($ingCategories)];
            $contact = $contactNames[$i % count($contactNames)];
            $terms = $paymentTermsList[$i % count($paymentTermsList)];
            $due = ($i % 3 === 0) ? round(($i % 200) * 15.5, 2) : 0.00;

            $suppBatch[] = [
                'name' => $companyName,
                'contact_person' => $contact,
                'phone' => '+95 9 ' . rand(200, 999) . ' ' . rand(100, 999) . ' ' . str_pad($i % 1000, 3, '0', STR_PAD_LEFT),
                'email' => 'supplier' . $i . '@vendorpos.com',
                'category' => $cat,
                'address' => 'Industrial Zone #' . (($i % 10) + 1) . ', Warehouse Unit ' . $i . ', Myanmar',
                'balance_due' => $due,
                'payment_terms' => $terms,
                'status' => ($i % 40 === 0) ? 'Inactive' : 'Active',
                'notes' => 'Registered verified procurement vendor #' . $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($suppBatch) === 1000) {
                DB::table('suppliers')->insert($suppBatch);
                $suppBatch = [];
                echo "   -> Inserted $i / 10,000 Suppliers...\n";
            }
        }
        if (!empty($suppBatch)) {
            DB::table('suppliers')->insert($suppBatch);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        echo "\n=======================================================\n";
        echo "    ALL 40,030 RECORDS SEEDED SUCCESSFULLY IN MYSQL!   \n";
        echo "=======================================================\n";
    }
}
