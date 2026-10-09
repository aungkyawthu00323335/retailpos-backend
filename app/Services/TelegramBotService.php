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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class TelegramBotService
{
    protected ?string $token;
    protected string $apiUrl;

    public function __construct()
    {
        $this->refreshToken();
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
     * Normalize Myanmar and International Phone Numbers
     */
    public function normalizePhoneNumber(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', trim($phone));
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
            $this->sendChatAction($chatId, 'upload_photo');

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
    public function sendCartView(int|string $chatId, string $userName = '', ?int $messageIdToEdit = null): void
    {
        $cartSummary = $this->getCartSummary($chatId);
        $cart = $cartSummary['cart'];

        $btnAll = Setting::get('telegram_bot_btn_all_products', '📦 ပစ္စည်းအားလုံး ကြည့်မည်');
        $btnMainMenu = Setting::get('telegram_bot_btn_main_menu', '🔙 ပင်မ မီနူးသို့');

        if (empty($cart)) {
            $cartEmptyTpl = Setting::get('telegram_bot_cart_empty_msg', '');
            $emptyMsg = !empty(trim($cartEmptyTpl))
                ? $this->replacePlaceholders($cartEmptyTpl, ['customer_name' => $userName, 'user_name' => $userName])
                : "🛒 *သင်၏ ခြင်းတောင်း (Shopping Cart) ထဲတွင် ပစ္စည်းမရှိသေးပါရှင်*\n\n"
                . "ကျွန်မတို့ဆိုင်၏ အရည်အသွေးမြင့် ကုန်ပစ္စည်းများကို '🛍️ ကုန်ပစ္စည်းများ' မှတဆင့် စိတ်ကြိုက် ရွေးချယ်မှာယူနိုင်ပါသည်ရှင်။ 🙏";

            $emptyButtons = [
                'inline_keyboard' => [
                    [['text' => !empty($btnAll) ? $btnAll : '🛍️ ကုန်ပစ္စည်းများ ကြည့်မည်', 'callback_data' => 'nav_all_products']],
                    [['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main']],
                ],
            ];

            if ($messageIdToEdit) {
                $res = $this->editMessageText($chatId, $messageIdToEdit, $emptyMsg, $emptyButtons);
                if (!empty($res['ok'])) return;
            }
            $this->sendMessage($chatId, $emptyMsg, $emptyButtons);
            return;
        }

        $totalQDisplay = (floor($cartSummary['total_qty']) == $cartSummary['total_qty']) ? (int)$cartSummary['total_qty'] : $cartSummary['total_qty'];
        $text = "🛒 *သင်၏ ခြင်းတောင်း (Shopping Cart)* [{$totalQDisplay} ခု | {$cartSummary['items_count']} မျိုး]\n"
              . "━━━━━━━━━━━━━━━━━━━━━\n\n";

        $idx = 1;
        $removeRows = [];

        foreach ($cart as $key => $item) {
            $title = !empty($item['variant_name']) ? "{$item['product_name']} ({$item['variant_name']})" : $item['product_name'];
            $priceFmt = number_format($item['price']);
            $subFmt = number_format($item['subtotal']);
            $unit = $item['unit_name'] ?? 'ခု';

            $text .= "{$idx}. *{$title}*\n"
                   . "   • အရေအတွက်: {$item['quantity']} {$unit} × {$priceFmt} MMK\n"
                   . "   • ကျသင့်ငွေ: *{$subFmt} MMK*\n\n";

            $shortTitle = mb_strimwidth($title, 0, 24, '...');
            $removeRows[] = [['text' => "❌ {$shortTitle}", 'callback_data' => "cart_rem_{$key}"]];
            $idx++;
        }

        $grandTotalFmt = number_format($cartSummary['grand_total']);
        $text .= "━━━━━━━━━━━━━━━━━━━━━\n"
              . "🛍️ ပစ္စည်း စုစုပေါင်း: *{$cartSummary['total_qty']} ခု* ({$cartSummary['items_count']} မျိုး)\n"
              . "💰 *စုစုပေါင်း ကျသင့်ငွေ:* *{$grandTotalFmt} MMK*\n\n"
              . "မှာယူလိုပါက '💳 အော်ဒါတင်မည်' ခလုတ်ကို နှိပ်ပါရှင်-";

        $btnCheckout = Setting::get('telegram_bot_btn_checkout', '💳 အော်ဒါတင်မည်');
        $btnContinue = Setting::get('telegram_bot_btn_continue_shopping', '➕ ပစ္စည်း ဆက်ဝယ်မည်');
        $btnClear = Setting::get('telegram_bot_btn_clear_cart', '🗑️ ခြင်းတောင်း ရှင်းမည်');

        $buttons = [
            [
                ['text' => (!empty($btnCheckout) ? $btnCheckout : '💳 အော်ဒါတင်မည်') . " ({$grandTotalFmt} MMK)", 'callback_data' => 'cart_checkout'],
            ],
            [
                ['text' => !empty($btnContinue) ? $btnContinue : '➕ ပစ္စည်း ဆက်ဝယ်မည်', 'callback_data' => 'nav_all_products'],
            ],
            [
                ['text' => !empty($btnClear) ? $btnClear : '🗑️ ခြင်းတောင်း ရှင်းမည်', 'callback_data' => 'cart_clear'],
            ],
        ];

        // Attach item remove buttons
        foreach ($removeRows as $r) {
            $buttons[] = $r;
        }

        $buttons[] = [
            ['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
        ];

        $markup = ['inline_keyboard' => $buttons];

        if ($messageIdToEdit) {
            $res = $this->editMessageText($chatId, $messageIdToEdit, $text, $markup);
            if (!empty($res['ok'])) return;
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
            $this->sendCartView($chatId, $userName);
            return;
        }

        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);

        // Inventory Stock verification
        $outOfStockList = [];
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
        $itemsList = collect($cart)->map(function ($it) {
            $title = !empty($it['variant_name']) ? "{$it['product_name']} ({$it['variant_name']})" : $it['product_name'];
            return "• {$title} x {$it['quantity']} " . ($it['unit_name'] ?? 'ခု') . " = " . number_format($it['subtotal']) . " MMK";
        })->implode("\n");

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
             . "💰 စုစုပေါင်း ကျသင့်ငွေ: *{$totalFormatted} MMK*\n\n"
             . "{$askPrompt}";

        $btnCancel = Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)');
        $btnSharePhone = Setting::get('telegram_bot_btn_share_phone', '📱 ကျွန်ုပ်၏ ဖုန်းနံပါတ် ပေးပို့မည် (Share Phone)');
        $btnCart = Setting::get('telegram_bot_btn_cart', '🛒 ခြင်းတောင်း ကြည့်မည်');
        $phoneKeyboard = [
            'keyboard' => [
                [['text' => !empty($btnSharePhone) ? $btnSharePhone : '📱 ကျွန်ုပ်၏ ဖုန်းနံပါတ် ပေးပို့မည် (Share Phone)', 'request_contact' => true]],
                [['text' => !empty($btnCart) ? $btnCart : '🛒 ခြင်းတောင်း ကြည့်မည်']],
                [['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)']],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];

        $this->sendMessage($chatId, $msg, $phoneKeyboard);
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
            $res = Http::timeout(10)->withoutVerifying()->post("{$this->getApiUrl()}/answerCallbackQuery", $payload);
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

        $this->sendChatAction($chatId, 'typing');

        $userName = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? ''));
        $usernameTag = !empty($message['from']['username']) ? '@' . $message['from']['username'] : '';
        $text = trim($message['text'] ?? '');

        // 1. Photo message (Payment Slip)
        if (!empty($message['photo'])) {
            $this->handlePhotoMessage($chatId, $message['photo'], $userName, $usernameTag);
            return;
        }

        // 2. Document message (Payment Slip sent as file / uncompressed image)
        if (!empty($message['document'])) {
            $this->handleDocumentMessage($chatId, $message['document'], $userName, $usernameTag);
            return;
        }

        // 3. Contact shared (1-Tap Verified Contact)
        if (!empty($message['contact']['phone_number'])) {
            $this->handleContactMessage($chatId, $message['contact']['phone_number'], $userName);
            return;
        }

        // 4. Location shared (1-Tap GPS Location)
        if (!empty($message['location'])) {
            $this->handleLocationMessage($chatId, (float)$message['location']['latitude'], (float)$message['location']['longitude'], $userName);
            return;
        }

        // 3. Command & Menu Handling
        if (str_starts_with($text, '/start') || strcasecmp($text, 'hi') === 0 || strcasecmp($text, 'hello') === 0 || strcasecmp($text, 'menu') === 0) {
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
        $btnCart = trim((string)Setting::get('telegram_bot_btn_cart', '🛒 ခြင်းတောင်း ကြည့်မည်'));
        $btnOrders = trim((string)Setting::get('telegram_bot_btn_orders', '📦 အော်ဒါများ'));
        $btnPayment = trim((string)Setting::get('telegram_bot_btn_payment', 'ℹ️ ငွေလွှဲရန် အချက်အလက်'));
        $btnContact = trim((string)Setting::get('telegram_bot_btn_contact', '📞 ဆိုင်နှင့် ဆက်သွယ်ရန်'));
        $btnCancel = trim((string)Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)'));

        if (($btnCatalog !== '' && ($text === $btnCatalog || str_contains($text, 'ကုန်ပစ္စည်းများ')))
            || $text === '🛍️ ကုန်ပစ္စည်းများ'
            || $text === '🛍️ ကုန်ပစ္စည်းများ ကြည့်မည်'
            || $text === 'ကုန်ပစ္စည်းများ'
            || str_starts_with($text, '/catalog')) {
            $this->sendCatalogMenu($chatId);
            return;
        }

        $isCartCommand = ($btnCart !== '' && (str_starts_with($text, $btnCart) || $text === $btnCart))
            || str_starts_with($text, '🛒 ခြင်းတောင်း')
            || str_contains($text, 'ခြင်းတောင်း')
            || str_starts_with($text, '/cart');

        if ($isCartCommand) {
            $this->sendCartView($chatId, $userName);
            return;
        }

        if (($btnOrders !== '' && ($text === $btnOrders || str_contains($text, 'အော်ဒါများ')))
            || $text === '📦 အော်ဒါများ'
            || $text === '📦 ကျွန်ုပ်၏ အော်ဒါများ'
            || $text === '🚚 အော်ဒါများ'
            || str_starts_with($text, '/orders')) {
            $this->sendCustomerOrders($chatId);
            return;
        }

        if (($btnPayment !== '' && ($text === $btnPayment || str_contains($text, 'ငွေလွှဲ')))
            || $text === 'ℹ️ ငွေလွှဲရန် အချက်အလက်'
            || $text === '💳 ငွေလွှဲရန်'
            || str_starts_with($text, '/payment')) {
            $this->sendPaymentInfo($chatId);
            return;
        }

        if (($btnContact !== '' && ($text === $btnContact || str_contains($text, 'ဆက်သွယ်ရန်')))
            || $text === '📞 ဆိုင်နှင့် ဆက်သွယ်ရန်'
            || $text === '✉️ ဆိုင်ကိုဆက်သွယ်ရန်'
            || str_starts_with($text, '/contact')) {
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

        // Default greeting / welcome menu
        $this->sendWelcomeMenu($chatId, $userName);
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
     * Catalog Navigation Hub: Options to view all, by category, by brand, or search
     */
    public function sendCatalogMenu(int|string $chatId, ?int $messageIdToEdit = null): void
    {
        $tpl = Setting::get('telegram_bot_catalog_menu_msg', '');
        $text = !empty(trim($tpl))
            ? $this->replacePlaceholders($tpl, [])
            : "🛍️ *ကုန်ပစ္စည်းများ ကြည့်ရှု/မှာယူရန် ရွေးချယ်ပါ*\n\n"
            . "အောက်ပါ ခလုတ်များမှတဆင့် အမျိုးအစားအလိုက်၊ တံဆိပ်အလိုက် (သို့မဟုတ်) ရှာဖွေ၍ လွယ်ကူစွာ မှာယူနိုင်ပါသည်-";

        $btnAll = Setting::get('telegram_bot_btn_all_products', '📦 ပစ္စည်းအားလုံး ကြည့်မည် (All Products)');
        $btnCats = Setting::get('telegram_bot_btn_categories', '📂 အမျိုးအစားအလိုက် (Categories)');
        $btnBrands = Setting::get('telegram_bot_btn_brands', '🏷️ တံဆိပ်အလိုက် (Brands)');
        $btnSearch = Setting::get('telegram_bot_btn_search', '🔎 အမည် / ကုတ်နံပါတ်ဖြင့် ရှာမည် (Search)');
        $btnCancel = Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)');

        $buttons = [
            [
                ['text' => !empty($btnAll) ? $btnAll : '📦 ပစ္စည်းအားလုံး ကြည့်မည် (All Products)', 'callback_data' => 'nav_all_products'],
            ],
            [
                ['text' => !empty($btnCats) ? $btnCats : '📂 အမျိုးအစားအလိုက် (Categories)', 'callback_data' => 'nav_categories'],
            ],
            [
                ['text' => !empty($btnBrands) ? $btnBrands : '🏷️ တံဆိပ်အလိုက် (Brands)', 'callback_data' => 'nav_brands'],
            ],
            [
                ['text' => !empty($btnSearch) ? $btnSearch : '🔎 အမည် / ကုတ်နံပါတ်ဖြင့် ရှာမည် (Search)', 'callback_data' => 'nav_search'],
            ],
            [
                ['text' => '🏠 ပင်မ မီနူး (Home)', 'callback_data' => 'nav_home'],
                ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
            ],
        ];

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
        $categories = Cache::remember('tg_bot_active_categories', 300, function () {
            return ProductCategory::whereHas('products', function ($q) {
                $q->where('is_bot_available', true)
                  ->where(function ($sub) {
                      $sub->where('is_active', true)->orWhere('status', 'Active');
                  });
            })->get();
        });

        $btnAll = Setting::get('telegram_bot_btn_all_products', '📦 ပစ္စည်းအားလုံး ကြည့်မည်');
        $btnBack = Setting::get('telegram_bot_btn_back', '🔙 နောက်သို့');
        $btnMainMenu = Setting::get('telegram_bot_btn_main_menu', '🔙 ပင်မ မီနူးသို့');

        if ($categories->isEmpty()) {
            $noCatsTpl = Setting::get('telegram_bot_no_categories_msg', '');
            $noCatsMsg = !empty(trim($noCatsTpl))
                ? $this->replacePlaceholders($noCatsTpl, [])
                : "ℹ️ လက်ရှိတွင် အမျိုးအစား ခွဲခြားထားသော Bot ပစ္စည်းများ မရှိသေးပါရှင်။";

            $this->editOrSendMessage($chatId, $messageIdToEdit, $noCatsMsg, [
                'inline_keyboard' => [
                    [['text' => !empty($btnAll) ? $btnAll : '📦 ပစ္စည်းအားလုံး ကြည့်မည်', 'callback_data' => 'nav_all_products']],
                    [['text' => !empty($btnBack) ? $btnBack : '🔙 နောက်သို့', 'callback_data' => 'catalog_main']],
                ],
            ]);
            return;
        }

        $text = "📂 *အမျိုးအစား (Category) ရွေးချယ်ပါ-*";
        $buttons = [];

        foreach ($categories as $cat) {
            $buttons[] = [
                ['text' => "📁 {$cat->name}", 'callback_data' => "f_cat_{$cat->id}"],
            ];
        }

        $buttons[] = [
            ['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
        ];

        $this->editOrSendMessage($chatId, $messageIdToEdit, $text, ['inline_keyboard' => $buttons]);
    }

    /**
     * Show Brand Filter Options
     */
    public function sendBrandFilterMenu(int|string $chatId, ?int $messageIdToEdit = null): void
    {
        $brands = Cache::remember('tg_bot_active_brands', 300, function () {
            return Brand::whereHas('products', function ($q) {
                $q->where('is_bot_available', true)
                  ->where(function ($sub) {
                      $sub->where('is_active', true)->orWhere('status', 'Active');
                  });
            })->get();
        });

        $btnAll = Setting::get('telegram_bot_btn_all_products', '📦 ပစ္စည်းအားလုံး ကြည့်မည်');
        $btnBack = Setting::get('telegram_bot_btn_back', '🔙 နောက်သို့');
        $btnMainMenu = Setting::get('telegram_bot_btn_main_menu', '🔙 ပင်မ မီနူးသို့');

        if ($brands->isEmpty()) {
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
            $buttons[] = [
                ['text' => "🏷️ {$brand->name}", 'callback_data' => "f_brand_{$brand->id}"],
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

        $perPage = (int)Setting::get('telegram_bot_catalog_per_page', 3);
        $perPage = max(1, min(15, $perPage));

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
                    $cardLines[] = "📌 ကုတ်: `{$p->code}`";
                }

                // 3. Price
                if ($showPrice) {
                    $priceFormatted = number_format($effectivePrice);
                    $cardLines[] = "💰 ဈေးနှုန်း: *{$priceFormatted} MMK*";
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
                    $cardLines[] = "📦 {$stockText}";
                }

                // 5. Details / Description snippet
                if ($showDetails && !empty($p->description)) {
                    $cleanDesc = trim(strip_tags($p->description));
                    if (!empty($cleanDesc)) {
                        $snippet = mb_substr($cleanDesc, 0, 95) . (mb_strlen($cleanDesc) > 95 ? '...' : '');
                        $cardLines[] = "📝 *အသေးစိတ်:* _{$snippet}_";
                    }
                }

                // 6. Variants
                $hasActiveVariants = $p->has_variants && $p->variants && $p->variants->isNotEmpty();
                if ($showVariants && $hasActiveVariants) {
                    $vNames = $p->variants->pluck('name')->implode(', ');
                    $cardLines[] = "🎨 ဆိုဒ်များ: {$vNames}";
                }

                // 7. Action Button attached DIRECTLY to this product!
                if ($hasActiveVariants) {
                    $buyBtnText = !empty($customSelectVar) ? $customSelectVar : '🎨 ဆိုဒ်ရွေးမည် (Select Size)';
                    $buyCallback = "buy_{$p->id}";
                } else {
                    if ($stock <= 0) {
                        if ($allowOutOfStock) {
                            $buyBtnText = !empty($customPreorder) ? $customPreorder : '⏳ ကြိုတင်မှာမည် (Pre-Order)';
                            $buyCallback = "buy_{$p->id}";
                        } else {
                            $buyBtnText = "⚠️ ပစ္စည်းပြတ် (Sold Out)";
                            $buyCallback = "stock_out_{$p->id}";
                        }
                    } else {
                        $buyBtnText = !empty($customBuy) ? $customBuy : '🛒 မှာယူမည် (Order Now)';
                        $buyCallback = "buy_{$p->id}";
                    }
                }

                $cardButtons = [
                    [
                        ['text' => $buyBtnText, 'callback_data' => $buyCallback],
                    ]
                ];

                if (!$hasActiveVariants && $stock <= 0 && !$allowOutOfStock) {
                    $cardButtons[] = [
                        ['text' => '🔔 ရောက်လျှင် သတိပေးပါ (Notify Me)', 'callback_data' => "stock_notify_{$p->id}"],
                    ];
                }

                $caption = implode("\n", $cardLines);

                $imagePath = $p->image_url 
                    ?: ($p->primaryImage?->path ?: ($p->images->first()?->path ?? null));

                $sent = false;
                if (!empty($imagePath)) {
                    $res = $this->sendPhoto($chatId, $imagePath, $caption, ['inline_keyboard' => $cardButtons]);
                    if (!empty($res['ok'])) {
                        $sent = true;
                    }
                }

                if (!$sent) {
                    $this->sendMessage($chatId, $caption, ['inline_keyboard' => $cardButtons]);
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

            $navButtons = [];

            if ($totalPages > 1) {
                $paginationRow = [];
                if ($page > 1) {
                    $prevPage = $page - 1;
                    $paginationRow[] = ['text' => !empty($btnPrev) ? $btnPrev : '◀️ ရှေ့သို့', 'callback_data' => "cat_p_{$prevPage}_{$filterToken}"];
                }
                $paginationRow[] = ['text' => "📄 {$page}/{$totalPages}", 'callback_data' => 'noop'];
                if ($page < $totalPages) {
                    $nextPage = $page + 1;
                    $paginationRow[] = ['text' => !empty($btnNext) ? $btnNext : 'နောက်သို့ ▶️', 'callback_data' => "cat_p_{$nextPage}_{$filterToken}"];
                }
                $navButtons[] = $paginationRow;
            }

            // Dynamic Cart Bar if items exist in cart
            $cartSummary = $this->getCartSummary($chatId);
            if (($cartSummary['items_count'] ?? 0) > 0) {
                $totalQ = (floor($cartSummary['total_qty']) == $cartSummary['total_qty']) ? (int)$cartSummary['total_qty'] : $cartSummary['total_qty'];
                $grandT = number_format($cartSummary['grand_total']);
                $navButtons[] = [
                    ['text' => "💳 အော်ဒါတင်မည် ({$grandT} MMK)", 'callback_data' => 'cart_checkout'],
                ];
                $navButtons[] = [
                    ['text' => "🛒 ခြင်းတောင်း ကြည့်မည် ({$totalQ} ခု)", 'callback_data' => 'cart_view'],
                ];
            }

            $navButtons[] = [
                ['text' => !empty($btnCats) ? $btnCats : '📂 အမျိုးအစားများ', 'callback_data' => 'nav_categories'],
            ];
            $navButtons[] = [
                ['text' => !empty($btnBrands) ? $btnBrands : '🏷️ တံဆိပ်များ', 'callback_data' => 'nav_brands'],
            ];
            $navButtons[] = [
                ['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
            ];
            $navButtons[] = [
                ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
            ];

            $navMsg = "━━━━━━━━━━━━━━━━━━━━━\n"
                    . "📄 *စာမျက်နှာ:* {$page} / {$totalPages} (စုစုပေါင်း {$totalCount} မျိုး)\n\n"
                    . "💡 မိမိမှာယူလိုသော ပစ္စည်းအောက်ရှိ '{$customBuy}' ကို နှိပ်၍ တိုက်ရိုက်မှာယူနိုင်ပါသည်ရှင်-";

            $this->sendMessage($chatId, $navMsg, ['inline_keyboard' => $navButtons]);
            return;
        }

        // =========================================================================
        // 📋 2. SINGLE SUMMARY STYLE (Aggregated text list)
        // =========================================================================
        $text = "{$headerTitle} (စာမျက်နှာ {$page}/{$totalPages})\n"
              . "━━━━━━━━━━━━━━━━━━━━━\n\n";

        $buttons = [];
        $index = 1;

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
                $cardLines[] = "   📌 ကုတ်: `{$p->code}`";
            }

            // 3. Price
            if ($showPrice) {
                $priceFormatted = number_format($effectivePrice);
                $cardLines[] = "   💰 ဈေးနှုန်း: *{$priceFormatted} MMK*";
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
                $cardLines[] = "   📦 {$stockText}";
            }

            // 5. Details / Description snippet
            if ($showDetails && !empty($p->description)) {
                $cleanDesc = trim(strip_tags($p->description));
                if (!empty($cleanDesc)) {
                    $snippet = mb_substr($cleanDesc, 0, 75) . (mb_strlen($cleanDesc) > 75 ? '...' : '');
                    $cardLines[] = "   📝 *အသေးစိတ်:* _{$snippet}_";
                }
            }

            // 6. Variants
            $hasActiveVariants = $p->has_variants && $p->variants && $p->variants->isNotEmpty();
            if ($showVariants && $hasActiveVariants) {
                $vNames = $p->variants->pluck('name')->implode(', ');
                $cardLines[] = "   🎨 ဆိုဒ်များ: {$vNames}";
            }

            // Card Divider
            $text .= implode("\n", $cardLines) . "\n───────────────────\n\n";

            // 7. Action Buttons
            if ($hasActiveVariants) {
                $buyBtnText = "{$itemNum}. " . (!empty($customSelectVar) ? $customSelectVar : '🎨 ဆိုဒ်ရွေးမည်');
                $buyCallback = "buy_{$p->id}";
            } else {
                if ($stock <= 0) {
                    if ($allowOutOfStock) {
                        $buyBtnText = "{$itemNum}. " . (!empty($customPreorder) ? $customPreorder : '⏳ ကြိုတင်မှာမည်');
                        $buyCallback = "buy_{$p->id}";
                    } else {
                        $buyBtnText = "{$itemNum}. ⚠️ ပစ္စည်းပြတ်";
                        $buyCallback = "stock_out_{$p->id}";
                    }
                } else {
                    $buyBtnText = "{$itemNum}. " . (!empty($customBuy) ? $customBuy : '🛒 မှာမည်');
                    $buyCallback = "buy_{$p->id}";
                }
            }

            $rowButtons = [];
            if ($showImageBtn) {
                $viewBtnText = "{$itemNum}. " . (!empty($customView) ? $customView : '🔍 အသေးစိတ်/ပုံ');
                $rowButtons[] = ['text' => $viewBtnText, 'callback_data' => "view_{$p->id}"];
            }
            $rowButtons[] = ['text' => $buyBtnText, 'callback_data' => $buyCallback];
            $buttons[] = $rowButtons;
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

        $paginationRow = [];
        if ($page > 1) {
            $prevPage = $page - 1;
            $paginationRow[] = ['text' => !empty($btnPrev) ? $btnPrev : '◀️ ရှေ့သို့', 'callback_data' => "cat_p_{$prevPage}_{$filterToken}"];
        }

        $paginationRow[] = ['text' => "📄 {$page}/{$totalPages}", 'callback_data' => 'noop'];

        if ($page < $totalPages) {
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
            $buttons[] = [
                ['text' => "💳 အော်ဒါတင်မည် ({$grandT} MMK)", 'callback_data' => 'cart_checkout'],
            ];
            $buttons[] = [
                ['text' => "🛒 ခြင်းတောင်း ({$totalQ} ခု)", 'callback_data' => 'cart_view'],
            ];
        }

        $buttons[] = [
            ['text' => !empty($btnCats) ? $btnCats : '📂 အမျိုးအစားများ', 'callback_data' => 'nav_categories'],
        ];
        $buttons[] = [
            ['text' => !empty($btnBrands) ? $btnBrands : '🏷️ တံဆိပ်များ', 'callback_data' => 'nav_brands'],
        ];
        $buttons[] = [
            ['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
        ];
        $buttons[] = [
            ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
        ];

        if ($messageIdToEdit) {
            $res = $this->editMessageText($chatId, $messageIdToEdit, $text, ['inline_keyboard' => $buttons]);
            if (!empty($res['ok'])) {
                return;
            }
        }

        $this->sendMessage($chatId, $text, ['inline_keyboard' => $buttons]);
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
                 . "📌 *ကုတ်နံပါတ်:* `{$product->code}`\n"
                 . ($product->category_name ? "📂 *အမျိုးအစား:* {$product->category_name}\n" : "")
                 . ($product->brand_name ? "🏷️ *တံဆိပ်:* {$product->brand_name}\n" : "")
                 . "💰 *ဈေးနှုန်း:* {$priceText}\n"
                 . "📦 *လက်ကျန်အခြေအနေ:* {$stockBadge}\n";

        if ($hasActiveVariants) {
            $vNames = $activeVariants->pluck('name')->implode(', ');
            $caption .= "🎨 *ရွေးချယ်နိုင်သော ဆိုဒ်များ:* {$vNames}\n";
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

        $buyBtnText = $hasActiveVariants
            ? (!empty($btnSelectVar) ? $btnSelectVar : '🎨 ဆိုဒ်ရွေး၍ မှာယူမည်')
            : (!empty($btnOrderNow) ? $btnOrderNow : '🛒 မှာယူမည် (Order Now)');

        $buttons = [
            [
                ['text' => $buyBtnText, 'callback_data' => "buy_{$product->id}"],
            ],
        ];

        $cartSummary = $this->getCartSummary($chatId);
        if (($cartSummary['items_count'] ?? 0) > 0) {
            $totalQ = (floor($cartSummary['total_qty']) == $cartSummary['total_qty']) ? (int)$cartSummary['total_qty'] : $cartSummary['total_qty'];
            $grandT = number_format($cartSummary['grand_total']);
            $buttons[] = [
                ['text' => "💳 အော်ဒါတင်မည် ({$grandT} MMK)", 'callback_data' => 'cart_checkout'],
            ];
            $buttons[] = [
                ['text' => "🛒 ခြင်းတောင်း ကြည့်မည် ({$totalQ} ခု)", 'callback_data' => 'cart_view'],
            ];
        }

        $buttons[] = [
            ['text' => !empty($btnBackCatalog) ? $btnBackCatalog : '🔙 ကတ်တလောက်သို့ ပြန်သွားမည်', 'callback_data' => $backCallback ?: 'nav_all_products'],
        ];
        $buttons[] = [
            ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
        ];

        // Resolve Image Source
        $imagePath = $product->image_url 
            ?: ($product->primaryImage?->path ?: ($product->images->first()?->path ?? null));

        $photoSent = false;
        if (!empty($imagePath)) {
            $res = $this->sendPhoto($chatId, $imagePath, $caption, ['inline_keyboard' => $buttons]);
            if (!empty($res['ok'])) {
                $photoSent = true;
            }
        }

        // If photo sending failed or product has no photo, fallback to formatted message card
        if (!$photoSent) {
            $fallbackMsg = "🖼️ [ဓာတ်ပုံ မရှိပါ]\n\n" . $caption;
            $this->sendMessage($chatId, $fallbackMsg, ['inline_keyboard' => $buttons]);
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

        $buttons[] = [
            ['text' => !empty($btnBackCatalog) ? $btnBackCatalog : '🔙 ပစ္စည်းစာရင်းသို့', 'callback_data' => 'nav_all_products'],
        ];
        $buttons[] = [
            ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
        ];

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

        $baseHeader = "🛒 *ခြင်းတောင်း အပ်ဒိတ် လုပ်လိုက်ပါပြီရှင်!*\n"
                    . "━━━━━━━━━━━━━━━━━━━━━\n"
                    . "✅ *{$addedItemTitle}* x {$addedQty} {$unitName} အား ခြင်းတောင်းထဲသို့ ထည့်သွင်းပြီးပါပြီ။\n\n"
                    . "🛍️ *လူကြီးမင်း ခြင်းတောင်းထဲရှိ ပစ္စည်းများ:*\n\n";

        $baseFooter = "\n━━━━━━━━━━━━━━━━━━━━━\n"
                    . "📦 ပစ္စည်း စုစုပေါင်း: *{$totalQtyDisplay} ခု* ({$itemsCount} မျိုး)\n"
                    . "💰 *စုစုပေါင်း ကျသင့်ငွေ:* *{$totalFormatted} MMK*\n\n"
                    . "အော်ဒါတင်လိုပါက '💳 အော်ဒါတင်မည်' ကို နှိပ်ပါ (သို့မဟုတ်) အခြားပစ္စည်းများ ဆက်လက်ရွေးချယ်နိုင်ပါသည်-";

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
                  . "   • အရေအတွက်: `{$q}` {$u} × `{$p}` MMK = *{$sub} MMK*";

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

        $msg = "🛍️ *{$displayName}*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "💰 ဈေးနှုန်း: *" . number_format($effectivePrice) . " MMK*\n"
             . "📦 လက်ကျန်: `{$stock}` " . ($product->unit_name ?: 'ခု') . "\n\n"
             . "👇 မှာယူလိုသော အရေအတွက်ကို အောက်ပါခလုတ်များမှ ရွေးချယ်ပါ (သို့မဟုတ်) လိုချင်သော အရေအတွက်ကို ကိန်းဂဏန်း တိုက်ရိုက် စာရိုက်ပို့ပေးနိုင်ပါသည်ရှင်-\n"
             . "(💡 ဥပမာ- `10` သို့မဟုတ် `၁၀`)";

        $btnBackVar = Setting::get('telegram_bot_btn_back_variant', '🔙 အခြားဆိုဒ် ပြန်ရွေးမည်');
        $btnBackCatalog = Setting::get('telegram_bot_btn_back_to_catalog', '🔙 ပစ္စည်းစာရင်းသို့');
        $btnCancel = Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)');

        $btnQty1 = Setting::get('telegram_bot_btn_qty_1', '1 ခု');
        $btnQty2 = Setting::get('telegram_bot_btn_qty_2', '2 ခု');
        $btnQty3 = Setting::get('telegram_bot_btn_qty_3', '3 ခု');
        $btnQty5 = Setting::get('telegram_bot_btn_qty_5', '5 ခု');

        $backButton = ($variant || $product->has_variants)
            ? ['text' => !empty($btnBackVar) ? $btnBackVar : '🔙 အခြားဆိုဒ် ပြန်ရွေးမည်', 'callback_data' => "buy_{$product->id}"]
            : ['text' => !empty($btnBackCatalog) ? $btnBackCatalog : '🔙 ပစ္စည်းစာရင်းသို့', 'callback_data' => 'nav_all_products'];

        $inlineKeyboard = [
            [
                ['text' => !empty($btnQty1) ? $btnQty1 : '1 ခု', 'callback_data' => "qty_{$product->id}_1"],
                ['text' => !empty($btnQty2) ? $btnQty2 : '2 ခု', 'callback_data' => "qty_{$product->id}_2"],
                ['text' => !empty($btnQty3) ? $btnQty3 : '3 ခု', 'callback_data' => "qty_{$product->id}_3"],
                ['text' => !empty($btnQty5) ? $btnQty5 : '5 ခု', 'callback_data' => "qty_{$product->id}_5"],
            ],
            [
                $backButton,
            ],
            [
                ['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
            ],
        ];

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

        // 💬 Real-time "typing..." indicator in customer chat header
        $this->sendChatAction($chatId, 'typing');

        // 🏠 Breadcrumb: Universal Home Menu Handler
        if ($data === 'nav_home') {
            Cache::forget("tg_session_{$chatId}");
            $this->answerCallbackQuery($id);
            $welcomeMsg = $this->formatWelcomeMessage($userName, (string)$chatId);
            $this->sendMessage($chatId, $welcomeMsg, $this->getMainKeyboard($chatId));
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
            $this->sendCatalogMenu($chatId, $messageId);
            return;
        }

        if ($data === 'noop') {
            $this->answerCallbackQuery($id);
            return;
        }

        // 🔄 1-Click Reorder Action (Loads past items into active cart)
        if (str_starts_with($data, 'reorder_')) {
            $saleId = (int)str_replace('reorder_', '', $data);
            $sale = Sale::with('items')->find($saleId);
            if ($sale && $sale->items && $sale->items->isNotEmpty()) {
                $this->answerCallbackQuery($id, 'ပစ္စည်းများကို ခြင်းတောင်းထဲ ထည့်သွင်းပေးနေပါသည်...');
                $addedCount = 0;
                foreach ($sale->items as $it) {
                    $prod = Product::find($it->product_id);
                    if ($prod && $prod->is_bot_available) {
                        $variant = $it->product_variant_id ? ProductVariant::find($it->product_variant_id) : null;
                        $this->addToCart($chatId, $prod, $variant, (float)$it->quantity);
                        $addedCount++;
                    }
                }
                if ($addedCount > 0) {
                    $this->sendCartView($chatId, $userName);
                } else {
                    $this->sendMessage($chatId, "⚠️ ယခင်မှာယူခဲ့သော ပစ္စည်းများသည် လက်ရှိတွင် ရောင်းချခြင်း မရှိတော့ပါသဖြင့် ပြန်လည်မမှာယူနိုင်သေးပါရှင်။", $this->getMainKeyboard($chatId));
                }
            } else {
                $this->answerCallbackQuery($id, 'အော်ဒါ ရှာမတွေ့ပါ');
            }
            return;
        }

        // 🛒 Cart Actions
        if ($data === 'cart_view') {
            $this->answerCallbackQuery($id);
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->sendCartView($chatId, $userName, $messageId);
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

        if (str_starts_with($data, 'cart_rem_')) {
            $itemKey = str_replace('cart_rem_', '', $data);
            $this->removeFromCart($chatId, $itemKey);
            $this->answerCallbackQuery($id, 'ပစ္စည်းအား ခြင်းတောင်းမှ ဖယ်ရှားပြီးပါပြီ');
            $messageId = $callbackQuery['message']['message_id'] ?? null;
            $this->sendCartView($chatId, $userName, $messageId);
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
                $subcategories = Cache::remember("tg_bot_subcats_cat_{$catId}", 300, function () use ($catId) {
                    return SubCategory::where('parent_category_id', $catId)->whereHas('products', function ($q) {
                        $q->where('is_bot_available', true)->where('is_active', true);
                    })->get();
                });

                if ($subcategories->isNotEmpty()) {
                    $text = "📂 *{$category->name}* အတွင်းရှိ အမျိုးအစားခွဲများ-\n"
                          . "━━━━━━━━━━━━━━━━━━━━━\n"
                          . "ကြည့်ရှုလိုသည့် အမျိုးအစားခွဲကို ရွေးချယ်ပေးပါရှင်-";
                    $buttons = [
                        [['text' => "📦 {$category->name} အားလုံးပြပါ", 'callback_data' => "show_cat_{$catId}"]],
                    ];
                    // 1 button per row for clean, thumb-friendly mobile layout
                    foreach ($subcategories as $sub) {
                        $buttons[] = [
                            ['text' => "🔹 {$sub->name}", 'callback_data' => "f_subcat_{$sub->id}"],
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
                $brandCategories = Cache::remember("tg_bot_brand_cats_{$brandId}", 300, function () use ($brandId) {
                    return ProductCategory::whereHas('products', function ($q) use ($brandId) {
                        $q->where('brand_id', $brandId)
                          ->where('is_bot_available', true)
                          ->where(function ($sub) {
                              $sub->where('is_active', true)->orWhere('status', 'Active');
                          });
                    })->get();
                });

                if ($brandCategories->count() > 1) {
                    $text = "🏷️ *{$brand->name}* တံဆိပ်အတွင်းရှိ အမျိုးအစားများ-\n"
                          . "━━━━━━━━━━━━━━━━━━━━━\n"
                          . "ကြည့်ရှုလိုသည့် ကုန်ပစ္စည်း အမျိုးအစားကို ရွေးချယ်ပေးပါရှင်-";
                    $buttons = [
                        [['text' => "📦 {$brand->name} ပစ္စည်းအားလုံးပြပါ", 'callback_data' => "show_brand_{$brandId}"]],
                    ];
                    // 1 button per row for clean, thumb-friendly mobile layout
                    foreach ($brandCategories as $bCat) {
                        $buttons[] = [
                            ['text' => "📂 {$bCat->name}", 'callback_data' => "f_bc_{$brandId}_{$bCat->id}"],
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
            $this->answerCallbackQuery($id, "✅ ခြင်းတောင်းထဲသို့ {$qty} ခု ထည့်ပြီးပါပြီ (စုစုပေါင်း {$cartRes['total_qty']} ခု)");

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

            $cartButtons = [
                [
                    ['text' => (!empty($btnCheckout) ? $btnCheckout : '💳 အော်ဒါတင်မည်') . " ({$totalFormatted} MMK)", 'callback_data' => 'cart_checkout'],
                ],
                [
                    ['text' => (!empty($btnCart) ? $btnCart : '🛒 ခြင်းတောင်း ကြည့်မည်') . " ({$cartRes['total_qty']} ခု)", 'callback_data' => 'cart_view'],
                ],
                [
                    ['text' => !empty($btnContinue) ? $btnContinue : '➕ ပစ္စည်း ဆက်ဝယ်မည်', 'callback_data' => 'nav_all_products'],
                ],
                [
                    ['text' => !empty($btnClear) ? $btnClear : '🗑️ ခြင်းတောင်း ရှင်းမည်', 'callback_data' => 'cart_clear'],
                ],
                [
                    ['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
                ],
            ];

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
            if ($messageId && !empty($imagePath)) {
                // Instant caption edit on the existing photo card
                $res = $this->editMessageCaption($chatId, $messageId, $cartMsg, ['inline_keyboard' => $cartButtons]);
            }

            if (empty($res['ok'])) {
                if ($messageId) {
                    $this->deleteMessage($chatId, $messageId);
                }
                if (!empty($imagePath)) {
                    $res = $this->sendPhoto($chatId, $imagePath, $cartMsg, ['inline_keyboard' => $cartButtons]);
                }
                if (empty($res['ok'])) {
                    $this->sendMessage($chatId, $cartMsg, ['inline_keyboard' => $cartButtons]);
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
            $details[] = "👤 *အကောင့်အမည်:* `{$pm->account_name}`";
        }
        if (!empty($pm->account_number)) {
            $details[] = "🔢 *အကောင့်နံပါတ်:* `{$pm->account_number}` _(နှိပ်၍ ကူးယူပါ)_";
        }
        if (!empty($pm->description)) {
            $details[] = "📝 *မှတ်ချက်:* {$pm->description}";
        }

        $accountDetailsText = !empty($details)
            ? implode("\n", $details)
            : "ကျေးဇူးပြု၍ ဆိုင်သို့ ဆက်သွယ်၍ ငွေလွှဲပေးပါရှင်။";

        $caption = "💳 *{$pm->name} ဖြင့် ငွေလွှဲရန် အချက်အလက်*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "{$accountDetailsText}\n\n"
                 . "💰 *လွှဲရမည့် စုစုပေါင်း ပမာဏ:* `{$totalFormatted} MMK`\n"
                 . "🔖 *အော်ဒါအမှတ်:* `{$orderRef}`\n\n"
                 . "⚠️ _ငွေလွှဲမှတ်ချက် (Note) တွင် အော်ဒါအမှတ် ရိုက်ထည့်ပေးပါရန် မေတ္တာရပ်ခံပါသည်ရှင်။_\n"
                 . "📸 _ငွေလွှဲပြီးပါက **ငွေလွှဲပြေစာ (Slip) ဓာတ်ပုံ** အား ဤနေရာသို့ ပေးပို့ပေးပါရှင်။_ 🙏";

        $actionButtons = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 အခြား ငွေချေနည်းလမ်း ရွေးမည်', 'callback_data' => 'pay_change_method'],
                ],
                [
                    ['text' => '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
                ],
            ],
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
             . "• ပစ္စည်း: {$itemTitle}\n"
             . "• အရေအတွက်: {$qty} ခု\n"
             . "• စုစုပေါင်း ကျသင့်ငွေ: *{$totalFormatted} MMK*\n\n"
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
        $actionButtons = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 အခြား ငွေချေနည်းလမ်း ရွေးမည်', 'callback_data' => 'pay_change_method'],
                ],
                [
                    ['text' => '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
                ],
            ],
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

            $cartButtons = [
                [
                    ['text' => (!empty($btnCheckout) ? $btnCheckout : '💳 အော်ဒါတင်မည်') . " ({$totalFormatted} MMK)", 'callback_data' => 'cart_checkout'],
                ],
                [
                    ['text' => (!empty($btnCart) ? $btnCart : '🛒 ခြင်းတောင်း ကြည့်မည်') . " ({$cartRes['total_qty']} ခု)", 'callback_data' => 'cart_view'],
                ],
                [
                    ['text' => !empty($btnContinue) ? $btnContinue : '➕ ပစ္စည်း ဆက်ဝယ်မည်', 'callback_data' => 'nav_all_products'],
                ],
                [
                    ['text' => !empty($btnClear) ? $btnClear : '🗑️ ခြင်းတောင်း ရှင်းမည်', 'callback_data' => 'cart_clear'],
                ],
                [
                    ['text' => !empty($btnMainMenu) ? $btnMainMenu : '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
                ],
            ];

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
            if (!empty($imagePath)) {
                $res = $this->sendPhoto($chatId, $imagePath, $cartMsg, ['inline_keyboard' => $cartButtons]);
            }
            if (empty($res['ok'])) {
                $this->sendMessage($chatId, $cartMsg, ['inline_keyboard' => $cartButtons]);
            }
            return;
        }

        if ($step === 'waiting_phone') {
            $cleanPhone = preg_replace('/[^0-9+]/', '', $text);
            if (strlen($cleanPhone) < 7) {
                $errTpl = Setting::get('telegram_bot_invalid_phone_msg', '');
                $errMsg = !empty(trim($errTpl))
                    ? $this->replacePlaceholders($errTpl, ['customer_name' => $userName, 'user_name' => $userName])
                    : "⚠️ မှန်ကန်သော ဖုန်းနံပါတ် ရိုက်ထည့်ပေးပါရှင် (ဥပမာ- 09123456789) -";
                $this->sendMessage($chatId, $errMsg);
                return;
            }
            $normalizedPhone = $this->normalizePhoneNumber($text);
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
        $allowPickup = Setting::get('telegram_bot_allow_store_pickup', '1') === '1';
        $pickupFee = (float)Setting::get('telegram_bot_delivery_store_pickup_fee', 0);
        $pickupFeeStr = $pickupFee > 0 ? number_format($pickupFee) . " MMK" : "အခမဲ့ (Free)";

        $activeRegions = DeliveryRegion::where('is_active', true)
            ->withCount('activeTownships')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $totalTownships = DeliveryTownship::where('is_active', true)->count();

        // If Store Pickup is disabled or no pickup allowed, route directly to Region/Township Selection
        if (!$allowPickup) {
            if ($activeRegions->count() === 1) {
                $this->sendTownshipSelection($chatId, $session, $messageIdToEdit, $activeRegions->first()->id);
            } else {
                $this->sendRegionSelection($chatId, $session, $messageIdToEdit);
            }
            return;
        }

        $msg = "🚚 *ပို့ဆောင်မှု နည်းလမ်း ရွေးချယ်ပေးပါရှင်*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n"
             . "လူကြီးမင်း၏ အော်ဒါအား ပို့ဆောင်ပေးရမည့် ပုံစံကို ရွေးချယ်ပေးပါ-\n\n"
             . "၁။ 🚚 *ဒေသနှင့် မြို့နယ်အလိုက် အိမ်အရောက်ပို့* (Home Delivery)\n"
             . "   • သတ်မှတ်ထားသော မြို့နယ် ({$totalTownships}) ခုသို့ သတ်မှတ်ပို့ဆောင်ခနှုန်းထားများအတိုင်း ပို့ဆောင်ပေးပါသည်\n\n"
             . "၂။ 🏢 *ဆိုင်တွင် ကိုယ်တိုင်လာယူမည်* (Store Pickup) - {$pickupFeeStr}\n\n"
             . "ရွေးချယ်လိုသည့် နည်းလမ်းခလုတ်ကို နှိပ်ပေးပါ-";

        $buttons = [
            [
                ['text' => "🚚 ဒေသအလိုက် အိမ်အရောက်ပို့ ({$totalTownships} မြို့နယ်)", 'callback_data' => 'deliv_city'],
            ],
            [
                ['text' => "🏢 ဆိုင်တွင် လာယူမည် ({$pickupFeeStr})", 'callback_data' => 'deliv_pickup'],
            ],
            [
                ['text' => '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel'],
            ],
        ];

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
            $itemsList = collect($session['items'])->map(function ($it) {
                $name = !empty($it['variant_name']) ? "{$it['product_name']} ({$it['variant_name']})" : $it['product_name'];
                return "   • {$name} x {$it['quantity']} " . ($it['unit_name'] ?? 'ခု');
            })->implode("\n");

            $msg = "💳 *ငွေပေးချေမှု နည်းလမ်း ရွေးချယ်ပါ*\n\n"
                 . "📦 *မှာယူသော ပစ္စည်းများ (" . count($session['items']) . " မျိုး):*\n"
                 . "{$itemsList}\n"
                 . "━━━━━━━━━━━━━━━━━━━━━\n"
                 . "💵 ပစ္စည်းကျသင့်ငွေ: *{$itemsTotalFormatted} MMK*\n";
            if ($shippingFee > 0) {
                $msg .= "🚚 ပို့ဆောင်ခ ({$delivMethod}): *+" . number_format($shippingFee) . " MMK*\n";
            } else {
                $msg .= "🚚 ပို့ဆောင်ခ ({$delivMethod}): *အခမဲ့ (0 MMK)*\n";
            }
            $msg .= "💰 *စုစုပေါင်း ကျသင့်ငွေ:* *{$totalFormatted} MMK*\n"
                 . "📞 ဖုန်း: {$phoneDisplay}\n"
                 . "📍 လိပ်စာ: {$addressDisplay}\n\n"
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
                    ? "🚚 ပို့ဆောင်ခ ({$delivMethod}): *+" . number_format($shippingFee) . " MMK*\n"
                    : "🚚 ပို့ဆောင်ခ ({$delivMethod}): *အခမဲ့ (0 MMK)*\n";

                $msg = "💳 *ငွေပေးချေမှု နည်းလမ်း ရွေးချယ်ပါ*\n\n"
                     . "🔖 ပစ္စည်း: {$itemTitle}\n"
                     . "📦 အရေအတွက်: " . ($session['quantity'] ?? 1) . " ခု\n"
                     . "💵 ပစ္စည်းကျသင့်ငွေ: *{$itemsTotalFormatted} MMK*\n"
                     . $shipLine
                     . "💰 *စုစုပေါင်း: *{$totalFormatted} MMK*\n"
                     . "📞 ဖုန်း: {$phoneDisplay}\n"
                     . "📍 လိပ်စာ: {$addressDisplay}\n\n"
                     . "ငွေပေးချေလိုသည့် ပုံစံကို ရွေးချယ်ပါ-";
            }
        }

        $buttons = [];
        $allowUnpaid = Setting::get('telegram_bot_allow_unpaid', '1') === '1';
        $allowPaid = Setting::get('telegram_bot_allow_paid', '1') === '1';

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
                $row = [];
                foreach ($paidMethods as $pm) {
                    $icon = match (strtolower($pm->code ?? '')) {
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
                $buttons[] = [
                    ['text' => !empty($btnKpay) ? $btnKpay : '📱 KBZPay ဖြင့်လွှဲမည်', 'callback_data' => 'pay_kbzpay'],
                ];
                $buttons[] = [
                    ['text' => !empty($btnWave) ? $btnWave : '📱 WavePay ဖြင့်လွှဲမည်', 'callback_data' => 'pay_wavepay'],
                ];
            }
        }

        $buttons[] = [['text' => !empty($btnCancel) ? $btnCancel : '❌ မမှာတော့ပါ (Cancel)', 'callback_data' => 'cancel']];

        $this->editOrSendMessage($chatId, $messageIdToEdit, $msg, [
            'inline_keyboard' => $buttons,
        ]);
    }

    /**
     * Handle Photo message (Payment Slip)
     */
    protected function handlePhotoMessage(int|string $chatId, array $photoArray, string $userName, string $usernameTag): void
    {
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

        $this->processPaymentSlipUpload($chatId, $fileId, $userName, $usernameTag, $fileUniqueId);
    }

    /**
     * Handle Document message (Payment Slip sent as file / uncompressed image)
     */
    protected function handleDocumentMessage(int|string $chatId, array $document, string $userName, string $usernameTag): void
    {
        $mime = $document['mime_type'] ?? '';
        $fileName = strtolower($document['file_name'] ?? '');
        $isImage = str_starts_with($mime, 'image/') || preg_match('/\.(jpg|jpeg|png|webp|bmp)$/i', $fileName);

        if (!$isImage) {
            $invalidDocTpl = Setting::get('telegram_bot_invalid_slip_doc_msg', '');
            $invalidDocMsg = !empty(trim($invalidDocTpl))
                ? $this->replacePlaceholders($invalidDocTpl, ['customer_name' => $userName, 'user_name' => $userName])
                : "⚠️ ကျေးဇူးပြု၍ ငွေလွှဲပြေစာ ဓာတ်ပုံ (Image) သာ ပေးပို့ပေးပါရှင်။ 🙏";
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

        $this->processPaymentSlipUpload($chatId, $fileId, $userName, $usernameTag, $fileUniqueId);
    }

    /**
     * Common processor for Payment Slip Uploads (Photo or Document)
     */
    protected function processPaymentSlipUpload(
        int|string $chatId,
        string $fileId,
        string $userName,
        string $usernameTag,
        ?string $fileUniqueId = null
    ): void {
        $download = $this->downloadTelegramFileWithHash($fileId);
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
    protected function handleLocationMessage(int|string $chatId, float $lat, float $lng, string $userName): void
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
                'notes' => 'Telegram Bot Order by ' . $userName . ' (' . ($usernameTag ?: $chatId) . ')' . (!empty($session['delivery_method']) ? ' | ပို့ဆောင်မှု: ' . $session['delivery_method'] : '') . (!empty($session['delivery_township']) ? ' | မြို့နယ်: ' . $session['delivery_township'] : '') . $slipMeta . $preorderMeta,
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
                $itemsSummaryLines[] = "• {$nameForReceipt} x {$iQty} " . ($product->unit_name ?: 'ခု') . " = " . number_format($iSubtotal) . " MMK";

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
            $statusText = $autoApprove ? "✅ အတည်ပြုပြီးပါပြီ (Approved)" : "⏳ ဆိုင်မှ စစ်ဆေးအတည်ပြုရန် စောင့်ဆိုင်းနေပါသည် (Pending Review)";
            $firstItemName = $itemsSummaryLines[0] ?? 'ကုန်ပစ္စည်းများ';
            $allItemsFormatted = implode("\n", $itemsSummaryLines);

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
                            . "📦 *မှာယူသော ပစ္စည်းများ (" . count($cartItems) . " မျိုး / {$totalQuantity} ခု):*\n"
                            . "{$allItemsFormatted}\n"
                            . "━━━━━━━━━━━━━━━━━━━━━\n"
                            . "💰 *စုစုပေါင်း ကျသင့်ငွေ:* *{$totalFormatted} MMK*\n"
                            . "💳 *ငွေပေးချေမှု:* {$paymentType} ({$paymentStatus})\n"
                            . "📞 *ဖုန်းနံပါတ်:* {$customerPhone}\n"
                            . "📍 *ပို့ဆောင်ရမည့် လိပ်စာ:* {$shippingAddress}\n"
                            . "📌 *အခြေအနေ:* {$statusText}\n\n"
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
            $msg .= "🔖 အော်ဒါအမှတ်: `{$s->reference_no}`\n"
                 . "📅 ရက်စွဲ: {$s->date}\n";

            if ($s->items && $s->items->isNotEmpty()) {
                $itemsStr = $s->items->map(function ($it) {
                    $name = $it->variant_name ? "{$it->product_name} ({$it->variant_name})" : $it->product_name;
                    return "   • {$name} (x{$it->quantity})";
                })->implode("\n");
                $msg .= "{$itemsStr}\n";
            }

            $msg .= "💰 စုစုပေါင်း: *" . number_format($s->grand_total) . " MMK* ({$s->payment_type})\n"
                 . "📌 အခြေအနေ: {$statusBadge}\n"
                 . "─────────────────────\n\n";
        }

        $reorderButtons = [];
        foreach ($sales as $s) {
            $shortRef = substr($s->reference_no, -8);
            $reorderButtons[] = [
                ['text' => "🔄 အော်ဒါ #{$shortRef} ပြန်လည်မှာယူမည် (Reorder)", 'callback_data' => "reorder_{$s->id}"]
            ];
        }
        $reorderButtons[] = [
            ['text' => '🛍️ ကုန်ပစ္စည်းများ ကြည့်မည်', 'callback_data' => 'nav_all_products'],
        ];
        $reorderButtons[] = [
            ['text' => '🔙 ပင်မ မီနူးသို့', 'callback_data' => 'catalog_main'],
        ];

        $this->sendMessage($chatId, $msg, ['inline_keyboard' => $reorderButtons]);
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
            $itemsStr = $sale->items->map(function ($it) {
                $name = $it->variant_name ? "{$it->product_name} ({$it->variant_name})" : $it->product_name;
                return "   • {$name} (x{$it->quantity})";
            })->implode("\n");
        }

        $msg = "📦 *အော်ဒါ အချက်အလက်နှင့် အခြေအနေ စစ်ဆေးခြင်း*\n"
             . "━━━━━━━━━━━━━━━━━━━━━\n\n"
             . "🔖 အော်ဒါအမှတ်: `{$sale->reference_no}`\n"
             . "📅 ရက်စွဲ: {$sale->date}\n";

        if ($itemsStr) {
            $msg .= "{$itemsStr}\n";
        }

        if ($sale->shipping > 0) {
            $msg .= "🚚 ပို့ဆောင်ခ: " . number_format($sale->shipping) . " MMK\n";
        }

        $msg .= "💰 စုစုပေါင်း: *" . number_format($sale->grand_total) . " MMK* ({$sale->payment_type})\n"
             . "📌 အခြေအနေ: {$statusBadge}\n"
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
                          . "   • ပစ္စည်းရောက်မှ ငွေချေစနစ် (Cash on Delivery)\n";
                    if (!empty($pm->description)) {
                        $msg .= "   • {$pm->description}\n";
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
                    $msg .= "   👤 အမည်: `{$pm->account_name}`\n";
                }
                if (!empty($pm->account_number)) {
                    $msg .= "   🔢 နံပါတ်: `{$pm->account_number}` (1-Tap Copy)\n";
                }
                if (!empty($pm->description)) {
                    $msg .= "   📝 ညွှန်ကြားချက်: {$pm->description}\n";
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
                 . "━━━━━━━━━━━━━━━━━━━━━\n\n"
                 . "🏬 ဆိုင်အမည်: {$shopName}\n"
                 . "📍 ဆိုင်လိပ်စာ: {$address}\n"
                 . "☎️ ဖုန်းနံပါတ်: {$phone}\n\n"
                 . "မေးမြန်းလိုသည်များ ရှိပါက ဆိုင်သို့ တိုက်ရိုက် ဆက်သွယ်စုံစမ်းနိုင်ပါသည်ရှင်။ 🙏";
        }
        $this->sendMessage($chatId, $msg, $this->getMainKeyboard($chatId));
    }

    /**
     * Format Dynamic Cart Button Text with Count / Amount Badges
     */
    public function formatCartButtonText(int|string|null $chatId = null, string $baseBtnCart = ''): string
    {
        $baseText = !empty(trim($baseBtnCart)) ? trim($baseBtnCart) : '🛒 ခြင်းတောင်း ကြည့်မည်';
        if (empty($chatId)) {
            return $baseText;
        }

        try {
            $badgeStyle = Setting::get('telegram_bot_cart_badge_style', 'none');
            if ($badgeStyle === 'none' || $badgeStyle === 'disabled') {
                return $baseText;
            }

            $summary = $this->getCartSummary($chatId);
            $totalQty = (float)($summary['total_qty'] ?? 0);
            $itemsCount = (int)($summary['items_count'] ?? 0);
            $grandTotal = (float)($summary['grand_total'] ?? 0);

            $hideWhenEmpty = filter_var(Setting::get('telegram_bot_cart_badge_hide_when_empty', false), FILTER_VALIDATE_BOOLEAN);
            if ($hideWhenEmpty && ($itemsCount <= 0 || $totalQty <= 0)) {
                return $baseText;
            }

            $qtyDisplay = (floor($totalQty) == $totalQty) ? (int)$totalQty : number_format($totalQty, 1);
            $amountFormatted = number_format($grandTotal);

            return match ($badgeStyle) {
                'count_unit' => "{$baseText} ({$qtyDisplay} ခု)",
                'count_price' => "{$baseText} ({$qtyDisplay} ခု • {$amountFormatted} MMK)",
                'types_and_count' => "{$baseText} ({$itemsCount} မျိုး / {$qtyDisplay} ခု)",
                default => "{$baseText} ({$qtyDisplay})",
            };
        } catch (\Throwable $e) {
            return $baseText;
        }
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
        $btnCart = trim((string)Setting::get('telegram_bot_btn_cart', '🛒 ခြင်းတောင်း ကြည့်မည်'));
        $btnOrders = trim((string)Setting::get('telegram_bot_btn_orders', '📦 အော်ဒါများ'));
        $btnPayment = trim((string)Setting::get('telegram_bot_btn_payment', 'ℹ️ ငွေလွှဲရန် အချက်အလက်'));
        $btnContact = trim((string)Setting::get('telegram_bot_btn_contact', '📞 ဆိုင်နှင့် ဆက်သွယ်ရန်'));

        $btnCartText = $this->formatCartButtonText($chatId, $btnCart);

        return [
            'keyboard' => [
                [
                    ['text' => !empty($btnCatalog) ? $btnCatalog : '🛍️ ကုန်ပစ္စည်းများ'],
                    ['text' => $btnCartText],
                ],
                [
                    ['text' => !empty($btnOrders) ? $btnOrders : '📦 အော်ဒါများ'],
                    ['text' => !empty($btnPayment) ? $btnPayment : 'ℹ️ ငွေလွှဲရန် အချက်အလက်'],
                ],
                [
                    ['text' => !empty($btnContact) ? $btnContact : '📞 ဆိုင်နှင့် ဆက်သွယ်ရန်'],
                ],
            ],
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
            $msg = "🔔 *အော်ဒါအသစ် ရောက်ရှိပါသည်!*\n\n"
                 . "🔖 *အော်ဒါအမှတ်:* `{$sale->reference_no}`\n"
                 . "👤 *ဖောက်သည်:* {$sale->customer_name}\n"
                 . "📞 *ဖုန်းနံပါတ်:* " . ($sale->customer_phone ?: 'မပါရှိ') . "\n"
                 . "🏢 *ဂိုဒေါင်:* {$sale->warehouse_name}\n"
                 . ($itemsText ? "{$itemsText}" : "")
                 . "💰 *စုစုပေါင်းငွေ:* {$totalFormatted} MMK\n"
                 . "💳 *ငွေပေးချေမှု:* {$sale->payment_status} ({$sale->payment_type})\n"
                 . "📌 *အခြေအနေ:* {$statusIcon}\n";

            if ($sale->shipping_address) {
                $msg .= "📍 *ပို့ဆောင်ရမည့်လိပ်စာ:* {$sale->shipping_address}\n";
            }

            $msg .= "\n_အောက်ပါ ခလုတ်များမှတဆင့် အတည်ပြု/ပယ်ဖျက်နိုင်ပါသည်-_\n";
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

        $caption = "📸 *ငွေလွှဲပြေစာ (Payment Slip) အသစ် ရောက်ရှိပါသည်!*\n\n"
                 . "🔖 *အော်ဒါအမှတ်:* `{$sale->reference_no}`\n"
                 . "👤 *ဖောက်သည်:* {$sale->customer_name}\n"
                 . "📞 *ဖုန်းနံပါတ်:* " . ($sale->customer_phone ?: 'မပါရှိ') . "\n"
                 . "💰 *ကျသင့်ငွေ:* {$totalFormatted} MMK\n"
                 . "💳 *ငွေပေးချေမှု:* {$sale->payment_status} ({$sale->payment_type})\n"
                 . "📌 *အခြေအနေ:* {$statusIcon}\n\n"
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
        $effectivePrice = ($product->bot_price && (float)$product->bot_price > 0)
            ? (float)$product->bot_price
            : (float)($product->selling_price ?: $product->retail_price);
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
}

