<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\SubCategory;
use App\Models\ProductUnit;
use App\Models\Product;
use App\Models\PaymentMethod;
use App\Models\ExpenseCategory;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Setting;
use App\Models\Currency;

class RetailSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Payment Methods (InfyPOS #/app/payment-methods)
        $paymentMethods = [
            ['name' => 'Cash', 'code' => 'CASH', 'icon' => 'payments_rounded', 'is_active' => true, 'is_default' => true],
            ['name' => 'Credit / Debit Card', 'code' => 'CARD', 'icon' => 'credit_card_rounded', 'is_active' => true, 'is_default' => false],
            ['name' => 'Bank Transfer', 'code' => 'BANK', 'icon' => 'account_balance_rounded', 'is_active' => true, 'is_default' => false],
            ['name' => 'KBZPay', 'code' => 'KBZPAY', 'icon' => 'qr_code_scanner_rounded', 'is_active' => true, 'is_default' => false],
            ['name' => 'WavePay', 'code' => 'WAVEPAY', 'icon' => 'phone_android_rounded', 'is_active' => true, 'is_default' => false],
            ['name' => 'Cheque', 'code' => 'CHEQUE', 'icon' => 'receipt_long_rounded', 'is_active' => true, 'is_default' => false],
            ['name' => 'Stripe', 'code' => 'STRIPE', 'icon' => 'public_rounded', 'is_active' => true, 'is_default' => false],
            ['name' => 'PayPal', 'code' => 'PAYPAL', 'icon' => 'account_balance_wallet_rounded', 'is_active' => true, 'is_default' => false],
        ];
        foreach ($paymentMethods as $pm) {
            PaymentMethod::firstOrCreate(['code' => $pm['code']], $pm);
        }

        // 2. Brands
        $brands = [
            ['name' => 'Nike', 'code' => 'BR-NIKE', 'description' => 'Athletic footwear and apparel', 'status' => 'Active', 'image_url' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=150'],
            ['name' => 'Apple', 'code' => 'BR-APPLE', 'description' => 'Consumer electronics and accessories', 'status' => 'Active', 'image_url' => 'https://images.unsplash.com/photo-1510557880182-3d4d3cba35a5?w=150'],
            ['name' => 'Sony', 'code' => 'BR-SONY', 'description' => 'Audio, visual and gaming electronics', 'status' => 'Active', 'image_url' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=150'],
            ['name' => 'Samsung', 'code' => 'BR-SAM', 'description' => 'Smartphones, wearables and displays', 'status' => 'Active', 'image_url' => 'https://images.unsplash.com/photo-1584006682522-dc17d6c0d9ac?w=150'],
            ['name' => 'Zara', 'code' => 'BR-ZARA', 'description' => 'Fast fashion clothing and accessories', 'status' => 'Active', 'image_url' => 'https://images.unsplash.com/photo-1489987707025-afc232f7ea0f?w=150'],
            ['name' => 'Logitech', 'code' => 'BR-LOGI', 'description' => 'Computer peripherals and workspace tech', 'status' => 'Active', 'image_url' => 'https://images.unsplash.com/photo-1527864550417-7fd91fc51a46?w=150'],
            ['name' => 'Ray-Ban', 'code' => 'BR-RAY', 'description' => 'Luxury sunglasses and eyewear', 'status' => 'Active', 'image_url' => 'https://images.unsplash.com/photo-1511499767150-a48a237f0083?w=150'],
        ];
        foreach ($brands as $b) {
            Brand::firstOrCreate(['name' => $b['name']], $b);
        }

        // 3. Product Categories
        $categories = [
            ['name' => 'Footwear & Shoes', 'description' => 'Running shoes, casual sneakers and boots', 'status' => 'Active'],
            ['name' => 'Mobile & Accessories', 'description' => 'Smartphones, cases and chargers', 'status' => 'Active'],
            ['name' => 'Audio & Gadgets', 'description' => 'Headphones, earphones and Bluetooth speakers', 'status' => 'Active'],
            ['name' => 'Fashion & Apparel', 'description' => 'Clothing, jackets and casual wear', 'status' => 'Active'],
            ['name' => 'Computer Accessories', 'description' => 'Keyboards, mice and workspace accessories', 'status' => 'Active'],
            ['name' => 'Eyewear & Fashion', 'description' => 'Sunglasses and optical frames', 'status' => 'Active'],
        ];
        foreach ($categories as $cat) {
            ProductCategory::firstOrCreate(['name' => $cat['name']], $cat);
        }

        // 3.1. Sub Categories (InfyPOS Sub-Category Taxonomy)
        $subCategories = [
            // Footwear & Shoes
            ['parent_category_name' => 'Footwear & Shoes', 'name' => 'Running Shoes', 'description' => 'Performance marathon and daily runners', 'status' => 'Active'],
            ['parent_category_name' => 'Footwear & Shoes', 'name' => 'Casual Sneakers', 'description' => 'Everyday lifestyle fashion sneakers', 'status' => 'Active'],
            ['parent_category_name' => 'Footwear & Shoes', 'name' => 'Formal Leather Shoes', 'description' => 'Business and dress footwear', 'status' => 'Active'],
            // Mobile & Accessories
            ['parent_category_name' => 'Mobile & Accessories', 'name' => 'Phone Cases & Covers', 'description' => 'Protective silicone and leather covers', 'status' => 'Active'],
            ['parent_category_name' => 'Mobile & Accessories', 'name' => 'Chargers & Cables', 'description' => 'Fast chargers and braided USB-C cables', 'status' => 'Active'],
            ['parent_category_name' => 'Mobile & Accessories', 'name' => 'Smart Watches & Bands', 'description' => 'Health and fitness wearables', 'status' => 'Active'],
            // Audio & Gadgets
            ['parent_category_name' => 'Audio & Gadgets', 'name' => 'Over-Ear Headphones', 'description' => 'Wireless noise canceling studio headphones', 'status' => 'Active'],
            ['parent_category_name' => 'Audio & Gadgets', 'name' => 'TWS Wireless Earbuds', 'description' => 'Compact in-ear true wireless buds', 'status' => 'Active'],
            ['parent_category_name' => 'Audio & Gadgets', 'name' => 'Portable Bluetooth Speakers', 'description' => 'Waterproof outdoor sound systems', 'status' => 'Active'],
            // Fashion & Apparel
            ['parent_category_name' => 'Fashion & Apparel', 'name' => 'Men\'s Shirts & Polos', 'description' => 'Cotton casual and formal shirts', 'status' => 'Active'],
            ['parent_category_name' => 'Fashion & Apparel', 'name' => 'Jeans & Trousers', 'description' => 'Denim pants and stretch chinos', 'status' => 'Active'],
            ['parent_category_name' => 'Fashion & Apparel', 'name' => 'Jackets & Outerwear', 'description' => 'Winter coats and windbreakers', 'status' => 'Active'],
            // Computer Accessories
            ['parent_category_name' => 'Computer Accessories', 'name' => 'Wireless Mice', 'description' => 'Ergonomic precision mice', 'status' => 'Active'],
            ['parent_category_name' => 'Computer Accessories', 'name' => 'Mechanical Keyboards', 'description' => 'RGB and low profile productivity boards', 'status' => 'Active'],
            // Eyewear & Fashion
            ['parent_category_name' => 'Eyewear & Fashion', 'name' => 'Classic Aviators', 'description' => 'Gold/black teardrop pilot shades', 'status' => 'Active'],
            ['parent_category_name' => 'Eyewear & Fashion', 'name' => 'Polarized Sunglasses', 'description' => 'UV400 protective polarized lenses', 'status' => 'Active'],
        ];
        foreach ($subCategories as $sub) {
            $parent = ProductCategory::where('name', $sub['parent_category_name'])->first();
            SubCategory::firstOrCreate(
                ['name' => $sub['name'], 'parent_category_name' => $sub['parent_category_name']],
                array_merge($sub, ['parent_category_id' => $parent ? $parent->id : null])
            );
        }

        // 4. Product Units
        $units = [
            ['name' => 'Piece', 'description' => 'Individual unit count', 'status' => 'Active'],
            ['name' => 'Pair', 'description' => 'Paired items (footwear/gloves)', 'status' => 'Active'],
            ['name' => 'Box', 'description' => 'Prepackaged box containers', 'status' => 'Active'],
            ['name' => 'Set', 'description' => 'Multiple bundled items', 'status' => 'Active'],
            ['name' => 'Kilogram', 'description' => 'Weight measurement in kg', 'status' => 'Active'],
        ];
        foreach ($units as $u) {
            ProductUnit::firstOrCreate(['name' => $u['name']], $u);
        }

        // 5. Products with Barcodes
        $products = [
            [
                'code' => '8934567890123',
                'barcode_symbology' => 'CODE128',
                'name' => 'Nike Air Zoom Pegasus 40',
                'brand_name' => 'Nike',
                'category_name' => 'Footwear & Shoes',
                'unit_name' => 'Pair',
                'purchase_unit' => 'Pair',
                'sale_unit' => 'Pair',
                'purchase_price' => 65.00,
                'retail_price' => 130.00,
                'stock_quantity' => 45.0,
                'alert_quantity' => 10.0,
                'order_tax' => 5.00,
                'tax_type' => 'Exclusive',
                'image_url' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=400',
                'description' => 'Responsive daily trainer running shoes with dual Zoom Air units.',
                'status' => 'Active',
            ],
            [
                'code' => '8934567890124',
                'barcode_symbology' => 'CODE128',
                'name' => 'Apple iPhone 15 Pro Silicone Case',
                'brand_name' => 'Apple',
                'category_name' => 'Mobile & Accessories',
                'unit_name' => 'Piece',
                'purchase_unit' => 'Piece',
                'sale_unit' => 'Piece',
                'purchase_price' => 18.00,
                'retail_price' => 49.00,
                'stock_quantity' => 80.0,
                'alert_quantity' => 15.0,
                'order_tax' => 7.00,
                'tax_type' => 'Inclusive',
                'image_url' => 'https://images.unsplash.com/photo-1510557880182-3d4d3cba35a5?w=400',
                'description' => 'MagSafe compatible soft-touch silicone protective case.',
                'status' => 'Active',
            ],
            [
                'code' => '8934567890125',
                'barcode_symbology' => 'CODE128',
                'name' => 'Sony WH-1000XM5 Noise Canceling Headphones',
                'brand_name' => 'Sony',
                'category_name' => 'Audio & Gadgets',
                'unit_name' => 'Piece',
                'purchase_unit' => 'Piece',
                'sale_unit' => 'Piece',
                'purchase_price' => 220.00,
                'retail_price' => 399.00,
                'stock_quantity' => 25.0,
                'alert_quantity' => 5.0,
                'order_tax' => 5.00,
                'tax_type' => 'Exclusive',
                'image_url' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=400',
                'description' => 'Industry leading noise cancellation with two processors and 8 microphones.',
                'status' => 'Active',
            ],
            [
                'code' => '8934567890126',
                'barcode_symbology' => 'CODE128',
                'name' => 'Samsung Galaxy Watch 6 Classic (47mm)',
                'brand_name' => 'Samsung',
                'category_name' => 'Mobile & Accessories',
                'unit_name' => 'Piece',
                'purchase_unit' => 'Piece',
                'sale_unit' => 'Piece',
                'purchase_price' => 190.00,
                'retail_price' => 349.00,
                'stock_quantity' => 18.0,
                'alert_quantity' => 5.0,
                'order_tax' => 5.00,
                'tax_type' => 'Exclusive',
                'image_url' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=400',
                'description' => 'Rotating bezel stainless steel fitness smartwatch with ECG & sleep coaching.',
                'status' => 'Active',
            ],
            [
                'code' => '8934567890127',
                'barcode_symbology' => 'CODE128',
                'name' => 'Zara Slim Fit Stretch Denim Jacket',
                'brand_name' => 'Zara',
                'category_name' => 'Fashion & Apparel',
                'unit_name' => 'Piece',
                'purchase_unit' => 'Piece',
                'sale_unit' => 'Piece',
                'purchase_price' => 28.00,
                'retail_price' => 69.90,
                'stock_quantity' => 55.0,
                'alert_quantity' => 12.0,
                'order_tax' => 5.00,
                'tax_type' => 'Inclusive',
                'image_url' => 'https://images.unsplash.com/photo-1551028719-00167b16eac5?w=400',
                'description' => 'Classic washed denim jacket with metal button closure.',
                'status' => 'Active',
            ],
            [
                'code' => '8934567890128',
                'barcode_symbology' => 'CODE128',
                'name' => 'Logitech MX Master 3S Wireless Mouse',
                'brand_name' => 'Logitech',
                'category_name' => 'Computer Accessories',
                'unit_name' => 'Piece',
                'purchase_unit' => 'Piece',
                'sale_unit' => 'Piece',
                'purchase_price' => 55.00,
                'retail_price' => 99.99,
                'stock_quantity' => 38.0,
                'alert_quantity' => 8.0,
                'order_tax' => 5.00,
                'tax_type' => 'Exclusive',
                'image_url' => 'https://images.unsplash.com/photo-1527864550417-7fd91fc51a46?w=400',
                'description' => 'Ergonomic performance mouse with 8K DPI sensor and quiet clicks.',
                'status' => 'Active',
            ],
            [
                'code' => '8934567890129',
                'barcode_symbology' => 'CODE128',
                'name' => 'Logitech MX Mechanical Wireless Keyboard',
                'brand_name' => 'Logitech',
                'category_name' => 'Computer Accessories',
                'unit_name' => 'Piece',
                'purchase_unit' => 'Piece',
                'sale_unit' => 'Piece',
                'purchase_price' => 95.00,
                'retail_price' => 169.99,
                'stock_quantity' => 20.0,
                'alert_quantity' => 5.0,
                'order_tax' => 5.00,
                'tax_type' => 'Exclusive',
                'image_url' => 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?w=400',
                'description' => 'Low-profile tactile quiet mechanical switches with smart illumination.',
                'status' => 'Active',
            ],
            [
                'code' => '8934567890130',
                'barcode_symbology' => 'CODE128',
                'name' => 'Ray-Ban Classic Aviator Sunglasses',
                'brand_name' => 'Ray-Ban',
                'category_name' => 'Eyewear & Fashion',
                'unit_name' => 'Piece',
                'purchase_unit' => 'Piece',
                'sale_unit' => 'Piece',
                'purchase_price' => 85.00,
                'retail_price' => 175.00,
                'stock_quantity' => 22.0,
                'alert_quantity' => 5.0,
                'order_tax' => 5.00,
                'tax_type' => 'Inclusive',
                'image_url' => 'https://images.unsplash.com/photo-1511499767150-a48a237f0083?w=400',
                'description' => 'Iconic crystal green polarized lenses with gold metal frame.',
                'status' => 'Active',
            ],
        ];
        foreach ($products as $p) {
            Product::firstOrCreate(['code' => $p['code']], $p);
        }

        // 6. Expense Categories
        $expCategories = [
            ['name' => 'Store Rent & Lease', 'description' => 'Premises rental fees'],
            ['name' => 'Electricity & Utilities', 'description' => 'Power, water and AC utilities'],
            ['name' => 'Packaging & Bags', 'description' => 'Bags, carton boxes and shopping wraps'],
            ['name' => 'Advertising & Marketing', 'description' => 'Social media ads and billboard displays'],
            ['name' => 'Store Maintenance', 'description' => 'Lighting, repairs and POS hardware'],
        ];
        foreach ($expCategories as $ec) {
            ExpenseCategory::firstOrCreate(['name' => $ec['name']], $ec);
        }

        // 7. Customers
        $customers = [
            ['name' => 'Walk-in Customer', 'email' => 'walkin@retailpos.com', 'phone' => '0900000000', 'tier' => 'Silver Regular', 'status' => 'Active'],
            ['name' => 'Aung Kyaw Moe', 'email' => 'aungkyaw@gmail.com', 'phone' => '09798765432', 'tier' => 'Gold VIP', 'status' => 'Active'],
            ['name' => 'Su Myat Noe', 'email' => 'sumyat@gmail.com', 'phone' => '09450012345', 'tier' => 'Silver Regular', 'status' => 'Active'],
            ['name' => 'Thura Zaw', 'email' => 'thurazaw@outlook.com', 'phone' => '09254112233', 'tier' => 'Platinum Premium', 'status' => 'Active'],
        ];
        foreach ($customers as $c) {
            Customer::firstOrCreate(['name' => $c['name']], $c);
        }

        // 8. Supplier Categories & Suppliers
        $supCats = [
            ['name' => 'Retail Electronics', 'color' => '#2563EB', 'description' => 'Suppliers of phones, gadgets, audio and electronics'],
            ['name' => 'Gadgets & Peripherals', 'color' => '#0D9488', 'description' => 'Computer accessories, mice and keyboards'],
            ['name' => 'Fashion & Footwear', 'color' => '#8B5CF6', 'description' => 'Athletic shoes, apparel and sneakers'],
            ['name' => 'Packaging & Bags', 'color' => '#10B981', 'description' => 'Boxes, poly bags and retail wrapping supplies'],
            ['name' => 'Eyewear & Accessories', 'color' => '#E11D48', 'description' => 'Optical glasses, sunglasses and frames'],
        ];
        foreach ($supCats as $sc) {
            \App\Models\SupplierCategory::firstOrCreate(['name' => $sc['name']], $sc);
        }

        $suppliers = [
            ['name' => 'Apex Global Distribution Ltd', 'contact_person' => 'U Myo Min', 'email' => 'sales@apexglobal.com', 'phone' => '09771122334', 'category' => 'Retail Electronics', 'balance_due' => 0.00, 'payment_terms' => 'Net 30 Days', 'status' => 'Active'],
            ['name' => 'Pacific Tech Importers Co.', 'contact_person' => 'Daw Hnin Nu', 'email' => 'orders@pacifictech.com', 'phone' => '09882233445', 'category' => 'Gadgets & Peripherals', 'balance_due' => 450.00, 'payment_terms' => 'Net 15 Days', 'status' => 'Active'],
            ['name' => 'Sunlight Apparel & Goods Wholesale', 'contact_person' => 'Ko Lin Htet', 'email' => 'contact@sunlightapparel.com', 'phone' => '09993344556', 'category' => 'Fashion & Footwear', 'balance_due' => 0.00, 'payment_terms' => 'Cash On Delivery', 'status' => 'Active'],
        ];
        foreach ($suppliers as $s) {
            Supplier::firstOrCreate(['name' => $s['name']], $s);
        }

        // 9. Currencies
        $currencies = [
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'rate' => 1.00, 'is_default' => true],
            ['code' => 'MMK', 'name' => 'Myanmar Kyat', 'symbol' => 'Ks', 'rate' => 3500.00, 'is_default' => false],
            ['code' => 'THB', 'name' => 'Thai Baht', 'symbol' => '฿', 'rate' => 36.50, 'is_default' => false],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'rate' => 0.92, 'is_default' => false],
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'rate' => 1.35, 'is_default' => false],
        ];
        foreach ($currencies as $cur) {
            Currency::firstOrCreate(['code' => $cur['code']], $cur);
        }

        // 10. Store Settings
        $settings = [
            'company_name' => 'Infy Retail POS',
            'company_email' => 'contact@infypos.com',
            'company_phone' => '+95 9 1234 5678',
            'company_address' => 'No. 124, Merchant Road, Yangon, Myanmar',
            'default_currency' => 'USD',
            'currency_symbol' => '$',
            'currency_position' => 'Prefix',
            'default_warehouse' => 'Main Warehouse Branch',
            'default_customer' => 'Walk-in Customer',
            'receipt_header' => 'Welcome to Infy Retail POS!',
            'receipt_footer' => 'Thank you for shopping with us! Please come again.',
            'tax_rate' => '5',
            'barcode_symbology' => 'CODE128',
            'receipt_width' => '80mm',
        ];
        foreach ($settings as $key => $val) {
            Setting::set($key, $val);
        }
    }
}
