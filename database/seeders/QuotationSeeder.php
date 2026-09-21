<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Outlet;
use Carbon\Carbon;

class QuotationSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::take(50)->get();
        if ($products->isEmpty()) {
            return;
        }

        $customers = Customer::take(30)->get();
        $outlets = Outlet::all();
        if ($outlets->isEmpty()) {
            $outlets = collect([
                (object)['id' => 1, 'name' => 'Main Branch Outlet'],
                (object)['id' => 2, 'name' => 'Downtown Store Branch'],
                (object)['id' => 3, 'name' => 'Airport Express Store'],
                (object)['id' => 4, 'name' => 'Central Warehouse'],
            ]);
        }

        $users = [
            ['id' => 1, 'name' => 'Super Admin', 'role' => 'Super Admin'],
            ['id' => 2, 'name' => 'Sales Team', 'role' => 'Sales Staff'],
            ['id' => 3, 'name' => 'David Aung', 'role' => 'Store Manager'],
            ['id' => 4, 'name' => 'Khin Su', 'role' => 'Sales Representative'],
            ['id' => 5, 'name' => 'Aung Myo', 'role' => 'Accountant'],
        ];

        $statuses = ['Sent', 'Pending', 'Ordered', 'Expired'];

        // Realistic seed list of ~65 quotations
        // Generate with varied dates from May 2026 to Sep 20, 2026
        $refIndex = 1000;
        $now = Carbon::create(2026, 9, 20, 16, 30, 0);

        for ($i = 0; $i < 65; $i++) {
            $refIndex++;
            $daysAgo = ($i * 2) + rand(0, 2);
            $quoteDate = $now->copy()->subDays($daysAgo)->subHours(rand(1, 10))->subMinutes(rand(5, 55));
            
            // Status distribution: ~35% Sent, ~25% Ordered, ~20% Pending, ~20% Expired
            if ($daysAgo > 45 && rand(0, 10) > 3) {
                $status = 'Expired';
            } elseif ($i % 4 === 0) {
                $status = 'Ordered';
            } elseif ($i % 3 === 0) {
                $status = 'Pending';
            } else {
                $status = 'Sent';
            }

            // Validity / Expiry date logic (Senior Accounting)
            if ($status === 'Expired') {
                $expiryDate = $quoteDate->copy()->addDays(rand(7, 14))->format('Y-m-d');
            } else {
                $expiryDate = $quoteDate->copy()->addDays(rand(14, 30))->format('Y-m-d');
            }

            $customer = $customers->isNotEmpty() ? $customers->random() : null;
            $customerName = $customer ? $customer->name : 'Corporate Client ' . ($i + 1);
            $customerId = $customer ? $customer->id : null;

            $outlet = $outlets->random();
            $outletId = $outlet->id ?? 1;
            $outletName = $outlet->name ?? 'Main Branch Outlet';

            $user = $users[array_rand($users)];

            // Select 1 to 4 random products for items
            $itemCount = rand(1, 4);
            $selectedProducts = $products->random(min($itemCount, $products->count()));

            $subtotal = 0.0;
            $lineItemsData = [];

            foreach ($selectedProducts as $prod) {
                $qty = (float)rand(1, 8);
                $unitPrice = (float)($prod->price ?? rand(25, 450));
                if ($unitPrice <= 0) $unitPrice = 50.0;
                $lineSubtotal = round($qty * $unitPrice, 2);
                $subtotal += $lineSubtotal;

                $lineItemsData[] = [
                    'product_id' => $prod->id,
                    'product_code' => $prod->code ?? ('PRD-' . $prod->id),
                    'product_name' => $prod->name,
                    'unit_name' => 'Piece',
                    'conversion_rate' => 1.0,
                    'unit_price' => $unitPrice,
                    'base_unit_price' => $unitPrice,
                    'quantity' => $qty,
                    'base_quantity' => $qty,
                    'tax' => 0.0,
                    'discount' => 0.0,
                    'subtotal' => $lineSubtotal,
                ];
            }

            // Financial Calculations
            $taxRate = ($i % 3 === 0) ? 5.0 : 0.0;
            $taxAmount = round($subtotal * ($taxRate / 100.0), 2);
            $discount = ($subtotal > 200 && rand(0, 1) === 1) ? round(rand(5, 30), 2) : 0.0;
            $shipping = ($subtotal > 500 && rand(0, 1) === 1) ? 15.00 : 0.0;
            $grandTotal = round($subtotal + $taxAmount - $discount + $shipping, 2);

            $dateString = $quoteDate->format('Y-m-d H:i:s');
            $datePart = $quoteDate->format('Ymd');
            $referenceNo = sprintf('QUO_%s-%04d', $datePart, $refIndex);

            $quotation = Quotation::create([
                'reference_no' => $referenceNo,
                'customer_id' => $customerId,
                'customer_name' => $customerName,
                'warehouse_id' => $outletId,
                'warehouse_name' => $outletName,
                'date' => $dateString,
                'expiry_date' => $expiryDate,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'discount' => $discount,
                'shipping' => $shipping,
                'grand_total' => $grandTotal,
                'status' => $status,
                'notes' => "Official price proposal for {$customerName} ({$status})",
                'created_by' => $user['name'],
                'user_id' => $user['id'],
                'user_name' => $user['name'],
                'user_role' => $user['role'],
            ]);

            foreach ($lineItemsData as $item) {
                $item['quotation_id'] = $quotation->id;
                QuotationItem::create($item);
            }
        }
    }
}
