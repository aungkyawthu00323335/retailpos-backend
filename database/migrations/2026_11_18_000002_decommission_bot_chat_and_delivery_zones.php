<?php

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\FifoInventoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Purge all historical and pending telegram_bot orders safely with inventory/FIFO/register cleanup
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'order_source')) {
            DB::transaction(function () {
                $botSales = Sale::with(['items', 'payments'])
                    ->where('order_source', 'telegram_bot')
                    ->get();

                if ($botSales->isNotEmpty()) {
                    $botSaleIds = $botSales->pluck('id')->all();
                    $botRefs = $botSales->pluck('reference_no')->filter()->all();
                    $affectedCustomerIds = $botSales->pluck('customer_id')->filter()->unique()->all();
                    $touchedProductIds = [];
                    $touchedVariantIds = [];

                    // Reverse & delete any SaleReturns linked to purged telegram_bot sales first
                    if (Schema::hasTable('sale_returns') && !empty($botRefs)) {
                        $linkedReturns = SaleReturn::whereIn('sale_reference_no', $botRefs)->get();
                        foreach ($linkedReturns as $ret) {
                            $retStatus = strtolower(trim((string) $ret->status));
                            if ($retStatus === 'completed') {
                                $whId = $ret->warehouse_id ?: DB::table('outlets')->where('name', $ret->warehouse_name)->value('id');
                                $retItems = json_decode((string) $ret->items_payload, true);
                                if (is_array($retItems) && $whId) {
                                    foreach ($retItems as $it) {
                                        $pid = $it['product_id'] ?? null;
                                        $vid = !empty($it['product_variant_id']) ? (int) $it['product_variant_id'] : null;
                                        $baseQty = (float) ($it['base_quantity'] ?? (($it['quantity'] ?? 0) * ($it['conversion_rate'] ?? 1)));
                                        if ($pid && $baseQty > 0) {
                                            $bal = StockBalance::where('warehouse_id', $whId)
                                                ->where('product_id', $pid)
                                                ->when($vid === null, fn ($q) => $q->whereNull('product_variant_id'), fn ($q) => $q->where('product_variant_id', $vid))
                                                ->first();
                                            if ($bal) {
                                                $bal->update(['quantity' => max(0.0, round((float) $bal->quantity - $baseQty, 4))]);
                                            }
                                            $touchedProductIds[] = (int) $pid;
                                            if ($vid) {
                                                $touchedVariantIds[] = $vid;
                                            }
                                        }
                                    }
                                }
                                InventoryBatch::where('source_type', 'sale_return')
                                    ->where('source_reference', $ret->reference_no)
                                    ->delete();
                            }
                            StockMovement::where('reference_type', 'sale_return')
                                ->where('reference_id', (string) $ret->id)
                                ->delete();
                            $ret->delete();
                        }
                    }

                    $fifoService = app(FifoInventoryService::class);
                    foreach ($botSales as $sale) {
                        $wasDepleted = ($sale->status === 'Completed') || ($sale->bot_approval_status === 'approved');
                        if ($wasDepleted && Schema::hasTable('stock_balances')) {
                            $whId = $sale->warehouse_id ?: DB::table('outlets')->where('name', $sale->warehouse_name)->value('id');
                            foreach ($sale->items as $item) {
                                $baseQty = (float) ($item->base_quantity > 0 ? $item->base_quantity : ($item->quantity * ($item->conversion_rate ?: 1)));
                                if ($whId && $item->product_id && $baseQty > 0) {
                                    $bal = StockBalance::where('warehouse_id', $whId)
                                        ->where('product_id', $item->product_id)
                                        ->when($item->product_variant_id === null, fn ($q) => $q->whereNull('product_variant_id'), fn ($q) => $q->where('product_variant_id', $item->product_variant_id))
                                        ->first();
                                    if ($bal) {
                                        $bal->update(['quantity' => round((float) $bal->quantity + $baseQty, 4)]);
                                    }
                                    $touchedProductIds[] = (int) $item->product_id;
                                    if ($item->product_variant_id) {
                                        $touchedVariantIds[] = (int) $item->product_variant_id;
                                    }
                                }
                            }
                            if (Schema::hasTable('sale_item_cost_layers')) {
                                $fifoService->reverseSaleFifo($sale);
                            }
                        }

                        // Adjust currently open cash register if cash payments were tied to it
                        if ($sale->cash_register_id && Schema::hasTable('cash_registers')) {
                            $reg = CashRegister::find($sale->cash_register_id);
                            if ($reg && in_array(strtolower((string) $reg->status), ['open', 'opened'], true)) {
                                $cashPaid = $sale->payments->isNotEmpty()
                                    ? (float) $sale->payments->filter(fn ($p) => strtolower((string) $p->payment_type) === 'cash')->sum('amount')
                                    : (strtolower((string) $sale->payment_type) === 'cash' ? (float) $sale->paid_amount : 0.0);
                                if ($cashPaid > 0.001 && $reg->expected_cash !== null) {
                                    $reg->update(['expected_cash' => round((float) $reg->expected_cash - $cashPaid, 2)]);
                                }
                            }
                        }

                        StockMovement::where('reference_type', 'sale')
                            ->where('reference_id', (string) $sale->id)
                            ->delete();
                    }

                    SalePayment::whereIn('sale_id', $botSaleIds)->delete();
                    SaleItem::whereIn('sale_id', $botSaleIds)->delete();
                    if (Schema::hasTable('sale_item_cost_layers')) {
                        DB::table('sale_item_cost_layers')->whereIn('sale_id', $botSaleIds)->delete();
                    }
                    Sale::whereIn('id', $botSaleIds)->delete();

                    // Resync product & variant aggregate stock_quantity cleanly
                    foreach (array_unique($touchedProductIds) as $pid) {
                        $sum = (float) StockBalance::where('product_id', $pid)->sum('quantity');
                        Product::whereKey($pid)->update(['stock_quantity' => round($sum, 4)]);
                    }
                    if (!empty($touchedVariantIds) && Schema::hasColumn('product_variants', 'stock_quantity')) {
                        foreach (array_unique($touchedVariantIds) as $vid) {
                            $vSum = (float) StockBalance::where('product_variant_id', $vid)->sum('quantity');
                            ProductVariant::whereKey($vid)->update(['stock_quantity' => round($vSum, 4)]);
                        }
                    }

                    // Recompute customer lifetime totals from remaining non-bot Completed sales
                    if (!empty($affectedCustomerIds) && Schema::hasTable('customers')) {
                        foreach ($affectedCustomerIds as $cid) {
                            $cust = Customer::find($cid);
                            if (!$cust) {
                                continue;
                            }
                            $agg = DB::table('sales')
                                ->where('customer_id', $cid)
                                ->where('status', 'Completed')
                                ->selectRaw('COUNT(*) as visits, COALESCE(SUM(grand_total), 0) as spent')
                                ->first();
                            $cust->update([
                                'total_visits' => (int) ($agg->visits ?? 0),
                                'total_spent' => round((float) ($agg->spent ?? 0.0), 2),
                            ]);
                        }
                    }
                }
            });
        }

        // 2. Clean stored bot slips, bot settings, and obsolete role permissions
        $slipsDir = storage_path('app/public/bot_slips');
        if (File::isDirectory($slipsDir)) {
            File::deleteDirectory($slipsDir);
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->where('key', 'like', 'telegram_%')
                ->orWhere('key', 'like', 'tg_%')
                ->delete();
        }

        if (Schema::hasTable('roles')) {
            $removedExact = ['Telegram Bot Settings', 'Delivery Fees'];
            Role::query()->each(function (Role $role) use ($removedExact) {
                if (!is_array($role->permissions)) {
                    return;
                }
                $filtered = array_values(array_filter($role->permissions, function ($perm) use ($removedExact) {
                    if (!is_string($perm)) {
                        return false;
                    }
                    if (in_array($perm, $removedExact, true)) {
                        return false;
                    }
                    foreach (['bot_orders.', 'bot_order.', 'bot_chat.', 'bot_chats.', 'delivery_zones.', 'delivery_zone.', 'telegram_bot.'] as $prefix) {
                        if (str_starts_with(strtolower($perm), $prefix)) {
                            return false;
                        }
                    }
                    return true;
                }));
                if (count($filtered) !== count($role->permissions)) {
                    $role->update(['permissions' => $filtered]);
                }
            });
        }

        // 3. Drop bot composite/single indexes before dropping columns on sales
        if (Schema::hasTable('sales')) {
            $this->dropIndexIfExists('sales', 'sales_cust_ar_idx');
            $this->dropIndexIfExists('sales', 'idx_sales_source_approval_date_id');
            foreach (['order_source', 'telegram_chat_id', 'telegram_order_id', 'bot_approval_status'] as $indexedCol) {
                $this->dropIndexIfExists('sales', "sales_{$indexedCol}_index");
                $this->dropColumnIndexIfExists('sales', [$indexedCol]);
            }

            $botSalesCols = [
                'order_source',
                'telegram_chat_id',
                'telegram_username',
                'telegram_order_id',
                'bot_approval_status',
                'bot_rejection_reason',
                'payment_slip_url',
                'shipping_address',
                'customer_phone',
                'bot_approved_by',
                'bot_approved_at',
                'bot_rejected_by',
                'bot_rejected_at',
            ];
            $dropSalesCols = array_values(array_filter($botSalesCols, fn ($c) => Schema::hasColumn('sales', $c)));
            if (!empty($dropSalesCols)) {
                Schema::table('sales', function (Blueprint $table) use ($dropSalesCols) {
                    $table->dropColumn($dropSalesCols);
                });
            }

            if (!Schema::hasIndex('sales', 'sales_cust_ar_idx') && !Schema::hasIndex('sales', ['customer_id', 'status', 'due_amount'])) {
                Schema::table('sales', function (Blueprint $table) {
                    $table->index(['customer_id', 'status', 'due_amount'], 'sales_cust_ar_idx');
                });
            }
        }

        // 4. Drop bot columns on products (preserving delivery_price)
        if (Schema::hasTable('products')) {
            $this->dropIndexIfExists('products', 'products_is_bot_available_index');
            $this->dropColumnIndexIfExists('products', ['is_bot_available']);

            $dropProductCols = array_values(array_filter(['is_bot_available', 'bot_price'], fn ($c) => Schema::hasColumn('products', $c)));
            if (!empty($dropProductCols)) {
                Schema::table('products', function (Blueprint $table) use ($dropProductCols) {
                    $table->dropColumn($dropProductCols);
                });
            }
        }

        // 5. Drop ONLY is_bot_available on payment_methods (preserving account_number & account_name)
        if (Schema::hasTable('payment_methods') && Schema::hasColumn('payment_methods', 'is_bot_available')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->dropColumn('is_bot_available');
            });
        }

        // 6. Drop standalone bot/chat/delivery tables in FK-safe order
        Schema::dropIfExists('bot_chat_messages');
        Schema::dropIfExists('bot_restock_subscribers');
        Schema::dropIfExists('delivery_townships');
        Schema::dropIfExists('delivery_regions');
    }

    public function down(): void
    {
        // Permanently decommissioned modules.
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        try {
            if (Schema::hasIndex($tableName, $indexName)) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        } catch (\Throwable $e) {
            // Ignore when index does not exist on current driver
        }
    }

    private function dropColumnIndexIfExists(string $tableName, array $columns): void
    {
        try {
            if (Schema::hasIndex($tableName, $columns)) {
                Schema::table($tableName, function (Blueprint $table) use ($columns) {
                    $table->dropIndex($columns);
                });
            }
        } catch (\Throwable $e) {
            // Ignore when index does not exist on current driver
        }
    }
};
