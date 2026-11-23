<?php

namespace App\Console\Commands;

use App\Services\TelegramNotificationService;
use Illuminate\Console\Command;

class SendScheduledTelegramDailySummary extends Command
{
    protected $signature = 'telegram:send-scheduled-daily {--force : Dispatch immediately regardless of scheduled time or prior send today}';

    protected $description = 'Dispatch the scheduled daily Telegram business summary when the configured store time is reached.';

    public function handle(TelegramNotificationService $telegram): int
    {
        $force = (bool)$this->option('force');
        $result = $telegram->dispatchScheduledDailyIfDue($force);

        if (!empty($result['skipped'])) {
            $this->line('Telegram scheduled daily summary skipped: ' . ($result['reason'] ?? 'not due'));
            return self::SUCCESS;
        }

        if (!empty($result['ok'])) {
            $this->info($result['message'] ?? 'Scheduled Telegram daily summary sent.');
            return self::SUCCESS;
        }

        $this->warn($result['message'] ?? 'Scheduled Telegram daily summary failed.');
        return self::FAILURE;
    }
}
