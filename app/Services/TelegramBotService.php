<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductCategory;
use App\Models\SubCategory;
use App\Models\Brand;
use App\Models\Outlet;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Services\ProductUnitConversionService;
use App\Services\FifoInventoryService;
use App\Models\PaymentMethod;
use App\Models\DeliveryRegion;
use App\Models\DeliveryTownship;
use App\Models\BotRestockSubscriber;
use App\Models\BotChatMessage;
use App\Services\TelegramAiAgentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class TelegramBotService
{
    protected ?string $token;
    protected string $apiUrl;
    protected TelegramAiAgentService $aiAgentService;

    public function __construct()
    {
        $this->refreshToken();
        $this->aiAgentService = app(TelegramAiAgentService::class);
    }

    /**
     * Explicitly refresh the bot credentials from parameter or database
     */
    public function refreshToken(?string $token = null): void
    {
        $raw = $token ?? Setting::get('telegram_bot_token', config('services.telegram.bot_token', ''));
        $this->token = !empty($raw) ? trim((string)$raw) : null;
        $this->apiUrl = !empty($this->token) ? "https://api.telegram.org/bot{$this->token}" : '';
    }

    /**
     * Ensure bot token matches database state in real-time
     */
    public function ensureFreshToken(): void
    {
        $dbToken = trim((string)Setting::get('telegram_bot_token', config('services.telegram.bot_token', '')));
        if ($dbToken !== ($this->token ?? '')) {
            $this->refreshToken($dbToken);
        }
    }

    public function getToken(): ?string
    {
        $this->ensureFreshToken();
        return $this->token;
    }

    public function getApiUrl(): string
    {
        $this->ensureFreshToken();
        return $this->apiUrl;
    }

    public function isConfigured(): bool
    {
        $this->ensureFreshToken();
        return !empty($this->token);
    }

    /**
     * Test Bot Token via getMe
     */
    public function getMe(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'Telegram Bot Token is not configured.'];
        }

        try {
            $response = Http::timeout(10)->withoutVerifying()->get("{$this->getApiUrl()}/getMe");
            return $response->json();
        } catch (\Throwable $e) {
            Log::error('Telegram getMe failed: ' . $e->getMessage());
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    /**
     * Webhook Management: Set Webhook URL with Secret Token
     */
    public function setWebhook(string $url, ?string $secretToken = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'Telegram Bot Token is not configured.'];
        }

        $payload = [
            'url' => $url,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ];
        if ($secretToken) {
            $payload['secret_token'] = $secretToken;
        }

        try {
            $response = Http::timeout(15)->withoutVerifying()->post("{$this->getApiUrl()}/setWebhook", $payload);
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            Log::error('Telegram setWebhook failed: ' . $e->getMessage());
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    /**
     * Delete Webhook (for polling)
     */
    public function deleteWebhook(bool $dropPendingUpdates = false): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'Telegram Bot Token is not configured.'];
        }

        try {
            $response = Http::timeout(15)->withoutVerifying()->post("{$this->getApiUrl()}/deleteWebhook", [
                'drop_pending_updates' => $dropPendingUpdates,
            ]);
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            Log::error('Telegram deleteWebhook failed: ' . $e->getMessage());
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    /**
     * Get Webhook Status Information from Telegram
     */
    public function getWebhookInfo(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'Telegram Bot Token is not configured.'];
        }

        try {
            $res = Http::timeout(10)->withoutVerifying()->get("{$this->getApiUrl()}/getWebhookInfo");
            return $res->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            Log::error('getWebhookInfo error: ' . $e->getMessage());
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    /**
     * Set Chat Menu Button (for Telegram Mini App persistent menu button)
     */
    public function setChatMenuButton(?string $webAppUrl = null, string $buttonText = '🛍️ Shop Now'): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'Telegram Bot Token is not configured.'];
        }

        try {
            $payload = [];
            if (!empty($webAppUrl)) {
                $payload['menu_button'] = [
                    'type' => 'web_app',
                    'text' => $buttonText,
                    'web_app' => [
                        'url' => $webAppUrl,
                    ],
                ];
            } else {
                $payload['menu_button'] = [
                    'type' => 'default',
                ];
            }

            $response = Http::timeout(15)->withoutVerifying()->post("{$this->getApiUrl()}/setChatMenuButton", $payload);
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            Log::error('Telegram setChatMenuButton failed: ' . $e->getMessage());
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    /**
     * Synchronize Bot Menu Commands with Telegram API (setMyCommands)
     */
    public function syncBotCommands(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'Telegram Bot Token is not configured.'];
        }

        $commands = [
            ['command' => 'start', 'description' => '🏠 ပင်မ မီနူးသို့ သွားမည် (Home Menu)'],
            ['command' => 'catalog', 'description' => '🛍️ ကုန်ပစ္စည်းများ ကြည့်ရှုရန် (Catalog)'],
            ['command' => 'cart', 'description' => '🛒 ခြင်းတောင်း ကြည့်ရှုရန် (Shopping Cart)'],
            ['command' => 'orders', 'description' => '📦 ကျွန်ုပ်၏ အော်ဒါမှတ်တမ်း (Order History)'],
            ['command' => 'payment', 'description' => '💳 ငွေလွှဲရန် အချက်အလက် (Payment Info)'],
            ['command' => 'contact', 'description' => '📞 ဆိုင်နှင့် ဆက်သွယ်ရန် (Contact Us)'],
            ['command' => 'cancel', 'description' => '❌ လုပ်ဆောင်ချက် ပယ်ဖျက်မည် (Cancel)'],
        ];

        try {
            $response = Http::timeout(10)->withoutVerifying()->post("{$this->getApiUrl()}/setMyCommands", [
                'commands' => $commands,
            ]);
            $res = $response->json() ?? ['ok' => false];
            if (!empty($res['ok'])) {
                Log::info('Telegram Bot commands synchronized successfully.');
            }
            return $res;
        } catch (\Throwable $e) {
            Log::error('Telegram syncBotCommands failed: ' . $e->getMessage());
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    /**
     * Convert Myanmar Digits (၀-၉) to Western Digits (0-9)
     */
    public function convertMyanmarDigits(string $str): string
    {
        $myanmarDigits = ['၀', '၁', '၂', '၃', '၄', '၅', '၆', '၇', '၈', '၉'];
        $arabicDigits  = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($myanmarDigits, $arabicDigits, $str);
    }

    /**
     * Normalize Myanmar and International Phone Numbers
     */
    public function normalizePhoneNumber(string $phone): string
    {
        $converted = $this->convertMyanmarDigits($phone);
        $clean = preg_replace('/[^\d+]/', '', trim($converted));
        if (str_starts_with($clean, '+959')) {
            return '09' . substr($clean, 4);
        }
        if (str_starts_with($clean, '959')) {
            return '09' . substr($clean, 3);
        }
        if (str_starts_with($clean, '+95')) {
            return '0' . substr($clean, 3);
        }
        if (str_starts_with($clean, '9') && strlen($clean) >= 9 && strlen($clean) <= 11) {
            return '0' . $clean;
        }
        return $clean;
    }

    /**
     * Log Outgoing System Bot Message to Live Chat history
     */
    public function logOutgoingBotMessage(int|string $chatId, string $text, string $type = 'text', ?array $metadata = null): void
    {
        try {
            $customerName = 'Customer';
            $cust = BotChatMessage::where('telegram_chat_id', (string)$chatId)->whereNotNull('customer_name')->latest('id')->first();
            if ($cust && !empty($cust->customer_name)) {
                $customerName = $cust->customer_name;
            }

            BotChatMessage::create([
                'telegram_chat_id' => (string)$chatId,
                'customer_name' => $customerName,
                'sender_type' => 'bot',
                'message_type' => $type,
                'message_text' => $text,
                'metadata' => $metadata,
                'is_read' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('logOutgoingBotMessage error: ' . $e->getMessage());
        }
    }

    /**
     * Send Chat Action (typing, upload_photo, find_location, etc.)
     * Displays real-time status in customer Telegram chat bar for fluid microcopy
     */
    public function sendChatAction(string|int $chatId, string $action = 'typing'): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false];
        }

        try {
            $response = Http::connectTimeout(2)->timeout(3)->withoutVerifying()->post("{$this->getApiUrl()}/sendChatAction", [
                'chat_id' => (string)$chatId,
                'action' => $action,
            ]);
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false];
        }
    }

    /**
     * Send Text Message
     */
    public function sendMessage(string|int $chatId, string $text, ?array $replyMarkup = null, ?int $replyToMessageId = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'Bot token missing'];
        }

        try {
            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ];

            if ($replyMarkup) {
                $payload['reply_markup'] = json_encode($replyMarkup);
            }

            if ($replyToMessageId) {
                $payload['reply_to_message_id'] = $replyToMessageId;
            }

            $response = Http::connectTimeout(15)
                ->timeout(25)
                ->withoutVerifying()
                ->post("{$this->getApiUrl()}/sendMessage", $payload);
            $resData = $response->json() ?? ['ok' => false];

            // If Markdown parsing fails (e.g. customer address or name has special characters), fallback to plain text
            if (empty($resData['ok']) && !empty($resData['description']) && (str_contains($resData['description'], "can't parse entities") || str_contains($resData['description'], "Bad Request"))) {
                unset($payload['parse_mode']);
                $retryRes = Http::connectTimeout(15)
                    ->timeout(25)
                    ->withoutVerifying()
                    ->post("{$this->getApiUrl()}/sendMessage", $payload);
                return $retryRes->json() ?? ['ok' => false];
            }

            return $resData;
        } catch (\Throwable $e) {
            // If Guzzle threw an exception due to 400 Bad Request or Markdown parse error, retry in plain text
            if (str_contains($e->getMessage(), "can't parse entities") || str_contains($e->getMessage(), "400")) {
                try {
                    $plainPayload = [
                        'chat_id' => $chatId,
                        'text' => $text,
                    ];
                    if ($replyMarkup) {
                        $plainPayload['reply_markup'] = json_encode($replyMarkup);
                    }
                    if ($replyToMessageId) {
                        $plainPayload['reply_to_message_id'] = $replyToMessageId;
                    }
                    $retryRes = Http::connectTimeout(15)
                        ->timeout(25)
                        ->withoutVerifying()
                        ->post("{$this->getApiUrl()}/sendMessage", $plainPayload);
                    $resFallback = $retryRes->json() ?? ['ok' => false];
                    if (!empty($resFallback['ok'])) {
                        return $resFallback;
                    }
                } catch (\Throwable $ex) {
                    // fallback failed
                }
            }
            Log::error('Telegram sendMessage failed: ' . $e->getMessage());
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    /**
     * Resolve local image path on disk from various formats (URL, relative storage path, etc.)
     */
    public function resolveLocalImagePath(string $pathOrUrl): ?string
    {
        // 1. Direct path on disk
        if (file_exists($pathOrUrl) && is_file($pathOrUrl)) {
            return $pathOrUrl;
        }

        // 2. Local URL like http://127.0.0.1:8000/storage/products/xxx.jpg
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            $urlParts = parse_url($pathOrUrl);
            $urlPath = $urlParts['path'] ?? '';
            // Remove leading /storage/ or storage/
            $relPath = preg_replace('#^/?storage/#', '', $urlPath);
            $candidate = storage_path('app/public/' . ltrim($relPath, '/'));
            if (file_exists($candidate) && is_file($candidate)) {
                return $candidate;
            }

            $candidatePublic = public_path(ltrim($urlPath, '/'));
            if (file_exists($candidatePublic) && is_file($candidatePublic)) {
                return $candidatePublic;
            }
        }

        // 3. Storage relative path like products/xxx.jpg or /products/xxx.jpg
        $clean = ltrim($pathOrUrl, '/');
        $clean = preg_replace('#^storage/#', '', $clean);
        $candidate1 = storage_path('app/public/' . $clean);
        if (file_exists($candidate1) && is_file($candidate1)) {
            return $candidate1;
        }

        $candidate2 = storage_path('app/' . $clean);
        if (file_exists($candidate2) && is_file($candidate2)) {
            return $candidate2;
        }

        $candidate3 = public_path('storage/' . $clean);
        if (file_exists($candidate3) && is_file($candidate3)) {
            return $candidate3;
        }

        return null;
    }

    /**
     * Send Photo (supports Remote URLs and Local Storage Multipart File Uploads)
     */
    public function sendPhoto(string|int $chatId, string $photoSource, ?string $caption = null, ?array $replyMarkup = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'description' => 'Bot token missing'];
        }

        try {
            $payload = [
                'chat_id' => (string)$chatId,
            ];

            if ($caption) {
                $payload['caption'] = $caption;
                $payload['parse_mode'] = 'Markdown';
            }

            if ($replyMarkup) {
                $payload['reply_markup'] = json_encode($replyMarkup);
            }

            // Check if photoSource is a local file or local URL
            $localFilePath = $this->resolveLocalImagePath($photoSource);

            if ($localFilePath && file_exists($localFilePath) && is_readable($localFilePath)) {
                $fileName = basename($localFilePath);
                $fileContents = file_get_contents($localFilePath);

                $request = Http::connectTimeout(15)->timeout(25)->retry(2, 300)->withoutVerifying()->attach('photo', $fileContents, $fileName);
                $response = $request->post("{$this->getApiUrl()}/sendPhoto", $payload);
                $resData = $response->json() ?? ['ok' => false];

                // Retry without Markdown if parse error occurs
                if (empty($resData['ok']) && str_contains($resData['description'] ?? '', "can't parse entities")) {
                    unset($payload['parse_mode']);
                    $retryReq = Http::connectTimeout(15)->timeout(25)->withoutVerifying()->attach('photo', $fileContents, $fileName);
                    $retryRes = $retryReq->post("{$this->getApiUrl()}/sendPhoto", $payload);
                    return $retryRes->json() ?? ['ok' => false];
                }

                return $resData;
            }

            // If it's a valid remote URL (http/https and NOT 127.0.0.1 / localhost)
            if (filter_var($photoSource, FILTER_VALIDATE_URL) && !str_contains($photoSource, '127.0.0.1') && !str_contains($photoSource, 'localhost')) {
                $payload['photo'] = $photoSource;
                $response = Http::connectTimeout(15)->timeout(20)->retry(2, 300)->withoutVerifying()->post("{$this->getApiUrl()}/sendPhoto", $payload);
                $resData = $response->json() ?? ['ok' => false];

                if (empty($resData['ok']) && str_contains($resData['description'] ?? '', "can't parse entities")) {
                    unset($payload['parse_mode']);
                    $retryRes = Http::connectTimeout(15)->timeout(20)->withoutVerifying()->post("{$this->getApiUrl()}/sendPhoto", $payload);
                    return $retryRes->json() ?? ['ok' => false];
                }

                return $resData;
            }

            Log::warning("Telegram sendPhoto: Unable to resolve local/remote photo for '{$photoSource}'");
            return ['ok' => false, 'description' => 'Image file not found or inaccessible'];
        } catch (\Throwable $e) {
            Log::error('Telegram sendPhoto failed: ' . $e->getMessage());
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    /**
     * Edit Message Text in-place for fast pagination without chat spam
     */
    public function editMessageText(string|int $chatId, int $messageId, string $text, ?array $replyMarkup = null): array
    {
        if (!$this->isConfigured()) return ['ok' => false];

        try {
            $payload = [
                'chat_id' => (string)$chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ];

            if ($replyMarkup) {
                $payload['reply_markup'] = json_encode($replyMarkup);
            }

            $response = Http::connectTimeout(8)->timeout(12)->withoutVerifying()->post("{$this->getApiUrl()}/editMessageText", $payload);
            $resData = $response->json() ?? ['ok' => false];

            if (empty($resData['ok'])) {
                // If unmodified, that's completely normal when user clicks current page
                if (str_contains($resData['description'] ?? '', 'message is not modified')) {
                    return ['ok' => true];
                }

                // If entities parsing error, retry without parse_mode
                if (str_contains($resData['description'] ?? '', "can't parse entities")) {
                    unset($payload['parse_mode']);
                    $retryRes = Http::connectTimeout(8)->timeout(12)->withoutVerifying()->post("{$this->getApiUrl()}/editMessageText", $payload);
                    return $retryRes->json() ?? ['ok' => false];
                }
            }

            return $resData;
        } catch (\Throwable $e) {
            Log::error('Telegram editMessageText error: ' . $e->getMessage());
            return ['ok' => false];
        }
    }

    /**
     * Edit Message Caption for photo messages
     */
    public function editMessageCaption(string|int $chatId, int $messageId, string $caption, ?array $replyMarkup = null): array
    {
        if (!$this->isConfigured()) return ['ok' => false];

        try {
            $payload = [
                'chat_id' => (string)$chatId,
                'message_id' => $messageId,
                'caption' => $caption,
                'parse_mode' => 'Markdown',
            ];

            if ($replyMarkup) {
                $payload['reply_markup'] = json_encode($replyMarkup);
            }

            $response = Http::connectTimeout(15)->timeout(20)->retry(2, 300)->withoutVerifying()->post("{$this->getApiUrl()}/editMessageCaption", $payload);
            $resData = $response->json() ?? ['ok' => false];

            if (empty($resData['ok']) && str_contains($resData['description'] ?? '', "can't parse entities")) {
                unset($payload['parse_mode']);
                $retryRes = Http::connectTimeout(15)->timeout(20)->withoutVerifying()->post("{$this->getApiUrl()}/editMessageCaption", $payload);
                return $retryRes->json() ?? ['ok' => false];
            }

            return $resData;
        } catch (\Throwable $e) {
            Log::error('Telegram editMessageCaption error: ' . $e->getMessage());
            return ['ok' => false];
        }
    }

    /**
     * Edit Message Reply Markup in-place
     */
    public function editMessageReplyMarkup(string|int $chatId, int $messageId, ?array $replyMarkup = null): array
    {
        if (!$this->isConfigured()) return ['ok' => false];

        try {
            $payload = [
                'chat_id' => (string)$chatId,
                'message_id' => $messageId,
            ];

            if ($replyMarkup) {
                $payload['reply_markup'] = json_encode($replyMarkup);
            }

            $response = Http::connectTimeout(15)->timeout(20)->retry(2, 300)->withoutVerifying()->post("{$this->getApiUrl()}/editMessageReplyMarkup", $payload);
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            Log::error('Telegram editMessageReplyMarkup error: ' . $e->getMessage());
            return ['ok' => false];
        }
    }

    /**
     * Edit message in-place if messageIdToEdit is provided, otherwise send fresh message
     */
    public function editOrSendMessage(string|int $chatId, ?int $messageIdToEdit, string $text, ?array $replyMarkup = null): array
    {
        if ($messageIdToEdit) {
            $res = $this->editMessageText($chatId, $messageIdToEdit, $text, $replyMarkup);
            if (!empty($res['ok'])) {
                return $res;
            }
            $resCaption = $this->editMessageCaption($chatId, $messageIdToEdit, $text, $replyMarkup);
            if (!empty($resCaption['ok'])) {
                return $resCaption;
            }
        }
        return $this->sendMessage($chatId, $text, $replyMarkup);
    }

    /**
     * Delete Message from chat
     */
    public function deleteMessage(string|int $chatId, int $messageId): array
    {
        if (!$this->isConfigured()) return ['ok' => false];

        try {
            $response = Http::connectTimeout(5)->timeout(10)->withoutVerifying()->post("{$this->getApiUrl()}/deleteMessage", [
                'chat_id' => (string)$chatId,
                'message_id' => $messageId,
            ]);
            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            return ['ok' => false];
        }
    }

    // ==========================================
    // 🛒 SHOPPING CART SYSTEM METHODS
    // ==========================================

    /**
     * Get Customer Shopping Cart
     */
    public function getCart(int|string $chatId): array
    {
        return Cache::get("tg_cart_{$chatId}", []);
    }

    /**
     * Save Customer Shopping Cart (persist for 7 days)
     */
    public function saveCart(int|string $chatId, array $cart): void
    {
        Cache::put("tg_cart_{$chatId}", $cart, now()->addDays(7));
    }

    /**
     * Clear Customer Shopping Cart
     */
    public function clearCart(int|string $chatId): void
    {
        Cache::forget("tg_cart_{$chatId}");
    }

    /**
     * Get Cart Summary Totals
     */
    public function getCartSummary(int|string $chatId): array
    {
        $cart = $this->getCart($chatId);
        $totalQty = 0;
        $grandTotal = 0.0;

        foreach ($cart as $item) {
            $qty = (float)($item['quantity'] ?? 0);
            $sub = (float)($item['subtotal'] ?? ((float)($item['price'] ?? 0) * $qty));
            $totalQty += $qty;
            $grandTotal += $sub;
        }

        return [
            'cart' => $cart,
            'items_count' => count($cart),
            'total_qty' => $totalQty,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * Add Product/Variant to Customer Shopping Cart
     */
    public function addToCart(
        int|string $chatId,
        Product $product,
        ?ProductVariant $variant = null,
        float|int $qty = 1
    ): array {
        $cart = $this->getCart($chatId);
        $variantId = $variant?->id;
        $itemKey = $variantId ? "p_{$product->id}_v_{$variantId}" : "p_{$product->id}";

        $effectivePrice = $variant
            ? (float)($variant->selling_price ?: ($product->retail_price ?: $product->selling_price))
            : (float)($product->retail_price ?: $product->selling_price);

        $existingQty = isset($cart[$itemKey]) ? (float)$cart[$itemKey]['quantity'] : 0.0;
        $newQty = $existingQty + (float)$qty;

        $cart[$itemKey] = [
            'key' => $itemKey,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->code ?: $product->sku,
            'variant_id' => $variantId,
            'variant_name' => $variant?->name,
            'variant_sku' => $variant?->sku ?: ($product->code . ($variant ? '-' . $variant->name : '')),
            'price' => $effectivePrice,
            'quantity' => $newQty,
            'unit_name' => $product->unit_name ?: 'ခု',
            'subtotal' => $newQty * $effectivePrice,
        ];

        $this->saveCart($chatId, $cart);

        return [
            'cart' => $cart,
            'added_item' => $cart[$itemKey],
            'items_count' => count($cart),
            'total_qty' => (float)collect($cart)->sum('quantity'),
            'grand_total' => (float)collect($cart)->sum('subtotal'),
        ];
    }

    /**
     * Remove single item from Shopping Cart
     */
    public function removeFromCart(int|string $chatId, string $itemKey): array
    {
        $cart = $this->getCart($chatId);
        if (isset($cart[$itemKey])) {
            unset($cart[$itemKey]);
            $this->saveCart($chatId, $cart);
        }

        return $this->getCartSummary($chatId);
    }

    /**
     * Display Shopping Cart view to customer with breakdown and actions
     */
    public function sendCartView(
        int|string $chatId,
        string $userName = '',
        ?int $messageIdToEdit = null,
        bool $isEditMode = false,
        ?string $flashNotice = null,
        ?string $callbackQueryId = null,
        ?string $toastText = null
    ): void {
        $cartSummary = $this->getCartSummary($chatId);
        $cart = $cartSummary['cart'];

        $btnAll = Setting::get('telegram_bot_btn_all_products', '🛍️ ကုန်ပစ္စည်းများ ကြည့်မည်');
        $btnMainMenu = Setting::get('telegram_bot_btn_main_menu', '🔙 ပင်မ မီနူးသို့');

        if (empty($cart)) {
            $cartEmptyTpl = Setting::get('telegram_bot_cart_empty_msg', '');
            $emptyMsg = !empty(trim($cartEmptyTpl))
                ? $this->replacePlaceholders($cartEmptyTpl, ['customer_name' => $userName, 'user_name' => $userName])
                : "🛒 *သင်၏ ခြင်းတောင်းထဲတွင် ပစ္စည်းမရှိသေးပါရှင်*\n\n"
                . "ကျွန်မတို့ဆိုင်၏ အရည်အသွေးမြင့် ကုန်ပစ္စည်းများကို '🛍️ ကုန်ပစ္စည်းများ' မှတဆင့် စိတ်ကြိုက် ရွေးချယ်မှာယူနိုင်ပါသည်ရှင်။ 🙏";

            $emptyButtons = [
                'inline_keyboard' => [
                    [['text' => !empty($btnAll) ? $btnAll : '🛍️ ကုန်ပစ္စည်းများ ကြည့်မည်', 'callback_data' => 'nav_all_products']],
                    [['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main']],
                ],
            ];

            if ($messageIdToEdit) {
                if ($callbackQueryId) {
                    $apiUrl = $this->getApiUrl();
                    $ansPayload = ['callback_query_id' => $callbackQueryId];
                    if ($toastText) {
                        $ansPayload['text'] = $toastText;
                        $ansPayload['show_alert'] = false;
                    }
                    try {
                        Http::pool(fn (\Illuminate\Http\Client\Pool $pool) => [
                            $pool->as('answer')->connectTimeout(4)->timeout(8)->withoutVerifying()->post("{$apiUrl}/answerCallbackQuery", $ansPayload),
                            $pool->as('edit')->connectTimeout(4)->timeout(8)->withoutVerifying()->post("{$apiUrl}/editMessageText", [
                                'chat_id' => (string)$chatId,
                                'message_id' => $messageIdToEdit,
                                'text' => $emptyMsg,
                                'parse_mode' => 'Markdown',
                                'reply_markup' => json_encode($emptyButtons),
                            ]),
                        ]);
                        return;
                    } catch (\Throwable $e) {
                        Log::warning('Parallel sendCartView empty error: ' . $e->getMessage());
                    }
                } else {
                    $res = $this->editMessageText($chatId, $messageIdToEdit, $emptyMsg, $emptyButtons);
                    if (!empty($res['ok'])) return;
                }
            }
            $this->sendMessage($chatId, $emptyMsg, $emptyButtons);
            return;
        }

        // Customizable Header, Totals, and Buttons
        $showHeader = Setting::get('telegram_bot_cart_show_header', '1') === '1';
        $headerTitle = Setting::get('telegram_bot_cart_header_title', '🛒 ယူထားတဲ့ စာရင်း');
        $showTotal = Setting::get('telegram_bot_cart_show_total', '1') === '1';
        $totalLabel = Setting::get('telegram_bot_cart_total_label', '💰 စုစုပေါင်း:');
        $showUnitPrice = Setting::get('telegram_bot_cart_show_unit_price', '1') === '1';

        $showBtnDec = Setting::get('telegram_bot_cart_show_btn_dec', '1') === '1';
        $showItemName = Setting::get('telegram_bot_cart_show_item_name', '1') === '1';
        $showBtnInc = Setting::get('telegram_bot_cart_show_btn_inc', '1') === '1';
        $showBtnDel = Setting::get('telegram_bot_cart_show_btn_del', '1') === '1';

        $btnIncRaw = Setting::get('telegram_bot_cart_btn_inc', '➕');
        $btnDecRaw = Setting::get('telegram_bot_cart_btn_dec', '➖');
        $btnDelRaw = Setting::get('telegram_bot_cart_btn_del', '🗑️');

        $btnInc = !empty(trim((string)$btnIncRaw)) ? trim((string)$btnIncRaw) : '➕';
        $btnDec = !empty(trim((string)$btnDecRaw)) ? trim((string)$btnDecRaw) : '➖';
        $btnDel = !empty(trim((string)$btnDelRaw)) ? trim((string)$btnDelRaw) : '🗑️';
        $showEditor = Setting::get('telegram_bot_cart_show_editor', '1') === '1';

        $btnDone = Setting::get('telegram_bot_cart_btn_done', '✅ Done');
        $showDoneBtn = Setting::get('telegram_bot_cart_show_done_btn', '1') === '1';

        $btnCheckout = Setting::get('telegram_bot_cart_btn_checkout_inline', Setting::get('telegram_bot_btn_checkout', '📦 မှာမည့်ပစ္စည်းများ Order တင်မည်'));
        $showCheckoutBtn = Setting::get('telegram_bot_cart_show_checkout_btn', '1') === '1';

        $btnEdit = Setting::get('telegram_bot_btn_edit_cart', '✏️ စာရင်း ပြင်မယ်');
        $showEditBtn = Setting::get('telegram_bot_cart_show_edit_btn', '1') === '1';

        $btnContinue = Setting::get('telegram_bot_btn_continue_shopping', 'တခြားပစ္စည်းကြည့်ဦးမယ်');
        $showContinueBtn = Setting::get('telegram_bot_cart_show_continue_btn', '1') === '1';

        $text = '';
        if (!empty($flashNotice)) {
            $text .= "{$flashNotice}\n\n";
        }
        if ($showHeader) {
            $hdr = !empty(trim($headerTitle)) ? trim($headerTitle) : '🛒 ယူထားတဲ့ စာရင်း';
            $text .= "*{$hdr}*\n\n";
        }

        $idx = 1;
        $editRows = [];
        $indexIcons = [1 => '1️⃣', 2 => '2️⃣', 3 => '3️⃣', 4 => '4️⃣', 5 => '5️⃣', 6 => '6️⃣', 7 => '7️⃣', 8 => '8️⃣', 9 => '9️⃣', 10 => '🔟'];

        foreach ($cart as $key => $item) {
            $title = !empty($item['variant_name']) ? "{$item['product_name']} ({$item['variant_name']})" : $item['product_name'];
            $priceFmt = number_format($item['price']);
            $subFmt = number_format($item['subtotal']);
            $qtyDisplay = (floor($item['quantity']) == $item['quantity']) ? (int)$item['quantity'] : $item['quantity'];
            $unitName = !empty($item['unit_name']) ? $item['unit_name'] : 'ခု';

            $badge = $indexIcons[$idx] ?? "{$idx}.";

            // Tree-structured alignment: Title on line 1, clean calculation indented on line 2
            if ($showUnitPrice) {
                $text .= "{$badge} *{$title}*\n   ▫️ {$qtyDisplay} {$unitName} × {$priceFmt} = *{$subFmt} ကျပ်*\n\n";
            } else {
                $text .= "{$badge} *{$title}*\n   ▫️ {$qtyDisplay} {$unitName} — *{$subFmt} ကျပ်*\n\n";
            }

            if ($showEditor) {
                $shortTitle = mb_strimwidth($item['product_name'], 0, 14, '..');
                $row = [];
                if ($showBtnDec) {
                    $row[] = ['text' => $btnDec, 'callback_data' => "cart_dec_{$key}"];
                }
                if ($showItemName) {
                    $row[] = ['text' => "{$shortTitle} ×{$qtyDisplay}", 'callback_data' => 'noop'];
                }
                if ($showBtnInc) {
                    $row[] = ['text' => $btnInc, 'callback_data' => "cart_inc_{$key}"];
                }
                if ($showBtnDel) {
                    $row[] = ['text' => $btnDel, 'callback_data' => "cart_del_{$key}"];
                }
                if (!empty($row)) {
                    $editRows[] = $row;
                }
            }
            $idx++;
        }

        if ($showTotal) {
            $grandTotalFmt = number_format($cartSummary['grand_total']);
            $totLabel = !empty(trim($totalLabel)) ? trim($totalLabel) : '💰 စုစုပေါင်း:';
            $text .= "{$totLabel} *{$grandTotalFmt} ကျပ်*";
        }

        $footerMsg = Setting::get('telegram_bot_cart_footer_msg', '');
        if (!empty(trim((string)$footerMsg))) {
            $text .= "\n\n" . trim((string)$footerMsg);
        }

        if ($isEditMode) {
            if ($showDoneBtn) {
                $editRows[] = [
                    ['text' => !empty($btnDone) ? $btnDone : '✅ Done', 'callback_data' => 'cart_done'],
                ];
            }
            $markup = ['inline_keyboard' => $editRows];
        } else {
            $reviewRows = [];
            $actionRow = [];
            if ($showCheckoutBtn) {
                $actionRow[] = ['text' => !empty($btnCheckout) ? $btnCheckout : '📦 မှာမည့်ပစ္စည်းများ Order တင်မည်', 'callback_data' => 'cart_checkout'];
            }
            if ($showEditBtn && $showEditor) {
                $actionRow[] = ['text' => !empty($btnEdit) ? $btnEdit : '✏️ စာရင်း ပြင်မယ်', 'callback_data' => 'cart_edit'];
            }
            if (!empty($actionRow)) {
                $reviewRows[] = $actionRow;
            }
            if ($showContinueBtn) {
                $reviewRows[] = [['text' => !empty($btnContinue) ? $btnContinue : 'တခြားပစ္စည်းကြည့်ဦးမယ်', 'callback_data' => 'nav_all_products']];
            }
            $markup = ['inline_keyboard' => $reviewRows];
        }

        if ($messageIdToEdit) {
            if ($callbackQueryId) {
                $apiUrl = $this->getApiUrl();
                $editPayload = [
                    'chat_id' => (string)$chatId,
                    'message_id' => $messageIdToEdit,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                ];
                if ($markup) {
                    $editPayload['reply_markup'] = json_encode($markup);
                }

                $ansPayload = ['callback_query_id' => $callbackQueryId];
                if ($toastText) {
                    $ansPayload['text'] = $toastText;
                    $ansPayload['show_alert'] = false;
                }

                try {
                    $responses = Http::pool(fn (\Illuminate\Http\Client\Pool $pool) => [
                        $pool->as('answer')->connectTimeout(4)->timeout(8)->withoutVerifying()->post("{$apiUrl}/answerCallbackQuery", $ansPayload),
                        $pool->as('edit')->connectTimeout(4)->timeout(8)->withoutVerifying()->post("{$apiUrl}/editMessageText", $editPayload),
                    ]);

                    $editRes = $responses['edit']->json() ?? ['ok' => false];
                    if (!empty($editRes['ok']) || str_contains($editRes['description'] ?? '', 'message is not modified')) {
                        return;
                    }
                    if (str_contains($editRes['description'] ?? '', "can't parse entities")) {
                        unset($editPayload['parse_mode']);
                        Http::connectTimeout(4)->timeout(8)->withoutVerifying()->post("{$apiUrl}/editMessageText", $editPayload);
                        return;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Parallel sendCartView pool error: ' . $e->getMessage());
                }
            } else {
                $res = $this->editMessageText($chatId, $messageIdToEdit, $text, $markup);
                if (!empty($res['ok'])) return;
            }
        }

        $this->sendMessage($chatId, $text, $markup);
    }

    /**
     * Initiate Multi-Item Cart Checkout flow
     */
    public function initiateCartCheckout(int|string $chatId, string $userName): void
    {
        $cartSummary = $this->getCartSummary($chatId);
        $cart = $cartSummary['cart'];

        if (empty($cart)) {
            $cartEmptyTpl = Setting::get('telegram_bot_cart_empty_msg', '');
            $emptyMsg = !empty(trim($cartEmptyTpl))
                ? $this->replacePlaceholders($cartEmptyTpl, ['customer_name' => $userName, 'user_name' => $userName])
                : "🛒 *သင်၏ ခြင်းတောင်းထဲတွင် ပစ္စည်းမရှိသေးပါရှင်*\n\nကျွန်မတို့ဆိုင်၏ အရည်အသွေးမြင့် ကုန်ပစ္စည်းများကို ရွေးချယ်ပြီးမှ Order တင်ပေးပါရှင်။ 🙏";

            $btnAll = Setting::get('telegram_bot_btn_all_products', '🛍️ ကုန်ပစ္စည်းများ ကြည့်မည်');
            $btnMainMenu = Setting::get('telegram_bot_btn_main_menu', '🔙 ပင်မ မီနူးသို့');
            $this->sendMessage($chatId, $emptyMsg, [
                'inline_keyboard' => [
                    [['text' => !empty($btnAll) ? $btnAll : '🛍️ ကုန်ပစ္စည်းများ ကြည့်မည်', 'callback_data' => 'nav_all_products']],
                    [['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main']],
                ],
            ]);
            return;
        }

        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
        $allowOutOfStock = Setting::get('telegram_bot_allow_out_of_stock_orders', '0') === '1';

        // Inventory Stock verification
        $outOfStockList = [];
        if (!$allowOutOfStock) {
            foreach ($cart as $item) {
                $stockQuery = StockBalance::where('warehouse_id', $warehouseId)
                    ->where('product_id', $item['product_id']);
                if (!empty($item['variant_id'])) {
                    $stockQuery->where('product_variant_id', $item['variant_id']);
                }
                $stock = (float)$stockQuery->sum('quantity');
                if ($stock < (float)$item['quantity']) {
                    $title = !empty($item['variant_name']) ? "{$item['product_name']} ({$item['variant_name']})" : $item['product_name'];
                    $outOfStockList[] = "• {$title} (လက်ကျန်: {$stock} " . ($item['unit_name'] ?? 'ခု') . " သာရှိပါသည်)";
                }
            }
        }

        if (!empty($outOfStockList)) {
            $warnMsg = "⚠️ *အချို့ပစ္စည်းများ လက်ကျန် မလုံလောက်ပါ:*\n\n"
                     . implode("\n", $outOfStockList) . "\n\n"
                     . "ကျေးဇူးပြု၍ ခြင်းတောင်းထဲမှ အရေအတွက် လျှော့ချပေးပါရှင်။";
            $btnCart = Setting::get('telegram_bot_btn_cart', '🛒 ခြင်းတောင်း ကြည့်မည်');
            $btnClear = Setting::get('telegram_bot_btn_clear_cart', '🗑️ ခြင်းတောင်း ရှင်းမည်');
            $this->sendMessage($chatId, $warnMsg, [
                'inline_keyboard' => [
                    [['text' => !empty($btnCart) ? $btnCart : '🛒 ခြင်းတောင်း ကြည့်မည်', 'callback_data' => 'cart_view']],
                    [['text' => !empty($btnClear) ? $btnClear : '🗑️ ခြင်းတောင်း ရှင်းမည်', 'callback_data' => 'cart_clear']],
                ]
            ]);
            return;
        }

        // Store checkout state in session
        $session = [
            'is_cart' => true,
            'items' => array_values($cart),
            'grand_total' => $cartSummary['grand_total'],
            'total_qty' => $cartSummary['total_qty'],
            'customer_name' => $userName ?: 'Customer',
            'step' => 'waiting_phone',
        ];
        Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

        $totalFormatted = number_format($cartSummary['grand_total']);
        $indexIcons = [1 => '1️⃣', 2 => '2️⃣', 3 => '3️⃣', 4 => '4️⃣', 5 => '5️⃣', 6 => '6️⃣', 7 => '7️⃣', 8 => '8️⃣', 9 => '9️⃣', 10 => '🔟'];
        $itemsList = collect($cart)->values()->map(function ($it, $idx) use ($indexIcons) {
            $num = $idx + 1;
            $badge = $indexIcons[$num] ?? "{$num}.";
            $title = !empty($it['variant_name']) ? "{$it['product_name']} ({$it['variant_name']})" : $it['product_name'];
            $qty = (floor($it['quantity']) == $it['quantity']) ? (int)$it['quantity'] : $it['quantity'];
            $unit = !empty($it['unit_name']) ? $it['unit_name'] : 'ခု';
            $price = number_format($it['price'] ?? 0);
            $sub = number_format($it['subtotal'] ?? 0);
            return "{$badge} *{$title}*\n   ▫️ {$qty} {$unit} × {$price}  =  *{$sub} MMK*";
        })->implode("\n\n");

        $tpl = Setting::get('telegram_bot_ask_phone_msg', '');
        $askPrompt = !empty(trim($tpl))
            ? $this->replacePlaceholders($tpl, [
                'customer_name' => $userName ?: 'ဖောက်သည်',
                'user_name' => $userName ?: 'ဖောက်သည်',
                'amount' => $totalFormatted,
                'grand_total' => $totalFormatted,
                'total' => $totalFormatted,
                'quantity' => (string)$cartSummary['total_qty'],
                'qty' => (string)$cartSummary['total_qty'],
            ])
            : "📱 ဆက်သွယ်ရမည့် *ဖုန်းနံပါတ်* ရိုက်ထည့်ပေးပါရှင် (ဥပမာ- 09123456789) -";

        $msg = "📝 *အော်ဒါ အနှစ်ချုပ် ({$cartSummary['items_count']} မျိုး / {$cartSummary['total_qty']} ခု)*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "{$itemsList}\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "💰 စုစုပေါင်း ကျသင့်ငွေ:  *{$totalFormatted} MMK*\n\n"
             . "{$askPrompt}";

        $btnCancel = Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)');
        $btnSharePhone = Setting::get('telegram_bot_btn_share_phone', '📱 ကျွန်ုပ်၏ ဖုန်းနံပါတ် ပေးပို့မည် (Share Phone)');
        $btnCart = Setting::get('telegram_bot_btn_cart', '🛒 ခြင်းတောင်း ကြည့်မည်');

        $showSharePhone = Setting::get('telegram_bot_show_btn_share_phone', '1') === '1';
        $showBtnCart = Setting::get('telegram_bot_show_btn_cart', '1') === '1';
        $showBtnCancel = Setting::get('telegram_bot_show_catalog_btn_cancel', '1') === '1';

        $keyboardRows = [];
        if ($showSharePhone) {
            $keyboardRows[] = [['text' => !empty($btnSharePhone) ? $btnSharePhone : '📱 ကျွန်ုပ်၏ ဖုန်းနံပါတ် ပေးပို့မည် (Share Phone)', 'request_contact' => true]];
        }
        if ($showBtnCart) {
            $keyboardRows[] = [['text' => !empty($btnCart) ? $btnCart : '🛒 ခြင်းတောင်း ကြည့်မည်']];
        }
        if ($showBtnCancel) {
            $keyboardRows[] = [['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)']];
        }

        $phoneKeyboard = !empty($keyboardRows)
            ? [
                'keyboard' => $keyboardRows,
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]
            : ['remove_keyboard' => true];

        $this->sendMessage($chatId, $msg, $phoneKeyboard);
        $this->logOutgoingBotMessage($chatId, $msg);
    }

    /**
     * Answer Callback Query
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): array
    {
        if (!$this->isConfigured()) return ['ok' => false];

        try {
            $payload = ['callback_query_id' => $callbackQueryId];
            if ($text) {
                $payload['text'] = $text;
                $payload['show_alert'] = $showAlert;
            }
            $res = Http::connectTimeout(3)->timeout(5)->withoutVerifying()->post("{$this->getApiUrl()}/answerCallbackQuery", $payload);
            return $res->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            Log::error('answerCallbackQuery error: ' . $e->getMessage());
            return ['ok' => false];
        }
    }

    /**
     * Download Telegram File, store locally, and return URL with SHA-256 hash for anti-fraud detection
     */
    public function downloadTelegramFileWithHash(string $fileId): ?array
    {
        if (!$this->isConfigured()) return null;

        try {
            $res = Http::timeout(10)->withoutVerifying()->get("{$this->getApiUrl()}/getFile", ['file_id' => $fileId]);
            if (!$res->successful() || !$res->json('ok')) {
                return null;
            }

            $filePath = $res->json('result.file_path');
            $fileUrl = "https://api.telegram.org/file/bot{$this->getToken()}/{$filePath}";

            $fileContents = Http::timeout(25)->withoutVerifying()->get($fileUrl)->body();
            $hash = hash('sha256', $fileContents);

            $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            $filename = 'bot_slip_' . date('Ymd_His') . '_' . rand(1000, 9999) . '.' . $ext;
            $storagePath = "bot_slips/{$filename}";

            Storage::disk('public')->put($storagePath, $fileContents);

            $baseUrl = rtrim(config('app.url', 'http://127.0.0.1:8000'), '/');
            if (!str_contains($baseUrl, ':8000') && !str_contains($baseUrl, 'https://') && (str_contains($baseUrl, 'localhost') || str_contains($baseUrl, '127.0.0.1'))) {
                $baseUrl .= ':8000';
            }
            return [
                'url' => "{$baseUrl}/storage/{$storagePath}",
                'hash' => $hash,
                'contents' => $fileContents,
                'mime' => 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext),
                'storage_path' => $storagePath,
            ];
        } catch (\Throwable $e) {
            Log::error('downloadTelegramFileWithHash failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Download Telegram File and store locally
     */
    public function downloadTelegramFile(string $fileId): ?string
    {
        $res = $this->downloadTelegramFileWithHash($fileId);
        return $res ? $res['url'] : null;
    }

    /**
     * Main Entry Point: Handle Any Incoming Telegram Update
     */
    public function handleIncomingUpdate(array $update): void
    {
        try {
            $this->ensureFreshToken();

            $updateId = $update['update_id'] ?? null;
            if ($updateId !== null) {
                $dedupKey = "tg_processed_update_{$updateId}";
                if (!Cache::add($dedupKey, true, now()->addMinutes(10))) {
                    Log::info("Duplicate Telegram update skipped: {$updateId}");
                    return;
                }
            }

            if (isset($update['callback_query'])) {
                $this->handleCallbackQuery($update['callback_query']);
                return;
            }

            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
                return;
            }
        } catch (\Throwable $e) {
            Log::error('handleIncomingUpdate error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            try {
                $chatId = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
                if ($chatId) {
                    $shopPhone = Setting::get('company_phone', '');
                    $supportButtons = [];
                    if (!empty($shopPhone)) {
                        $supportButtons = [
                            'inline_keyboard' => [
                                [['text' => "📞 ဆိုင်သို့ ဆက်သွယ်ရန် ({$shopPhone})", 'callback_data' => 'contact_info']],
                            ],
                        ];
                    }
                    $politeMsg = "⚠️ တောင်းပန်ပါသည်ရှင်၊ စနစ်အနည်းငယ် ချို့ယွင်းနေပါသဖြင့် ခေတ္တစောင့်ဆိုင်းပြီး ပြန်လည်ကြိုးစားပေးပါရှင်။ 🙏\n\nအရေးကြီးပါက ဆိုင်သို့ တိုက်ရိုက် ဆက်သွယ်ပေးပါရန် မေတ္တာရပ်ခံအပ်ပါသည်ရှင်။";
                    $this->sendMessage($chatId, $politeMsg, $supportButtons);
                }
            } catch (\Throwable $ignored) {}
        }
    }

    /**
     * Handle Telegram Messages
     */
    protected function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        if (!$chatId) return;

        $userName = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? ''));
        $usernameTag = !empty($message['from']['username']) ? '@' . $message['from']['username'] : '';
        $caption = trim($message['caption'] ?? '');
        $text = trim($message['text'] ?? '');
        if (empty($text) && !empty($caption)) {
            $text = $caption;
        }

        // 0. Log incoming text customer message for Live Chat & Audit (photos/docs logged after download with image_url)
        if (empty($message['photo']) && empty($message['document'])) {
            try {
                $msgType = 'text';
                $msgContent = $text;
                if (empty($msgContent)) {
                    if (!empty($message['contact'])) $msgContent = '[ဖုန်းနံပါတ်: ' . ($message['contact']['phone_number'] ?? '') . ']';
                    elseif (!empty($message['location'])) $msgContent = '[GPS တည်နေရာ ပေးပို့ခဲ့သည်]';
                    else $msgContent = '[မက်ဆေ့ချ်]';
                }

                BotChatMessage::create([
                    'telegram_chat_id' => (string)$chatId,
                    'customer_name' => $userName,
                    'telegram_username' => $usernameTag,
                    'sender_type' => 'customer',
                    'message_type' => $msgType,
                    'message_text' => $msgContent,
                    'is_read' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Log BotChatMessage error: ' . $e->getMessage());
            }
        }

        // 0b. Admin Mobile Telegram Reply-Relay: Check if Admin is replying to a forwarded customer alert
        $adminChatIdsRaw = Setting::get('telegram_admin_chat_ids', '');
        if (!empty($adminChatIdsRaw)) {
            $adminIds = array_map('trim', explode(',', $adminChatIdsRaw));
            if (in_array((string)$chatId, $adminIds, true) && !empty($message['reply_to_message'])) {
                $repliedText = $message['reply_to_message']['text'] ?? ($message['reply_to_message']['caption'] ?? '');
                if (preg_match('/(?:#CHAT_|Customer ID:\s*)([A-Za-z0-9_-]+)/i', $repliedText, $matches)) {
                    $targetCustomerChatId = $matches[1];
                    $adminReplyText = trim($text);
                    if (!empty($adminReplyText)) {
                        $this->sendMessage($targetCustomerChatId, "👨‍💼 *ဆိုင်တာဝန်ခံမှ စာပြန်ကြားချက်:*\n\n{$adminReplyText}");

                        BotChatMessage::create([
                            'telegram_chat_id' => (string)$targetCustomerChatId,
                            'customer_name' => $userName,
                            'sender_type' => 'staff',
                            'message_type' => 'text',
                            'message_text' => $adminReplyText,
                            'is_read' => true,
                        ]);

                        $this->sendMessage($chatId, "✅ Customer (`{$targetCustomerChatId}`) သို့ စာပြန်ကြားချက် အောင်မြင်စွာ ပို့ဆောင်ပြီးပါပြီ။");
                        return;
                    }
                }
            }
        }

        // 1. Photo message (Product inquiry or Payment Slip)
        if (!empty($message['photo'])) {
            $this->handlePhotoMessage($chatId, $message['photo'], $userName, $usernameTag, $caption);
            return;
        }

        // 2. Document message (Payment Slip or image sent as file / uncompressed)
        if (!empty($message['document'])) {
            $this->handleDocumentMessage($chatId, $message['document'], $userName, $usernameTag, $caption);
            return;
        }

        // 3. Contact shared (1-Tap Verified Contact)
        if (!empty($message['contact']['phone_number'])) {
            $this->handleContactMessage($chatId, $message['contact']['phone_number'], $userName);
            return;
        }

        // 4. Location shared (1-Tap GPS Location)
        if (!empty($message['location'])) {
            $this->handleLocationMessage($chatId, (float)$message['location']['latitude'], (float)$message['location']['longitude'], $userName, $usernameTag);
            return;
        }

        // 3. AI Direct Control Commands
        if (strcasecmp($text, '/ai') === 0 || strcasecmp($text, '/bot') === 0) {
            if ($this->aiAgentService->getOperatingMode() === 'standard') {
                $this->sendMessage(
                    $chatId,
                    "📱 *ဤဘော့တ်သည် ရိုးရိုး မီနူးခလုတ်စနစ် (Standard Mode) ဖြင့် လည်ပတ်နေပါသည်ရှင်။*\n\nအောက်ပါ မီနူးခလုတ်များမှတဆင့် ကုန်ပစ္စည်းများကို အဆင်ပြေစွာ ရွေးချယ်ကြည့်ရှုမှာယူနိုင်ပါသည်ရှင်။ 🙏",
                    $this->getMainKeyboard($chatId)
                );
                return;
            }
            $this->aiAgentService->resumeAiForChat($chatId);
            $this->sendMessage($chatId, "🤖 *AI အရောင်းလက်ထောက် စနစ်ကို ပြန်လည်ဖွင့်လိုက်ပါပြီရှင်။*\n\nကုန်ပစ္စည်းများနှင့် စျေးနှုန်းများကို စိတ်ကြိုက် မေးမြန်းနိုင်ပါပြီရှင် 🙏");
            return;
        }

        if (strcasecmp($text, '/human') === 0 || strcasecmp($text, '/staff') === 0) {
            $this->aiAgentService->pauseAiForChat($chatId, 60);
            BotChatMessage::where('telegram_chat_id', (string)$chatId)->latest('id')->update(['needs_human' => true]);
            $this->notifyAdminCustomerNeedsHelp($chatId, $userName, $usernameTag, "Customer requested staff assistance via /human");
            $staffNotice = ($this->aiAgentService->getOperatingMode() === 'standard')
                ? "👨‍💼 *ဆိုင်ဝန်ထမ်းထံသို့ အကြောင်းကြားထားပါသည်ရှင်။*\n\nမကြာမီ လူကိုယ်တိုင် ဝင်ရောက်စာပြန်ပေးပါမည်ရှင်။ 🙏"
                : "👨‍💼 *ဆိုင်ဝန်ထမ်းထံသို့ အကြောင်းကြားထားပါသည်ရှင်။*\n\nမကြာမီ လူကိုယ်တိုင် ဝင်ရောက်စာပြန်ပေးပါမည်ရှင်။\n(AI လက်ထောက်နှင့် ပြန်ပြောလိုပါက /ai ဟု ရိုက်ပို့နိုင်ပါသည်)";
            $this->sendMessage($chatId, $staffNotice);
            return;
        }

        // 4. Command & Menu Handling
        $isStartCmd = str_starts_with($text, '/start');
        $isAiActive = $this->aiAgentService->isEnabled() && !$this->aiAgentService->isAiPausedForChat($chatId);

        if ($isStartCmd || (!$isAiActive && (strcasecmp($text, 'hi') === 0 || strcasecmp($text, 'hello') === 0 || strcasecmp($text, 'menu') === 0))) {
            // Check for deep link payload e.g. /start p_12 or /start cat_5 or /start ref_SALE-1234
            $parts = explode(' ', $text, 2);
            $payload = isset($parts[1]) ? trim($parts[1]) : '';

            if (!empty($payload)) {
                // 1. Direct Cart link
                if (in_array(strtolower($payload), ['cart', 'mycart', 'basket'])) {
                    $this->sendCartView($chatId, $userName);
                    return;
                }

                // 2. Direct Catalog link
                if (in_array(strtolower($payload), ['catalog', 'shop', 'products', 'items'])) {
                    $this->sendCatalog($chatId);
                    return;
                }

                // 3. Category deep link: cat_5, c_5, category_5
                if (preg_match('/^(?:cat|c|category)_(\d+)$/i', $payload, $m)) {
                    $catId = (int)$m[1];
                    $category = ProductCategory::find($catId);
                    $this->sendCatalog($chatId, page: 1, categoryId: $catId, headerTitle: "📂 *" . ($category?->name ?? 'အမျိုးအစား') . " ကုန်ပစ္စည်းများ*");
                    return;
                }

                // 3b. Sub-Category deep link: subcat_43, sub_43, scat_43, sc_43, subcategory_43, sub_category_43
                if (preg_match('/^(?:subcat|sub_cat|sub|scat|sc|subcategory|sub_category)_(\d+)$/i', $payload, $m)) {
                    $subId = (int)$m[1];
                    $subCategory = SubCategory::find($subId);
                    if ($subCategory) {
                        $parentName = $subCategory->parent_category_name ?: ($subCategory->parentCategory?->name ?? '');
                        $header = !empty($parentName)
                            ? "📂 *{$parentName}* › 🔹 *{$subCategory->name}* ကုန်ပစ္စည်းများ"
                            : "🔹 *{$subCategory->name} ကုန်ပစ္စည်းများ*";
                        $this->sendCatalog($chatId, page: 1, subCategoryId: $subId, headerTitle: $header);
                        return;
                    } else {
                        $msg = "🔍 *အမျိုးအစားခွဲ ရှာမတွေ့ပါ*\n━━━━━━━━━━━━━━━━━━━━━\nသင်နှိပ်လိုက်သော လင့်ခ်မှ အမျိုးအစားခွဲ (Sub-Category ID: `{$subId}`) ကို ရှာမတွေ့ပါရှင်။ ဆိုင်ရှိ အခြားကုန်ပစ္စည်းများကို အောက်ပါခလုတ်မှတစ်ဆင့် ကြည့်ရှုနိုင်ပါသည်-";
                        $this->sendMessage($chatId, $msg, [
                            'inline_keyboard' => [
                                [
                                    ['text' => '🛍️ ကုန်ပစ္စည်းများ ကြည့်ရှုမည်', 'callback_data' => 'nav_all_products'],
                                    ['text' => '🏠 မူလစာမျက်နှာ', 'callback_data' => 'nav_home'],
                                ]
                            ]
                        ]);
                        return;
                    }
                }

                // 4. Order tracking deep link: ref_SALE-1234, track_SALE-1234, order_SALE-1234
                if (preg_match('/^(?:ref|track|order)_([A-Za-z0-9_-]+)$/i', $payload, $m)) {
                    $this->sendOrderTrackCard($chatId, $m[1]);
                    return;
                }

                // 5. Product deep link (e.g. p_BOT-060, BOT-060, prod_BOT-060, buy_BOT-060, code_BOT-060, p_253)
                $cleanParam = preg_replace('/^(?:p|prod|product|buy|code|item)_/i', '', $payload);
                $product = null;

                // 5a. If numeric, check by primary key ID first
                if (ctype_digit($cleanParam)) {
                    $product = Product::find((int)$cleanParam);
                }

                // 5b. Check by Code (exact, uppercase, or raw payload)
                if (!$product) {
                    $product = Product::where('code', $cleanParam)
                        ->orWhere('code', strtoupper($cleanParam))
                        ->orWhere('code', $payload)
                        ->first();
                }

                // 5c. Check by Barcode or SKU
                if (!$product) {
                    $product = Product::where('barcode', $cleanParam)
                        ->orWhere('sku', $cleanParam)
                        ->orWhere('barcode', $payload)
                        ->orWhere('sku', $payload)
                        ->first();
                }

                if ($product) {
                    if ($product->is_bot_available) {
                        $this->sendProductPhotoCard($chatId, $product);
                        return;
                    } else {
                        $msg = "⚠️ *ဤကုန်ပစ္စည်း ({$product->name}) သည် လက်ရှိတွင် Bot တွင် မှာယူ၍ မရသေးပါရှင်။*\n\nဆိုင်ရှိ အခြားရရှိနိုင်သော ကုန်ပစ္စည်းများကို အောက်ပါခလုတ်မှတစ်ဆင့် လေ့လာကြည့်ရှုနိုင်ပါသည်-";
                        $this->sendMessage($chatId, $msg, [
                            'inline_keyboard' => [
                                [
                                    ['text' => '🛍️ ကုန်ပစ္စည်းများ ကြည့်ရှုမည်', 'callback_data' => 'nav_all_products'],
                                    ['text' => '🏠 မူလစာမျက်နှာ', 'callback_data' => 'nav_home'],
                                ]
                            ]
                        ]);
                        return;
                    }
                }

                // If user clicked an invalid or nonexistent product link
                $hasProductPrefix = (bool)preg_match('/^(?:p|prod|product|buy|code|item)_/i', $payload);
                if ($hasProductPrefix) {
                    $msg = "🔍 *ကုန်ပစ္စည်း ရှာမတွေ့ပါ*\n━━━━━━━━━━━━━━━━━━━━━\nသင်နှိပ်လိုက်သော ပစ္စည်းလင့်ခ်မှ ကုန်ပစ္စည်းကို ရှာမတွေ့ပါရှင်။ ဆိုင်ရှိ အခြားကုန်ပစ္စည်းများကို အောက်ပါခလုတ်မှတစ်ဆင့် ကြည့်ရှုနိုင်ပါသည်-";
                    $this->sendMessage($chatId, $msg, [
                        'inline_keyboard' => [
                            [
                                ['text' => '🛍️ ကုန်ပစ္စည်းများ ကြည့်ရှုမည်', 'callback_data' => 'nav_all_products'],
                                ['text' => '🏠 မူလစာမျက်နှာ', 'callback_data' => 'nav_home'],
                            ]
                        ]
                    ]);
                    return;
                }
            }

            $this->sendWelcomeMenu($chatId, $userName);
            return;
        }

        if (str_starts_with($text, '/help')) {
            $helpMsg = "ℹ️ *Telegram Bot အသုံးပြုပုံ လမ်းညွှန်*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n\n"
                     . "🛍️ */catalog* - ကုန်ပစ္စည်းများ ကြည့်ရှုရှာဖွေရန်\n"
                     . "🛒 */cart* - သင်၏ ခြင်းတောင်း ကြည့်ရှုရန်\n"
                     . "📦 */orders* - သင် မှာယူထားသော အော်ဒါများ ကြည့်ရန်\n"
                     . "💳 */payment* - ငွေလွှဲရန် အချက်အလက်များ ကြည့်ရန်\n"
                     . "📞 */contact* - ဆိုင်လိပ်စာနှင့် ဖုန်းနံပါတ်များ ကြည့်ရန်\n"
                     . "❌ */cancel* - လုပ်ဆောင်ချက် ပယ်ဖျက်ရန်\n\n"
                     . "မေးမြန်းလိုသည်များ ရှိပါက '📞 ဆိုင်နှင့် ဆက်သွယ်ရန်' မှတဆင့် ဆက်သွယ်နိုင်ပါသည်ရှင်။ 🙏";
            $this->sendMessage($chatId, $helpMsg, $this->getMainKeyboard($chatId));
            return;
        }

        $btnCatalog = trim((string)Setting::get('telegram_bot_btn_catalog', '🛍️ ကုန်ပစ္စည်းများ'));
        $btnCart = trim((string)Setting::get('telegram_bot_btn_cart', '🛒 ဝယ်မည့်ပစ္စည်းများ'));
        $btnOrders = trim((string)Setting::get('telegram_bot_btn_orders', '📦 တင်ထားသော Order များ'));
        $btnCheckout = trim((string)Setting::get('telegram_bot_btn_checkout', '📦 မှာမည့်ပစ္စည်းများ Order တင်မည်'));
        $btnCheckoutInline = trim((string)Setting::get('telegram_bot_cart_btn_checkout_inline', ''));
        $btnPayment = trim((string)Setting::get('telegram_bot_btn_payment', 'ℹ️ ငွေလွှဲရန် အချက်အလက်'));
        $btnContact = trim((string)Setting::get('telegram_bot_btn_contact', '📞 ဆိုင်နှင့် ဆက်သွယ်ရန်'));
        $btnCancel = trim((string)Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)'));

        $normalizeText = function (string $str): string {
            return preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', mb_strtolower($str));
        };

        $cleanText = $normalizeText($text);
        $cleanBtnCatalog = $normalizeText($btnCatalog);
        $cleanBtnCart = $normalizeText($btnCart);
        $cleanBtnOrders = $normalizeText($btnOrders);
        $cleanBtnCheckout = $normalizeText($btnCheckout);
        $cleanBtnCheckoutInline = $normalizeText($btnCheckoutInline);
        $cleanBtnPayment = $normalizeText($btnPayment);
        $cleanBtnContact = $normalizeText($btnContact);

        // 1. Catalog Command
        if (($cleanBtnCatalog !== '' && ($cleanText === $cleanBtnCatalog || str_starts_with($cleanText, $cleanBtnCatalog)))
            || ($btnCatalog !== '' && (str_starts_with($text, $btnCatalog) || $text === $btnCatalog))
            || (mb_strlen($cleanText) <= 20 && str_contains($cleanText, 'ကုန်ပစ္စည်း'))
            || $cleanText === 'ပစ္စည်းများ'
            || strcasecmp($text, '/catalog') === 0
            || strcasecmp($text, 'catalog') === 0) {
            if (Setting::get('telegram_bot_direct_categories', '0') === '1') {
                $this->sendCategoryFilterMenu($chatId);
            } else {
                $this->sendCatalogMenu($chatId);
            }
            return;
        }

        // 2. Checkout Command (Direct Order Placement Flow)
        // High priority: Evaluated BEFORE Cart Command so that "📦 မှာမည့်ပစ္စည်းများ Order တင်မည်" immediately begins checkout (asks for phone number & address) without showing the Cart Review list again.
        $isCheckoutTrigger = ($cleanBtnCheckout !== '' && ($cleanText === $cleanBtnCheckout || str_starts_with($cleanText, $cleanBtnCheckout)))
            || ($cleanBtnCheckoutInline !== '' && ($cleanText === $cleanBtnCheckoutInline || str_starts_with($cleanText, $cleanBtnCheckoutInline)))
            || ($btnCheckout !== '' && (str_starts_with($text, $btnCheckout) || $text === $btnCheckout))
            || ($btnCheckoutInline !== '' && (str_starts_with($text, $btnCheckoutInline) || $text === $btnCheckoutInline))
            || str_contains($cleanText, 'orderတင်')
            || str_contains($cleanText, 'အော်ဒါတင်')
            || strcasecmp($text, '/checkout') === 0
            || strcasecmp($text, 'checkout') === 0;

        if ($isCheckoutTrigger) {
            $this->initiateCartCheckout($chatId, $userName);
            return;
        }

        // 3. Cart View Command (Matches '🛒 မှာမည့်ပစ္စည်းများ', 'ဝယ်မည့်ပစ္စည်းများ', 'ခြင်းတောင်း', '/cart', etc.)
        $isCartCommand = ($cleanBtnCart !== '' && ($cleanText === $cleanBtnCart || str_starts_with($cleanText, $cleanBtnCart)))
            || ($btnCart !== '' && (str_starts_with($text, $btnCart) || $text === $btnCart))
            || str_contains($cleanText, 'မှာမည့်ပစ္စည်း')
            || str_contains($cleanText, 'ဝယ်မည့်ပစ္စည်း')
            || str_contains($cleanText, 'ခြင်းတောင်း')
            || str_contains($cleanText, 'ယူထားတဲ့စာရင်း')
            || strcasecmp($text, '/cart') === 0
            || strcasecmp($text, 'cart') === 0;

        if ($isCartCommand) {
            $this->sendCartView($chatId, $userName);
            return;
        }

        // 3. Customer Orders Command (Matches 'တင်ထားသော Order များ', 'အော်ဒါများ', etc.)
        if (($cleanBtnOrders !== '' && ($cleanText === $cleanBtnOrders || str_contains($cleanText, $cleanBtnOrders)))
            || ($btnOrders !== '' && (str_starts_with($text, $btnOrders) || $text === $btnOrders))
            || str_contains($cleanText, 'တင်ထားသော')
            || str_contains($cleanText, 'အော်ဒါများ')
            || str_contains($cleanText, 'orderများ')
            || strcasecmp($text, '/orders') === 0
            || strcasecmp($text, 'orders') === 0) {
            $this->sendCustomerOrders($chatId);
            return;
        }

        // 4. Payment Info Command (Matches 'ငွေလွှဲရန် အချက်အလက်', etc.)
        if (($cleanBtnPayment !== '' && ($cleanText === $cleanBtnPayment || str_contains($cleanText, $cleanBtnPayment)))
            || ($btnPayment !== '' && (str_starts_with($text, $btnPayment) || $text === $btnPayment))
            || str_contains($cleanText, 'ငွေလွှဲ')
            || strcasecmp($text, '/payment') === 0
            || strcasecmp($text, 'payment') === 0) {
            $this->sendPaymentInfo($chatId);
            return;
        }

        // 5. Contact Shop Command (Matches 'ဆိုင်နှင့် ဆက်သွယ်ရန်', etc.)
        if (($cleanBtnContact !== '' && ($cleanText === $cleanBtnContact || str_contains($cleanText, $cleanBtnContact)))
            || ($btnContact !== '' && (str_starts_with($text, $btnContact) || $text === $btnContact))
            || str_contains($cleanText, 'ဆက်သွယ်ရန်')
            || strcasecmp($text, '/contact') === 0
            || strcasecmp($text, 'contact') === 0) {
            $this->sendContactInfo($chatId);
            return;
        }

        // Sub-Category direct command e.g. /subcat 43 or /subcat_43 or /sc 43
        if (preg_match('/^\/(?:subcat|sub_cat|sub|scat|sc|subcategory|sub_category)[_\s]+(\d+)$/i', trim($text), $m)) {
            $subId = (int)$m[1];
            $subCategory = SubCategory::find($subId);
            if ($subCategory) {
                $parentName = $subCategory->parent_category_name ?: ($subCategory->parentCategory?->name ?? '');
                $header = !empty($parentName)
                    ? "📂 *{$parentName}* › 🔹 *{$subCategory->name}* ကုန်ပစ္စည်းများ"
                    : "🔹 *{$subCategory->name} ကုန်ပစ္စည်းများ*";
                $this->sendCatalog($chatId, page: 1, subCategoryId: $subId, headerTitle: $header);
                return;
            } else {
                $this->sendMessage($chatId, "⚠️ အမျိုးအစားခွဲ (Sub-Category ID: `{$subId}`) ရှာမတွေ့ပါရှင်။", [
                    'inline_keyboard' => [
                        [['text' => '🛍️ ကုန်ပစ္စည်းများ ကြည့်ရှုမည်', 'callback_data' => 'nav_all_products']]
                    ]
                ]);
                return;
            }
        }

        $isCancelText = ($btnCancel !== '' && $text === $btnCancel)
            || $text === '❌ မမှာတော့ပါ (Cancel)'
            || str_starts_with($text, '/cancel')
            || strcasecmp($text, 'cancel') === 0
            || $text === 'မမှာတော့ပါ'
            || str_contains($text, 'မမှာတော့');

        if ($isCancelText) {
            Cache::forget("tg_session_{$chatId}");
            $cancelMsg = $this->formatCancelledMessage($userName);
            $this->sendMessage($chatId, $cancelMsg, $this->getMainKeyboard($chatId));
            return;
        }

        // 4. Session state machine for conversational checkout or product search
        $session = Cache::get("tg_session_{$chatId}");
        if ($session && !empty($session['step'])) {
            $this->handleSessionInput($chatId, $text, $session, $userName, $usernameTag);
            return;
        }

        // 5. Direct Sub-Category / Product / Variant Search
        if (!empty($text)) {
            // Direct match on Sub-Category (e.g. User types subcat 43, subcat_43, or sc 43)
            if (preg_match('/^(?:subcat|sub_cat|sub|scat|sc|subcategory|sub_category)[_\s:]*(\d+)$/i', trim($text), $sm)) {
                $subId = (int)$sm[1];
                $subCategory = SubCategory::find($subId);
                if ($subCategory) {
                    $parentName = $subCategory->parent_category_name ?: ($subCategory->parentCategory?->name ?? '');
                    $header = !empty($parentName)
                        ? "📂 *{$parentName}* › 🔹 *{$subCategory->name}* ကုန်ပစ္စည်းများ"
                        : "🔹 *{$subCategory->name} ကုန်ပစ္စည်းများ*";
                    $this->sendCatalog($chatId, page: 1, subCategoryId: $subId, headerTitle: $header);
                    return;
                }
            }

            $directProduct = Product::where('is_bot_available', true)
                ->where(function ($q) use ($text) {
                    $q->where('code', $text)
                      ->orWhere('sku', $text)
                      ->orWhere('id', is_numeric($text) ? (int)$text : -1);
                })
                ->first();

            if ($directProduct) {
                if ($directProduct->has_variants && $directProduct->variants()->where('is_active', true)->exists()) {
                    $this->sendVariantSelection($chatId, $directProduct);
                } else {
                    $this->sendDirectProductPrompt($chatId, $directProduct);
                }
                return;
            }

            // Direct match on Product Variant SKU or Barcode
            $directVariant = ProductVariant::where('sku', $text)
                ->orWhere('barcode', $text)
                ->first();

            if ($directVariant && $directVariant->product && $directVariant->product->is_bot_available) {
                $this->initiateQuantitySelection($chatId, $directVariant->product, $directVariant);
                return;
            }

            // C. Intelligent Full-Text / Fuzzy Match by Name, Category, Sub-Category, or Brand
            if (mb_strlen($text) >= 2 && !str_starts_with($text, '/')) {
                $matches = Product::where('is_bot_available', true)
                    ->where(function ($q) {
                        $q->where('is_active', true)->orWhere('status', 'Active');
                    })
                    ->where(function ($q) use ($text) {
                        $q->where('name', 'like', "%{$text}%")
                          ->orWhere('code', 'like', "%{$text}%")
                          ->orWhere('sku', 'like', "%{$text}%")
                          ->orWhere('sub_category_name', 'like', "%{$text}%")
                          ->orWhere('sub_category_id', is_numeric($text) ? (int)$text : -1)
                          ->orWhereHas('category', fn($cq) => $cq->where('name', 'like', "%{$text}%"))
                          ->orWhereHas('subCategory', fn($sq) => $sq->where('name', 'like', "%{$text}%"))
                          ->orWhereHas('brand', fn($bq) => $bq->where('name', 'like', "%{$text}%"));
                    })
                    ->take(5)
                    ->get();

                if ($matches->count() === 1) {
                    $single = $matches->first();
                    if ($single->has_variants && $single->variants()->where('is_active', true)->exists()) {
                        $this->sendVariantSelection($chatId, $single);
                    } else {
                        $this->sendProductPhotoCard($chatId, $single, 'catalog_main');
                    }
                    return;
                } elseif ($matches->count() > 1) {
                    $this->sendCatalog($chatId, searchQuery: $text, headerTitle: "🔎 *'{$text}' အတွက် ရှာဖွေတွေ့ရှိသော ကုန်ပစ္စည်းများ*");
                    return;
                }
            }
        }

        // 🤖 Smart AI Sales Agent Interception (Natural Language / Product & Policy Inquiries)
        $operatingMode = $this->aiAgentService->getOperatingMode();
        if ($operatingMode !== 'standard' && !empty($text) && $this->aiAgentService->isEnabled() && !$this->aiAgentService->isAiPausedForChat($chatId)) {
            $this->sendChatAction($chatId, 'typing');
            $aiRes = $this->handleCustomerQuery($chatId, $userName, $text, $usernameTag);
            if ($aiRes && !empty($aiRes['reply'])) {
                return;
            }
        }

        // If AI is paused due to Human Staff Takeover
        if ($this->aiAgentService->isEnabled() && $this->aiAgentService->isAiPausedForChat($chatId)) {
            $this->notifyAdminCustomerNeedsHelp($chatId, $userName, $usernameTag, $text);
            $this->sendMessage($chatId, "👨‍💼 *ဆိုင်တာဝန်ခံ/ဝန်ထမ်းထံသို့ စာပေးပို့ထားပါသည်ရှင်။* ခေတ္တစောင့်ဆိုင်းပေးပါခင်ဗျာ။\n\n💡 _AI လက်ထောက်နှင့် ချက်ချင်း စကားဆက်ပြောလိုပါက အောက်ပါခလုတ်ကို နှိပ်ပါ သို့မဟုတ်_ /ai _ဟု ရိုက်ပို့ပါရှင်။_", [
                'inline_keyboard' => [
                    [
                        ['text' => '🤖 AI ဖြင့် ဆက်ပြောမည်', 'callback_data' => 'resume_ai_mode'],
                        ['text' => '🏠 ပင်မ မီနူး', 'callback_data' => 'catalog_main'],
                    ]
                ]
            ]);
            return;
        }

        // If AI is enabled but was temporarily unable to generate a response (e.g. LLM timeout or temporary rate limit):
        if ($this->aiAgentService->isEnabled()) {
            $fallbackMsg = "မင်္ဂလာပါရှင် 🙏 လူကြီးမင်း မေးမြန်းထားသော ကုန်ပစ္စည်းများနှင့် အချက်အလက်များကို အောက်ပါ ခလုတ်များမှတဆင့် အလွယ်တကူ ရွေးချယ်ကြည့်ရှုနိုင်ပါသည်ရှင်။\n\n💬 ဆိုင်ဝန်ထမ်းနှင့် တိုက်ရိုက်ပြောဆိုလိုပါက /human ဟု ရိုက်ပို့နိုင်ပါသည်ရှင်။";
            $this->sendMessage($chatId, $fallbackMsg, [
                'inline_keyboard' => [
                    [
                        ['text' => '🛍️ ကုန်ပစ္စည်းများ အားလုံးကြည့်မည်', 'callback_data' => 'nav_all_products'],
                        ['text' => '📂 အမျိုးအစားအလိုက်', 'callback_data' => 'nav_categories'],
                    ],
                    [
                        ['text' => '👨‍💼 ဆိုင်ဝန်ထမ်းနှင့် စကားပြောမည်', 'callback_data' => 'ask_human'],
                        ['text' => '🛒 ခြင်းတောင်း ကြည့်မည်', 'callback_data' => 'cart_view'],
                    ]
                ]
            ]);
            return;
        }

        // Default greeting / welcome menu (only when AI is disabled)
        $this->sendWelcomeMenu($chatId, $userName);
    }

    /**
     * 🧠 Main Entry: Process Customer Natural Language Query with AI Agent
     * Executes Conversational Autonomous Commerce (Auto Add to Cart & Auto Order Finalize)
     */
    public function handleCustomerQuery(
        int|string $chatId,
        string $userName,
        string $text,
        ?string $usernameTag = ''
    ): ?array {
        $aiRes = $this->aiAgentService->handleCustomerQuery($chatId, $userName, $text, $usernameTag);

        if (!$aiRes || empty($aiRes['reply'])) {
            return null;
        }

        $this->executeAiAutonomousActions($chatId, $userName, $text, $usernameTag ?: '', $aiRes);

        return $aiRes;
    }

    /**
     * 🛒 Auto-Recover Cart from Chat History if customer is checking out but cart is empty
     */
    public function recoverCartFromChatHistory(int|string $chatId): array
    {
        $cart = $this->getCart($chatId);
        if (!empty($cart)) {
            return $cart;
        }

        $recentMessages = BotChatMessage::where('telegram_chat_id', (string)$chatId)
            ->latest('id')
            ->take(10)
            ->get();

        $candidateProductId = null;
        $candidateVariantId = null;

        // 1. First, search recent message texts for specific bot-available products (newest messages first)
        $botProducts = Product::with(['variants' => fn($vq) => $vq->where('is_active', true)])
            ->where('is_bot_available', true)
            ->where(fn($q) => $q->where('is_active', true)->orWhere('status', 'Active'))
            ->get();

        foreach ($recentMessages as $msg) {
            $txt = mb_strtolower($msg->message_text);
            foreach ($botProducts as $prod) {
                $pName = mb_strtolower($prod->name);
                $words = array_filter(explode(' ', $pName), fn($w) => mb_strlen($w) >= 4);
                $matchCount = 0;
                foreach ($words as $w) {
                    if (str_contains($txt, $w)) {
                        $matchCount++;
                    }
                }
                if ($matchCount >= 2 || (count($words) === 1 && $matchCount === 1)) {
                    $candidateProductId = $prod->id;
                    if ($prod->has_variants && $prod->variants->isNotEmpty()) {
                        foreach ($prod->variants as $var) {
                            $vName = mb_strtolower($var->name);
                            if (str_contains($txt, 'အသားလတ်') && (str_contains($vName, 'အသားလတ်') || str_contains($vName, 'no.2') || str_contains($vName, 'beige'))) {
                                $candidateVariantId = $var->id;
                                break;
                            }
                            if (str_contains($txt, 'အသားဖြူ') && (str_contains($vName, 'အသားဖြူ') || str_contains($vName, 'no.1') || str_contains($vName, 'light'))) {
                                $candidateVariantId = $var->id;
                                break;
                            }
                        }
                        if (!$candidateVariantId) {
                            $candidateVariantId = $prod->variants->first()->id;
                        }
                    }
                    break 2;
                }
            }
        }

        // 2. If not found in text, look at message metadata
        if (!$candidateProductId) {
            foreach ($recentMessages as $msg) {
                $meta = is_array($msg->metadata) ? $msg->metadata : (json_decode($msg->metadata ?? '[]', true) ?: []);
                if (!empty($meta['cart_action']['items'])) {
                    foreach ($meta['cart_action']['items'] as $it) {
                        if (!empty($it['product_id'])) {
                            $candidateProductId = (int)$it['product_id'];
                            $candidateVariantId = !empty($it['variant_id']) ? (int)$it['variant_id'] : null;
                            break 2;
                        }
                    }
                }
                if (!empty($meta['recommended_product_ids'])) {
                    $candidateProductId = (int)$meta['recommended_product_ids'][0];
                    break;
                }
            }
        }

        if ($candidateProductId) {
            $product = Product::find($candidateProductId);
            if ($product && $product->is_bot_available) {
                $variant = $candidateVariantId ? ProductVariant::find($candidateVariantId) : ($product->has_variants ? $product->variants()->where('is_active', true)->first() : null);
                $this->addToCart($chatId, $product, $variant, 1);
                Log::info("Cart auto-recovered for chat {$chatId}: Product {$product->name} (Variant: " . ($variant?->name ?? 'none') . ")");
                return $this->getCart($chatId);
            }
        }

        return [];
    }

    /**
     * 🔎 Detect Customer Purchase Intent & Product in text (Deterministic Safety Net)
     */
    public function detectBuyIntentAndProduct(string $text, int|string $chatId): ?array
    {
        $lowerText = mb_strtolower($text);
        $buyKeywords = [
            'ယူမယ်', 'ဝယ်မယ်', 'မှာမယ်', 'ဒါယူ', 'ဒါဝယ်', 'ဒါလေးယူ', 'ဒါလေးဝယ်',
            'အဲ့တာယူ', 'အဲ့ဒါယူ', 'အဲ့တာလေးယူ', 'အဲ့ဒါလေးယူ', 'အဲ့တာလေးဝယ်', 'အဲ့တာလေးယူမယ်နော်',
            'ခြင်းတောင်းထဲထည့်', 'ခြင်းတောင်းထဲ', 'ဝယ်ချင်', 'မှာချင်', 'ယူချင်',
            'buy', 'order', 'purchase', 'add to cart'
        ];

        $hasBuyIntent = false;
        foreach ($buyKeywords as $kw) {
            if (str_contains($lowerText, $kw)) {
                $hasBuyIntent = true;
                break;
            }
        }

        if (!$hasBuyIntent) {
            return null;
        }

        $products = Product::with(['variants' => fn($vq) => $vq->where('is_active', true)])
            ->where('is_bot_available', true)
            ->where(fn($q) => $q->where('is_active', true)->orWhere('status', 'Active'))
            ->get();

        $matchedProduct = null;
        $matchedVariant = null;

        foreach ($products as $p) {
            $pName = mb_strtolower($p->name);
            $pCode = mb_strtolower((string)$p->code);
            $pSku = mb_strtolower((string)$p->sku);

            if (($pCode && str_contains($lowerText, $pCode)) || ($pSku && str_contains($lowerText, $pSku))) {
                $matchedProduct = $p;
                break;
            }

            $words = array_filter(explode(' ', $pName), fn($w) => mb_strlen($w) >= 4);
            $matchCount = 0;
            foreach ($words as $w) {
                if (str_contains($lowerText, $w)) {
                    $matchCount++;
                }
            }
            if ($matchCount >= 2 || (count($words) === 1 && $matchCount === 1)) {
                $matchedProduct = $p;
                break;
            }
        }

        // If product not explicitly named, check recent recommendations
        if (!$matchedProduct) {
            $recent = BotChatMessage::where('telegram_chat_id', (string)$chatId)
                ->latest('id')
                ->take(6)
                ->get();

            foreach ($recent as $msg) {
                $meta = is_array($msg->metadata) ? $msg->metadata : (json_decode($msg->metadata ?? '[]', true) ?: []);
                if (!empty($meta['recommended_product_ids'])) {
                    $matchedProduct = Product::find($meta['recommended_product_ids'][0]);
                    if ($matchedProduct) break;
                }
            }
        }

        if ($matchedProduct) {
            if ($matchedProduct->has_variants && $matchedProduct->variants->isNotEmpty()) {
                foreach ($matchedProduct->variants as $v) {
                    $vName = mb_strtolower($v->name);
                    $vSku = mb_strtolower((string)$v->sku);
                    if ($vSku && str_contains($lowerText, $vSku)) {
                        $matchedVariant = $v;
                        break;
                    }
                    if (str_contains($lowerText, 'အသားလတ်') && (str_contains($vName, 'အသားလတ်') || str_contains($vName, 'no.2') || str_contains($vName, 'natural') || str_contains($vName, 'beige'))) {
                        $matchedVariant = $v;
                        break;
                    }
                    if (str_contains($lowerText, 'အသားဖြူ') && (str_contains($vName, 'အသားဖြူ') || str_contains($vName, 'no.1') || str_contains($vName, 'light') || str_contains($vName, 'fair'))) {
                        $matchedVariant = $v;
                        break;
                    }
                    if (str_contains($lowerText, mb_strtolower($v->name))) {
                        $matchedVariant = $v;
                        break;
                    }
                }
                if (!$matchedVariant) {
                    $matchedVariant = $matchedProduct->variants->first();
                }
            }

            $qty = 1;
            if (preg_match('/([0-9၀-၉]+)\s*(?:ခု|ဘူး|ထည်|လုံး|တွဲ|pack|pcs)/u', $lowerText, $qMatch)) {
                $bDigits = ['၀','၁','၂','၃','၄','၅','၆','၇','၈','၉'];
                $eDigits = ['0','1','2','3','4','5','6','7','8','9'];
                $cleanQ = str_replace($bDigits, $eDigits, $qMatch[1]);
                $qty = max(1, (int)$cleanQ);
            }

            return [
                'product' => $matchedProduct,
                'variant' => $matchedVariant,
                'quantity' => $qty,
            ];
        }

        return null;
    }

    /**
     * 📞 Extract Phone & Township from text if customer provided delivery info
     */
    public function detectDeliveryInfoFromText(string $text): array
    {
        $bDigits = ['၀','၁','၂','၃','၄','၅','၆','၇','၈','၉'];
        $eDigits = ['0','1','2','3','4','5','6','7','8','9'];
        $cleanText = str_replace($bDigits, $eDigits, $text);

        $phone = null;
        if (preg_match('/(?:09|\+?959)[\d\s\-]{7,11}/', $cleanText, $pm)) {
            $phone = preg_replace('/\D/', '', $pm[0]);
        }

        $township = null;
        $activeTownships = DeliveryTownship::where('is_active', true)->get(['id', 'name', 'name_mm', 'code']);
        foreach ($activeTownships as $t) {
            if ($t->name_mm && str_contains($text, $t->name_mm)) {
                $township = $t->name_mm;
                break;
            }
            if ($t->name && str_contains(mb_strtolower($text), mb_strtolower($t->name))) {
                $township = $t->name;
                break;
            }
        }

        return [
            'phone' => $phone,
            'township' => $township,
        ];
    }

    /**
     * ⚡ Execute Structured AI Actions:
     * - Auto Add to Cart / Remove / Clear
     * - Auto POS Order Finalization
     * - Smart Hybrid conversational flow with product cards
     */
    public function executeAiAutonomousActions(
        int|string $chatId,
        string $userName,
        string $text,
        string $usernameTag,
        array $aiRes
    ): array {
        $operatingMode = $this->aiAgentService->getOperatingMode();
        $cartAction = $aiRes['cart_action'] ?? ['type' => 'none', 'items' => []];
        $orderAction = $aiRes['order_action'] ?? ['type' => 'none'];

        $cartActionType = $cartAction['type'] ?? 'none';
        $orderActionType = $orderAction['type'] ?? 'none';

        $cartUpdated = false;
        $orderCreated = false;

        // 🛡️ Deterministic Safety Net 1: Fallback Cart Auto-Detection
        if ($cartActionType !== 'add_to_cart' || empty($cartAction['items'])) {
            $detectedBuy = $this->detectBuyIntentAndProduct($text, $chatId);
            if ($detectedBuy) {
                $p = $detectedBuy['product'];
                $v = $detectedBuy['variant'];
                $q = $detectedBuy['quantity'];
                $this->addToCart($chatId, $p, $v, $q);
                $cartUpdated = true;
                $cartAction = [
                    'type' => 'add_to_cart',
                    'items' => [
                        [
                            'product_id' => $p->id,
                            'variant_id' => $v?->id,
                            'quantity' => $q,
                        ]
                    ]
                ];
                $cartActionType = 'add_to_cart';
                Log::info("Deterministic Cart Added: Product {$p->name} (Variant: " . ($v?->name ?? 'none') . ") for chat {$chatId}");
            }
        }

        // 1. Process cart_action
        if ($cartActionType === 'add_to_cart' && !empty($cartAction['items']) && !$cartUpdated) {
            $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
            $allowOutOfStock = Setting::get('telegram_bot_allow_out_of_stock_orders', '0') === '1';

            foreach ($cartAction['items'] as $it) {
                $product = Product::find($it['product_id'] ?? 0);
                if (!$product || !$product->is_bot_available) continue;

                $variant = !empty($it['variant_id']) ? ProductVariant::find($it['variant_id']) : null;
                $qty = max(1, (float)($it['quantity'] ?? 1));

                if (!$allowOutOfStock) {
                    $stockQuery = StockBalance::where('warehouse_id', $warehouseId)->where('product_id', $product->id);
                    if ($variant) {
                        $stockQuery->where('product_variant_id', $variant->id);
                    }
                    $availStock = (float)$stockQuery->sum('quantity');
                    if ($availStock < $qty && $availStock <= 0) {
                        continue;
                    }
                }

                $this->addToCart($chatId, $product, $variant, $qty);
                $cartUpdated = true;
            }
        } elseif ($cartActionType === 'remove_from_cart') {
            if (!empty($cartAction['items'])) {
                foreach ($cartAction['items'] as $it) {
                    $pId = (int)($it['product_id'] ?? 0);
                    $vId = !empty($it['variant_id']) ? (int)$it['variant_id'] : null;
                    $itemKey = $vId ? "p_{$pId}_v_{$vId}" : "p_{$pId}";
                    $this->removeFromCart($chatId, $itemKey);
                    $cartUpdated = true;
                }
            }
        } elseif ($cartActionType === 'clear_cart') {
            $this->clearCart($chatId);
            $cartUpdated = true;
        }

        // 🛡️ Deterministic Safety Net 2: Fallback Delivery & Phone Auto-Detection
        $deliveryInfo = $this->detectDeliveryInfoFromText($text);
        if ($orderActionType !== 'create_order' && !empty($deliveryInfo['phone'])) {
            $orderActionType = 'create_order';
            $orderAction['type'] = 'create_order';
            $orderAction['phone'] = $deliveryInfo['phone'];
            if (!empty($deliveryInfo['township'])) {
                $orderAction['township_name'] = $deliveryInfo['township'];
            }
            $orderAction['address'] = $text;
        }

        // 2. Process order_action (Autonomous POS Order Finalization)
        if ($orderActionType === 'create_order') {
            $cart = $this->getCart($chatId);

            // If cart is empty but cart action had items, populate cart first
            if (empty($cart) && !empty($cartAction['items'])) {
                foreach ($cartAction['items'] as $it) {
                    $product = Product::find($it['product_id'] ?? 0);
                    if (!$product || !$product->is_bot_available) continue;
                    $variant = !empty($it['variant_id']) ? ProductVariant::find($it['variant_id']) : null;
                    $qty = max(1, (float)($it['quantity'] ?? 1));
                    $this->addToCart($chatId, $product, $variant, $qty);
                }
                $cart = $this->getCart($chatId);
            }

            // 🛡️ Deterministic Safety Net 3: Auto-Recover Cart from Chat History!
            if (empty($cart)) {
                $cart = $this->recoverCartFromChatHistory($chatId);
            }

            // Fallback: If cart is empty but recommended products exist and user wanted to buy
            if (empty($cart) && !empty($aiRes['recommended_product_ids'])) {
                $firstRecId = (int)($aiRes['recommended_product_ids'][0] ?? 0);
                $product = Product::find($firstRecId);
                if ($product && $product->is_bot_available) {
                    $variant = $product->has_variants ? $product->variants()->where('is_active', true)->first() : null;
                    $this->addToCart($chatId, $product, $variant, 1);
                    $cart = $this->getCart($chatId);
                }
            }

            // Phone normalization and validation
            $rawPhone = (string)($orderAction['phone'] ?? $deliveryInfo['phone'] ?? '');
            $burmeseDigits = ['၀','၁','၂','၃','၄','၅','၆','၇','၈','၉'];
            $englishDigits = ['0','1','2','3','4','5','6','7','8','9'];
            $cleanPhone = str_replace($burmeseDigits, $englishDigits, $rawPhone);
            $normalizedPhone = $this->normalizePhoneNumber($cleanPhone);
            $digitsOnly = preg_replace('/\D/', '', $normalizedPhone);
            $isValidPhone = strlen($digitsOnly) >= 8;

            if (!empty($cart) && $isValidPhone) {
                $townshipInput = trim((string)($orderAction['township_name'] ?? $deliveryInfo['township'] ?? ''));
                $deliveryType = $orderAction['delivery_type'] ?? 'city';

                $cityFee = (float)Setting::get('telegram_bot_delivery_city_fee', 2500);
                $expressFee = (float)Setting::get('telegram_bot_delivery_express_fee', 3000);
                $pickupFee = (float)Setting::get('telegram_bot_delivery_store_pickup_fee', 0);

                $shippingFee = $cityFee;
                $townshipId = null;
                $regionId = null;
                $resolvedTownshipDisplay = !empty($townshipInput) ? $townshipInput : 'City Delivery';
                $deliveryMethod = "City Delivery ({$resolvedTownshipDisplay})";

                if ($deliveryType === 'store_pickup' || str_contains($townshipInput, 'ဆိုင်လာယူ') || str_contains($townshipInput, 'pickup')) {
                    $shippingFee = $pickupFee;
                    $resolvedTownshipDisplay = 'ဆိုင်တွင် လာယူမည် (Store Pickup)';
                    $deliveryMethod = 'Store Pickup (ဆိုင်လာယူ)';
                } elseif ($deliveryType === 'express') {
                    $shippingFee = $expressFee;
                    $resolvedTownshipDisplay = !empty($townshipInput) ? $townshipInput : 'Express / Highway Bus';
                    $deliveryMethod = "Express Delivery (ကားဂိတ်/အမြန်ချော) - {$resolvedTownshipDisplay}";
                } else {
                    $matchedTownship = null;
                    if (!empty($townshipInput)) {
                        $matchedTownship = DeliveryTownship::where('is_active', true)
                            ->where(function ($q) use ($townshipInput) {
                                $q->where('name', 'like', "%{$townshipInput}%")
                                  ->orWhere('name_mm', 'like', "%{$townshipInput}%")
                                  ->orWhere('code', 'like', "%{$townshipInput}%");
                            })
                            ->first();
                    }

                    if ($matchedTownship) {
                        $townshipId = $matchedTownship->id;
                        $regionId = $matchedTownship->delivery_region_id;
                        $shippingFee = (float)$matchedTownship->delivery_fee;
                        $itemsTotal = (float)collect($cart)->sum('subtotal');
                        if ($matchedTownship->free_delivery_threshold && $itemsTotal >= (float)$matchedTownship->free_delivery_threshold) {
                            $shippingFee = 0.0;
                        }
                        $resolvedTownshipDisplay = $matchedTownship->name_mm ?: $matchedTownship->name;
                        $deliveryMethod = "City Delivery ({$resolvedTownshipDisplay})";
                    }
                }

                $cartSummary = $this->getCartSummary($chatId);
                $custName = !empty($orderAction['customer_name']) ? $orderAction['customer_name'] : ($userName ?: 'Telegram Customer');
                $rawAddr = !empty($orderAction['address']) ? $orderAction['address'] : $resolvedTownshipDisplay;
                $paymentMethod = !empty($orderAction['payment_method']) ? $orderAction['payment_method'] : 'Cash on Delivery';

                $session = [
                    'is_cart' => true,
                    'items' => array_values($cart),
                    'grand_total' => $cartSummary['grand_total'],
                    'total_qty' => $cartSummary['total_qty'],
                    'customer_name' => $custName,
                    'phone' => $normalizedPhone,
                    'delivery_method' => $deliveryMethod,
                    'delivery_township' => $resolvedTownshipDisplay,
                    'delivery_township_id' => $townshipId,
                    'delivery_region_id' => $regionId,
                    'shipping_fee' => $shippingFee,
                    'address' => $rawAddr,
                    'payment_type' => $paymentMethod,
                    'payment_status' => 'Unpaid',
                    'step' => 'completed',
                    'ai_autonomous_order' => true,
                ];

                // 1. Send conversational AI reply message first
                $this->sendMessage($chatId, $aiRes['reply']);

                // 2. Finalize order (creates POS Sale, clears cart, prints voucher in chat)
                $this->finalizeOrder($chatId, $session, $custName, $usernameTag);
                $orderCreated = true;
            }
        }

        // 🔒 Anti-Hallucination Gatekeeper (Accounting & Business Integrity Guardrail)
        if (!$orderCreated) {
            $rawReply = $aiRes['reply'] ?? '';
            $hallucinationPatterns = [
                'အောင်မြင်စွာ တင်ပေးလိုက်ပါပြီ',
                'အော်ဒါလေးကို အောင်မြင်စွာ တင်ပေး',
                'အော်ဒါလေးကို တင်ပေးလိုက်ပါပြီ',
                'အော်ဒါတင်ပေးလိုက်ပါပြီ',
                'မှာယူမှု အောင်မြင်',
                'အော်ဒါလေး တင်ပေးပြီး',
                'order has been placed',
                'order confirmed'
            ];
            $isHallucinating = false;
            foreach ($hallucinationPatterns as $hp) {
                if (str_contains($rawReply, $hp)) {
                    $isHallucinating = true;
                    break;
                }
            }

            if ($isHallucinating) {
                $cleanPhone = $deliveryInfo['phone'] ?? null;
                if ($cleanPhone) {
                    $aiRes['reply'] = "မင်္ဂလာပါရှင် 🙏 လူကြီးမင်း ပေးပို့ထားသော လိပ်စာနှင့် ဖုန်းနံပါတ် ({$cleanPhone}) ကို လက်ခံရရှိထားပါသည်ရှင်။\n\nမှာယူလိုသည့် ကုန်ပစ္စည်းကို အတည်ပြုရန် လိုအပ်နေပါသဖြင့် မှာယူလိုသော ပစ္စည်းအမည် (သို့မဟုတ်) အရောင်/ဆိုဒ် လေးကို ပြောပြပေးပါဦးရှင့်။ စုစုက POS စနစ်တွင် အော်ဒါ ချက်ချင်း တင်ပေးပါမည်ရှင်။";
                } else {
                    $aiRes['reply'] = "ဟုတ်ကဲ့ပါရှင် 🙏 အော်ဒါ အောင်မြင်စွာ တင်နိုင်ရန်အတွက် ပို့ဆောင်ရမည့် မြို့နယ်၊ လိပ်စာ နှင့် ဖုန်းနံပါတ်လေးကို ပြောပြပေးပါဦးရှင့်။";
                }
            }
        }

        // 3. Log AI reply in database
        BotChatMessage::create([
            'telegram_chat_id' => (string)$chatId,
            'customer_name' => $userName,
            'telegram_username' => $usernameTag,
            'sender_type' => 'ai',
            'message_type' => 'text',
            'message_text' => $aiRes['reply'],
            'metadata' => [
                'recommended_product_ids' => $aiRes['recommended_product_ids'] ?? [],
                'suggested_action' => $aiRes['suggested_action'] ?? 'none',
                'cart_action' => $cartAction,
                'order_action' => $orderAction,
                'order_created' => $orderCreated,
            ],
            'is_read' => true,
            'needs_human' => !empty($aiRes['needs_human']),
        ]);

        // If order was already finalized above, voucher & reply already sent!
        if ($orderCreated) {
            if (!empty($aiRes['needs_human'])) {
                $this->notifyAdminCustomerNeedsHelp($chatId, $userName, $usernameTag, $text);
            }
            return $aiRes;
        }

        // 4. Send AI dialogue response (if not already sent during order creation)
        $this->sendMessage($chatId, $aiRes['reply']);

        // 5. Handle Interactive Buttons & Product Cards in Hybrid Mode
        $recIds = (array)($aiRes['recommended_product_ids'] ?? []);
        if (empty($recIds)) {
            $lastProdId = Cache::get("tg_last_visual_product_{$chatId}");
            $lowerText = mb_strtolower($text);
            $isBuyIntent = str_contains($lowerText, 'ဝယ်') 
                || str_contains($lowerText, 'မှာ') 
                || str_contains($lowerText, 'ယူ') 
                || str_contains($lowerText, 'buy') 
                || str_contains($lowerText, 'order');
            if ($lastProdId && $isBuyIntent) {
                $recIds = [(int)$lastProdId];
            }
        }

        $recProducts = collect();
        if (!empty($recIds)) {
            $recProducts = Product::whereIn('id', $recIds)
                ->where('is_bot_available', true)
                ->where(function ($q) {
                    $q->whereNull('is_active')->orWhere('is_active', true)->orWhere('status', 'Active');
                })
                ->take(3)
                ->get();
        }

        if ($recProducts->isNotEmpty()) {
            $cIdx = 1;
            foreach ($recProducts as $rp) {
                $this->sendProductCardItem($chatId, $rp, $cIdx++);
            }
        }

        $tmaUrl = Setting::get('telegram_bot_tma_url', '');
        $tmaEnabled = Setting::get('telegram_bot_tma_enabled', '1') === '1';
        $navRow = [];
        if ($tmaEnabled && !empty($tmaUrl)) {
            $navRow[] = ['text' => '🛍️ Shop Now', 'web_app' => ['url' => $tmaUrl]];
        }
        $navRow[] = ['text' => '🏠 ပင်မ မီနူး', 'callback_data' => 'catalog_main'];
        $navRow[] = ['text' => '👨‍💼 ဝန်ထမ်းနှင့် စကားပြောမည်', 'callback_data' => 'ask_human'];

        $this->sendMessage($chatId, "👇 _အထက်ပါ ကုန်ပစ္စည်းများမှ မှာယူလိုပါက ခလုတ်ကို နှိပ်နိုင်ပါသည်ရှင်_", [
            'inline_keyboard' => [$navRow]
        ]);

        if (!empty($aiRes['needs_human'])) {
            $this->notifyAdminCustomerNeedsHelp($chatId, $userName, $usernameTag, $text);
        }

        return $aiRes;
    }

    /**
     * Replace template placeholders with real data values, supporting all aliases.
     */
    public function replacePlaceholders(string $template, array $data): string
    {
        if (empty(trim($template))) {
            return '';
        }

        $shopName = Setting::get('company_name', 'အလင်းသစ် (Ah Linn Thit)');
        $shopPhone = Setting::get('company_phone', '09123456789');
        $shopAddress = Setting::get('company_address', 'ရန်ကုန်');

        $map = [
            // Order Reference / ID Aliases
            '{order_ref}' => $data['order_ref'] ?? $data['reference_no'] ?? $data['order_id'] ?? '',
            '{order_id}' => $data['order_id'] ?? $data['order_ref'] ?? $data['reference_no'] ?? '',
            '{reference_no}' => $data['reference_no'] ?? $data['order_ref'] ?? $data['order_id'] ?? '',
            '{voucher_no}' => $data['voucher_no'] ?? $data['order_ref'] ?? $data['reference_no'] ?? '',

            // Customer Name Aliases
            '{customer_name}' => $data['customer_name'] ?? $data['user_name'] ?? 'ဖောက်သည်',
            '{user_name}' => $data['user_name'] ?? $data['customer_name'] ?? 'ဖောက်သည်',
            '{name}' => $data['name'] ?? $data['customer_name'] ?? 'ဖောက်သည်',

            // Amount / Total Aliases
            '{amount}' => $data['amount'] ?? $data['grand_total'] ?? $data['total'] ?? '',
            '{grand_total}' => $data['grand_total'] ?? $data['amount'] ?? $data['total'] ?? '',
            '{total}' => $data['total'] ?? $data['amount'] ?? $data['grand_total'] ?? '',
            '{price}' => $data['price'] ?? $data['amount'] ?? '',

            // Paid & Due
            '{paid_amount}' => $data['paid_amount'] ?? $data['paid'] ?? '',
            '{paid}' => $data['paid'] ?? $data['paid_amount'] ?? '',
            '{due_amount}' => $data['due_amount'] ?? $data['due'] ?? '',
            '{due}' => $data['due'] ?? $data['due_amount'] ?? '',

            // Product & Qty
            '{product_name}' => $data['product_name'] ?? '',
            '{product}' => $data['product_name'] ?? '',
            '{variant_name}' => $data['variant_name'] ?? '',
            '{product_code}' => $data['product_code'] ?? '',
            '{quantity}' => (string)($data['quantity'] ?? $data['qty'] ?? ''),
            '{qty}' => (string)($data['qty'] ?? $data['quantity'] ?? ''),
            '{stock}' => (string)($data['stock'] ?? ''),
            '{items}' => (string)($data['items'] ?? ''),

            // Payment Details
            '{payment_name}' => $data['payment_name'] ?? $data['payment_type'] ?? '',
            '{payment_type}' => $data['payment_type'] ?? $data['payment_name'] ?? '',
            '{payment_method}' => $data['payment_method'] ?? $data['payment_name'] ?? $data['payment_type'] ?? '',
            '{payment_status}' => $data['payment_status'] ?? '',
            '{account_details}' => $data['account_details'] ?? '',
            '{account_info}' => $data['account_details'] ?? '',

            // Contact & Address
            '{phone}' => $data['phone'] ?? $data['customer_phone'] ?? '',
            '{customer_phone}' => $data['customer_phone'] ?? $data['phone'] ?? '',
            '{address}' => $data['address'] ?? $data['shipping_address'] ?? '',
            '{shipping_address}' => $data['shipping_address'] ?? $data['address'] ?? '',

            // Warehouse & Outlet
            '{warehouse_name}' => $data['warehouse_name'] ?? '',

            // Status & Reason
            '{status}' => $data['status'] ?? $data['order_status'] ?? '',
            '{order_status}' => $data['order_status'] ?? $data['status'] ?? '',
            '{reason}' => $data['reason'] ?? '',
            '{search_query}' => $data['search_query'] ?? '',

            // Delivery Fees & Matrix
            '{pickup_fee}' => $data['pickup_fee'] ?? '',
            '{city_fee}' => $data['city_fee'] ?? '',
            '{express_fee}' => $data['express_fee'] ?? '',
            '{delivery_fee}' => $data['delivery_fee'] ?? '',
            '{township}' => $data['township'] ?? $data['delivery_township'] ?? '',
            '{region}' => $data['region'] ?? $data['delivery_region'] ?? '',
            '{rider_name}' => $data['rider_name'] ?? '',
            '{rider_phone}' => $data['rider_phone'] ?? '',
            '{tracking_code}' => $data['tracking_code'] ?? '',
            '{notes}' => $data['notes'] ?? '',
            '{maps_url}' => $data['maps_url'] ?? '',
            '{duplicate_ref}' => $data['duplicate_ref'] ?? '',

            // Dates & Shop Info
            '{date}' => $data['date'] ?? date('Y-m-d H:i:s'),
            '{shop_name}' => $data['shop_name'] ?? $shopName,
            '{shop_phone}' => $data['shop_phone'] ?? $shopPhone,
            '{shop_address}' => $data['shop_address'] ?? $shopAddress,
        ];

        // Also merge any additional key passed in $data dynamically
        foreach ($data as $k => $v) {
            if (is_scalar($v) || is_null($v)) {
                $map['{' . $k . '}'] = (string)($v ?? '');
            }
        }

        return str_replace(array_keys($map), array_values($map), $template);
    }

    /**
     * Send Welcome Message with Customizable Template & Main Keyboard
     */
    public function sendWelcomeMenu(int|string $chatId, string $userName): void
    {
        Cache::forget("tg_session_{$chatId}");

        $shopName = Setting::get('company_name', 'အလင်းသစ် (Ah Linn Thit)');
        $welcomeTemplate = Setting::get('telegram_bot_welcome_msg', '');

        $displayName = !empty($userName) ? $userName : 'ဖောက်သည်';

        if (!empty(trim($welcomeTemplate))) {
            $msg = $this->replacePlaceholders($welcomeTemplate, [
                'customer_name' => $displayName,
                'user_name' => $displayName,
                'shop_name' => $shopName,
            ]);
        } else {
            // Default elegant Myanmar welcome
            $msg = "👋 *မင်္ဂလာပါ {$displayName}!* \n"
                 . "🏬 *{$shopName}* မှ လှိုက်လှဲစွာ ကြိုဆိုပါသည်ရှင်။\n\n"
                 . "ကျွန်မတို့ဆိုင်မှ ကုန်ပစ္စည်းများကို အောက်ပါခလုတ်များမှတဆင့် အလွယ်တကူ ရွေးချယ်မှာယူနိုင်ပါပြီရှင်။";
        }

        $this->sendMessage($chatId, $msg, $this->getMainKeyboard($chatId));
    }

    /**
     * Format Welcome Message String
     */
    public function formatWelcomeMessage(string $userName, ?string $chatId = null): string
    {
        $shopName = Setting::get('company_name', 'အလင်းသစ် (Ah Linn Thit)');
        $welcomeTemplate = Setting::get('telegram_bot_welcome_msg', '');
        $displayName = !empty($userName) ? $userName : 'ဖောက်သည်';

        if (!empty(trim($welcomeTemplate))) {
            return $this->replacePlaceholders($welcomeTemplate, [
                'customer_name' => $displayName,
                'user_name' => $displayName,
                'shop_name' => $shopName,
            ]);
        }

        return "👋 *မင်္ဂလာပါ {$displayName}!* \n"
             . "🏬 *{$shopName}* မှ လှိုက်လှဲစွာ ကြိုဆိုပါသည်ရှင်။\n\n"
             . "ကျွန်မတို့ဆိုင်မှ ကုန်ပစ္စည်းများကို အောက်ပါခလုတ်များမှတဆင့် အလွယ်တကူ ရွေးချယ်မှာယူနိုင်ပါပြီရှင်။";
    }

    /**
     * Catalog Navigation Hub: Options to view all, by category, by brand, or search
     */
    public function sendCatalogMenu(int|string $chatId, ?int $messageIdToEdit = null): void
    {
        $tpl = Setting::get('telegram_bot_catalog_menu_msg', '');
        $text = !empty(trim($tpl))
            ? $this->replacePlaceholders($tpl, ['chat_id' => $chatId])
            : "🛍️ *ကျွန်မတို့ဆိုင်၏ ကုန်ပစ္စည်း ကတ်တလောက်*\n"
            . "━━━━━━━━━━━━━━━━━━━━━\n\n"
            . "လူကြီးမင်း လိုအပ်သော ကုန်ပစ္စည်းများကို အောက်ပါ အမျိုးအစားများမှတဆင့် အဆင်ပြေစွာ ရှာဖွေရွေးချယ် ဝယ်ယူနိုင်ပါသည်ရှင်။\n\n"
            . "ရွေးချယ်လိုသည့် ကဏ္ဍကို နှိပ်ပေးပါ-";

        $btnAll = Setting::get('telegram_bot_btn_all_products', '📦 ပစ္စည်းအားလုံး ကြည့်မည် (All Products)');
        $btnCats = Setting::get('telegram_bot_btn_categories', '📂 အမျိုးအစားအလိုက် (Categories)');
        $btnBrands = Setting::get('telegram_bot_btn_brands', '🏷️ တံဆိပ်အလိုက် (Brands)');
        $btnSearch = Setting::get('telegram_bot_btn_search', '🔎 အမည် / ကုတ်နံပါတ်ဖြင့် ရှာမည် (Search)');
        $btnHome = Setting::get('telegram_bot_btn_home', '🏠 ပင်မ မီနူး (Home)');
        $btnCancel = Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)');

        $showAll = Setting::get('telegram_bot_show_catalog_btn_all', '1') === '1';
        $showCats = Setting::get('telegram_bot_show_catalog_btn_categories', '1') === '1';
        $showBrands = Setting::get('telegram_bot_show_catalog_btn_brands', '1') === '1';
        $showSearch = Setting::get('telegram_bot_show_catalog_btn_search', '1') === '1';
        $showHome = Setting::get('telegram_bot_show_catalog_btn_home', '1') === '1';
        $showCancel = Setting::get('telegram_bot_show_catalog_btn_cancel', '1') === '1';

        $buttons = [];
        if ($showAll) {
            $buttons[] = [
                ['text' => !empty($btnAll) ? $btnAll : '📦 ပစ္စည်းအားလုံး ကြည့်မည် (All Products)', 'callback_data' => 'nav_all_products'],
            ];
        }
        if ($showCats) {
            $buttons[] = [
                ['text' => !empty($btnCats) ? $btnCats : '📂 အမျိုးအစားအလိုက် (Categories)', 'callback_data' => 'nav_categories'],
            ];
        }
        if ($showBrands) {
            $buttons[] = [
                ['text' => !empty($btnBrands) ? $btnBrands : '🏷️ တံဆိပ်အလိုက် (Brands)', 'callback_data' => 'nav_brands'],
            ];
        }
        if ($showSearch) {
            $buttons[] = [
                ['text' => !empty($btnSearch) ? $btnSearch : '🔎 အမည် / ကုတ်နံပါတ်ဖြင့် ရှာမည် (Search)', 'callback_data' => 'nav_search'],
            ];
        }

        if ($showHome) {
            $buttons[] = [
                ['text' => !empty($btnHome) ? $btnHome : '🏠 ပင်မ မီနူး (Home)', 'callback_data' => 'nav_home'],
            ];
        }
        if ($showCancel) {
            $buttons[] = [
                ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
            ];
        }

        // Safety fallback: If all buttons are disabled by merchant, provide at least Home button so customer is never stuck
        if (empty($buttons)) {
            $buttons[] = [
                ['text' => !empty($btnHome) ? $btnHome : '🏠 ပင်မ မီနူး (Home)', 'callback_data' => 'nav_home'],
            ];
        }

        // Telegram Mini App (TMA) Touch Storefront
        if (Setting::get('telegram_bot_tma_enabled', '1') === '1') {
            $tmaUrl = trim(Setting::get('telegram_bot_tma_url', ''));
            if (empty($tmaUrl)) {
                $tmaUrl = url('/bot/shop');
            }
            array_unshift($buttons, [
                ['text' => '🛍️ Mini App ဖြင့်ကြည့်ရှုရန် (Touch App)', 'web_app' => ['url' => $tmaUrl]],
            ]);
        }

        $this->editOrSendMessage($chatId, $messageIdToEdit, $text, ['inline_keyboard' => $buttons]);
    }

    /**
     * Show Category Filter Options
     */
    public function sendCategoryFilterMenu(int|string $chatId, ?int $messageIdToEdit = null): void
    {
        try {
            $categories = Cache::remember('tg_bot_active_categories_v2', 300, function () {
                return ProductCategory::whereHas('products', function ($q) {
                    $q->where('is_bot_available', true)
                      ->where(function ($sub) {
                          $sub->where('is_active', true)->orWhere('status', 'Active');
                      });
                })->select('id', 'name')->get()->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->all();
            });

            if ($categories instanceof \__PHP_Incomplete_Class || (!is_array($categories) && !($categories instanceof \Illuminate\Support\Collection))) {
                Cache::forget('tg_bot_active_categories_v2');
                Cache::forget('tg_bot_active_categories');
                $categories = ProductCategory::whereHas('products', function ($q) {
                    $q->where('is_bot_available', true)
                      ->where(function ($sub) {
                          $sub->where('is_active', true)->orWhere('status', 'Active');
                      });
                })->select('id', 'name')->get()->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->all();
            }
        } catch (\Throwable $e) {
            $categories = ProductCategory::whereHas('products', function ($q) {
                $q->where('is_bot_available', true)
                  ->where(function ($sub) {
                      $sub->where('is_active', true)->orWhere('status', 'Active');
                  });
            })->select('id', 'name')->get()->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->all();
        }

        $btnAll = Setting::get('telegram_bot_btn_all_products', '📦 ပစ္စည်းအားလုံး ကြည့်မည်');
        $btnBack = Setting::get('telegram_bot_btn_back', '🔙 နောက်သို့');
        $btnMainMenu = Setting::get('telegram_bot_btn_main_menu', '🔙 ပင်မ မီနူးသို့');
        $btnSearch = Setting::get('telegram_bot_btn_search', '🔎 ရှာဖွေမည်');
        $isDirect = Setting::get('telegram_bot_direct_categories', '0') === '1';

        $isCatsEmpty = is_array($categories) ? empty($categories) : (method_exists($categories, 'isEmpty') ? $categories->isEmpty() : empty($categories));
        if ($isCatsEmpty) {
            $noCatsTpl = Setting::get('telegram_bot_no_categories_msg', '');
            $noCatsMsg = !empty(trim($noCatsTpl))
                ? $this->replacePlaceholders($noCatsTpl, [])
                : "ℹ️ လက်ရှိတွင် အမျိုးအစား ခွဲခြားထားသော Bot ပစ္စည်းများ မရှိသေးပါရှင်။";

            $emptyButtons = [
                [['text' => !empty($btnAll) ? $btnAll : '📦 ပစ္စည်းအားလုံး ကြည့်မည်', 'callback_data' => 'nav_all_products']],
            ];
            if ($isDirect) {
                $emptyButtons[] = [['text' => '🏠 ပင်မ မီနူး', 'callback_data' => 'nav_home']];
            } else {
                $emptyButtons[] = [['text' => !empty($btnBack) ? $btnBack : '🔙 နောက်သို့', 'callback_data' => 'catalog_main']];
            }

            $this->editOrSendMessage($chatId, $messageIdToEdit, $noCatsMsg, [
                'inline_keyboard' => $emptyButtons,
            ]);
            return;
        }

        $text = $isDirect
            ? "📂 *ကုန်ပစ္စည်း အမျိုးအစားများ (Categories)*\n\nလိုချင်သော အမျိုးအစားကို ရွေးချယ်ပါ (သို့မဟုတ် ပစ္စည်းအားလုံး ကြည့်ရှုပါ)-"
            : "📂 *အမျိုးအစား (Category) ရွေးချယ်ပါ-*";

        $buttons = [];

        foreach ($categories as $cat) {
            $catId = is_array($cat) ? $cat['id'] : $cat->id;
            $catName = is_array($cat) ? $cat['name'] : $cat->name;
            $buttons[] = [
                ['text' => "📁 {$catName}", 'callback_data' => "f_cat_{$catId}"],
            ];
        }

        // 📦 All Products & 🔎 Search placed right ABOVE Home / Main Menu
        $showAll = Setting::get('telegram_bot_show_catalog_btn_all', '1') === '1';
        $showSearch = Setting::get('telegram_bot_show_catalog_btn_search', '1') === '1';
        $subRow = [];
        if ($showAll) {
            $subRow[] = ['text' => !empty($btnAll) ? $btnAll : '📦 ပစ္စည်းအားလုံး ကြည့်မည်', 'callback_data' => 'nav_all_products'];
        }
        if ($showSearch) {
            $subRow[] = ['text' => !empty($btnSearch) ? $btnSearch : '🔎 ရှာဖွေမည်', 'callback_data' => 'nav_search'];
        }
        if (!empty($subRow)) {
            $buttons[] = $subRow;
        }

        // Bottom navigation
        if ($isDirect) {
            $buttons[] = [
                ['text' => '🏠 ပင်မ မီနူး (Home)', 'callback_data' => 'nav_home'],
            ];
        } else {
            $buttons[] = [
                ['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
            ];
        }

        $this->editOrSendMessage($chatId, $messageIdToEdit, $text, ['inline_keyboard' => $buttons]);
    }

    /**
     * Show Brand Filter Options
     */
    public function sendBrandFilterMenu(int|string $chatId, ?int $messageIdToEdit = null): void
    {
        try {
            $brands = Cache::remember('tg_bot_active_brands_v2', 300, function () {
                return Brand::whereHas('products', function ($q) {
                    $q->where('is_bot_available', true)
                      ->where(function ($sub) {
                          $sub->where('is_active', true)->orWhere('status', 'Active');
                      });
                })->select('id', 'name')->get()->map(fn($b) => ['id' => $b->id, 'name' => $b->name])->all();
            });

            if ($brands instanceof \__PHP_Incomplete_Class || (!is_array($brands) && !($brands instanceof \Illuminate\Support\Collection))) {
                Cache::forget('tg_bot_active_brands_v2');
                Cache::forget('tg_bot_active_brands');
                $brands = Brand::whereHas('products', function ($q) {
                    $q->where('is_bot_available', true)
                      ->where(function ($sub) {
                          $sub->where('is_active', true)->orWhere('status', 'Active');
                      });
                })->select('id', 'name')->get()->map(fn($b) => ['id' => $b->id, 'name' => $b->name])->all();
            }
        } catch (\Throwable $e) {
            $brands = Brand::whereHas('products', function ($q) {
                $q->where('is_bot_available', true)
                  ->where(function ($sub) {
                      $sub->where('is_active', true)->orWhere('status', 'Active');
                  });
            })->select('id', 'name')->get()->map(fn($b) => ['id' => $b->id, 'name' => $b->name])->all();
        }

        $btnAll = Setting::get('telegram_bot_btn_all_products', '📦 ပစ္စည်းအားလုံး ကြည့်မည်');
        $btnBack = Setting::get('telegram_bot_btn_back', '🔙 နောက်သို့');
        $btnMainMenu = Setting::get('telegram_bot_btn_main_menu', '🔙 ပင်မ မီနူးသို့');

        $isBrandsEmpty = is_array($brands) ? empty($brands) : (method_exists($brands, 'isEmpty') ? $brands->isEmpty() : empty($brands));
        if ($isBrandsEmpty) {
            $noBrandsTpl = Setting::get('telegram_bot_no_brands_msg', '');
            $noBrandsMsg = !empty(trim($noBrandsTpl))
                ? $this->replacePlaceholders($noBrandsTpl, [])
                : "ℹ️ လက်ရှိတွင် တံဆိပ် (Brand) ခွဲခြားထားသော Bot ပစ္စည်းများ မရှိသေးပါရှင်။";

            $this->editOrSendMessage($chatId, $messageIdToEdit, $noBrandsMsg, [
                'inline_keyboard' => [
                    [['text' => !empty($btnAll) ? $btnAll : '📦 ပစ္စည်းအားလုံး ကြည့်မည်', 'callback_data' => 'nav_all_products']],
                    [['text' => !empty($btnBack) ? $btnBack : '🔙 နောက်သို့', 'callback_data' => 'catalog_main']],
                ],
            ]);
            return;
        }

        $text = "🏷️ *တံဆိပ် (Brand) ရွေးချယ်ပါ-*";
        $buttons = [];

        foreach ($brands as $brand) {
            $bId = is_array($brand) ? $brand['id'] : $brand->id;
            $bName = is_array($brand) ? $brand['name'] : $brand->name;
            $buttons[] = [
                ['text' => "🏷️ {$bName}", 'callback_data' => "f_brand_{$bId}"],
            ];
        }

        $showAll = Setting::get('telegram_bot_show_catalog_btn_all', '1') === '1';
        if ($showAll) {
            $buttons[] = [
                ['text' => !empty($btnAll) ? $btnAll : '📦 ပစ္စည်းအားလုံး ကြည့်မည်', 'callback_data' => 'nav_all_products'],
            ];
        }
        $buttons[] = [
            ['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
        ];

        $this->editOrSendMessage($chatId, $messageIdToEdit, $text, ['inline_keyboard' => $buttons]);
    }

    /**
     * Send Paginated Catalog of Products (Filtered or All) with Fast Navigation Buttons
     */
    /**
     * Send a single rich product card with photo, badges, details, stock and order button
     */
    public function sendProductCardItem(string|int $chatId, Product $p, int $index = 1, bool $withButtons = true): bool
    {
        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 3);
        $allowOutOfStock = Setting::get('telegram_bot_allow_out_of_stock_orders', '0') === '1';

        $showCode = Setting::get('telegram_bot_show_product_code', '1') === '1';
        $showCat = Setting::get('telegram_bot_show_product_category', '1') === '1';
        $showBrand = Setting::get('telegram_bot_show_product_brand', '1') === '1';
        $showPrice = Setting::get('telegram_bot_show_product_price', '1') === '1';
        $showStock = Setting::get('telegram_bot_show_product_stock', '1') === '1';
        $showDetails = Setting::get('telegram_bot_show_product_details', '1') === '1';
        $showVariants = Setting::get('telegram_bot_show_product_variants', '1') === '1';

        if (!$p->relationLoaded('variants') || !$p->relationLoaded('category') || !$p->relationLoaded('brand')) {
            $p->load(['variants' => fn($vq) => $vq->where('is_active', true), 'category', 'brand', 'primaryImage', 'images']);
        }

        $effectivePrice = (float)($p->retail_price ?: $p->selling_price);
        $stock = (float)StockBalance::where('warehouse_id', $warehouseId)
            ->where('product_id', $p->id)
            ->sum('quantity');

        // 1. Classification Badges
        $badges = [];
        $catName = $p->category?->name ?? $p->category_name ?? '';
        $brandName = $p->brand?->name ?? $p->brand_name ?? '';
        if (!empty($catName)) $badges[] = "📁 {$catName}";
        if (!empty($brandName)) $badges[] = "🏷️ {$brandName}";
        $metaStr = !empty($badges) ? "\n" . implode(' • ', $badges) : "";

        $cardLines = [];
        $numEmoji = match($index) {
            1 => '1️⃣',
            2 => '2️⃣',
            3 => '3️⃣',
            4 => '4️⃣',
            5 => '5️⃣',
            default => "{$index}️⃣",
        };
        $cardLines[] = "{$numEmoji} *{$p->name}*{$metaStr}";

        // 2. Product Code
        if ($showCode && !empty($p->code ?: $p->sku)) {
            $code = $p->code ?: $p->sku;
            $cardLines[] = "📌 ကုတ်နံပါတ်:  `{$code}`";
        }

        // 3. Price
        if ($showPrice) {
            $priceFormatted = number_format($effectivePrice);
            $cardLines[] = "💰 ဈေးနှုန်း:    *{$priceFormatted} MMK*";
        }

        // 4. Stock Badge & Out of Stock Policy
        if ($showStock) {
            if ($stock <= 0) {
                $stockText = $allowOutOfStock
                    ? "⚠️ *လက်ကျန်ပြတ်လပ် (Pre-Order ရရှိနိုင်သည်)*"
                    : "❌ *လက်ကျန်ပြတ်လပ် (Sold Out)*";
            } elseif ($stock <= 5) {
                $stockText = "⚡ *လက်ကျန်:* `{$stock}` " . ($p->unit_name ?: 'ခု') . " *သာ ကျန်ပါတော့သည် (Low Stock)*";
            } else {
                $stockText = "✅ *လက်ကျန်:* `{$stock}` " . ($p->unit_name ?: 'ခု') . " (Available)";
            }
            $cardLines[] = "📦 လက်ကျန်:   {$stockText}";
        }

        // 5. Details / Description snippet
        if (!empty($p->description)) {
            $cleanDesc = trim(strip_tags($p->description));
            if (!empty($cleanDesc)) {
                $snippet = mb_substr($cleanDesc, 0, 95) . (mb_strlen($cleanDesc) > 95 ? '...' : '');
                $cardLines[] = "📝 အသေးစိတ်:  _{$snippet}_";
            }
        }

        // 6. Variants
        $hasActiveVariants = $p->has_variants && $p->variants && $p->variants->isNotEmpty();
        if ($showVariants && $hasActiveVariants) {
            $vNames = $p->variants->pluck('name')->implode(', ');
            $cardLines[] = "🎨 ဆိုဒ်များ:   {$vNames}";
        }

        $markup = null;
        if ($withButtons) {
            // 7. Action Button
            if ($hasActiveVariants) {
                $buyBtnText = Setting::get('telegram_bot_btn_select_variant_catalog', Setting::get('telegram_bot_btn_select_variant', '🎨 ဆိုဒ်ရွေးမည်'));
                if (empty($buyBtnText)) {
                    $buyBtnText = '🎨 ဆိုဒ်ရွေးမည်';
                }
                $buyCallback = "buy_{$p->id}";
            } else {
                if ($stock <= 0) {
                    if ($allowOutOfStock) {
                        $buyBtnText = Setting::get('telegram_bot_btn_preorder_catalog', '⏳ ကြိုတင်မှာမည်');
                        if (empty($buyBtnText)) {
                            $buyBtnText = '⏳ ကြိုတင်မှာမည်';
                        }
                        $buyCallback = "buy_{$p->id}";
                    } else {
                        $buyBtnText = "⚠️ ပစ္စည်းပြတ် (Sold Out)";
                        $buyCallback = "stock_out_{$p->id}";
                    }
                } else {
                    $buyBtnText = Setting::get('telegram_bot_btn_buy_catalog', Setting::get('telegram_bot_btn_buy', '🛒 မှာမည်'));
                    if (empty($buyBtnText)) {
                        $buyBtnText = '🛒 မှာမည်';
                    }
                    $buyCallback = "buy_{$p->id}";
                }
            }

            $cardButtons = [
                [
                    ['text' => $buyBtnText, 'callback_data' => $buyCallback],
                ]
            ];
            $markup = ['inline_keyboard' => $cardButtons];
        } else {
            $cardLines[] = "💡 မှာယူလိုပါက 'ဒီဟာ ယူမယ်' (သို့မဟုတ်) အရောင်/ဆိုဒ်လေး ပြောပြပေးပါရှင်။";
        }

        $caption = implode("\n", $cardLines);

        $showPhotos = Setting::get('telegram_bot_ai_show_photos', '1') === '1';
        $imagePath = $showPhotos 
            ? ($p->image_url ?: ($p->primaryImage?->path ?: ($p->images->first()?->path ?? null)))
            : null;

        $sent = false;
        if (!empty($imagePath)) {
            $res = $this->sendPhoto($chatId, $imagePath, $caption, $markup);
            if (!empty($res['ok'])) {
                $sent = true;
            }
        }

        if (!$sent) {
            $res = $this->sendMessage($chatId, $caption, $markup);
            return !empty($res['ok']);
        }

        return true;
    }

    public function sendCatalog(
        int|string $chatId,
        int $page = 1,
        ?int $categoryId = null,
        ?int $subCategoryId = null,
        ?int $brandId = null,
        ?string $searchQuery = null,
        ?string $headerTitle = null,
        ?int $messageIdToEdit = null
    ): void {
        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);

        $query = Product::where('is_bot_available', true)
            ->where(function ($q) {
                $q->where('is_active', true)->orWhere('status', 'Active');
            });

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($subCategoryId) {
            $query->where('sub_category_id', $subCategoryId);
        }

        if ($brandId) {
            $query->where('brand_id', $brandId);
        }

        if (!empty($searchQuery)) {
            $s = trim($searchQuery);
            if (preg_match('/^(?:subcat|sub_cat|sub|scat|sc|subcategory|sub_category)[_\s:]*(\d+)$/i', $s, $sm)) {
                $query->where('sub_category_id', (int)$sm[1]);
            } else {
                $query->where(function ($q) use ($s) {
                    $q->where('name', 'like', "%{$s}%")
                      ->orWhere('code', 'like', "%{$s}%")
                      ->orWhere('sku', 'like', "%{$s}%")
                      ->orWhere('description', 'like', "%{$s}%")
                      ->orWhere('sub_category_name', 'like', "%{$s}%")
                      ->orWhere('sub_category_id', is_numeric($s) ? (int)$s : -1)
                      ->orWhereHas('category', fn($cq) => $cq->where('name', 'like', "%{$s}%"))
                      ->orWhereHas('subCategory', fn($sq) => $sq->where('name', 'like', "%{$s}%"))
                      ->orWhereHas('brand', fn($bq) => $bq->where('name', 'like', "%{$s}%"))
                      ->orWhere('id', is_numeric($s) ? (int)$s : -1);
                });
            }
        }

        $hideOutOfStock = Setting::get('telegram_bot_hide_out_of_stock', '0') === '1';
        if ($hideOutOfStock) {
            $query->whereHas('stockBalances', function ($sq) use ($warehouseId) {
                $sq->where('warehouse_id', $warehouseId)
                   ->where('quantity', '>', 0);
            });
        }

        $catalogStyle = Setting::get('telegram_bot_catalog_style', 'card_feed');

        $rawPerPage = (int)Setting::get('telegram_bot_catalog_per_page', 3);
        if ($rawPerPage < 1) $rawPerPage = 3;

        // 🛡️ Intelligent Safety Clamping based on Telegram Bot API Limits:
        // Enforce strict Max 10 products per page ceiling for both Card Feed and Single Summary styles.
        // - Single Summary: 10 items totals ~3,100 to 3,600 chars (< 4,096 chars limit) & 26 buttons (< 100 limit).
        // - Card Feed: Allows merchant to display up to 10 photo cards per page without crashing or flood wait.
        // Guarantees fitting in Telegram limits, zero dropped messages, and 100% sequential product numbering.
        $perPage = max(1, min(10, $rawPerPage));


        $totalCount = $query->count();

        if ($totalCount === 0) {
            $tpl = Setting::get('telegram_bot_no_products_msg', '');
            $emptyMsg = !empty(trim($tpl))
                ? $this->replacePlaceholders($tpl, ['search_query' => $searchQuery ?? ''])
                : "ℹ️ ရှာဖွေမှုနှင့် ကိုက်ညီသော ကုန်ပစ္စည်းများ မရှိသေးပါရှင်။";

            $btnAll = Setting::get('telegram_bot_btn_all_products', '📦 ပစ္စည်းအားလုံး ကြည့်မည်');
            $btnBack = Setting::get('telegram_bot_btn_back', '🔙 နောက်သို့');

            $emptyButtons = [
                'inline_keyboard' => [
                    [['text' => !empty($btnAll) ? $btnAll : '📦 ပစ္စည်းအားလုံး ကြည့်မည်', 'callback_data' => 'nav_all_products']],
                    [['text' => !empty($btnBack) ? $btnBack : '🔙 နောက်သို့', 'callback_data' => 'catalog_main']],
                ],
            ];

            if ($messageIdToEdit) {
                $res = $this->editMessageText($chatId, $messageIdToEdit, $emptyMsg, $emptyButtons);
                if (!empty($res['ok'])) return;
            }
            $this->sendMessage($chatId, $emptyMsg, $emptyButtons);
            return;
        }

        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        if ($page < 1) $page = 1;

        $products = $query->with([
            'variants' => fn($q) => $q->where('is_active', true)->orderBy('sort_order', 'asc'),
            'images',
            'primaryImage',
        ])->skip(($page - 1) * $perPage)->take($perPage)->get();

        // High-Performance SSOT: Pre-aggregate stock for all products on current page in 1 single query (Eliminates N+1)
        $productIds = $products->pluck('id')->toArray();
        $stockMap = StockBalance::where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as total_qty')
            ->pluck('total_qty', 'product_id');

        if (empty($headerTitle)) {
            if ($categoryId) {
                $catName = ProductCategory::find($categoryId)?->name ?? 'အမျိုးအစား';
                $headerTitle = "📂 *{$catName} ကုန်ပစ္စည်းများ*";
            } elseif ($subCategoryId) {
                $subName = SubCategory::find($subCategoryId)?->name ?? 'အမျိုးအစားခွဲ';
                $headerTitle = "🔹 *{$subName} ကုန်ပစ္စည်းများ*";
            } elseif ($brandId) {
                $brandName = Brand::find($brandId)?->name ?? 'တံဆိပ်';
                $headerTitle = "🏷️ *{$brandName} ကုန်ပစ္စည်းများ*";
            } else {
                $headerTitle = "🛍️ *ကျွန်မတို့ဆိုင်၏ ရောင်းချပေးနေသော ကုန်ပစ္စည်းများ*";
            }
        }

        // Configurable Field Visibility Settings & Out-of-Stock Selling Policy (SSOT)
        $showCode = Setting::get('telegram_bot_show_product_code', '1') === '1';
        $showCat = Setting::get('telegram_bot_show_product_category', '1') === '1';
        $showSubCat = Setting::get('telegram_bot_show_product_sub_category', '1') === '1';
        $showBrand = Setting::get('telegram_bot_show_product_brand', '1') === '1';
        $showPrice = Setting::get('telegram_bot_show_product_price', '1') === '1';
        $showStock = Setting::get('telegram_bot_show_product_stock', '1') === '1';
        $showDetails = Setting::get('telegram_bot_show_product_details', '0') === '1';
        $showImageBtn = Setting::get('telegram_bot_show_product_image', '1') === '1';
        $showVariants = Setting::get('telegram_bot_show_product_variants', '1') === '1';
        $allowOutOfStock = Setting::get('telegram_bot_allow_out_of_stock_orders', '0') === '1';

        $customSelectVar = Setting::get('telegram_bot_btn_select_variant_catalog', '🎨 ဆိုဒ်ရွေးမည်');
        $customBuy = Setting::get('telegram_bot_btn_buy_catalog', '🛒 မှာမည်');
        $customPreorder = Setting::get('telegram_bot_btn_preorder_catalog', '⏳ ကြိုတင်မှာမည်');
        $customView = Setting::get('telegram_bot_btn_view_details', '🔍 အသေးစိတ်/ပုံ');

        // =========================================================================
        // 🌟 1. CARD FEED STYLE (Each product has its own card with direct buttons)
        // =========================================================================
        if ($catalogStyle === 'card_feed') {
            if ($messageIdToEdit) {
                $this->deleteMessage($chatId, $messageIdToEdit);
            }

            // Header banner when searching or filtering
            if (!empty($searchQuery)) {
                $this->sendMessage($chatId, "🔎 *'{$searchQuery}' အတွက် ရှာဖွေတွေ့ရှိသော ကုန်ပစ္စည်းများ* (စာမျက်နှာ {$page}/{$totalPages})");
            } elseif ($categoryId || $subCategoryId || $brandId) {
                $sectionHeader = $headerTitle ?: '📂 ကုန်ပစ္စည်းများ';
                $this->sendMessage($chatId, "{$sectionHeader} (စာမျက်နှာ {$page}/{$totalPages})");
            }

            $index = 1;
            foreach ($products as $p) {
                $effectivePrice = (float)($p->retail_price ?: $p->selling_price);
                $stock = (float)($stockMap[$p->id] ?? 0);
                $itemNum = ($page - 1) * $perPage + $index;

                // 1. Classification Badges
                $badges = [];
                if ($showCat && !empty($p->category_name)) $badges[] = "📂 {$p->category_name}";
                if ($showSubCat && !empty($p->sub_category_name)) $badges[] = "🔹 {$p->sub_category_name}";
                if ($showBrand && !empty($p->brand_name)) $badges[] = "🏷️ {$p->brand_name}";
                $metaStr = !empty($badges) ? "\n" . implode(' • ', $badges) : "";

                $cardLines = [];
                $cardLines[] = "{$itemNum}️⃣ *{$p->name}*{$metaStr}";

                // 2. Product Code
                if ($showCode && !empty($p->code)) {
                    $cardLines[] = "📌 ကုတ်နံပါတ်:  `{$p->code}`";
                }

                // 3. Price
                if ($showPrice) {
                    $priceFormatted = number_format($effectivePrice);
                    $cardLines[] = "💰 ဈေးနှုန်း:    *{$priceFormatted} MMK*";
                }

                // 4. Stock Badge & Out of Stock Policy
                if ($showStock) {
                    if ($stock <= 0) {
                        $stockText = $allowOutOfStock
                            ? "⚠️ *လက်ကျန်ပြတ်လပ် (Pre-Order ရရှိနိုင်သည်)*"
                            : "❌ *လက်ကျန်ပြတ်လပ် (Sold Out)*";
                    } elseif ($stock <= 5) {
                        $stockText = "⚡ *လက်ကျန်:* `{$stock}` " . ($p->unit_name ?: 'ခု') . " *သာ ကျန်ပါတော့သည် (Low Stock)*";
                    } else {
                        $stockText = "✅ *လက်ကျန်:* `{$stock}` " . ($p->unit_name ?: 'ခု') . " (Available)";
                    }
                    $cardLines[] = "📦 လက်ကျန်:   {$stockText}";
                }

                // 5. Details / Description snippet
                if ($showDetails && !empty($p->description)) {
                    $cleanDesc = trim(strip_tags($p->description));
                    if (!empty($cleanDesc)) {
                        $snippet = mb_substr($cleanDesc, 0, 95) . (mb_strlen($cleanDesc) > 95 ? '...' : '');
                        $cardLines[] = "📝 အသေးစိတ်:  _{$snippet}_";
                    }
                }

                // 6. Variants
                $hasActiveVariants = $p->has_variants && $p->variants && $p->variants->isNotEmpty();
                if ($showVariants && $hasActiveVariants) {
                    $vNames = $p->variants->pluck('name')->implode(', ');
                    $cardLines[] = "🎨 ဆိုဒ်များ:   {$vNames}";
                }

                // 7. Action Button attached DIRECTLY to this product!
                $showBtnBuyCatalog = Setting::get('telegram_bot_show_btn_buy_catalog', '1') === '1';
                $showBtnSelectVarCatalog = Setting::get('telegram_bot_show_btn_select_variant_catalog', '1') === '1';

                $cardButtons = [];
                if ($hasActiveVariants) {
                    if ($showBtnSelectVarCatalog) {
                        $buyBtnText = !empty($customSelectVar) ? $customSelectVar : '🎨 ဆိုဒ်ရွေးမည် (Select Size)';
                        $cardButtons[] = [
                            ['text' => $buyBtnText, 'callback_data' => "buy_{$p->id}"],
                        ];
                    }
                } else {
                    if ($stock <= 0) {
                        if ($allowOutOfStock) {
                            $buyBtnText = !empty($customPreorder) ? $customPreorder : '⏳ ကြိုတင်မှာမည် (Pre-Order)';
                            $cardButtons[] = [
                                ['text' => $buyBtnText, 'callback_data' => "buy_{$p->id}"],
                            ];
                        } else {
                            $cardButtons[] = [
                                ['text' => "⚠️ ပစ္စည်းပြတ် (Sold Out)", 'callback_data' => "stock_out_{$p->id}"],
                            ];
                        }
                    } elseif ($showBtnBuyCatalog) {
                        $buyBtnText = !empty($customBuy) ? $customBuy : '🛒 မှာယူမည် (Order Now)';
                        $cardButtons[] = [
                            ['text' => $buyBtnText, 'callback_data' => "buy_{$p->id}"],
                        ];
                    }
                }

                if (!$hasActiveVariants && $stock <= 0 && !$allowOutOfStock) {
                    if (Setting::get('telegram_bot_restock_notify_enabled', '0') === '1') {
                        $cardButtons[] = [
                            ['text' => '🔔 ရောက်လျှင် သတိပေးပါ (Notify Me)', 'callback_data' => "stock_notify_{$p->id}"],
                        ];
                    }
                }

                $caption = implode("\n", $cardLines);

                $imagePath = $p->image_url 
                    ?: ($p->primaryImage?->path ?: ($p->images->first()?->path ?? null));

                $cardReplyMarkup = !empty($cardButtons) ? ['inline_keyboard' => $cardButtons] : null;
                $sent = false;
                if (!empty($imagePath)) {
                    $res = $this->sendPhoto($chatId, $imagePath, $caption, $cardReplyMarkup);
                    if (!empty($res['ok'])) {
                        $sent = true;
                    }
                }

                if (!$sent) {
                    $this->sendMessage($chatId, $caption, $cardReplyMarkup);
                }

                $index++;
            }

            // Pagination & Navigation Bar at bottom of current page
            $cId = $categoryId ?? 0;
            $sId = $subCategoryId ?? 0;
            $bId = $brandId ?? 0;
            $filterToken = "{$cId}_{$sId}_{$bId}";

            $btnPrev = Setting::get('telegram_bot_btn_prev', '◀️ ရှေ့သို့');
            $btnNext = Setting::get('telegram_bot_btn_next', 'နောက်သို့ ▶️');
            $btnCats = Setting::get('telegram_bot_btn_categories', '📂 အမျိုးအစားများ');
            $btnBrands = Setting::get('telegram_bot_btn_brands', '🏷️ တံဆိပ်များ');
            $btnMainMenu = Setting::get('telegram_bot_btn_main_menu', '🔙 ပင်မ မီနူးသို့');
            $btnCancel = Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)');

            $showBtnPrev = Setting::get('telegram_bot_show_btn_prev', '1') === '1';
            $showBtnNext = Setting::get('telegram_bot_show_btn_next', '1') === '1';
            $showNavCats = Setting::get('telegram_bot_show_catalog_nav_categories', Setting::get('telegram_bot_show_catalog_btn_categories', '1')) === '1';
            $showNavBrands = Setting::get('telegram_bot_show_catalog_nav_brands', Setting::get('telegram_bot_show_catalog_btn_brands', '1')) === '1';
            $showNavMainMenu = Setting::get('telegram_bot_show_catalog_nav_main_menu', Setting::get('telegram_bot_show_catalog_btn_home', '1')) === '1';
            $showNavCancel = Setting::get('telegram_bot_show_catalog_nav_cancel', Setting::get('telegram_bot_show_catalog_btn_cancel', '1')) === '1';

            $navButtons = [];

            if ($totalPages > 1) {
                $paginationRow = [];
                if ($page > 1 && $showBtnPrev) {
                    $prevPage = $page - 1;
                    $paginationRow[] = ['text' => !empty($btnPrev) ? $btnPrev : '◀️ ရှေ့သို့', 'callback_data' => "cat_p_{$prevPage}_{$filterToken}"];
                }
                if ($showBtnPrev || $showBtnNext) {
                    $paginationRow[] = ['text' => "📄 {$page}/{$totalPages}", 'callback_data' => 'noop'];
                }
                if ($page < $totalPages && $showBtnNext) {
                    $nextPage = $page + 1;
                    $paginationRow[] = ['text' => !empty($btnNext) ? $btnNext : 'နောက်သို့ ▶️', 'callback_data' => "cat_p_{$nextPage}_{$filterToken}"];
                }
                if (!empty($paginationRow)) {
                    $navButtons[] = $paginationRow;
                }
            }

            // Dynamic Cart Bar if items exist in cart
            $cartSummary = $this->getCartSummary($chatId);
            if (($cartSummary['items_count'] ?? 0) > 0) {
                $totalQ = (floor($cartSummary['total_qty']) == $cartSummary['total_qty']) ? (int)$cartSummary['total_qty'] : $cartSummary['total_qty'];
                $grandT = number_format($cartSummary['grand_total']);

                $showNavCheckout = in_array(Setting::get('telegram_bot_show_catalog_nav_checkout', '1'), ['1', 1, true, 'true'], true);
                $showNavCart = in_array(Setting::get('telegram_bot_show_catalog_nav_cart', '1'), ['1', 1, true, 'true'], true);
                $btnNavCheckout = Setting::get('telegram_bot_btn_catalog_nav_checkout', Setting::get('telegram_bot_btn_checkout', '💳 အော်ဒါတင်မည်'));
                $btnNavCart = Setting::get('telegram_bot_btn_catalog_nav_cart', Setting::get('telegram_bot_btn_cart', '🛒 ခြင်းတောင်း ကြည့်မည်'));

                if ($showNavCheckout) {
                    $chkLabel = !empty($btnNavCheckout) ? $btnNavCheckout : '💳 အော်ဒါတင်မည်';
                    $navButtons[] = [
                        ['text' => $chkLabel, 'callback_data' => 'cart_checkout'],
                    ];
                }
                if ($showNavCart) {
                    $cartLabel = !empty($btnNavCart) ? $btnNavCart : '🛒 ခြင်းတောင်း ကြည့်မည်';
                    $navButtons[] = [
                        ['text' => $cartLabel, 'callback_data' => 'cart_view'],
                    ];
                }
            }

            if ($showNavCats) {
                $navButtons[] = [
                    ['text' => !empty($btnCats) ? $btnCats : '📂 အမျိုးအစားများ', 'callback_data' => 'nav_categories'],
                ];
            }
            if ($showNavBrands) {
                $navButtons[] = [
                    ['text' => !empty($btnBrands) ? $btnBrands : '🏷️ တံဆိပ်များ', 'callback_data' => 'nav_brands'],
                ];
            }
            if ($showNavMainMenu) {
                $navButtons[] = [
                    ['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
                ];
            }
            if ($showNavCancel) {
                $navButtons[] = [
                    ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
                ];
            }

            $navMsg = "━━━━━━━━━━━━━━━━━━━━━\n"
                    . "📄 *စာမျက်နှာ:* {$page} / {$totalPages} (စုစုပေါင်း {$totalCount} မျိုး)\n\n"
                    . "💡 မိမိမှာယူလိုသော ပစ္စည်းအောက်ရှိ '{$customBuy}' ကို နှိပ်၍ တိုက်ရိုက်မှာယူနိုင်ပါသည်ရှင်-";

            $replyMarkup = !empty($navButtons) ? ['inline_keyboard' => $navButtons] : null;
            $this->sendMessage($chatId, $navMsg, $replyMarkup);
            return;
        }

        // =========================================================================
        // 📋 2. SINGLE SUMMARY STYLE (Aggregated text list)
        // =========================================================================
        $text = "{$headerTitle} (စာမျက်နှာ {$page}/{$totalPages})\n"
              . "━━━━━━━━━━━━━━━━━━━━━\n\n";

        $buttons = [];
        $index = 1;

        $showBtnBuyCatalog = Setting::get('telegram_bot_show_btn_buy_catalog', '1') === '1';
        $showBtnSelectVarCatalog = Setting::get('telegram_bot_show_btn_select_variant_catalog', '1') === '1';

        foreach ($products as $p) {
            $effectivePrice = (float)($p->retail_price ?: $p->selling_price);
            $stock = (float)($stockMap[$p->id] ?? 0);
            $itemNum = ($page - 1) * $perPage + $index;

            // 1. Title & Classification Badges
            $badges = [];
            if ($showCat && !empty($p->category_name)) $badges[] = "📂 {$p->category_name}";
            if ($showSubCat && !empty($p->sub_category_name)) $badges[] = "🔹 {$p->sub_category_name}";
            if ($showBrand && !empty($p->brand_name)) $badges[] = "🏷️ {$p->brand_name}";
            $metaStr = !empty($badges) ? " [" . implode(' • ', $badges) . "]" : "";

            $cardLines = [];
            $cardLines[] = "{$itemNum}️⃣ *{$p->name}*{$metaStr}";

            // 2. Product Code
            if ($showCode && !empty($p->code)) {
                $cardLines[] = "   ▫️ ကုတ်နံပါတ်:  `{$p->code}`";
            }

            // 3. Price
            if ($showPrice) {
                $priceFormatted = number_format($effectivePrice);
                $cardLines[] = "   ▫️ ဈေးနှုန်း:    *{$priceFormatted} MMK*";
            }

            // 4. Stock Badge & Out of Stock Policy
            if ($showStock) {
                if ($stock <= 0) {
                    $stockText = $allowOutOfStock
                        ? "⚠️ လက်ကျန်ပြတ်လပ် (Pre-Order ရရှိနိုင်)"
                        : "❌ လက်ကျန်ပြတ်လပ် (Sold Out)";
                } elseif ($stock <= 5) {
                    $stockText = "⚡ `{$stock}` " . ($p->unit_name ?: 'ခု') . " သာ ကျန်ပါတော့သည်";
                } else {
                    $stockText = "✅ `{$stock}` " . ($p->unit_name ?: 'ခု') . " (Available)";
                }
                $cardLines[] = "   ▫️ လက်ကျန်:   {$stockText}";
            }

            // 5. Details / Description snippet
            if ($showDetails && !empty($p->description)) {
                $cleanDesc = trim(strip_tags($p->description));
                if (!empty($cleanDesc)) {
                    $snippet = mb_substr($cleanDesc, 0, 75) . (mb_strlen($cleanDesc) > 75 ? '...' : '');
                    $cardLines[] = "   ▫️ အသေးစိတ်:  _{$snippet}_";
                }
            }

            // 6. Variants
            $hasActiveVariants = $p->has_variants && $p->variants && $p->variants->isNotEmpty();
            if ($showVariants && $hasActiveVariants) {
                $vNames = $p->variants->pluck('name')->implode(', ');
                $cardLines[] = "   ▫️ ဆိုဒ်များ:   {$vNames}";
            }

            // Card Divider
            $text .= implode("\n", $cardLines) . "\n───────────────────\n\n";

            // 7. Action Buttons
            $rowButtons = [];
            if ($showImageBtn) {
                $viewBtnText = "{$itemNum}. " . (!empty($customView) ? $customView : '🔍 အသေးစိတ်/ပုံ');
                $rowButtons[] = ['text' => $viewBtnText, 'callback_data' => "view_{$p->id}"];
            }

            if ($hasActiveVariants) {
                if ($showBtnSelectVarCatalog) {
                    $buyBtnText = "{$itemNum}. " . (!empty($customSelectVar) ? $customSelectVar : '🎨 ဆိုဒ်ရွေးမည်');
                    $rowButtons[] = ['text' => $buyBtnText, 'callback_data' => "buy_{$p->id}"];
                }
            } else {
                if ($stock <= 0) {
                    if ($allowOutOfStock) {
                        $buyBtnText = "{$itemNum}. " . (!empty($customPreorder) ? $customPreorder : '⏳ ကြိုတင်မှာမည်');
                        $rowButtons[] = ['text' => $buyBtnText, 'callback_data' => "buy_{$p->id}"];
                    } else {
                        $buyBtnText = "{$itemNum}. ⚠️ ပစ္စည်းပြတ်";
                        $rowButtons[] = ['text' => $buyBtnText, 'callback_data' => "stock_out_{$p->id}"];
                    }
                } elseif ($showBtnBuyCatalog) {
                    $buyBtnText = "{$itemNum}. " . (!empty($customBuy) ? $customBuy : '🛒 မှာမည်');
                    $rowButtons[] = ['text' => $buyBtnText, 'callback_data' => "buy_{$p->id}"];
                }
            }

            if (!empty($rowButtons)) {
                $buttons[] = $rowButtons;
            }
            $index++;
        }

        // Pagination buttons
        $cId = $categoryId ?? 0;
        $sId = $subCategoryId ?? 0;
        $bId = $brandId ?? 0;
        $filterToken = "{$cId}_{$sId}_{$bId}";

        $btnPrev = Setting::get('telegram_bot_btn_prev', '◀️ ရှေ့သို့');
        $btnNext = Setting::get('telegram_bot_btn_next', 'နောက်သို့ ▶️');
        $btnCats = Setting::get('telegram_bot_btn_categories', '📂 အမျိုးအစားများ');
        $btnBrands = Setting::get('telegram_bot_btn_brands', '🏷️ တံဆိပ်များ');
        $btnMainMenu = Setting::get('telegram_bot_btn_main_menu', '🔙 ပင်မ မီနူးသို့');
        $btnCancel = Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)');

        $showBtnPrev = Setting::get('telegram_bot_show_btn_prev', '1') === '1';
        $showBtnNext = Setting::get('telegram_bot_show_btn_next', '1') === '1';
        $showNavCats = Setting::get('telegram_bot_show_catalog_nav_categories', Setting::get('telegram_bot_show_catalog_btn_categories', '1')) === '1';
        $showNavBrands = Setting::get('telegram_bot_show_catalog_nav_brands', Setting::get('telegram_bot_show_catalog_btn_brands', '1')) === '1';
        $showNavMainMenu = Setting::get('telegram_bot_show_catalog_nav_main_menu', Setting::get('telegram_bot_show_catalog_btn_home', '1')) === '1';
        $showNavCancel = Setting::get('telegram_bot_show_catalog_nav_cancel', Setting::get('telegram_bot_show_catalog_btn_cancel', '1')) === '1';

        $paginationRow = [];
        if ($page > 1 && $showBtnPrev) {
            $prevPage = $page - 1;
            $paginationRow[] = ['text' => !empty($btnPrev) ? $btnPrev : '◀️ ရှေ့သို့', 'callback_data' => "cat_p_{$prevPage}_{$filterToken}"];
        }

        if ($showBtnPrev || $showBtnNext) {
            $paginationRow[] = ['text' => "📄 {$page}/{$totalPages}", 'callback_data' => 'noop'];
        }

        if ($page < $totalPages && $showBtnNext) {
            $nextPage = $page + 1;
            $paginationRow[] = ['text' => !empty($btnNext) ? $btnNext : 'နောက်သို့ ▶️', 'callback_data' => "cat_p_{$nextPage}_{$filterToken}"];
        }

        if (!empty($paginationRow)) {
            $buttons[] = $paginationRow;
        }

        // 🛒 Dynamic Cart Quick Access Bar if items exist in cart
        $cartSummary = $this->getCartSummary($chatId);
        if (($cartSummary['items_count'] ?? 0) > 0) {
            $totalQ = (floor($cartSummary['total_qty']) == $cartSummary['total_qty']) ? (int)$cartSummary['total_qty'] : $cartSummary['total_qty'];
            $grandT = number_format($cartSummary['grand_total']);

            $showNavCheckout = in_array(Setting::get('telegram_bot_show_catalog_nav_checkout', '1'), ['1', 1, true, 'true'], true);
            $showNavCart = in_array(Setting::get('telegram_bot_show_catalog_nav_cart', '1'), ['1', 1, true, 'true'], true);
            $btnNavCheckout = Setting::get('telegram_bot_btn_catalog_nav_checkout', Setting::get('telegram_bot_btn_checkout', '💳 အော်ဒါတင်မည်'));
            $btnNavCart = Setting::get('telegram_bot_btn_catalog_nav_cart', Setting::get('telegram_bot_btn_cart', '🛒 ခြင်းတောင်း'));

            if ($showNavCheckout) {
                $chkLabel = !empty($btnNavCheckout) ? $btnNavCheckout : '💳 အော်ဒါတင်မည်';
                $buttons[] = [
                    ['text' => $chkLabel, 'callback_data' => 'cart_checkout'],
                ];
            }
            if ($showNavCart) {
                $cartLabel = !empty($btnNavCart) ? $btnNavCart : '🛒 ခြင်းတောင်း';
                $buttons[] = [
                    ['text' => $cartLabel, 'callback_data' => 'cart_view'],
                ];
            }
        }

        if ($showNavCats) {
            $buttons[] = [
                ['text' => !empty($btnCats) ? $btnCats : '📂 အမျိုးအစားများ', 'callback_data' => 'nav_categories'],
            ];
        }
        if ($showNavBrands) {
            $buttons[] = [
                ['text' => !empty($btnBrands) ? $btnBrands : '🏷️ တံဆိပ်များ', 'callback_data' => 'nav_brands'],
            ];
        }
        if ($showNavMainMenu) {
            $buttons[] = [
                ['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
            ];
        }
        if ($showNavCancel) {
            $buttons[] = [
                ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
            ];
        }

        // 🛡️ Telegram Inline Buttons Guard (Telegram API strict limit: Max 100 buttons total on an inline keyboard)
        $totalFlatButtons = 0;
        foreach ($buttons as $btnRow) {
            $totalFlatButtons += count($btnRow);
        }

        if ($totalFlatButtons > 95) {
            // Preserve bottom navigation rows (pagination, cart, categories, brands, menu, cancel)
            $navRowCount = 0;
            if (!empty($paginationRow)) $navRowCount++;
            if (($cartSummary['items_count'] ?? 0) > 0) $navRowCount += 2;
            if ($showNavCats) $navRowCount++;
            if ($showNavBrands) $navRowCount++;
            if ($showNavMainMenu) $navRowCount++;
            if ($showNavCancel) $navRowCount++;

            $totalRows = count($buttons);
            $productRows = array_slice($buttons, 0, max(0, $totalRows - $navRowCount));
            $navRows = array_slice($buttons, max(0, $totalRows - $navRowCount));

            $safeProductRows = [];
            $currCount = 0;
            foreach ($navRows as $nr) {
                $currCount += count($nr);
            }
            foreach ($productRows as $pr) {
                if ($currCount + count($pr) <= 95) {
                    $safeProductRows[] = $pr;
                    $currCount += count($pr);
                } else {
                    break;
                }
            }
            $buttons = array_merge($safeProductRows, $navRows);
        }

        // 🛡️ Telegram Message Length Guard (Strict limit: 4096 UTF-8 characters per message)
        $chunks = [];
        if (mb_strlen($text, 'UTF-8') > 3900) {
            $lines = explode("\n", $text);
            $currentChunk = '';
            foreach ($lines as $line) {
                if (mb_strlen($currentChunk . "\n" . $line, 'UTF-8') > 3800) {
                    if (!empty($currentChunk)) {
                        $chunks[] = $currentChunk;
                        $currentChunk = $line;
                    } else {
                        // In the rare event that a single line is > 3800 chars, slice safely with mb_substr
                        $chunks[] = mb_substr($line, 0, 3800, 'UTF-8');
                        $currentChunk = mb_substr($line, 3800, null, 'UTF-8');
                    }
                } else {
                    $currentChunk = empty($currentChunk) ? $line : $currentChunk . "\n" . $line;
                }
            }
            if (!empty($currentChunk)) {
                $chunks[] = $currentChunk;
            }
        } else {
            $chunks = [$text];
        }

        // If multiple chunks exist and $messageIdToEdit was provided, delete old message first
        // because editMessageText cannot edit multiple messages.
        if (count($chunks) > 1 && $messageIdToEdit) {
            $this->deleteMessage($chatId, $messageIdToEdit);
            $messageIdToEdit = null;
        }

        for ($c = 0; $c < count($chunks) - 1; $c++) {
            $this->sendMessage($chatId, $chunks[$c]);
        }

        $finalChunk = end($chunks);
        $replyMarkup = !empty($buttons) ? ['inline_keyboard' => $buttons] : null;

        if ($messageIdToEdit) {
            $res = $this->editMessageText($chatId, $messageIdToEdit, $finalChunk, $replyMarkup);
            if (!empty($res['ok'])) {
                return;
            }
            $this->deleteMessage($chatId, $messageIdToEdit);
        }

        $this->sendMessage($chatId, $finalChunk, $replyMarkup);
    }

    /**
     * Send Product Photo Card with High-Res Image, Real-time Stock, and Markdown Specs
     */
    public function sendProductPhotoCard(int|string $chatId, Product $product, ?string $backCallback = 'nav_all_products'): void
    {
        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
        $stock = (float)StockBalance::where('warehouse_id', $warehouseId)
            ->where('product_id', $product->id)
            ->sum('quantity');

        $effectivePrice = (float)($product->retail_price ?: $product->selling_price);

        $priceFormatted = number_format($effectivePrice);
        $priceText = "*{$priceFormatted} MMK*";

        $stockBadge = $stock > 0 
            ? "✅ လက်ကျန်ရှိပါသည် ({$stock} " . ($product->unit_name ?: 'ခု') . ")"
            : "⚠️ လက်ကျန်ပြတ်လပ်နေပါသည် (Out of Stock)";

        $activeVariants = $product->variants()->where('is_active', true)->orderBy('sort_order', 'asc')->get();
        $hasActiveVariants = $product->has_variants && $activeVariants->isNotEmpty();

        $caption = "🏷️ *{$product->name}*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "📌 *ကုတ်နံပါတ်:*   `{$product->code}`\n"
                 . ($product->category_name ? "📂 *အမျိုးအစား:*   {$product->category_name}\n" : "")
                 . ($product->brand_name ? "🏷️ *တံဆိပ်:*       {$product->brand_name}\n" : "")
                 . "💰 *ဈေးနှုန်း:*     {$priceText}\n"
                 . "📦 *လက်ကျန်:*     {$stockBadge}\n";

        if ($hasActiveVariants) {
            $vNames = $activeVariants->pluck('name')->implode(', ');
            $caption .= "🎨 *ဆိုဒ်များ:*     {$vNames}\n";
        }
        $caption .= "\n";

        if (!empty($product->description)) {
            $caption .= "📝 *အသေးစိတ် အချက်အလက်:*\n{$product->description}\n\n";
        }

        $caption .= "မှာယူလိုပါက အောက်ပါခလုတ်ကို နှိပ်ပါရှင်-";

        $btnOrderNow = Setting::get('telegram_bot_btn_order_now', '🛒 မှာယူမည် (Order Now)');
        $btnSelectVar = Setting::get('telegram_bot_btn_select_variant', '🎨 ဆိုဒ်ရွေး၍ မှာယူမည်');
        $btnBackCatalog = Setting::get('telegram_bot_btn_back_to_catalog', '🔙 ကတ်တလောက်သို့ ပြန်သွားမည်');
        $btnCancel = Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)');

        $showBtnOrderNow = Setting::get('telegram_bot_show_btn_order_now', '1') === '1';
        $showBtnSelectVar = Setting::get('telegram_bot_show_btn_select_variant', '1') === '1';
        $showBtnBackCatalog = Setting::get('telegram_bot_show_btn_back_to_catalog', '1') === '1';
        $showBtnCancel = Setting::get('telegram_bot_show_catalog_nav_cancel', Setting::get('telegram_bot_show_catalog_btn_cancel', '1')) === '1';

        $buttons = [];
        if ($hasActiveVariants) {
            if ($showBtnSelectVar) {
                $buyBtnText = !empty($btnSelectVar) ? $btnSelectVar : '🎨 ဆိုဒ်ရွေး၍ မှာယူမည်';
                $buttons[] = [
                    ['text' => $buyBtnText, 'callback_data' => "buy_{$product->id}"],
                ];
            }
        } elseif ($showBtnOrderNow) {
            $buyBtnText = !empty($btnOrderNow) ? $btnOrderNow : '🛒 မှာယူမည် (Order Now)';
            $buttons[] = [
                ['text' => $buyBtnText, 'callback_data' => "buy_{$product->id}"],
            ];
        }

        $cartSummary = $this->getCartSummary($chatId);
        if (($cartSummary['items_count'] ?? 0) > 0) {
            $totalQ = (floor($cartSummary['total_qty']) == $cartSummary['total_qty']) ? (int)$cartSummary['total_qty'] : $cartSummary['total_qty'];
            $grandT = number_format($cartSummary['grand_total']);

            $showNavCheckout = in_array(Setting::get('telegram_bot_show_catalog_nav_checkout', '1'), ['1', 1, true, 'true'], true);
            $showNavCart = in_array(Setting::get('telegram_bot_show_catalog_nav_cart', '1'), ['1', 1, true, 'true'], true);
            $btnNavCheckout = Setting::get('telegram_bot_btn_catalog_nav_checkout', Setting::get('telegram_bot_btn_checkout', '💳 အော်ဒါတင်မည်'));
            $btnNavCart = Setting::get('telegram_bot_btn_catalog_nav_cart', Setting::get('telegram_bot_btn_cart', '🛒 ခြင်းတောင်း ကြည့်မည်'));

            if ($showNavCheckout) {
                $chkLabel = !empty($btnNavCheckout) ? $btnNavCheckout : '💳 အော်ဒါတင်မည်';
                $buttons[] = [
                    ['text' => $chkLabel, 'callback_data' => 'cart_checkout'],
                ];
            }
            if ($showNavCart) {
                $cartLabel = !empty($btnNavCart) ? $btnNavCart : '🛒 ခြင်းတောင်း ကြည့်မည်';
                $buttons[] = [
                    ['text' => $cartLabel, 'callback_data' => 'cart_view'],
                ];
            }
        }

        if ($showBtnBackCatalog) {
            $buttons[] = [
                ['text' => !empty($btnBackCatalog) ? $btnBackCatalog : '🔙 ကတ်တလောက်သို့ ပြန်သွားမည်', 'callback_data' => $backCallback ?: 'nav_all_products'],
            ];
        }
        if ($showBtnCancel) {
            $buttons[] = [
                ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
            ];
        }

        // Resolve Image Source
        $showProductImage = Setting::get('telegram_bot_show_product_image', '1') === '1';
        $imagePath = $showProductImage
            ? ($product->image_url ?: ($product->primaryImage?->path ?: ($product->images->first()?->path ?? null)))
            : null;

        $photoSent = false;
        if (!empty($imagePath)) {
            $res = $this->sendPhoto($chatId, $imagePath, $caption, !empty($buttons) ? ['inline_keyboard' => $buttons] : null);
            if (!empty($res['ok'])) {
                $photoSent = true;
            }
        }

        // If photo sending failed, disabled, or product has no photo, fallback to formatted message card
        if (!$photoSent) {
            $this->sendMessage($chatId, $caption, !empty($buttons) ? ['inline_keyboard' => $buttons] : null);
        }
    }

    /**
     * Send Variant Selection Menu for Variation Products
     */
    public function sendVariantSelection(int|string $chatId, Product $product, ?int $messageIdToEdit = null): void
    {
        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
        $variants = $product->variants()->where('is_active', true)->orderBy('sort_order', 'asc')->get();

        if ($variants->isEmpty()) {
            $this->initiateQuantitySelection($chatId, $product);
            return;
        }

        $basePrice = (float)($product->retail_price ?: $product->selling_price);

        $varPromptTpl = Setting::get('telegram_bot_select_variant_msg', '');
        $varPrompt = !empty(trim($varPromptTpl))
            ? $this->replacePlaceholders($varPromptTpl, [
                'product_name' => $product->name,
                'price' => number_format($basePrice),
                'product_code' => $product->code,
            ])
            : "✨ ကျေးဇူးပြု၍ လိုချင်သော **ဆိုဒ် / အရွယ်အစား (Variant)** အား အောက်ပါခလုတ်များမှ ရွေးချယ်ပေးပါရှင်-";

        $msg = "👟 *{$product->name}*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "📌 ကုတ်နံပါတ်: `{$product->code}`\n"
             . "💰 အခြေခံရောင်းဈေး: *" . number_format($basePrice) . " MMK*\n\n"
             . "{$varPrompt}";

        $buttons = [];
        $currentRow = [];

        foreach ($variants as $v) {
            $stock = (float)StockBalance::where('warehouse_id', $warehouseId)
                ->where('product_id', $product->id)
                ->where('product_variant_id', $v->id)
                ->sum('quantity');

            $vPrice = (float)($v->selling_price ?: $basePrice);
            $priceBadge = ($vPrice != $basePrice) ? " (" . number_format($vPrice) . " MMK)" : "";

            if ($stock > 0) {
                $btnText = "👟 {$v->name}{$priceBadge} [လက်ကျန်: {$stock}]";
            } else {
                $btnText = "👟 {$v->name} (⚠️ ပြတ်)";
            }

            $buttons[] = [
                [
                    'text' => $btnText,
                    'callback_data' => "var_{$product->id}_{$v->id}",
                ]
            ];
        }

        $btnBackCatalog = Setting::get('telegram_bot_btn_back_to_catalog', '🔙 ပစ္စည်းစာရင်းသို့');
        $btnCancel = Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)');

        $showBtnBackCatalog = Setting::get('telegram_bot_show_btn_back_to_catalog', '1') === '1';
        $showBtnCancel = Setting::get('telegram_bot_show_catalog_nav_cancel', Setting::get('telegram_bot_show_catalog_btn_cancel', '1')) === '1';

        if ($showBtnBackCatalog) {
            $buttons[] = [
                ['text' => !empty($btnBackCatalog) ? $btnBackCatalog : '🔙 ပစ္စည်းစာရင်းသို့', 'callback_data' => 'nav_all_products'],
            ];
        }
        if ($showBtnCancel) {
            $buttons[] = [
                ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
            ];
        }

        if ($messageIdToEdit) {
            $res = $this->editOrSendMessage($chatId, $messageIdToEdit, $msg, ['inline_keyboard' => $buttons]);
            if (!empty($res['ok'])) {
                if (!empty($res['result']['message_id'])) {
                    $existingSession = Cache::get("tg_session_{$chatId}") ?? [];
                    $existingSession['last_prompt_msg_id'] = $res['result']['message_id'];
                    Cache::put("tg_session_{$chatId}", $existingSession, now()->addHours(2));
                }
                return;
            }
        }

        $imagePath = !empty($product->image_url) 
            ? $product->image_url 
            : ($product->primaryImage?->path ?: ($product->images?->first()?->path ?? null));

        if (!empty($imagePath)) {
            $res = $this->sendPhoto($chatId, $imagePath, $msg, ['inline_keyboard' => $buttons]);
            if (!empty($res['ok'])) {
                if (!empty($res['result']['message_id'])) {
                    $existingSession = Cache::get("tg_session_{$chatId}") ?? [];
                    $existingSession['last_prompt_msg_id'] = $res['result']['message_id'];
                    Cache::put("tg_session_{$chatId}", $existingSession, now()->addHours(2));
                }
                return;
            }
        }

        $res = $this->sendMessage($chatId, $msg, ['inline_keyboard' => $buttons]);
        if (!empty($res['ok']) && !empty($res['result']['message_id'])) {
            $existingSession = Cache::get("tg_session_{$chatId}") ?? [];
            $existingSession['last_prompt_msg_id'] = $res['result']['message_id'];
            Cache::put("tg_session_{$chatId}", $existingSession, now()->addHours(2));
        }
    }

    /**
     * Parse and normalize quantity from customer text input.
     * Supports English (0-9) and Myanmar unicode digits (၀-၉),
     * and handles common suffixes like 'ခု', 'pcs', 'packs', 'x', etc.
     */
    public function parseQuantity(string $text): int
    {
        $myanmarDigits = ['၀', '၁', '၂', '၃', '၄', '၅', '၆', '၇', '၈', '၉'];
        $arabicDigits  = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $normalized = str_replace($myanmarDigits, $arabicDigits, trim($text));

        if (preg_match('/\d+/', $normalized, $matches)) {
            return (int)$matches[0];
        }

        return 0;
    }

    /**
     * Format Cart Updated Confirmation with full itemized breakdown
     */
    public function formatCartUpdatedMessage(array $cartRes, string $addedItemTitle, float|int $addedQty, string $unitName): string
    {
        $cart = $cartRes['cart'] ?? [];
        $totalFormatted = number_format($cartRes['grand_total'] ?? 0);
        $totalQty = $cartRes['total_qty'] ?? 0;
        $totalQtyDisplay = (floor($totalQty) == $totalQty) ? (int)$totalQty : $totalQty;
        $itemsCount = $cartRes['items_count'] ?? count($cart);

        $customHeader = trim(Setting::get('telegram_bot_cart_updated_header', '🛒 *ခြင်းတောင်း အပ်ဒိတ် လုပ်လိုက်ပါပြီရှင်!*'));
        if (empty($customHeader)) {
            $customHeader = "🛒 *ခြင်းတောင်း အပ်ဒိတ် လုပ်လိုက်ပါပြီရှင်!*";
        }

        $itemAddedTpl = trim(Setting::get('telegram_bot_cart_item_added_tpl', '✅ *{product_name}* x {quantity} {unit} အား ခြင်းတောင်းထဲသို့ ထည့်သွင်းပြီးပါပြီ။'));
        if (empty($itemAddedTpl)) {
            $itemAddedTpl = "✅ *{product_name}* x {quantity} {unit} အား ခြင်းတောင်းထဲသို့ ထည့်သွင်းပြီးပါပြီ။";
        }
        $itemAddedNotice = str_replace(
            ['{product_name}', '{item_title}', '{quantity}', '{qty}', '{unit}'],
            [$addedItemTitle, $addedItemTitle, $addedQty, $addedQty, $unitName],
            $itemAddedTpl
        );

        $cartItemsHeading = trim(Setting::get('telegram_bot_cart_items_heading', '🛍️ *လူကြီးမင်း ခြင်းတောင်းထဲရှိ ပစ္စည်းများ:*'));
        if (empty($cartItemsHeading)) {
            $cartItemsHeading = "🛍️ *လူကြီးမင်း ခြင်းတောင်းထဲရှိ ပစ္စည်းများ:*";
        }

        $footerPrompt = trim(Setting::get('telegram_bot_cart_updated_footer_prompt', "အော်ဒါတင်လိုပါက '💳 အော်ဒါတင်မည်' ကို နှိပ်ပါ (သို့မဟုတ်) အခြားပစ္စည်းများ ဆက်လက်ရွေးချယ်နိုင်ပါသည်-"));

        $baseHeader = "{$customHeader}\n"
                    . "━━━━━━━━━━━━━━━━━━━━━\n"
                    . "{$itemAddedNotice}\n\n"
                    . "{$cartItemsHeading}\n\n";

        $baseFooter = "\n━━━━━━━━━━━━━━━━━━━━━\n"
                    . "📦 ပစ္စည်း စုစုပေါင်း:   *{$totalQtyDisplay} ခု* ({$itemsCount} မျိုး)\n"
                    . "💰 စုစုပေါင်း ကျသင့်ငွေ:  *{$totalFormatted} MMK*\n"
                    . "━━━━━━━━━━━━━━━━━━━━━\n\n";

        if (!empty($footerPrompt)) {
            $baseFooter .= "{$footerPrompt}";
        }

        $maxAllowedCaptionLen = 1000;
        $availableForItems = $maxAllowedCaptionLen - mb_strlen($baseHeader) - mb_strlen($baseFooter);

        $itemLines = [];
        $currentLen = 0;
        $idx = 1;

        foreach ($cart as $it) {
            $title = !empty($it['variant_name']) ? "{$it['product_name']} ({$it['variant_name']})" : $it['product_name'];
            $q = (floor($it['quantity']) == $it['quantity']) ? (int)$it['quantity'] : $it['quantity'];
            $u = $it['unit_name'] ?? 'ခု';
            $p = number_format($it['price'] ?? 0);
            $sub = number_format($it['subtotal'] ?? 0);

            $line = "{$idx}️⃣ *{$title}*\n"
                  . "   ▫️ {$q} {$u} × {$p}  =  *{$sub} MMK*";

            $lineLen = mb_strlen($line) + 2;
            if (($currentLen + $lineLen + 60) > $availableForItems && $idx < count($cart)) {
                $omitted = count($cart) - $idx + 1;
                $itemLines[] = "➕ ... နှင့် အခြားပစ္စည်း *{$omitted} မျိုး* (ခြင်းတောင်းထဲတွင် အပြည့်အစုံ ကြည့်ရှုနိုင်ပါသည်)";
                break;
            }

            $itemLines[] = $line;
            $currentLen += $lineLen;
            $idx++;
        }

        $itemsBreakdown = implode("\n\n", $itemLines);

        return $baseHeader . $itemsBreakdown . $baseFooter;
    }

    /**
     * Initiate Quantity Selection step for Single Product or chosen Variant
     */
    public function initiateQuantitySelection(
        int|string $chatId,
        Product $product,
        ?ProductVariant $variant = null,
        ?int $messageIdToEdit = null
    ): void {
        $effectivePrice = $variant
            ? (float)($variant->selling_price ?: ($product->retail_price ?: $product->selling_price))
            : (float)($product->retail_price ?: $product->selling_price);

        $displayName = $variant ? "{$product->name} ({$variant->name})" : $product->name;

        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
        $stockQuery = StockBalance::where('warehouse_id', $warehouseId)->where('product_id', $product->id);
        if ($variant) {
            $stockQuery->where('product_variant_id', $variant->id);
        }
        $stock = (float)$stockQuery->sum('quantity');

        $existingSession = Cache::get("tg_session_{$chatId}");
        if (!empty($existingSession['last_prompt_msg_id']) && !$messageIdToEdit) {
            $this->deleteMessage($chatId, (int)$existingSession['last_prompt_msg_id']);
        }

        $session = [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'variant_id' => $variant?->id,
            'variant_name' => $variant?->name,
            'variant_sku' => $variant?->sku ?: ($product->code . ($variant ? '-' . $variant->name : '')),
            'price' => $effectivePrice,
            'step' => 'waiting_qty',
        ];
        Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

        $qtyPromptTpl = Setting::get('telegram_bot_select_qty_msg', '');
        if (!empty(trim($qtyPromptTpl))) {
            $msg = $this->replacePlaceholders($qtyPromptTpl, [
                'product_name' => $displayName,
                'price' => number_format($effectivePrice),
                'stock' => (string)$stock,
                'unit' => $product->unit_name ?: 'ခု',
            ]);
        } else {
            $msg = "🛍️ *{$displayName}*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "💰 *ဈေးနှုန်း:*  *" . number_format($effectivePrice) . " MMK*\n"
                 . "📦 *လက်ကျန်:*  `{$stock}` " . ($product->unit_name ?: 'ခု') . "\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n\n"
                 . "👇 မှာယူလိုသော အရေအတွက်ကို အောက်ပါခလုတ်များမှ ရွေးချယ်ပါ (သို့မဟုတ်) လိုချင်သော အရေအတွက်ကို ကိန်းဂဏန်း တိုက်ရိုက် စာရိုက်ပို့ပေးနိုင်ပါသည်ရှင်-\n"
                 . "(💡 ဥပမာ- `10` သို့မဟုတ် `၁၀`)";
        }

        $btnBackVar = Setting::get('telegram_bot_btn_back_variant', '🔙 အခြားဆိုဒ် ပြန်ရွေးမည်');
        $btnBackCatalog = Setting::get('telegram_bot_btn_back_to_catalog', '🔙 ပစ္စည်းစာရင်းသို့');
        $btnCancel = Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)');

        $btnQty1 = Setting::get('telegram_bot_btn_qty_1', '1 ခု');
        $btnQty2 = Setting::get('telegram_bot_btn_qty_2', '2 ခု');
        $btnQty3 = Setting::get('telegram_bot_btn_qty_3', '3 ခု');
        $btnQty5 = Setting::get('telegram_bot_btn_qty_5', '5 ခု');
        $btnCustomQty = Setting::get('telegram_bot_btn_custom_qty', '🔢 အခြားအရေအတွက်');

        $showQty1 = Setting::get('telegram_bot_show_btn_qty_1', '1') === '1';
        $showQty2 = Setting::get('telegram_bot_show_btn_qty_2', '1') === '1';
        $showQty3 = Setting::get('telegram_bot_show_btn_qty_3', '1') === '1';
        $showQty5 = Setting::get('telegram_bot_show_btn_qty_5', '1') === '1';
        $showCustomQty = Setting::get('telegram_bot_show_btn_custom_qty', '1') === '1';
        $showBackVar = Setting::get('telegram_bot_show_btn_back_variant', '1') === '1';
        $showBackCatalog = Setting::get('telegram_bot_show_btn_back_to_catalog', '1') === '1';
        $showCancel = Setting::get('telegram_bot_show_catalog_nav_cancel', Setting::get('telegram_bot_show_catalog_btn_cancel', '1')) === '1';

        $inlineKeyboard = [];

        $qtyRow = [];
        if ($showQty1) {
            $qtyRow[] = ['text' => !empty($btnQty1) ? $btnQty1 : '1 ခု', 'callback_data' => "qty_{$product->id}_1"];
        }
        if ($showQty2) {
            $qtyRow[] = ['text' => !empty($btnQty2) ? $btnQty2 : '2 ခု', 'callback_data' => "qty_{$product->id}_2"];
        }
        if ($showQty3) {
            $qtyRow[] = ['text' => !empty($btnQty3) ? $btnQty3 : '3 ခု', 'callback_data' => "qty_{$product->id}_3"];
        }
        if ($showQty5) {
            $qtyRow[] = ['text' => !empty($btnQty5) ? $btnQty5 : '5 ခု', 'callback_data' => "qty_{$product->id}_5"];
        }
        if (!empty($qtyRow)) {
            $inlineKeyboard[] = $qtyRow;
        }

        if ($showCustomQty) {
            $inlineKeyboard[] = [
                ['text' => !empty($btnCustomQty) ? $btnCustomQty : '🔢 အခြားအရေအတွက်', 'callback_data' => "ask_qty_{$product->id}" . ($variant ? "_{$variant->id}" : "")],
            ];
        }

        if ($variant || $product->has_variants) {
            if ($showBackVar) {
                $inlineKeyboard[] = [
                    ['text' => !empty($btnBackVar) ? $btnBackVar : '🔙 အခြားဆိုဒ် ပြန်ရွေးမည်', 'callback_data' => "buy_{$product->id}"],
                ];
            }
        } elseif ($showBackCatalog) {
            $inlineKeyboard[] = [
                ['text' => !empty($btnBackCatalog) ? $btnBackCatalog : '🔙 ပစ္စည်းစာရင်းသို့', 'callback_data' => 'nav_all_products'],
            ];
        }

        if ($showCancel) {
            $inlineKeyboard[] = [
                ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
            ];
        }

        // Resolve Image Source (Variant specific -> Product main -> Primary image -> First image)
        $imagePath = null;
        if ($variant && !empty($variant->image_url)) {
            $imagePath = $variant->image_url;
        } elseif (!empty($product->image_url)) {
            $imagePath = $product->image_url;
        } else {
            $imagePath = $product->primaryImage?->path ?: ($product->images?->first()?->path ?? null);
        }

        $res = null;
        if ($messageIdToEdit) {
            $res = $this->editOrSendMessage($chatId, $messageIdToEdit, $msg, ['inline_keyboard' => $inlineKeyboard]);
        } else {
            if (!empty($imagePath)) {
                $res = $this->sendPhoto($chatId, $imagePath, $msg, ['inline_keyboard' => $inlineKeyboard]);
            }
            if (empty($res['ok'])) {
                $res = $this->sendMessage($chatId, $msg, ['inline_keyboard' => $inlineKeyboard]);
            }
        }

        if (!empty($res['ok']) && !empty($res['result']['message_id'])) {
            $session['last_prompt_msg_id'] = $res['result']['message_id'];
            Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));
        }
    }

    /**
     * Send Direct Product Prompt when searched by code (Delegates to sendProductPhotoCard)
     */
    public function sendDirectProductPrompt(int|string $chatId, Product $product): void
    {
        $this->sendProductPhotoCard($chatId, $product, 'catalog_main');
    }

    /**
     * Handle Inline Callback Queries (Button clicks)
     */
    protected function handleCallbackQuery(array $callbackQuery): void
    {
        $id = $callbackQuery['id'];
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = $callbackQuery['data'] ?? '';
        $userName = trim(($callbackQuery['from']['first_name'] ?? '') . ' ' . ($callbackQuery['from']['last_name'] ?? ''));
        $usernameTag = !empty($callbackQuery['from']['username']) ? '@' . $callbackQuery['from']['username'] : '';

        if (!$chatId) return;

        // ⚡ Sub-100ms Immediate Acknowledgment for navigation & menus to stop client spinner instantly
        $isInstantNav = str_starts_with($data, 'nav_') 
                     || str_starts_with($data, 'f_cat_') 
                     || str_starts_with($data, 'f_subcat_')
                     || str_starts_with($data, 'f_brand_')
                     || str_starts_with($data, 'f_bc_')
                     || str_starts_with($data, 'show_cat_')
                     || str_starts_with($data, 'show_brand_')
                     || str_starts_with($data, 'cat_p_')
                     || str_starts_with($data, 'brand_p_')
                     || str_starts_with($data, 'p_pg_')
                     || $data === 'catalog_main'
                     || $data === 'nav_home'
                     || $data === 'nav_all_products'
                     || $data === 'nav_categories'
                     || $data === 'nav_brands'
                     || $data === 'nav_search'
                     || $data === 'noop';

        if ($isInstantNav) {
            $this->answerCallbackQuery($id);
        }

        // 🏠 Breadcrumb: Universal Home Menu Handler
        if ($data === 'nav_home') {
            Cache::forget("tg_session_{$chatId}");
            if (!$isInstantNav) {
                $this->answerCallbackQuery($id);
            }
            $this->sendWelcomeMenu($chatId, $userName);
            return;
        }

        // 🤖 Resume AI Mode via Callback
        if ($data === 'resume_ai_mode') {
            if ($this->aiAgentService->getOperatingMode() === 'standard') {
                $this->answerCallbackQuery($id, 'ရိုးရိုး မီနူးစနစ် အသုံးပြုထားပါသည်');
                $this->sendMessage($chatId, "📱 *ဤဘော့တ်သည် ရိုးရိုး မီနူးခလုတ်စနစ် (Standard Mode) ဖြင့် လည်ပတ်နေပါသည်ရှင်။*\n\nအောက်ပါ မီနူးခလုတ်များမှတဆင့် ကုန်ပစ္စည်းများကို အဆင်ပြေစွာ ရွေးချယ်မှာယူနိုင်ပါသည်ရှင်။ 🙏", $this->getMainKeyboard($chatId));
                return;
            }
            $this->aiAgentService->resumeAiForChat($chatId);
            $this->answerCallbackQuery($id, 'AI အရောင်းလက်ထောက်ကို ပြန်လည်ဖွင့်လိုက်ပါပြီ');
            $this->sendMessage($chatId, "🤖 *AI အရောင်းလက်ထောက် စနစ်ကို ပြန်လည်ဖွင့်လိုက်ပါပြီရှင်။*\n\nမေးမြန်းလိုသည့် ပစ္စည်းအမည်၊ စျေးနှုန်း သို့မဟုတ် သိရှိလိုသည်များကို စာရိုက်၍ မေးမြန်းနိုင်ပါပြီရှင် 🙏");
            return;
        }

        // 👨‍💼 Contact Human Staff Callback
        if ($data === 'ask_human' || $data === 'contact_human_support') {
            $this->aiAgentService->pauseAiForChat($chatId, 120);
            BotChatMessage::where('telegram_chat_id', (string)$chatId)->latest('id')->update(['needs_human' => true]);
            $this->notifyAdminCustomerNeedsHelp($chatId, $userName, $usernameTag, "Customer clicked 'Talk to Human Staff'");
            $this->answerCallbackQuery($id, 'ဆိုင်ဝန်ထမ်းထံ အကြောင်းကြားထားပါသည်');
            $staffNotice = ($this->aiAgentService->getOperatingMode() === 'standard')
                ? "👨‍💼 *ဆိုင်ဝန်ထမ်းထံသို့ အကြောင်းကြားထားပါသည်ရှင်။*\n\nမကြာမီ ဝန်ထမ်းမှ ဝင်ရောက်စာပြန်ပေးပါမည်ရှင်။ 🙏"
                : "👨‍💼 *ဆိုင်ဝန်ထမ်းထံသို့ အကြောင်းကြားထားပါသည်ရှင်။*\n\nမကြာမီ ဝန်ထမ်းမှ ဝင်ရောက်စာပြန်ပေးပါမည်ရှင်။\n(AI လက်ထောက်နှင့် ပြန်ပြောလိုပါက /ai ဟု ရိုက်ပို့နိုင်ပါသည်)";
            $this->sendMessage($chatId, $staffNotice);
            return;
        }

        if ($data === 'cancel') {
            Cache::forget("tg_session_{$chatId}");
            $this->answerCallbackQuery($id, 'အော်ဒါအား ပယ်ဖျက်လိုက်ပါပြီ');
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            if ($messageId) {
                $this->deleteMessage($chatId, $messageId);
            }
            $cancelMsg = $this->formatCancelledMessage($userName);
            $this->sendMessage($chatId, $cancelMsg, $this->getMainKeyboard($chatId));
            return;
        }

        if ($data === 'catalog_main') {
            $this->answerCallbackQuery($id);
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            if (Setting::get('telegram_bot_direct_categories', '0') === '1') {
                $this->sendCategoryFilterMenu($chatId, $messageId);
            } else {
                $this->sendCatalogMenu($chatId, $messageId);
            }
            return;
        }

        if ($data === 'noop') {
            $this->answerCallbackQuery($id);
            return;
        }

        // 🔄 1-Click Reorder Action (Disabled)
        if (str_starts_with($data, 'reorder_')) {
            $this->answerCallbackQuery($id, 'ဤလုပ်ဆောင်ချက်ကို ပိတ်ထားပါသည်');
            return;
        }

        // 🛒 Cart Actions
        if ($data === 'cart_view' || $data === 'view_cart') {
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->sendCartView($chatId, $userName, $messageId, false, null, $id);
            return;
        }

        if ($data === 'cart_checkout') {
            $this->answerCallbackQuery($id);
            $this->initiateCartCheckout($chatId, $userName);
            return;
        }

        if ($data === 'cart_clear') {
            $this->clearCart($chatId);
            $this->answerCallbackQuery($id, 'ခြင်းတောင်းကို ရှင်းထုတ်လိုက်ပါပြီ');
            $emptyMsgTpl = Setting::get('telegram_bot_cart_cleared_msg', "🗑️ သင်၏ ခြင်းတောင်းကို ရှင်းထုတ်ပြီးပါပြီရှင်။\n\nပစ္စည်းများ ပြန်လည်ရွေးချယ်ရန် '🛍️ ကုန်ပစ္စည်းများ' ကို နှိပ်ပါရှင်။ 🙏");
            $emptyMsg = !empty(trim($emptyMsgTpl))
                ? $this->replacePlaceholders($emptyMsgTpl, ['customer_name' => $userName, 'user_name' => $userName])
                : "🗑️ သင်၏ ခြင်းတောင်းကို ရှင်းထုတ်ပြီးပါပြီရှင်။\n\nပစ္စည်းများ ပြန်လည်ရွေးချယ်ရန် '🛍️ ကုန်ပစ္စည်းများ' ကို နှိပ်ပါရှင်။ 🙏";
            $messageId = $callbackQuery['message']['message_id'] ?? null;

            if ($messageId) {
                $this->deleteMessage($chatId, $messageId);
            }
            $this->sendMessage($chatId, $emptyMsg, $this->getMainKeyboard($chatId));
            return;
        }

        if ($data === 'cart_edit') {
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->sendCartView($chatId, $userName, $messageId, true, null, $id);
            return;
        }

        if ($data === 'cart_done') {
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->sendCartView($chatId, $userName, $messageId, false, null, $id);
            return;
        }

        if (str_starts_with($data, 'cart_inc_')) {
            $itemKey = str_replace('cart_inc_', '', $data);
            $cart = $this->getCart($chatId);
            if (isset($cart[$itemKey])) {
                $item = $cart[$itemKey];
                $prodId = (int)$item['product_id'];
                $varId = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
                $allowOutOfStock = Setting::get('telegram_bot_allow_out_of_stock_orders', '0') === '1';

                $currentQty = (float)$item['quantity'];
                $newQty = $currentQty + 1;

                if (!$allowOutOfStock) {
                    $cacheKey = "stock_bal_{$warehouseId}_{$prodId}_" . ($varId ?: '0');
                    $availableStock = (float)Cache::remember($cacheKey, 15, function () use ($warehouseId, $prodId, $varId) {
                        $stockQ = StockBalance::where('warehouse_id', $warehouseId)->where('product_id', $prodId);
                        if ($varId) {
                            $stockQ->where('product_variant_id', $varId);
                        }
                        return $stockQ->sum('quantity');
                    });
                    if ($newQty > $availableStock) {
                        $this->answerCallbackQuery($id, '⚠️ လက်ကျန်ပစ္စည်း ထပ်မံမရရှိနိုင်တော့ပါရှင်', true);
                        return;
                    }
                }

                $cart[$itemKey]['quantity'] = $newQty;
                $cart[$itemKey]['subtotal'] = $newQty * (float)$item['price'];
                $this->saveCart($chatId, $cart);

                // Instant In-App Top Toast Banner (Disabled)
                $showToast = Setting::get('telegram_bot_cart_show_toast', '0') === '1';
                $toastInc = Setting::get('telegram_bot_cart_toast_inc', 'အရေအတွက် တိုးလိုက်ပါသည် (+1)');
                $toastText = $showToast ? $toastInc : null;

                $showFlash = Setting::get('telegram_bot_cart_show_flash_notice', '0') === '1';
                $flashNotice = ($showFlash && $showToast) ? "⚡ *{$toastInc}*" : null;

                $messageId = $callbackQuery['message']['message_id'] ?? null;
                $this->sendCartView($chatId, $userName, $messageId, true, $flashNotice, $id, $toastText);
                return;
            }
            $this->answerCallbackQuery($id);
            return;
        }

        if (str_starts_with($data, 'cart_dec_')) {
            $itemKey = str_replace('cart_dec_', '', $data);
            $cart = $this->getCart($chatId);
            if (isset($cart[$itemKey])) {
                $currentQty = (float)$cart[$itemKey]['quantity'];
                $showToast = Setting::get('telegram_bot_cart_show_toast', '0') === '1';
                $toastDec = Setting::get('telegram_bot_cart_toast_dec', 'အရေအတွက် လျှော့ချလိုက်ပါသည် (-1)');
                $toastDel = Setting::get('telegram_bot_cart_toast_del', 'ပစ္စည်းအား ခြင်းတောင်းမှ ဖယ်ရှားလိုက်ပါပြီ');
                $showFlash = Setting::get('telegram_bot_cart_show_flash_notice', '0') === '1';

                if ($currentQty > 1) {
                    $newQty = $currentQty - 1;
                    $cart[$itemKey]['quantity'] = $newQty;
                    $cart[$itemKey]['subtotal'] = $newQty * (float)$cart[$itemKey]['price'];
                    $this->saveCart($chatId, $cart);
                    $toastText = $showToast ? $toastDec : null;
                    $flashNotice = ($showFlash && $showToast) ? "⚡ *{$toastDec}*" : null;
                } else {
                    unset($cart[$itemKey]);
                    $this->saveCart($chatId, $cart);
                    $toastText = $showToast ? $toastDel : null;
                    $flashNotice = ($showFlash && $showToast) ? "🗑️ *{$toastDel}*" : null;
                }
                $messageId = $callbackQuery['message']['message_id'] ?? null;
                $this->sendCartView($chatId, $userName, $messageId, true, $flashNotice, $id, $toastText);
                return;
            }
            $this->answerCallbackQuery($id);
            return;
        }

        if (str_starts_with($data, 'cart_del_') || str_starts_with($data, 'cart_rem_')) {
            $itemKey = str_starts_with($data, 'cart_del_')
                ? str_replace('cart_del_', '', $data)
                : str_replace('cart_rem_', '', $data);
            $this->removeFromCart($chatId, $itemKey);
            $showToast = Setting::get('telegram_bot_cart_show_toast', '0') === '1';
            $toastDel = Setting::get('telegram_bot_cart_toast_del', 'ပစ္စည်းအား ခြင်းတောင်းမှ ဖယ်ရှားလိုက်ပါပြီ');
            $showFlash = Setting::get('telegram_bot_cart_show_flash_notice', '0') === '1';
            $toastText = $showToast ? $toastDel : null;
            $flashNotice = ($showFlash && $showToast) ? "🗑️ *{$toastDel}*" : null;
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->sendCartView($chatId, $userName, $messageId, true, $flashNotice, $id, $toastText);
            return;
        }

        // 🚚 Delivery Method Selection
        if (str_starts_with($data, 'deliv_') && !str_starts_with($data, 'deliv_tsp_') && !str_starts_with($data, 'deliv_reg_')) {
            $this->answerCallbackQuery($id);
            $session = Cache::get("tg_session_{$chatId}");
            if (!$session || empty($session['step'])) {
                $this->sendMessage($chatId, "⚠️ သက်တမ်းကုန်သွားပါပြီရှင်။ /start ဖြင့် အစမှ ပြန်လည်စတင်ပေးပါ။", $this->getMainKeyboard($chatId));
                return;
            }

            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $pickupFee = (float)Setting::get('telegram_bot_delivery_store_pickup_fee', 0);
            $expressFee = (float)Setting::get('telegram_bot_delivery_express_fee', 3000);

            if ($data === 'deliv_pickup') {
                $session['shipping_fee'] = $pickupFee;
                $session['delivery_method'] = 'Store Pickup (ဆိုင်လာယူ)';
                $session['delivery_township'] = 'ဆိုင်တွင် လာယူမည်';
                $session['step'] = 'waiting_payment';
                Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));
                $this->sendPaymentSelection($chatId, $session, $messageId);
                return;
            } elseif ($data === 'deliv_city') {
                $activeRegions = DeliveryRegion::where('is_active', true)->withCount('activeTownships')->orderBy('sort_order')->get();
                if ($activeRegions->count() > 1) {
                    $this->sendRegionSelection($chatId, $session, $messageId);
                    return;
                } elseif ($activeRegions->count() === 1) {
                    $this->sendTownshipSelection($chatId, $session, $messageId, $activeRegions->first()->id);
                    return;
                } else {
                    $this->sendTownshipSelection($chatId, $session, $messageId);
                    return;
                }
            }
        }

        // 🏙️ Region Selection Callback
        if (str_starts_with($data, 'deliv_reg_')) {
            $this->answerCallbackQuery($id);
            $session = Cache::get("tg_session_{$chatId}");
            if (!$session || empty($session['step'])) {
                $this->sendMessage($chatId, "⚠️ သက်တမ်းကုန်သွားပါပြီရှင်။ /start ဖြင့် အစမှ ပြန်လည်စတင်ပေးပါ။", $this->getMainKeyboard($chatId));
                return;
            }

            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $regionId = (int)str_replace('deliv_reg_', '', $data);
            $this->sendTownshipSelection($chatId, $session, $messageId, $regionId);
            return;
        }

        // 🛵 Township Delivery Matrix Selected
        if (str_starts_with($data, 'deliv_tsp_')) {
            $this->answerCallbackQuery($id);
            $session = Cache::get("tg_session_{$chatId}");
            if (!$session || empty($session['step'])) {
                $this->sendMessage($chatId, "⚠️ သက်တမ်းကုန်သွားပါပြီရှင်။ /start ဖြင့် အစမှ ပြန်လည်စတင်ပေးပါ။", $this->getMainKeyboard($chatId));
                return;
            }

            $messageId = $callbackQuery['message']['message_id'] ?? null;

            if ($data === 'deliv_tsp_back') {
                $this->sendDeliveryMethodSelection($chatId, $session, $messageId);
                return;
            }

            if ($data === 'deliv_tsp_regions') {
                $this->sendRegionSelection($chatId, $session, $messageId);
                return;
            }

            $townshipId = (int)str_replace('deliv_tsp_', '', $data);
            $township = DeliveryTownship::with('region')->find($townshipId);

            $itemsTotal = !empty($session['is_cart']) && !empty($session['items'])
                ? (float)collect($session['items'])->sum('subtotal')
                : (float)($session['price'] ?? 0) * (float)($session['quantity'] ?? 1);

            $fee = 2000.0;
            $townshipName = 'City Delivery';
            $regionName = '';

            if ($township) {
                $fee = (float)$township->delivery_fee;
                if ($township->free_delivery_threshold && $itemsTotal >= (float)$township->free_delivery_threshold) {
                    $fee = 0.0;
                }
                $townshipName = $township->name_mm ?? $township->name;
                $regionName = $township->region ? ($township->region->name_mm ?? $township->region->name) : '';
                $session['delivery_region_id'] = $township->delivery_region_id;
                $session['delivery_township_id'] = $township->id;
            }

            $displayName = !empty($regionName) ? "{$regionName} - {$townshipName}" : $townshipName;
            $session['shipping_fee'] = $fee;
            $session['delivery_method'] = "City Delivery ({$displayName})";
            $session['delivery_township'] = $displayName;
            $session['step'] = 'waiting_payment';
            Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

            $this->sendPaymentSelection($chatId, $session, $messageId);
            return;
        }

        // 👨‍💼 Admin Interactive Telegram Actions
        if (str_starts_with($data, 'adm_items_')) {
            $saleId = (int)str_replace('adm_items_', '', $data);
            $this->handleAdminViewAllItemsCallback($id, $chatId, $saleId, $callbackQuery);
            return;
        }

        if (str_starts_with($data, 'adm_app_')) {
            $saleId = (int)str_replace('adm_app_', '', $data);
            $this->handleAdminApproveCallback($id, $chatId, $saleId, $callbackQuery);
            return;
        }

        if (str_starts_with($data, 'adm_rej_')) {
            $saleId = (int)str_replace('adm_rej_', '', $data);
            $this->handleAdminRejectCallback($id, $chatId, $saleId, $callbackQuery);
            return;
        }

        if ($data === 'nav_all_products') {
            $this->answerCallbackQuery($id);
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $session = Cache::get("tg_session_{$chatId}");
            if (!empty($session['step']) && $session['step'] === 'waiting_qty') {
                Cache::forget("tg_session_{$chatId}");
                if ($messageId) {
                    $this->deleteMessage($chatId, $messageId);
                    $messageId = null;
                }
            }
            $this->sendCatalog($chatId, page: 1, messageIdToEdit: $messageId);
            return;
        }

        if (str_starts_with($data, 'cat_p_')) {
            $this->answerCallbackQuery($id);
            $parts = explode('_', $data);
            $page = max(1, (int)($parts[2] ?? 1));
            $catId = !empty($parts[3]) && (int)$parts[3] > 0 ? (int)$parts[3] : null;
            $subId = !empty($parts[4]) && (int)$parts[4] > 0 ? (int)$parts[4] : null;
            $brandId = !empty($parts[5]) && (int)$parts[5] > 0 ? (int)$parts[5] : null;

            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->sendCatalog(
                chatId: $chatId,
                page: $page,
                categoryId: $catId,
                subCategoryId: $subId,
                brandId: $brandId,
                messageIdToEdit: $messageId
            );
            return;
        }

        if (str_starts_with($data, 'view_')) {
            $productId = (int)str_replace('view_', '', $data);
            $product = Product::find($productId);
            if (!$product) {
                $this->answerCallbackQuery($id, 'ကုန်ပစ္စည်း ရှာမတွေ့ပါ', true);
                return;
            }
            $this->answerCallbackQuery($id);
            $this->sendProductPhotoCard($chatId, $product);
            return;
        }

        if ($data === 'nav_categories') {
            $this->answerCallbackQuery($id);
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->sendCategoryFilterMenu($chatId, $messageId);
            return;
        }

        if ($data === 'nav_brands') {
            $this->answerCallbackQuery($id);
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->sendBrandFilterMenu($chatId, $messageId);
            return;
        }

        if ($data === 'nav_search') {
            $this->answerCallbackQuery($id);
            Cache::put("tg_session_{$chatId}", ['step' => 'waiting_search'], now()->addMinutes(30));
            $searchTpl = Setting::get('telegram_bot_search_prompt_msg', '');
            $searchPrompt = !empty(trim($searchTpl))
                ? $this->replacePlaceholders($searchTpl, ['customer_name' => $userName, 'user_name' => $userName])
                : "🔎 ရှာဖွေလိုသော ကုန်ပစ္စည်း *အမည်* သို့မဟုတ် *ကုတ်နံပါတ် (Product Code / ID)* ရိုက်ထည့်ပေးပါရှင်-\n(ဥပမာ- `PROD-001` သို့မဟုတ် `ရှားစောင်း`)";
            $this->sendMessage($chatId, $searchPrompt);
            return;
        }

        // Category Filter Selected (Hierarchical Category -> Sub-Category Drilldown)
        if (str_starts_with($data, 'f_cat_')) {
            $catId = (int)str_replace('f_cat_', '', $data);
            $category = ProductCategory::find($catId);
            $this->answerCallbackQuery($id);
            $messageId = $callbackQuery['message']['message_id'] ?? null;

            $catDrilldown = Setting::get('telegram_bot_category_drilldown_sub', '1') === '1';

            if ($catDrilldown && $category) {
                // Check if subcategories exist with bot-available products
                try {
                    $subcategories = Cache::remember("tg_bot_subcats_cat_{$catId}_v2", 300, function () use ($catId) {
                        return SubCategory::where('parent_category_id', $catId)->whereHas('products', function ($q) {
                            $q->where('is_bot_available', true)->where('is_active', true);
                        })->select('id', 'name')->get()->map(fn($s) => ['id' => $s->id, 'name' => $s->name])->all();
                    });
                    if ($subcategories instanceof \__PHP_Incomplete_Class || (!is_array($subcategories) && !($subcategories instanceof \Illuminate\Support\Collection))) {
                        Cache::forget("tg_bot_subcats_cat_{$catId}_v2");
                        Cache::forget("tg_bot_subcats_cat_{$catId}");
                        $subcategories = SubCategory::where('parent_category_id', $catId)->whereHas('products', function ($q) {
                            $q->where('is_bot_available', true)->where('is_active', true);
                        })->select('id', 'name')->get()->map(fn($s) => ['id' => $s->id, 'name' => $s->name])->all();
                    }
                } catch (\Throwable $e) {
                    $subcategories = SubCategory::where('parent_category_id', $catId)->whereHas('products', function ($q) {
                        $q->where('is_bot_available', true)->where('is_active', true);
                    })->select('id', 'name')->get()->map(fn($s) => ['id' => $s->id, 'name' => $s->name])->all();
                }

                $hasSubs = is_array($subcategories) ? !empty($subcategories) : (method_exists($subcategories, 'isNotEmpty') ? $subcategories->isNotEmpty() : !empty($subcategories));
                if ($hasSubs) {
                    $text = "📂 *{$category->name}* အတွင်းရှိ အမျိုးအစားခွဲများ-\n"
                          . "━━━━━━━━━━━━━━━━━━━━━\n"
                          . "ကြည့်ရှုလိုသည့် အမျိုးအစားခွဲကို ရွေးချယ်ပေးပါရှင်-";
                    $buttons = [
                        [['text' => "📦 {$category->name} အားလုံးပြပါ", 'callback_data' => "show_cat_{$catId}"]],
                    ];
                    // 1 button per row for clean, thumb-friendly mobile layout
                    foreach ($subcategories as $sub) {
                        $sId = is_array($sub) ? $sub['id'] : $sub->id;
                        $sName = is_array($sub) ? $sub['name'] : $sub->name;
                        $buttons[] = [
                            ['text' => "🔹 {$sName}", 'callback_data' => "f_subcat_{$sId}"],
                        ];
                    }
                    $buttons[] = [['text' => '🔙 အမျိုးအစားများသို့', 'callback_data' => 'nav_categories']];

                    $this->editOrSendMessage($chatId, $messageId, $text, ['inline_keyboard' => $buttons]);
                    return;
                }
            }

            // Direct show products in this category
            $this->sendCatalog($chatId, page: 1, categoryId: $catId, headerTitle: "📂 *{$category?->name} အမျိုးအစား ကုန်ပစ္စည်းများ*", messageIdToEdit: $messageId);
            return;
        }

        if ($data === 'show_cat_') {
            return;
        }

        if (str_starts_with($data, 'show_cat_')) {
            $catId = (int)str_replace('show_cat_', '', $data);
            $category = ProductCategory::find($catId);
            $this->answerCallbackQuery($id);
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->sendCatalog($chatId, page: 1, categoryId: $catId, headerTitle: "📂 *{$category?->name} အမျိုးအစား ကုန်ပစ္စည်းများ*", messageIdToEdit: $messageId);
            return;
        }

        if (str_starts_with($data, 'f_subcat_')) {
            $subId = (int)str_replace('f_subcat_', '', $data);
            $sub = SubCategory::find($subId);
            $this->answerCallbackQuery($id);
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->sendCatalog($chatId, page: 1, subCategoryId: $subId, headerTitle: "🔹 *{$sub?->name} ကုန်ပစ္စည်းများ*", messageIdToEdit: $messageId);
            return;
        }

        // Brand Filter Selected (Hierarchical Brand -> Category Cross-Drilldown)
        if (str_starts_with($data, 'f_brand_')) {
            $brandId = (int)str_replace('f_brand_', '', $data);
            $brand = Brand::find($brandId);
            $this->answerCallbackQuery($id);
            $messageId = $callbackQuery['message']['message_id'] ?? null;

            $brandDrilldown = Setting::get('telegram_bot_brand_drilldown_category', '1') === '1';

            if ($brandDrilldown && $brand) {
                // Find categories that have active bot products under this brand
                try {
                    $brandCategories = Cache::remember("tg_bot_brand_cats_{$brandId}_v2", 300, function () use ($brandId) {
                        return ProductCategory::whereHas('products', function ($q) use ($brandId) {
                            $q->where('brand_id', $brandId)
                              ->where('is_bot_available', true)
                              ->where(function ($sub) {
                                  $sub->where('is_active', true)->orWhere('status', 'Active');
                              });
                        })->select('id', 'name')->get()->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->all();
                    });
                    if ($brandCategories instanceof \__PHP_Incomplete_Class || (!is_array($brandCategories) && !($brandCategories instanceof \Illuminate\Support\Collection))) {
                        Cache::forget("tg_bot_brand_cats_{$brandId}_v2");
                        Cache::forget("tg_bot_brand_cats_{$brandId}");
                        $brandCategories = ProductCategory::whereHas('products', function ($q) use ($brandId) {
                            $q->where('brand_id', $brandId)
                              ->where('is_bot_available', true)
                              ->where(function ($sub) {
                                  $sub->where('is_active', true)->orWhere('status', 'Active');
                              });
                        })->select('id', 'name')->get()->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->all();
                    }
                } catch (\Throwable $e) {
                    $brandCategories = ProductCategory::whereHas('products', function ($q) use ($brandId) {
                        $q->where('brand_id', $brandId)
                          ->where('is_bot_available', true)
                          ->where(function ($sub) {
                              $sub->where('is_active', true)->orWhere('status', 'Active');
                          });
                    })->select('id', 'name')->get()->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->all();
                }

                $bCatCount = is_array($brandCategories) ? count($brandCategories) : (method_exists($brandCategories, 'count') ? $brandCategories->count() : count((array)$brandCategories));
                if ($bCatCount > 1) {
                    $text = "🏷️ *{$brand->name}* တံဆိပ်အတွင်းရှိ အမျိုးအစားများ-\n"
                          . "━━━━━━━━━━━━━━━━━━━━━\n"
                          . "ကြည့်ရှုလိုသည့် ကုန်ပစ္စည်း အမျိုးအစားကို ရွေးချယ်ပေးပါရှင်-";
                    $buttons = [
                        [['text' => "📦 {$brand->name} ပစ္စည်းအားလုံးပြပါ", 'callback_data' => "show_brand_{$brandId}"]],
                    ];
                    // 1 button per row for clean, thumb-friendly mobile layout
                    foreach ($brandCategories as $bCat) {
                        $bCatId = is_array($bCat) ? $bCat['id'] : $bCat->id;
                        $bCatName = is_array($bCat) ? $bCat['name'] : $bCat->name;
                        $buttons[] = [
                            ['text' => "📂 {$bCatName}", 'callback_data' => "f_bc_{$brandId}_{$bCatId}"],
                        ];
                    }
                    $buttons[] = [['text' => '🔙 တံဆိပ်များသို့', 'callback_data' => 'nav_brands']];

                    $this->editOrSendMessage($chatId, $messageId, $text, ['inline_keyboard' => $buttons]);
                    return;
                }
            }

            $this->sendCatalog($chatId, page: 1, brandId: $brandId, headerTitle: "🏷️ *{$brand?->name} တံဆိပ် ကုန်ပစ္စည်းများ*", messageIdToEdit: $messageId);
            return;
        }

        // Show All Products of a Brand (Bypass Category Drilldown)
        if (str_starts_with($data, 'show_brand_')) {
            $brandId = (int)str_replace('show_brand_', '', $data);
            $brand = Brand::find($brandId);
            $this->answerCallbackQuery($id);
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->sendCatalog($chatId, page: 1, brandId: $brandId, headerTitle: "🏷️ *{$brand?->name} တံဆိပ် ကုန်ပစ္စည်းများ*", messageIdToEdit: $messageId);
            return;
        }

        // Cross-Filter: Brand + Category Selected
        if (str_starts_with($data, 'f_bc_')) {
            $parts = explode('_', $data);
            $brandId = (int)($parts[2] ?? 0);
            $catId = (int)($parts[3] ?? 0);
            $brand = Brand::find($brandId);
            $category = ProductCategory::find($catId);
            $this->answerCallbackQuery($id);
            $messageId = $callbackQuery['message']['message_id'] ?? null;

            $brandName = $brand?->name ?? 'Brand';
            $catName = $category?->name ?? 'Category';
            $title = "🏷️ *{$brandName}* • 📂 *{$catName} ကုန်ပစ္စည်းများ*";

            $this->sendCatalog(
                chatId: $chatId,
                page: 1,
                categoryId: $catId,
                brandId: $brandId,
                headerTitle: $title,
                messageIdToEdit: $messageId
            );
            return;
        }

        // Out of Stock Alert Handler
        if (str_starts_with($data, 'stock_out_')) {
            $this->answerCallbackQuery($id, '⚠️ ယခုကုန်ပစ္စည်း လက်ကျန်ပြတ်လပ်နေပါသည်ရှင်။ မကြာမီ ပြန်လည်ရောက်ရှိပါမည်။', true);
            return;
        }

        // Restock Notification Subscription Handler (Database Backed & Lead Captured)
        if (str_starts_with($data, 'stock_notify_')) {
            $rawId = str_replace('stock_notify_', '', $data);
            $variantId = null;
            if (str_contains($rawId, '_var_')) {
                [$pIdPart, $vIdPart] = explode('_var_', $rawId);
                $productId = (int)$pIdPart;
                $variantId = (int)$vIdPart;
            } else {
                $productId = (int)$rawId;
            }

            $alreadySubscribed = BotRestockSubscriber::where('telegram_chat_id', (string)$chatId)
                ->where('product_id', $productId)
                ->where('product_variant_id', $variantId)
                ->where('is_notified', false)
                ->exists();

            if (!$alreadySubscribed) {
                BotRestockSubscriber::create([
                    'telegram_chat_id' => (string)$chatId,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'customer_name' => $userName,
                    'username' => $usernameTag,
                    'is_notified' => false,
                ]);

                $subKey = "tg_restock_subscribers_{$productId}";
                $subscribers = Cache::get($subKey, []);
                if (!in_array($chatId, $subscribers)) {
                    $subscribers[] = $chatId;
                    Cache::forever($subKey, $subscribers);
                }

                $this->answerCallbackQuery($id, '🔔 ပစ္စည်းပြန်လည်ရောက်ရှိချိန်တွင် သင့်ထံသို့ အလိုအလျောက် သတင်းစကား ပို့ပေးပါမည်ရှင်။ ကျေးဇူးတင်ပါသည်!', true);
            } else {
                $this->answerCallbackQuery($id, 'ℹ️ သင်သည် ဤပစ္စည်းအတွက် သတိပေးချက် ရယူရန် စာရင်းသွင်းထားပြီးဖြစ်ပါသည်ရှင်။', true);
            }
            return;
        }

        // Product selection -> ask quantity
        // Product selection -> check if variation product or single product
        if (str_starts_with($data, 'buy_')) {
            $productId = (int)str_replace('buy_', '', $data);
            $product = Product::with([
                'variants' => fn($q) => $q->where('is_active', true)->orderBy('sort_order', 'asc'),
                'primaryImage',
                'images',
            ])->find($productId);
            if (!$product) {
                $this->answerCallbackQuery($id, 'ကုန်ပစ္စည်း ရှာမတွေ့ပါ', true);
                return;
            }

            // Real-Time Stock Guard: Prevent ordering if simple product has 0 stock unless pre-order is allowed
            if (!$product->has_variants) {
                $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
                $currStock = (float)StockBalance::where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->sum('quantity');
                $allowOutOfStock = Setting::get('telegram_bot_allow_out_of_stock_orders', '0') === '1';
                if ($currStock <= 0 && !$allowOutOfStock) {
                    $this->answerCallbackQuery($id, '⚠️ ယခုကုန်ပစ္စည်း လက်ကျန်ပြတ်လပ်သွားပါပြီရှင်။', true);
                    return;
                }
            }

            $session = Cache::get("tg_session_{$chatId}");
            $isEditingFromQty = !empty($session['step']) && $session['step'] === 'waiting_qty';
            $messageId = $isEditingFromQty ? ($callbackQuery['message']['message_id'] ?? null) : null;

            // If product has active variations, prompt for variant selection first at bottom
            if ($product->has_variants && $product->variants->isNotEmpty()) {
                $this->answerCallbackQuery($id, "🎨 {$product->name} အတွက် ဆိုဒ်/အရွယ်အစား ရွေးချယ်ပေးပါရှင်");
                $this->sendVariantSelection($chatId, $product, $messageId);
                return;
            }

            $this->answerCallbackQuery($id, "🛍️ {$product->name} အတွက် အရေအတွက် ရွေးချယ်ပေးပါရှင်");
            $this->initiateQuantitySelection($chatId, $product, null, $messageId);
            return;
        }

        // Variant selected (e.g. var_12_3)
        if (str_starts_with($data, 'var_')) {
            $parts = explode('_', $data);
            $productId = (int)($parts[1] ?? 0);
            $variantId = (int)($parts[2] ?? 0);

            $product = Product::with(['primaryImage', 'images'])->find($productId);
            $variant = ProductVariant::where('product_id', $productId)->find($variantId);

            if (!$product || !$variant) {
                $this->answerCallbackQuery($id, 'ဆိုဒ်/အရွယ်အစား ရှာမတွေ့ပါ', true);
                return;
            }

            $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
            $stock = (float)StockBalance::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('product_variant_id', $variantId)
                ->sum('quantity');

            if ($stock <= 0) {
                $stockTpl = Setting::get('telegram_bot_out_of_stock_msg', '');
                $stockAlert = !empty(trim($stockTpl))
                    ? $this->replacePlaceholders($stockTpl, ['variant_name' => $variant->name, 'product_name' => $product->name])
                    : "⚠️ စိတ်မကောင်းပါဘူးရှင်၊ {$variant->name} လက်ကျန်ပြတ်လပ်နေပါသည်";
                $this->answerCallbackQuery($id, $stockAlert, true);
                return;
            }

            $this->answerCallbackQuery($id, "🎨 {$variant->name} အတွက် အရေအတွက် ရွေးပါ (သို့မဟုတ်) ရိုက်ထည့်ပါ");
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->initiateQuantitySelection($chatId, $product, $variant, $messageId);
            return;
        }

        // Custom Quantity Button clicked -> Pop open native keyboard with ForceReply
        if (str_starts_with($data, 'ask_qty_')) {
            $parts = explode('_', $data);
            $productId = (int)($parts[2] ?? 0);
            $variantId = isset($parts[3]) && $parts[3] !== '' ? (int)$parts[3] : null;

            $product = Product::find($productId);
            if (!$product) {
                $this->answerCallbackQuery($id, 'ကုန်ပစ္စည်း ရှာမတွေ့ပါ', true);
                return;
            }

            $variant = $variantId ? ProductVariant::where('product_id', $productId)->find($variantId) : null;
            $displayName = $variant ? "{$product->name} ({$variant->name})" : $product->name;
            $effectivePrice = $variant
                ? (float)($variant->selling_price ?: ($product->retail_price ?: $product->selling_price))
                : (float)($product->retail_price ?: $product->selling_price);

            $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
            $stockQuery = StockBalance::where('warehouse_id', $warehouseId)->where('product_id', $product->id);
            if ($variant) {
                $stockQuery->where('product_variant_id', $variant->id);
            }
            $stock = (float)$stockQuery->sum('quantity');

            // Set waiting_qty state in session
            $session = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'variant_id' => $variant?->id,
                'variant_name' => $variant?->name,
                'variant_sku' => $variant?->sku ?: ($product->code . ($variant ? '-' . $variant->name : '')),
                'price' => $effectivePrice,
                'step' => 'waiting_qty',
            ];
            Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

            $this->answerCallbackQuery($id, '✍️ အရေအတွက် ရိုက်ထည့်ပေးပါရှင်');

            $unitName = $product->unit_name ?: 'ခု';
            $msg = "✏️ *{$displayName}*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "💰 ဈေးနှုန်း: *" . number_format($effectivePrice) . " MMK*\n"
                 . "📦 လက်ကျန်: {$stock} {$unitName}\n\n"
                 . "မှာယူလိုသော အရေအတွက်ကို ကိန်းဂဏန်း ရိုက်ပို့ပေးပါရှင်-\n"
                 . "(💡 ဥပမာ- `10` သို့မဟုတ် `၁၀`)";

            $this->sendMessage($chatId, $msg, [
                'force_reply' => true,
                'selective' => true,
                'input_field_placeholder' => 'ဥပမာ- 10 သို့မဟုတ် ၁၀',
            ]);
            return;
        }

        // Quantity selected via quick buttons -> Add to Shopping Cart!
        if (str_starts_with($data, 'qty_')) {
            $parts = explode('_', $data);
            $productId = (int)($parts[1] ?? 0);
            $qty = (int)($parts[2] ?? 1);

            $session = Cache::get("tg_session_{$chatId}");
            $variant = null;
            if ($session && !empty($session['variant_id'])) {
                $variant = ProductVariant::find($session['variant_id']);
            }

            $product = Product::with(['primaryImage', 'images'])->find($productId);
            if (!$product) {
                $this->answerCallbackQuery($id, 'ကုန်ပစ္စည်း ရှာမတွေ့ပါ');
                return;
            }

            $cartRes = $this->addToCart($chatId, $product, $variant, $qty);
            Cache::forget("tg_session_{$chatId}");
            if (Setting::get('telegram_bot_cart_show_toast', '0') === '1') {
                $this->answerCallbackQuery($id, "✅ ခြင်းတောင်းထဲသို့ {$qty} ခု ထည့်ပြီးပါပြီ");
            } else {
                $this->answerCallbackQuery($id);
            }

            $addedItem = $cartRes['added_item'];
            $itemTitle = !empty($addedItem['variant_name'])
                ? "{$addedItem['product_name']} ({$addedItem['variant_name']})"
                : $addedItem['product_name'];
            $totalFormatted = number_format($cartRes['grand_total']);
            $unitName = $addedItem['unit_name'] ?? 'ခု';

            $cartMsg = $this->formatCartUpdatedMessage($cartRes, $itemTitle, $qty, $unitName);

            $btnCheckout = Setting::get('telegram_bot_btn_checkout', '💳 အော်ဒါတင်မည်');
            $btnCart = Setting::get('telegram_bot_btn_cart', '🛒 ခြင်းတောင်း ကြည့်မည်');
            $btnContinue = Setting::get('telegram_bot_btn_continue_shopping', '➕ ပစ္စည်း ဆက်ဝယ်မည်');
            $btnMainMenu = Setting::get('telegram_bot_btn_main_menu', '🔙 ပင်မ မီနူးသို့');
            $btnClear = Setting::get('telegram_bot_btn_clear_cart', '🗑️ ခြင်းတောင်း ရှင်းမည်');

            $showBtnCheckout = Setting::get('telegram_bot_show_cart_updated_btn_checkout', '1') === '1';
            $showBtnCart = Setting::get('telegram_bot_show_cart_updated_btn_view', '1') === '1';
            $showBtnContinue = Setting::get('telegram_bot_show_cart_updated_btn_continue', '1') === '1';
            $showBtnClear = Setting::get('telegram_bot_show_cart_updated_btn_clear', '1') === '1';
            $showBtnMainMenu = Setting::get('telegram_bot_show_cart_updated_btn_menu', '1') === '1';

            $cartButtons = [];
            if ($showBtnCheckout) {
                $cartButtons[] = [
                    ['text' => (!empty($btnCheckout) ? $btnCheckout : '💳 အော်ဒါတင်မည်'), 'callback_data' => 'cart_checkout'],
                ];
            }
            if ($showBtnCart) {
                $cartButtons[] = [
                    ['text' => (!empty($btnCart) ? $btnCart : '🛒 ခြင်းတောင်း ကြည့်မည်'), 'callback_data' => 'cart_view'],
                ];
            }
            if ($showBtnContinue) {
                $cartButtons[] = [
                    ['text' => !empty($btnContinue) ? $btnContinue : '➕ ပစ္စည်း ဆက်ဝယ်မည်', 'callback_data' => 'nav_all_products'],
                ];
            }
            if ($showBtnClear) {
                $cartButtons[] = [
                    ['text' => !empty($btnClear) ? $btnClear : '🗑️ ခြင်းတောင်း ရှင်းမည်', 'callback_data' => 'cart_clear'],
                ];
            }
            if ($showBtnMainMenu) {
                $cartButtons[] = [
                    ['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
                ];
            }

            // Resolve Image Source of the newly added product
            $imagePath = null;
            if ($variant && !empty($variant->image_url)) {
                $imagePath = $variant->image_url;
            } elseif (!empty($product->image_url)) {
                $imagePath = $product->image_url;
            } else {
                $imagePath = $product->primaryImage?->path ?: ($product->images?->first()?->path ?? null);
            }

            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $res = null;
            $replyMarkup = !empty($cartButtons) ? ['inline_keyboard' => $cartButtons] : null;

            if ($messageId && !empty($imagePath)) {
                // Instant caption edit on the existing photo card
                $res = $this->editMessageCaption($chatId, $messageId, $cartMsg, $replyMarkup);
            }

            if (empty($res['ok'])) {
                if ($messageId) {
                    $this->deleteMessage($chatId, $messageId);
                }
                if (!empty($imagePath)) {
                    $res = $this->sendPhoto($chatId, $imagePath, $cartMsg, $replyMarkup);
                }
                if (empty($res['ok'])) {
                    $this->sendMessage($chatId, $cartMsg, $replyMarkup);
                }
            }
            return;
        }

        // Payment method selected (Dynamic PM by ID or Legacy)
        if (str_starts_with($data, 'pay_')) {
            $methodData = str_replace('pay_', '', $data);
            $session = Cache::get("tg_session_{$chatId}");
            if (!$session || (empty($session['product_id']) && empty($session['items']))) {
                $this->answerCallbackQuery($id, 'အော်ဒါသက်တမ်းကုန်သွားပါပြီ /start ဖြင့် ပြန်စပါ', true);
                return;
            }

            $this->answerCallbackQuery($id);

            $messageId = $callbackQuery['message']['message_id'] ?? null;

            if ($methodData === 'change_method') {
                $session['step'] = 'waiting_payment_method';
                Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));
                if ($messageId) {
                    $this->deleteMessage($chatId, $messageId);
                }
                $this->promptPaymentMethodSelection($chatId);
                return;
            }

            if ($methodData === 'cod') {
                $session['payment_type'] = 'Cash on Delivery';
                $session['payment_status'] = 'Unpaid';
                $this->finalizeOrder($chatId, $session, $userName, $usernameTag);
                return;
            }

            if ($methodData === 'confirm_no_slip') {
                $session['payment_status'] = 'Pending Review';
                $session['notes_extra'] = 'Mobile Banking (Slip verification skipped by setting)';
                $this->finalizeOrder($chatId, $session, $userName, $usernameTag);
                return;
            }

            // Direct PaymentMethod ID from database SSOT
            if (str_starts_with($methodData, 'pm_')) {
                $pmId = (int)str_replace('pm_', '', $methodData);
                $pm = PaymentMethod::find($pmId);
                if ($pm) {
                    $code = strtoupper($pm->code ?? '');
                    $name = strtolower($pm->name);
                    $isCash = ($code === 'CASH' || str_contains($name, 'cash') || str_contains($name, 'cod'));

                    if ($isCash) {
                        $session['payment_type'] = $pm->name;
                        $session['payment_method_id'] = $pm->id;
                        $session['payment_status'] = 'Unpaid';
                        $this->finalizeOrder($chatId, $session, $userName, $usernameTag);
                        return;
                    }

                    $this->handlePaymentMethodModelSelection($chatId, $pm, $session, $messageId);
                    return;
                }
            }

            // Fallback for legacy string callbacks
            $this->handlePaymentMethodSelection($chatId, $methodData, $session, $messageId);
            return;
        }
    }

    /**
     * Handle Dynamic PaymentMethod model selection with Real-time QR Code Card & Live Invalidation
     */
    protected function handlePaymentMethodModelSelection(int|string $chatId, PaymentMethod $pm, array $session, ?int $prevMessageId = null): void
    {
        // Always fetch fresh from database Single Source of Truth
        $pm = $pm->fresh() ?? $pm;
        $session['payment_type'] = $pm->name;
        $session['payment_method_id'] = $pm->id;
        $session['step'] = 'waiting_slip';

        $grandTotal = !empty($session['grand_total'])
            ? (float)$session['grand_total']
            : (float)($session['price'] ?? 0) * (float)($session['quantity'] ?? 1);
        $totalFormatted = number_format($grandTotal);

        $orderRef = $session['order_ref'] ?? ('ORD-' . date('Ymd') . '-' . substr(md5((string)$chatId . time()), 0, 4));
        $session['order_ref'] = $orderRef;
        Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

        $details = [];
        if (!empty($pm->account_name)) {
            $details[] = "👤 အကောင့်အမည်:   `{$pm->account_name}`";
        }
        if (!empty($pm->account_number)) {
            $details[] = "🔢 အကောင့်နံပါတ်:  `{$pm->account_number}` _(နှိပ်၍ ကူးယူပါ)_";
        }
        if (!empty($pm->description)) {
            $details[] = "📝 မှတ်ချက်:      {$pm->description}";
        }

        $accountDetailsText = !empty($details)
            ? implode("\n", $details)
            : "ကျေးဇူးပြု၍ ဆိုင်သို့ ဆက်သွယ်၍ ငွေလွှဲပေးပါရှင်။";

        $caption = "💳 *{$pm->name} ဖြင့် ငွေလွှဲရန် အချက်အလက်*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "{$accountDetailsText}\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "💰 လွှဲရမည့် ပမာဏ:  *{$totalFormatted} MMK*\n"
                 . "🔖 အော်ဒါအမှတ်:    `{$orderRef}`\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n\n"
                 . "⚠️ _ငွေလွှဲမှတ်ချက် (Note) တွင် အော်ဒါအမှတ် ထည့်ပေးပါရန် မေတ္တာရပ်ခံပါသည်ရှင်။_\n"
                 . "📸 _ငွေလွှဲပြီးပါက **ငွေလွှဲပြေစာ (Slip) ဓာတ်ပုံ** အား ဤနေရာသို့ ပေးပို့ပေးပါရှင်။_ 🙏";

        $requireSlip = Setting::get('telegram_bot_require_payment_slip', '1') === '1';
        $inlineButtons = [];
        if (!$requireSlip) {
            $inlineButtons[] = [
                ['text' => '✅ ငွေလွှဲပြီးပါပြီ (Order အတည်ပြုမည်)', 'callback_data' => 'pay_confirm_no_slip']
            ];
        }
        $inlineButtons[] = [
            ['text' => '🔄 အခြား ငွေချေနည်းလမ်း ရွေးမည်', 'callback_data' => 'pay_change_method']
        ];
        $inlineButtons[] = [
            ['text' => '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel']
        ];

        $actionButtons = [
            'inline_keyboard' => $inlineButtons,
        ];

        // Clean up previous selection message for zero clutter
        if ($prevMessageId) {
            $this->deleteMessage($chatId, $prevMessageId);
        }

        // Check if QR code image is available
        $qrLocalPath = $pm->getQrCodeLocalPath();
        $qrSent = false;

        if (!empty($qrLocalPath) && file_exists($qrLocalPath)) {
            // Real-time cache key keyed by PM ID, image path, and updated_at timestamp.
            // Whenever Admin changes QR or details, the timestamp changes and invalidates immediately!
            $cacheKey = "tg_pm_file_id_{$pm->id}_" . md5(($pm->qr_code_image ?? '') . '_' . ($pm->updated_at?->timestamp ?? 0));
            $cachedFileId = Cache::get($cacheKey);

            if (!empty($cachedFileId)) {
                // Ultra-low latency dispatch via Telegram file_id (~120ms)
                $res = $this->sendPhoto($chatId, $cachedFileId, $caption, $actionButtons);
                if (!empty($res['ok'])) {
                    $qrSent = true;
                }
            }

            if (!$qrSent) {
                // Upload local binary and cache file_id for subsequent lightning-fast checkouts
                $res = $this->sendPhoto($chatId, $qrLocalPath, $caption, $actionButtons);
                if (!empty($res['ok'])) {
                    $qrSent = true;
                    $photos = $res['result']['photo'] ?? [];
                    $largestPhoto = end($photos);
                    if (!empty($largestPhoto['file_id'])) {
                        Cache::put($cacheKey, $largestPhoto['file_id'], now()->addDays(7));
                    }
                }
            }
        }

        // Graceful Fallback to formatted Text Card if no QR image or photo sending failed
        if (!$qrSent) {
            $this->sendMessage($chatId, $caption, $actionButtons);
        }
    }

    /**
     * Format Payment Instructions Message with custom template
     */
    public function formatPaymentInstructionsMessage(string $paymentName, string $accountDetailsText, string $totalFormatted): string
    {
        $tpl = Setting::get('telegram_bot_payment_instructions_msg', '');
        if (!empty(trim($tpl))) {
            return $this->replacePlaceholders($tpl, [
                'payment_name' => $paymentName,
                'payment_type' => $paymentName,
                'account_details' => $accountDetailsText,
                'amount' => $totalFormatted,
                'grand_total' => $totalFormatted,
                'total' => $totalFormatted,
            ]);
        }

        return "💳 *{$paymentName} ဖြင့် ငွေလွှဲရန် အချက်အလက်*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n\n"
             . "{$accountDetailsText}\n\n"
             . "💰 *လွှဲရမည့် စုစုပေါင်း ပမာဏ:* *{$totalFormatted} MMK*\n\n"
             . "📸 ငွေလွှဲပြီးပါက **ငွေလွှဲပြေစာ (Slip) ဓာတ်ပုံ** ကို ဤနေရာသို့ ပေးပို့ပေးပါရှင်။";
    }

    /**
     * Format Ask Phone message with custom template & placeholders
     */
    public function formatAskPhoneMessage(string $itemTitle, int $qty, float $total, string $userName): string
    {
        $totalFormatted = number_format($total);
        $displayName = !empty(trim($userName)) ? $userName : 'ဖောက်သည်';

        $tpl = Setting::get('telegram_bot_ask_phone_msg', '');
        if (!empty(trim($tpl))) {
            $askPrompt = $this->replacePlaceholders($tpl, [
                'customer_name' => $displayName,
                'user_name' => $displayName,
                'name' => $displayName,
                'product_name' => $itemTitle,
                'quantity' => $qty,
                'qty' => $qty,
                'amount' => $totalFormatted,
                'grand_total' => $totalFormatted,
                'total' => $totalFormatted,
            ]);
        } else {
            $askPrompt = "📱 ဆက်သွယ်ရမည့် *ဖုန်းနံပါတ်* ရိုက်ထည့်ပေးပါရှင် (ဥပမာ- 09123456789) -";
        }

        return "📝 *အော်ဒါ အနှစ်ချုပ်*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "📦 ပစ္စည်း:          *{$itemTitle}*\n"
             . "🔢 အရေအတွက်:       *{$qty} ခု*\n"
             . "💰 စုစုပေါင်း ကျသင့်ငွေ: *{$totalFormatted} MMK*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n\n"
             . "{$askPrompt}";
    }

    /**
     * Get Address Keyboard based on telegram_bot_location_input_mode
     */
    public function getAddressKeyboard(): array
    {
        $locMode = Setting::get('telegram_bot_location_input_mode', 'both_button_and_text');
        $btnLocation = Setting::get('telegram_bot_btn_share_location', '📍 လက်ရှိတည်နေရာ ပေးပို့မည် (Share GPS Location)');
        $btnCancel = Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)');

        $rows = [];
        if ($locMode !== 'text_only') {
            $rows[] = [['text' => !empty($btnLocation) ? $btnLocation : '📍 လက်ရှိတည်နေရာ ပေးပို့မည် (Share GPS Location)', 'request_location' => true]];
        }
        $rows[] = [['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)']];

        return [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * Format Ask Address message with custom template & placeholders
     */
    public function formatAskAddressMessage(string $userName, array $session = []): string
    {
        $displayName = !empty(trim($userName)) ? $userName : 'ဖောက်သည်';
        $tpl = Setting::get('telegram_bot_ask_address_msg', '');
        if (!empty(trim($tpl))) {
            return $this->replacePlaceholders($tpl, [
                'customer_name' => $displayName,
                'user_name' => $displayName,
                'name' => $displayName,
                'phone' => $session['phone'] ?? '',
                'customer_phone' => $session['phone'] ?? '',
                'product_name' => $session['product_name'] ?? '',
            ]);
        }

        $locMode = Setting::get('telegram_bot_location_input_mode', 'both_button_and_text');
        $btnLocation = Setting::get('telegram_bot_btn_share_location', '📍 လက်ရှိတည်နေရာ ပေးပို့မည် (Share GPS Location)');

        if ($locMode === 'text_only') {
            return "📍 ပစ္စည်း ပို့ဆောင်ပေးရမည့် *လိပ်စာ* ရိုက်ထည့်ပေးပါရှင် (ဥပမာ- အမှတ် ၁၂၊ လမ်း ၅၀၊ ရန်ကုန်)-";
        }

        return "📍 ပစ္စည်း ပို့ဆောင်ပေးရမည့် *လိပ်စာ* ရိုက်ထည့်ပေးပါရှင် (ဥပမာ- အမှတ် ၁၂၊ လမ်း ၅၀၊ ရန်ကုန်)\n\n"
             . "သို့မဟုတ် အောက်ပါ '{$btnLocation}' ခလုတ်ကို နှိပ်၍ GPS Location ကို ၁ ချက်နှိပ်ရုံဖြင့် တိုက်ရိုက် ပေးပို့နိုင်ပါသည်ရှင်-";
    }

    /**
     * Format Ask Slip message with custom template & placeholders
     */
    public function formatAskSlipMessage(string $userName, array $session = []): string
    {
        $displayName = !empty(trim($userName)) ? $userName : 'ဖောက်သည်';
        $tpl = Setting::get('telegram_bot_ask_slip_msg', '');
        if (!empty(trim($tpl))) {
            return $this->replacePlaceholders($tpl, [
                'customer_name' => $displayName,
                'user_name' => $displayName,
                'name' => $displayName,
                'payment_name' => $session['payment_type'] ?? '',
                'payment_type' => $session['payment_type'] ?? '',
            ]);
        }

        return "📸 ငွေလွှဲပြေစာ (Slip) ဓာတ်ပုံကို ပေးပို့ပေးပါရှင်။ (မမှာတော့ပါက '❌ မမှာတော့ပါ (Cancel)' ကို နှိပ်နိုင်ပါသည်)";
    }

    /**
     * Format Cancelled message with custom template & placeholders
     */
    public function formatCancelledMessage(string $userName): string
    {
        $displayName = !empty(trim($userName)) ? $userName : 'ဖောက်သည်';
        $cancelTpl = Setting::get('telegram_bot_cancelled_msg', '');
        if (!empty(trim($cancelTpl))) {
            return $this->replacePlaceholders($cancelTpl, [
                'customer_name' => $displayName,
                'user_name' => $displayName,
                'name' => $displayName,
            ]);
        }

        return "❌ မှာယူမှုကို ပယ်ဖျက်လိုက်ပါပြီရှင်။ /start ကို နှိပ်၍ အစမှ ပြန်လည်စတင်နိုင်ပါသည်ရှင်။ 🙏";
    }

    /**
     * Handle Specific Payment Method (KPay sends KPay only, Wave sends Wave only)
     */
    protected function handlePaymentMethodSelection(int|string $chatId, string $method, array $session, ?int $prevMessageId = null): void
    {
        $paymentTypeName = match ($method) {
            'wavepay' => 'WavePay',
            'ayapay' => 'AYA Pay',
            'cbpay' => 'CB Pay',
            'bank' => 'Bank Transfer',
            'kbz_special' => 'KBZ Special Account',
            default => 'KBZPay',
        };

        // Check if there is an official PaymentMethod in database
        $pm = PaymentMethod::where('is_active', true)
            ->where(function ($q) use ($paymentTypeName, $method) {
                $q->where('name', 'like', "%{$paymentTypeName}%")
                  ->orWhere('code', strtoupper($method));
            })->first();

        if ($pm) {
            $this->handlePaymentMethodModelSelection($chatId, $pm, $session, $prevMessageId);
            return;
        }

        if ($prevMessageId) {
            $this->deleteMessage($chatId, $prevMessageId);
        }

        $session['payment_type'] = $paymentTypeName;
        $session['step'] = 'waiting_slip';
        Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

        $grandTotal = !empty($session['grand_total'])
            ? (float)$session['grand_total']
            : (float)($session['price'] ?? 0) * (float)($session['quantity'] ?? 1);
        $totalFormatted = number_format($grandTotal);

        // Fetch dedicated bank info for this method
        $bankDetails = $this->getDedicatedPaymentInfo($paymentTypeName);

        $msg = $this->formatPaymentInstructionsMessage($paymentTypeName, $bankDetails, $totalFormatted);
        $requireSlip = Setting::get('telegram_bot_require_payment_slip', '1') === '1';
        $inlineButtons = [];
        if (!$requireSlip) {
            $inlineButtons[] = [
                ['text' => '✅ ငွေလွှဲပြီးပါပြီ (Order အတည်ပြုမည်)', 'callback_data' => 'pay_confirm_no_slip']
            ];
        }
        $inlineButtons[] = [
            ['text' => '🔄 အခြား ငွေချေနည်းလမ်း ရွေးမည်', 'callback_data' => 'pay_change_method']
        ];
        $inlineButtons[] = [
            ['text' => '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel']
        ];

        $actionButtons = [
            'inline_keyboard' => $inlineButtons,
        ];
        $this->sendMessage($chatId, $msg, $actionButtons);
    }

    /**
     * Dedicated Payment Method Resolver: Returns ONLY account details for selected method
     */
    public function getDedicatedPaymentInfo(string $paymentTypeName): string
    {
        // Check DB PaymentMethod first (Single Source of Truth)
        $pm = PaymentMethod::where('is_active', true)
            ->where('name', 'like', "%{$paymentTypeName}%")
            ->first();

        if ($pm && (!empty($pm->account_number) || !empty($pm->account_name))) {
            $lines = [];
            if (!empty($pm->account_name)) $lines[] = "👤 *အကောင့်အမည်:* `{$pm->account_name}`";
            if (!empty($pm->account_number)) $lines[] = "🔢 *အကောင့်နံပါတ်:* `{$pm->account_number}`";
            if (!empty($pm->description)) $lines[] = "📝 *မှတ်ချက်:* {$pm->description}";
            return implode("\n", $lines);
        }

        $info = match ($paymentTypeName) {
            'KBZPay' => Setting::get('telegram_bot_kpay_info', ''),
            'WavePay' => Setting::get('telegram_bot_wave_info', ''),
            'AYA Pay' => Setting::get('telegram_bot_ayapay_info', ''),
            'CB Pay' => Setting::get('telegram_bot_cbpay_info', ''),
            'Bank Transfer' => Setting::get('telegram_bot_other_bank_info', ''),
            'KBZ Special Account' => Setting::get('telegram_bot_other_bank_info', ''),
            default => '',
        };

        if (!empty(trim($info))) {
            return trim($info);
        }

        // Fallback: If dedicated field is empty, attempt intelligent line extraction from generic payment info
        $genericInfo = Setting::get('telegram_bot_payment_info', '');
        if (!empty($genericInfo)) {
            $lines = explode("\n", $genericInfo);
            $matched = [];
            $keyword = match ($paymentTypeName) {
                'KBZPay' => 'kbz',
                'WavePay' => 'wave',
                'AYA Pay' => 'aya',
                'CB Pay' => 'cb',
                default => '',
            };

            if ($keyword) {
                foreach ($lines as $line) {
                    if (stripos($line, $keyword) !== false) {
                        $matched[] = trim($line);
                    }
                }
            }

            if (!empty($matched)) {
                return implode("\n", $matched);
            }

            return trim($genericInfo);
        }

        // Default placeholders
        return match ($paymentTypeName) {
            'KBZPay' => "KBZPay Account: 09123456789 (Ah Linn Thit Store)",
            'WavePay' => "WavePay Account: 09987654321 (Ah Linn Thit Store)",
            default => "Account Details: 09123456789",
        };
    }

    /**
     * Handle Text Input in Conversation State
     */
    protected function handleSessionInput(int|string $chatId, string $text, array $session, string $userName, string $usernameTag): void
    {
        $step = $session['step'];

        // Search Query Handling
        if ($step === 'waiting_search') {
            Cache::forget("tg_session_{$chatId}");

            // Check if user specifically searched by subcategory e.g. subcat 43, subcat_43, sc 43
            if (preg_match('/^(?:subcat|sub_cat|sub|scat|sc|subcategory|sub_category)[_\s:]*(\d+)$/i', trim($text), $sm)) {
                $subId = (int)$sm[1];
                $subCategory = SubCategory::find($subId);
                if ($subCategory) {
                    $parentName = $subCategory->parent_category_name ?: ($subCategory->parentCategory?->name ?? '');
                    $header = !empty($parentName)
                        ? "📂 *{$parentName}* › 🔹 *{$subCategory->name}* ကုန်ပစ္စည်းများ"
                        : "🔹 *{$subCategory->name} ကုန်ပစ္စည်းများ*";
                    $this->sendCatalog($chatId, page: 1, subCategoryId: $subId, headerTitle: $header);
                    return;
                }
            }

            // Check variant exact match by sku or barcode first
            $variantSearch = ProductVariant::where('sku', $text)
                ->orWhere('barcode', $text)
                ->first();
            if ($variantSearch && $variantSearch->product && $variantSearch->product->is_bot_available) {
                $this->initiateQuantitySelection($chatId, $variantSearch->product, $variantSearch);
                return;
            }

            // If exact match by code, sku, or ID -> directly show high-res photo card
            $exactProduct = Product::where('is_bot_available', true)
                ->where(function ($q) use ($text) {
                    $q->where('code', $text)
                      ->orWhere('sku', $text)
                      ->orWhere('id', is_numeric($text) ? (int)$text : -1);
                })
                ->first();

            if ($exactProduct) {
                $this->sendProductPhotoCard($chatId, $exactProduct, 'catalog_main');
                return;
            }

            $this->sendCatalog($chatId, searchQuery: $text, headerTitle: "🔎 *'{$text}' အတွက် ရှာဖွေတွေ့ရှိသော ကုန်ပစ္စည်းများ*");
            return;
        }

        if ($step === 'waiting_qty') {
            $qty = $this->parseQuantity($text);
            if ($qty <= 0) {
                $errTpl = Setting::get('telegram_bot_invalid_qty_msg', '');
                $errMsg = !empty(trim($errTpl))
                    ? $this->replacePlaceholders($errTpl, ['customer_name' => $userName, 'user_name' => $userName])
                    : "⚠️ အရေအတွက်မှာ အနည်းဆုံး ၁ ခု ဖြစ်ရပါမည်ရှင်။ အရေအတွက် ထပ်မံရိုက်ထည့်ပေးပါ-";
                $this->sendMessage($chatId, $errMsg, [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' => 'ဥပမာ- 10 သို့မဟုတ် ၁၀',
                ]);
                return;
            }

            $product = Product::with(['primaryImage', 'images'])->find($session['product_id'] ?? 0);
            if (!$product) {
                $this->sendMessage($chatId, "⚠️ ကုန်ပစ္စည်း ရှာမတွေ့ပါ။", $this->getMainKeyboard($chatId));
                return;
            }

            $variant = !empty($session['variant_id']) ? ProductVariant::find($session['variant_id']) : null;

            // Inventory Stock Check
            $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
            $stockQuery = StockBalance::where('warehouse_id', $warehouseId)->where('product_id', $product->id);
            if ($variant) {
                $stockQuery->where('product_variant_id', $variant->id);
            }
            $stock = (float)$stockQuery->sum('quantity');
            $allowOutOfStock = Setting::get('telegram_bot_allow_out_of_stock_orders', '0') === '1';

            if ($stock < $qty && !$allowOutOfStock) {
                $unit = $product->unit_name ?: 'ခု';
                $this->sendMessage($chatId, "⚠️ စိတ်မကောင်းပါဘူးရှင်။ လက်ကျန်မှာ *{$stock} {$unit}* သာ ရှိပါသဖြင့် အရေအတွက် လျှော့ချ၍ ပြန်လည်ရိုက်ထည့်ပေးပါရှင်- (ဥပမာ- {$stock})", [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' => "အများဆုံး {$stock}",
                ]);
                return;
            }

            if (!empty($session['last_prompt_msg_id'])) {
                $this->deleteMessage($chatId, (int)$session['last_prompt_msg_id']);
            }

            $cartRes = $this->addToCart($chatId, $product, $variant, $qty);
            Cache::forget("tg_session_{$chatId}");

            $addedItem = $cartRes['added_item'];
            $itemTitle = !empty($addedItem['variant_name'])
                ? "{$addedItem['product_name']} ({$addedItem['variant_name']})"
                : $addedItem['product_name'];
            $totalFormatted = number_format($cartRes['grand_total']);
            $unitName = $addedItem['unit_name'] ?? 'ခု';

            $cartMsg = $this->formatCartUpdatedMessage($cartRes, $itemTitle, $qty, $unitName);

            $btnCheckout = Setting::get('telegram_bot_btn_checkout', '💳 အော်ဒါတင်မည်');
            $btnCart = Setting::get('telegram_bot_btn_cart', '🛒 ခြင်းတောင်း ကြည့်မည်');
            $btnContinue = Setting::get('telegram_bot_btn_continue_shopping', '➕ ပစ္စည်း ဆက်ဝယ်မည်');
            $btnMainMenu = Setting::get('telegram_bot_btn_main_menu', '🔙 ပင်မ မီနူးသို့');
            $btnClear = Setting::get('telegram_bot_btn_clear_cart', '🗑️ ခြင်းတောင်း ရှင်းမည်');

            $showBtnCheckout = Setting::get('telegram_bot_show_cart_updated_btn_checkout', '1') === '1';
            $showBtnCart = Setting::get('telegram_bot_show_cart_updated_btn_view', '1') === '1';
            $showBtnContinue = Setting::get('telegram_bot_show_cart_updated_btn_continue', '1') === '1';
            $showBtnClear = Setting::get('telegram_bot_show_cart_updated_btn_clear', '1') === '1';
            $showBtnMainMenu = Setting::get('telegram_bot_show_cart_updated_btn_menu', '1') === '1';

            $cartButtons = [];
            if ($showBtnCheckout) {
                $cartButtons[] = [
                    ['text' => (!empty($btnCheckout) ? $btnCheckout : '💳 အော်ဒါတင်မည်'), 'callback_data' => 'cart_checkout'],
                ];
            }
            if ($showBtnCart) {
                $cartButtons[] = [
                    ['text' => (!empty($btnCart) ? $btnCart : '🛒 ခြင်းတောင်း ကြည့်မည်'), 'callback_data' => 'cart_view'],
                ];
            }
            if ($showBtnContinue) {
                $cartButtons[] = [
                    ['text' => !empty($btnContinue) ? $btnContinue : '➕ ပစ္စည်း ဆက်ဝယ်မည်', 'callback_data' => 'nav_all_products'],
                ];
            }
            if ($showBtnClear) {
                $cartButtons[] = [
                    ['text' => !empty($btnClear) ? $btnClear : '🗑️ ခြင်းတောင်း ရှင်းမည်', 'callback_data' => 'cart_clear'],
                ];
            }
            if ($showBtnMainMenu) {
                $cartButtons[] = [
                    ['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
                ];
            }

            // Resolve Image Source of the newly added product
            $imagePath = null;
            if ($variant && !empty($variant->image_url)) {
                $imagePath = $variant->image_url;
            } elseif (!empty($product->image_url)) {
                $imagePath = $product->image_url;
            } else {
                $imagePath = $product->primaryImage?->path ?: ($product->images?->first()?->path ?? null);
            }

            $res = null;
            $replyMarkup = !empty($cartButtons) ? ['inline_keyboard' => $cartButtons] : null;

            if (!empty($imagePath)) {
                $res = $this->sendPhoto($chatId, $imagePath, $cartMsg, $replyMarkup);
            }
            if (empty($res['ok'])) {
                $this->sendMessage($chatId, $cartMsg, $replyMarkup);
            }
            return;
        }

        if ($step === 'waiting_phone') {
            $convertedPhone = $this->convertMyanmarDigits($text);
            $cleanPhone = preg_replace('/[^0-9+]/', '', $convertedPhone);
            if (strlen($cleanPhone) < 7) {
                $errTpl = Setting::get('telegram_bot_invalid_phone_msg', '');
                $errMsg = !empty(trim($errTpl))
                    ? $this->replacePlaceholders($errTpl, ['customer_name' => $userName, 'user_name' => $userName])
                    : "⚠️ မှန်ကန်သော ဖုန်းနံပါတ် ရိုက်ထည့်ပေးပါရှင် (ဥပမာ- 09123456789 သို့မဟုတ် ၀၉၁၂၃၄၅၆၇၈၉) -";
                $this->sendMessage($chatId, $errMsg);
                return;
            }
            $normalizedPhone = $this->normalizePhoneNumber($convertedPhone);
            $session['phone'] = $normalizedPhone;
            $session['step'] = 'waiting_address';
            Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

            $phoneSavedTpl = Setting::get('telegram_bot_phone_saved_msg', "✅ ဖုန်းနံပါတ် `{phone}` အောင်မြင်စွာ မှတ်သားပြီးပါပြီရှင်။");
            $phoneSavedMsg = !empty(trim($phoneSavedTpl))
                ? $this->replacePlaceholders($phoneSavedTpl, ['phone' => $normalizedPhone, 'customer_name' => $userName, 'user_name' => $userName])
                : "✅ ဖုန်းနံပါတ် `{$normalizedPhone}` အောင်မြင်စွာ မှတ်သားပြီးပါပြီရှင်။";

            $askAddr = "{$phoneSavedMsg}\n\n"
                     . $this->formatAskAddressMessage($userName, $session);
            $this->sendMessage($chatId, $askAddr, $this->getAddressKeyboard());
            $this->logOutgoingBotMessage($chatId, $askAddr);
            return;
        }

        if ($step === 'waiting_address') {
            $session['address'] = $text;
            $session['step'] = 'waiting_delivery';
            Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

            $addrSavedTpl = Setting::get('telegram_bot_address_saved_msg', "📍 လိပ်စာ အောင်မြင်စွာ မှတ်သားပြီးပါပြီရှင်။");
            $addrSavedMsg = !empty(trim($addrSavedTpl))
                ? $this->replacePlaceholders($addrSavedTpl, ['address' => $text, 'customer_name' => $userName, 'user_name' => $userName])
                : "📍 လိပ်စာ အောင်မြင်စွာ မှတ်သားပြီးပါပြီရှင်။";

            $this->sendMessage($chatId, $addrSavedMsg, ['remove_keyboard' => true]);
            $this->logOutgoingBotMessage($chatId, $addrSavedMsg);

            $isQuickCod = Setting::get('telegram_bot_checkout_quick_cod', '1') === '1';
            $isSkipTownship = Setting::get('telegram_bot_checkout_skip_township', '1') === '1';

            if ($isQuickCod) {
                $cityFee = (float)Setting::get('telegram_bot_delivery_city_fee', 0);
                $session['delivery_method'] = 'အိမ်အရောက်ပို့ (Cash on Delivery)';
                $session['shipping_fee'] = $cityFee;
                $itemsTotal = !empty($session['is_cart']) && !empty($session['items'])
                    ? (float)collect($session['items'])->sum('subtotal')
                    : ((float)($session['price'] ?? 0) * (float)($session['quantity'] ?? 1));
                $session['grand_total'] = $itemsTotal + $cityFee;
                $session['payment_type'] = 'Cash on Delivery';
                $session['payment_status'] = 'Unpaid';
                $session['step'] = 'completed';
                $this->finalizeOrder($chatId, $session, $userName, $usernameTag);
                return;
            }

            if ($isSkipTownship) {
                $cityFee = (float)Setting::get('telegram_bot_delivery_city_fee', 0);
                $session['delivery_method'] = 'အိမ်အရောက်ပို့ (Standard Delivery)';
                $session['shipping_fee'] = $cityFee;
                $itemsTotal = !empty($session['is_cart']) && !empty($session['items'])
                    ? (float)collect($session['items'])->sum('subtotal')
                    : ((float)($session['price'] ?? 0) * (float)($session['quantity'] ?? 1));
                $session['grand_total'] = $itemsTotal + $cityFee;
                $session['step'] = 'waiting_payment';
                Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));
                $this->sendPaymentSelection($chatId, $session);
                return;
            }

            $this->sendDeliveryMethodSelection($chatId, $session);
            return;
        }

        if ($step === 'waiting_delivery') {
            if (mb_strlen($text) >= 3 && !in_array(mb_strtolower($text), ['hi', 'hello', 'မင်္ဂလာပါ', 'ok'])) {
                $session['address'] = $text;
                Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));
                $this->sendMessage($chatId, "📍 လိပ်စာအား `{$text}` အဖြစ် ပြင်ဆင်မှတ်သားပြီးပါပြီရှင်။");
            }
            $this->sendDeliveryMethodSelection($chatId, $session);
            return;
        }

        if ($step === 'waiting_payment') {
            $this->sendMessage($chatId, "💳 ငွေပေးချေမည့် နည်းလမ်းကို အောက်ပါ ခလုတ်များမှ ရွေးချယ်ပေးပါရှင်-");
            $this->sendPaymentSelection($chatId, $session);
            return;
        }

        if ($step === 'waiting_slip') {
            $askSlip = $this->formatAskSlipMessage($userName, $session);
            $this->sendMessage($chatId, $askSlip);
            return;
        }
    }

    /**
     * Send Delivery Method & Shipping Fee Selection (Enforces POS Delivery Zones SSOT)
     */
    public function sendDeliveryMethodSelection(int|string $chatId, array $session, ?int $messageIdToEdit = null): void
    {
        $allowPickup = Setting::get('telegram_bot_allow_store_pickup', '1') === '1'
            && Setting::get('telegram_bot_show_delivery_pickup', '1') === '1';
        $showCity = Setting::get('telegram_bot_show_delivery_city', '1') === '1';
        $showExpress = Setting::get('telegram_bot_show_delivery_express', '1') === '1';

        $pickupFee = (float)Setting::get('telegram_bot_delivery_store_pickup_fee', 0);
        $pickupFeeStr = $pickupFee > 0 ? number_format($pickupFee) . " MMK" : "အခမဲ့ (Free)";
        $expressFee = (float)Setting::get('telegram_bot_delivery_express_fee', 3000);
        $expressFeeStr = $expressFee > 0 ? number_format($expressFee) . " MMK" : "အခမဲ့ (Free)";

        $pickupLabel = trim((string)Setting::get('telegram_bot_delivery_store_pickup_label', '🏢 ဆိုင်တွင် ကိုယ်တိုင်လာယူမည် (Store Pickup)'));
        $cityLabel = trim((string)Setting::get('telegram_bot_delivery_city_label', '🚚 မြို့တွင်း အိမ်အရောက်ပို့ (City Delivery)'));
        $expressLabel = trim((string)Setting::get('telegram_bot_delivery_express_label', '📦 နယ်ဝေး ကားဂိတ်ပို့ဆောင် (Highway Bus Gate)'));
        $btnCancel = trim((string)Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)'));
        $showCancel = Setting::get('telegram_bot_show_catalog_nav_cancel', Setting::get('telegram_bot_show_catalog_btn_cancel', '1')) === '1';

        $activeRegions = DeliveryRegion::where('is_active', true)
            ->withCount('activeTownships')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $totalTownships = DeliveryTownship::where('is_active', true)->count();

        // If Store Pickup and Express are disabled and only City Delivery is active, route directly to Region/Township Selection
        if (!$allowPickup && !$showExpress && $showCity) {
            if ($activeRegions->count() === 1) {
                $this->sendTownshipSelection($chatId, $session, $messageIdToEdit, $activeRegions->first()->id);
            } else {
                $this->sendRegionSelection($chatId, $session, $messageIdToEdit);
            }
            return;
        }

        $msg = "🚚 *ပို့ဆောင်မှု နည်းလမ်း ရွေးချယ်ပေးပါရှင်*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "လူကြီးမင်း၏ အော်ဒါအား ပို့ဆောင်ပေးရမည့် နည်းလမ်းကို ရွေးချယ်ပေးပါ-\n\n";

        if ($showCity) {
            $msg .= "▫️ *{$cityLabel}*\n"
                  . "   မြို့နယ် ({$totalTownships}) ခုသို့ သတ်မှတ်နှုန်းထားများအတိုင်း ပို့ဆောင်ပေးပါသည်\n\n";
        }
        if ($allowPickup) {
            $msg .= "▫️ *{$pickupLabel}*\n"
                  . "   ကျသင့်ငွေ: {$pickupFeeStr}\n\n";
        }
        if ($showExpress) {
            $msg .= "▫️ *{$expressLabel}*\n"
                  . "   ကျသင့်ငွေ: {$expressFeeStr}\n\n";
        }

        $msg .= "━━━━━━━━━━━━━━━━━━━━━\n\n"
              . "ရွေးချယ်လိုသည့် နည်းလမ်းခလုတ်ကို နှိပ်ပေးပါ-";

        $buttons = [];
        if ($showCity) {
            $buttons[] = [
                ['text' => (!empty($cityLabel) ? $cityLabel : "🚚 ဒေသအလိုက် အိမ်အရောက်ပို့") . " ({$totalTownships} မြို့နယ်)", 'callback_data' => 'deliv_city'],
            ];
        }
        if ($allowPickup) {
            $buttons[] = [
                ['text' => (!empty($pickupLabel) ? $pickupLabel : "🏢 ဆိုင်တွင် လာယူမည်") . " ({$pickupFeeStr})", 'callback_data' => 'deliv_pickup'],
            ];
        }
        if ($showExpress) {
            $buttons[] = [
                ['text' => (!empty($expressLabel) ? $expressLabel : "📦 နယ်ဝေး ကားဂိတ်ပို့ဆောင်") . " ({$expressFeeStr})", 'callback_data' => 'deliv_express'],
            ];
        }
        if ($showCancel) {
            $buttons[] = [
                ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
            ];
        }

        $this->editOrSendMessage($chatId, $messageIdToEdit, $msg, ['inline_keyboard' => $buttons]);
    }

    /**
     * Send Region Selection Menu (Dynamic Regions from POS Database)
     */
    public function sendRegionSelection(int|string $chatId, array $session, ?int $messageIdToEdit = null): void
    {
        $regions = DeliveryRegion::where('is_active', true)
            ->withCount(['activeTownships'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $msg = "🏙️ *ပို့ဆောင်ရမည့် တိုင်းဒေသကြီး / ပြည်နယ် ရွေးချယ်ပေးပါရှင်*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "လူကြီးမင်း ပစ္စည်းလက်ခံမည့် ဒေသကို အောက်ပါတို့မှ ရွေးချယ်ပေးပါ-";

        $buttons = [];
        foreach ($regions as $reg) {
            $name = $reg->name_mm ?? $reg->name;
            $countStr = $reg->active_townships_count > 0 ? " ({$reg->active_townships_count} မြို့နယ်)" : '';
            $buttons[] = [
                [
                    'text' => "🏢 {$name}{$countStr}",
                    'callback_data' => "deliv_reg_{$reg->id}",
                ]
            ];
        }

        $buttons[] = [
            ['text' => '🔙 ပို့ဆောင်မှု နည်းလမ်း ပြန်ရွေးမည်', 'callback_data' => 'deliv_tsp_back'],
        ];
        $buttons[] = [
            ['text' => '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
        ];

        $this->editOrSendMessage($chatId, $messageIdToEdit, $msg, ['inline_keyboard' => $buttons]);
    }

    /**
     * Send Township Selection Menu (Dynamic Townships from POS Database)
     */
    public function sendTownshipSelection(int|string $chatId, array $session, ?int $messageIdToEdit = null, ?int $regionId = null): void
    {
        $query = DeliveryTownship::where('is_active', true)->with('region');
        $regionName = '';

        if ($regionId) {
            $query->where('delivery_region_id', $regionId);
            $region = DeliveryRegion::find($regionId);
            if ($region) {
                $rTitle = $region->name_mm ?? $region->name;
                $regionName = !empty($rTitle) ? " [{$rTitle}]" : '';
            }
        }

        $townships = $query->orderBy('sort_order')->orderBy('id')->get();

        if ($townships->isEmpty()) {
            $townships = DeliveryTownship::where('is_active', true)->with('region')->orderBy('sort_order')->get();
        }

        $itemsTotal = !empty($session['is_cart']) && !empty($session['items'])
            ? (float)collect($session['items'])->sum('subtotal')
            : (float)($session['price'] ?? 0) * (float)($session['quantity'] ?? 1);

        $msg = "🛵 *ပို့ဆောင်ရမည့် မြို့နယ် ရွေးချယ်ပေးပါရှင်*{$regionName}\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "သတ်မှတ်ထားသော ပို့ဆောင်ခနှုန်းထားများ ဖြစ်ပါသည်ရှင်။\n\n"
             . "သက်ဆိုင်ရာ မြို့နယ်ခလုတ်ကို နှိပ်ပေးပါ-";

        $buttons = [];
        foreach ($townships as $tsp) {
            $effectiveFee = (float)$tsp->delivery_fee;
            $isFree = $tsp->free_delivery_threshold && $itemsTotal >= (float)$tsp->free_delivery_threshold;
            $feeStr = $isFree ? 'အခမဲ့ Free' : '+' . number_format($effectiveFee) . ' K';
            $tspTitle = $tsp->name_mm ?? $tsp->name;

            $buttons[] = [
                [
                    'text' => "📍 {$tspTitle} ({$feeStr})",
                    'callback_data' => "deliv_tsp_{$tsp->id}",
                ]
            ];
        }

        $totalRegionsCount = DeliveryRegion::where('is_active', true)->count();
        if ($totalRegionsCount > 1) {
            $buttons[] = [['text' => '🔙 အခြားတိုင်း/ဒေသ ရွေးမည်', 'callback_data' => 'deliv_tsp_regions']];
        } else {
            $buttons[] = [['text' => '🔙 နည်းလမ်း ပြန်ရွေးမည်', 'callback_data' => 'deliv_tsp_back']];
        }
        $buttons[] = [['text' => '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel']];

        $this->editOrSendMessage($chatId, $messageIdToEdit, $msg, ['inline_keyboard' => $buttons]);
    }

    /**
     * Get Configured Township Delivery Rates (Township Delivery Matrix)
     */
    public function getTownshipDeliveryRates(): array
    {
        $townships = DeliveryTownship::where('is_active', true)->with('region')->orderBy('sort_order')->get();
        if ($townships->isNotEmpty()) {
            return $townships->map(function ($t) {
                return [
                    'key' => (string)$t->id,
                    'name' => $t->name_mm ? "{$t->name_mm} ({$t->name})" : $t->name,
                    'fee' => (float)$t->delivery_fee,
                    'region' => $t->region?->name ?? 'Yangon',
                ];
            })->toArray();
        }

        return [
            ['key' => '1', 'name' => 'ကမာရွတ် (Kamayut)', 'fee' => 2000, 'region' => 'Yangon'],
            ['key' => '2', 'name' => 'စမ်းချောင်း (Sanchaung)', 'fee' => 2000, 'region' => 'Yangon'],
        ];
    }

    /**
     * Send Payment Selection Buttons (Directly integrated with POS Payment Methods SSOT)
     */
    public function sendPaymentSelection(int|string $chatId, array $session, ?int $messageIdToEdit = null): void
    {
        $isCart = !empty($session['is_cart']) && !empty($session['items']);
        $itemsTotal = 0.0;
        if ($isCart) {
            $itemsTotal = (float)collect($session['items'])->sum('subtotal');
        } else {
            $itemsTotal = (float)($session['price'] ?? 0) * (float)($session['quantity'] ?? 1);
        }

        $shippingFee = (float)($session['shipping_fee'] ?? 0);
        $grandTotal = $itemsTotal + $shippingFee;
        $session['grand_total'] = $grandTotal;
        Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

        $totalFormatted = number_format($grandTotal);
        $itemsTotalFormatted = number_format($itemsTotal);
        $delivMethod = $session['delivery_method'] ?? 'ပို့ဆောင်မှု';
        $phoneDisplay = !empty($session['phone']) ? $session['phone'] : '-';
        $addressDisplay = !empty($session['address']) ? $session['address'] : 'ဆိုင်တွင် လာယူမည်';

        if ($isCart) {
            $indexIcons = [1 => '1️⃣', 2 => '2️⃣', 3 => '3️⃣', 4 => '4️⃣', 5 => '5️⃣', 6 => '6️⃣', 7 => '7️⃣', 8 => '8️⃣', 9 => '9️⃣', 10 => '🔟'];
            $itemsList = collect($session['items'])->values()->map(function ($it, $idx) use ($indexIcons) {
                $num = $idx + 1;
                $badge = $indexIcons[$num] ?? "{$num}.";
                $name = !empty($it['variant_name']) ? "{$it['product_name']} ({$it['variant_name']})" : $it['product_name'];
                $qty = (floor($it['quantity']) == $it['quantity']) ? (int)$it['quantity'] : $it['quantity'];
                $unit = !empty($it['unit_name']) ? $it['unit_name'] : 'ခု';
                $price = number_format($it['price'] ?? 0);
                $sub = number_format($it['subtotal'] ?? 0);
                return "{$badge} *{$name}*\n   ▫️ {$qty} {$unit} × {$price}  =  *{$sub} MMK*";
            })->implode("\n\n");

            $shipLine = $shippingFee > 0
                ? "🚚 ပို့ဆောင်ခ ({$delivMethod}):  *+" . number_format($shippingFee) . " MMK*\n"
                : "🚚 ပို့ဆောင်ခ ({$delivMethod}):  *အခမဲ့ (0 MMK)*\n";

            $msg = "💳 *ငွေပေးချေမှု နည်းလမ်း ရွေးချယ်ပါ*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "📦 *မှာယူသော ပစ္စည်းများ (" . count($session['items']) . " မျိုး):*\n\n"
                 . "{$itemsList}\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "💵 ပစ္စည်းကျသင့်ငွေ:  *{$itemsTotalFormatted} MMK*\n"
                 . $shipLine
                 . "💰 စုစုပေါင်း ကျသင့်ငွေ:  *{$totalFormatted} MMK*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "📞 ဖုန်းနံပါတ်:  `{$phoneDisplay}`\n"
                 . "📍 ပို့ဆောင်ရန်:  {$addressDisplay}\n\n"
                 . "ငွေပေးချေလိုသည့် ပုံစံကို ရွေးချယ်ပါ-";
        } else {
            $itemTitle = !empty($session['variant_name'])
                ? "{$session['product_name']} ({$session['variant_name']})"
                : ($session['product_name'] ?? 'ကုန်ပစ္စည်း');

            $pmTpl = Setting::get('telegram_bot_select_payment_msg', '');
            if (!empty(trim($pmTpl))) {
                $msg = $this->replacePlaceholders($pmTpl, [
                    'product_name' => $itemTitle,
                    'quantity' => (string)($session['quantity'] ?? 1),
                    'amount' => $totalFormatted,
                    'grand_total' => $totalFormatted,
                    'phone' => $phoneDisplay,
                    'address' => $addressDisplay,
                    'customer_name' => $session['customer_name'] ?? '',
                ]);
            } else {
                $shipLine = $shippingFee > 0
                    ? "🚚 ပို့ဆောင်ခ ({$delivMethod}):  *+" . number_format($shippingFee) . " MMK*\n"
                    : "🚚 ပို့ဆောင်ခ ({$delivMethod}):  *အခမဲ့ (0 MMK)*\n";

                $msg = "💳 *ငွေပေးချေမှု နည်းလမ်း ရွေးချယ်ပါ*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "📦 *မှာယူသော ပစ္စည်း:*\n"
                     . "1️⃣ *{$itemTitle}*\n"
                     . "   ▫️ " . ($session['quantity'] ?? 1) . " ခု × " . number_format((float)($session['price'] ?? 0)) . "  =  *{$itemsTotalFormatted} MMK*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "💵 ပစ္စည်းကျသင့်ငွေ:  *{$itemsTotalFormatted} MMK*\n"
                     . $shipLine
                     . "💰 စုစုပေါင်း ကျသင့်ငွေ:  *{$totalFormatted} MMK*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "📞 ဖုန်းနံပါတ်:       `{$phoneDisplay}`\n"
                     . "📍 ပို့ဆောင်ရန်:       {$addressDisplay}\n\n"
                     . "ငွေပေးချေလိုသည့် ပုံစံကို ရွေးချယ်ပါ-";
            }
        }

        $buttons = [];
        $allowUnpaid = Setting::get('telegram_bot_allow_unpaid', '1') === '1';
        $allowPaid = Setting::get('telegram_bot_allow_paid', '1') === '1';
        $showBtnKpay = Setting::get('telegram_bot_show_btn_kpay', '1') === '1';
        $showBtnWave = Setting::get('telegram_bot_show_btn_wave', '1') === '1';
        $showCancel = Setting::get('telegram_bot_show_catalog_nav_cancel', Setting::get('telegram_bot_show_catalog_btn_cancel', '1')) === '1';

        // Fetch active payment methods configured for Telegram Bot from Database
        $activeMethods = PaymentMethod::where('is_active', true)
            ->where('is_bot_available', true)
            ->orderBy('is_default', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        // Separate cash/COD methods and digital/banking methods
        $cashMethods = $activeMethods->filter(function ($m) {
            $code = strtoupper($m->code ?? '');
            $name = strtolower($m->name);
            return $code === 'CASH' || str_contains($name, 'cash') || str_contains($name, 'cod');
        });

        $paidMethods = $activeMethods->filter(function ($m) {
            $code = strtoupper($m->code ?? '');
            $name = strtolower($m->name);
            return !($code === 'CASH' || str_contains($name, 'cash') || str_contains($name, 'cod'));
        });

        // 1. Unpaid / Cash on Delivery buttons
        $btnCod = Setting::get('telegram_bot_btn_cod', '💵 ပစ္စည်းရောက်မှငွေချေ (COD)');
        $btnKpay = Setting::get('telegram_bot_btn_kpay', '📱 KBZPay ဖြင့်လွှဲမည်');
        $btnWave = Setting::get('telegram_bot_btn_wave', '📱 WavePay ဖြင့်လွှဲမည်');
        $btnCancel = Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)');

        if ($allowUnpaid) {
            if ($cashMethods->isNotEmpty()) {
                foreach ($cashMethods as $cm) {
                    $buttons[] = [['text' => "💵 {$cm->name} (ပစ္စည်းရောက်မှငွေချေ)", 'callback_data' => "pay_pm_{$cm->id}"]];
                }
            } else {
                $buttons[] = [['text' => !empty($btnCod) ? $btnCod : '💵 ပစ္စည်းရောက်မှငွေချေ (COD)', 'callback_data' => 'pay_cod']];
            }
        }

        // 2. Paid / Mobile Banking buttons
        if ($allowPaid) {
            if ($paidMethods->isNotEmpty()) {
                foreach ($paidMethods as $pm) {
                    $codeLower = strtolower($pm->code ?? '');
                    $nameLower = strtolower($pm->name ?? '');
                    if (($codeLower === 'kpay' || str_contains($nameLower, 'kpay') || str_contains($nameLower, 'kbzpay')) && !$showBtnKpay) {
                        continue;
                    }
                    if (($codeLower === 'wavepay' || str_contains($nameLower, 'wave')) && !$showBtnWave) {
                        continue;
                    }
                    $icon = match ($codeLower) {
                        'kpay' => '📱',
                        'wavepay' => '📱',
                        'ayapay' => '📱',
                        'cbpay' => '📱',
                        default => '💳',
                    };
                    $buttons[] = [
                        ['text' => "{$icon} {$pm->name} ဖြင့်လွှဲမည်", 'callback_data' => "pay_pm_{$pm->id}"]
                    ];
                }
            } else {
                // Fallback default buttons if DB has no specific mobile methods
                if ($showBtnKpay) {
                    $buttons[] = [
                        ['text' => !empty($btnKpay) ? $btnKpay : '📱 KBZPay ဖြင့်လွှဲမည်', 'callback_data' => 'pay_kbzpay'],
                    ];
                }
                if ($showBtnWave) {
                    $buttons[] = [
                        ['text' => !empty($btnWave) ? $btnWave : '📱 WavePay ဖြင့်လွှဲမည်', 'callback_data' => 'pay_wavepay'],
                    ];
                }
            }
        }

        if ($showCancel) {
            $buttons[] = [['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel']];
        }

        $this->editOrSendMessage($chatId, $messageIdToEdit, $msg, [
            'inline_keyboard' => $buttons,
        ]);
    }

    /**
     * 📸 Intelligent Photo Handler (Product Visual Search vs Payment Slip vs Prescription)
     */
    protected function handlePhotoMessage(
        int|string $chatId,
        array $photoArray,
        string $userName,
        string $usernameTag,
        string $caption = ''
    ): void {
        $highestRes = end($photoArray);
        $fileId = $highestRes['file_id'] ?? null;
        $fileUniqueId = $highestRes['file_unique_id'] ?? null;
        if (!$fileId) {
            $photoErrTpl = Setting::get('telegram_bot_photo_error_msg', '');
            $photoErrMsg = !empty(trim($photoErrTpl))
                ? $this->replacePlaceholders($photoErrTpl, ['customer_name' => $userName, 'user_name' => $userName])
                : "⚠️ ဓာတ်ပုံ ရယူ၍ မရပါရှင်။ ကျေးဇူးပြု၍ ပြန်လည် ပေးပို့ပေးပါရှင်။";
            $this->sendMessage($chatId, $photoErrMsg);
            return;
        }

        // Show uploading photo indicator while fetching and processing
        $this->sendChatAction($chatId, 'upload_photo');

        $download = $this->downloadTelegramFileWithHash($fileId);
        if (!$download || empty($download['url'])) {
            $photoErrTpl = Setting::get('telegram_bot_photo_error_msg', '');
            $photoErrMsg = !empty(trim($photoErrTpl))
                ? $this->replacePlaceholders($photoErrTpl, ['customer_name' => $userName, 'user_name' => $userName])
                : "⚠️ ဓာတ်ပုံ ရယူ၍ မရပါရှင်။ ကျေးဇူးပြု၍ ပြန်လည် ပေးပို့ပေးပါရှင်။";
            $this->sendMessage($chatId, $photoErrMsg);
            return;
        }

        $photoUrl = $download['url'];

        // 1. Record incoming customer photo message in Live Chat
        try {
            $custMsgText = !empty($caption) ? $caption : '[ဓာတ်ပုံ ပေးပို့ခဲ့သည်]';
            BotChatMessage::create([
                'telegram_chat_id' => (string)$chatId,
                'customer_name' => $userName,
                'telegram_username' => $usernameTag,
                'sender_type' => 'customer',
                'message_type' => 'photo',
                'message_text' => $custMsgText,
                'metadata' => [
                    'image_url' => $photoUrl,
                    'file_id' => $fileId,
                    'caption' => $caption,
                ],
                'is_read' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Log BotChatMessage photo error: ' . $e->getMessage());
        }

        // 2. CONTEXT 1: Active Checkout Session in Progress
        $session = Cache::get("tg_session_{$chatId}");
        $isInCheckout = $session && (!empty($session['product_id']) || (!empty($session['is_cart']) && !empty($session['items'])));

        $captionLower = mb_strtolower($caption);
        $isExplicitSlipCaption = str_contains($captionLower, 'slip') 
            || str_contains($captionLower, 'စလစ်') 
            || str_contains($captionLower, 'ပြေစာ') 
            || str_contains($captionLower, 'ငွေလွှဲ') 
            || str_contains($captionLower, 'kpay') 
            || str_contains($captionLower, 'wave') 
            || str_contains($captionLower, 'kbzpay');

        if ($isInCheckout || $isExplicitSlipCaption) {
            $this->processPaymentSlipUpload($chatId, $fileId, $userName, $usernameTag, $fileUniqueId, $download);
            return;
        }

        // Operating Mode: Standard Mode Check (zero AI Vision API calls)
        $operatingMode = $this->aiAgentService->getOperatingMode();
        if ($operatingMode === 'standard') {
            $this->notifyAdminCustomerNeedsHelp(
                $chatId,
                $userName,
                $usernameTag,
                "Customer sent photo in Standard Mode: " . ($caption ?: "Please review photo")
            );

            $stdPhotoMsg = "📷 *ဓာတ်ပုံ လက်ခံရရှိပါသည်ရှင်။*\n\nဆိုင်တာဝန်ခံ/ဝန်ထမ်းထံသို့ ပေးပို့ထားပြီး မကြာမီ စစ်ဆေးအကြောင်းပြန်ပေးပါမည်ရှင်။ 🙏\n\n💡 _ငွေလွှဲစလစ် ပေးပို့လိုပါက ပစ္စည်းမှာယူပြီးမှ ပေးပို့ပေးပါရန် မေတ္တာရပ်ခံအပ်ပါသည်ရှင်။_";
            $this->sendMessage($chatId, $stdPhotoMsg, [
                'inline_keyboard' => [
                    [
                        ['text' => '🛍️ ကုန်ပစ္စည်းများ ကြည့်မည်', 'callback_data' => 'nav_all_products'],
                        ['text' => '🛒 ခြင်းတောင်း ကြည့်မည်', 'callback_data' => 'cart_view'],
                    ],
                    [
                        ['text' => '👨‍💼 ဆိုင်ဝန်ထမ်းနှင့် စကားပြောမည်', 'callback_data' => 'ask_human'],
                        ['text' => '🏠 မူလစာမျက်နှာ', 'callback_data' => 'catalog_main'],
                    ]
                ]
            ]);
            return;
        }

        // 3. CONTEXT 2: AI Agent Enabled -> Multimodal Visual Search
        if ($this->aiAgentService->isEnabled() && !$this->aiAgentService->isAiPausedForChat($chatId)) {
            $this->sendChatAction($chatId, 'typing');

            $aiRes = $this->aiAgentService->handleCustomerImageQuery(
                $chatId,
                $userName,
                $download['contents'] ?? '',
                $download['mime'] ?? 'image/jpeg',
                $caption,
                $usernameTag
            );

            if ($aiRes) {
                $intent = $aiRes['image_intent'] ?? 'product_inquiry';

                // Case A: AI detected it IS a bank transfer slip
                if ($intent === 'payment_slip') {
                    $this->processPaymentSlipUpload($chatId, $fileId, $userName, $usernameTag, $fileUniqueId, $download);
                    return;
                }

                // Case B: AI detected a doctor's medical prescription
                if ($intent === 'prescription') {
                    BotChatMessage::create([
                        'telegram_chat_id' => (string)$chatId,
                        'customer_name' => $userName,
                        'telegram_username' => $usernameTag,
                        'sender_type' => 'ai',
                        'message_type' => 'text',
                        'message_text' => $aiRes['reply'],
                        'metadata' => [
                            'prescription_detected' => true,
                            'image_url' => $photoUrl,
                        ],
                        'is_read' => true,
                        'needs_human' => true,
                    ]);

                    $this->aiAgentService->pauseAiForChat($chatId, 120);
                    $this->notifyAdminCustomerNeedsHelp($chatId, $userName, $usernameTag, "Customer sent prescription photo: " . ($caption ?: "Please review prescription"));

                    $keyboard = [
                        'inline_keyboard' => [
                            [
                                ['text' => '👨‍💼 ဆိုင်တာဝန်ခံနှင့် တိုက်ရိုက်ပြောမည်', 'callback_data' => 'ask_human'],
                                ['text' => '🏠 ပင်မ မီနူး', 'callback_data' => 'catalog_main'],
                            ],
                        ],
                    ];
                    $this->sendMessage($chatId, $aiRes['reply'], $keyboard);
                    return;
                }

                // Case C: AI detected a product / item inquiry ("ဒါလေးရှိလား")
                if ($intent === 'product_inquiry' || !empty($aiRes['matched_product_ids'])) {
                    $matchedIds = $aiRes['matched_product_ids'] ?? [];

                    BotChatMessage::create([
                        'telegram_chat_id' => (string)$chatId,
                        'customer_name' => $userName,
                        'telegram_username' => $usernameTag,
                        'sender_type' => 'ai',
                        'message_type' => 'text',
                        'message_text' => $aiRes['reply'],
                        'metadata' => [
                            'recommended_product_ids' => $matchedIds,
                            'detected_item' => $aiRes['detected_item'] ?? null,
                            'visual_match' => true,
                            'customer_image_url' => $photoUrl,
                            'suggested_action' => $aiRes['suggested_action'] ?? 'order',
                        ],
                        'is_read' => true,
                        'needs_human' => !empty($aiRes['needs_human']),
                    ]);

                    // 1. Send AI vision recognition text
                    $this->sendMessage($chatId, $aiRes['reply']);

                    // 2. Send the matched product card(s)
                    if (!empty($matchedIds)) {
                        Cache::put("tg_last_visual_product_{$chatId}", $matchedIds[0], now()->addMinutes(30));

                        $recProducts = Product::whereIn('id', (array)$matchedIds)
                            ->where('is_bot_available', true)
                            ->where(function ($q) {
                                $q->whereNull('is_active')->orWhere('is_active', true)->orWhere('status', 'Active');
                            })
                            ->take(3)
                            ->get();

                        $vIdx = 1;
                        foreach ($recProducts as $rp) {
                            $this->sendProductCardItem($chatId, $rp, $vIdx++, true);
                        }
                    }

                    $navRow = [
                        ['text' => '👨‍💼 ဝန်ထမ်းနှင့် စကားပြောမည်', 'callback_data' => 'ask_human'],
                        ['text' => '🏠 ပင်မ မီနူး', 'callback_data' => 'catalog_main'],
                    ];
                    $this->sendMessage($chatId, "👇 _အခြား အကူအညီ လိုအပ်ပါက ရွေးချယ်နိုင်ပါသည်ရှင်_", [
                        'inline_keyboard' => [$navRow]
                    ]);
                    return;
                }

                // Case D: Other image intent with AI reply
                if (!empty($aiRes['reply'])) {
                    BotChatMessage::create([
                        'telegram_chat_id' => (string)$chatId,
                        'customer_name' => $userName,
                        'telegram_username' => $usernameTag,
                        'sender_type' => 'ai',
                        'message_type' => 'text',
                        'message_text' => $aiRes['reply'],
                        'metadata' => [
                            'customer_image_url' => $photoUrl,
                        ],
                        'is_read' => true,
                        'needs_human' => !empty($aiRes['needs_human']),
                    ]);

                    $this->sendMessage($chatId, $aiRes['reply'], $this->getMainKeyboard($chatId));
                    return;
                }
            }
        }

        // 4. FALLBACK: Process payment slip logic
        $this->processPaymentSlipUpload($chatId, $fileId, $userName, $usernameTag, $fileUniqueId, $download);
    }

    /**
     * Handle Document message (Payment Slip or uncompressed image)
     */
    protected function handleDocumentMessage(
        int|string $chatId,
        array $document,
        string $userName,
        string $usernameTag,
        string $caption = ''
    ): void {
        $mime = $document['mime_type'] ?? '';
        $fileName = strtolower($document['file_name'] ?? '');
        $isImage = str_starts_with($mime, 'image/') || preg_match('/\.(jpg|jpeg|png|webp|bmp)$/i', $fileName);

        if (!$isImage) {
            $invalidDocTpl = Setting::get('telegram_bot_invalid_slip_doc_msg', '');
            $invalidDocMsg = !empty(trim($invalidDocTpl))
                ? $this->replacePlaceholders($invalidDocTpl, ['customer_name' => $userName, 'user_name' => $userName])
                : "⚠️ ကျေးဇူးပြု၍ ကုန်ပစ္စည်း သို့မဟုတ် ငွေလွှဲပြေစာ ဓာတ်ပုံ (Image) သာ ပေးပို့ပေးပါရှင်။ 🙏";
            $this->sendMessage($chatId, $invalidDocMsg, $this->getMainKeyboard($chatId));
            return;
        }

        $fileId = $document['file_id'] ?? null;
        $fileUniqueId = $document['file_unique_id'] ?? null;
        if (!$fileId) {
            $photoErrTpl = Setting::get('telegram_bot_photo_error_msg', '');
            $photoErrMsg = !empty(trim($photoErrTpl))
                ? $this->replacePlaceholders($photoErrTpl, ['customer_name' => $userName, 'user_name' => $userName])
                : "⚠️ ဓာတ်ပုံ ရယူ၍ မရပါရှင်။ ကျေးဇူးပြု၍ ပြန်လည် ပေးပို့ပေးပါရှင်။";
            $this->sendMessage($chatId, $photoErrMsg);
            return;
        }

        $fakePhotoArray = [
            ['file_id' => $fileId, 'file_unique_id' => $fileUniqueId]
        ];
        $this->handlePhotoMessage($chatId, $fakePhotoArray, $userName, $usernameTag, $caption);
    }

    /**
     * Common processor for Payment Slip Uploads (Photo or Document)
     */
    protected function processPaymentSlipUpload(
        int|string $chatId,
        string $fileId,
        string $userName,
        string $usernameTag,
        ?string $fileUniqueId = null,
        ?array $preloadedDownload = null
    ): void {
        $download = $preloadedDownload ?: $this->downloadTelegramFileWithHash($fileId);
        if (!$download || empty($download['url'])) {
            $photoErrTpl = Setting::get('telegram_bot_photo_error_msg', '');
            $photoErrMsg = !empty(trim($photoErrTpl))
                ? $this->replacePlaceholders($photoErrTpl, ['customer_name' => $userName, 'user_name' => $userName])
                : "⚠️ ဓာတ်ပုံ ရယူ၍ မရပါရှင်။ ကျေးဇူးပြု၍ ပြန်လည် ပေးပို့ပေးပါရှင်။";
            $this->sendMessage($chatId, $photoErrMsg);
            return;
        }

        $slipUrl = $download['url'];
        $slipHash = $download['hash'] ?? null;

        // 🛡️ Anti-Fraud Duplicate Payment Slip Detection (SHA-256 + Telegram file_unique_id)
        $duplicateRef = null;
        if ($slipHash && Cache::has("tg_slip_hash_{$slipHash}")) {
            $duplicateRef = Cache::get("tg_slip_hash_{$slipHash}");
        } elseif ($fileUniqueId && Cache::has("tg_slip_uid_{$fileUniqueId}")) {
            $duplicateRef = Cache::get("tg_slip_uid_{$fileUniqueId}");
        }

        if ($duplicateRef) {
            $dupSlipTpl = Setting::get('telegram_bot_duplicate_slip_msg', '');
            if (!empty(trim($dupSlipTpl))) {
                $warnMsg = $this->replacePlaceholders($dupSlipTpl, [
                    'duplicate_ref' => $duplicateRef,
                    'reference_no' => $duplicateRef,
                    'customer_name' => $userName,
                    'user_name' => $userName,
                ]);
            } else {
                $warnMsg = "⚠️ *ငွေလွှဲပြေစာ ပယ်ချခံရပါသည် (Duplicate Slip Detected)*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━\n"
                         . "ဤငွေလွှဲပြေစာသည် ယခင်အော်ဒါ (`{$duplicateRef}`) တွင် အသုံးပြုပြီးဖြစ်ပါသဖြင့် ထပ်မံအသုံးပြု၍ မရနိုင်ပါရှင်။ 🙏\n\n"
                         . "ကျေးဇူးပြု၍ ငွေအသစ်လွှဲထားသော ပြေစာအသစ် (Recent Payment Slip) ကိုသာ ပေးပို့ပေးပါရန် မေတ္တာရပ်ခံအပ်ပါသည်ရှင်။";
            }
            $this->sendMessage($chatId, $warnMsg, $this->getMainKeyboard($chatId));
            return;
        }

        $session = Cache::get("tg_session_{$chatId}");

        // CASE 1: Active checkout session in progress (single product OR shopping cart)
        if ($session && (!empty($session['product_id']) || (!empty($session['is_cart']) && !empty($session['items'])))) {
            $session['payment_slip_url'] = $slipUrl;
            $session['payment_slip_hash'] = $slipHash;
            $session['payment_slip_uid'] = $fileUniqueId;
            $session['payment_status'] = 'Paid';
            if (empty($session['payment_type'])) {
                $session['payment_type'] = 'KBZPay';
            }

            $this->finalizeOrder($chatId, $session, $userName, $usernameTag);
            return;
        }

        // CASE 2: No active session - Check for recent order by this customer (within 7 days)
        $recentSale = Sale::where('telegram_chat_id', (string)$chatId)
            ->orderBy('id', 'desc')
            ->first();

        if ($recentSale && now()->diffInDays($recentSale->created_at) <= 7) {
            $meta = ($slipHash ? " [SLIP_HASH:{$slipHash}]" : "") . ($fileUniqueId ? " [SLIP_UID:{$fileUniqueId}]" : "");
            $recentSale->payment_slip_url = $slipUrl;
            $recentSale->payment_status = 'Paid';
            if ($recentSale->paid_amount <= 0) {
                $recentSale->paid_amount = $recentSale->grand_total;
                $recentSale->due_amount = 0.0;
            }
            $recentSale->notes = trim($recentSale->notes . $meta);
            $recentSale->save();

            // Anti-fraud register in cache for 30 days
            if ($slipHash) Cache::put("tg_slip_hash_{$slipHash}", $recentSale->reference_no, now()->addDays(30));
            if ($fileUniqueId) Cache::put("tg_slip_uid_{$fileUniqueId}", $recentSale->reference_no, now()->addDays(30));

            // Record payment in sale_payments if not recorded yet
            $hasPayment = SalePayment::where('sale_id', $recentSale->id)->exists();
            if (!$hasPayment) {
                SalePayment::create([
                    'sale_id' => $recentSale->id,
                    'reference_no' => 'PAY-' . date('Ymd') . '-' . rand(1000, 9999),
                    'payment_date' => now()->format('Y-m-d H:i:s'),
                    'payment_type' => $recentSale->payment_type ?: 'Mobile Banking',
                    'amount' => $recentSale->grand_total,
                    'received_amount' => $recentSale->grand_total,
                    'change_amount' => 0.0,
                    'notes' => 'Telegram Bot payment slip uploaded' . $meta,
                    'created_by' => 'Telegram Bot',
                    'user_name' => 'Telegram Bot',
                    'user_role' => 'Bot Channel',
                ]);
            }

            $reply = "📸 လူကြီးမင်း၏ အော်ဒါ `{$recentSale->reference_no}` အတွက် ငွေလွှဲပြေစာ (Payment Slip) ကို အောင်မြင်စွာ လက်ခံရရှိပါသည်ရှင်။\n\n"
                   . "ဆိုင်တာဝန်ခံမှ ငွေလွှဲပြေစာအား စစ်ဆေးအတည်ပြုပြီးပါက အကြောင်းကြားပေးပါမည်ရှင်။ ကျေးဇူးတင်ပါသည်! 🙏";
            $this->sendMessage($chatId, $reply, $this->getMainKeyboard($chatId));

            // Immediately notify admin(s) with the payment slip photo
            $this->notifyAdminPaymentSlipReceived($recentSale, $slipUrl);
            return;
        }

        // CASE 3: No active session and no recent order
        $slipTpl = Setting::get('telegram_bot_unexpected_slip_msg', '');
        $msg = !empty(trim($slipTpl))
            ? $this->replacePlaceholders($slipTpl, ['customer_name' => $userName, 'user_name' => $userName])
            : "📸 စလစ်ဓာတ်ပုံ လက်ခံရရှိပါသည်ရှင်။ လက်ရှိတွင် မှာယူဆဲ အော်ဒါမရှိသေးပါသဖြင့် /start ကို နှိပ်၍ အော်ဒါ အရင်တင်ပေးပါရှင်။ 🙏";
        $this->sendMessage($chatId, $msg, $this->getMainKeyboard($chatId));
    }

    /**
     * Handle Contact Shared
     */
    protected function handleContactMessage(int|string $chatId, string $phone, string $userName): void
    {
        $session = Cache::get("tg_session_{$chatId}");
        if ($session && $session['step'] === 'waiting_phone') {
            $normalizedPhone = $this->normalizePhoneNumber($phone);
            $session['phone'] = $normalizedPhone;
            $session['step'] = 'waiting_address';
            Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

            $phoneSavedTpl = Setting::get('telegram_bot_phone_saved_msg', "✅ ဖုန်းနံပါတ် `{phone}` အောင်မြင်စွာ မှတ်သားပြီးပါပြီရှင်။");
            $phoneSavedMsg = !empty(trim($phoneSavedTpl))
                ? $this->replacePlaceholders($phoneSavedTpl, ['phone' => $normalizedPhone, 'customer_name' => $userName, 'user_name' => $userName])
                : "✅ ဖုန်းနံပါတ် `{$normalizedPhone}` အောင်မြင်စွာ မှတ်သားပြီးပါပြီရှင်။";

            $askAddr = "{$phoneSavedMsg}\n\n"
                     . $this->formatAskAddressMessage($userName, $session);
            $this->sendMessage($chatId, $askAddr, $this->getAddressKeyboard());
        }
    }

    /**
     * Handle 1-Tap GPS Location Message
     */
    protected function handleLocationMessage(int|string $chatId, float $lat, float $lng, string $userName, string $usernameTag = ''): void
    {
        $session = Cache::get("tg_session_{$chatId}");
        if ($session && $session['step'] === 'waiting_address') {
            $mapsUrl = "https://maps.google.com/?q={$lat},{$lng}";
            $session['address'] = "📍 GPS တည်နေရာ ({$lat}, {$lng})\n🗺️ Maps: {$mapsUrl}";
            $session['latitude'] = $lat;
            $session['longitude'] = $lng;
            $session['step'] = 'waiting_delivery';
            Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));

            $ackTpl = Setting::get('telegram_bot_location_received_msg', '');
            if (!empty(trim($ackTpl))) {
                $ackMsg = $this->replacePlaceholders($ackTpl, [
                    'maps_url' => $mapsUrl,
                    'latitude' => (string)$lat,
                    'longitude' => (string)$lng,
                    'customer_name' => $userName,
                    'user_name' => $userName,
                ]);
            } else {
                $ackMsg = "📍 တည်နေရာ အောင်မြင်စွာ လက်ခံရရှိပါသည်ရှင်!\n🗺️ {$mapsUrl}";
            }
            $this->sendMessage($chatId, $ackMsg, ['remove_keyboard' => true]);

            $isQuickCod = Setting::get('telegram_bot_checkout_quick_cod', '1') === '1';
            $isSkipTownship = Setting::get('telegram_bot_checkout_skip_township', '1') === '1';

            if ($isQuickCod) {
                $cityFee = (float)Setting::get('telegram_bot_delivery_city_fee', 0);
                $session['delivery_method'] = 'အိမ်အရောက်ပို့ (City Delivery - GPS)';
                $session['shipping_fee'] = $cityFee;
                $itemsTotal = !empty($session['is_cart']) && !empty($session['items'])
                    ? (float)collect($session['items'])->sum('subtotal')
                    : ((float)($session['price'] ?? 0) * (float)($session['quantity'] ?? 1));
                $session['grand_total'] = $itemsTotal + $cityFee;
                $session['payment_type'] = 'Cash on Delivery';
                $session['payment_status'] = 'Unpaid';
                $session['step'] = 'completed';
                $this->finalizeOrder($chatId, $session, $userName, $usernameTag);
                return;
            }

            if ($isSkipTownship) {
                $cityFee = (float)Setting::get('telegram_bot_delivery_city_fee', 0);
                $session['delivery_method'] = 'အိမ်အရောက်ပို့ (City Delivery - GPS)';
                $session['shipping_fee'] = $cityFee;
                $itemsTotal = !empty($session['is_cart']) && !empty($session['items'])
                    ? (float)collect($session['items'])->sum('subtotal')
                    : ((float)($session['price'] ?? 0) * (float)($session['quantity'] ?? 1));
                $session['grand_total'] = $itemsTotal + $cityFee;
                $session['step'] = 'waiting_payment';
                Cache::put("tg_session_{$chatId}", $session, now()->addHours(2));
                $this->sendPaymentSelection($chatId, $session);
                return;
            }

            $this->sendDeliveryMethodSelection($chatId, $session);
            return;
        }

        $locAckTpl = Setting::get('telegram_bot_location_received_msg', '');
        $locAck = !empty(trim($locAckTpl))
            ? $this->replacePlaceholders($locAckTpl, ['maps_url' => '', 'customer_name' => $userName, 'user_name' => $userName])
            : "📍 တည်နေရာ လက်ခံရရှိပါသည်ရှင်။";
        $this->sendMessage($chatId, $locAck, $this->getMainKeyboard($chatId));
    }

    /**
     * Finalize Order & Insert into Database
     */
    public function finalizeOrder(int|string $chatId, array $session, string $userName, string $usernameTag): void
    {
        DB::beginTransaction();
        try {
            $isCart = !empty($session['is_cart']) && !empty($session['items']);
            $cartItems = $isCart ? $session['items'] : [];

            if (!$isCart) {
                $product = Product::find($session['product_id'] ?? 0);
                if (!$product) {
                    DB::rollBack();
                    $this->sendMessage($chatId, "⚠️ ကုန်ပစ္စည်း ရှာမတွေ့ပါသဖြင့် အော်ဒါမတင်နိုင်ပါ။");
                    return;
                }

                $qty = (float)($session['quantity'] ?? 1);
                $unitPrice = (float)$session['price'];
                $cartItems = [[
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'variant_id' => $session['variant_id'] ?? null,
                    'variant_name' => $session['variant_name'] ?? null,
                    'variant_sku' => $session['variant_sku'] ?? null,
                    'price' => $unitPrice,
                    'quantity' => $qty,
                    'unit_name' => $product->unit_name ?: 'ခု',
                    'subtotal' => $unitPrice * $qty,
                ]];
            }

            if (empty($cartItems)) {
                DB::rollBack();
                $this->sendMessage($chatId, "⚠️ မှာယူမည့် ပစ္စည်းများ ရှာမတွေ့ပါ။");
                return;
            }

            $subtotal = (float)collect($cartItems)->sum('subtotal');
            $totalQuantity = (float)collect($cartItems)->sum('quantity');
            $shippingFee = (float)($session['shipping_fee'] ?? 0.0);
            $grandTotal = $subtotal + $shippingFee;

            $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
            $warehouse = Outlet::find($warehouseId) ?: Outlet::first();
            $warehouseName = $warehouse?->name ?? 'Default Warehouse';

            $autoApprove = Setting::get('telegram_bot_auto_approve', '0') === '1';
            $botApprovalStatus = $autoApprove ? 'approved' : 'pending';
            $saleStatus = $autoApprove ? 'Completed' : 'Pending';

            $paymentType = $session['payment_type'] ?? 'Cash on Delivery';
            $paymentStatus = $session['payment_status'] ?? 'Unpaid';
            $paidAmount = ($paymentStatus === 'Paid') ? $grandTotal : 0.0;
            $dueAmount = max(0, $grandTotal - $paidAmount);

            $refNo = 'SALE-' . date('Ymd') . '-' . rand(1000, 9999);
            $customerPhone = $session['phone'] ?? '';
            $townshipInfo = $session['delivery_township'] ?? '';
            $rawAddress = $session['address'] ?? '';
            $shippingAddress = !empty($townshipInfo)
                ? ($townshipInfo . (!empty($rawAddress) ? " | {$rawAddress}" : ''))
                : $rawAddress;

            $customer = null;
            if (!empty($customerPhone)) {
                $customer = Customer::where('phone', $customerPhone)->first();
                if (!$customer) {
                    $customer = Customer::create([
                        'customer_id' => 'CUST-' . date('Ymd') . '-' . rand(1000, 9999),
                        'name' => !empty($userName) ? $userName : 'Telegram Customer',
                        'phone' => $customerPhone,
                        'tier' => 'Silver Regular',
                        'reward_points' => 0,
                        'total_spent' => (float)$grandTotal,
                        'total_visits' => 1,
                        'status' => 'Active',
                        'notes' => 'Registered via Telegram Bot (@' . ($usernameTag ?: $chatId) . ')' . (!empty($shippingAddress) ? " | Address: {$shippingAddress}" : ''),
                    ]);
                } else {
                    $customer->increment('total_visits');
                    $customer->increment('total_spent', $grandTotal);
                }
            }
            if (!$customer) {
                $customer = Customer::first();
            }

            $slipHash = $session['payment_slip_hash'] ?? null;
            $slipUid = $session['payment_slip_uid'] ?? null;
            $slipMeta = ($slipHash ? " [SLIP_HASH:{$slipHash}]" : "") . ($slipUid ? " [SLIP_UID:{$slipUid}]" : "");

            $hasPreorderItem = false;
            foreach ($cartItems as $it) {
                $curStk = (float)StockBalance::where('warehouse_id', $warehouseId)
                    ->where('product_id', $it['product_id'] ?? 0)
                    ->sum('quantity');
                if ($curStk <= 0) {
                    $hasPreorderItem = true;
                    break;
                }
            }
            $preorderMeta = $hasPreorderItem ? " [⚠️ Pre-order / Backorder Item]" : "";
            $aiMeta = !empty($session['ai_autonomous_order']) ? " [⚡ AI Autonomous Zero-Click Order]" : "";

            $sale = Sale::create([
                'reference_no' => $refNo,
                'customer_id' => $customer?->id,
                'customer_name' => !empty($userName) ? $userName : ($customer?->name ?? 'Walk-in Customer'),
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouseName,
                'date' => now()->format('Y-m-d H:i:s'),
                'status' => $saleStatus,
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'discount' => 0.0,
                'shipping' => $shippingFee,
                'subtotal' => $subtotal,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'change_amount' => 0.0,
                'payment_status' => $paymentStatus,
                'payment_type' => $paymentType,
                'notes' => 'Telegram Bot Order by ' . $userName . ' (' . ($usernameTag ?: $chatId) . ')' . (!empty($session['delivery_method']) ? ' | ပို့ဆောင်မှု: ' . $session['delivery_method'] : '') . (!empty($session['delivery_township']) ? ' | မြို့နယ်: ' . $session['delivery_township'] : '') . $slipMeta . $preorderMeta . $aiMeta,
                'pos_flag' => false,
                'order_source' => 'telegram_bot',
                'telegram_chat_id' => (string)$chatId,
                'telegram_username' => $usernameTag ?: $userName,
                'telegram_order_id' => $refNo,
                'bot_approval_status' => $botApprovalStatus,
                'payment_slip_url' => $session['payment_slip_url'] ?? null,
                'shipping_address' => $shippingAddress,
                'customer_phone' => $customerPhone,
                'created_by' => 'Telegram Bot',
                'user_name' => 'Telegram Bot',
                'user_role' => 'Bot Channel',
            ]);

            if ($slipHash) Cache::put("tg_slip_hash_{$slipHash}", $sale->reference_no, now()->addDays(30));
            if ($slipUid) Cache::put("tg_slip_uid_{$slipUid}", $sale->reference_no, now()->addDays(30));

            $unitService = app(ProductUnitConversionService::class);
            $fifoService = $autoApprove ? app(FifoInventoryService::class) : null;
            $totalSaleCogs = 0.0;
            $itemsSummaryLines = [];

            foreach ($cartItems as $itemData) {
                $product = Product::find($itemData['product_id']);
                if (!$product) continue;

                $vId = !empty($itemData['variant_id']) ? (int)$itemData['variant_id'] : null;
                $vName = $itemData['variant_name'] ?? null;
                $pCode = $itemData['variant_sku'] ?? ($product->code ?: $product->sku);
                $iQty = (float)$itemData['quantity'];
                $iPrice = (float)$itemData['price'];
                $iSubtotal = (float)($itemData['subtotal'] ?? ($iPrice * $iQty));

                $linkedUnit = $unitService->resolve($product, 'sale', null, $product->unit_name ?: 'Piece', $vId);
                $baseQty = $unitService->baseQuantity($iQty, $linkedUnit['conversion_rate']);
                $baseUnitPrice = $unitService->basePrice($iPrice, $linkedUnit['conversion_rate']);

                $saleItem = SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $vId,
                    'unit_id' => $linkedUnit['unit_id'],
                    'unit_name' => $linkedUnit['unit_name'],
                    'conversion_rate' => (float)$linkedUnit['conversion_rate'],
                    'product_code' => $pCode,
                    'product_name' => $product->name,
                    'variant_name' => $vName,
                    'unit_price' => $iPrice,
                    'base_unit_price' => $baseUnitPrice,
                    'quantity' => $iQty,
                    'base_quantity' => $baseQty,
                    'tax' => 0.0,
                    'discount' => 0.0,
                    'subtotal' => $iSubtotal,
                ]);

                $nameForReceipt = $vName ? "{$product->name} ({$vName})" : $product->name;
                $cleanQty = (floor($iQty) == $iQty) ? (int)$iQty : $iQty;
                $unitStr = $product->unit_name ?: 'ခု';
                $priceFmt = number_format($iPrice);
                $subFmt = number_format($iSubtotal);
                $numBadge = (count($itemsSummaryLines) + 1);
                $badge = match ($numBadge) {
                    1 => '1️⃣', 2 => '2️⃣', 3 => '3️⃣', 4 => '4️⃣', 5 => '5️⃣',
                    6 => '6️⃣', 7 => '7️⃣', 8 => '8️⃣', 9 => '9️⃣', 10 => '🔟',
                    default => "{$numBadge}."
                };
                $itemsSummaryLines[] = "{$badge} *{$nameForReceipt}*\n   ▫️ {$cleanQty} {$unitStr} × {$priceFmt}  =  *{$subFmt} MMK*";

                if ($autoApprove && $fifoService) {
                    $unitService->applyStock(
                        $product,
                        $warehouseId,
                        $vId,
                        $baseQty,
                        'out',
                        'sale',
                        'sale',
                        (string)$sale->id,
                        'Telegram Bot Order auto-approved'
                    );

                    $fallbackCost = (float)($product->cost_price ?: ($product->purchase_price ?: 0));
                    if ($vId) {
                        $varObj = ProductVariant::find($vId);
                        if ($varObj && $varObj->cost_price > 0) {
                            $fallbackCost = (float)$varObj->cost_price;
                        }
                    }

                    $depletion = $fifoService->depleteFifo(
                        warehouseId: (int)$warehouseId,
                        productId: (int)$product->id,
                        variantId: $vId,
                        neededBaseQty: (float)$baseQty,
                        saleId: (int)$sale->id,
                        saleItemId: (int)$saleItem->id,
                        saleDate: (string)$sale->date,
                        fallbackCost: $fallbackCost
                    );

                    $saleItem->update([
                        'base_unit_cost' => $depletion['avg_unit_cost'],
                        'total_cogs' => $depletion['total_cogs'],
                    ]);
                    $totalSaleCogs += (float)$depletion['total_cogs'];
                }
            }

            if ($autoApprove && $totalSaleCogs > 0) {
                $sale->update(['total_cogs' => round($totalSaleCogs, 2)]);
            }

            if ($paidAmount > 0) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'reference_no' => 'PAY-' . date('Ymd') . '-' . rand(1000, 9999),
                    'payment_date' => $sale->date,
                    'payment_type' => $paymentType,
                    'amount' => $paidAmount,
                    'received_amount' => $paidAmount,
                    'change_amount' => 0.0,
                    'notes' => 'Telegram Bot payment: ' . $paymentType . $slipMeta,
                    'created_by' => 'Telegram Bot',
                    'user_name' => 'Telegram Bot',
                    'user_role' => 'Bot Channel',
                ]);
            }

            DB::commit();

            // Clear Cart & Active Session
            $this->clearCart($chatId);
            Cache::forget("tg_session_{$chatId}");

            $totalFormatted = number_format($grandTotal);
            $itemsTotalFmt = number_format($subtotal);
            $shipFmt = $shippingFee > 0 ? "+" . number_format($shippingFee) . " MMK" : "အခမဲ့ (0 MMK)";
            $statusText = $autoApprove ? "✅ အတည်ပြုပြီးပါပြီ (Approved)" : "⏳ ဆိုင်မှ စစ်ဆေးအတည်ပြုရန် စောင့်ဆိုင်းနေပါသည် (Pending Review)";
            $firstItemName = $itemsSummaryLines[0] ?? 'ကုန်ပစ္စည်းများ';
            $allItemsFormatted = implode("\n\n", $itemsSummaryLines);

            // Custom Order Success Message Template
            $customSuccessTemplate = Setting::get('telegram_bot_order_success_msg', '');
            if (!empty(trim($customSuccessTemplate))) {
                $receiptMsg = $this->replacePlaceholders($customSuccessTemplate, [
                    'order_ref' => $sale->reference_no,
                    'reference_no' => $sale->reference_no,
                    'order_id' => $sale->reference_no,
                    'voucher_no' => $sale->reference_no,
                    'product_name' => $firstItemName,
                    'items' => $allItemsFormatted,
                    'quantity' => (string)$totalQuantity,
                    'qty' => (string)$totalQuantity,
                    'amount' => $totalFormatted,
                    'grand_total' => $totalFormatted,
                    'total' => $totalFormatted,
                    'price' => $totalFormatted,
                    'payment_type' => $paymentType,
                    'payment_name' => $paymentType,
                    'payment_status' => $paymentStatus,
                    'phone' => $customerPhone,
                    'customer_phone' => $customerPhone,
                    'customer_name' => $session['customer_name'] ?? $sale->customer_name ?? 'Customer',
                    'user_name' => $session['customer_name'] ?? $sale->customer_name ?? 'Customer',
                    'address' => $shippingAddress,
                    'shipping_address' => $shippingAddress,
                    'status' => $statusText,
                    'order_status' => $statusText,
                    'date' => $sale->date,
                ]);
            } else {
                $receiptMsg = "🎉 *အော်ဒါတင်ခြင်း အောင်မြင်ပါသည်ရှင်!*\n\n"
                            . "🔖 *အော်ဒါအမှတ်:* `{$sale->reference_no}`\n"
                            . "━━━━━━━━━━━━━━━━━━━━━\n"
                            . "📦 *မှာယူသော ပစ္စည်းများ (" . count($cartItems) . " မျိုး / {$totalQuantity} ခု):*\n\n"
                            . "{$allItemsFormatted}\n"
                            . "━━━━━━━━━━━━━━━━━━━━━\n"
                            . "💵 ကုန်ပစ္စည်းတန်ဖိုး:   *{$itemsTotalFmt} MMK*\n"
                            . "🚚 ပို့ဆောင်ခ:         *{$shipFmt}*\n"
                            . "💰 စုစုပေါင်း ကျသင့်ငွေ: *{$totalFormatted} MMK*\n"
                            . "━━━━━━━━━━━━━━━━━━━━━\n"
                            . "💳 ငွေပေးချေမှု:      *{$paymentType}* ({$paymentStatus})\n"
                            . "📞 ဖုန်းနံပါတ်:        `{$customerPhone}`\n"
                            . "📍 ပို့ဆောင်ရမည့်လိပ်စာ:  {$shippingAddress}\n"
                            . "📌 အခြေအနေ:         {$statusText}\n\n"
                            . "📊 *တိုးတက်မှု (Live Status Tracker):*\n"
                            . "┏━━━━━━━━━━━━━━━━━━━━┓\n"
                            . ($autoApprove
                                ? "  [✅ အတည်ပြုပြီး] ➔ [📦 ထုပ်ပိုးဆဲ] ➔ [🚚 စောင့်ဆိုင်း] ➔ [🎉 ပြီးဆုံး]\n"
                                : "  [⏳ စိစစ်ဆဲ] ➔ [📦 ထုပ်ပိုးရန်] ➔ [🚚 ပို့ဆောင်ရန်] ➔ [🎉 ပြီးဆုံး]\n")
                            . "┗━━━━━━━━━━━━━━━━━━━━┛\n\n";

                if ($autoApprove) {
                    $receiptMsg .= "📦 ပစ္စည်းများကို အမြန်ဆုံး ထုပ်ပိုး ပို့ဆောင်ပေးသွားပါမည်ရှင်။ ကျေးဇူးတင်ပါသည်! 🙏";
                } else {
                    $receiptMsg .= "ဆိုင်ဝန်ထမ်းမှ အော်ဒါနှင့် စလစ်အား စစ်ဆေးပြီးပါက ဤနေရာတွင် အကြောင်းကြားပေးပါမည်ရှင်။ ကျေးဇူးတင်ပါသည်! 🙏";
                }
            }

            $this->sendMessage($chatId, $receiptMsg, $this->getMainKeyboard($chatId));

            $this->notifyAdminNewOrder($sale);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('finalizeOrder error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $errTpl = Setting::get('telegram_bot_order_error_msg', '');
            $errMsg = !empty(trim($errTpl))
                ? $this->replacePlaceholders($errTpl, ['customer_name' => $userName, 'user_name' => $userName])
                : "⚠️ စနစ်ချို့ယွင်းမှုကြောင့် အော်ဒါမတင်နိုင်ခဲ့ပါရှင်။ ကျေးဇူးပြု၍ ပြန်လည်ကြိုးစားပေးပါ။";
            $this->sendMessage($chatId, $errMsg, $this->getMainKeyboard($chatId));
        }
    }

    /**
     * Send Customer Recent Orders
     */
    public function sendCustomerOrders(int|string $chatId): void
    {
        $sales = Sale::with('items')
            ->where('telegram_chat_id', (string)$chatId)
            ->orderBy('id', 'desc')
            ->take(5)
            ->get();

        if ($sales->isEmpty()) {
            $noOrdersTpl = Setting::get('telegram_bot_no_orders_msg', '');
            $msg = !empty(trim($noOrdersTpl))
                ? $noOrdersTpl
                : "📦 သင် မှာယူထားသော အော်ဒါများ မရှိသေးပါရှင်။\n\nကျွန်မတို့ဆိုင်၏ ကုန်ပစ္စည်းများကို \"🛍️ ကုန်ပစ္စည်းများ\" မှတဆင့် လွယ်ကူစွာ စတင်မှာယူနိုင်ပါသည်ရှင်။ 🙏";
            $this->sendMessage($chatId, $msg, $this->getMainKeyboard($chatId));
            return;
        }

        $msg = "📦 *သင်၏ လတ်တလော အော်ဒါမှတ်တမ်းများ*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n\n";

        foreach ($sales as $s) {
            $statusBadge = match($s->bot_approval_status) {
                'approved' => '✅ အတည်ပြုပြီး (Approved)',
                'rejected' => '❌ ပယ်ဖျက်ထား (Rejected)',
                default => '⏳ စစ်ဆေးရန်ကျန် (Pending Review)',
            };
            $grandTotalFmt = number_format($s->grand_total);
            $msg .= "🔖 အော်ဒါအမှတ်:      `{$s->reference_no}`\n"
                 . "📅 ရက်စွဲ:           {$s->date}\n";

            if ($s->items && $s->items->isNotEmpty()) {
                $itemsStr = $s->items->map(function ($it, $i) {
                    $name = $it->variant_name ? "{$it->product_name} ({$it->variant_name})" : $it->product_name;
                    $qty = (floor($it->quantity) == $it->quantity) ? (int)$it->quantity : $it->quantity;
                    return "   ▫️ {$name} (x{$qty})";
                })->implode("\n");
                $msg .= "{$itemsStr}\n";
            }

            $msg .= "💰 စုစုပေါင်း ကျသင့်ငွေ: *{$grandTotalFmt} MMK* ({$s->payment_type})\n"
                 . "📌 အခြေအနေ:        {$statusBadge}\n"
                 . "─────────────────────\n\n";
        }

        $navButtons = [
            [['text' => '🛍️ ကုန်ပစ္စည်းများ ကြည့်မည်', 'callback_data' => 'nav_all_products']],
            [['text' => '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main']],
        ];

        $this->sendMessage($chatId, $msg, ['inline_keyboard' => $navButtons]);
    }

    /**
     * Send Order Track Card via Deep Link or Query
     */
    public function sendOrderTrackCard(int|string $chatId, string $refNo): void
    {
        $sale = Sale::with('items')
            ->where('reference_no', $refNo)
            ->orWhere('telegram_order_id', $refNo)
            ->first();

        if (!$sale) {
            $this->sendMessage($chatId, "⚠️ အော်ဒါအမှတ် `{$refNo}` အား ရှာမတွေ့ပါရှင်။", $this->getMainKeyboard($chatId));
            return;
        }

        $statusBadge = match($sale->bot_approval_status) {
            'approved' => '✅ အတည်ပြုပြီး (Approved)',
            'rejected' => '❌ ပယ်ဖျက်ထား (Rejected)',
            default => '⏳ စစ်ဆေးရန်ကျန် (Pending Review)',
        };

        $itemsStr = "";
        if ($sale->items && $sale->items->isNotEmpty()) {
            $itemsList = $sale->items->map(function ($it, $i) {
                $num = $i + 1;
                $name = $it->variant_name ? "{$it->product_name} ({$it->variant_name})" : $it->product_name;
                $qty = (floor($it->quantity) == $it->quantity) ? (int)$it->quantity : $it->quantity;
                $sub = number_format($it->subtotal);
                return "   {$num}. *{$name}*\n      ▫️ {$qty} ခု — *{$sub} MMK*";
            })->implode("\n");
            $itemsStr = "📦 *မှာယူထားသော ပစ္စည်းများ:*\n{$itemsList}\n";
        }

        $grandTotalFmt = number_format($sale->grand_total);
        $shipLine = $sale->shipping > 0
            ? "🚚 ပို့ဆောင်ခ:        *+" . number_format($sale->shipping) . " MMK*\n"
            : "🚚 ပို့ဆောင်ခ:        *အခမဲ့ (0 MMK)*\n";

        $msg = "📦 *အော်ဒါ အချက်အလက်နှင့် အခြေအနေ စစ်ဆေးခြင်း*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "🔖 အော်ဒါအမှတ်:      `{$sale->reference_no}`\n"
             . "📅 ရက်စွဲ:           {$sale->date}\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . ($itemsStr ? "{$itemsStr}━━━━━━━━━━━━━━━━━━━━━\n" : "")
             . $shipLine
             . "💰 စုစုပေါင်း ကျသင့်ငွေ: *{$grandTotalFmt} MMK*\n"
             . "💳 ငွေပေးချေမှု:     *{$sale->payment_type}*\n"
             . "📌 အခြေအနေ:        {$statusBadge}\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n\n"
             . "မေးမြန်းလိုသည်များ ရှိပါက '📞 ဆိုင်နှင့် ဆက်သွယ်ရန်' မှတဆင့် တိုက်ရိုက် မေးမြန်းနိုင်ပါသည်ရှင်။ 🙏";

        $this->sendMessage($chatId, $msg, $this->getMainKeyboard($chatId));
    }

    /**
     * Send Payment Info (Single Source of Truth from PaymentMethod database)
     */
    public function sendPaymentInfo(int|string $chatId): void
    {
        $activeMethods = PaymentMethod::where('is_active', true)
            ->where('is_bot_available', true)
            ->orderBy('is_default', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        $msg = "ℹ️ *ငွေပေးချေရန် နည်းလမ်းများနှင့် အချက်အလက်များ*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n\n";

        if ($activeMethods->isNotEmpty()) {
            foreach ($activeMethods as $pm) {
                $code = strtoupper($pm->code ?? '');
                $name = strtolower($pm->name);
                $isCash = ($code === 'CASH' || str_contains($name, 'cash') || str_contains($name, 'cod'));

                if ($isCash) {
                    $msg .= "💵 *{$pm->name}*\n"
                          . "   ▫️ အမျိုးအစား:     ပစ္စည်းရောက်မှ ငွေချေစနစ် (Cash on Delivery)\n";
                    if (!empty($pm->description)) {
                        $msg .= "   ▫️ အသေးစိတ်:     {$pm->description}\n";
                    }
                    $msg .= "\n";
                    continue;
                }

                $icon = match (strtolower($pm->code ?? '')) {
                    'kpay' => '📱',
                    'wavepay' => '📱',
                    'ayapay' => '📱',
                    'cbpay' => '📱',
                    default => '💳',
                };

                $msg .= "{$icon} *{$pm->name}*\n";
                if (!empty($pm->account_name)) {
                    $msg .= "   ▫️ အကောင့်အမည်:   `{$pm->account_name}`\n";
                }
                if (!empty($pm->account_number)) {
                    $msg .= "   ▫️ အကောင့်နံပါတ်:  `{$pm->account_number}` _(နှိပ်၍ ကူးယူပါ)_\n";
                }
                if (!empty($pm->description)) {
                    $msg .= "   ▫️ မှတ်ချက်:      {$pm->description}\n";
                }
                $msg .= "\n";
            }
        } else {
            // Fallback to legacy settings if payment methods table has none enabled
            $kpay = Setting::get('telegram_bot_kpay_info');
            $wave = Setting::get('telegram_bot_wave_info');
            $aya = Setting::get('telegram_bot_ayapay_info');
            $cb = Setting::get('telegram_bot_cbpay_info');
            $other = Setting::get('telegram_bot_other_bank_info');
            $generic = Setting::get('telegram_bot_payment_info');

            if ($kpay) $msg .= "📱 *KBZPay:* {$kpay}\n\n";
            if ($wave) $msg .= "📱 *WavePay:* {$wave}\n\n";
            if ($aya) $msg .= "📱 *AYA Pay:* {$aya}\n\n";
            if ($cb) $msg .= "📱 *CB Pay:* {$cb}\n\n";
            if ($other) $msg .= "🏦 *ဘဏ်အကောင့်:* {$other}\n\n";

            if (!$kpay && !$wave && !$aya && !$cb && !$other && $generic) {
                $msg .= "{$generic}\n\n";
            }
        }

        $msg .= "━━━━━━━━━━━━━━━━━━━━━\n"
             . "ပစ္စည်းများ မှာယူလိုပါက '🛍️ ကုန်ပစ္စည်းများ ကြည့်မည်' ကို နှိပ်၍ မှာယူနိုင်ပါသည်ရှင်။ 🙏";

        $this->sendMessage($chatId, $msg, $this->getMainKeyboard($chatId));
    }

    /**
     * Send Contact Info
     */
    public function sendContactInfo(int|string $chatId): void
    {
        $shopName = Setting::get('company_name', 'အလင်းသစ် (Ah Linn Thit)');
        $phone = Setting::get('company_phone', '09123456789');
        $address = Setting::get('company_address', 'ရန်ကုန်');

        $contactTpl = Setting::get('telegram_bot_contact_info_msg', '');
        if (!empty(trim($contactTpl))) {
            $msg = $this->replacePlaceholders($contactTpl, [
                'shop_name' => $shopName,
                'shop_phone' => $phone,
                'shop_address' => $address,
            ]);
        } else {
            $msg = "📞 *{$shopName} နှင့် ဆက်သွယ်ရန် အချက်အလက်များ*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "🏬 ဆိုင်အမည်:   *{$shopName}*\n"
                 . "📍 ဆိုင်လိပ်စာ:  {$address}\n"
                 . "☎️ ဖုန်းနံပါတ်:  `{$phone}`\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n\n"
                 . "မေးမြန်းလိုသည်များ ရှိပါက ဆိုင်သို့ တိုက်ရိုက် ဆက်သွယ်စုံစမ်းနိုင်ပါသည်ရှင်။ 🙏";
        }
        $this->sendMessage($chatId, $msg, $this->getMainKeyboard($chatId));
    }

    /**
     * Format Dynamic Cart Button Text (Always Clean without badges)
     */
    public function formatCartButtonText(int|string|null $chatId = null, string $baseBtnCart = ''): string
    {
        return !empty(trim($baseBtnCart)) ? trim($baseBtnCart) : '🛒 ဝယ်မည့်ပစ္စည်းများ';
    }

    /**
     * Synchronize customer's persistent Reply Keyboard.
     * In Option 1 (Clean Persistent Menu), the bottom Reply Keyboard remains
     * permanently clean and static without companion message spam.
     */
    public function syncPersistentKeyboard(int|string|null $chatId, ?string $text = null): void
    {
        // Safe no-op to eliminate unwanted chat messages
        return;
    }

    /**
     * Persistent Main Keyboard (Clean & Static - Option 1 Standard)
     */
    public function getMainKeyboard(int|string|null $chatId = null): array
    {
        $btnCatalog = trim((string)Setting::get('telegram_bot_btn_catalog', '🛍️ ကုန်ပစ္စည်းများ'));
        $btnCart = trim((string)Setting::get('telegram_bot_btn_cart', '🛒 ဝယ်မည့်ပစ္စည်းများ'));
        $btnOrders = trim((string)Setting::get('telegram_bot_btn_orders', '📦 တင်ထားသော Order များ'));
        $btnCheckout = trim((string)Setting::get('telegram_bot_btn_checkout', '📦 မှာမည့်ပစ္စည်းများ Order တင်မည်'));
        $btnPayment = trim((string)Setting::get('telegram_bot_btn_payment', 'ℹ️ ငွေလွှဲရန် အချက်အလက်'));
        $btnContact = trim((string)Setting::get('telegram_bot_btn_contact', '📞 ဆိုင်နှင့် ဆက်သွယ်ရန်'));
        $btnReorder = trim((string)Setting::get('telegram_bot_btn_reorder', '🔄 ယခင်အော်ဒါ ပြန်မှာမည်'));

        $showCatalog = Setting::get('telegram_bot_show_btn_catalog', '1') === '1';
        $showCart = Setting::get('telegram_bot_show_btn_cart', '1') === '1';
        $showOrders = Setting::get('telegram_bot_show_btn_orders', '1') === '1';
        $showCheckout = Setting::get('telegram_bot_show_btn_checkout', '1') === '1';
        $showPayment = Setting::get('telegram_bot_show_btn_payment', '1') === '1';
        $showContact = Setting::get('telegram_bot_show_btn_contact', '1') === '1';
        $enableReorder = Setting::get('telegram_bot_enable_reorder', '0') === '1';
        $showReorder = $enableReorder && Setting::get('telegram_bot_show_btn_reorder', '1') === '1';

        $btnCartText = $this->formatCartButtonText($chatId, $btnCart);

        $visibleButtons = [];
        if ($showCatalog) {
            $visibleButtons[] = ['text' => !empty($btnCatalog) ? $btnCatalog : '🛍️ ကုန်ပစ္စည်းများ'];
        }
        if ($showCart) {
            $visibleButtons[] = ['text' => $btnCartText];
        }
        if ($showOrders) {
            $visibleButtons[] = ['text' => !empty($btnOrders) ? $btnOrders : '📦 တင်ထားသော Order များ'];
        }
        if ($showCheckout) {
            $visibleButtons[] = ['text' => !empty($btnCheckout) ? $btnCheckout : '📦 မှာမည့်ပစ္စည်းများ Order တင်မည်'];
        }
        if ($showPayment) {
            $visibleButtons[] = ['text' => !empty($btnPayment) ? $btnPayment : 'ℹ️ ငွေလွှဲရန် အချက်အလက်'];
        }
        if ($showContact) {
            $visibleButtons[] = ['text' => !empty($btnContact) ? $btnContact : '📞 ဆိုင်နှင့် ဆက်သွယ်ရန်'];
        }
        if ($showReorder) {
            $visibleButtons[] = ['text' => !empty($btnReorder) ? $btnReorder : '🔄 ယခင်အော်ဒါ ပြန်မှာမည်'];
        }

        if (empty($visibleButtons)) {
            return [
                'remove_keyboard' => true,
            ];
        }

        return [
            'keyboard' => array_chunk($visibleButtons, 2),
            'resize_keyboard' => true,
        ];
    }

    /**
     * Notify Admin(s) about a new order with interactive Approve / Reject buttons
     */
    public function notifyAdminNewOrder(Sale $sale): void
    {
        $adminChatIds = Setting::get('telegram_admin_chat_ids', '');
        if (empty($adminChatIds)) {
            return;
        }

        $ids = array_map('trim', explode(',', $adminChatIds));
        $totalFormatted = number_format($sale->grand_total);
        $statusIcon = $sale->bot_approval_status === 'approved' ? '✅ အတည်ပြုပြီး' : '⏳ စစ်ဆေးရန်ကျန် (Pending Review)';

        $sale->loadMissing(['items', 'customer', 'outlet']);
        $itemsText = "";
        if ($sale->items && $sale->items->isNotEmpty()) {
            $totalCount = $sale->items->count();
            $displayItems = $sale->items->take(5);
            $itemsList = $displayItems->map(function ($it) {
                $name = $it->variant_name ? "{$it->product_name} ({$it->variant_name})" : $it->product_name;
                return "   • {$name} x {$it->quantity} (" . number_format($it->subtotal) . " MMK)";
            })->implode("\n");

            if ($totalCount > 5) {
                $rem = $totalCount - 5;
                $itemsList .= "\n   • ... နှင့် အခြား {$rem} မျိုး";
            }
            $itemsText = "📦 *မှာယူသော ပစ္စည်းများ:*\n{$itemsList}\n";
        }

        $adminOrderTpl = Setting::get('telegram_admin_new_order_msg', '');
        if (!empty(trim($adminOrderTpl))) {
            $addressText = $sale->shipping_address ?: 'မပါရှိ';
            $msg = $this->replacePlaceholders($adminOrderTpl, [
                'order_ref' => $sale->reference_no,
                'reference_no' => $sale->reference_no,
                'order_id' => $sale->reference_no,
                'customer_name' => $sale->customer_name ?: 'ဖောက်သည်',
                'user_name' => $sale->customer_name ?: 'ဖောက်သည်',
                'phone' => $sale->customer_phone ?: 'မပါရှိ',
                'customer_phone' => $sale->customer_phone ?: 'မပါရှိ',
                'warehouse_name' => $sale->warehouse_name ?: '',
                'items' => $itemsText ?: '',
                'amount' => $totalFormatted,
                'grand_total' => $totalFormatted,
                'total' => $totalFormatted,
                'payment_status' => $sale->payment_status,
                'payment_type' => $sale->payment_type,
                'payment_method' => $sale->payment_type,
                'status' => $statusIcon,
                'address' => $addressText,
                'shipping_address' => $addressText,
            ]);
        } else {
            $msg = "🔔 *အော်ဒါအသစ် ရောက်ရှိပါသည်!*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "🔖 အော်ဒါအမှတ်:      `{$sale->reference_no}`\n"
                 . "👤 ဖောက်သည်:         {$sale->customer_name}\n"
                 . "📞 ဖုန်းနံပါတ်:       `" . ($sale->customer_phone ?: 'မပါရှိ') . "`\n"
                 . "🏢 ဂိုဒေါင်:         {$sale->warehouse_name}\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . ($itemsText ? "{$itemsText}━━━━━━━━━━━━━━━━━━━━━\n" : "")
                 . "💰 စုစုပေါင်း ကျသင့်ငွေ: *{$totalFormatted} MMK*\n"
                 . "💳 ငွေပေးချေမှု:     {$sale->payment_status} ({$sale->payment_type})\n"
                 . "📌 အခြေအနေ:        {$statusIcon}\n";

            if ($sale->shipping_address) {
                $msg .= "📍 ပို့ဆောင်ရမည့်လိပ်စာ: {$sale->shipping_address}\n";
            }

            $msg .= "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "\n_အောက်ပါ ခလုတ်များမှတဆင့် အတည်ပြု/ပယ်ဖျက်နိုင်ပါသည်-_\n";
        }

        $adminButtons = [];
        if ($sale->items && $sale->items->count() > 5) {
            $totalCount = $sale->items->count();
            $adminButtons[] = [
                ['text' => "📋 ပစ္စည်းအားလုံးကြည့်ရန် ({$totalCount} မျိုး)", 'callback_data' => "adm_items_{$sale->id}"],
            ];
        }

        if ($sale->bot_approval_status === 'approved') {
            $adminButtons[] = [
                ['text' => '✅ အတည်ပြုပြီးပါပြီ (Approved)', 'callback_data' => 'noop'],
            ];
        } else {
            $adminButtons[] = [
                ['text' => '✅ အတည်ပြုမည် (Approve)', 'callback_data' => "adm_app_{$sale->id}"],
            ];
            $adminButtons[] = [
                ['text' => '❌ ပယ်ဖျက်မည် (Reject)', 'callback_data' => "adm_rej_{$sale->id}"],
            ];
        }

        $adminKeyboard = ['inline_keyboard' => $adminButtons];

        foreach ($ids as $chatId) {
            if (empty($chatId)) continue;

            $sent = false;

            // 1. If payment slip was uploaded, send ONE unified photo card with complete order ticket & buttons
            if (!empty($sale->payment_slip_url)) {
                $photoCaption = mb_strlen($msg) > 1024 ? (mb_substr($msg, 0, 1020) . '...') : $msg;
                $photoRes = $this->sendPhoto($chatId, $sale->payment_slip_url, $photoCaption, $adminKeyboard);
                if (!empty($photoRes['ok'])) {
                    $sent = true;
                } else {
                    Log::warning("Telegram sendPhoto slip failed for new order {$sale->reference_no} to admin {$chatId}: " . json_encode($photoRes));
                }
            }

            // 2. If no payment slip or if photo upload failed: send exactly ONE text message with order details & buttons
            if (!$sent) {
                $slipLink = !empty($sale->payment_slip_url) ? "\n\n🔗 *ငွေလွှဲစလစ်လင့်ခ်:* {$sale->payment_slip_url}" : "";
                $this->sendMessage($chatId, $msg . $slipLink, $adminKeyboard);
            }
        }
    }

    /**
     * Notify Admin(s) specifically when a payment slip is received for an existing order with interactive buttons
     */
    public function notifyAdminPaymentSlipReceived(Sale $sale, string $slipUrl): void
    {
        $adminChatIds = Setting::get('telegram_admin_chat_ids', '');
        if (empty($adminChatIds)) {
            return;
        }

        $ids = array_map('trim', explode(',', $adminChatIds));
        $totalFormatted = number_format($sale->grand_total);
        $statusIcon = $sale->bot_approval_status === 'approved' ? '✅ အတည်ပြုပြီး' : '⏳ စစ်ဆေးရန်ကျန် (Pending Review)';

        $caption = "📸 *ငွေလွှဲပြေစာ (Payment Slip) အသစ် ရောက်ရှိပါသည်!*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "🔖 အော်ဒါအမှတ်:      `{$sale->reference_no}`\n"
                 . "👤 ဖောက်သည်:         {$sale->customer_name}\n"
                 . "📞 ဖုန်းနံပါတ်:       `" . ($sale->customer_phone ?: 'မပါရှိ') . "`\n"
                 . "💰 ကျသင့်ငွေ:        *{$totalFormatted} MMK*\n"
                 . "💳 ငွေပေးချေမှု:     {$sale->payment_status} ({$sale->payment_type})\n"
                 . "📌 အခြေအနေ:        {$statusIcon}\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n\n"
                 . "အောက်ပါ ခလုတ်များမှတဆင့် တိုက်ရိုက် စစ်ဆေးအတည်ပြုနိုင်ပါသည်-";

        $sale->loadMissing(['items', 'customer']);
        $adminButtons = [];
        if ($sale->items && $sale->items->count() > 5) {
            $totalCount = $sale->items->count();
            $adminButtons[] = [
                ['text' => "📋 ပစ္စည်းအားလုံးကြည့်ရန် ({$totalCount} မျိုး)", 'callback_data' => "adm_items_{$sale->id}"],
            ];
        }

        if ($sale->bot_approval_status === 'approved') {
            $adminButtons[] = [
                ['text' => '✅ အတည်ပြုပြီးပါပြီ (Approved)', 'callback_data' => 'noop'],
            ];
        } else {
            $adminButtons[] = [
                ['text' => '✅ အတည်ပြုမည် (Approve)', 'callback_data' => "adm_app_{$sale->id}"],
            ];
            $adminButtons[] = [
                ['text' => '❌ ပယ်ဖျက်မည် (Reject)', 'callback_data' => "adm_rej_{$sale->id}"],
            ];
        }

        $adminKeyboard = ['inline_keyboard' => $adminButtons];

        foreach ($ids as $chatId) {
            if (empty($chatId)) continue;

            $photoRes = $this->sendPhoto($chatId, $slipUrl, $caption, $adminKeyboard);
            if (empty($photoRes['ok'])) {
                Log::warning("Telegram sendPhoto slip failed for admin {$chatId}: " . json_encode($photoRes));
                $fallbackMsg = $caption . "\n\n🔗 *စလစ်လင့်ခ်:* {$slipUrl}";
                $this->sendMessage($chatId, $fallbackMsg, $adminKeyboard);
            }
        }
    }

    /**
     * Handle Admin View All Items Callback directly from Telegram Chat
     */
    public function handleAdminViewAllItemsCallback(string $callbackQueryId, int|string $adminChatId, int $saleId, array $callbackQuery): void
    {
        $adminChatIds = Setting::get('telegram_admin_chat_ids', '');
        $adminIdList = array_filter(array_map('trim', explode(',', $adminChatIds)));

        if (!in_array((string)$adminChatId, $adminIdList, true)) {
            $this->answerCallbackQuery($callbackQueryId, '⛔ သင်သည် ဆိုင်တာဝန်ခံ (Admin) မဟုတ်ပါသဖြင့် ဤလုပ်ဆောင်ချက်ကို ပြုလုပ်ခွင့်မရှိပါ', true);
            return;
        }

        $sale = Sale::with(['items', 'customer'])->where('order_source', 'telegram_bot')->find($saleId);
        if (!$sale) {
            $this->answerCallbackQuery($callbackQueryId, '⚠️ အော်ဒါ ရှာမတွေ့ပါ', true);
            return;
        }

        $this->answerCallbackQuery($callbackQueryId, '📋 ပစ္စည်းစာရင်း အပြည့်အစုံကို ထုတ်ပြပေးထားပါသည်');

        $totalFormatted = number_format($sale->grand_total);
        $totalItemsCount = $sale->items ? $sale->items->count() : 0;
        $totalQty = $sale->items ? $sale->items->sum('quantity') : 0;

        $itemLines = [];
        $i = 1;
        if ($sale->items && $sale->items->isNotEmpty()) {
            foreach ($sale->items as $it) {
                $name = $it->variant_name ? "{$it->product_name} ({$it->variant_name})" : $it->product_name;
                $qty = $it->quantity;
                $unitPrice = number_format($it->unit_price ?: ($it->subtotal / max(1, $it->quantity)));
                $subtotal = number_format($it->subtotal);
                $itemLines[] = "{$i}. *{$name}*\n    └ အရေအတွက်: `{$qty}` | တစ်ခုဈေး: `{$unitPrice} MMK` | စုစုပေါင်း: `{$subtotal} MMK`";
                $i++;
            }
        }

        $itemsBody = !empty($itemLines) ? implode("\n\n", $itemLines) : "ပစ္စည်းများ မရှိပါ";

        $replyText = "📋 *အော်ဒါအမှတ် `{$sale->reference_no}` ၏ ပစ္စည်းစာရင်း အပြည့်အစုံ*\n"
                   . "👤 *ဖောက်သည်:* {$sale->customer_name} (" . ($sale->customer_phone ?: 'ဖုန်းမပါရှိ') . ")\n"
                   . "━━━━━━━━━━━━━━━━━━━━━\n"
                   . "{$itemsBody}\n"
                   . "━━━━━━━━━━━━━━━━━━━━━\n"
                   . "📦 *စုစုပေါင်း ပစ္စည်းအမျိုးအစား:* `{$totalItemsCount} မျိုး` (`{$totalQty} ခု`)\n"
                   . "💰 *စုစုပေါင်း ကျသင့်ငွေ:* `{$totalFormatted} MMK`\n\n"
                   . "_ဤအော်ဒါအား အပေါ်ရှိ မူလကတ်မှတစ်ဆင့် အတည်ပြု/ပယ်ဖျက်နိုင်ပါသည်_ 👆";

        $messageId = $callbackQuery['message']['message_id'] ?? null;
        $this->sendMessage($adminChatId, $replyText, null, $messageId);
    }

    /**
     * Handle Admin Approve Callback directly from Telegram Chat
     */
    public function handleAdminApproveCallback(string $callbackQueryId, int|string $adminChatId, int $saleId, array $callbackQuery): void
    {
        $adminChatIds = Setting::get('telegram_admin_chat_ids', '');
        $adminIdList = array_filter(array_map('trim', explode(',', $adminChatIds)));

        if (!in_array((string)$adminChatId, $adminIdList, true)) {
            $this->answerCallbackQuery($callbackQueryId, '⛔ သင်သည် ဆိုင်တာဝန်ခံ (Admin) မဟုတ်ပါသဖြင့် ဤလုပ်ဆောင်ချက်ကို ပြုလုပ်ခွင့်မရှိပါ', true);
            return;
        }

        $sale = Sale::with(['items', 'customer', 'outlet'])->where('order_source', 'telegram_bot')->find($saleId);
        if (!$sale) {
            $this->answerCallbackQuery($callbackQueryId, '⚠️ အော်ဒါ ရှာမတွေ့ပါ', true);
            return;
        }

        if ($sale->bot_approval_status === 'approved') {
            $this->answerCallbackQuery($callbackQueryId, 'ℹ️ ဤအော်ဒါသည် အတည်ပြုပြီးဖြစ်ပါသည်', true);
            return;
        }

        if ($sale->bot_approval_status === 'rejected') {
            $this->answerCallbackQuery($callbackQueryId, '⚠️ ပယ်ဖျက်ထားသော အော်ဒါအား အတည်မပြုနိုင်ပါ', true);
            return;
        }

        // 🛡️ GAAP Inventory Check: Verify sufficient stock before depletion
        $warehouseId = $sale->warehouse_id ?: (int)Setting::get('telegram_bot_warehouse_id', 1);
        $insufficientItems = [];

        foreach ($sale->items as $item) {
            $stockQuery = StockBalance::where('warehouse_id', $warehouseId)
                ->where('product_id', $item->product_id);
            if ($item->product_variant_id) {
                $stockQuery->where('product_variant_id', $item->product_variant_id);
            }
            $available = (float)$stockQuery->sum('quantity');
            if ($available < (float)$item->quantity) {
                $name = $item->variant_name ? "{$item->product_name} ({$item->variant_name})" : $item->product_name;
                $insufficientItems[] = "• {$name} (လက်ကျန်: {$available}, လိုအပ်ချက်: {$item->quantity})";
            }
        }

        if (!empty($insufficientItems)) {
            $this->answerCallbackQuery($callbackQueryId, '⚠️ လက်ကျန် မလုံလောက်ပါသဖြင့် အတည်မပြုနိုင်ပါ', true);
            $this->sendMessage($adminChatId, "⚠️ *ဂိုဒေါင်တွင် ပစ္စည်းလက်ကျန် မလုံလောက်ပါ*\n\nအော်ဒါအမှတ်: `{$sale->reference_no}`\n" . implode("\n", $insufficientItems));
            return;
        }

        DB::beginTransaction();
        try {
            $unitService = app(ProductUnitConversionService::class);
            $fifoService = app(FifoInventoryService::class);
            $totalSaleCogs = 0.0;

            foreach ($sale->items as $item) {
                $product = Product::find($item->product_id);
                if (!$product) continue;

                $baseQty = (float)($item->base_quantity ?: $unitService->baseQuantity($item->quantity, $item->conversion_rate ?: 1));

                $unitService->applyStock(
                    $product,
                    $warehouseId,
                    $item->product_variant_id,
                    $baseQty,
                    'out',
                    'sale',
                    'sale',
                    (string)$sale->id,
                    'Telegram Bot Order approved by Admin via Telegram'
                );

                $fallbackCost = (float)($product->cost_price ?: ($product->purchase_price ?: 0));
                if ($item->product_variant_id) {
                    $varObj = ProductVariant::find($item->product_variant_id);
                    if ($varObj && $varObj->cost_price > 0) {
                        $fallbackCost = (float)$varObj->cost_price;
                    }
                }

                $depletion = $fifoService->depleteFifo(
                    warehouseId: (int)$warehouseId,
                    productId: (int)$product->id,
                    variantId: $item->product_variant_id,
                    neededBaseQty: (float)$baseQty,
                    saleId: (int)$sale->id,
                    saleItemId: (int)$item->id,
                    saleDate: (string)$sale->date,
                    fallbackCost: $fallbackCost
                );

                $item->update([
                    'base_unit_cost' => $depletion['avg_unit_cost'],
                    'total_cogs' => $depletion['total_cogs'],
                ]);
                $totalSaleCogs += (float)$depletion['total_cogs'];
            }

            $adminUser = !empty($callbackQuery['from']['username']) ? '@' . $callbackQuery['from']['username'] : trim(($callbackQuery['from']['first_name'] ?? '') . ' ' . ($callbackQuery['from']['last_name'] ?? ''));
            $approvedByText = "Telegram Admin ({$adminUser})";

            $sale->update([
                'status' => 'Completed',
                'bot_approval_status' => 'approved',
                'bot_approved_by' => $approvedByText,
                'bot_approved_at' => now(),
                'total_cogs' => round($totalSaleCogs, 2),
                'notes' => trim($sale->notes . " | Approved via Telegram Admin ({$adminUser}) on " . now()->format('Y-m-d H:i')),
            ]);

            DB::commit();

            $this->answerCallbackQuery($callbackQueryId, "✅ အော်ဒါ #{$sale->reference_no} အား အတည်ပြုပြီးပါပြီ");

            // Update Telegram message buttons in-place
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            if ($messageId) {
                $approvedButtons = [];
                if ($sale->items && $sale->items->count() > 5) {
                    $totalCount = $sale->items->count();
                    $approvedButtons[] = [
                        ['text' => "📋 ပစ္စည်းအားလုံးကြည့်ရန် ({$totalCount} မျိုး)", 'callback_data' => "adm_items_{$sale->id}"],
                    ];
                }
                $approvedButtons[] = [
                    ['text' => "✅ အတည်ပြုပြီးပါပြီ ({$adminUser})", 'callback_data' => 'noop'],
                ];
                $this->editMessageReplyMarkup($adminChatId, $messageId, ['inline_keyboard' => $approvedButtons]);
            }

            // Notify customer with 80mm POS voucher image
            try {
                $sale->refresh();
                $sale->load(['items', 'customer', 'outlet']);
                $this->notifyCustomerOrderApproved($sale);
            } catch (\Throwable $ne) {
                Log::warning("Telegram approve notify error: " . $ne->getMessage());
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("handleAdminApproveCallback error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->answerCallbackQuery($callbackQueryId, "❌ အတည်ပြုရာတွင် အမှားဖြစ်ပွားပါသည်", true);
        }
    }

    /**
     * Handle Admin Reject Callback directly from Telegram Chat
     */
    public function handleAdminRejectCallback(string $callbackQueryId, int|string $adminChatId, int $saleId, array $callbackQuery): void
    {
        $adminChatIds = Setting::get('telegram_admin_chat_ids', '');
        $adminIdList = array_filter(array_map('trim', explode(',', $adminChatIds)));

        if (!in_array((string)$adminChatId, $adminIdList, true)) {
            $this->answerCallbackQuery($callbackQueryId, '⛔ သင်သည် ဆိုင်တာဝန်ခံ (Admin) မဟုတ်ပါသဖြင့် ဤလုပ်ဆောင်ချက်ကို ပြုလုပ်ခွင့်မရှိပါ', true);
            return;
        }

        $sale = Sale::with('items')->where('order_source', 'telegram_bot')->find($saleId);
        if (!$sale) {
            $this->answerCallbackQuery($callbackQueryId, '⚠️ အော်ဒါ ရှာမတွေ့ပါ', true);
            return;
        }

        if ($sale->bot_approval_status === 'approved') {
            $this->answerCallbackQuery($callbackQueryId, '⚠️ အတည်ပြုပြီးသော အော်ဒါဖြစ်သဖြင့် ပယ်ဖျက်၍ မရပါ', true);
            return;
        }

        if ($sale->bot_approval_status === 'rejected') {
            $this->answerCallbackQuery($callbackQueryId, 'ℹ️ ဤအော်ဒါသည် ပယ်ဖျက်ပြီးဖြစ်ပါသည်', true);
            return;
        }

        DB::beginTransaction();
        try {
            $adminUser = !empty($callbackQuery['from']['username']) ? '@' . $callbackQuery['from']['username'] : trim(($callbackQuery['from']['first_name'] ?? '') . ' ' . ($callbackQuery['from']['last_name'] ?? ''));
            $rejectedByText = "Telegram Admin ({$adminUser})";
            $reason = "ဆိုင်တာဝန်ခံ (Admin) မှ Telegram မှတဆင့် ပယ်ဖျက်ခဲ့ပါသည်";

            // Void unverified payments
            SalePayment::where('sale_id', $sale->id)->delete();

            $sale->update([
                'status' => 'Cancelled',
                'bot_approval_status' => 'rejected',
                'bot_approved_by' => null,
                'bot_rejected_by' => $rejectedByText,
                'bot_rejected_at' => now(),
                'bot_rejection_reason' => $reason,
                'paid_amount' => 0.0,
                'due_amount' => 0.0,
                'payment_status' => 'Unpaid',
                'notes' => trim($sale->notes . " | Cancelled via Telegram Admin ({$adminUser}) on " . now()->format('Y-m-d H:i')),
            ]);

            DB::commit();

            $this->answerCallbackQuery($callbackQueryId, "❌ အော်ဒါ #{$sale->reference_no} အား ပယ်ဖျက်လိုက်ပါပြီ");

            // Update message buttons in-place
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            if ($messageId) {
                $rejectedButtons = [];
                if ($sale->items && $sale->items->count() > 5) {
                    $totalCount = $sale->items->count();
                    $rejectedButtons[] = [
                        ['text' => "📋 ပစ္စည်းအားလုံးကြည့်ရန် ({$totalCount} မျိုး)", 'callback_data' => "adm_items_{$sale->id}"],
                    ];
                }
                $rejectedButtons[] = [
                    ['text' => "❌ ပယ်ဖျက်ပြီးပါပြီ ({$adminUser})", 'callback_data' => 'noop'],
                ];
                $this->editMessageReplyMarkup($adminChatId, $messageId, ['inline_keyboard' => $rejectedButtons]);
            }

            // Notify customer
            try {
                $this->notifyCustomerOrderRejected($sale, $reason);
            } catch (\Throwable $ne) {
                Log::warning("Telegram reject notify error: " . $ne->getMessage());
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("handleAdminRejectCallback error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->answerCallbackQuery($callbackQueryId, "❌ ပယ်ဖျက်ရာတွင် အမှားဖြစ်ပွားပါသည်", true);
        }
    }

    /**
     * Notify Customer when order is approved
     */
    public function notifyCustomerOrderApproved(Sale $sale): void
    {
        if (empty($sale->telegram_chat_id)) {
            return;
        }

        $sale->loadMissing(['items', 'customer', 'outlet']);

        $totalFormatted = number_format($sale->grand_total);
        $paidFormatted = number_format($sale->paid_amount);
        $dueFormatted = number_format($sale->due_amount);

        $customTemplate = Setting::get('telegram_bot_order_approved_msg', '');
        if (!empty(trim($customTemplate))) {
            $msg = $this->replacePlaceholders($customTemplate, [
                'order_ref' => $sale->reference_no,
                'reference_no' => $sale->reference_no,
                'order_id' => $sale->reference_no,
                'amount' => $totalFormatted,
                'grand_total' => $totalFormatted,
                'total' => $totalFormatted,
                'paid_amount' => $paidFormatted,
                'paid' => $paidFormatted,
                'due_amount' => $dueFormatted,
                'due' => $dueFormatted,
                'date' => $sale->date,
                'customer_name' => $sale->customer_name ?: 'Customer',
                'user_name' => $sale->customer_name ?: 'Customer',
                'status' => '✅ အတည်ပြုပြီးပါပြီ (Approved)',
            ]);
        } else {
            $msg = "✅ *သင့်အော်ဒါ အတည်ပြုပြီးပါပြီရှင်!*\n\n"
                 . "🔖 *အော်ဒါအမှတ်:* `{$sale->reference_no}`\n"
                 . "📅 *ရက်စွဲ:* {$sale->date}\n"
                 . "💰 *ကျသင့်ငွေ:* {$totalFormatted} MMK\n"
                 . "💵 *ပေးချေပြီးငွေ:* {$paidFormatted} MMK\n";

            if ($sale->due_amount > 0) {
                $msg .= "⚠️ *ကျန်ငွေ (ပစ္စည်းရောက်မှပေးချေရန်):* {$dueFormatted} MMK\n";
            }

            $msg .= "\n📊 *တိုးတက်မှု (Live Status Tracker):*\n"
                  . "┏━━━━━━━━━━━━━━━━━━━━┓\n"
                  . "  [✅ အတည်ပြုပြီး] ➔ [📦 ထုပ်ပိုးဆဲ] ➔ [🚚 စောင့်ဆိုင်း] ➔ [🎉 ပြီးဆုံး]\n"
                  . "┗━━━━━━━━━━━━━━━━━━━━┛\n\n";

            $msg .= "\n🧾 *80mm POS အရောင်းဘောက်ချာကို ပူးတွဲပေးပို့အပ်ပါသည်။*\n"
                 . "📦 ပစ္စည်းများကို အမြန်ဆုံး ထုပ်ပိုး ပို့ဆောင်ပေးသွားပါမည်။\n"
                 . "ကျေးဇူးတင်ပါသည်ရှင်! 🙏";
        }

        // 🧾 Generate 80mm POS Thermal Receipt Voucher Image
        $voucherPath = null;
        try {
            $voucherService = app(BotVoucherImageService::class);
            $voucherData = $voucherService->generateVoucherImage($sale);
            $voucherPath = $voucherData['path'] ?? null;
        } catch (\Throwable $ve) {
            Log::warning("Voucher image generation failed for sale #{$sale->reference_no}: " . $ve->getMessage());
        }

        // Send Voucher Image directly to customer
        $photoSent = false;
        if (!empty($voucherPath) && file_exists($voucherPath)) {
            $photoRes = $this->sendPhoto($sale->telegram_chat_id, $voucherPath, $msg, $this->getMainKeyboard($sale->telegram_chat_id));
            if (!empty($photoRes['ok'])) {
                $photoSent = true;
            } else {
                Log::warning("Telegram sendPhoto voucher error for order #{$sale->reference_no}: " . json_encode($photoRes));
            }
        }

        // Fallback to text message if photo sending failed or unavailable
        if (!$photoSent) {
            $this->sendMessage($sale->telegram_chat_id, $msg, $this->getMainKeyboard($sale->telegram_chat_id));
        }
    }

    /**
     * Notify Customer when order is rejected
     */
    public function notifyCustomerOrderRejected(Sale $sale, string $reason): void
    {
        if (empty($sale->telegram_chat_id)) {
            return;
        }

        $totalFormatted = number_format($sale->grand_total);

        $customTemplate = Setting::get('telegram_bot_order_rejected_msg', '');
        if (!empty(trim($customTemplate))) {
            $msg = $this->replacePlaceholders($customTemplate, [
                'order_ref' => $sale->reference_no,
                'reference_no' => $sale->reference_no,
                'order_id' => $sale->reference_no,
                'amount' => $totalFormatted,
                'grand_total' => $totalFormatted,
                'total' => $totalFormatted,
                'reason' => $reason,
                'customer_name' => $sale->customer_name ?: 'Customer',
                'user_name' => $sale->customer_name ?: 'Customer',
                'date' => $sale->date,
                'status' => '❌ ပယ်ဖျက်ထားပါသည် (Rejected)',
            ]);
        } else {
            $msg = "❌ *သင့်အော်ဒါအား ပယ်ဖျက်ထားပါသည်ရှင်*\n\n"
                 . "🔖 *အော်ဒါအမှတ်:* `{$sale->reference_no}`\n"
                 . "📝 *အကြောင်းပြချက်:* {$reason}\n\n"
                 . "အသေးစိတ် ထပ်မံသိရှိလိုပါက ဆိုင်သို့ တိုက်ရိုက် မေးမြန်းစုံစမ်းနိုင်ပါသည်ရှင်။ 🙏";
        }

        $this->sendMessage($sale->telegram_chat_id, $msg, $this->getMainKeyboard($sale->telegram_chat_id));
    }

    /**
     * Notify customer that order is dispatched / handed to rider
     */
    public function notifyCustomerOrderDispatched(
        Sale $sale,
        ?string $riderName = null,
        ?string $riderPhone = null,
        ?string $trackingCode = null,
        ?string $notes = null
    ): bool {
        if (!$this->isConfigured() || empty($sale->telegram_chat_id)) {
            return false;
        }

        $shopName = Setting::get('company_name', 'အလင်းသစ် (Ah Linn Thit)');
        $shopPhone = Setting::get('company_phone', '09123456789');

        $dispatchedTpl = Setting::get('telegram_bot_order_dispatched_msg', '');

        if (!empty(trim($dispatchedTpl))) {
            $msg = $this->replacePlaceholders($dispatchedTpl, [
                'order_ref' => $sale->reference_no,
                'customer_name' => $sale->customer?->name ?? 'ဖောက်သည်',
                'rider_name' => $riderName ?: 'ပို့ဆောင်ရေး အဖွဲ့သား',
                'rider_phone' => $riderPhone ?: '-',
                'tracking_code' => $trackingCode ?: '-',
                'notes' => $notes ?: '',
                'shop_name' => $shopName,
                'shop_phone' => $shopPhone,
            ]);
        } else {
            $msg = "🚚 *လူကြီးမင်း၏ အော်ဒါအား ပို့ဆောင်ရန် ထွက်ခွာပါပြီရှင်!*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "🔖 အော်ဒါအမှတ်: `{$sale->reference_no}`\n\n"
                 . "📊 *တိုးတက်မှု (Live Status Tracker):*\n"
                 . "┏━━━━━━━━━━━━━━━━━━━━┓\n"
                 . "  [✅ အတည်ပြုပြီး] ➔ [📦 ထုပ်ပိုးပြီး] ➔ [🚚 လမ်းပေါ်ရောက်ရှိ] ➔ [🎉 ပြီးဆုံး]\n"
                 . "┗━━━━━━━━━━━━━━━━━━━━┛\n\n";

            if (!empty($riderName)) {
                $msg .= "🛵 ပို့ဆောင်သူ (Rider): *{$riderName}*\n";
            }
            if (!empty($riderPhone)) {
                $msg .= "📞 ဆက်သွယ်ရန်ဖုန်း: `{$riderPhone}`\n";
            }
            if (!empty($trackingCode)) {
                $msg .= "📦 Tracking / ဂိတ်ပြေစာ: `{$trackingCode}`\n";
            }
            if (!empty($notes)) {
                $msg .= "📝 မှတ်ချက်: {$notes}\n";
            }

            $msg .= "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "ခဏတာအတွင်း လူကြီးမင်းထံ အရောက်ပို့ဆောင်ပေးပါမည်ရှင်။ ကျေးဇူးတင်ရှိပါသည်! 🙏";
        }

        $buttons = [];
        if (!empty($riderPhone)) {
            $cleanPhone = preg_replace('/[^0-9+]/', '', $riderPhone);
            $buttons[] = [
                ['text' => "📞 Rider သို့ တိုက်ရိုက်ဖုန်းခေါ်မည် ({$riderPhone})", 'url' => "tel:{$cleanPhone}"],
            ];
        }
        $buttons[] = [
            ['text' => '🛍️ ဆိုင်သို့ ပြန်သွားမည်', 'callback_data' => 'catalog_main'],
        ];

        $markup = ['inline_keyboard' => $buttons];
        $res = $this->sendMessage($sale->telegram_chat_id, $msg, $markup);
        return !empty($res['ok']);
    }

    /**
     * 🔔 Broadcast Restock Notifications to Waiting Customers
     */
    public function notifyRestockSubscribers(int $productId, ?int $variantId = null): int
    {
        if (!$this->isConfigured()) {
            return 0;
        }

        if (Setting::get('telegram_bot_restock_notify_enabled', '1') === '0') {
            return 0;
        }

        $query = BotRestockSubscriber::where('product_id', $productId)
            ->where('is_notified', false);

        if ($variantId !== null) {
            $query->where(function ($q) use ($variantId) {
                $q->where('product_variant_id', $variantId)
                  ->orWhereNull('product_variant_id');
            });
        }

        $subscribers = $query->get();
        if ($subscribers->isEmpty()) {
            return 0;
        }

        $product = Product::with(['primaryImage', 'images', 'category', 'brand', 'variants'])->find($productId);
        if (!$product) {
            return 0;
        }

        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
        $totalStock = (float)StockBalance::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->sum('quantity');

        if ($totalStock <= 0) {
            return 0;
        }

        $currency = Setting::get('currency_symbol', 'MMK');
        $effectivePrice = (float)($product->retail_price ?: $product->selling_price);
        $formattedPrice = number_format($effectivePrice) . ' ' . $currency;
        $sku = $product->code ?: ($product->sku ?: "PROD-{$product->id}");

        $lines = [
            "🎉 *ပစ္စည်းပြန်လည်ရောက်ရှိပါပြီရှင်!*",
            "━━━━━━━━━━━━━━━━━━━━━",
            "မင်္ဂလာပါရှင်၊ သင် စောင့်မျှော်နေသော ကုန်ပစ္စည်း ဆိုင်သို့ ပြန်လည်ရောက်ရှိလာပါပြီရှင်။",
            "",
            "📦 *{$product->name}*",
            "🏷️ SKU: `{$sku}`",
            "💰 စျေးနှုန်း: *{$formattedPrice}*",
            "🟢 စတော့လက်ကျန်: *{$totalStock} " . ($product->unit_name ?: 'ခု') . "*",
            "",
            "⚠️ _ပစ္စည်းလက်ကျန် အကန့်အသတ်ရှိသဖြင့် ဦးသူစနစ်ဖြင့် အမြန်ဆုံး ဝယ်ယူနိုင်ပါပြီရှင်!_",
        ];

        $caption = implode("\n", $lines);

        $inlineKeyboard = [
            [
                ['text' => '🛒 ယခုဝယ်မည် (Order Now)', 'callback_data' => "buy_{$product->id}"],
            ],
            [
                ['text' => '🛍️ ဆိုင်သို့သွားမည်', 'callback_data' => 'catalog_main'],
                ['text' => '🏠 ပင်မ မီနူး', 'callback_data' => 'nav_home'],
            ]
        ];

        // Telegram Mini App (TMA) Touch Storefront button
        if (Setting::get('telegram_bot_tma_enabled', '1') === '1') {
            $tmaUrl = trim(Setting::get('telegram_bot_tma_url', ''));
            if (empty($tmaUrl)) {
                $tmaUrl = url('/bot/shop');
            }
            array_unshift($inlineKeyboard, [
                ['text' => '🛍️ Mini App ဖြင့်ဖွင့်ကြည့်ရန်', 'web_app' => ['url' => $tmaUrl]]
            ]);
        }

        $markup = ['inline_keyboard' => $inlineKeyboard];

        $imagePath = $product->image_url 
            ?: ($product->primaryImage?->path ?: ($product->images->first()?->path ?? null));

        $sentCount = 0;
        foreach ($subscribers as $sub) {
            try {
                if (!empty($imagePath)) {
                    $res = $this->sendPhoto($sub->telegram_chat_id, $imagePath, $caption, $markup);
                    if (empty($res['ok'])) {
                        $this->sendMessage($sub->telegram_chat_id, $caption, $markup);
                    }
                } else {
                    $this->sendMessage($sub->telegram_chat_id, $caption, $markup);
                }

                $sub->update([
                    'is_notified' => true,
                    'notified_at' => now(),
                ]);
                $sentCount++;

                // Anti-spam delay to respect Telegram 30 msg/sec limit
                usleep(40000); // 40ms
            } catch (\Throwable $e) {
                Log::warning("Failed to notify restock subscriber {$sub->id}: " . $e->getMessage());
            }
        }

        return $sentCount;
    }

    /**
     * 🚨 Notify Admins that a customer needs human staff assistance
     */
    public function notifyAdminCustomerNeedsHelp(string|int $customerChatId, string $userName, string $usernameTag, string $query): void
    {
        $adminChatIds = Setting::get('telegram_admin_chat_ids', '');
        if (empty($adminChatIds)) return;
        $ids = array_map('trim', explode(',', $adminChatIds));

        $msg = "🚨 *Customer အကူအညီ လိုအပ်နေပါသည် (Needs Staff Attention)*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "👤 Customer:  *{$userName}* ({$usernameTag})\n"
             . "🆔 Chat ID:   `#CHAT_{$customerChatId}`\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n\n"
             . "💬 *မေးမြန်းထားသော စာ:*\n\"{$query}\"\n\n"
             . "👉 *ဤစာကို တိုက်ရိုက် Reply ပြန်၍ စာဖြေကြားနိုင်ပါသည်* သို့မဟုတ် POS Live Chat မှတစ်ဆင့် ဝင်ရောက်စကားပြောနိုင်ပါသည်။";

        foreach ($ids as $aid) {
            if (!empty($aid)) {
                $this->sendMessage($aid, $msg);
            }
        }
    }

    /**
     * 👨‍💼 Send Staff Message or Product Card to Customer from POS Live Chat
     */
    public function sendStaffMessageToCustomer(
        string|int $customerChatId,
        string $messageText,
        ?int $staffUserId = null,
        ?Product $productToSend = null
    ): array {
        if ($productToSend) {
            $prefix = "👨‍💼 *ဆိုင်ဝန်ထမ်းမှ ပေးပို့သော ပစ္စည်း အချက်အလက်:*\n\n";
            $priceFmt = number_format((float)($productToSend->retail_price ?: $productToSend->selling_price));
            $caption = "{$prefix}📦 *{$productToSend->name}*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n"
                     . "💰 ဈေးနှုန်း:   *{$priceFmt} MMK*\n"
                     . "📌 ကုတ်နံပါတ်: `{$productToSend->code}`\n"
                     . "━━━━━━━━━━━━━━━━━━━━━\n\n"
                     . (!empty($messageText) ? "💬 *မှတ်ချက်:* {$messageText}\n\n" : "")
                     . "မှာယူလိုပါက အောက်ပါခလုတ်ကို နှိပ်ပါရှင်-";

            $markup = [
                'inline_keyboard' => [
                    [
                        ['text' => "🔍 အသေးစိတ်/ဆိုဒ်ရွေးမည်", 'callback_data' => "prod_{$productToSend->id}"],
                        ['text' => "🛒 ခြင်းတောင်းထဲထည့်မည်", 'callback_data' => "buy_{$productToSend->id}"],
                    ]
                ]
            ];

            $res = null;
            if ($productToSend->image) {
                $res = $this->sendPhoto($customerChatId, $productToSend->image, $caption, $markup);
            }
            if (empty($res['ok'])) {
                $res = $this->sendMessage($customerChatId, $caption, $markup);
            }

            BotChatMessage::create([
                'telegram_chat_id' => (string)$customerChatId,
                'sender_type' => 'staff',
                'staff_user_id' => $staffUserId,
                'message_type' => 'product_card',
                'message_text' => $caption,
                'metadata' => ['product_id' => $productToSend->id],
                'is_read' => true,
            ]);

            return $res ?? ['ok' => false];
        }

        // Standard Text Message
        $prefix = "👨‍💼 *ဆိုင်ဝန်ထမ်းမှ စာပြန်ကြားချက်:*\n\n";
        $fullText = $prefix . $messageText;
        $res = $this->sendMessage($customerChatId, $fullText);

        if (!empty($res['ok'])) {
            BotChatMessage::create([
                'telegram_chat_id' => (string)$customerChatId,
                'sender_type' => 'staff',
                'staff_user_id' => $staffUserId,
                'message_type' => 'text',
                'message_text' => $messageText,
                'is_read' => true,
            ]);
        }

        return $res;
    }
}

