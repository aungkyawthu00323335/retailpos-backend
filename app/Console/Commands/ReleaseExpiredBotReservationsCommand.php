<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Services\TelegramBotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleaseExpiredBotReservationsCommand extends Command
{
    protected $signature = 'bot:release-expired-reservations {--force : Force release without interactive confirmation}';
    protected $description = 'Automatically release reserved stock for expired unapproved Telegram bot orders';

    public function handle(TelegramBotService $botService): int
    {
        $enabled = Setting::get('telegram_bot_enable_stock_reservation', '1') === '1';
        if (!$enabled) {
            $this->info("Stock reservation is currently disabled in settings. Skipping audit.");
            return Command::SUCCESS;
        }

        $timeoutMinutes = (int)Setting::get('telegram_bot_stock_reservation_timeout', 60);
        if ($timeoutMinutes <= 0) {
            $timeoutMinutes = 60;
        }

        $expiryThreshold = now()->subMinutes($timeoutMinutes);

        $expiredSales = Sale::with('items')
            ->where('order_source', 'telegram_bot')
            ->where('bot_approval_status', 'pending')
            ->where('stock_reserved', true)
            ->where('stock_reserved_at', '<=', $expiryThreshold)
            ->get();

        if ($expiredSales->isEmpty()) {
            $this->info("No expired stock reservations found (timeout: {$timeoutMinutes} mins).");
            return Command::SUCCESS;
        }

        $this->info("Found {$expiredSales->count()} expired order(s) requiring stock reservation rollback.");
        $releasedCount = 0;

        foreach ($expiredSales as $sale) {
            DB::beginTransaction();
            try {
                $lockedSale = Sale::with('items')->whereKey($sale->id)->lockForUpdate()->first();
                if (!$lockedSale || $lockedSale->bot_approval_status !== 'pending' || !$lockedSale->stock_reserved) {
                    DB::rollBack();
                    continue;
                }

                $reason = "အော်ဒါတင်ပြီးနောက် {$timeoutMinutes} မိနစ်အတွင်း အတည်မပြုနိုင်သဖြင့် သက်တမ်းကုန်ဆုံးသွားပါသည် (Stock Reservation Expired)";

                // 1. Rollback stock balance
                $botService->rollbackStockReservation($lockedSale, "Reservation expired after {$timeoutMinutes} minutes");

                // 2. Void preliminary payments
                SalePayment::where('sale_id', $lockedSale->id)->delete();

                // 3. Mark sale as Cancelled
                $lockedSale->update([
                    'status' => 'Cancelled',
                    'bot_approval_status' => 'rejected',
                    'bot_rejection_reason' => $reason,
                    'stock_reserved' => false,
                    'stock_released_at' => now(),
                    'paid_amount' => 0.0,
                    'due_amount' => 0.0,
                    'payment_status' => 'Unpaid',
                    'notes' => trim($lockedSale->notes . " | Auto-cancelled: Stock reservation expired after {$timeoutMinutes} mins on " . now()->format('Y-m-d H:i')),
                ]);

                DB::commit();
                $releasedCount++;

                // 4. Notify customer via Telegram
                try {
                    $botService->notifyCustomerOrderRejected($lockedSale, $reason);
                } catch (\Throwable $ne) {
                    Log::warning("Telegram notify expired order error for #{$lockedSale->reference_no}: " . $ne->getMessage());
                }

                $this->line(" - Released Order #{$lockedSale->reference_no} (reserved at {$lockedSale->stock_reserved_at})");
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error("Failed to release expired reservation for order #{$sale->id}: " . $e->getMessage());
                $this->error("Failed to release order #{$sale->reference_no}: " . $e->getMessage());
            }
        }

        $this->info("Completed. Successfully released {$releasedCount} expired reservation(s).");
        return Command::SUCCESS;
    }
}
