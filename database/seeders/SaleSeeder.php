<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Customer;
use App\Models\Product;

class SaleSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('sale_payments')->truncate();
        DB::table('sale_items')->truncate();
        DB::table('sales')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $products = Product::take(10)->get();

        $salesData = [
            [
                'ref' => 'SA_1114186',
                'user' => 'Admin POS',
                'customer' => 'walk-in-customer',
                'warehouse' => 'warehouse',
                'status' => 'Completed',
                'grand_total' => 595.40,
                'paid' => 595.40,
                'due' => 0.00,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
                'created_on' => '2026-08-26 20:37:00',
            ],
            [
                'ref' => 'SA_1114185',
                'user' => 'Admin POS',
                'customer' => 'walk-in-customer',
                'warehouse' => 'warehouse',
                'status' => 'Completed',
                'grand_total' => 268.90,
                'paid' => 268.90,
                'due' => 0.00,
                'payment_status' => 'Paid',
                'payment_type' => 'Bank Transfer',
                'created_on' => '2026-08-26 20:36:00',
            ],
            [
                'ref' => 'SA_1114184',
                'user' => 'Admin POS',
                'customer' => 'Meera Nair',
                'warehouse' => 'General Warehouse',
                'status' => 'Completed',
                'grand_total' => 597.00,
                'paid' => 0.00,
                'due' => 597.00,
                'payment_status' => 'Unpaid',
                'payment_type' => '',
                'created_on' => '2026-08-25 18:44:00',
            ],
            [
                'ref' => 'SA_1114183',
                'user' => 'Admin POS',
                'customer' => 'walk-in-customer',
                'warehouse' => 'warehouse',
                'status' => 'Completed',
                'grand_total' => 266.10,
                'paid' => 266.10,
                'due' => 0.00,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
                'created_on' => '2026-08-25 11:18:00',
            ],
            [
                'ref' => 'SA_1114182',
                'user' => 'Admin POS',
                'customer' => 'walk-in-customer',
                'warehouse' => 'warehouse',
                'status' => 'Completed',
                'grand_total' => 268.90,
                'paid' => 300.00,
                'due' => 0.00,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
                'created_on' => '2026-08-25 12:48:00',
            ],
            [
                'ref' => 'SA_1114181',
                'user' => 'Admin POS',
                'customer' => 'walk-in-customer',
                'warehouse' => 'warehouse',
                'status' => 'Completed',
                'grand_total' => 405.90,
                'paid' => 0.00,
                'due' => 405.90,
                'payment_status' => 'Partial',
                'payment_type' => '',
                'created_on' => '2026-08-24 18:47:00',
            ],
            [
                'ref' => 'SA_1114180',
                'user' => 'Admin POS',
                'customer' => 'walk-in-customer',
                'warehouse' => 'warehouse',
                'status' => 'Completed',
                'grand_total' => 181.60,
                'paid' => 181.50,
                'due' => 0.10,
                'payment_status' => 'Partial',
                'payment_type' => 'Cash',
                'created_on' => '2026-08-23 21:47:00',
            ],
            [
                'ref' => 'SA_1114189',
                'user' => 'Admin POS',
                'customer' => 'walk-in-customer',
                'warehouse' => 'warehouse',
                'status' => 'Completed',
                'grand_total' => 323.40,
                'paid' => 0.00,
                'due' => 323.40,
                'payment_status' => 'Partial',
                'payment_type' => '',
                'created_on' => '2026-08-23 18:51:00',
            ],
            [
                'ref' => 'SA_1114188',
                'user' => 'Admin POS',
                'customer' => 'walk-in-customer',
                'warehouse' => 'warehouse',
                'status' => 'Completed',
                'grand_total' => 536.40,
                'paid' => 536.40,
                'due' => 0.00,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
                'created_on' => '2026-08-23 10:53:00',
            ],
            [
                'ref' => 'SA_1114187',
                'user' => 'Admin POS',
                'customer' => 'walk-in-customer',
                'warehouse' => 'warehouse',
                'status' => 'Completed',
                'grand_total' => 539.20,
                'paid' => 539.20,
                'due' => 0.00,
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
                'created_on' => '2026-08-23 08:07:00',
            ],
        ];

        foreach ($salesData as $index => $item) {
            $sale = Sale::create([
                'reference_no' => $item['ref'],
                'customer_id' => 1,
                'customer_name' => $item['customer'],
                'warehouse_name' => $item['warehouse'],
                'date' => $item['created_on'],
                'status' => $item['status'],
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'discount' => 0.0,
                'shipping' => 0.0,
                'subtotal' => $item['grand_total'],
                'grand_total' => $item['grand_total'],
                'paid_amount' => $item['paid'],
                'due_amount' => $item['due'],
                'change_amount' => max(0, $item['paid'] - $item['grand_total']),
                'payment_status' => $item['payment_status'],
                'payment_type' => $item['payment_type'] ?: 'Cash',
                'notes' => 'Sale registered via POS / Web terminal.',
                'pos_flag' => true,
                'created_by' => $item['user'],
            ]);

            $p = $products->isNotEmpty() ? $products->get($index % $products->count()) : null;
            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $p?->id,
                'product_code' => $p?->code ?? '8934567800001',
                'product_name' => $p?->name ?? 'Retail Product Item',
                'unit_price' => $item['grand_total'],
                'quantity' => 1.0,
                'tax' => 0.0,
                'discount' => 0.0,
                'subtotal' => $item['grand_total'],
            ]);

            if ($item['paid'] > 0) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'reference_no' => 'PAY-' . str_replace('SA_', '', $item['ref']),
                    'payment_date' => $item['created_on'],
                    'payment_type' => $item['payment_type'] ?: 'Cash',
                    'amount' => $item['paid'],
                    'received_amount' => $item['paid'],
                    'change_amount' => max(0, $item['paid'] - $item['grand_total']),
                    'notes' => 'Initial payment',
                    'created_by' => $item['user'],
                ]);
            }
        }

        echo "Successfully seeded 10 sales matching UI reference!\n";
    }
}
