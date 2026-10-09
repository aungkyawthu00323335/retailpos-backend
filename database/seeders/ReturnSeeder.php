<?php

namespace Database\Seeders;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Database\Seeder;

class ReturnSeeder extends Seeder
{
    /**
     * Run the database seeds for Sale Returns & Purchase Returns.
     */
    public function run(): void
    {
        // 1. Seed Purchase Returns (Vendor Returns)
        if (PurchaseReturn::count() === 0) {
            $purchase = Purchase::first();

            PurchaseReturn::create([
                'reference_no'          => 'PR-2026-001',
                'purchase_reference_no' => $purchase ? $purchase->reference_no : 'PO-2026-001',
                'supplier_id'           => $purchase ? $purchase->supplier_id : 1,
                'supplier_name'         => $purchase ? $purchase->supplier_name : 'Apex Global Distribution Ltd',
                'warehouse_id'          => $purchase ? $purchase->warehouse_id : 1,
                'warehouse_name'        => $purchase ? $purchase->warehouse_name : 'Main Warehouse Branch',
                'outlet_id'             => $purchase ? $purchase->outlet_id : 1,
                'date'                  => '2026-09-15',
                'status'                => 'Completed',
                'grand_total'           => 300.00,
                'paid_amount'           => 300.00,
                'due_amount'            => 0.00,
                'payment_status'        => 'Paid',
                'payment_type'          => 'Bank Transfer',
                'subtotal'              => 300.00,
                'tax_rate'              => 0.0,
                'tax_amount'            => 0.0,
                'discount'              => 0.0,
                'shipping'              => 0.0,
                'notes'                 => 'Returned 2 pairs of defective shoes to supplier for refund.',
                'return_reason'         => 'Defective / Damaged Product',
                'created_by'            => 'Super Admin',
                'user_name'             => 'Super Admin',
                'user_role'             => 'Super Admin',
                'items_payload'         => json_encode([
                    [
                        'id'            => 1,
                        'product_id'    => 1,
                        'product_name'  => 'Nike Air Zoom Pegasus 40',
                        'product_code'  => '8934567800001',
                        'unit_cost'     => 150.00,
                        'quantity'      => 2,
                        'subtotal'      => 300.00,
                        'return_reason' => 'Defective / Damaged Product',
                    ],
                ]),
                'payments_payload'      => json_encode([
                    [
                        'id'             => 1,
                        'reference_no'   => 'PAY-PR-2026-001',
                        'payment_date'   => '2026-09-15',
                        'payment_type'   => 'Bank Transfer',
                        'amount'         => 300.00,
                        'user_name'      => 'Super Admin',
                        'notes'          => 'Supplier refunded via bank transfer.',
                    ],
                ]),
            ]);

            PurchaseReturn::create([
                'reference_no'          => 'PR-2026-002',
                'purchase_reference_no' => 'PO-2026-003',
                'supplier_id'           => 2,
                'supplier_name'         => 'Sunlight Apparel & Goods Wholesale',
                'warehouse_id'          => 1,
                'warehouse_name'        => 'Main Warehouse Branch',
                'outlet_id'             => 1,
                'date'                  => '2026-09-20',
                'status'                => 'Pending',
                'grand_total'           => 180.00,
                'paid_amount'           => 0.00,
                'due_amount'            => 180.00,
                'payment_status'        => 'Unpaid',
                'payment_type'          => 'Cash',
                'subtotal'              => 180.00,
                'tax_rate'              => 0.0,
                'tax_amount'            => 0.0,
                'discount'              => 0.0,
                'shipping'              => 0.0,
                'notes'                 => 'Awaiting supplier credit note confirmation.',
                'return_reason'         => 'Wrong Item Received',
                'created_by'            => 'Super Admin',
                'user_name'             => 'Super Admin',
                'user_role'             => 'Super Admin',
                'items_payload'         => json_encode([
                    [
                        'id'            => 2,
                        'product_id'    => 13,
                        'product_name'  => 'Apple iPhone 15 Silicone Case MagSafe',
                        'product_code'  => '8934567800007',
                        'unit_cost'     => 18.00,
                        'quantity'      => 10,
                        'subtotal'      => 180.00,
                        'return_reason' => 'Wrong Item Received',
                    ],
                ]),
                'payments_payload'      => json_encode([]),
            ]);
        }

        // 2. Seed Sale Returns (Customer Returns)
        if (SaleReturn::count() === 0) {
            $sale = Sale::first();

            SaleReturn::create([
                'reference_no'      => 'SR-2026-001',
                'sale_reference_no' => $sale ? $sale->reference_no : 'SA_1114186',
                'customer_id'       => $sale ? $sale->customer_id : 1,
                'customer_name'     => $sale ? $sale->customer_name : 'Walk-in Customer',
                'warehouse_id'      => $sale ? $sale->warehouse_id : 1,
                'warehouse_name'    => $sale ? $sale->warehouse_name : 'warehouse',
                'date'              => '2026-09-18',
                'status'            => 'Completed',
                'tax_rate'          => 0.0,
                'tax_amount'        => 0.0,
                'discount'          => 0.0,
                'shipping'          => 0.0,
                'subtotal'          => 95.00,
                'grand_total'       => 95.00,
                'paid_amount'       => 95.00,
                'due_amount'        => 0.00,
                'payment_status'    => 'Paid',
                'payment_type'      => 'Cash',
                'notes'             => 'Customer returned wrong size shoe.',
                'return_reason'     => 'Customer Changed Mind',
                'refund_tender'     => 'Cash',
                'created_by'        => 'Super Admin',
                'user_name'         => 'Super Admin',
                'user_role'         => 'Super Admin',
                'items_payload'     => json_encode([
                    [
                        'id'            => 1,
                        'product_id'    => 3,
                        'product_name'  => 'Nike Air Jordan 1 Retro High OG',
                        'product_code'  => '8934567800002',
                        'unit_price'    => 95.00,
                        'quantity'      => 1,
                        'subtotal'      => 95.00,
                        'return_reason' => 'Customer Changed Mind',
                    ],
                ]),
                'payments_payload'  => json_encode([
                    [
                        'id'           => 1,
                        'reference_no' => 'PAY-SR-2026-001',
                        'payment_date' => '2026-09-18',
                        'payment_type' => 'Cash',
                        'amount'       => 95.00,
                        'user_name'    => 'Super Admin',
                        'notes'        => 'Refunded cash to customer at POS counter.',
                    ],
                ]),
            ]);

            SaleReturn::create([
                'reference_no'      => 'SR-2026-002',
                'sale_reference_no' => 'SA_1114187',
                'customer_id'       => 2,
                'customer_name'     => 'Aung Kyaw Moe',
                'warehouse_id'      => 1,
                'warehouse_name'    => 'warehouse',
                'date'              => '2026-09-22',
                'status'            => 'Completed',
                'tax_rate'          => 0.0,
                'tax_amount'        => 0.0,
                'discount'          => 0.0,
                'shipping'          => 0.0,
                'subtotal'          => 52.00,
                'grand_total'       => 52.00,
                'paid_amount'       => 52.00,
                'due_amount'        => 0.00,
                'payment_status'    => 'Paid',
                'payment_type'      => 'KBZPay',
                'notes'             => 'Customer returned defective runner shoe.',
                'return_reason'     => 'Defective / Damaged Product',
                'refund_tender'     => 'KBZPay',
                'created_by'        => 'Super Admin',
                'user_name'         => 'Super Admin',
                'user_role'         => 'Super Admin',
                'items_payload'     => json_encode([
                    [
                        'id'            => 2,
                        'product_id'    => 9,
                        'product_name'  => 'Puma Velocity Nitro 2 Runner',
                        'product_code'  => '8934567800005',
                        'unit_price'    => 52.00,
                        'quantity'      => 1,
                        'subtotal'      => 52.00,
                        'return_reason' => 'Defective / Damaged Product',
                    ],
                ]),
                'payments_payload'  => json_encode([
                    [
                        'id'           => 1,
                        'reference_no' => 'PAY-SR-2026-002',
                        'payment_date' => '2026-09-22',
                        'payment_type' => 'KBZPay',
                        'amount'       => 52.00,
                        'user_name'    => 'Super Admin',
                        'notes'        => 'Refunded via KBZPay QR.',
                    ],
                ]),
            ]);
        }
    }
}
