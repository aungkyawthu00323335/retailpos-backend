<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Setting;
use App\Services\TelegramBotService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramPollCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:poll';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll Telegram API for incoming messages and orders (ideal for local development and standalone mode)';

    /**
     * Execute the console command.
     */
    public function handle(TelegramBotService $botService): int
    {
        // Prevent multiple concurrent polling instances
        $lockDir = storage_path('framework');
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }
        $lockFile = $lockDir . '/telegram_poll.lock';
        $fp = fopen($lockFile, 'c+');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            $this->warn('⚠️ Another instance of telegram:poll is already running! Exiting to avoid duplicate replies.');
            return 0;
        }

        $currentToken = trim((string)Setting::get('telegram_bot_token', ''));
        if (!empty($currentToken)) {
            $botService->refreshToken($currentToken);
            $botService->deleteWebhook();
            $botMe = $botService->getMe();
            $username = $botMe['result']['username'] ?? 'UnknownBot';
            $this->info("🤖 Telegram Bot Polling started for @{$username}!");
        } else {
            $this->warn('⚠️ Telegram Bot Token is not set in POS Settings. Polling waiting for configuration...');
        }
        $this->info("⚡ Listening for customer messages, orders, and payment slips...");

        $offset = 0;

        while (true) {
            try {
                $token = trim((string)Setting::get('telegram_bot_token', ''));
                if (empty($token)) {
                    $this->warn('No bot token configured. Sleeping 5s...');
                    sleep(5);
                    continue;
                }

                // 🔄 Dynamic Token Switch Detection:
                if ($token !== $currentToken) {
                    $this->info("🔄 Bot Token changed in POS Settings! Switching daemon to new bot token...");
                    $currentToken = $token;
                    $offset = 0; // MUST reset offset to 0 for new bot!
                    $botService->refreshToken($currentToken);
                    $botService->deleteWebhook(); // Delete webhook on new bot to allow getUpdates

                    $botMe = $botService->getMe();
                    if (($botMe['ok'] ?? false) === true) {
                        $newUsername = $botMe['result']['username'] ?? 'UnknownBot';
                        $this->info("✅ Successfully switched to Telegram Bot @{$newUsername}!");
                        Setting::set('telegram_bot_username', '@' . $newUsername);
                    } else {
                        $desc = $botMe['description'] ?? 'Invalid token or network error';
                        $this->error("❌ New Bot Token authentication failed: {$desc}");
                    }
                }

                $response = Http::connectTimeout(20)
                    ->timeout(35)
                    ->withoutVerifying()
                    ->get("https://api.telegram.org/bot{$token}/getUpdates", [
                        'offset' => $offset,
                        'timeout' => 20,
                    ]);

                if ($response->successful() && $response->json('ok')) {
                    $updates = $response->json('result') ?? [];
                    foreach ($updates as $update) {
                        $offset = $update['update_id'] + 1;
                        $updateId = $update['update_id'];

                        if (isset($update['message'])) {
                            $from = $update['message']['from']['first_name'] ?? 'User';
                            $text = $update['message']['text'] ?? (isset($update['message']['photo']) ? '[Photo]' : '[Media]');
                            $this->line("📩 [Update #{$updateId}] From {$from}: {$text}");
                        } elseif (isset($update['callback_query'])) {
                            $from = $update['callback_query']['from']['first_name'] ?? 'User';
                            $data = $update['callback_query']['data'] ?? '';
                            $this->line("🔘 [Update #{$updateId}] Button by {$from}: {$data}");
                        }

                        // Process update
                        $botService->handleIncomingUpdate($update);
                    }
                } else {
                    $status = $response->status();
                    $desc = $response->json('description') ?? 'Unknown error';
                    if ($status === 401) {
                        $this->warn("⚠️ Telegram API returned 401 Unauthorized for current token. Please verify token with @BotFather.");
                        sleep(5);
                    } elseif ($status === 409) {
                        $this->warn("⚠️ Telegram API 409 Conflict (Webhook active). Deleting webhook...");
                        $botService->deleteWebhook();
                        sleep(3);
                    } else {
                        sleep(2);
                    }
                }
            } catch (\Throwable $e) {
                $err = $e->getMessage();
                if (str_contains($err, 'timed out') || str_contains($err, 'cURL error 28')) {
                    // Normal idle timeout during long polling
                    continue;
                }
                $this->error("Polling error: " . $err);
                Log::error("telegram:poll error: " . $err);
                sleep(2);
            }
        }

        return 0;
    }
}
