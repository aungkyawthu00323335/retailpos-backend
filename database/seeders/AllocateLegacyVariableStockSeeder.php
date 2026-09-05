<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class AllocateLegacyVariableStockSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1. For Product 427: Remove orphaned parent-level StockBalance (sb_id: 39)
            // because variants 33, 35, 37 already have explicit StockBalance records.
            $p427 = Product::find(427);
            if ($p427) {
                StockBalance::where('product_id', 427)
                    ->whereNull('product_variant_id')
                    ->delete();
            }

            // 2. Find any variable products where variants exist, but all variants have 0 stock balances,
            // while a parent stock balance (product_variant_id IS NULL) exists.
            $variableProducts = Product::where('has_variants', true)->with(['variants.stockBalances', 'stockBalances'])->get();

            foreach ($variableProducts as $product) {
                $variants = $product->variants;
                if ($variants->isEmpty()) {
                    continue;
                }

                $totalVariantStockBalances = $variants->sum(fn($v) => $v->stockBalances->count());
                if ($totalVariantStockBalances > 0) {
                    // Variants already have explicit stock balances. Just remove any orphaned parent balances.
                    StockBalance::where('product_id', $product->id)
                        ->whereNull('product_variant_id')
                        ->delete();
                    continue;
                }

                // Variants have NO stock balances. Check if parent has stock balances.
                $parentBalances = StockBalance::where('product_id', $product->id)
                    ->whereNull('product_variant_id')
                    ->get();

                if ($parentBalances->isEmpty()) {
                    continue;
                }

                $variantCount = $variants->count();
                foreach ($parentBalances as $pb) {
                    $totalQty = (float)$pb->quantity;
                    $wId = $pb->warehouse_id;

                    if ($totalQty <= 0) {
                        $pb->delete();
                        continue;
                    }

                    $qtyPerVariant = round($totalQty / $variantCount, 4);
                    $allocatedTotal = 0;

                    foreach ($variants as $idx => $v) {
                        $allocatedQty = ($idx === $variantCount - 1)
                            ? ($totalQty - $allocatedTotal)
                            : $qtyPerVariant;
                        $allocatedTotal += $allocatedQty;

                        StockBalance::create([
                            'warehouse_id' => $wId,
                            'product_id' => $product->id,
                            'product_variant_id' => $v->id,
                            'quantity' => $allocatedQty,
                        ]);

                        StockMovement::create([
                            'warehouse_id' => $wId,
                            'product_id' => $product->id,
                            'product_variant_id' => $v->id,
                            'movement_type' => 'opening_stock',
                            'reference_type' => 'legacy_allocation',
                            'reference_id' => (string)$product->id,
                            'quantity_in' => $allocatedQty,
                            'quantity_out' => 0.0,
                            'balance_after' => $allocatedQty,
                            'note' => 'Allocated opening stock to variant',
                            'created_at' => now(),
                        ]);
                    }

                    // Delete the parent-level balance now that it's distributed
                    $pb->delete();
                }
            }
        });
    }
}
