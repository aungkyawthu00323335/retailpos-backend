<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\Setting;
use App\Models\PaymentMethod;
use App\Models\DeliveryTownship;
use App\Models\Sale;
use App\Models\BotChatMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TelegramAiAgentService
{
    /**
     * Get configured Bot Operating Mode ('standard', 'hybrid')
     */
    public function getOperatingMode(): string
    {
        $mode = Setting::get('telegram_bot_mode');
        if (!empty($mode) && in_array($mode, ['standard', 'hybrid'], true)) {
            return $mode;
        }
        // Auto-migrate legacy 'ai_only' to 'hybrid'
        if ($mode === 'ai_only') {
            return 'hybrid';
        }
        // Backward compatibility with legacy setting
        if (Setting::get('telegram_bot_ai_enabled') === '0') {
            return 'standard';
        }
        return 'hybrid';
    }

    /**
     * Check if AI Agent is enabled in settings
     */
    public function isEnabled(): bool
    {
        if ($this->getOperatingMode() === 'standard') {
            return false;
        }
        return Setting::get('telegram_bot_ai_enabled', '1') === '1';
    }

    /**
     * Get configured AI provider ('gemini' or 'openai')
     */
    public function getProvider(): string
    {
        return strtolower(trim((string)Setting::get('telegram_bot_ai_provider', 'gemini')));
    }

    /**
     * Get configured API Key
     */
    public function getApiKey(): string
    {
        return trim((string)Setting::get('telegram_bot_ai_api_key', ''));
    }

    /**
     * Get configured Model Name
     */
    public function getModel(): string
    {
        $provider = $this->getProvider();
        $default = match($provider) {
            'openai' => 'gpt-4o-mini',
            'deepseek' => 'deepseek-chat',
            'anthropic' => 'claude-3-5-haiku-20241022',
            default => 'gemini-2.0-flash',
        };
        $model = trim((string)Setting::get('telegram_bot_ai_model', $default));
        if (empty($model)) {
            return $default;
        }
        return $model;
    }

    /**
     * Check if AI is currently paused for a specific customer (human takeover)
     */
    public function isAiPausedForChat(string|int $chatId): bool
    {
        return Cache::has("tg_ai_paused_{$chatId}");
    }

    /**
     * Pause AI auto-reply for a customer (e.g. staff took over conversation)
     */
    public function pauseAiForChat(string|int $chatId, int $minutes = 60): void
    {
        Cache::put("tg_ai_paused_{$chatId}", true, now()->addMinutes($minutes));
    }

    /**
     * Resume AI auto-reply for a customer
     */
    public function resumeAiForChat(string|int $chatId): void
    {
        Cache::forget("tg_ai_paused_{$chatId}");
    }

    /**
     * 🧠 Main Entry: Process Customer Query with AI + Live POS RAG
     */
    public function handleCustomerQuery(
        string|int $chatId,
        string $userName,
        string $customerQuery,
        ?string $usernameTag = '',
        bool $forceRun = false
    ): ?array {
        if (!$forceRun && !$this->isEnabled()) {
            return null;
        }

        if ($this->isAiPausedForChat($chatId)) {
            Log::info("AI Agent skipped for chat {$chatId}: Human staff takeover is active.");
            return null;
        }

        $apiKey = $this->getApiKey();
        if (empty($apiKey)) {
            Log::warning('Telegram AI Agent is enabled but API Key is empty.');
            return null;
        }

        try {
            // 1. Build Live RAG Context from POS Database
            $context = $this->buildRagContext($customerQuery, $chatId, $userName);

            // 2. Build System Prompt & Conversation History
            $systemPrompt = $this->buildSystemPrompt($context);
            $history = $this->getChatHistoryForLlm($chatId);

            // 3. Call LLM Provider (Gemini, OpenAI, DeepSeek, Anthropic)
            $provider = $this->getProvider();
            $result = null;

            if ($provider === 'openai') {
                $result = $this->callOpenAi($apiKey, $this->getModel(), $systemPrompt, $history, $customerQuery);
            } elseif ($provider === 'deepseek') {
                $result = $this->callDeepSeek($apiKey, $this->getModel(), $systemPrompt, $history, $customerQuery);
            } elseif ($provider === 'anthropic') {
                $result = $this->callAnthropic($apiKey, $this->getModel(), $systemPrompt, $history, $customerQuery);
            } else {
                $result = $this->callGemini($apiKey, $this->getModel(), $systemPrompt, $history, $customerQuery);
            }

            if (!$result || empty($result['reply'])) {
                return null;
            }

            // 4. Check if human takeover is required
            $needsHuman = !empty($result['needs_human']) || $this->detectHumanHandoffIntent($customerQuery);
            if ($needsHuman) {
                $result['needs_human'] = true;
                $this->pauseAiForChat($chatId, 120); // Pause AI for 2 hours on human request
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('TelegramAiAgentService handleCustomerQuery error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * 🔍 Build Live RAG Context (Products, Stock, Warehouse, Policies, Cart & Orders)
     */
    public function buildRagContext(string $query, string|int $chatId, string $userName): array
    {
        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
        $hideOutOfStock = Setting::get('telegram_bot_hide_out_of_stock', '0') === '1';

        // 1. Keyword search and Myanmar retail synonyms expansion
        $burmeseSynonyms = [
            'နခမ်းနီ' => ['နှုတ်ခမ်းနီ', 'lipstick', 'lip', 'tint'],
            'နှုတ်ခမ်းနီ' => ['lipstick', 'lip', 'tint'],
            'မျက်နှာသစ်' => ['facial', 'cleanser', 'foam', 'toner'],
            'မျက်နှာသစ်ဆေး' => ['facial', 'cleanser', 'foam', 'toner'],
            'နေလောင်ကာ' => ['sunscreen', 'sunblock', 'spf'],
            'နေကာ' => ['sunscreen', 'sunblock'],
            'ပေါင်ဒါ' => ['powder', 'compact'],
            'မျက်ခုံး' => ['eyebrow', 'eyeliner'],
            'ခရင်မ်' => ['cream', 'serum', 'lotion', 'retinol'],
            'အင်္ကျီ' => ['shirt', 't-shirt', 'dress'],
            'ဂါဝန်' => ['dress', 'maxi'],
            'ကော်ဖီ' => ['coffee', 'premier'],
            'လက်ဖက်ရည်' => ['teamix', 'tea'],
            'ဖျား' => ['paracetamol', 'fever', 'pain'],
            'အဖျားပျောက်ဆေး' => ['paracetamol', 'fever'],
            'အားဆေး' => ['booster', 'vitamin', 'blackmores'],
            'ပလာစတာ' => ['plaster', 'dettol', 'bandage'],
            'ဓာတ်ဆား' => ['ors', 'rehydration'],
            'ဖုန်း' => ['phone', 'galaxy', 'samsung'],
        ];

        $lowerQuery = mb_strtolower($query);
        $keywords = array_filter(preg_split('/[\s,]+/', $lowerQuery), fn($k) => mb_strlen($k) >= 2);

        foreach ($burmeseSynonyms as $term => $syns) {
            if (str_contains($lowerQuery, $term)) {
                $keywords = array_merge($keywords, $syns);
            }
        }
        $keywords = array_values(array_unique($keywords));

        // 2. Deep keyword search across the entire inventory (even if thousands of products)
        $matchedProducts = collect();
        if (!empty($keywords)) {
            $matchedProducts = Product::with(['category', 'subCategory', 'brand', 'variants' => function($vq) {
                $vq->where('is_active', true);
            }])
            ->where('is_bot_available', true)
            ->where(function ($q) {
                $q->where('is_active', true)->orWhere('status', 'Active');
            })
            ->where(function ($pq) use ($keywords) {
                foreach ($keywords as $kw) {
                    $pq->orWhere('name', 'like', "%{$kw}%")
                       ->orWhere('code', 'like', "%{$kw}%")
                       ->orWhere('sku', 'like', "%{$kw}%")
                       ->orWhereHas('category', fn($cq) => $cq->where('name', 'like', "%{$kw}%"))
                       ->orWhereHas('brand', fn($bq) => $bq->where('name', 'like', "%{$kw}%"));
                }
            })
            ->take(30)
            ->get();
        }

        // 3. Real-time New Arrivals & Popular Catalog (newest products always at top)
        $latestProducts = Product::with(['category', 'subCategory', 'brand', 'variants' => function($vq) {
            $vq->where('is_active', true);
        }])
        ->where('is_bot_available', true)
        ->where(function ($q) {
            $q->where('is_active', true)->orWhere('status', 'Active');
        })
        ->orderBy('id', 'desc')
        ->take(120)
        ->get();

        // Merge: Searched matches first, then newest store arrivals (unique by id)
        $candidateProducts = $matchedProducts->concat($latestProducts)->unique('id')->values();

        $productsData = [];
        foreach ($candidateProducts as $p) {
            $stockQty = (float)StockBalance::where('warehouse_id', $warehouseId)
                ->where('product_id', $p->id)
                ->sum('quantity');

            if ($hideOutOfStock && $stockQty <= 0) {
                continue;
            }

            $price = (float)($p->retail_price ?: $p->selling_price);
            $variantsInfo = [];

            if ($p->has_variants && $p->variants->isNotEmpty()) {
                foreach ($p->variants as $v) {
                    $vStock = (float)StockBalance::where('warehouse_id', $warehouseId)
                        ->where('product_id', $p->id)
                        ->where('product_variant_id', $v->id)
                        ->sum('quantity');

                    $vPrice = (float)($v->selling_price ?: $price);
                    $variantsInfo[] = [
                        'variant_id' => $v->id,
                        'name' => $v->name,
                        'price' => $vPrice,
                        'stock' => $vStock,
                    ];
                }
            }

            $productsData[] = [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code ?: $p->sku,
                'price' => $price,
                'unit' => $p->unit_name ?: 'ခု',
                'category' => $p->category?->name ?? 'အထွေထွေ',
                'brand' => $p->brand?->name ?? '',
                'stock' => $stockQty,
                'is_in_stock' => $stockQty > 0,
                'has_variants' => !empty($variantsInfo),
                'variants' => $variantsInfo,
            ];
        }

                // 2. Store Delivery Rates
        $cityFee = (float)Setting::get('telegram_bot_delivery_city_fee', 2500);
        $expressFee = (float)Setting::get('telegram_bot_delivery_express_fee', 3000);
        $pickupFee = (float)Setting::get('telegram_bot_delivery_store_pickup_fee', 0);

        // 3. Payment Methods
        $paymentMethods = PaymentMethod::where('is_active', true)
            ->where(function ($q) {
                $q->where('is_bot_available', true)->orWhere('is_default', true);
            })
            ->get(['name', 'code', 'account_number', 'account_name', 'description'])
            ->map(fn($pm) => [
                'name' => $pm->name,
                'code' => $pm->code,
                'account_number' => $pm->account_number,
                'account_name' => $pm->account_name,
                'description' => $pm->description,
            ])
            ->toArray();

        // 4. Customer Active Cart & Recent Orders
        $cartSummary = Cache::get("tg_cart_{$chatId}", []);
        $recentOrders = Sale::where('telegram_chat_id', (string)$chatId)
            ->latest('id')
            ->take(3)
            ->get(['id', 'reference_no', 'grand_total', 'status', 'bot_approval_status', 'payment_status', 'created_at'])
            ->toArray();

        // Active Delivery Townships with fees and COD support
        $townships = DeliveryTownship::where('is_active', true)
            ->orderBy('sort_order')
            ->take(120)
            ->get(['id', 'name', 'name_mm', 'code', 'delivery_fee', 'free_delivery_threshold', 'is_cod_available'])
            ->map(fn($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'name_mm' => $t->name_mm ?: $t->name,
                'code' => $t->code,
                'delivery_fee' => (float)$t->delivery_fee,
                'free_delivery_threshold' => (float)($t->free_delivery_threshold ?? 0),
                'cod' => (bool)$t->is_cod_available,
            ])
            ->toArray();

        return [
            'store_name' => Setting::get('company_name', 'အလင်းသစ် စတိုး'),
            'store_categories' => array_values(array_unique(array_filter(array_column($productsData, 'category')))),
            'store_phone' => Setting::get('company_phone', ''),
            'store_address' => Setting::get('company_address', ''),
            'customer_name' => $userName,
            'products' => $productsData,
            'delivery' => [
                'city_fee' => $cityFee,
                'highway_bus_fee' => $expressFee,
                'store_pickup' => $pickupFee,
                'townships' => $townships,
            ],
            'payments' => $paymentMethods,
            'customer_cart' => $cartSummary,
            'customer_recent_orders' => $recentOrders,
            'custom_faq' => Setting::get('telegram_bot_ai_custom_prompt', ''),
        ];
    }

    /**
     * 📝 Build System Prompt with Guardrails, Tone, and Autonomous Actions
     */
    protected function buildSystemPrompt(array $context): string
    {
        $persona = Setting::get('telegram_bot_ai_persona', 'polite_sales');
        $customPersona = trim((string)Setting::get('telegram_bot_ai_custom_persona', ''));
        
        $personaGuideline = match($persona) {
            'professional' => 'You are a professional, concise, and knowledgeable Retail Sales Consultant.',
            'friendly' => 'You are an energetic, warm, and friendly shopping buddy (use casual, upbeat Myanmar polite words).',
            'custom' => !empty($customPersona)
                ? "CUSTOM PERSONA & SPEAKING STYLE INSTRUCTION:\n{$customPersona}"
                : 'You are a warm, polite, and helpful female Myanmar retail sales assistant ("ရှင်/ရှင့်", "ဟုတ်ကဲ့ပါရှင်", "မင်္ဂလာပါရှင်").',
            default => 'You are a warm, extremely polite, and helpful female Myanmar retail sales assistant ("ရှင်/ရှင့်", "ဟုတ်ကဲ့ပါရှင်", "မင်္ဂလာပါရှင်").',
        };

        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<EOT
{$personaGuideline}
You are the official AI Sales & Customer Support Assistant for "{$context['store_name']}" inside Telegram.
Your mission is to help customers find products, answer questions, provide genuine advice, check stock/prices, and assist them with ZERO-CLICK CONVERSATIONAL COMMERCE (ခလုတ်နှိပ်စရာမလိုဘဲ စကားပြောရုံဖြင့် အော်ဒါတင်စနစ်).

# STORE REAL-TIME DATABASE CONTEXT:
```json
{$contextJson}
```

# STRICT RULES & GUARDRAILS:
1. TRUTHFULNESS: Only recommend products, prices, and stock numbers that actually exist in the DATABASE CONTEXT above. NEVER hallucinate or invent products or prices. If a product is out of stock, politely state that it's currently out of stock.
2. CURRENCY: Always format prices in MMK / ကျပ် (e.g. 25,000 MMK or ၂၅,၀၀၀ ကျပ်).
3. LANGUAGE: Answer primarily in natural, grammatically correct, and fluent Myanmar language (Unicode). If the customer writes in English, reply in English.
4. TONE: Warm, courteous, and respectful. Treat every customer like a valued guest.
5. RECOMMENDATIONS: When recommending products, mention their key advantages, variant options (if any), and price. Keep answers concise, clear, and easy to read on mobile screens.
6. HUMAN HANDOVER: If the customer is angry, wants to negotiate bulk/wholesale discount, requests a refund/exchange, or explicitly asks for a human ("လူနဲ့ပြောချင်တယ်", "ဆိုင်ရှင်", "manager"), set "needs_human": true in your JSON and politely inform them that a staff member will assist them shortly.

# ⚡ AUTONOMOUS COMMERCE & ZERO-CLICK ORDERING INSTRUCTIONS:
Customers can shop and order completely through conversation without needing to manually click button sequences.

1. CART ACTIONS ("cart_action") - MANDATORY STRICT ENFORCEMENT:
   - Recognize all purchase intent keywords (e.g., "ဝယ်မယ်", "ယူမယ်", "မှာမယ်", "ဒါယူမယ်", "ဒါလေးယူမယ်", "အဲ့တာလေးယူမယ်နော်", "ခြင်းတောင်းထဲထည့်", "၂ ခု ယူမယ်", "buy", "order", "add to cart").
   - Whenever customer wants to buy an item OR whenever your "reply" mentions adding to the cart (e.g. "ခြင်းတောင်းထဲ ထည့်ပေးလိုက်ပါပြီ"):
     - You MUST match the product_id from the context products list.
     - If the product has variants (has_variants: true), match variant_id by shade/color/size mentioned (e.g., "အသားလတ်" -> No.2 Natural Beige, "အသားဖြူ" -> No.1 Light). If unspecified, pick the first active variant.
     - Extract requested quantity (default 1).
     - Set "cart_action": { "type": "add_to_cart", "items": [{"product_id": <id>, "variant_id": <variant_id or null>, "quantity": <qty>}] }.
     - NEVER set "cart_action": {"type": "none"} when the customer says "ယူမယ်" or "ဝယ်မယ်"!
   - If customer wants to remove an item ("ပြန်ထုတ်ပေးပါ", "မယူတော့ဘူး"):
     - Set "cart_action": { "type": "remove_from_cart", "items": [{"product_id": <id>, "variant_id": <variant_id or null>}] }.
   - If customer wants to clear cart ("ခြင်းတောင်းရှင်းမယ်", "မမှာတော့ဘူး"):
     - Set "cart_action": { "type": "clear_cart", "items": [] }.
   - If customer wants to view cart ("ခြင်းတောင်းပြပါ", "ဘာတွေဝယ်ထားလဲ"):
     - Set "cart_action": { "type": "view_cart", "items": [] }.
   - If no cart action, set "cart_action": { "type": "none", "items": [] }.

2. ORDER FINALIZATION ("order_action") - MANDATORY STRICT ENFORCEMENT:
   - Recognize checkout details and order intent (e.g., phone number, township, address, "အော်ဒါတင်မယ်", "ပို့ပေးပါ", "မှာမယ်", COD, KPay).
   - Phone Extraction: Extract customer phone number (Myanmar format e.g. 09..., ၀၉... or international).
   - Township Extraction: Match with the townships list under context delivery (e.g., "လှိုင်", "ကမာရွတ်", "သိမ်ဇရပ်", "ကျိုက်ထို", "Hlaing", etc.).
   - Address Extraction: Any road/street, ward, or house number mentioned.
   - Payment Method Extraction: "Cash on Delivery" (for အိမ်ရောက်ငွေချေ / COD), "KBZPay", or "WavePay". Default is "Cash on Delivery".
   - Delivery Type: "city" (township delivery), "express" (gate / highway bus), or "store_pickup" (ဆိုင်လာယူ).
   - When Phone number AND Township/Delivery location are present:
     - You MUST set:
       "order_action": {
         "type": "create_order",
         "customer_name": "{$context['customer_name']}",
         "phone": "<extracted normalized phone number e.g. 09777777554>",
         "delivery_type": "city",
         "township_name": "<matched township name>",
         "address": "<extracted address>",
         "payment_method": "Cash on Delivery"
       }
   - If customer wants to order but phone number or township is missing:
     - Set "order_action": { "type": "collect_info" }
     - In "reply", politely ask the customer for the missing information (e.g., "ပို့ဆောင်ရမည့် မြို့နယ်နှင့် ဖုန်းနံပါတ်လေး ပြောပြပေးပါရှင်").
   - If not placing an order, set "order_action": { "type": "none" }.

# REQUIRED JSON OUTPUT FORMAT:
You MUST respond with valid JSON ONLY.
Schema:
{
  "reply": "Your polite, helpful Myanmar response to the customer",
  "recommended_product_ids": [12, 15],
  "needs_human": false,
  "suggested_action": "show_product",
  "cart_action": {
    "type": "none", // "add_to_cart" | "remove_from_cart" | "clear_cart" | "view_cart" | "none"
    "items": [
      {
        "product_id": 12,
        "variant_id": null,
        "quantity": 1
      }
    ]
  },
  "order_action": {
    "type": "none", // "create_order" | "collect_info" | "none"
    "customer_name": "Customer Name",
    "phone": "09777777554",
    "delivery_type": "city", // "city" | "express" | "store_pickup"
    "township_name": "လှိုင်",
    "address": "အမှတ် ၄၀၊ စံပယ်လမ်း",
    "payment_method": "Cash on Delivery" // "Cash on Delivery" | "KBZPay" | "WavePay"
  }
}
EOT;
    }

    /**
     * Load recent chat history from BotChatMessage
     */
    protected function getChatHistoryForLlm(string|int $chatId): array
    {
        $messages = BotChatMessage::where('telegram_chat_id', (string)$chatId)
            ->latest('id')
            ->take(12)
            ->get()
            ->reverse();

        $history = [];
        foreach ($messages as $msg) {
            $role = ($msg->sender_type === 'customer') ? 'user' : 'assistant';
            $content = $msg->message_text;

            // If message was a photo inquiry or referenced products, enrich the text for LLM memory
            $meta = is_array($msg->metadata) ? $msg->metadata : (json_decode($msg->metadata ?? '[]', true) ?: []);
            if ($msg->message_type === 'photo' || !empty($meta['visual_match'])) {
                if (!empty($meta['detected_item']['name'])) {
                    $content .= " [Customer sent photo: " . $meta['detected_item']['name'] . "]";
                }
                if (!empty($meta['recommended_product_ids'])) {
                    $prodNames = Product::whereIn('id', (array)$meta['recommended_product_ids'])->pluck('name')->implode(', ');
                    if (!empty($prodNames)) {
                        $content .= " [Referenced store items: {$prodNames}]";
                    }
                }
            }

            $history[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $history;
    }

    /**
     * 🌐 Call Google Gemini API
     */
    protected function callGemini(
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $history,
        string $userMessage
    ): ?array {
        $cleanModel = ltrim($model ?: 'gemini-2.0-flash', '/');
        if (in_array($cleanModel, ['gemini-3.5-flash-lite', 'gemini-3.7-flash', 'gemini-3.8-flash', 'gemini-flash-latest'])) {
            $cleanModel = 'gemini-2.0-flash';
        }

        $contents = [];
        foreach ($history as $h) {
            $contents[] = [
                'role' => ($h['role'] === 'user') ? 'user' : 'model',
                'parts' => [['text' => $h['content']]],
            ];
        }
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $userMessage]],
        ];

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature' => 0.5,
                'maxOutputTokens' => 2048,
            ],
        ];

        $candidateModels = array_unique([
            $cleanModel,
            'gemini-2.0-flash',
            'gemini-1.5-flash',
            'gemini-2.0-flash-lite',
            'gemini-1.5-pro',
        ]);
        $response = null;

        foreach ($candidateModels as $tryModel) {
            try {
                $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$tryModel}:generateContent?key={$apiKey}";
                $response = Http::timeout(10)
                    ->withoutVerifying()
                    ->post($endpoint, $payload);

                if ($response->successful()) {
                    break;
                }
                Log::warning("Gemini model {$tryModel} failed with status {$response->status()}, trying fallback model...");
                if ($response->status() === 400 || $response->status() === 403 || $response->status() === 429) {
                    // Stop trying other models on quota (429) or invalid auth (400/403)
                    break;
                }
            } catch (\Throwable $e) {
                Log::warning("Gemini model {$tryModel} connection exception: " . $e->getMessage());
            }
        }

        if (!$response || !$response->successful()) {
            Log::error('All Gemini API attempts failed: ' . ($response ? $response->body() : 'No response'));
            return null;
        }

        $resJson = $response->json();
        $rawText = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$rawText) return null;

        return $this->parseLlmJsonOutput($rawText);
    }

    /**
     * 🌐 Call OpenAI API
     */
    protected function callOpenAi(
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $history,
        string $userMessage
    ): ?array {
        $endpoint = 'https://api.openai.com/v1/chat/completions';

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];
        foreach ($history as $h) {
            $messages[] = [
                'role' => $h['role'],
                'content' => $h['content'],
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $payload = [
            'model' => $model ?: 'gpt-4o-mini',
            'messages' => $messages,
            'temperature' => 0.5,
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 2048,
        ];

        $response = Http::timeout(25)
            ->withToken($apiKey)
            ->withoutVerifying()
            ->post($endpoint, $payload);

        if (!$response->successful()) {
            Log::error('OpenAI API error: ' . $response->body());
            return null;
        }

        $resJson = $response->json();
        $rawText = $resJson['choices'][0]['message']['content'] ?? null;
        if (!$rawText) return null;

        return $this->parseLlmJsonOutput($rawText);
    }

    /**
     * 🌐 Call DeepSeek API (OpenAI Compatible)
     */
    protected function callDeepSeek(
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $history,
        string $userMessage
    ): ?array {
        $endpoint = 'https://api.deepseek.com/chat/completions';

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];
        foreach ($history as $h) {
            $messages[] = [
                'role' => $h['role'],
                'content' => $h['content'],
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $payload = [
            'model' => $model ?: 'deepseek-chat',
            'messages' => $messages,
            'temperature' => 0.5,
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 2048,
        ];

        $response = Http::timeout(25)
            ->withToken($apiKey)
            ->withoutVerifying()
            ->post($endpoint, $payload);

        if (!$response->successful()) {
            Log::error('DeepSeek API error: ' . $response->body());
            return null;
        }

        $resJson = $response->json();
        $rawText = $resJson['choices'][0]['message']['content'] ?? null;
        if (!$rawText) return null;

        return $this->parseLlmJsonOutput($rawText);
    }

    /**
     * 🌐 Call Anthropic Claude API
     */
    protected function callAnthropic(
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $history,
        string $userMessage
    ): ?array {
        $endpoint = 'https://api.anthropic.com/v1/messages';

        $messages = [];
        foreach ($history as $h) {
            $messages[] = [
                'role' => ($h['role'] === 'user') ? 'user' : 'assistant',
                'content' => $h['content'],
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $payload = [
            'model' => $model ?: 'claude-3-5-haiku-20241022',
            'system' => $systemPrompt,
            'messages' => $messages,
            'max_tokens' => 2048,
            'temperature' => 0.5,
        ];

        $response = Http::timeout(25)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->withoutVerifying()
            ->post($endpoint, $payload);

        if (!$response->successful()) {
            Log::error('Anthropic API error: ' . $response->body());
            return null;
        }

        $resJson = $response->json();
        $rawText = $resJson['content'][0]['text'] ?? null;
        if (!$rawText) return null;

        return $this->parseLlmJsonOutput($rawText);
    }

    /**
     * Clean and parse JSON response from LLM (Bulletproof parser)
     */
    public function parseLlmJsonOutput(string $rawText): ?array
    {
        $clean = trim($rawText);

        // Strip markdown backticks block
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $clean, $blockMatch)) {
            $clean = trim($blockMatch[1]);
        } elseif (str_starts_with($clean, '```json')) {
            $clean = trim(substr($clean, 7));
        } elseif (str_starts_with($clean, '```')) {
            $clean = trim(substr($clean, 3));
        }
        if (str_ends_with($clean, '```')) {
            $clean = trim(substr($clean, 0, -3));
        }

        // 1. Try standard JSON decode
        $decoded = json_decode($clean, true);

        // 2. If decode failed, attempt regex extraction of the JSON object
        if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $clean, $jsonMatch)) {
            $decoded = json_decode($jsonMatch[0], true);
        }

        if (is_array($decoded) && isset($decoded['reply'])) {
            $recIds = array_map('intval', (array)($decoded['recommended_product_ids'] ?? $decoded['matched_product_ids'] ?? []));
            $recIds = array_values(array_filter($recIds, fn($id) => $id > 0));

            return [
                'reply' => trim((string)$decoded['reply']),
                'recommended_product_ids' => $recIds,
                'matched_product_ids' => $recIds,
                'image_intent' => $decoded['image_intent'] ?? null,
                'confidence' => $decoded['confidence'] ?? null,
                'detected_item' => $decoded['detected_item'] ?? null,
                'needs_human' => (bool)($decoded['needs_human'] ?? false),
                'suggested_action' => $decoded['suggested_action'] ?? 'none',
                'cart_action' => $this->extractCartAction($decoded),
                'order_action' => $this->extractOrderAction($decoded),
                'slip_details' => $decoded['slip_details'] ?? null,
            ];
        }

        // 3. Resilient Fallback: Truncated or malformed JSON where "reply" exists
        // E.g. {"reply": "ဟုတ်ကဲ့ပါ..." without closing quote or brace
        if (preg_match('/"reply"\s*:\s*"((?:[^"\\\\]|\\\\.)*)/s', $clean, $matches)) {
            $extractedReply = $matches[1];
            // Decode unicode escape sequences like \u103b
            $decodedReply = json_decode('"' . addcslashes($extractedReply, "\0..\37") . '"');
            if (is_string($decodedReply) && !empty($decodedReply)) {
                $extractedReply = $decodedReply;
            } else {
                $extractedReply = stripcslashes($extractedReply);
            }

            // Extract recommended or matched IDs if present
            $recIds = [];
            if (preg_match('/"(?:recommended|matched)_product_ids"\s*:\s*\[(.*?)\]/s', $clean, $idMatches)) {
                $rawIds = explode(',', $idMatches[1]);
                foreach ($rawIds as $rid) {
                    $val = (int)trim($rid);
                    if ($val > 0) $recIds[] = $val;
                }
            }

            $needsHuman = (bool)preg_match('/"needs_human"\s*:\s*true/i', $clean);
            $action = 'none';
            if (preg_match('/"suggested_action"\s*:\s*"([^"]+)"/', $clean, $actMatch)) {
                $action = $actMatch[1];
            }

            // Attempt to extract cart_action and order_action from clean text
            $cartAction = ['type' => 'none', 'items' => []];
            if (preg_match('/"cart_action"\s*:\s*(\{(?:[^{}]|(?1))*\})/s', $clean, $caMatch)) {
                $parsedCa = json_decode($caMatch[1], true);
                if (is_array($parsedCa)) {
                    $cartAction = $this->extractCartAction(['cart_action' => $parsedCa]);
                }
            }

            $orderAction = [
                'type' => 'none',
                'customer_name' => null,
                'phone' => null,
                'delivery_type' => 'city',
                'township_name' => null,
                'address' => null,
                'payment_method' => 'Cash on Delivery',
            ];
            if (preg_match('/"order_action"\s*:\s*(\{(?:[^{}]|(?1))*\})/s', $clean, $oaMatch)) {
                $parsedOa = json_decode($oaMatch[1], true);
                if (is_array($parsedOa)) {
                    $orderAction = $this->extractOrderAction(['order_action' => $parsedOa]);
                }
            }

            return [
                'reply' => trim($extractedReply),
                'recommended_product_ids' => $recIds,
                'matched_product_ids' => $recIds,
                'needs_human' => $needsHuman,
                'suggested_action' => $action,
                'cart_action' => $cartAction,
                'order_action' => $orderAction,
            ];
        }

        // 4. Resilient Fallback: If text looks like JSON (starts with { or contains "reply": or "image_intent":)
        // Clean away any JSON syntax markers so raw JSON syntax is NEVER returned to customer!
        if (str_starts_with($clean, '{') || str_contains($clean, '"reply"') || str_contains($clean, '"image_intent"')) {
            $stripped = preg_replace('/"[\w_]+"\s*:\s*/', '', $clean);
            $stripped = trim($stripped, "{}[],\"\t\n\r ");
            if (!empty($stripped)) {
                return [
                    'reply' => $stripped,
                    'recommended_product_ids' => [],
                    'matched_product_ids' => [],
                    'needs_human' => false,
                    'suggested_action' => 'none',
                    'cart_action' => ['type' => 'none', 'items' => []],
                    'order_action' => [
                        'type' => 'none',
                        'customer_name' => null,
                        'phone' => null,
                        'delivery_type' => 'city',
                        'township_name' => null,
                        'address' => null,
                        'payment_method' => 'Cash on Delivery',
                    ],
                ];
            }
        }

        // 5. Fallback: Plain conversational text
        if (!empty($rawText)) {
            return [
                'reply' => trim($rawText),
                'recommended_product_ids' => [],
                'matched_product_ids' => [],
                'needs_human' => false,
                'suggested_action' => 'none',
                'cart_action' => ['type' => 'none', 'items' => []],
                'order_action' => [
                    'type' => 'none',
                    'customer_name' => null,
                    'phone' => null,
                    'delivery_type' => 'city',
                    'township_name' => null,
                    'address' => null,
                    'payment_method' => 'Cash on Delivery',
                ],
            ];
        }

        return null;
    }

    /**
     * Extract structured cart_action from decoded LLM response
     */
    public function extractCartAction(array $decoded): array
    {
        $cartAction = [
            'type' => 'none',
            'items' => [],
        ];

        if (isset($decoded['cart_action']) && is_array($decoded['cart_action'])) {
            $rawType = strtolower(trim((string)($decoded['cart_action']['type'] ?? 'none')));
            if (in_array($rawType, ['add_to_cart', 'remove_from_cart', 'clear_cart', 'view_cart', 'none'], true)) {
                $cartAction['type'] = $rawType;
            }

            if (isset($decoded['cart_action']['items']) && is_array($decoded['cart_action']['items'])) {
                foreach ($decoded['cart_action']['items'] as $it) {
                    if (is_array($it) && !empty($it['product_id'])) {
                        $cartAction['items'][] = [
                            'product_id' => (int)$it['product_id'],
                            'variant_id' => !empty($it['variant_id']) ? (int)$it['variant_id'] : null,
                            'quantity' => max(1, (float)($it['quantity'] ?? 1)),
                        ];
                    }
                }
            }
        }

        return $cartAction;
    }

    /**
     * Extract structured order_action from decoded LLM response
     */
    public function extractOrderAction(array $decoded): array
    {
        $orderAction = [
            'type' => 'none',
            'customer_name' => null,
            'phone' => null,
            'delivery_type' => 'city',
            'township_name' => null,
            'address' => null,
            'payment_method' => 'Cash on Delivery',
        ];

        if (isset($decoded['order_action']) && is_array($decoded['order_action'])) {
            $rawType = strtolower(trim((string)($decoded['order_action']['type'] ?? 'none')));
            if (in_array($rawType, ['create_order', 'collect_info', 'none'], true)) {
                $orderAction['type'] = $rawType;
            }

            $orderAction['customer_name'] = !empty($decoded['order_action']['customer_name']) ? trim((string)$decoded['order_action']['customer_name']) : null;
            $orderAction['phone'] = !empty($decoded['order_action']['phone']) ? trim((string)$decoded['order_action']['phone']) : null;
            
            $rawDeliv = strtolower(trim((string)($decoded['order_action']['delivery_type'] ?? 'city')));
            $orderAction['delivery_type'] = in_array($rawDeliv, ['city', 'express', 'store_pickup'], true) ? $rawDeliv : 'city';
            
            $orderAction['township_name'] = !empty($decoded['order_action']['township_name']) ? trim((string)$decoded['order_action']['township_name']) : null;
            $orderAction['address'] = !empty($decoded['order_action']['address']) ? trim((string)$decoded['order_action']['address']) : null;
            
            $rawPayment = trim((string)($decoded['order_action']['payment_method'] ?? 'Cash on Delivery'));
            $orderAction['payment_method'] = !empty($rawPayment) ? $rawPayment : 'Cash on Delivery';
        }

        return $orderAction;
    }

    /**
     * Keyword-based intent check for human handoff
     */
    public function detectHumanHandoffIntent(string $text): bool
    {
        $lower = mb_strtolower($text);
        $triggers = [
            'လူနဲ့ပြောချင်',
            'လူကိုယ်တိုင်',
            'ဆိုင်ရှင်',
            'ဆိုင်တာဝန်ခံ',
            'manager',
            'human',
            'staff',
            'လိမ်တယ်',
            'တိုင်မယ်',
            'မှားပို့',
            'ငွေပြန်အမ်း',
            'refund',
            'အော်ဒါမရောက်',
            'ဘယ်ရောက်သွားလဲ',
        ];

        foreach ($triggers as $t) {
            if (str_contains($lower, $t)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 📸 Multimodal Image Recognition & Visual Search
     * Handles customer photos of products, prescriptions, or payment slips.
     */
    public function handleCustomerImageQuery(
        string|int $chatId,
        string $userName,
        string $imageBytes,
        string $mimeType,
        string $caption = '',
        ?string $usernameTag = '',
        bool $forceRun = false
    ): ?array {
        if (!$forceRun && !$this->isEnabled()) {
            return null;
        }

        if ($this->isAiPausedForChat($chatId)) {
            Log::info("AI Vision skipped for chat {$chatId}: Human staff takeover is active.");
            return null;
        }

        $apiKey = $this->getApiKey();
        if (empty($apiKey)) {
            Log::warning('Telegram AI Vision skipped: API Key is empty.');
            return null;
        }

        try {
            // 1. Build Vision Catalog Context (Store catalog of products)
            $context = $this->buildVisionCatalogContext($caption, $chatId, $userName);

            // 2. Build Vision System Prompt
            $systemPrompt = $this->buildVisionSystemPrompt($context);

            // 3. Call Multimodal Vision Model (Gemini or OpenAI)
            $provider = $this->getProvider();
            $result = null;

            if ($provider === 'openai') {
                $result = $this->callOpenAiVision($apiKey, $this->getModel(), $systemPrompt, $imageBytes, $mimeType, $caption);
            } else {
                $result = $this->callGeminiVision($apiKey, $this->getModel(), $systemPrompt, $imageBytes, $mimeType, $caption);
            }

            if (!$result) {
                return null;
            }

            // 4. Handle human handoff if needed
            if (!empty($result['needs_human'])) {
                $this->pauseAiForChat($chatId, 120);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('TelegramAiAgentService handleCustomerImageQuery error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * 📦 Build Catalog Context for Vision Recognition
     */
    public function buildVisionCatalogContext(string $query, string|int $chatId, string $userName): array
    {
        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);

        // Fetch all bot-available active products (up to 150 items to fit in context)
        $products = Product::with(['category', 'brand', 'variants' => function($vq) {
            $vq->where('is_active', true);
        }])
        ->where('is_bot_available', true)
        ->where(function ($q) {
            $q->where('is_active', true)->orWhere('status', 'Active');
        })
        ->orderBy('id', 'desc')
        ->take(150)
        ->get();

        $catalog = [];
        foreach ($products as $p) {
            $stockQty = (float)StockBalance::where('warehouse_id', $warehouseId)
                ->where('product_id', $p->id)
                ->sum('quantity');

            $price = (float)($p->retail_price ?: $p->selling_price);
            $variantsInfo = [];

            if ($p->has_variants && $p->variants->isNotEmpty()) {
                foreach ($p->variants as $v) {
                    $vStock = (float)StockBalance::where('warehouse_id', $warehouseId)
                        ->where('product_id', $p->id)
                        ->where('product_variant_id', $v->id)
                        ->sum('quantity');

                    $vPrice = (float)($v->selling_price ?: $price);
                    $variantsInfo[] = [
                        'variant_id' => $v->id,
                        'name' => $v->name,
                        'price' => $vPrice,
                        'stock' => $vStock,
                    ];
                }
            }

            $catalog[] = [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code ?: $p->sku,
                'category' => $p->category?->name ?? '',
                'brand' => $p->brand?->name ?? '',
                'price' => $price,
                'unit' => $p->unit_name ?: 'ခု',
                'stock' => $stockQty,
                'is_in_stock' => $stockQty > 0,
                'variants' => $variantsInfo,
            ];
        }

        return [
            'store_name' => Setting::get('company_name', 'အလင်းသစ် စတိုး'),
            'store_categories' => array_values(array_unique(array_filter(array_column($catalog, 'category')))),
            'customer_name' => $userName,
            'caption' => $query,
            'catalog' => $catalog,
        ];
    }

    /**
     * 📝 Build System Prompt for Visual Search
     */
    protected function buildVisionSystemPrompt(array $context): string
    {
        $persona = Setting::get('telegram_bot_ai_persona', 'polite_sales');
        $customPersona = trim((string)Setting::get('telegram_bot_ai_custom_persona', ''));

        $personaGuideline = match($persona) {
            'professional' => 'You are a professional, accurate Retail Pharmacist & Sales Consultant.',
            'friendly' => 'You are an energetic, warm, and friendly shopping assistant.',
            'custom' => !empty($customPersona) ? $customPersona : 'You are a warm, extremely polite female Myanmar retail assistant ("ရှင်/ရှင့်", "ဟုတ်ကဲ့ပါရှင်").',
            default => 'You are a warm, extremely polite female Myanmar retail assistant ("ရှင်/ရှင့်", "ဟုတ်ကဲ့ပါရှင်").',
        };

        $catalogJson = json_encode($context['catalog'], JSON_UNESCAPED_UNICODE);

        return <<<EOT
{$personaGuideline}
You are the Multimodal Vision AI for "{$context['store_name']}".
A customer ("{$context['customer_name']}") sent an image on Telegram.

# STORE CATALOG (Available Products in POS Database):
```json
{$catalogJson}
```

# YOUR TASK:
1. CLASSIFY THE IMAGE INTENT into one of:
   - "payment_slip": Bank transfer receipt, mobile wallet voucher (KBZPay, WavePay, AYA, CB Pay, etc. with amount, transaction ID, success status).
   - "prescription": Doctor prescription sheet, clinic medical slip, handwritten doctor's note with medicines.
   - "product_inquiry": A photo of a physical product, medicine box, blister strip, syrup bottle, cosmetic packaging, barcode, or screenshot of a product asking "ဒါလေးရှိလား" (Do you have this?).
   - "other": Unrelated image.

2. IF "payment_slip":
   - "image_intent": "payment_slip"
   - "reply": "ငွေလွှဲပြေစာ (Payment Slip) လက်ခံရရှိပါသည်ရှင်။ စစ်ဆေးအတည်ပြုပေးနေပါသည်ရှင် 🙏"
   - "slip_details": { "bank": "KBZPay/Wave/etc", "amount": 10000, "txn_id": "..." }

3. IF "prescription":
   - "image_intent": "prescription"
   - "needs_human": true
   - "reply": "🩺 ဆရာဝန် ဆေးစာရွက် (Prescription) အား လက်ခံရရှိပါသည်ရှင်။ ဆေးစာရင်းနှင့် သောက်သုံးရန် ညွှန်ကြားချက်များအား ဆိုင်တာဝန်ခံ/ဆေးဝါးကျွမ်းကျင်သူမှ လူကိုယ်တိုင် အသေးစိတ် စစ်ဆေးပြီး ချက်ချင်း ဆက်သွယ်ပေးပါမည်ရှင် 🙏"
   - "suggested_action": "contact_human"

4. IF "product_inquiry":
   - Carefully read all visible text, brand names, product titles, dosage (e.g. 500mg, 250mg, 100ml), and form from the packaging in the image.
   - Search the provided STORE CATALOG above for matching items:
     * Compare by Brand name and Product title.
     * CRITICAL SAFETY: Check the dosage/strength! Do not confuse 500mg with 250mg if the catalog has distinct items.
   - IF MATCH FOUND & IN STOCK:
     * "matched_product_ids": [id1, id2] (sorted by relevance, up to 3)
     * "reply": A warm, polite Myanmar response (ရှင်/ရှင့်) stating:
       1. Identify the product recognized (e.g. "ဓာတ်ပုံထဲမှ ပစ္စည်းသည် [အမည်] ဖြစ်ပါသည်ရှင်။")
       2. Current price (e.g. "၁ ဗူးလျှင် [စျေးနှုန်း] ကျပ် ဖြစ်ပါသည်ရှင်။")
       3. Stock availability (e.g. "ဆိုင်တွင် လက်ကျန်ရှိပါသည်ရှင်။")
       4. Invite the customer to order using the buttons below ("အောက်ပါ [🛒 မှာယူမည်] ခလုတ်ကို နှိပ်၍ မှာယူနိုင်ပါသည်ရှင်။")
     * "suggested_action": "order"
   - IF MATCH FOUND BUT OUT OF STOCK:
     * "matched_product_ids": [id1]
     * "reply": Politely inform that this item is currently out of stock. If there is a similar alternative product in the catalog, recommend it.
     * "suggested_action": "alternative"
   - IF NOT IN CATALOG:
     * "matched_product_ids": []
     * "reply": Politely state what product was identified in the image, but clearly explain that our store does not currently carry this item. (e.g. "ဓာတ်ပုံထဲမှ ပစ္စည်းသည် [အမည်] ဖြစ်ပါသည်ရှင်။ လက်ရှိ မမတို့ဆိုင်တွင် အဆိုပါပစ္စည်း ရောင်းချခြင်းမရှိသေးပါရှင်/ပစ္စည်းပြတ်နေပါသည်ရှင် 🙏 အခြား လိုအပ်သော ဆေးဝါး/ပစ္စည်းများ ရှိပါက မေးမြန်းနိုင်ပါသည်ရှင့်။")
     * "suggested_action": "none"

CRITICAL GROUNDING RULES:
- DO NOT hallucinate prices or claim an item is in stock if it does not match an entry in the STORE CATALOG above!
- If the item is not in the STORE CATALOG, "matched_product_ids" MUST be empty: [].
- Never tell the customer to press [🛒 မှာယူမည်] if "matched_product_ids" is empty!

# REQUIRED JSON OUTPUT FORMAT:
You MUST respond with valid JSON ONLY.
Schema:
{
  "image_intent": "product_inquiry", // "product_inquiry" | "payment_slip" | "prescription" | "other"
  "confidence": 0.95,
  "detected_item": {
    "name": "Detected product name",
    "brand": "Detected brand",
    "dosage": "500mg/etc",
    "form": "Tablet/Syrup/etc"
  },
  "matched_product_ids": [12], // Array of matching Product IDs from catalog
  "reply": "Your polite Myanmar reply",
  "suggested_action": "order", // "order" | "view" | "alternative" | "contact_human" | "none"
  "needs_human": false
}
EOT;
    }

    /**
     * 🌐 Call Google Gemini Multimodal Vision API
     */
    protected function callGeminiVision(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $imageBytes,
        string $mimeType,
        string $caption
    ): ?array {
        $cleanModel = ltrim($model ?: 'gemini-3.5-flash-lite', '/');
        if (in_array($cleanModel, ['gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.0-pro', 'gemini-3.7-flash', 'gemini-flash-latest', 'gemini-3.8-flash'])) {
            $cleanModel = 'gemini-3.5-flash-lite';
        }

        $base64Data = base64_encode($imageBytes);

        $contents = [
            [
                'role' => 'user',
                'parts' => [
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType ?: 'image/jpeg',
                            'data' => $base64Data,
                        ],
                    ],
                    [
                        'text' => !empty($caption)
                            ? "Customer sent this photo with text: \"{$caption}\". Please analyze it."
                            : "Customer sent this photo. Please analyze it.",
                    ],
                ],
            ],
        ];

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature' => 0.2,
                'maxOutputTokens' => 2048,
            ],
        ];

        $candidateModels = array_unique([
            $cleanModel,
            'gemini-3.5-flash-lite',
            'gemini-3.5-flash',
            'gemini-flash-lite-latest',
        ]);
        $response = null;

        foreach ($candidateModels as $tryModel) {
            try {
                $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$tryModel}:generateContent?key={$apiKey}";
                $response = Http::timeout(12)
                    ->withoutVerifying()
                    ->post($endpoint, $payload);

                if ($response->successful()) {
                    break;
                }
                Log::warning("Gemini Vision model {$tryModel} failed with status {$response->status()}, trying fallback...");
                if ($response->status() === 400 || $response->status() === 403 || $response->status() === 429) {
                    break;
                }
            } catch (\Throwable $e) {
                Log::warning("Gemini Vision model {$tryModel} connection exception: " . $e->getMessage());
            }
        }

        if (!$response || !$response->successful()) {
            Log::error('All Gemini Vision API attempts failed: ' . ($response ? $response->body() : 'No response'));
            return null;
        }

        $resJson = $response->json();
        $rawText = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$rawText) return null;

        return $this->parseLlmJsonOutput($rawText);
    }

    /**
     * 🌐 Call OpenAI Multimodal Vision API
     */
    protected function callOpenAiVision(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $imageBytes,
        string $mimeType,
        string $caption
    ): ?array {
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        $base64Data = base64_encode($imageBytes);
        $mime = $mimeType ?: 'image/jpeg';

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => !empty($caption)
                            ? "Customer sent this photo with text: \"{$caption}\". Please analyze it."
                            : "Customer sent this photo. Please analyze it.",
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mime};base64,{$base64Data}",
                        ],
                    ],
                ],
            ],
        ];

        $payload = [
            'model' => $model ?: 'gpt-4o-mini',
            'messages' => $messages,
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 1500,
        ];

        $response = Http::timeout(25)
            ->withToken($apiKey)
            ->withoutVerifying()
            ->post($endpoint, $payload);

        if (!$response->successful()) {
            Log::error('OpenAI Vision API error: ' . $response->body());
            return null;
        }

        $resJson = $response->json();
        $rawText = $resJson['choices'][0]['message']['content'] ?? null;
        if (!$rawText) return null;

        return $this->parseLlmJsonOutput($rawText);
    }

    /**
     * 🔌 Test AI API Key and Connectivity
     */
    public function testConnection(string $provider, string $apiKey, string $model): array
    {
        $startTime = microtime(true);
        $provider = strtolower(trim($provider));
        $apiKey = trim($apiKey);
        $model = trim($model);

        if (empty($apiKey)) {
            return ['ok' => false, 'message' => 'API Key is required.'];
        }

        try {
            if ($provider === 'openai') {
                $endpoint = 'https://api.openai.com/v1/chat/completions';
                $res = Http::timeout(10)->withToken($apiKey)->withoutVerifying()->post($endpoint, [
                    'model' => $model ?: 'gpt-4o-mini',
                    'messages' => [['role' => 'user', 'content' => 'Hello, respond with: OK']],
                    'max_tokens' => 10,
                ]);

                $latency = round((microtime(true) - $startTime) * 1000);
                if ($res->successful()) {
                    return [
                        'ok' => true,
                        'provider' => 'OpenAI',
                        'model' => $model ?: 'gpt-4o-mini',
                        'latency_ms' => $latency,
                        'message' => "Connected successfully to OpenAI ({$model}) in {$latency}ms.",
                    ];
                }
                $err = $res->json('error.message') ?? $res->body();
                return ['ok' => false, 'message' => "OpenAI Connection Failed: {$err}"];
            } elseif ($provider === 'deepseek') {
                $endpoint = 'https://api.deepseek.com/chat/completions';
                $useModel = $model ?: 'deepseek-chat';
                $res = Http::timeout(10)->withToken($apiKey)->withoutVerifying()->post($endpoint, [
                    'model' => $useModel,
                    'messages' => [['role' => 'user', 'content' => 'Hello, respond with: OK']],
                    'max_tokens' => 10,
                ]);
                $latency = round((microtime(true) - $startTime) * 1000);
                if ($res->successful()) {
                    return [
                        'ok' => true,
                        'provider' => 'DeepSeek',
                        'model' => $useModel,
                        'latency_ms' => $latency,
                        'message' => "Connected successfully to DeepSeek ({$useModel}) in {$latency}ms.",
                    ];
                }
                $err = $res->json('error.message') ?? $res->body();
                return ['ok' => false, 'message' => "DeepSeek Connection Failed: {$err}"];
            } elseif ($provider === 'anthropic') {
                $endpoint = 'https://api.anthropic.com/v1/messages';
                $useModel = $model ?: 'claude-3-5-haiku-20241022';
                $res = Http::timeout(12)->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])->withoutVerifying()->post($endpoint, [
                    'model' => $useModel,
                    'max_tokens' => 10,
                    'messages' => [['role' => 'user', 'content' => 'Hello, respond with: OK']],
                ]);
                $latency = round((microtime(true) - $startTime) * 1000);
                if ($res->successful()) {
                    return [
                        'ok' => true,
                        'provider' => 'Anthropic Claude',
                        'model' => $useModel,
                        'latency_ms' => $latency,
                        'message' => "Connected successfully to Anthropic Claude ({$useModel}) in {$latency}ms.",
                    ];
                }
                $err = $res->json('error.message') ?? $res->body();
                return ['ok' => false, 'message' => "Anthropic Claude Connection Failed: {$err}"];
            } else {
                // Gemini test
                $cleanModel = ltrim($model ?: 'gemini-2.0-flash', '/');
                $candidateModels = array_unique([$cleanModel, 'gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-2.0-flash-lite', 'gemini-1.5-pro']);
                $res = null;
                $activeModel = $cleanModel;

                foreach ($candidateModels as $tryModel) {
                    $activeModel = $tryModel;
                    $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$tryModel}:generateContent?key={$apiKey}";
                    $res = Http::timeout(12)->withoutVerifying()->post($endpoint, [
                        'contents' => [['parts' => [['text' => 'Hello, respond with: OK']]]],
                        'generationConfig' => ['maxOutputTokens' => 10],
                    ]);

                    if ($res->successful()) {
                        break;
                    }
                    if ($res->status() === 400 || $res->status() === 403) {
                        break;
                    }
                }

                $latency = round((microtime(true) - $startTime) * 1000);
                if ($res && $res->successful()) {
                    return [
                        'ok' => true,
                        'provider' => 'Google Gemini',
                        'model' => $activeModel,
                        'latency_ms' => $latency,
                        'message' => "Connected successfully to Google Gemini ({$activeModel}) in {$latency}ms.",
                    ];
                }
                $err = $res ? ($res->json('error.message') ?? $res->body()) : 'No response';
                return ['ok' => false, 'message' => "Google Gemini Connection Failed: {$err}"];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection test error: ' . $e->getMessage()];
        }
    }
}

