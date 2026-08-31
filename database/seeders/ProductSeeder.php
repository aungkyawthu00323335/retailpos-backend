<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\ProductUnitLink;
use App\Models\Product;
use Illuminate\Support\Facades\Schema;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Categories
        $categories = [
            ['name' => 'Main Dishes', 'description' => 'Burgers, Steaks, Pastas & Rice Bowls', 'status' => 'Active'],
            ['name' => 'Appetizers & Starters', 'description' => 'Fries, Wings, Nachos & Salads', 'status' => 'Active'],
            ['name' => 'Desserts & Sweets', 'description' => 'Cakes, Ice cream & Pastries', 'status' => 'Active'],
            ['name' => 'Hot & Cold Beverages', 'description' => 'Coffee, Fresh Juices & Smoothies', 'status' => 'Active'],
            ['name' => 'Alcohol & Cocktails', 'description' => 'Beers, Wines & Signature Cocktails', 'status' => 'Active'],
            ['name' => 'Side Orders', 'description' => 'Extra toppings, dips & sauces', 'status' => 'Active'],
        ];

        foreach ($categories as $cat) {
            ProductCategory::firstOrCreate(['name' => $cat['name']], $cat);
        }

        // 2. Units
        $units = [
            ['name' => 'Plate', 'description' => 'Standard serving plate for hot dishes', 'status' => 'Active'],
            ['name' => 'Bowl', 'description' => 'Soup, salads, and noodles bowl', 'status' => 'Active'],
            ['name' => 'Glass', 'description' => 'Single serving glass for cold & hot drinks', 'status' => 'Active'],
            ['name' => 'Set', 'description' => 'Combo meal set or breakfast platter', 'status' => 'Active'],
            ['name' => 'Portion', 'description' => 'Individual dessert or side dish portion', 'status' => 'Active'],
            ['name' => 'Box', 'description' => 'Takeout box or package', 'status' => 'Active'],
            ['name' => 'Pcs', 'description' => 'Per piece item count', 'status' => 'Active'],
            ['name' => 'Bottle', 'description' => 'Bottled beverages or water', 'status' => 'Active'],
        ];

        foreach ($units as $u) {
            ProductUnit::firstOrCreate(['name' => $u['name']], $u);
        }

        // 3. Products
        $products = [
            [
                'code' => 'PRD-101',
                'name' => 'Classic Cheeseburger Deluxe',
                'alternative_name' => 'ချိစ်ဘာဂါ (Classic)',
                'category_name' => 'Main Dishes',
                'unit_name' => 'Plate',
                'purchase_price' => 4.50,
                'low_qty' => 10.0,
                'dine_in_price' => 12.50,
                'take_away_price' => 13.00,
                'delivery_price' => 14.50,
                'image_url' => 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=400',
                'description' => 'Juicy beef patty with aged cheddar, crisp lettuce, tomato and signature sauce.',
                'status' => 'Active',
                'added_by' => 'Admin User',
            ],
            [
                'code' => 'PRD-102',
                'name' => 'Truffle Mushroom Fettuccine',
                'alternative_name' => 'မှိုခေါက်ဆွဲဖြူ (Truffle Pasta)',
                'category_name' => 'Main Dishes',
                'unit_name' => 'Plate',
                'purchase_price' => 5.80,
                'low_qty' => 8.0,
                'dine_in_price' => 16.00,
                'take_away_price' => 16.50,
                'delivery_price' => 18.00,
                'image_url' => 'https://images.unsplash.com/photo-1621996346565-e3d5d6281691?w=400',
                'description' => 'Handmade fettuccine with wild forest mushrooms in rich creamy black truffle sauce.',
                'status' => 'Active',
                'added_by' => 'Admin User',
            ],
            [
                'code' => 'PRD-103',
                'name' => 'Crispy Truffle French Fries',
                'alternative_name' => 'အာလူးကြော် (Truffle Fries)',
                'category_name' => 'Appetizers & Starters',
                'unit_name' => 'Bowl',
                'purchase_price' => 1.80,
                'low_qty' => 15.0,
                'dine_in_price' => 6.50,
                'take_away_price' => 6.50,
                'delivery_price' => 7.50,
                'image_url' => 'https://images.unsplash.com/photo-1576107232684-1279f3908594?w=400',
                'description' => 'Golden shoestring fries tossed in white truffle oil and parmesan.',
                'status' => 'Active',
                'added_by' => 'Admin User',
            ],
            [
                'code' => 'PRD-104',
                'name' => 'Iced Caramel Macchiato',
                'alternative_name' => 'ကာရာမဲလ်ကော်ဖီအေး',
                'category_name' => 'Hot & Cold Beverages',
                'unit_name' => 'Glass',
                'purchase_price' => 1.20,
                'low_qty' => 20.0,
                'dine_in_price' => 5.50,
                'take_away_price' => 5.50,
                'delivery_price' => 6.00,
                'image_url' => 'https://images.unsplash.com/photo-1517701604599-bb29b565090c?w=400',
                'description' => 'Espresso with vanilla syrup, steamed milk and buttery caramel drizzle.',
                'status' => 'Active',
                'added_by' => 'Admin User',
            ],
            [
                'code' => 'PRD-105',
                'name' => 'Japanese Matcha Green Tea Latte',
                'alternative_name' => 'ဂျပန်မတ်ချာလက်တေး',
                'category_name' => 'Hot & Cold Beverages',
                'unit_name' => 'Glass',
                'purchase_price' => 1.40,
                'low_qty' => 12.0,
                'dine_in_price' => 5.80,
                'take_away_price' => 5.80,
                'delivery_price' => 6.50,
                'image_url' => 'https://images.unsplash.com/photo-1536256263959-770b48d82b0a?w=400',
                'description' => 'Ceremonial grade Uji matcha whisked with fresh whole milk.',
                'status' => 'Active',
                'added_by' => 'Admin User',
            ],
            [
                'code' => 'PRD-106',
                'name' => 'Molten Chocolate Lava Cake',
                'alternative_name' => 'ချောကလက်လာဗာကိတ်',
                'category_name' => 'Desserts & Sweets',
                'unit_name' => 'Portion',
                'purchase_price' => 2.50,
                'low_qty' => 6.0,
                'dine_in_price' => 8.50,
                'take_away_price' => 8.50,
                'delivery_price' => 9.50,
                'image_url' => 'https://images.unsplash.com/photo-1606313564200-e75d5e30476c?w=400',
                'description' => 'Warm chocolate cake with decadent oozing center served with vanilla gelato.',
                'status' => 'Active',
                'added_by' => 'Admin User',
            ],
        ];

        foreach ($products as $prod) {
            $product = Product::firstOrCreate(['code' => $prod['code']], $prod);

            // Keep simple seeded products compatible with the unit-linking
            // flow used by Product Details, Purchase and POS.
            if (Schema::hasTable('product_unit_links')) {
                $unit = ProductUnit::where('name', $prod['unit_name'])->first();
                foreach (['purchase', 'sale'] as $unitType) {
                    ProductUnitLink::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'product_variant_id' => null,
                            'unit_type' => $unitType,
                        ],
                        [
                            'unit_id' => $unit?->id,
                            'unit_name' => $prod['unit_name'],
                            'conversion_rate' => 1.0,
                            'is_default' => true,
                            'is_active' => true,
                        ],
                    );
                }
            }
        }
    }
}
