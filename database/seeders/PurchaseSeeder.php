<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\Product;

class PurchaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('purchase_items')->truncate();
        DB::table('purchases')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $suppliers = Supplier::all();
        $products = Product::take(20)->get();

        if ($products->isEmpty()) {
            return;
        }

        $samplePOs = [
            [
                'ref' => 'PO-2026-001',
                'supplier' => $suppliers->first()->name ?? 'Nike Official Distributor Asia',
                'supplier_id' => $suppliers->first()->id ?? 1,
                'warehouse' => 'Main Warehouse Branch',
                'date' => '2026-08-20',
                'status' => 'Received',
                'payment_status' => 'Paid',
                'payment_type' => 'Bank Transfer',
                'items_count' => 3,
            ],
            [
                'ref' => 'PO-2026-002',
                'supplier' => $suppliers->skip(1)->first()->name ?? 'Apple Premium Distributor',
                'supplier_id' => $suppliers->skip(1)->first()->id ?? 2,
                'warehouse' => 'Central Hub Warehouse',
                'date' => '2026-08-21',
                'status' => 'Received',
                'payment_status' => 'Paid',
                'payment_type' => 'Bank Transfer',
                'items_count' => 2,
            ],
            [
                'ref' => 'PO-2026-003',
                'supplier' => $suppliers->skip(2)->first()->name ?? 'Sony Electronics Asia Supply',
                'supplier_id' => $suppliers->skip(2)->first()->id ?? 3,
                'warehouse' => 'Main Warehouse Branch',
                'date' => '2026-08-22',
                'status' => 'Pending',
                'payment_status' => 'Partial',
                'payment_type' => 'Cheque',
                'items_count' => 4,
            ],
            [
                'ref' => 'PO-2026-004',
                'supplier' => $suppliers->first()->name ?? 'Anker Global Logistics',
                'supplier_id' => $suppliers->first()->id ?? 1,
                'warehouse' => 'Mandalay Depot Warehouse',
                'date' => '2026-08-23',
                'status' => 'Received',
                'payment_status' => 'Paid',
                'payment_type' => 'Cash',
                'items_count' => 2,
            ],
            [
                'ref' => 'PO-2026-005',
                'supplier' => $suppliers->skip(1)->first()->name ?? 'Adidas Wholesale Asia',
                'supplier_id' => $suppliers->skip(1)->first()->id ?? 2,
                'warehouse' => 'Main Warehouse Branch',
                'date' => '2026-08-24',
                'status' => 'Ordered',
                'payment_status' => 'Unpaid',
                'payment_type' => 'Credit Term 30 Days',
                'items_count' => 3,
            ],
            [
                'ref' => 'PO-2026-006',
                'supplier' => $suppliers->skip(2)->first()->name ?? 'Logitech Global Tech Supplies',
                'supplier_id' => $suppliers->skip(2)->first()->id ?? 3,
                'warehouse' => 'Central Hub Warehouse',
                'date' => '2026-08-25',
                'status' => 'Received',
                'payment_status' => 'Paid',
                'payment_type' => 'Bank Transfer',
                'items_count' => 3,
            ],
        ];

        foreach ($samplePOs as $index => $poData) {
            $selectedProducts = $products->slice(($index * 2) % $products->count(), $poData['items_count']);
            if ($selectedProducts->isEmpty()) {
                $selectedProducts = $products->take(2);
            }

            $total = 0;
            $itemsData = [];

            foreach ($selectedProducts as $prod) {
                $qty = rand(10, 50);
                $cost = $prod->purchase_price > 0 ? (float)$prod->purchase_price : 50.0;
                $subtotal = $qty * $cost;
                $total += $subtotal;

                $itemsData[] = [
                    'product_id' => $prod->id,
                    'product_code' => $prod->code,
                    'product_name' => $prod->name,
                    'unit_name' => $prod->unit_name ?: 'Piece',
                    'conversion_rate' => 1.0,
                    'unit_cost' => $cost,
                    'quantity' => $qty,
                    'base_quantity' => $qty,
                    'base_unit_cost' => $cost,
                    'tax' => 0.0,
                    'discount' => 0.0,
                    'subtotal' => $subtotal,
                ];
            }

            $paid = $poData['payment_status'] === 'Paid' ? $total : ($poData['payment_status'] === 'Partial' ? round($total * 0.5, 2) : 0.0);
            $due = round($total - $paid, 2);

            $purchase = Purchase::create([
                'reference_no' => $poData['ref'],
                'supplier_id' => $poData['supplier_id'],
                'supplier_name' => $poData['supplier'],
                'warehouse_name' => $poData['warehouse'],
                'date' => $poData['date'],
                'status' => $poData['status'],
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'discount' => 0.0,
                'shipping' => 0.0,
                'grand_total' => $total,
                'paid_amount' => $paid,
                'due_amount' => $due,
                'payment_status' => $poData['payment_status'],
                'payment_type' => $poData['payment_type'],
                'notes' => 'Standard procurement order for retail restock.',
                'created_by' => 'Admin Manager',
            ]);

            foreach ($itemsData as $item) {
                $item['purchase_id'] = $purchase->id;
                PurchaseItem::create($item);
            }
        }

        echo "Successfully seeded " . count($samplePOs) . " purchase orders!\n";
    }
}
