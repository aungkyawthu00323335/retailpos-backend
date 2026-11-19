<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TenThousandRecordsEnterpriseSeeder extends Seeder
{
    /**
     * Run the enterprise database seeds.
     * Seeds exactly 10,000 of each:
     * - 10,000 Products (Single, Variation, Unit-linked) + Variants + Unit Links + Balances
     * - 10,000 Purchases + Purchase Items
     * - 10,000 Sales + Sale Items + Sale Payments
     * - 10,000 Sale Returns + Items & Payments Payload
     * - 10,000 Purchase Returns + Items & Payments Payload
     * - 10,000 Quotations + Quotation Items
     * - 10,000 Stock Adjustments + Adjustment Items
     * - 10,000 Stock Transfers + Transfer Items
     * - 10,000 Expenses + Expense Payments
     */
    public function run(): void
    {
        $startTime = microtime(true);
        echo "\n========================================================================\n";
        echo "   ENTERPRISE 10,000-SCALE HIGH PERFORMANCE RETAIL SEEDER STARTING     \n";
        echo "   (Products, Sales, Sale Returns, Purchases, Purchase Returns,        \n";
        echo "    Quotations, Stock Adjustments, Stock Transfers, Expenses)          \n";
        echo "========================================================================\n\n";

        ini_set('memory_limit', '2048M');
        set_time_limit(0);
        DB::disableQueryLog();
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // 1. Ensure Master Data Prerequisites
        echo "[1/11] Initializing and validating master retail data...\n";
        $master = $this->ensureMasterData();
        echo "   -> Outlets: " . count($master['outlets']) . " | Categories: " . count($master['categories']) . " | Brands: " . count($master['brands']) . "\n";
        echo "   -> Customers: " . count($master['customers']) . " | Suppliers: " . count($master['suppliers']) . " | Units: " . count($master['units']) . "\n";
        echo "   -> Expense Categories: " . count($master['expense_categories']) . " | Payment Methods: " . count($master['payment_methods']) . "\n";

        // 2. Clean Target Tables
        echo "\n[2/11] Truncating target transaction & product tables for clean 10,000 counts...\n";
        $this->truncateTargetTables();
        echo "   -> Truncation completed successfully.\n";

        // 3. Seed 10,000 Products (5,000 Single, 3,000 Variation, 2,000 Unit-Linked)
        echo "\n[3/11] Generating exactly 10,000 Products (Single, Variation, Unit-Linked)...\n";
        $productsMetadata = $this->seedProducts($master);
        echo "   -> 10,000 Products generated (5,000 Single | 3,000 Variation | 2,000 Multi-Unit)!\n";

        // 4. Seed 10,000 Purchases (To support Purchase Returns and realistic supply chain)
        echo "\n[4/11] Generating exactly 10,000 Purchases with Purchase Items...\n";
        $purchasesMetadata = $this->seedPurchases($master, $productsMetadata);
        echo "   -> 10,000 Purchases generated!\n";

        // 5. Seed 10,000 Sales with Sale Items and Sale Payments
        echo "\n[5/11] Generating exactly 10,000 Sales with Sale Items & Payments...\n";
        $salesMetadata = $this->seedSales($master, $productsMetadata);
        echo "   -> 10,000 Sales generated!\n";

        // 6. Seed 10,000 Sale Returns
        echo "\n[6/11] Generating exactly 10,000 Sale Returns referencing Sales...\n";
        $this->seedSaleReturns($master, $salesMetadata, $productsMetadata);
        echo "   -> 10,000 Sale Returns generated!\n";

        // 7. Seed 10,000 Purchase Returns
        echo "\n[7/11] Generating exactly 10,000 Purchase Returns referencing Purchases...\n";
        $this->seedPurchaseReturns($master, $purchasesMetadata, $productsMetadata);
        echo "   -> 10,000 Purchase Returns generated!\n";

        // 8. Seed 10,000 Quotations with Line Items
        echo "\n[8/11] Generating exactly 10,000 Quotations with Quotation Items...\n";
        $this->seedQuotations($master, $productsMetadata);
        echo "   -> 10,000 Quotations generated!\n";

        // 9. Seed 10,000 Stock Adjustments with Adjustment Items
        echo "\n[9/11] Generating exactly 10,000 Stock Adjustments with Audit Items...\n";
        $this->seedStockAdjustments($master, $productsMetadata);
        echo "   -> 10,000 Stock Adjustments generated!\n";

        // 10. Seed 10,000 Stock Transfers with Transfer Items
        echo "\n[10/11] Generating exactly 10,000 Stock Transfers with Transfer Items...\n";
        $this->seedStockTransfers($master, $productsMetadata);
        echo "   -> 10,000 Stock Transfers generated!\n";

        // 11. Seed 10,000 Expenses with Expense Payments
        echo "\n[11/11] Generating exactly 10,000 Expenses with Expense Payments...\n";
        $this->seedExpenses($master);
        echo "   -> 10,000 Expenses generated!\n";

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $elapsed = round(microtime(true) - $startTime, 2);
        echo "\n========================================================================\n";
        echo "   SUCCESS! ALL 10,000-SCALE ENTERPRISE ENTITIES SEEDED IN {$elapsed}s! \n";
        echo "========================================================================\n\n";
    }

    /**
     * Safely insert records in small chunks to avoid PDO 65,535 placeholder limit.
     */
    protected function safeBatchInsert(string $table, array &$batch, int $chunkSize = 250): void
    {
        if (empty($batch)) {
            return;
        }
        foreach (array_chunk($batch, $chunkSize) as $chunk) {
            DB::table($table)->insert($chunk);
        }
        $batch = [];
    }

    /**
     * Ensure all necessary master data (Outlets, Units, Categories, Brands, Suppliers, Customers, Expense Categories, Payment Methods) exist.
     */
    protected function ensureMasterData(): array
    {
        $now = Carbon::now();

        // 1. Outlets / Warehouses (Ensure at least 6 active locations)
        $outletsList = [
            ['id' => 1, 'name' => 'Main Branch Store & Central Warehouse', 'phone' => '09-420011221', 'email' => 'main@retailpos.com', 'address' => 'Kamayut Township, Yangon', 'manager_name' => 'U Kyaw Zayar', 'is_active' => 1],
            ['id' => 2, 'name' => 'Downtown Flagship Outlet', 'phone' => '09-420011222', 'email' => 'downtown@retailpos.com', 'address' => 'Pabedan Township, Yangon', 'manager_name' => 'Daw Thandar Win', 'is_active' => 1],
            ['id' => 3, 'name' => 'Mandalay Regional Distribution Centre', 'phone' => '09-420011223', 'email' => 'mdy@retailpos.com', 'address' => '78th Street, Chanayethazan, Mandalay', 'manager_name' => 'U Min Thant', 'is_active' => 1],
            ['id' => 4, 'name' => 'Hledan High-Street Store', 'phone' => '09-420011224', 'email' => 'hledan@retailpos.com', 'address' => 'Hledan Road, Kamayut, Yangon', 'manager_name' => 'Daw Khin Myint', 'is_active' => 1],
            ['id' => 5, 'name' => 'Naypyidaw Capital Superstore', 'phone' => '09-420011225', 'email' => 'npt@retailpos.com', 'address' => 'Zabuthiri Township, Naypyidaw', 'manager_name' => 'U Thein Win', 'is_active' => 1],
            ['id' => 6, 'name' => 'Taunggyi Shan Hills Outlet', 'phone' => '09-420011226', 'email' => 'tg@retailpos.com', 'address' => 'Bogyoke Road, Taunggyi, Shan State', 'manager_name' => 'Daw Ni Ni Win', 'is_active' => 1],
        ];
        foreach ($outletsList as $o) {
            $existing = DB::table('outlets')->where('id', $o['id'])->first();
            if (!$existing) {
                DB::table('outlets')->insert(array_merge($o, ['created_at' => $now, 'updated_at' => $now]));
            } else {
                DB::table('outlets')->where('id', $o['id'])->update(['is_active' => 1, 'name' => $o['name']]);
            }
        }
        $outlets = DB::table('outlets')->where('is_active', 1)->get()->toArray();

        // 2. Units (Burmese & International Retail Units)
        $unitsList = [
            ['name' => 'ခု', 'description' => 'Piece / Item / Unit (Pcs)'],
            ['name' => 'ဘူး', 'description' => 'Bottle / Can / Small Box'],
            ['name' => 'ထည်', 'description' => 'Garment / Piece (Fashion)'],
            ['name' => 'ထုပ်', 'description' => 'Pack / Packet / Sachet'],
            ['name' => 'ကတ်', 'description' => 'Strip / Blister Card'],
            ['name' => 'ဖာ', 'description' => 'Carton / Master Case (Wholesale)'],
            ['name' => 'ဒါဇင်', 'description' => 'Dozen (12 Pcs)'],
            ['name' => 'တွဲ', 'description' => 'Bundle / Hanging Strip'],
            ['name' => 'ရံ', 'description' => 'Pair (Footwear / Gloves)'],
            ['name' => 'ချောင်း', 'description' => 'Stick / Bar / Tube'],
            ['name' => 'ပေါင်', 'description' => 'Pound (Weight Lb)'],
        ];
        foreach ($unitsList as $u) {
            $chk = DB::table('product_units')->where('name', $u['name'])->first();
            if (!$chk) {
                DB::table('product_units')->insert(array_merge($u, ['status' => 'Active', 'created_at' => $now, 'updated_at' => $now]));
            }
        }
        $units = DB::table('product_units')->get()->keyBy('name')->toArray();

        // 3. Product Categories
        $catNames = [
            '📱 ဖုန်းနှင့် အီလက်ထရောနစ်',
            '👗 အဝတ်အထည်နှင့် ဖက်ရှင်',
            '💄 အလှကုန်နှင့် အသားအရေထိန်းသိမ်းမှု',
            '☕ စားသောက်ကုန်နှင့် အဖျော်ယမကာ',
            '🏠 လူသုံးကုန်နှင့် အိမ်သုံးပစ္စည်း',
            '💊 ဆေးဝါးနှင့် ကျန်းမာရေး',
            '👟 ဖိနပ်နှင့် အားကစားသုံးပစ္စည်း',
            '✏️ စာရေးကိရိယာနှင့် ရုံးသုံးပစ္စည်း',
            '🍺 ဘီယာနှင့် အဖျော်ယမကာအေး',
            '🥨 မုန့်နှင့် သရေစာအမျိုးမျိုး'
        ];
        foreach ($catNames as $cName) {
            $c = DB::table('product_categories')->where('name', $cName)->first();
            if (!$c) {
                DB::table('product_categories')->insert(['name' => $cName, 'status' => 'Active', 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $categories = DB::table('product_categories')->get()->toArray();

        // 4. Brands
        $brandNames = [
            'Apple', 'Samsung', 'Xiaomi', 'Remax', 'Uniqlo', 'Giordano',
            'CeraVe', 'Bella', 'Premier', 'Royal Myanmar', 'Colgate', 'Dettol',
            'Sunlight', 'Blackmores', 'Tiger Balm', 'Nike', 'Adidas', 'Puma'
        ];
        foreach ($brandNames as $bName) {
            $b = DB::table('brands')->where('name', $bName)->first();
            if (!$b) {
                DB::table('brands')->insert(['name' => $bName, 'status' => 'Active', 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $brands = DB::table('brands')->get()->toArray();

        // 5. Payment Methods
        $paymentMethodsList = [
            ['name' => 'Cash', 'code' => 'CASH', 'icon' => 'payments_rounded', 'is_active' => 1, 'is_default' => 1, 'description' => 'ငွေသား လက်ငင်းရှင်းစနစ်'],
            ['name' => 'KBZPay', 'code' => 'KPAY', 'icon' => 'account_balance_wallet_rounded', 'is_active' => 1, 'is_default' => 0, 'description' => 'KBZPay QR & Mobile Wallet'],
            ['name' => 'CB Bank', 'code' => 'CB_BANK', 'icon' => 'account_balance_rounded', 'is_active' => 1, 'is_default' => 0, 'description' => 'CB Pay & Bank Transfer'],
            ['name' => 'Aya pay', 'code' => 'AYA_PAY', 'icon' => 'qr_code_scanner_rounded', 'is_active' => 1, 'is_default' => 0, 'description' => 'AYA Pay & Mobile Banking'],
            ['name' => 'WavePay', 'code' => 'WAVE_PAY', 'icon' => 'smartphone_rounded', 'is_active' => 1, 'is_default' => 0, 'description' => 'WavePay Mobile Money'],
            ['name' => 'Credit Card', 'code' => 'CARD', 'icon' => 'credit_card_rounded', 'is_active' => 1, 'is_default' => 0, 'description' => 'MPU / Visa / Master Card'],
            ['name' => 'Bank Transfer', 'code' => 'BANK', 'icon' => 'swap_horiz_rounded', 'is_active' => 1, 'is_default' => 0, 'description' => 'Direct Corporate Bank Wire Transfer'],
        ];
        foreach ($paymentMethodsList as $pm) {
            $chk = DB::table('payment_methods')->where('name', $pm['name'])->first();
            if (!$chk) {
                DB::table('payment_methods')->insert(array_merge($pm, ['created_at' => $now, 'updated_at' => $now]));
            }
        }
        $paymentMethods = DB::table('payment_methods')->get()->toArray();

        // 6. Expense Categories
        $expCatList = [
            ['name' => 'Store Rent & Facility Lease', 'code' => 'EXP-RENT', 'description' => 'ဆိုင်ခန်းနှင့် ဂိုဒေါင်ငှားရမ်းခများ'],
            ['name' => 'Electricity & Utilities (YESC)', 'code' => 'EXP-UTIL', 'description' => 'လျှပ်စစ်မီတာခ၊ ရေဖိုးနှင့် အထွေထွေအသုံးစရိတ်'],
            ['name' => 'Staff Salaries & Sales Commission', 'code' => 'EXP-SALARY', 'description' => 'ဝန်ထမ်းလစာ၊ ရက်မှန်ကြေးနှင့် ကော်မရှင်'],
            ['name' => 'Logistics, Courier & Delivery', 'code' => 'EXP-LOG', 'description' => 'ကုန်ပို့ဆောင်ခနှင့် အမြန်ချောပို့ စရိတ်များ'],
            ['name' => 'Digital Marketing & Ads Promotion', 'code' => 'EXP-MKT', 'description' => 'ကြော်ငြာနှင့် အရောင်းမြှင့်တင်ရေး စရိတ်များ'],
            ['name' => 'Equipment Maintenance & Repairs', 'code' => 'EXP-MAINT', 'description' => 'စက်ပစ္စည်းနှင့် အဲယားကွန်းပြင်ဆင်စရိတ်'],
            ['name' => 'Packaging Bags, Tape & Receipt Paper', 'code' => 'EXP-PACK', 'description' => 'ကြွပ်ကြွပ်အိတ်၊ ဘောက်ချာစက္ကူလိပ်နှင့် ထုပ်ပိုးပစ္စည်း'],
            ['name' => 'Office Supplies & Stationery', 'code' => 'EXP-OFFICE', 'description' => 'ရုံးသုံးစာရေးကိရိယာနှင့် အထွေထွေအသုံး'],
            ['name' => 'High-Speed Internet & IT Systems', 'code' => 'EXP-IT', 'description' => 'အင်တာနက်ဖိုးနှင့် POS စနစ်ဝန်ဆောင်ခ'],
            ['name' => 'Generator Fuel & Diesel Refill', 'code' => 'EXP-FUEL', 'description' => 'မီးစက်ဒီဇယ်ဆီနှင့် စက်ဆီ/ချောဆီ'],
            ['name' => 'Cleaning, Sanitation & Security', 'code' => 'EXP-SEC', 'description' => 'သန့်ရှင်းရေးနှင့် လုံခြုံရေးဝန်ဆောင်ခ'],
            ['name' => 'Taxes, Licenses & Municipal Fees', 'code' => 'EXP-TAX', 'description' => 'စည်ပင်လိုင်စင်ကြေးနှင့် အခွန်အခများ'],
        ];
        foreach ($expCatList as $ec) {
            $chk = DB::table('expense_categories')->where('name', $ec['name'])->first();
            if (!$chk) {
                DB::table('expense_categories')->insert(array_merge($ec, ['status' => 'Active', 'created_at' => $now, 'updated_at' => $now]));
            }
        }
        $expenseCategories = DB::table('expense_categories')->get()->toArray();

        // 7. Customers (Ensure 20+ Burmese Retail Customers)
        $customerList = [
            ['name' => 'Walk-in Customer', 'phone' => '09-000000000', 'email' => 'walkin@retail.com', 'tier' => 'Walk-in Regular'],
            ['name' => 'Daw Khin Myint', 'phone' => '09-421008811', 'email' => 'khinmyint@gmail.com', 'tier' => 'Gold VIP'],
            ['name' => 'U Kyaw Zayar', 'phone' => '09-798112233', 'email' => 'kyawzayar@gmail.com', 'tier' => 'Platinum VIP'],
            ['name' => 'Ma Thandar Win', 'phone' => '09-250334455', 'email' => 'thandar@gmail.com', 'tier' => 'Silver Regular'],
            ['name' => 'Ko Min Thant', 'phone' => '09-975556677', 'email' => 'minthant@gmail.com', 'tier' => 'Gold VIP'],
            ['name' => 'Daw Tin Tin Aye', 'phone' => '09-450001199', 'email' => 'tintinaye@gmail.com', 'tier' => 'Silver Regular'],
            ['name' => 'U Soe Lin Aung', 'phone' => '09-778899001', 'email' => 'soelinaung@gmail.com', 'tier' => 'Platinum VIP'],
            ['name' => 'Ma Hnin Yu Maw', 'phone' => '09-960112244', 'email' => 'hninyu@gmail.com', 'tier' => 'Silver Regular'],
            ['name' => 'U Thein Win', 'phone' => '09-430998877', 'email' => 'theinwin@gmail.com', 'tier' => 'Gold VIP'],
            ['name' => 'Daw Ni Ni Win', 'phone' => '09-261223344', 'email' => 'niniwin@gmail.com', 'tier' => 'Silver Regular'],
            ['name' => 'Ko Aung Ko Ko', 'phone' => '09-781234567', 'email' => 'aungkoko@gmail.com', 'tier' => 'Gold VIP'],
            ['name' => 'Ma Ei Ei Phyo', 'phone' => '09-952345678', 'email' => 'eieiphyo@gmail.com', 'tier' => 'Silver Regular'],
            ['name' => 'U Hlaing Bwar', 'phone' => '09-423456789', 'email' => 'hlaingbwar@gmail.com', 'tier' => 'Platinum VIP'],
            ['name' => 'Daw Myat Noe Oo', 'phone' => '09-793456780', 'email' => 'myatnoeoo@gmail.com', 'tier' => 'Gold VIP'],
            ['name' => 'Ko Hein Thu', 'phone' => '09-254567891', 'email' => 'heinthu@gmail.com', 'tier' => 'Silver Regular'],
            ['name' => 'Daw Khin Thiri', 'phone' => '09-975678912', 'email' => 'khinthiri@gmail.com', 'tier' => 'Platinum VIP'],
            ['name' => 'U Ye Min Htut', 'phone' => '09-456789123', 'email' => 'yeminhtut@gmail.com', 'tier' => 'Gold VIP'],
            ['name' => 'Ma Phyu Sin', 'phone' => '09-777891234', 'email' => 'phyusin@gmail.com', 'tier' => 'Silver Regular'],
            ['name' => 'Ko Sithu Aung', 'phone' => '09-968912345', 'email' => 'sithuaung@gmail.com', 'tier' => 'Gold VIP'],
            ['name' => 'Daw Aye Aye Thin', 'phone' => '09-439012346', 'email' => 'ayeayethin@gmail.com', 'tier' => 'Silver Regular'],
        ];
        foreach ($customerList as $idx => $cust) {
            $chk = DB::table('customers')->where('phone', $cust['phone'])->first();
            if (!$chk) {
                DB::table('customers')->insert([
                    'customer_id' => 'CUST-' . str_pad($idx + 1, 5, '0', STR_PAD_LEFT),
                    'name' => $cust['name'],
                    'phone' => $cust['phone'],
                    'email' => $cust['email'],
                    'tier' => $cust['tier'],
                    'reward_points' => rand(50, 2500),
                    'total_spent' => rand(150000, 4500000),
                    'total_visits' => rand(3, 45),
                    'status' => 'Active',
                    'notes' => 'Valued retail patron',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
        $customers = DB::table('customers')->get()->toArray();

        // 8. Suppliers (Ensure 10+ Retail Wholesale Suppliers)
        $supplierList = [
            ['name' => 'DKSH Myanmar Distribution Ltd', 'contact_person' => 'Ko Tun Tun', 'phone' => '09-770011221', 'email' => 'orders@dksh.com.mm', 'category' => 'Consumer FMCG & Health', 'city' => 'Yangon'],
            ['name' => 'Sunlight Apparel & Garment Wholesale', 'contact_person' => 'Daw Mary', 'phone' => '09-770011222', 'email' => 'supply@sunlightapparel.com', 'category' => 'Fashion & Apparel', 'city' => 'Yangon'],
            ['name' => 'Premier FMCG Foods & Drinks Importers', 'contact_person' => 'U Myo Min', 'phone' => '09-770011223', 'email' => 'orders@premiercoffee.com.mm', 'category' => 'Beverages & Coffee', 'city' => 'Yangon'],
            ['name' => 'Golden Eagle Electronics & Gadgets Trading', 'contact_person' => 'Ko Zaw Win', 'phone' => '09-770011224', 'email' => 'wholesale@goldeneagle.com', 'category' => 'Electronics & Mobile', 'city' => 'Mandalay'],
            ['name' => 'City Mart Retail Supply Chain Wholesale', 'contact_person' => 'Daw Sandar', 'phone' => '09-770011225', 'email' => 'b2b@citymart.com.mm', 'category' => 'Packaged Groceries', 'city' => 'Yangon'],
            ['name' => 'Apex Global Logistics & Wholesale Ltd', 'contact_person' => 'U Kyaw Swar', 'phone' => '09-770011226', 'email' => 'info@apexglobal.com', 'category' => 'General Merchandise', 'city' => 'Yangon'],
            ['name' => 'Mega Lifesciences Myanmar Co.', 'contact_person' => 'Dr. Thet Htar', 'phone' => '09-770011227', 'email' => 'supply@megalife.com.mm', 'category' => 'Pharmaceuticals & Health', 'city' => 'Yangon'],
            ['name' => 'Myanmar Golden Star Beverage Trading', 'contact_person' => 'Ko Htet Naing', 'phone' => '09-770011228', 'email' => 'sales@mgsbeverage.com', 'category' => 'Soft Drinks & Juice', 'city' => 'Yangon'],
            ['name' => 'Shwe Taung General Trading Wholesale', 'contact_person' => 'U Tin Htun', 'phone' => '09-770011229', 'email' => 'trading@shwetaung.com', 'category' => 'Household & Hygiene', 'city' => 'Mandalay'],
            ['name' => 'Shan Orchard Fresh Produce & Tea Ltd', 'contact_person' => 'Daw Nan Kham', 'phone' => '09-770011230', 'email' => 'sales@shanorchard.com', 'category' => 'Organic Snacks & Tea', 'city' => 'Taunggyi'],
        ];
        foreach ($supplierList as $sup) {
            $chk = DB::table('suppliers')->where('name', $sup['name'])->first();
            if (!$chk) {
                DB::table('suppliers')->insert(array_merge($sup, [
                    'country' => 'Myanmar',
                    'address' => $sup['city'] . ' Wholesale Hub, Myanmar',
                    'balance_due' => 0.00,
                    'payment_terms' => 'Net 30 Days',
                    'status' => 'Active',
                    'notes' => 'Primary approved vendor',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }
        $suppliers = DB::table('suppliers')->get()->toArray();

        // 9. Master Variation definitions
        $sizeVar = DB::table('variations')->where('name', 'Size')->first();
        if (!$sizeVar) {
            $sizeId = DB::table('variations')->insertGetId([
                'name' => 'Size',
                'variation_types' => json_encode(['S', 'M', 'L', 'XL', 'XXL', '38', '39', '40', '41', '42']),
                'status' => 'Active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $sizeId = $sizeVar->id;
        }

        $colorVar = DB::table('variations')->where('name', 'Color')->first();
        if (!$colorVar) {
            $colorId = DB::table('variations')->insertGetId([
                'name' => 'Color',
                'variation_types' => json_encode(['Black', 'White', 'Navy Blue', 'Charcoal Gray', 'Crimson Red', 'Olive Green', 'Rose Gold', 'Space Gray']),
                'status' => 'Active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $colorId = $colorVar->id;
        }

        return [
            'outlets' => $outlets,
            'units' => $units,
            'categories' => $categories,
            'brands' => $brands,
            'payment_methods' => $paymentMethods,
            'expense_categories' => $expenseCategories,
            'customers' => $customers,
            'suppliers' => $suppliers,
            'size_variation_id' => $sizeId,
            'color_variation_id' => $colorId,
        ];
    }

    /**
     * Truncate target tables for exact 10,000 counts.
     */
    protected function truncateTargetTables(): void
    {
        $tables = [
            'sale_payments',
            'sale_items',
            'sale_returns',
            'sales',
            'purchase_items',
            'purchase_returns',
            'purchases',
            'quotation_items',
            'quotations',
            'stock_adjustment_items',
            'stock_adjustments',
            'stock_transfer_items',
            'stock_transfers',
            'expense_payments',
            'expenses',
            'product_variant_values',
            'product_variants',
            'product_unit_links',
            'stock_balances',
            'stock_movements',
            'products',
        ];

        foreach ($tables as $t) {
            DB::table($t)->truncate();
        }
    }

    /**
     * Seed 10,000 Products:
     * - 1 to 5,000: Single Products (5,000)
     * - 5,001 to 8,000: Variation Products (3,000)
     * - 8,001 to 10,000: Multi-Unit Linked Products (2,000)
     */
    protected function seedProducts(array $master): array
    {
        $now = Carbon::now();
        $startDate = Carbon::create(2025, 5, 1, 8, 0, 0);

        $outlets = $master['outlets'];
        $categories = $master['categories'];
        $brands = $master['brands'];
        $suppliers = $master['suppliers'];
        $units = $master['units'];

        // Unit models
        $unitPiece = $units['ခု'] ?? (object)['id' => 1, 'name' => 'ခု'];
        $unitBottle = $units['ဘူး'] ?? $unitPiece;
        $unitGarment = $units['ထည်'] ?? $unitPiece;
        $unitPack = $units['ထုပ်'] ?? $unitPiece;
        $unitStrip = $units['ကတ်'] ?? $unitPiece;
        $unitCarton = $units['ဖာ'] ?? $unitPiece;
        $unitDozen = $units['ဒါဇင်'] ?? $unitPiece;
        $unitBundle = $units['တွဲ'] ?? $unitPiece;
        $unitPair = $units['ရံ'] ?? $unitPiece;

        // Realistic Product Templates
        $singleCatalogTemplates = [
            ['name' => 'သဘာဝ ရှားစောင်းလက်ပတ် ဂျယ်လ်', 'en' => 'Aloe Vera Soothing Gel 300ml', 'cat' => '💄 အလှကုန်နှင့် အသားအရေထိန်းသိမ်းမှု', 'unit' => $unitBottle, 'cost' => 4500, 'sell' => 7500],
            ['name' => 'ပရီးမီးယား ၃-အင်-၁ ကော်ဖီမစ်', 'en' => 'Premier 3-in-1 Coffee Mix (30 Sticks)', 'cat' => '☕ စားသောက်ကုန်နှင့် အဖျော်ယမကာ', 'unit' => $unitPack, 'cost' => 5200, 'sell' => 7200],
            ['name' => 'တော်ဝင်မြန်မာ လက်ဖက်ရည်', 'en' => 'Royal Myanmar Classic Tea Mix (30s)', 'cat' => '☕ စားသောက်ကုန်နှင့် အဖျော်ယမကာ', 'unit' => $unitPack, 'cost' => 5500, 'sell' => 7800],
            ['name' => 'Remax အမြန်အားသွင်းကြိုး Type-C 65W', 'en' => 'Remax Fast Charging Cable Type-C 65W', 'cat' => '📱 ဖုန်းနှင့် အီလက်ထရောနစ်', 'unit' => $unitPiece, 'cost' => 6500, 'sell' => 11000],
            ['name' => 'Xiaomi 20000mAh အားသွင်းပါဝါဘဏ်', 'en' => 'Xiaomi 20000mAh Fast Power Bank 22.5W', 'cat' => '📱 ဖုန်းနှင့် အီလက်ထရောနစ်', 'unit' => $unitPiece, 'cost' => 42000, 'sell' => 58000],
            ['name' => 'CeraVe အစိုဓာတ်ထိန်း မျက်နှာသစ်ဆေး', 'en' => 'CeraVe Hydrating Facial Cleanser 236ml', 'cat' => '💄 အလှကုန်နှင့် အသားအရေထိန်းသိမ်းမှု', 'unit' => $unitBottle, 'cost' => 28000, 'sell' => 38000],
            ['name' => 'Colgate သွားတိုက်ဆေး အစွမ်းထက်ကာကွယ်', 'en' => 'Colgate Total 12 Active Fresh Toothpaste', 'cat' => '🏠 လူသုံးကုန်နှင့် အိမ်သုံးပစ္စည်း', 'unit' => $unitPiece, 'cost' => 3200, 'sell' => 4800],
            ['name' => 'Dettol ပိုးသတ်ဆပ်ပြာတုံး Original', 'en' => 'Dettol Antiseptic Soap Bar 105g', 'cat' => '🏠 လူသုံးကုန်နှင့် အိမ်သုံးပစ္စည်း', 'unit' => $unitPiece, 'cost' => 1800, 'sell' => 2600],
            ['name' => 'Sunlight သံပုရာ သန်းရှင်းရေးဆပ်ပြာရည်', 'en' => 'Sunlight Lemon Dishwashing Liquid 750ml', 'cat' => '🏠 လူသုံးကုန်နှင့် အိမ်သုံးပစ္စည်း', 'unit' => $unitBottle, 'cost' => 3500, 'sell' => 4900],
            ['name' => 'Tiger Balm ရွှေရောင် အကိုက်အခဲပျောက် လိမ်းဆေး', 'en' => 'Tiger Balm White Ointment 19.4g', 'cat' => '💊 ဆေးဝါးနှင့် ကျန်းမာရေး', 'unit' => $unitBottle, 'cost' => 2400, 'sell' => 3500],
            ['name' => 'Blackmores ဗီတာမင်စီ ၁၀၀၀ မီလီဂရမ်', 'en' => 'Blackmores Bio C 1000mg 62 Tablets', 'cat' => '💊 ဆေးဝါးနှင့် ကျန်းမာရေး', 'unit' => $unitBottle, 'cost' => 32000, 'sell' => 45000],
            ['name' => 'Double A A4 ပုံနှိပ်စက္ကူ 80gsm ၅၀၀ ရွက်', 'en' => 'Double A Copier Paper 80gsm (500 Sheets)', 'cat' => '✏️ စာရေးကိရိယာနှင့် ရုံးသုံးပစ္စည်း', 'unit' => $unitPack, 'cost' => 11500, 'sell' => 15000],
            ['name' => 'ရမ်ဗာ အာလူးကြော် အရသာစုံ', 'en' => 'Yum Yum Crispy Potato Chips Hot & Spicy', 'cat' => '🥨 မုန့်နှင့် သရေစာအမျိုးမျိုး', 'unit' => $unitPack, 'cost' => 1400, 'sell' => 2000],
            ['name' => 'မြန်မာ ဘီယာ စည်သွပ်ဘူး ၃၃၀ မီလီလီတာ', 'en' => 'Myanmar Premium Lager Beer 330ml Can', 'cat' => '🍺 ဘီယာနှင့် အဖျော်ယမကာအေး', 'unit' => $unitBottle, 'cost' => 2200, 'sell' => 3000],
            ['name' => 'Apple iPhone 15 ဖုန်းကာဗာ MagSafe', 'en' => 'Apple iPhone 15 Magnetic Clear Protective Case', 'cat' => '📱 ဖုန်းနှင့် အီလက်ထရောနစ်', 'unit' => $unitPiece, 'cost' => 14000, 'sell' => 24000],
        ];

        $variationTemplates = [
            ['name' => 'Uniqlo ဂန္တဝင် ချည်သား ပိုလိုရှပ်အင်္ကျီ', 'en' => 'Uniqlo Classic Pique Cotton Polo Shirt', 'cat' => '👗 အဝတ်အထည်နှင့် ဖက်ရှင်', 'type' => 'Size', 'vals' => ['S', 'M', 'L', 'XL'], 'unit' => $unitGarment, 'cost' => 18000, 'sell' => 28000],
            ['name' => 'Giordano စတိုင်ကျ အကြောဆန့် ဂျင်းဘောင်းဘီ', 'en' => 'Giordano Slim Fit Stretch Denim Jeans', 'cat' => '👗 အဝတ်အထည်နှင့် ဖက်ရှင်', 'type' => 'Size', 'vals' => ['30', '32', '34', '36'], 'unit' => $unitGarment, 'cost' => 32000, 'sell' => 48000],
            ['name' => 'Nike လေဝင်လေထွက်ကောင်း အပြေးဖိနပ်', 'en' => 'Nike Air Zoom Breathable Running Shoes', 'cat' => '👟 ဖိနပ်နှင့် အားကစားသုံးပစ္စည်း', 'type' => 'Size', 'vals' => ['39', '40', '41', '42'], 'unit' => $unitPair, 'cost' => 65000, 'sell' => 95000],
            ['name' => 'Bella မက်ဆီ နှုတ်ခမ်းနီ Matte အရောင်စုံ', 'en' => 'Bella Velvet Long-Lasting Matte Lipstick', 'cat' => '💄 အလှကုန်နှင့် အသားအရေထိန်းသိမ်းမှု', 'type' => 'Color', 'vals' => ['Ruby Red', 'Nude Pink', 'Coral Rose', 'Mocha Brown'], 'unit' => $unitPiece, 'cost' => 6000, 'sell' => 9500],
            ['name' => 'Adidas ချည်စပော့ လေ့ကျင့်ခန်း ဘောင်းဘီတို', 'en' => 'Adidas Breathable Gym Training Shorts', 'cat' => '👗 အဝတ်အထည်နှင့် ဖက်ရှင်', 'type' => 'Size', 'vals' => ['M', 'L', 'XL'], 'unit' => $unitGarment, 'cost' => 14000, 'sell' => 22000],
            ['name' => 'Samsung Galaxy အကြမ်းခံ ဖုန်းကာဗာ', 'en' => 'Samsung Galaxy Shockproof Armor Case', 'cat' => '📱 ဖုန်းနှင့် အီလက်ထရောနစ်', 'type' => 'Color', 'vals' => ['Matte Black', 'Navy Blue', 'Silver Gray'], 'unit' => $unitPiece, 'cost' => 8500, 'sell' => 15000],
            ['name' => 'ရေစိုခံ အားကစား ကျောပိုးအိတ် အလတ်စား', 'en' => 'Waterproof Urban Sport Backpack 25L', 'cat' => '👗 အဝတ်အထည်နှင့် ဖက်ရှင်', 'type' => 'Color', 'vals' => ['Stealth Black', 'Carbon Gray', 'Deep Ocean Blue'], 'unit' => $unitPiece, 'cost' => 24000, 'sell' => 38000],
            ['name' => 'Puma ပေါ့ပါး သက်တောင့်သက်သာ ခြေညှပ်ဖိနပ်', 'en' => 'Puma Soft EVA Casual Comfort Slides', 'cat' => '👟 ဖိနပ်နှင့် အားကစားသုံးပစ္စည်း', 'type' => 'Size', 'vals' => ['39', '40', '41', '42'], 'unit' => $unitPair, 'cost' => 16000, 'sell' => 26000],
        ];

        $unitLinkingTemplates = [
            [
                'name' => 'ကိုကာကိုလာ သံဘူး အအေး',
                'en' => 'Coca-Cola Original Taste 330ml Can',
                'cat' => '☕ စားသောက်ကုန်နှင့် အဖျော်ယမကာ',
                'base_unit' => $unitBottle,
                'cost' => 1200,
                'sell' => 1600,
                'sale_units' => [
                    ['unit' => $unitBottle, 'name' => '၁ ဘူး (Single Can)', 'rate' => 1.0, 'default' => 1],
                    ['unit' => $unitPack, 'name' => '၆ ဘူးတွဲ (Pack of 6)', 'rate' => 6.0, 'default' => 0],
                    ['unit' => $unitCarton, 'name' => '၂၄ ဘူးဖာ (Carton 24s)', 'rate' => 24.0, 'default' => 0],
                ],
                'purchase_units' => [
                    ['unit' => $unitCarton, 'name' => '၂၄ ဘူးဖာ (Carton 24s)', 'rate' => 24.0, 'default' => 1],
                ],
            ],
            [
                'name' => 'ပါရာစီတမော အဖျားပျောက်ဆေး ၅၀၀ မီလီဂရမ်',
                'en' => 'Paracetamol 500mg Fever & Pain Relief',
                'cat' => '💊 ဆေးဝါးနှင့် ကျန်းမာရေး',
                'base_unit' => $unitStrip,
                'cost' => 600,
                'sell' => 1000,
                'sale_units' => [
                    ['unit' => $unitStrip, 'name' => '၁ ကတ် (Strip 10s)', 'rate' => 1.0, 'default' => 1],
                    ['unit' => $unitBottle, 'name' => '၁ ဘူး (Box 10 Strips)', 'rate' => 10.0, 'default' => 0],
                ],
                'purchase_units' => [
                    ['unit' => $unitBottle, 'name' => '၁ ဘူး (Box 10 Strips)', 'rate' => 10.0, 'default' => 1],
                ],
            ],
            [
                'name' => 'ယမ်ယမ် ကြက်သား အသင့်စားခေါက်ဆွဲခြောက်',
                'en' => 'Yum Yum Instant Noodles Chicken Flavor',
                'cat' => '🥨 မုန့်နှင့် သရေစာအမျိုးမျိုး',
                'base_unit' => $unitPack,
                'cost' => 750,
                'sell' => 1100,
                'sale_units' => [
                    ['unit' => $unitPack, 'name' => '၁ ထုပ် (Single Pack)', 'rate' => 1.0, 'default' => 1],
                    ['unit' => $unitBundle, 'name' => '၅ ထုပ်တွဲ (Bundle 5s)', 'rate' => 5.0, 'default' => 0],
                    ['unit' => $unitCarton, 'name' => '၃၀ ထုပ်ဖာ (Carton 30s)', 'rate' => 30.0, 'default' => 0],
                ],
                'purchase_units' => [
                    ['unit' => $unitCarton, 'name' => '၃၀ ထုပ်ဖာ (Carton 30s)', 'rate' => 30.0, 'default' => 1],
                ],
            ],
            [
                'name' => 'Pilot Gel Pen 0.5mm အပြာရောင် စာရေးဘောပင်',
                'en' => 'Pilot G2 Retractable Gel Roller Pen 0.5mm Blue',
                'cat' => '✏️ စာရေးကိရိယာနှင့် ရုံးသုံးပစ္စည်း',
                'base_unit' => $unitPiece,
                'cost' => 1500,
                'sell' => 2300,
                'sale_units' => [
                    ['unit' => $unitPiece, 'name' => '၁ ချောင်း (Single Pen)', 'rate' => 1.0, 'default' => 1],
                    ['unit' => $unitDozen, 'name' => '၁ ဒါဇင် (Dozen 12s)', 'rate' => 12.0, 'default' => 0],
                ],
                'purchase_units' => [
                    ['unit' => $unitBottle, 'name' => '၁ ဘူး (Box 24s)', 'rate' => 24.0, 'default' => 1],
                ],
            ],
            [
                'name' => 'အသင့်သောက် သစ်သီးဖျော်ရည် စုံလင်',
                'en' => 'Natural Fresh Tropical Fruit Juice 250ml',
                'cat' => '☕ စားသောက်ကုန်နှင့် အဖျော်ယမကာ',
                'base_unit' => $unitBottle,
                'cost' => 1100,
                'sell' => 1700,
                'sale_units' => [
                    ['unit' => $unitBottle, 'name' => '၁ ဘူး (Bottle)', 'rate' => 1.0, 'default' => 1],
                    ['unit' => $unitPack, 'name' => '၆ ဘူးတွဲ (Pack 6s)', 'rate' => 6.0, 'default' => 0],
                ],
                'purchase_units' => [
                    ['unit' => $unitCarton, 'name' => '၂၄ ဘူးဖာ (Carton 24s)', 'rate' => 24.0, 'default' => 1],
                ],
            ],
        ];

        $productsBatch = [];
        $variantsBatch = [];
        $variantValuesBatch = [];
        $unitLinksBatch = [];
        $stockBalancesBatch = [];
        $stockMovementsBatch = [];

        $variantIdCounter = 1;
        $totalProducts = 10000;

        for ($i = 1; $i <= $totalProducts; $i++) {
            $code = 'PRD-' . str_pad($i, 6, '0', STR_PAD_LEFT);
            $sku = 'SKU-' . str_pad($i, 6, '0', STR_PAD_LEFT);
            $barcode = '893' . str_pad($i, 10, '0', STR_PAD_LEFT);

            $brand = $brands[($i - 1) % count($brands)];
            $supplier = $suppliers[($i - 1) % count($suppliers)];

            // Category 1: Single Products (1 to 5000)
            if ($i <= 5000) {
                $tpl = $singleCatalogTemplates[($i - 1) % count($singleCatalogTemplates)];
                $cat = collect($categories)->firstWhere('name', $tpl['cat']) ?? $categories[($i - 1) % count($categories)];
                $name = "{$brand->name} {$tpl['name']} #{$i}";
                $altName = "{$tpl['en']} (#{$i})";
                $baseUnit = $tpl['unit'];
                $cost = (float)($tpl['cost'] + (($i * 17) % 15) * 100);
                $sell = (float)($tpl['sell'] + (($i * 17) % 15) * 150);
                $hasVariants = 0;

                $rPrices = [$sell, round($sell * 0.95), round($sell * 0.90)];
                $wPrices = [round($sell * 0.85), round($sell * 0.80), round($sell * 0.75)];

                $productStock = 0.0;
                // Add stock across all active outlets (including branch stores such as Hledan)
                foreach ($outlets as $o) {
                    $qty = 20.0 + (($i * 3 + $o->id * 7) % 60);
                    $productStock += $qty;
                    $stockBalancesBatch[] = [
                        'warehouse_id' => $o->id,
                        'product_id' => $i,
                        'product_variant_id' => null,
                        'quantity' => $qty,
                        'created_at' => $startDate,
                        'updated_at' => $now,
                    ];
                    $stockMovementsBatch[] = [
                        'warehouse_id' => $o->id,
                        'product_id' => $i,
                        'product_variant_id' => null,
                        'movement_type' => 'opening_stock',
                        'reference_type' => 'initial_seed',
                        'reference_id' => (string)$i,
                        'quantity_in' => $qty,
                        'quantity_out' => 0.0,
                        'balance_after' => $qty,
                        'created_by' => 1,
                        'note' => "Opening stock for {$name} at {$o->name}",
                        'created_at' => $startDate,
                    ];
                }
            }
            // Category 2: Variation Products (5001 to 8000)
            elseif ($i <= 8000) {
                $vIdx = $i - 5000;
                $tpl = $variationTemplates[($vIdx - 1) % count($variationTemplates)];
                $cat = collect($categories)->firstWhere('name', $tpl['cat']) ?? $categories[($vIdx - 1) % count($categories)];
                $name = "{$brand->name} {$tpl['name']} (Variations) #{$i}";
                $altName = "{$tpl['en']} (#{$i})";
                $baseUnit = $tpl['unit'];
                $cost = (float)($tpl['cost'] + (($i * 19) % 20) * 200);
                $sell = (float)($tpl['sell'] + (($i * 19) % 20) * 300);
                $hasVariants = 1;

                $rPrices = [$sell, round($sell * 0.95), round($sell * 0.90)];
                $wPrices = [round($sell * 0.85), round($sell * 0.80), round($sell * 0.75)];

                $attributeName = $tpl['type'];
                $attrId = ($attributeName === 'Size') ? $master['size_variation_id'] : $master['color_variation_id'];
                $varList = $tpl['vals'];

                $productStock = 0.0;
                foreach ($varList as $k => $varValue) {
                    $vId = $variantIdCounter++;
                    $vSku = $sku . '-' . Str::slug($varValue);
                    $vBarcode = '894' . str_pad($vId, 10, '0', STR_PAD_LEFT);
                    $vCost = $cost + ($k * 500);
                    $vSell = $sell + ($k * 800);
                    $vRPrices = [$vSell, round($vSell * 0.95), round($vSell * 0.90)];
                    $vWPrices = [round($vSell * 0.85), round($vSell * 0.80), round($vSell * 0.75)];

                    $variantsBatch[] = [
                        'id' => $vId,
                        'product_id' => $i,
                        'name' => "{$attributeName}: {$varValue}",
                        'sku' => $vSku,
                        'barcode' => $vBarcode,
                        'cost_price' => $vCost,
                        'selling_price' => $vSell,
                        'retail_prices' => json_encode($vRPrices),
                        'wholesale_price' => $vWPrices[0],
                        'wholesale_prices' => json_encode($vWPrices),
                        'is_active' => 1,
                        'sort_order' => $k,
                        'created_at' => $startDate,
                        'updated_at' => $now,
                    ];

                    $variantValuesBatch[] = [
                        'product_variant_id' => $vId,
                        'variation_attribute_id' => $attrId,
                        'variation_value_id' => $k + 1,
                        'attribute_name' => $attributeName,
                        'attribute_value' => $varValue,
                        'created_at' => $startDate,
                        'updated_at' => $now,
                    ];

                    // Multi-outlet stock balance for variant across all active outlets
                    foreach ($outlets as $o) {
                        $vQty = 15.0 + (($vId * 7 + $o->id * 5) % 35);
                        $productStock += $vQty;
                        $stockBalancesBatch[] = [
                            'warehouse_id' => $o->id,
                            'product_id' => $i,
                            'product_variant_id' => $vId,
                            'quantity' => $vQty,
                            'created_at' => $startDate,
                            'updated_at' => $now,
                        ];
                        $stockMovementsBatch[] = [
                            'warehouse_id' => $o->id,
                            'product_id' => $i,
                            'product_variant_id' => $vId,
                            'movement_type' => 'opening_stock',
                            'reference_type' => 'initial_seed',
                            'reference_id' => (string)$vId,
                            'quantity_in' => $vQty,
                            'quantity_out' => 0.0,
                            'balance_after' => $vQty,
                            'created_by' => 1,
                            'note' => "Opening stock for variant {$varValue} at {$o->name}",
                            'created_at' => $startDate,
                        ];
                    }
                }
            }
            // Category 3: Multi-Unit Linked Products (8001 to 10000)
            else {
                $uIdx = $i - 8000;
                $tpl = $unitLinkingTemplates[($uIdx - 1) % count($unitLinkingTemplates)];
                $cat = collect($categories)->firstWhere('name', $tpl['cat']) ?? $categories[($uIdx - 1) % count($categories)];
                $name = "{$brand->name} {$tpl['name']} (Multi-Unit Linked) #{$i}";
                $altName = "{$tpl['en']} (#{$i})";
                $baseUnit = $tpl['base_unit'];
                $cost = (float)($tpl['cost'] + (($i * 11) % 15) * 50);
                $sell = (float)($tpl['sell'] + (($i * 11) % 15) * 100);
                $hasVariants = 0;

                $rPrices = [$sell, round($sell * 0.95), round($sell * 0.90)];
                $wPrices = [round($sell * 0.85), round($sell * 0.80), round($sell * 0.75)];

                // Sale Units
                foreach ($tpl['sale_units'] as $su) {
                    $unitLinksBatch[] = [
                        'product_id' => $i,
                        'product_variant_id' => null,
                        'unit_type' => 'sale',
                        'unit_id' => $su['unit']->id,
                        'unit_name' => $su['name'],
                        'conversion_rate' => $su['rate'],
                        'is_default' => $su['default'],
                        'is_active' => 1,
                        'created_at' => $startDate,
                        'updated_at' => $now,
                    ];
                }

                // Purchase Units
                foreach ($tpl['purchase_units'] as $pu) {
                    $unitLinksBatch[] = [
                        'product_id' => $i,
                        'product_variant_id' => null,
                        'unit_type' => 'purchase',
                        'unit_id' => $pu['unit']->id,
                        'unit_name' => $pu['name'],
                        'conversion_rate' => $pu['rate'],
                        'is_default' => $pu['default'],
                        'is_active' => 1,
                        'created_at' => $startDate,
                        'updated_at' => $now,
                    ];
                }

                $productStock = 0.0;
                foreach ($outlets as $o) {
                    $qty = 40.0 + (($i * 7 + $o->id * 11) % 80);
                    $productStock += $qty;
                    $stockBalancesBatch[] = [
                        'warehouse_id' => $o->id,
                        'product_id' => $i,
                        'product_variant_id' => null,
                        'quantity' => $qty,
                        'created_at' => $startDate,
                        'updated_at' => $now,
                    ];
                    $stockMovementsBatch[] = [
                        'warehouse_id' => $o->id,
                        'product_id' => $i,
                        'product_variant_id' => null,
                        'movement_type' => 'opening_stock',
                        'reference_type' => 'initial_seed',
                        'reference_id' => (string)$i,
                        'quantity_in' => $qty,
                        'quantity_out' => 0.0,
                        'balance_after' => $qty,
                        'created_by' => 1,
                        'note' => "Opening stock for multi-unit {$name} at {$o->name}",
                        'created_at' => $startDate,
                    ];
                }
            }

            $productsBatch[] = [
                'id' => $i,
                'uuid' => (string)Str::uuid(),
                'code' => $code,
                'sku' => $sku,
                'barcode' => $barcode,
                'barcode_symbology' => 'CODE128',
                'category_id' => $cat->id,
                'brand_id' => $brand->id,
                'base_unit_id' => $baseUnit->id,
                'sale_unit_id' => $baseUnit->id,
                'purchase_unit_id' => $baseUnit->id,
                'name' => $name,
                'brand_name' => $brand->name,
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'alternative_name' => $altName,
                'category_name' => $cat->name,
                'sub_category_name' => null,
                'sub_category_id' => null,
                'unit_name' => $baseUnit->name,
                'purchase_unit' => $baseUnit->name,
                'sale_unit' => $baseUnit->name,
                'purchase_price' => $cost,
                'cost_price' => $cost,
                'retail_price' => $sell,
                'retail_prices' => json_encode($rPrices),
                'selling_price' => $sell,
                'wholesale_price' => $wPrices[0],
                'wholesale_prices' => json_encode($wPrices),
                'stock_quantity' => $productStock,
                'alert_quantity' => 10.0,
                'stock_alert' => 10.0,
                'has_variants' => $hasVariants,
                'order_tax' => 0.0,
                'tax_type' => 'Exclusive',
                'low_qty' => 5.0,
                'dine_in_price' => $sell,
                'take_away_price' => $sell,
                'delivery_price' => $sell,
                'image_url' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=400',
                'description' => "High standard retail product {$name} with certified Burmese & International barcode standards.",
                'note' => 'Enterprise seeded inventory record.',
                'status' => 'Active',
                'is_active' => 1,
                'added_by' => 'Super Admin',
                'created_at' => $startDate,
                'updated_at' => $now,
            ];

            // Chunk insert Products & related rows every 500 items safely
            if (count($productsBatch) >= 500) {
                $this->safeBatchInsert('products', $productsBatch, 250);
                $this->safeBatchInsert('product_variants', $variantsBatch, 250);
                $this->safeBatchInsert('product_variant_values', $variantValuesBatch, 250);
                $this->safeBatchInsert('product_unit_links', $unitLinksBatch, 250);
                $this->safeBatchInsert('stock_balances', $stockBalancesBatch, 250);
                $this->safeBatchInsert('stock_movements', $stockMovementsBatch, 250);
                echo "   -> Inserted {$i} / 10,000 Products and linked records...\n";
            }
        }

        $this->safeBatchInsert('products', $productsBatch, 250);
        $this->safeBatchInsert('product_variants', $variantsBatch, 250);
        $this->safeBatchInsert('product_variant_values', $variantValuesBatch, 250);
        $this->safeBatchInsert('product_unit_links', $unitLinksBatch, 250);
        $this->safeBatchInsert('stock_balances', $stockBalancesBatch, 250);
        $this->safeBatchInsert('stock_movements', $stockMovementsBatch, 250);

        return [
            'total' => 10000,
        ];
    }

    /**
     * Seed 10,000 Purchases with Purchase Items
     */
    protected function seedPurchases(array $master, array $prodMeta): array
    {
        $start = Carbon::create(2025, 5, 1, 8, 0, 0)->timestamp;
        $end = Carbon::create(2026, 10, 15, 18, 0, 0)->timestamp;
        $totalSeconds = $end - $start;

        $outlets = $master['outlets'];
        $suppliers = $master['suppliers'];

        $purchasesBatch = [];
        $purchaseItemsBatch = [];

        $purchasesSummary = [];

        for ($i = 1; $i <= 10000; $i++) {
            $ref = 'PO-' . str_pad($i, 7, '0', STR_PAD_LEFT);
            $secOffset = (int)(($i / 10000.0) * $totalSeconds);
            $dateObj = Carbon::createFromTimestamp($start + $secOffset)->subMinutes(($i * 13) % 45);
            $dateStr = $dateObj->format('Y-m-d H:i:s');

            $outlet = $outlets[($i - 1) % count($outlets)];
            $supplier = $suppliers[($i - 1) % count($suppliers)];

            // Select 1 to 2 products for purchase
            $prodId1 = (($i * 7 + 11) % 10000) + 1;
            $qty1 = (float)(10 + (($i * 5) % 50));
            $unitCost1 = (float)(3500 + (($prodId1 * 13) % 25000));
            $subtotal1 = $qty1 * $unitCost1;

            $grandTotal = $subtotal1;
            $paidAmount = ($i % 15 === 0) ? 0.0 : (($i % 10 === 0) ? round($grandTotal * 0.5) : $grandTotal);
            $dueAmount = $grandTotal - $paidAmount;
            $paymentStatus = ($dueAmount <= 0.0) ? 'Paid' : (($paidAmount > 0) ? 'Partial' : 'Unpaid');
            $paymentType = ($i % 3 === 0) ? 'Bank Transfer' : (($i % 2 === 0) ? 'Cash' : 'CB Bank');

            $payloadPayments = [];
            if ($paidAmount > 0) {
                $payloadPayments[] = [
                    'id' => 1,
                    'reference_no' => 'PAY-' . $ref,
                    'payment_date' => $dateStr,
                    'payment_type' => $paymentType,
                    'amount' => $paidAmount,
                    'user_name' => 'Super Admin',
                    'notes' => 'Supplier purchase invoice settlement',
                ];
            }

            $purchasesBatch[] = [
                'id' => $i,
                'reference_no' => $ref,
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => $dateStr,
                'status' => 'Received',
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'discount' => 0.0,
                'shipping' => 0.0,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
                'payment_type' => $paymentType,
                'payments_payload' => json_encode($payloadPayments),
                'notes' => "Official supplier procurement #{$ref} for {$outlet->name}",
                'created_by' => 'Super Admin',
                'user_id' => 1,
                'user_name' => 'Super Admin',
                'user_role' => 'Super Admin',
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            $purchaseItemsBatch[] = [
                'purchase_id' => $i,
                'product_id' => $prodId1,
                'product_variant_id' => null,
                'unit_id' => 1,
                'unit_name' => 'ခု',
                'conversion_rate' => 1.0,
                'product_code' => 'PRD-' . str_pad($prodId1, 6, '0', STR_PAD_LEFT),
                'product_name' => "Retail Product #{$prodId1}",
                'variant_name' => null,
                'unit_cost' => $unitCost1,
                'base_unit_cost' => $unitCost1,
                'quantity' => $qty1,
                'base_quantity' => $qty1,
                'tax' => 0.0,
                'discount' => 0.0,
                'subtotal' => $subtotal1,
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            $purchasesSummary[$i] = [
                'ref' => $ref,
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => $dateStr,
                'prod_id' => $prodId1,
                'unit_cost' => $unitCost1,
            ];

            if (count($purchasesBatch) >= 500) {
                $this->safeBatchInsert('purchases', $purchasesBatch, 250);
                $this->safeBatchInsert('purchase_items', $purchaseItemsBatch, 250);
                echo "   -> Inserted {$i} / 10,000 Purchases...\n";
            }
        }

        $this->safeBatchInsert('purchases', $purchasesBatch, 250);
        $this->safeBatchInsert('purchase_items', $purchaseItemsBatch, 250);

        return $purchasesSummary;
    }

    /**
     * Seed 10,000 Sales with Sale Items and Sale Payments
     */
    protected function seedSales(array $master, array $prodMeta): array
    {
        $start = Carbon::create(2025, 5, 1, 8, 30, 0)->timestamp;
        $end = Carbon::create(2026, 10, 15, 20, 0, 0)->timestamp;
        $totalSeconds = $end - $start;

        $outlets = $master['outlets'];
        $customers = $master['customers'];
        $payMethods = $master['payment_methods'];

        $salesBatch = [];
        $saleItemsBatch = [];
        $salePaymentsBatch = [];

        $salesSummary = [];

        for ($i = 1; $i <= 10000; $i++) {
            $ref = 'SA-' . str_pad($i, 7, '0', STR_PAD_LEFT);
            $secOffset = (int)(($i / 10000.0) * $totalSeconds);
            $dateObj = Carbon::createFromTimestamp($start + $secOffset)->subMinutes(($i * 19) % 55);
            $dateStr = $dateObj->format('Y-m-d H:i:s');

            $outlet = $outlets[($i - 1) % count($outlets)];
            $customer = $customers[($i - 1) % count($customers)];
            $payMethod = $payMethods[($i - 1) % count($payMethods)];

            // Select 1 to 2 products for sale
            $prodId1 = (($i * 13 + 7) % 10000) + 1;
            $qty1 = (float)(1 + (($i * 3) % 6));
            $cost1 = (float)(3000 + (($prodId1 * 11) % 20000));
            $price1 = round($cost1 * 1.45, 0);
            $subtotal1 = $qty1 * $price1;
            $cogs1 = $qty1 * $cost1;

            $hasSecondItem = ($i % 3 === 0);
            $subtotal = $subtotal1;
            $totalCogs = $cogs1;

            if ($hasSecondItem) {
                $prodId2 = (($i * 23 + 17) % 10000) + 1;
                $qty2 = (float)(1 + (($i * 7) % 4));
                $cost2 = (float)(2500 + (($prodId2 * 17) % 18000));
                $price2 = round($cost2 * 1.4, 0);
                $subtotal2 = $qty2 * $price2;
                $cogs2 = $qty2 * $cost2;

                $subtotal += $subtotal2;
                $totalCogs += $cogs2;
            }

            $taxRate = ($i % 5 === 0) ? 5.0 : 0.0;
            $taxAmount = round($subtotal * ($taxRate / 100.0), 2);
            $discount = ($subtotal > 20000 && $i % 4 === 0) ? 1000.0 : 0.0;
            $shipping = ($i % 12 === 0) ? 2500.0 : 0.0;
            $grandTotal = $subtotal + $taxAmount - $discount + $shipping;

            $paidAmount = ($i % 20 === 0) ? 0.0 : (($i % 12 === 0) ? round($grandTotal * 0.6) : $grandTotal);
            $dueAmount = $grandTotal - $paidAmount;
            $paymentStatus = ($dueAmount <= 0.0) ? 'Paid' : (($paidAmount > 0) ? 'Partial' : 'Unpaid');

            $salesBatch[] = [
                'id' => $i,
                'reference_no' => $ref,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => $dateStr,
                'status' => 'Completed',
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'discount' => $discount,
                'shipping' => $shipping,
                'subtotal' => $subtotal,
                'grand_total' => $grandTotal,
                'total_cogs' => $totalCogs,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'change_amount' => 0.0,
                'payment_status' => $paymentStatus,
                'payment_type' => $payMethod->name,
                'notes' => 'Sale registered via POS Terminal / Online store.',
                'pos_flag' => 1,
                'created_by' => 'Admin POS',
                'user_id' => 1,
                'user_name' => 'Admin POS',
                'user_role' => 'Super Admin',
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            $saleItemsBatch[] = [
                'sale_id' => $i,
                'product_id' => $prodId1,
                'product_variant_id' => null,
                'unit_id' => 1,
                'unit_name' => 'ခု',
                'conversion_rate' => 1.0,
                'product_code' => 'PRD-' . str_pad($prodId1, 6, '0', STR_PAD_LEFT),
                'product_name' => "Retail Product #{$prodId1}",
                'variant_name' => null,
                'unit_price' => $price1,
                'base_unit_price' => $price1,
                'base_unit_cost' => $cost1,
                'quantity' => $qty1,
                'base_quantity' => $qty1,
                'tax' => 0.0,
                'discount' => 0.0,
                'subtotal' => $subtotal1,
                'total_cogs' => $cogs1,
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            if ($hasSecondItem) {
                $saleItemsBatch[] = [
                    'sale_id' => $i,
                    'product_id' => $prodId2,
                    'product_variant_id' => null,
                    'unit_id' => 1,
                    'unit_name' => 'ခု',
                    'conversion_rate' => 1.0,
                    'product_code' => 'PRD-' . str_pad($prodId2, 6, '0', STR_PAD_LEFT),
                    'product_name' => "Retail Product #{$prodId2}",
                    'variant_name' => null,
                    'unit_price' => $price2,
                    'base_unit_price' => $price2,
                    'base_unit_cost' => $cost2,
                    'quantity' => $qty2,
                    'base_quantity' => $qty2,
                    'tax' => 0.0,
                    'discount' => 0.0,
                    'subtotal' => $subtotal2,
                    'total_cogs' => $cogs2,
                    'created_at' => $dateStr,
                    'updated_at' => $dateStr,
                ];
            }

            if ($paidAmount > 0) {
                $salePaymentsBatch[] = [
                    'sale_id' => $i,
                    'reference_no' => 'PAY-' . $ref,
                    'payment_date' => $dateStr,
                    'payment_type' => $payMethod->name,
                    'amount' => $paidAmount,
                    'received_amount' => $paidAmount,
                    'change_amount' => 0.0,
                    'notes' => 'Customer payment settlement',
                    'created_by' => 'Admin POS',
                    'user_id' => 1,
                    'user_name' => 'Admin POS',
                    'user_role' => 'Super Admin',
                    'created_at' => $dateStr,
                    'updated_at' => $dateStr,
                ];
            }

            $salesSummary[$i] = [
                'ref' => $ref,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => $dateStr,
                'prod_id' => $prodId1,
                'unit_price' => $price1,
            ];

            if (count($salesBatch) >= 500) {
                $this->safeBatchInsert('sales', $salesBatch, 250);
                $this->safeBatchInsert('sale_items', $saleItemsBatch, 250);
                $this->safeBatchInsert('sale_payments', $salePaymentsBatch, 250);
                echo "   -> Inserted {$i} / 10,000 Sales & Payments...\n";
            }
        }

        $this->safeBatchInsert('sales', $salesBatch, 250);
        $this->safeBatchInsert('sale_items', $saleItemsBatch, 250);
        $this->safeBatchInsert('sale_payments', $salePaymentsBatch, 250);

        return $salesSummary;
    }

    /**
     * Seed 10,000 Sale Returns referencing Sales
     */
    protected function seedSaleReturns(array $master, array $salesSummary, array $prodMeta): void
    {
        $saleReturnsBatch = [];

        $reasons = [
            'Defective / Damaged Product',
            'Customer Changed Mind',
            'Wrong Size / Color Picked',
            'Expired / Near Expiry Date',
            'Product Quality Dissatisfaction',
            'Packaging Torn at Counter'
        ];

        for ($i = 1; $i <= 10000; $i++) {
            $ref = 'SR-' . str_pad($i, 7, '0', STR_PAD_LEFT);
            $sale = $salesSummary[$i] ?? [
                'ref' => 'SA-' . str_pad($i, 7, '0', STR_PAD_LEFT),
                'customer_id' => 1,
                'customer_name' => 'Walk-in Customer',
                'warehouse_id' => 1,
                'warehouse_name' => 'Main Branch Store & Central Warehouse',
                'date' => '2026-06-15 14:00:00',
                'prod_id' => $i,
                'unit_price' => 7500,
            ];

            // Return happens 1 to 3 days after sale
            $dateObj = Carbon::parse($sale['date'])->addDays(($i % 3) + 1)->addHours(2);
            $dateStr = $dateObj->format('Y-m-d H:i:s');

            $reason = $reasons[($i - 1) % count($reasons)];
            $prodId = $sale['prod_id'];
            $unitPrice = $sale['unit_price'];
            $returnQty = 1.0;
            $grandTotal = $unitPrice * $returnQty;

            $status = ($i % 12 === 0) ? 'Pending' : 'Completed';
            $paidAmount = ($status === 'Completed') ? $grandTotal : 0.0;
            $dueAmount = $grandTotal - $paidAmount;
            $payStatus = ($dueAmount <= 0.0) ? 'Paid' : 'Unpaid';
            $refundTender = ($i % 3 === 0) ? 'KBZPay' : (($i % 2 === 0) ? 'Cash' : 'Store Credit');

            $itemsPayload = [
                [
                    'id' => 1,
                    'product_id' => $prodId,
                    'product_name' => "Retail Product #{$prodId}",
                    'product_code' => 'PRD-' . str_pad($prodId, 6, '0', STR_PAD_LEFT),
                    'unit_price' => $unitPrice,
                    'quantity' => $returnQty,
                    'subtotal' => $grandTotal,
                    'return_reason' => $reason,
                ]
            ];

            $paymentsPayload = [];
            if ($paidAmount > 0) {
                $paymentsPayload[] = [
                    'id' => 1,
                    'reference_no' => 'PAY-' . $ref,
                    'payment_date' => $dateStr,
                    'payment_type' => $refundTender,
                    'amount' => $paidAmount,
                    'user_name' => 'Super Admin',
                    'notes' => "Customer refund processed via {$refundTender}",
                ];
            }

            $saleReturnsBatch[] = [
                'id' => $i,
                'reference_no' => $ref,
                'sale_reference_no' => $sale['ref'],
                'customer_id' => $sale['customer_id'],
                'customer_name' => $sale['customer_name'],
                'warehouse_id' => $sale['warehouse_id'],
                'warehouse_name' => $sale['warehouse_name'],
                'date' => $dateStr,
                'status' => $status,
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'discount' => 0.0,
                'shipping' => 0.0,
                'subtotal' => $grandTotal,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $payStatus,
                'payment_type' => $refundTender,
                'notes' => "Customer return for {$sale['ref']}: {$reason}",
                'return_reason' => $reason,
                'refund_tender' => $refundTender,
                'items_payload' => json_encode($itemsPayload),
                'payments_payload' => json_encode($paymentsPayload),
                'created_by' => 'Super Admin',
                'user_id' => 1,
                'user_name' => 'Super Admin',
                'user_role' => 'Super Admin',
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            if (count($saleReturnsBatch) >= 500) {
                $this->safeBatchInsert('sale_returns', $saleReturnsBatch, 250);
                echo "   -> Inserted {$i} / 10,000 Sale Returns...\n";
            }
        }

        $this->safeBatchInsert('sale_returns', $saleReturnsBatch, 250);
    }

    /**
     * Seed 10,000 Purchase Returns referencing Purchases
     */
    protected function seedPurchaseReturns(array $master, array $purchasesSummary, array $prodMeta): void
    {
        $purchaseReturnsBatch = [];

        $reasons = [
            'Factory Defective Batch Returned',
            'Packaging Damaged During Logistics',
            'Wrong Product Specification Delivered',
            'Near Expiry Goods Returned for Credit Note',
            'Supplier Over-Shipment Reconciliation'
        ];

        for ($i = 1; $i <= 10000; $i++) {
            $ref = 'PR-' . str_pad($i, 7, '0', STR_PAD_LEFT);
            $purchase = $purchasesSummary[$i] ?? [
                'ref' => 'PO-' . str_pad($i, 7, '0', STR_PAD_LEFT),
                'supplier_id' => 1,
                'supplier_name' => 'DKSH Myanmar Distribution Ltd',
                'warehouse_id' => 1,
                'warehouse_name' => 'Main Branch Store & Central Warehouse',
                'date' => '2026-06-15 10:00:00',
                'prod_id' => $i,
                'unit_cost' => 5000,
            ];

            // Purchase Return happens 2 to 5 days after Purchase
            $dateObj = Carbon::parse($purchase['date'])->addDays(($i % 4) + 2)->addHours(3);
            $dateStr = $dateObj->format('Y-m-d H:i:s');

            $reason = $reasons[($i - 1) % count($reasons)];
            $prodId = $purchase['prod_id'];
            $unitCost = $purchase['unit_cost'];
            $returnQty = (float)(2 + ($i % 5));
            $grandTotal = $unitCost * $returnQty;

            $status = ($i % 15 === 0) ? 'Pending' : 'Completed';
            $paidAmount = ($status === 'Completed') ? $grandTotal : 0.0;
            $dueAmount = $grandTotal - $paidAmount;
            $payStatus = ($dueAmount <= 0.0) ? 'Paid' : 'Unpaid';
            $payType = ($i % 2 === 0) ? 'Bank Transfer' : 'Credit Note';

            $itemsPayload = [
                [
                    'id' => 1,
                    'product_id' => $prodId,
                    'product_name' => "Retail Product #{$prodId}",
                    'product_code' => 'PRD-' . str_pad($prodId, 6, '0', STR_PAD_LEFT),
                    'unit_cost' => $unitCost,
                    'quantity' => $returnQty,
                    'subtotal' => $grandTotal,
                    'return_reason' => $reason,
                ]
            ];

            $paymentsPayload = [];
            if ($paidAmount > 0) {
                $paymentsPayload[] = [
                    'id' => 1,
                    'reference_no' => 'PAY-' . $ref,
                    'payment_date' => $dateStr,
                    'payment_type' => $payType,
                    'amount' => $paidAmount,
                    'user_name' => 'Super Admin',
                    'notes' => "Vendor refund confirmed via {$payType}",
                ];
            }

            $purchaseReturnsBatch[] = [
                'id' => $i,
                'reference_no' => $ref,
                'purchase_reference_no' => $purchase['ref'],
                'supplier_id' => $purchase['supplier_id'],
                'supplier_name' => $purchase['supplier_name'],
                'warehouse_id' => $purchase['warehouse_id'],
                'outlet_id' => $purchase['warehouse_id'],
                'warehouse_name' => $purchase['warehouse_name'],
                'date' => $dateStr,
                'status' => $status,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $payStatus,
                'payment_type' => $payType,
                'subtotal' => $grandTotal,
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'discount' => 0.0,
                'shipping' => 0.0,
                'notes' => "Vendor return to {$purchase['supplier_name']}: {$reason}",
                'return_reason' => $reason,
                'items_payload' => json_encode($itemsPayload),
                'payments_payload' => json_encode($paymentsPayload),
                'created_by' => 'Super Admin',
                'user_id' => 1,
                'user_name' => 'Super Admin',
                'user_role' => 'Super Admin',
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            if (count($purchaseReturnsBatch) >= 500) {
                $this->safeBatchInsert('purchase_returns', $purchaseReturnsBatch, 250);
                echo "   -> Inserted {$i} / 10,000 Purchase Returns...\n";
            }
        }

        $this->safeBatchInsert('purchase_returns', $purchaseReturnsBatch, 250);
    }

    /**
     * Seed 10,000 Quotations with Quotation Items
     */
    protected function seedQuotations(array $master, array $prodMeta): void
    {
        $start = Carbon::create(2025, 5, 1, 9, 0, 0)->timestamp;
        $end = Carbon::create(2026, 10, 15, 17, 0, 0)->timestamp;
        $totalSeconds = $end - $start;

        $outlets = $master['outlets'];
        $customers = $master['customers'];

        $quotationsBatch = [];
        $quotationItemsBatch = [];

        $statuses = ['Ordered', 'Sent', 'Pending', 'Expired'];

        for ($i = 1; $i <= 10000; $i++) {
            $ref = 'QUO-' . str_pad($i, 7, '0', STR_PAD_LEFT);
            $secOffset = (int)(($i / 10000.0) * $totalSeconds);
            $dateObj = Carbon::createFromTimestamp($start + $secOffset)->subMinutes(($i * 17) % 50);
            $dateStr = $dateObj->format('Y-m-d H:i:s');
            $expiryDate = $dateObj->copy()->addDays(21)->format('Y-m-d');

            $outlet = $outlets[($i - 1) % count($outlets)];
            $customer = $customers[($i - 1) % count($customers)];
            $status = $statuses[($i - 1) % count($statuses)];

            $prodId = (($i * 17 + 23) % 10000) + 1;
            $qty = (float)(2 + ($i % 10));
            $unitPrice = (float)(4500 + (($prodId * 29) % 35000));
            $subtotal = $qty * $unitPrice;

            $taxRate = ($i % 4 === 0) ? 5.0 : 0.0;
            $taxAmount = round($subtotal * ($taxRate / 100.0), 2);
            $grandTotal = $subtotal + $taxAmount;

            $quotationsBatch[] = [
                'id' => $i,
                'reference_no' => $ref,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => $dateStr,
                'expiry_date' => $expiryDate,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'discount' => 0.0,
                'shipping' => 0.0,
                'grand_total' => $grandTotal,
                'status' => $status,
                'notes' => "Official price proposal for {$customer->name} ({$status})",
                'created_by' => 'Sales Representative',
                'user_id' => 1,
                'user_name' => 'Sales Representative',
                'user_role' => 'Sales Staff',
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            $quotationItemsBatch[] = [
                'quotation_id' => $i,
                'product_id' => $prodId,
                'product_variant_id' => null,
                'unit_id' => 1,
                'unit_name' => 'ခု',
                'conversion_rate' => 1.0,
                'product_code' => 'PRD-' . str_pad($prodId, 6, '0', STR_PAD_LEFT),
                'product_name' => "Retail Product #{$prodId}",
                'variant_name' => null,
                'unit_price' => $unitPrice,
                'base_unit_price' => $unitPrice,
                'quantity' => $qty,
                'base_quantity' => $qty,
                'tax' => 0.0,
                'discount' => 0.0,
                'subtotal' => $subtotal,
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            if (count($quotationsBatch) >= 500) {
                $this->safeBatchInsert('quotations', $quotationsBatch, 250);
                $this->safeBatchInsert('quotation_items', $quotationItemsBatch, 250);
                echo "   -> Inserted {$i} / 10,000 Quotations...\n";
            }
        }

        $this->safeBatchInsert('quotations', $quotationsBatch, 250);
        $this->safeBatchInsert('quotation_items', $quotationItemsBatch, 250);
    }

    /**
     * Seed 10,000 Stock Adjustments with Adjustment Items
     */
    protected function seedStockAdjustments(array $master, array $prodMeta): void
    {
        $start = Carbon::create(2025, 5, 1, 9, 30, 0)->timestamp;
        $end = Carbon::create(2026, 10, 15, 18, 30, 0)->timestamp;
        $totalSeconds = $end - $start;

        $outlets = $master['outlets'];

        $adjBatch = [];
        $adjItemsBatch = [];

        $reasons = [
            'Physical inventory recount audit surplus',
            'Damage write-off during shelf restocking',
            'Found uncatalogued packaging box in storage',
            'Near-expiry demo sample disposal',
            'Vendor quantity reconciliation correction',
            'Packaging tear damage write-off'
        ];

        for ($i = 1; $i <= 10000; $i++) {
            $ref = 'ADJ-' . str_pad($i, 7, '0', STR_PAD_LEFT);
            $secOffset = (int)(($i / 10000.0) * $totalSeconds);
            $dateObj = Carbon::createFromTimestamp($start + $secOffset)->subMinutes(($i * 23) % 40);
            $dateStr = $dateObj->format('Y-m-d H:i:s');

            $outlet = $outlets[($i - 1) % count($outlets)];
            $type = ($i % 2 === 0) ? 'Addition' : 'Subtraction';
            $note = $reasons[($i - 1) % count($reasons)];

            $prodId = (($i * 31 + 47) % 10000) + 1;
            $qty = (float)(1 + ($i % 5));
            $unitCost = (float)(3500 + (($prodId * 17) % 20000));
            $subtotal = $qty * $unitCost;

            $adjBatch[] = [
                'id' => $i,
                'reference_no' => $ref,
                'warehouse_id' => $outlet->id,
                'warehouse_name' => $outlet->name,
                'date' => $dateStr,
                'total_products' => 1,
                'total_quantity' => $qty,
                'type' => $type,
                'notes' => $note,
                'user_id' => 1,
                'created_by' => 'Inventory Auditor',
                'user_name' => 'Inventory Auditor',
                'user_role' => 'Warehouse Manager',
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            $adjItemsBatch[] = [
                'stock_adjustment_id' => $i,
                'product_id' => $prodId,
                'product_variant_id' => null,
                'product_code' => 'PRD-' . str_pad($prodId, 6, '0', STR_PAD_LEFT),
                'product_name' => "Retail Product #{$prodId}",
                'variant_name' => null,
                'unit_id' => 1,
                'unit_name' => 'ခု',
                'conversion_rate' => 1.0,
                'quantity' => $qty,
                'base_quantity' => $qty,
                'type' => $type,
                'unit_cost' => $unitCost,
                'subtotal' => $subtotal,
                'base_unit_cost' => $unitCost,
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            if (count($adjBatch) >= 500) {
                $this->safeBatchInsert('stock_adjustments', $adjBatch, 250);
                $this->safeBatchInsert('stock_adjustment_items', $adjItemsBatch, 250);
                echo "   -> Inserted {$i} / 10,000 Stock Adjustments...\n";
            }
        }

        $this->safeBatchInsert('stock_adjustments', $adjBatch, 250);
        $this->safeBatchInsert('stock_adjustment_items', $adjItemsBatch, 250);
    }

    /**
     * Seed 10,000 Stock Transfers with Transfer Items
     */
    protected function seedStockTransfers(array $master, array $prodMeta): void
    {
        $start = Carbon::create(2025, 5, 1, 10, 0, 0)->timestamp;
        $end = Carbon::create(2026, 10, 15, 19, 0, 0)->timestamp;
        $totalSeconds = $end - $start;

        $outlets = $master['outlets'];
        $transfersBatch = [];
        $transferItemsBatch = [];

        for ($i = 1; $i <= 10000; $i++) {
            $ref = 'TRF-' . str_pad($i, 7, '0', STR_PAD_LEFT);
            $secOffset = (int)(($i / 10000.0) * $totalSeconds);
            $dateObj = Carbon::createFromTimestamp($start + $secOffset)->subMinutes(($i * 11) % 50);
            $dateStr = $dateObj->format('Y-m-d H:i:s');

            $fromIdx = ($i - 1) % count($outlets);
            $toIdx = ($fromIdx + 1) % count($outlets);
            $fromOutlet = $outlets[$fromIdx];
            $toOutlet = $outlets[$toIdx];

            $status = ($i % 15 === 0) ? 'Pending' : (($i % 10 === 0) ? 'Sent' : 'Completed');

            $prodId = (($i * 37 + 59) % 10000) + 1;
            $qty = (float)(5 + ($i % 25));
            $unitCost = (float)(3200 + (($prodId * 23) % 25000));
            $subtotal = $qty * $unitCost;

            $transfersBatch[] = [
                'id' => $i,
                'reference_no' => $ref,
                'from_warehouse' => $fromOutlet->name,
                'from_warehouse_id' => $fromOutlet->id,
                'to_warehouse' => $toOutlet->name,
                'to_warehouse_id' => $toOutlet->id,
                'date' => $dateStr,
                'transfer_time' => $dateObj->format('H:i:s'),
                'items_count' => 1,
                'total_quantity' => $qty,
                'shipping_charge' => ($i % 5 === 0) ? 3500.0 : 0.0,
                'status' => $status,
                'notes' => "Inter-store transfer from {$fromOutlet->name} to {$toOutlet->name}",
                'created_by' => 'Logistics Controller',
                'received_by' => ($status === 'Completed') ? 'Branch Receiving Staff' : null,
                'received_at' => ($status === 'Completed') ? $dateObj->copy()->addHours(6) : null,
                'receiving_notes' => ($status === 'Completed') ? 'All items inspected and received in good condition.' : null,
                'user_id' => 1,
                'user_name' => 'Logistics Controller',
                'user_role' => 'Logistics Officer',
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            $transferItemsBatch[] = [
                'stock_transfer_id' => $i,
                'product_id' => $prodId,
                'product_variant_id' => null,
                'unit_id' => 1,
                'unit_name' => 'ခု',
                'conversion_rate' => 1.0,
                'product_code' => 'PRD-' . str_pad($prodId, 6, '0', STR_PAD_LEFT),
                'product_name' => "Retail Product #{$prodId}",
                'variant_name' => null,
                'quantity' => $qty,
                'base_quantity' => $qty,
                'unit_cost' => $unitCost,
                'base_unit_cost' => $unitCost,
                'subtotal' => $subtotal,
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            if (count($transfersBatch) >= 500) {
                $this->safeBatchInsert('stock_transfers', $transfersBatch, 250);
                $this->safeBatchInsert('stock_transfer_items', $transferItemsBatch, 250);
                echo "   -> Inserted {$i} / 10,000 Stock Transfers...\n";
            }
        }

        $this->safeBatchInsert('stock_transfers', $transfersBatch, 250);
        $this->safeBatchInsert('stock_transfer_items', $transferItemsBatch, 250);
    }

    /**
     * Seed 10,000 Expenses with Expense Payments
     */
    protected function seedExpenses(array $master): void
    {
        $start = Carbon::create(2025, 5, 1, 8, 0, 0)->timestamp;
        $end = Carbon::create(2026, 10, 15, 19, 0, 0)->timestamp;
        $totalSeconds = $end - $start;

        $outlets = $master['outlets'];
        $categories = $master['expense_categories'];
        $payMethods = $master['payment_methods'];

        $expensesBatch = [];
        $expensePaymentsBatch = [];

        $expenseTemplates = [
            ['title' => 'Monthly Storefront & Storage Lease Payment', 'min' => 250000, 'max' => 850000],
            ['title' => 'Commercial Electric Power Utility Bill (YESC)', 'min' => 120000, 'max' => 450000],
            ['title' => 'Branch Staff Monthly Salaries & Sales Allowances', 'min' => 450000, 'max' => 1200000],
            ['title' => 'Inter-city Courier, Delivery & Cargo Shipping', 'min' => 25000, 'max' => 95000],
            ['title' => 'Social Media Sponsored Ads & Campaign Promotion', 'min' => 50000, 'max' => 200000],
            ['title' => 'Commercial Air Conditioner & HVAC Servicing', 'min' => 35000, 'max' => 120000],
            ['title' => 'POS Thermal Receipt Paper Rolls & Shopping Bags', 'min' => 18000, 'max' => 65000],
            ['title' => 'Office Printing Paper, Pens & Admin Supplies', 'min' => 15000, 'max' => 45000],
            ['title' => 'Fiber Optic High-Speed Internet Subscription', 'min' => 38000, 'max' => 75000],
            ['title' => 'Standby Generator Diesel Fuel Refill 50 Liters', 'min' => 140000, 'max' => 320000],
            ['title' => 'Commercial Floor Sanitizer & Cleaning Supplies', 'min' => 12000, 'max' => 40000],
            ['title' => 'Municipal Business Signboard Tax & Operating Fee', 'min' => 45000, 'max' => 150000],
        ];

        for ($i = 1; $i <= 10000; $i++) {
            $ref = 'EXP-' . str_pad($i, 7, '0', STR_PAD_LEFT);
            $secOffset = (int)(($i / 10000.0) * $totalSeconds);
            $dateObj = Carbon::createFromTimestamp($start + $secOffset)->subMinutes(($i * 29) % 55);
            $dateStr = $dateObj->format('Y-m-d H:i:s');

            $outlet = $outlets[($i - 1) % count($outlets)];
            $cat = $categories[($i - 1) % count($categories)];
            $payMethod = $payMethods[($i - 1) % count($payMethods)];
            $tpl = $expenseTemplates[($i - 1) % count($expenseTemplates)];

            $amount = (float)($tpl['min'] + (($i * 137) % ($tpl['max'] - $tpl['min'] + 1000)));
            $amount = round($amount / 100) * 100; // Round to clean 100s

            $paidAmount = ($i % 20 === 0) ? 0.0 : (($i % 12 === 0) ? round($amount * 0.5) : $amount);
            $dueAmount = $amount - $paidAmount;
            $payStatus = ($dueAmount <= 0.0) ? 'Paid' : (($paidAmount > 0) ? 'Partial' : 'Unpaid');

            $expensesBatch[] = [
                'id' => $i,
                'reference_no' => $ref,
                'warehouse_name' => $outlet->name,
                'warehouse_id' => $outlet->id,
                'expense_category_id' => $cat->id,
                'expense_category_name' => $cat->name,
                'title' => "{$tpl['title']} #{$i}",
                'amount' => $amount,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $payStatus,
                'payment_method' => $payMethod->name,
                'payment_method_id' => $payMethod->id,
                'date' => $dateStr,
                'details' => "Operational retail expense disbursement for {$outlet->name}. Category: {$cat->name}.",
                'created_by' => 'Accountant',
                'user_id' => 1,
                'user_name' => 'Chief Accountant',
                'user_role' => 'Senior Accountant',
                'created_at' => $dateStr,
                'updated_at' => $dateStr,
            ];

            if ($paidAmount > 0) {
                $expensePaymentsBatch[] = [
                    'expense_id' => $i,
                    'reference_no' => 'PAY-' . $ref,
                    'payment_date' => $dateStr,
                    'payment_time' => $dateObj->format('H:i:s'),
                    'payment_type' => $payMethod->name,
                    'amount' => $paidAmount,
                    'received_amount' => $paidAmount,
                    'change_amount' => 0.0,
                    'notes' => 'Expense voucher payment disbursement',
                    'created_by' => 'Chief Accountant',
                    'user_id' => 1,
                    'user_name' => 'Chief Accountant',
                    'user_role' => 'Senior Accountant',
                    'created_at' => $dateStr,
                    'updated_at' => $dateStr,
                ];
            }

            if (count($expensesBatch) >= 500) {
                $this->safeBatchInsert('expenses', $expensesBatch, 250);
                $this->safeBatchInsert('expense_payments', $expensePaymentsBatch, 250);
                echo "   -> Inserted {$i} / 10,000 Expenses & Payments...\n";
            }
        }

        $this->safeBatchInsert('expenses', $expensesBatch, 250);
        $this->safeBatchInsert('expense_payments', $expensePaymentsBatch, 250);
    }
}
