<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\ProductCategory;
use App\Models\Brand;
use App\Models\ProductUnit;

class Product200Seeder extends Seeder
{
    public function run(): void
    {
        // 1. Disable Foreign Keys and Clear Existing Products
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        
        echo "Clearing existing products data...\n";
        DB::table('products')->truncate();
        if (\Illuminate\Support\Facades\Schema::hasTable('product_variants')) {
            DB::table('product_variants')->truncate();
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('product_unit_links')) {
            DB::table('product_unit_links')->truncate();
        }

        // 2. Ensure Categories Exist
        $categoryData = [
            ['name' => 'Electronics & Gadgets', 'description' => 'Smart devices, hubs, cameras and consumer tech', 'status' => 'Active'],
            ['name' => 'Mobile & Accessories', 'description' => 'Smartphones, covers, cables, chargers & screen protectors', 'status' => 'Active'],
            ['name' => 'Computers & Laptops', 'description' => 'Notebooks, desktop PCs, monitors and workspace peripherals', 'status' => 'Active'],
            ['name' => 'Audio & Sound', 'description' => 'Headphones, earbuds, soundbars and wireless speakers', 'status' => 'Active'],
            ['name' => 'Wearables & Smartwatches', 'description' => 'Fitness trackers, luxury smartwatches and wristbands', 'status' => 'Active'],
            ['name' => 'Men\'s Fashion & Apparel', 'description' => 'Men shirts, jeans, polo shirts, suits and sportswear', 'status' => 'Active'],
            ['name' => 'Women\'s Fashion & Apparel', 'description' => 'Dresses, blouses, skirts, jackets and activewear', 'status' => 'Active'],
            ['name' => 'Footwear & Shoes', 'description' => 'Athletic runners, sneakers, formal leather shoes and boots', 'status' => 'Active'],
            ['name' => 'Bags & Luggage', 'description' => 'Backpacks, travel duffels, leather wallets and suitcases', 'status' => 'Active'],
            ['name' => 'Beauty & Personal Care', 'description' => 'Skincare, perfumes, hair styling and grooming tools', 'status' => 'Active'],
            ['name' => 'Home & Kitchen Living', 'description' => 'Coffee makers, air purifiers, blenders and cookware', 'status' => 'Active'],
            ['name' => 'Office & Stationery', 'description' => 'Ergonomic chairs, desks, notebooks and writing supplies', 'status' => 'Active'],
            ['name' => 'Gaming & Consoles', 'description' => 'Gaming consoles, controllers, RGB headsets and VR gear', 'status' => 'Active'],
            ['name' => 'Sports & Fitness Equipment', 'description' => 'Dumbbells, yoga mats, resistance bands and water bottles', 'status' => 'Active'],
            ['name' => 'Beverages & Packaged Food', 'description' => 'Gourmet coffee beans, tea, snacks and organic juices', 'status' => 'Active'],
        ];

        $catMap = [];
        foreach ($categoryData as $c) {
            $catObj = ProductCategory::firstOrCreate(['name' => $c['name']], $c);
            $catMap[$c['name']] = $catObj->id;
        }

        // 3. Ensure Brands Exist
        $brandData = [
            ['name' => 'Apple', 'code' => 'BR-APPLE', 'description' => 'Premium consumer electronics & iOS ecosystem', 'status' => 'Active'],
            ['name' => 'Samsung', 'code' => 'BR-SAM', 'description' => 'Smartphones, QLED TVs and Galaxy appliances', 'status' => 'Active'],
            ['name' => 'Sony', 'code' => 'BR-SONY', 'description' => 'PlayStation, Alpha cameras and noise cancelling audio', 'status' => 'Active'],
            ['name' => 'Nike', 'code' => 'BR-NIKE', 'description' => 'World leading athletic footwear and sports gear', 'status' => 'Active'],
            ['name' => 'Adidas', 'code' => 'BR-ADI', 'description' => 'Originals streetwear, Boost runners and athletic wear', 'status' => 'Active'],
            ['name' => 'Logitech', 'code' => 'BR-LOGI', 'description' => 'Precision MX mice, mechanical keyboards and webcams', 'status' => 'Active'],
            ['name' => 'Anker', 'code' => 'BR-ANK', 'description' => 'GaN chargers, PowerCore banks and Soundcore audio', 'status' => 'Active'],
            ['name' => 'Xiaomi', 'code' => 'BR-MI', 'description' => 'Smart home IoT, Redmi smartphones and lifestyle tech', 'status' => 'Active'],
            ['name' => 'Dell', 'code' => 'BR-DELL', 'description' => 'XPS ultrabooks, UltraSharp monitors and Alienware', 'status' => 'Active'],
            ['name' => 'Asus', 'code' => 'BR-ASUS', 'description' => 'ROG gaming hardware, Zenbook laptops and motherboards', 'status' => 'Active'],
            ['name' => 'JBL', 'code' => 'BR-JBL', 'description' => 'Pro audio, waterproof speakers and party sound systems', 'status' => 'Active'],
            ['name' => 'Bose', 'code' => 'BR-BOSE', 'description' => 'QuietComfort acoustic noise cancelling sound', 'status' => 'Active'],
            ['name' => 'Ray-Ban', 'code' => 'BR-RAY', 'description' => 'Iconic Aviator, Wayfarer and Clubmaster eyewear', 'status' => 'Active'],
            ['name' => 'Casio', 'code' => 'BR-CASIO', 'description' => 'G-Shock tough watches, Edifice and Pro Trek', 'status' => 'Active'],
            ['name' => 'Canon', 'code' => 'BR-CANON', 'description' => 'EOS mirrorless cameras, RF lenses and photo printers', 'status' => 'Active'],
            ['name' => 'Dyson', 'code' => 'BR-DYSON', 'description' => 'V-series cordless vacuums, Supersonic hair dryers', 'status' => 'Active'],
            ['name' => 'Nespresso', 'code' => 'BR-NESP', 'description' => 'Vertuo espresso machines and barista coffee pods', 'status' => 'Active'],
            ['name' => 'Puma', 'code' => 'BR-PUMA', 'description' => 'Motorsport apparel, Nitro running shoes & football gear', 'status' => 'Active'],
            ['name' => 'Zara', 'code' => 'BR-ZARA', 'description' => 'European fashion collections, blazers and accessories', 'status' => 'Active'],
            ['name' => 'Uniqlo', 'code' => 'BR-UNIQ', 'description' => 'LifeWear, Heattech thermals and AIRism apparel', 'status' => 'Active'],
            ['name' => 'L\'Oreal', 'code' => 'BR-LOR', 'description' => 'Men Expert grooming, revitalift serums and haircare', 'status' => 'Active'],
            ['name' => 'Rolex', 'code' => 'BR-ROL', 'description' => 'Swiss luxury chronometers, Submariner and Datejust', 'status' => 'Active'],
            ['name' => 'Razer', 'code' => 'BR-RAZ', 'description' => 'Chroma RGB gaming peripherals and Blade laptops', 'status' => 'Active'],
            ['name' => 'Lenovo', 'code' => 'BR-LEN', 'description' => 'ThinkPad business laptops and Legion gaming rigs', 'status' => 'Active'],
            ['name' => 'Philips', 'code' => 'BR-PHIL', 'description' => 'Sonicare oral care, OneBlade shavers and Hue lighting', 'status' => 'Active'],
        ];

        $brandMap = [];
        foreach ($brandData as $b) {
            $brandObj = Brand::firstOrCreate(['name' => $b['name']], $b);
            $brandMap[$b['name']] = $brandObj->id;
        }

        // 4. Ensure Units Exist
        $unitData = [
            ['name' => 'Piece', 'description' => 'Single individual unit', 'status' => 'Active'],
            ['name' => 'Pair', 'description' => 'Pair unit for shoes and gloves', 'status' => 'Active'],
            ['name' => 'Box', 'description' => 'Box packaging unit', 'status' => 'Active'],
            ['name' => 'Pack', 'description' => 'Multi-item pack', 'status' => 'Active'],
            ['name' => 'Set', 'description' => 'Complete bundle set', 'status' => 'Active'],
            ['name' => 'Bottle', 'description' => 'Liquid bottle container', 'status' => 'Active'],
            ['name' => 'Kg', 'description' => 'Kilogram weight unit', 'status' => 'Active'],
        ];

        $unitMap = [];
        foreach ($unitData as $u) {
            $uObj = ProductUnit::firstOrCreate(['name' => $u['name']], $u);
            $unitMap[$u['name']] = $uObj->id;
        }

        // 5. Template catalog items for generating 200 realistic products
        $catalogTemplates = [
            // Footwear
            ['name' => 'Nike Air Zoom Pegasus 40', 'alt' => 'နိုက်ကီ အပြေးဖိနပ် (Pegasus 40)', 'cat' => 'Footwear & Shoes', 'brand' => 'Nike', 'unit' => 'Pair', 'cost' => 65.0, 'price' => 130.0, 'ws' => 110.0, 'img' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=400', 'desc' => 'Lightweight responsive daily road running shoes.'],
            ['name' => 'Nike Air Jordan 1 Retro High OG', 'alt' => 'နိုက်ကီ ဂျော်ဒန် ၁ ဖိနပ် (High OG)', 'cat' => 'Footwear & Shoes', 'brand' => 'Nike', 'unit' => 'Pair', 'cost' => 95.0, 'price' => 180.0, 'ws' => 160.0, 'img' => 'https://images.unsplash.com/photo-1552346154-21d32810aba3?w=400', 'desc' => 'Iconic premium high-top leather basketball sneakers.'],
            ['name' => 'Adidas Ultraboost Light 23', 'alt' => 'အာဒီးဒတ် အာထရာဘုစ် ဖိနပ် (Light 23)', 'cat' => 'Footwear & Shoes', 'brand' => 'Adidas', 'unit' => 'Pair', 'cost' => 88.0, 'price' => 190.0, 'ws' => 165.0, 'img' => 'https://images.unsplash.com/photo-1587563871167-1ee9c731aefb?w=400', 'desc' => 'Epic energy return running shoes with Light BOOST capsule.'],
            ['name' => 'Adidas Samba Classic Leather', 'alt' => 'အာဒီးဒတ် ဆမ်ဘာ ဖိနပ် (Samba)', 'cat' => 'Footwear & Shoes', 'brand' => 'Adidas', 'unit' => 'Pair', 'cost' => 45.0, 'price' => 100.0, 'ws' => 85.0, 'img' => 'https://images.unsplash.com/photo-1584735935682-2f2b69dff9d2?w=400', 'desc' => 'Timeless indoor soccer and casual lifestyle sneakers.'],
            ['name' => 'Puma Velocity Nitro 2 Runner', 'alt' => 'ပူးမား နိုက်ထရို အပြေးဖိနပ်', 'cat' => 'Footwear & Shoes', 'brand' => 'Puma', 'unit' => 'Pair', 'cost' => 52.0, 'price' => 120.0, 'ws' => 100.0, 'img' => 'https://images.unsplash.com/photo-1608231387042-66d1773070a5?w=400', 'desc' => 'All-distance runner with NITRO FOAM cushioning.'],

            // Mobile & Accessories
            ['name' => 'Apple iPhone 15 Pro Max 256GB Titanium', 'alt' => 'အိုင်ဖုန်း ၁၅ ပရိုမက်စ် (256GB)', 'cat' => 'Mobile & Accessories', 'brand' => 'Apple', 'unit' => 'Piece', 'cost' => 920.0, 'price' => 1199.0, 'ws' => 1120.0, 'img' => 'https://images.unsplash.com/photo-1695048133142-1a20484d2569?w=400', 'desc' => 'Aerospace-grade titanium design with A17 Pro chip and 5x Telephoto.'],
            ['name' => 'Apple iPhone 15 Silicone Case MagSafe', 'alt' => 'အိုင်ဖုန်း ၁၅ ဆီလီကွန် ကာဗာ', 'cat' => 'Mobile & Accessories', 'brand' => 'Apple', 'unit' => 'Piece', 'cost' => 18.0, 'price' => 49.0, 'ws' => 40.0, 'img' => 'https://images.unsplash.com/photo-1601784551446-20c9e07cdbdb?w=400', 'desc' => 'Silky soft-touch silicone exterior with built-in MagSafe magnets.'],
            ['name' => 'Samsung Galaxy S24 Ultra 512GB Titanium', 'alt' => 'ဆမ်ဆောင်း S24 အာထရာ (512GB)', 'cat' => 'Mobile & Accessories', 'brand' => 'Samsung', 'unit' => 'Piece', 'cost' => 980.0, 'price' => 1299.0, 'ws' => 1210.0, 'img' => 'https://images.unsplash.com/photo-1610945265064-0e34e5519bbf?w=400', 'desc' => 'Galaxy AI integrated titanium phone with S Pen stylus.'],
            ['name' => 'Anker 737 Power Bank 24000mAh 140W', 'alt' => 'အန်ကာ ၁၄၀ ဝပ် ပါဝါဘဏ် (24000mAh)', 'cat' => 'Mobile & Accessories', 'brand' => 'Anker', 'unit' => 'Piece', 'cost' => 68.0, 'price' => 149.0, 'ws' => 125.0, 'img' => 'https://images.unsplash.com/photo-1609592424300-d85c30623f95?w=400', 'desc' => 'High-capacity 140W fast-charging power bank with smart screen.'],
            ['name' => 'Anker 67W GaNPrime 3-Port Wall Charger', 'alt' => 'အန်ကာ ၆၇ ဝပ် အားသွင်းခေါင်း', 'cat' => 'Mobile & Accessories', 'brand' => 'Anker', 'unit' => 'Piece', 'cost' => 24.0, 'price' => 59.99, 'ws' => 49.0, 'img' => 'https://images.unsplash.com/photo-1583863788434-e58a36330cf0?w=400', 'desc' => 'Compact GaN fast wall charger for laptop, phone and tablet.'],
            ['name' => 'Xiaomi 14 Ultra 5G Leica Quad Camera', 'alt' => 'ရှောင်မီ ၁၄ အာထရာ စမတ်ဖုန်း', 'cat' => 'Mobile & Accessories', 'brand' => 'Xiaomi', 'unit' => 'Piece', 'cost' => 750.0, 'price' => 999.0, 'ws' => 920.0, 'img' => 'https://images.unsplash.com/photo-1598327105666-5b89351aff97?w=400', 'desc' => 'Co-engineered with Leica 1-inch variable aperture sensor.'],

            // Audio & Sound
            ['name' => 'Sony WH-1000XM5 Wireless Headphones', 'alt' => 'ဆိုနီ WH-1000XM5 ကြိုးမဲ့နားကြပ်', 'cat' => 'Audio & Sound', 'brand' => 'Sony', 'unit' => 'Piece', 'cost' => 220.0, 'price' => 399.0, 'ws' => 350.0, 'img' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=400', 'desc' => 'Industry-leading noise canceling with 2 processors and 8 microphones.'],
            ['name' => 'Sony WF-1000XM5 ANC Wireless Earbuds', 'alt' => 'ဆိုနီ WF-1000XM5 အသံတိတ် နားကြပ်', 'cat' => 'Audio & Sound', 'brand' => 'Sony', 'unit' => 'Piece', 'cost' => 160.0, 'price' => 299.0, 'ws' => 260.0, 'img' => 'https://images.unsplash.com/photo-1590658268037-6bf12165a8df?w=400', 'desc' => 'Astonishing sound quality with the best noise cancellation earbuds.'],
            ['name' => 'Apple AirPods Pro (2nd Gen) USB-C', 'alt' => 'အဲပေါ့စ် ပရို (၂ စနစ် USB-C)', 'cat' => 'Audio & Sound', 'brand' => 'Apple', 'unit' => 'Piece', 'cost' => 155.0, 'price' => 249.0, 'ws' => 220.0, 'img' => 'https://images.unsplash.com/photo-1600294037681-c80b4cb5b434?w=400', 'desc' => 'Adaptive Audio, Active Noise Cancellation & Transparency mode.'],
            ['name' => 'Bose QuietComfort Ultra Headphones', 'alt' => 'ဘို့စ် QC အာထရာ ကြိုးမဲ့နားကြပ်', 'cat' => 'Audio & Sound', 'brand' => 'Bose', 'unit' => 'Piece', 'cost' => 240.0, 'price' => 429.0, 'ws' => 380.0, 'img' => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?w=400', 'desc' => 'World-class noise cancellation and breakthrough spatialized audio.'],
            ['name' => 'JBL Charge 5 Portable Waterproof Speaker', 'alt' => 'ဂျေဘီအယ် ချာ့ချ် ၅ ဘလူးတုသ် စပီကာ', 'cat' => 'Audio & Sound', 'brand' => 'JBL', 'unit' => 'Piece', 'cost' => 85.0, 'price' => 179.95, 'ws' => 150.0, 'img' => 'https://images.unsplash.com/photo-1608043152269-423dbba4e7e1?w=400', 'desc' => 'IP67 waterproof and dustproof with built-in powerbank.'],
            ['name' => 'JBL Flip 6 Compact Bluetooth Speaker', 'alt' => 'ဂျေဘီအယ် ဖလစ်ပ် ၆ စပီကာ', 'cat' => 'Audio & Sound', 'brand' => 'JBL', 'unit' => 'Piece', 'cost' => 58.0, 'price' => 129.95, 'ws' => 110.0, 'img' => 'https://images.unsplash.com/photo-1589003077984-894e133dabab?w=400', 'desc' => '2-way speaker system delivering powerful, crystal-clear sound.'],

            // Computers & Laptops
            ['name' => 'Apple MacBook Air 15" M3 Chip 512GB', 'alt' => 'မက်ခ်ဘွတ် အဲယား ၁၅ လက်မ M3', 'cat' => 'Computers & Laptops', 'brand' => 'Apple', 'unit' => 'Piece', 'cost' => 1050.0, 'price' => 1499.0, 'ws' => 1380.0, 'img' => 'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?w=400', 'desc' => 'Impossibly thin 15.3-inch Liquid Retina display with 18h battery.'],
            ['name' => 'Dell XPS 13 Plus OLED Touch Laptop', 'alt' => 'ဒဲလ် XPS ၁၃ OLED လက်ပ်တော့ပ်', 'cat' => 'Computers & Laptops', 'brand' => 'Dell', 'unit' => 'Piece', 'cost' => 990.0, 'price' => 1399.0, 'ws' => 1280.0, 'img' => 'https://images.unsplash.com/photo-1593642632823-8f785ba67e45?w=400', 'desc' => 'Sleek CNC machined aluminum with seamless glass touchpad.'],
            ['name' => 'Asus ROG Zephyrus G16 Gaming Laptop', 'alt' => 'အေဆူး ROG ဂိမ်းမင်း လက်ပ်တော့ပ်', 'cat' => 'Computers & Laptops', 'brand' => 'Asus', 'unit' => 'Piece', 'cost' => 1350.0, 'price' => 1899.0, 'ws' => 1720.0, 'img' => 'https://images.unsplash.com/photo-1603302576837-37561b2e2302?w=400', 'desc' => 'Intel Core Ultra 9 with NVIDIA GeForce RTX 4080 graphics.'],
            ['name' => 'Lenovo ThinkPad X1 Carbon Gen 12', 'alt' => 'လီနိုဗို သင့်ပတ် X1 ကာဘွန်', 'cat' => 'Computers & Laptops', 'brand' => 'Lenovo', 'unit' => 'Piece', 'cost' => 1100.0, 'price' => 1599.0, 'ws' => 1450.0, 'img' => 'https://images.unsplash.com/photo-1588872657578-7efd1f1555ed?w=400', 'desc' => 'Ultralight carbon-fiber business flagship with Intel Evo platform.'],
            ['name' => 'Logitech MX Master 3S Wireless Mouse', 'alt' => 'လော်ဂျီတက် MX မောက်စ် (Master 3S)', 'cat' => 'Computers & Laptops', 'brand' => 'Logitech', 'unit' => 'Piece', 'cost' => 55.0, 'price' => 99.99, 'ws' => 85.0, 'img' => 'https://images.unsplash.com/photo-1615663245857-ac93bb7c39e7?w=400', 'desc' => 'Quiet clicks with 8000 DPI track-on-glass MagSpeed electromagnetic scroll.'],
            ['name' => 'Logitech MX Mechanical Wireless Keyboard', 'alt' => 'လော်ဂျီတက် MX မက္ကင်းနစ် ကီးဘုတ်', 'cat' => 'Computers & Laptops', 'brand' => 'Logitech', 'unit' => 'Piece', 'cost' => 85.0, 'price' => 169.99, 'ws' => 145.0, 'img' => 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?w=400', 'desc' => 'Low-profile mechanical keys with smart backlighting and multi-OS.'],
            ['name' => 'Dell UltraSharp 27" 4K USB-C Hub Monitor', 'alt' => 'ဒဲလ် ၂၇ လက်မ 4K မော်နီတာ', 'cat' => 'Computers & Laptops', 'brand' => 'Dell', 'unit' => 'Piece', 'cost' => 380.0, 'price' => 579.0, 'ws' => 510.0, 'img' => 'https://images.unsplash.com/photo-1527443224154-c4a3942d3acf?w=400', 'desc' => 'IPS Black technology with 2000:1 contrast and 90W USB-C power delivery.'],

            // Wearables & Watches
            ['name' => 'Apple Watch Ultra 2 Titanium 49mm', 'alt' => 'အက်ပဲလ် ဝေါ့ချ် အာထရာ ၂', 'cat' => 'Wearables & Smartwatches', 'brand' => 'Apple', 'unit' => 'Piece', 'cost' => 590.0, 'price' => 799.0, 'ws' => 730.0, 'img' => 'https://images.unsplash.com/photo-1510017803434-a899398421b3?w=400', 'desc' => 'Rugged aerospace titanium with dual-frequency GPS and 100m water resistance.'],
            ['name' => 'Samsung Galaxy Watch 6 Classic 47mm', 'alt' => 'ဆမ်ဆောင်း ဂလက်ဆီ ဝေါ့ချ် ၆', 'cat' => 'Wearables & Smartwatches', 'brand' => 'Samsung', 'unit' => 'Piece', 'cost' => 220.0, 'price' => 399.0, 'ws' => 340.0, 'img' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=400', 'desc' => 'Rotating physical bezel with advanced sleep coaching and BIA body analysis.'],
            ['name' => 'Casio G-Shock GA-2100 "CasiOak" All Black', 'alt' => 'ကတ်စီယို ဂျီရှော့ခ် လက်ပတ်နာရီ', 'cat' => 'Wearables & Smartwatches', 'brand' => 'Casio', 'unit' => 'Piece', 'cost' => 48.0, 'price' => 99.0, 'ws' => 80.0, 'img' => 'https://images.unsplash.com/photo-1524805444758-089113d48a6d?w=400', 'desc' => 'Carbon Core Guard structure with 200-meter water resistance octagonal bezel.'],
            ['name' => 'Rolex Submariner Date Oystersteel 41mm', 'alt' => 'ရိုလက်စ် ဆပ်မရင်းနား ဇိမ်ခံနာရီ', 'cat' => 'Wearables & Smartwatches', 'brand' => 'Rolex', 'unit' => 'Piece', 'cost' => 7500.0, 'price' => 10250.0, 'ws' => 9800.0, 'img' => 'https://images.unsplash.com/photo-1522335789203-aabd1fc54bc9?w=400', 'desc' => 'Cerachrom ceramic bezel with 300m waterproof Oyster architecture.'],

            // Gaming & Consoles
            ['name' => 'Sony PlayStation 5 Slim Disc Edition', 'alt' => 'ဆိုနီ ပလေးစတေရှင် ၅ စလင်းမ်', 'cat' => 'Gaming & Consoles', 'brand' => 'Sony', 'unit' => 'Piece', 'cost' => 380.0, 'price' => 499.99, 'ws' => 460.0, 'img' => 'https://images.unsplash.com/photo-1606813907291-d86efa9b94db?w=400', 'desc' => '1TB custom ultra-fast SSD with 4K 120Hz ray tracing graphics.'],
            ['name' => 'Sony PS5 DualSense Wireless Controller', 'alt' => 'ဆိုနီ PS5 ကြိုးမဲ့ ဂိမ်းခလုတ်', 'cat' => 'Gaming & Consoles', 'brand' => 'Sony', 'unit' => 'Piece', 'cost' => 42.0, 'price' => 74.99, 'ws' => 62.0, 'img' => 'https://images.unsplash.com/photo-1592840496694-26d035b52b48?w=400', 'desc' => 'Immersive haptic feedback and dynamic adaptive triggers.'],
            ['name' => 'Razer BlackShark V2 Pro Wireless Gaming Headset', 'alt' => 'ရေဇာ ဘလက်ရှာခ် V2 ဂိမ်းနားကြပ်', 'cat' => 'Gaming & Consoles', 'brand' => 'Razer', 'unit' => 'Piece', 'cost' => 98.0, 'price' => 199.99, 'ws' => 170.0, 'img' => 'https://images.unsplash.com/photo-1599669454699-248893623440?w=400', 'desc' => 'TriForce Titanium 50mm drivers with HyperClear Super Wideband mic.'],
            ['name' => 'Razer DeathAdder V3 Pro Ultra-lightweight Mouse', 'alt' => 'ရေဇာ ဒတ်သ်အက်ဒါ V3 မောက်စ်', 'cat' => 'Gaming & Consoles', 'brand' => 'Razer', 'unit' => 'Piece', 'cost' => 75.0, 'price' => 149.99, 'ws' => 125.0, 'img' => 'https://images.unsplash.com/photo-1527864550417-7fd91fc51a46?w=400', 'desc' => '63g ultra-lightweight ergonomic mouse with Focus Pro 30K optical sensor.'],

            // Eyewear & Fashion
            ['name' => 'Ray-Ban Classic Aviator Polarized Gold/Green', 'alt' => 'ရေးဘန်း အေဗီယေတာ နေကာမျက်မှန်', 'cat' => 'Electronics & Gadgets', 'brand' => 'Ray-Ban', 'unit' => 'Piece', 'cost' => 85.0, 'price' => 175.0, 'ws' => 145.0, 'img' => 'https://images.unsplash.com/photo-1511499767150-a48a237f0083?w=400', 'desc' => 'Legendary teardrop metal frame with polarized G-15 crystal lenses.'],
            ['name' => 'Ray-Ban Original Wayfarer Classic Tortoise', 'alt' => 'ရေးဘန်း ဝေးဖဲရား နေကာမျက်မှန်', 'cat' => 'Electronics & Gadgets', 'brand' => 'Ray-Ban', 'unit' => 'Piece', 'cost' => 78.0, 'price' => 163.0, 'ws' => 135.0, 'img' => 'https://images.unsplash.com/photo-1572635196237-14b3f281503f?w=400', 'desc' => 'Signature acetate sunglasses with 100% UV protection.'],

            // Home Living & Appliances
            ['name' => 'Dyson V12 Detect Slim Cordless Vacuum', 'alt' => 'ဒိုက်ဆင် V12 ကြိုးမဲ့ ဖုန်စုပ်စက်', 'cat' => 'Home & Kitchen Living', 'brand' => 'Dyson', 'unit' => 'Piece', 'cost' => 450.0, 'price' => 649.99, 'ws' => 580.0, 'img' => 'https://images.unsplash.com/photo-1558317374-067fb5f30001?w=400', 'desc' => 'Laser reveals microscopic dust with scientific proof of a deep clean.'],
            ['name' => 'Dyson Supersonic Nural Hair Dryer', 'alt' => 'ဒိုက်ဆင် ဆံပင်လေမှုတ်စက်', 'cat' => 'Beauty & Personal Care', 'brand' => 'Dyson', 'unit' => 'Piece', 'cost' => 320.0, 'price' => 499.99, 'ws' => 440.0, 'img' => 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=400', 'desc' => 'Smart temperature sensors prevent extreme heat damage to enhance natural shine.'],
            ['name' => 'Nespresso Vertuo Pop Coffee Machine Spicy Red', 'alt' => 'နက်စ်ပရက်ဆို ကော်ဖီဖျော်စက်', 'cat' => 'Home & Kitchen Living', 'brand' => 'Nespresso', 'unit' => 'Piece', 'cost' => 70.0, 'price' => 129.0, 'ws' => 105.0, 'img' => 'https://images.unsplash.com/photo-1517668808822-9ebb02f2a0e6?w=400', 'desc' => 'Centrifusion technology brewing 4 cup sizes at the touch of a button.'],
            ['name' => 'Philips Sonicare DiamondClean 9000 Toothbrush', 'alt' => 'ဖိလစ်ပတ် လျှပ်စစ် သွားတိုက်တံ', 'cat' => 'Beauty & Personal Care', 'brand' => 'Philips', 'unit' => 'Piece', 'cost' => 110.0, 'price' => 199.99, 'ws' => 165.0, 'img' => 'https://images.unsplash.com/photo-1559825481-12a05cc00344?w=400', 'desc' => 'Removes up to 10x more plaque with Connected Sonicare App guidance.'],
            ['name' => 'Philips OneBlade Pro 360 Face & Body Trimmer', 'alt' => 'ဖိလစ်ပတ် မုတ်ဆိတ်ရိတ်စက်', 'cat' => 'Beauty & Personal Care', 'brand' => 'Philips', 'unit' => 'Piece', 'cost' => 45.0, 'price' => 84.99, 'ws' => 68.0, 'img' => 'https://images.unsplash.com/photo-1621607512214-68297480165e?w=400', 'desc' => 'Revolutionary grooming technology designed to trim, edge and shave any hair length.'],

            // Fashion & Apparel
            ['name' => 'Uniqlo Ultra Light Down Jacket Black', 'alt' => 'ယူနီကလို ဂျာကင်အနွေးထည်', 'cat' => 'Men\'s Fashion & Apparel', 'brand' => 'Uniqlo', 'unit' => 'Piece', 'cost' => 38.0, 'price' => 79.90, 'ws' => 65.0, 'img' => 'https://images.unsplash.com/photo-1551028719-00167b16eac5?w=400', 'desc' => 'Premium down with 750+ fill power, water-repellent and packable.'],
            ['name' => 'Uniqlo AIRism Cotton Oversized T-Shirt', 'alt' => 'ယူနီကလို AIRism တီရှပ်', 'cat' => 'Men\'s Fashion & Apparel', 'brand' => 'Uniqlo', 'unit' => 'Piece', 'cost' => 10.0, 'price' => 24.90, 'ws' => 19.0, 'img' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?w=400', 'desc' => 'Smooth AIRism interior with clean cotton exterior silhouette.'],
            ['name' => 'Zara Tailored Fit Wool-Blend Blazer Navy', 'alt' => 'ဇာရာ ယောက်ျားလေး ကုတ်အင်္ကျီ', 'cat' => 'Men\'s Fashion & Apparel', 'brand' => 'Zara', 'unit' => 'Piece', 'cost' => 55.0, 'price' => 129.0, 'ws' => 105.0, 'img' => 'https://images.unsplash.com/photo-1594938298603-c8148c4dae35?w=400', 'desc' => 'Notched lapel collar blazer with front flap pockets.'],
            ['name' => 'Zara Pleated Satin Midi Dress Emerald', 'alt' => 'ဇာရာ မိန်းကလေး ပိုးဂါဝန်ရှည်', 'cat' => 'Women\'s Fashion & Apparel', 'brand' => 'Zara', 'unit' => 'Piece', 'cost' => 35.0, 'price' => 89.90, 'ws' => 70.0, 'img' => 'https://images.unsplash.com/photo-1515372039744-b8f02a3ae446?w=400', 'desc' => 'Fluid satin midi dress with V-neckline and delicate pleated skirt.'],
            ['name' => 'L\'Oreal Men Expert Hydra Energetic Face Cream', 'alt' => 'လော်ရီရယ် မျက်နှာလိမ်းခရင်မ်', 'cat' => 'Beauty & Personal Care', 'brand' => 'L\'Oreal', 'unit' => 'Piece', 'cost' => 8.5, 'price' => 19.99, 'ws' => 15.0, 'img' => 'https://images.unsplash.com/photo-1556228720-195a672e8a03?w=400', 'desc' => 'Vitamin C & Guarana 24H anti-fatigue daily moisturiser.'],
        ];

        // 6. Generate 200 items by permutations & variations across categories
        $productsToInsert = [];
        $now = now();
        $totalTarget = 200;
        $templateCount = count($catalogTemplates);

        $colorVariations = ['Midnight Black', 'Platinum Silver', 'Deep Navy', 'Alpine White', 'Titanium Gray', 'Space Gray', 'Forest Green', 'Sunset Orange', 'Rose Gold', 'Crimson Red'];
        $sizeVariations = ['Standard', 'Compact', 'Pro', 'Max', 'Plus', 'Mini', 'Ultra', 'Deluxe', 'Studio', 'Elite'];

        for ($i = 1; $i <= $totalTarget; $i++) {
            $t = $catalogTemplates[($i - 1) % $templateCount];
            $variantCycle = (int)(($i - 1) / $templateCount);
            
            $color = $colorVariations[$variantCycle % count($colorVariations)];
            $sizeTag = $sizeVariations[($i + $variantCycle) % count($sizeVariations)];
            
            $prodName = $variantCycle == 0 ? $t['name'] : "{$t['name']} - {$color} ({$sizeTag})";
            $prodAlt = $variantCycle == 0 ? $t['alt'] : "{$t['alt']} ({$color})";
            $sku = 'PRD-' . str_pad($i, 4, '0', STR_PAD_LEFT);
            $barcode = '89345678' . str_pad($i, 5, '0', STR_PAD_LEFT);

            $cost = round($t['cost'] * (1.0 + ($variantCycle * 0.05)), 2);
            $retail = round($t['price'] * (1.0 + ($variantCycle * 0.05)), 2);
            $wholesale = round($t['ws'] * (1.0 + ($variantCycle * 0.05)), 2);

            $stockQty = rand(15, 250);
            $alertQty = rand(5, 20);

            $catName = $t['cat'] == 'Accessories' ? 'Electronics & Gadgets' : $t['cat'];
            $catId = $catMap[$catName] ?? 1;
            $brandId = $brandMap[$t['brand']] ?? 1;
            $unitId = $unitMap[$t['unit']] ?? 1;

            $productsToInsert[] = [
                'uuid' => (string) Str::uuid(),
                'code' => $barcode,
                'sku' => $sku,
                'barcode' => $barcode,
                'barcode_symbology' => 'CODE128',
                'category_id' => $catId,
                'brand_id' => $brandId,
                'base_unit_id' => $unitId,
                'purchase_unit_id' => $unitId,
                'sale_unit_id' => $unitId,
                'name' => $prodName,
                'alternative_name' => $prodAlt,
                'category_name' => $catName,
                'sub_category_name' => $catName,
                'brand_name' => $t['brand'],
                'unit_name' => $t['unit'],
                'purchase_unit' => $t['unit'],
                'sale_unit' => $t['unit'],
                'purchase_price' => $cost,
                'cost_price' => $cost,
                'retail_price' => $retail,
                'selling_price' => $retail,
                'wholesale_price' => $wholesale,
                'wholesale_prices' => json_encode([$wholesale]),
                'dine_in_price' => $retail,
                'take_away_price' => $retail,
                'delivery_price' => round($retail * 1.05, 2),
                'stock_quantity' => $stockQty,
                'alert_quantity' => $alertQty,
                'stock_alert' => $alertQty,
                'low_qty' => $alertQty,
                'has_variants' => false,
                'image_url' => $t['img'],
                'description' => $t['desc'] . ' [SKU: ' . $sku . ']',
                'status' => 'Active',
                'is_active' => true,
                'added_by' => 'Admin Manager',
                'created_by' => 1,
                'updated_by' => 1,
                'created_at' => $now->copy()->subMinutes(200 - $i),
                'updated_at' => $now->copy()->subMinutes(200 - $i),
            ];
        }

        // Batch insert for maximum performance
        echo "Inserting 200 retail products into database...\n";
        foreach (array_chunk($productsToInsert, 50) as $chunk) {
            DB::table('products')->insert($chunk);
        }

        // Seed one default purchase and sale link for every simple product.
        // This keeps ProductResource and Flutter Details valid even when the
        // product was inserted directly by this bulk seeder.
        if (Schema::hasTable('product_unit_links')) {
            $seededProducts = DB::table('products')
                ->whereIn('code', array_column($productsToInsert, 'code'))
                ->get([
                    'id',
                    'purchase_unit_id',
                    'purchase_unit',
                    'sale_unit_id',
                    'sale_unit',
                    'unit_name',
                ]);

            $unitLinks = [];
            foreach ($seededProducts as $product) {
                $purchaseUnit = $product->purchase_unit ?: ($product->unit_name ?: 'Piece');
                $saleUnit = $product->sale_unit ?: ($product->unit_name ?: 'Piece');

                $unitLinks[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'unit_type' => 'purchase',
                    'unit_id' => $product->purchase_unit_id,
                    'unit_name' => $purchaseUnit,
                    'conversion_rate' => 1.0,
                    'is_default' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $unitLinks[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'unit_type' => 'sale',
                    'unit_id' => $product->sale_unit_id,
                    'unit_name' => $saleUnit,
                    'conversion_rate' => 1.0,
                    'is_default' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($unitLinks, 100) as $chunk) {
                DB::table('product_unit_links')->insert($chunk);
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        echo "Successfully seeded 200 products!\n";
    }
}
