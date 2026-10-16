<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Product;
use App\Models\Outlet;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\BotRestockSubscriber;
use App\Models\BotChatMessage;
use App\Services\TelegramBotService;
use App\Services\TelegramAiAgentService;
use App\Services\ProductUnitConversionService;
use App\Services\FifoInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class BotOrderController extends Controller
{
    protected TelegramBotService $botService;
    protected TelegramAiAgentService $aiAgentService;

    public function __construct(TelegramBotService $botService, TelegramAiAgentService $aiAgentService)
    {
        $this->botService = $botService;
        $this->aiAgentService = $aiAgentService;
    }

    /**
     * 🌐 Public / Telegram Mini App: Get Bot Configuration
     */
    public function getBotConfig()
    {
        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
        $warehouse = Outlet::find($warehouseId) ?: Outlet::where('is_active', true)->first();

        $paymentMethodsRaw = Setting::get('telegram_bot_payment_methods', '["Cash on Delivery", "KBZPay", "WavePay"]');
        $paymentMethods = is_string($paymentMethodsRaw) ? json_decode($paymentMethodsRaw, true) : $paymentMethodsRaw;

        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled' => Setting::get('telegram_bot_enabled', '0') === '1',
                'bot_username' => Setting::get('telegram_bot_username', ''),
                'warehouse_id' => $warehouse?->id ?? 1,
                'warehouse_name' => $warehouse?->name ?? 'Default Warehouse',
                'auto_approve' => Setting::get('telegram_bot_auto_approve', '0') === '1',
                'allow_unpaid' => Setting::get('telegram_bot_allow_unpaid', '1') === '1',
                'allow_paid' => Setting::get('telegram_bot_allow_paid', '1') === '1',
                'allow_partial' => Setting::get('telegram_bot_allow_partial', '0') === '1',
                'min_partial_pct' => (float)Setting::get('telegram_bot_min_partial_pct', '30'),
                'hide_out_of_stock' => Setting::get('telegram_bot_hide_out_of_stock', '0') === '1',
                'payment_methods' => $paymentMethods ?: ['Cash on Delivery', 'KBZPay', 'WavePay'],
                'payment_info' => Setting::get('telegram_bot_payment_info', ''),
                'kpay_info' => Setting::get('telegram_bot_kpay_info', ''),
                'wave_info' => Setting::get('telegram_bot_wave_info', ''),
                'ayapay_info' => Setting::get('telegram_bot_ayapay_info', ''),
                'cbpay_info' => Setting::get('telegram_bot_cbpay_info', ''),
                'other_bank_info' => Setting::get('telegram_bot_other_bank_info', ''),
                'welcome_msg' => Setting::get('telegram_bot_welcome_msg', 'Welcome to our store!'),
                'order_success_msg' => Setting::get('telegram_bot_order_success_msg', ''),
                'order_approved_msg' => Setting::get('telegram_bot_order_approved_msg', ''),
                'order_rejected_msg' => Setting::get('telegram_bot_order_rejected_msg', ''),
            ],
        ]);
    }

    /**
     * 🌐 Public / Telegram Mini App: Get Available Catalog
     */
    public function getProducts(Request $request)
    {
        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);

        $query = Product::query()
            ->where('is_bot_available', true)
            ->where(function ($q) {
                $q->where('is_active', true)
                  ->orWhere('status', 'Active');
            })
            ->with([
                'primaryImage',
                'category:id,name',
                'brand:id,name',
                'variants' => function ($q) {
                    $q->where('is_active', true);
                },
                'variants.stockBalances' => function ($q) use ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                },
                'stockBalances' => function ($q) use ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                },
            ]);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('search')) {
            $s = trim($request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('code', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%");
            });
        }

        if (Setting::get('telegram_bot_hide_out_of_stock', '0') === '1') {
            $query->whereHas('stockBalances', function ($sq) use ($warehouseId) {
                $sq->where('warehouse_id', $warehouseId)->where('quantity', '>', 0);
            });
        }

        $products = $query->orderBy('name')->get();

        $items = $products->map(function ($p) use ($warehouseId) {
            $availableQty = (float)$p->stockBalances->where('warehouse_id', $warehouseId)->sum('quantity');

            // Effective price (use bot_price if set > 0, else selling_price/retail_price)
            $effectivePrice = ($p->bot_price && (float)$p->bot_price > 0)
                ? (float)$p->bot_price
                : (float)($p->selling_price ?: $p->retail_price);

            $img = $p->image_url;
            if ($p->relationLoaded('primaryImage') && $p->primaryImage) {
                $img = $p->primaryImage->url;
            }

            return [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code ?: $p->sku,
                'category_name' => $p->category_name ?: ($p->category?->name ?? 'General'),
                'brand_name' => $p->brand_name ?: ($p->brand?->name ?? ''),
                'price' => $effectivePrice,
                'original_price' => (float)($p->selling_price ?: $p->retail_price),
                'available_stock' => $availableQty,
                'in_stock' => $availableQty > 0,
                'unit_name' => $p->unit_name ?: 'Piece',
                'image_url' => $img,
                'description' => $p->description ?: $p->note,
                'has_variants' => (bool)$p->has_variants,
                'variants' => $p->variants->map(function ($v) use ($warehouseId) {
                    $vQty = (float)$v->stockBalances->where('warehouse_id', $warehouseId)->sum('quantity');
                    return [
                        'id' => $v->id,
                        'name' => $v->name,
                        'sku' => $v->sku,
                        'price' => (float)($v->selling_price ?: $v->retail_price),
                        'available_stock' => $vQty,
                        'in_stock' => $vQty > 0,
                    ];
                }),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $items,
        ]);
    }

    /**
     * 🌐 Public / Telegram Mini App: Submit Order
     */
    public function submitOrder(Request $request)
    {
        $enabled = Setting::get('telegram_bot_enabled', '0') === '1';
        if (!$enabled) {
            return response()->json([
                'status' => 'error',
                'message' => 'Telegram Bot ordering is currently disabled by store administration.',
            ], 403);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|gt:0',
            'customer_name' => 'required|string|max:150',
            'customer_phone' => 'nullable|string|max:50',
            'shipping_address' => 'nullable|string|max:500',
            'telegram_chat_id' => 'nullable|string|max:100',
            'telegram_username' => 'nullable|string|max:100',
            'payment_type' => 'required|string|max:100',
            'payment_mode' => 'nullable|string|in:Paid,Unpaid,Partial',
            'paid_amount' => 'nullable|numeric|min:0',
            'payment_slip_url' => 'nullable|string',
            'notes' => 'nullable|string|max:500',
        ]);

        $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
        $warehouse = Outlet::find($warehouseId) ?: Outlet::first();
        $warehouseName = $warehouse ? $warehouse->name : 'Main Warehouse';

        // Payment Mode Rules
        $allowUnpaid = Setting::get('telegram_bot_allow_unpaid', '1') === '1';
        $allowPaid = Setting::get('telegram_bot_allow_paid', '1') === '1';
        $allowPartial = Setting::get('telegram_bot_allow_partial', '0') === '1';
        $minPartialPct = (float)Setting::get('telegram_bot_min_partial_pct', '30');

        $isCod = in_array(strtolower($validated['payment_type']), ['cash on delivery', 'cod', 'unpaid']);
        $reqPaymentMode = $validated['payment_mode'] ?? ($isCod ? 'Unpaid' : 'Paid');

        if ($reqPaymentMode === 'Unpaid' && !$allowUnpaid) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cash on Delivery (Unpaid) orders are not permitted at this time.',
            ], 422);
        }

        if ($reqPaymentMode === 'Partial' && !$allowPartial) {
            return response()->json([
                'status' => 'error',
                'message' => 'Partial payment orders are not permitted at this time.',
            ], 422);
        }

        // Auto approve setting
        $autoApprove = Setting::get('telegram_bot_auto_approve', '0') === '1';

        DB::beginTransaction();
        try {
            // Find or create default walk-in / bot customer
            $customerName = trim($validated['customer_name']);
            $customerPhone = trim($validated['customer_phone'] ?? '');
            $customer = null;

            if (!empty($customerPhone)) {
                $customer = Customer::where('phone', $customerPhone)->first();
            }
            if (!$customer && !empty($validated['telegram_chat_id'])) {
                $customer = Customer::where('telegram_chat_id', $validated['telegram_chat_id'])->first();
            }
            if (!$customer) {
                $customer = Customer::where('name', 'Walk-in Customer')->first() ?: Customer::first();
            }

            // Generate reference number
            $refNo = 'BOT-' . date('Ymd') . '-' . rand(1000, 9999);
            while (Sale::where('reference_no', $refNo)->exists()) {
                $refNo = 'BOT-' . date('Ymd') . '-' . rand(1000, 9999);
            }

            // Pre-calculate items and subtotal
            $subtotal = 0.0;
            $preparedItems = [];
            $unitService = app(ProductUnitConversionService::class);

            foreach ($validated['items'] as $itemData) {
                $product = Product::with(['variants', 'unitLinks'])->findOrFail($itemData['product_id']);
                $variantId = !empty($itemData['product_variant_id']) ? (int)$itemData['product_variant_id'] : null;

                $basePrice = (float)($product->retail_price ?: $product->selling_price);
                $unitPrice = $basePrice;

                $variantName = '';
                if ($variantId && $product->variants) {
                    $variant = $product->variants->firstWhere('id', $variantId);
                    if ($variant) {
                        $variantName = $variant->name;
                        if ((float)$variant->selling_price > 0) {
                            $unitPrice = (float)$variant->selling_price;
                        }
                    }
                }

                $qty = (float)$itemData['quantity'];
                $lineTotal = $unitPrice * $qty;
                $subtotal += $lineTotal;

                $linkedUnit = $unitService->resolve(
                    $product,
                    'sale',
                    null,
                    $product->unit_name ?: 'Piece',
                    $variantId
                );

                $preparedItems[] = [
                    'product' => $product,
                    'variant_id' => $variantId,
                    'variant_name' => $variantName,
                    'unit_id' => $linkedUnit['unit_id'],
                    'unit_name' => $linkedUnit['unit_name'],
                    'conversion_rate' => (float)$linkedUnit['conversion_rate'],
                    'unit_price' => $unitPrice,
                    'quantity' => $qty,
                    'line_total' => $lineTotal,
                ];
            }

            $grandTotal = $subtotal;

            // Calculate paid vs due
            if ($reqPaymentMode === 'Unpaid') {
                $paidAmount = 0.0;
                $dueAmount = $grandTotal;
                $paymentStatus = 'Unpaid';
            } elseif ($reqPaymentMode === 'Partial') {
                $minRequired = round($grandTotal * ($minPartialPct / 100), 2);
                $paidAmount = (float)($validated['paid_amount'] ?? $minRequired);
                if ($paidAmount < $minRequired) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => "Partial deposit must be at least {$minPartialPct}% ({$minRequired} MMK).",
                    ], 422);
                }
                $dueAmount = max(0, $grandTotal - $paidAmount);
                $paymentStatus = $dueAmount <= 0 ? 'Paid' : 'Partial';
            } else {
                $paidAmount = $grandTotal;
                $dueAmount = 0.0;
                $paymentStatus = 'Paid';
            }

            $saleStatus = $autoApprove ? 'Completed' : 'Pending';
            $botApprovalStatus = $autoApprove ? 'approved' : 'pending';

            $sale = Sale::create([
                'reference_no' => $refNo,
                'customer_id' => $customer?->id,
                'customer_name' => $customerName,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouseName,
                'date' => now()->format('Y-m-d H:i:s'),
                'status' => $saleStatus,
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'discount' => 0.0,
                'shipping' => 0.0,
                'subtotal' => $subtotal,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'change_amount' => 0.0,
                'payment_status' => $paymentStatus,
                'payment_type' => $validated['payment_type'],
                'notes' => $validated['notes'] ?? 'Order placed via Telegram Bot',
                'pos_flag' => false,
                'order_source' => 'telegram_bot',
                'telegram_chat_id' => $validated['telegram_chat_id'] ?? null,
                'telegram_username' => $validated['telegram_username'] ?? null,
                'telegram_order_id' => $refNo,
                'bot_approval_status' => $botApprovalStatus,
                'payment_slip_url' => $validated['payment_slip_url'] ?? null,
                'shipping_address' => $validated['shipping_address'] ?? null,
                'customer_phone' => $customerPhone,
                'created_by' => 'Telegram Bot',
                'user_name' => 'Telegram Bot',
                'user_role' => 'Bot Channel',
            ]);

            // Save items & deplete stock if auto-approve
            $fifoService = app(FifoInventoryService::class);
            $totalSaleCogs = 0.0;

            foreach ($preparedItems as $prep) {
                $baseQty = $unitService->baseQuantity($prep['quantity'], $prep['conversion_rate']);
                $baseUnitPrice = $unitService->basePrice($prep['unit_price'], $prep['conversion_rate']);

                $saleItem = SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $prep['product']->id,
                    'product_variant_id' => $prep['variant_id'],
                    'unit_id' => $prep['unit_id'],
                    'unit_name' => $prep['unit_name'],
                    'conversion_rate' => $prep['conversion_rate'],
                    'product_code' => $prep['product']->code ?: $prep['product']->sku,
                    'product_name' => $prep['product']->name,
                    'variant_name' => $prep['variant_name'],
                    'unit_price' => $prep['unit_price'],
                    'base_unit_price' => $baseUnitPrice,
                    'quantity' => $prep['quantity'],
                    'base_quantity' => $baseQty,
                    'tax' => 0.0,
                    'discount' => 0.0,
                    'subtotal' => $prep['line_total'],
                ]);

                if ($autoApprove) {
                    $unitService->applyStock(
                        $prep['product'],
                        $warehouseId,
                        $prep['variant_id'],
                        $baseQty,
                        'out',
                        'sale',
                        'sale',
                        (string)$sale->id,
                        'Telegram Bot Order auto-approved'
                    );

                    $fallbackCost = (float)($prep['product']->cost_price ?: ($prep['product']->purchase_price ?: 0));
                    $depletion = $fifoService->depleteFifo(
                        warehouseId: (int)$warehouseId,
                        productId: (int)$prep['product']->id,
                        variantId: $prep['variant_id'],
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

            // Record payment entry if paid
            if ($paidAmount > 0) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'reference_no' => 'PAY-' . date('Ymd') . '-' . rand(1000, 9999),
                    'payment_date' => $sale->date,
                    'payment_type' => $sale->payment_type,
                    'amount' => $paidAmount,
                    'received_amount' => $paidAmount,
                    'change_amount' => 0.0,
                    'notes' => 'Telegram Bot payment: ' . $sale->payment_type,
                    'created_by' => 'Telegram Bot',
                    'user_name' => 'Telegram Bot',
                    'user_role' => 'Bot Channel',
                ]);
            }

            DB::commit();

            // Fire notifications in background
            try {
                $this->botService->notifyAdminNewOrder($sale);
                if ($autoApprove) {
                    $this->botService->notifyCustomerOrderApproved($sale);
                } else if (!empty($sale->telegram_chat_id)) {
                    $this->botService->sendMessage(
                        $sale->telegram_chat_id,
                        "📥 *သင့်အော်ဒါအား လက်ခံရရှိထားပါသည်ရှင်*\n\n"
                        . "🔖 *အော်ဒါအမှတ်:* `{$sale->reference_no}`\n"
                        . "💰 *စုစုပေါင်းငွေ:* " . number_format($sale->grand_total, 2) . " MMK\n\n"
                        . "ဆိုင်ဝန်ထမ်းမှ စလစ်နှင့် အချက်အလက်များအား စစ်ဆေးအတည်ပြုပြီးပါက အကြောင်းကြားပေးပါမည်ရှင်။ 🙏"
                    );
                }
            } catch (\Throwable $ne) {
                Log::warning('Telegram notification error: ' . $ne->getMessage());
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Order placed successfully.',
                'data' => [
                    'id' => $sale->id,
                    'reference_no' => $sale->reference_no,
                    'status' => $sale->status,
                    'bot_approval_status' => $sale->bot_approval_status,
                    'grand_total' => $sale->grand_total,
                    'paid_amount' => $sale->paid_amount,
                    'due_amount' => $sale->due_amount,
                    'payment_status' => $sale->payment_status,
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Bot submitOrder failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to place bot order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 🌐 Public / Telegram: Upload Slip Image
     */
    public function uploadSlip(Request $request)
    {
        $validated = $request->validate([
            'image' => 'required|file|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $file = $request->file('image');
        $path = $file->store('bot_slips', 'public');
        $baseHost = $request->getSchemeAndHttpHost() ?: config('app.url');
        $url = rtrim($baseHost, '/') . '/storage/' . ltrim($path, '/');

        return response()->json([
            'status' => 'success',
            'data' => [
                'path' => $path,
                'url' => $url,
            ],
        ]);
    }

    /**
     * 🤖 Telegram Webhook Endpoint
     */
    public function handleWebhook(Request $request)
    {
        $expectedSecret = Setting::get('telegram_bot_webhook_secret', '');
        if (!empty($expectedSecret)) {
            $secretHeader = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if ($secretHeader !== $expectedSecret) {
                Log::warning('Telegram Webhook unauthorized: Invalid Secret Token');
                return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
            }
        }

        $update = $request->all();
        $this->botService->handleIncomingUpdate($update);

        return response()->json(['status' => 'ok']);
    }

    /**
     * 👨‍💼 POS Staff API: List Bot Orders
     */
    public function listBotOrders(Request $request)
    {
        $query = Sale::query()
            ->where('order_source', 'telegram_bot')
            ->with([
                'items:id,sale_id,product_id,product_code,product_name,variant_name,unit_name,unit_price,quantity,subtotal',
                'payments:id,sale_id,reference_no,payment_date,payment_type,amount,received_amount,change_amount,notes,user_name,user_role,created_by',
                'customer:id,name,phone',
            ]);

        // Status Filter: pending, approved, rejected, all
        if ($request->filled('approval_status') && $request->input('approval_status') !== 'all') {
            $query->where('bot_approval_status', $request->input('approval_status'));
        }

        if ($request->filled('payment_status') && $request->input('payment_status') !== 'all') {
            $query->where('payment_status', $request->input('payment_status'));
        }

        if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'all') {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('date', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('date', '<=', $request->input('to_date'));
        }

        if ($request->filled('search')) {
            $s = trim($request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('reference_no', 'like', "%{$s}%")
                  ->orWhere('customer_name', 'like', "%{$s}%")
                  ->orWhere('customer_phone', 'like', "%{$s}%")
                  ->orWhere('telegram_username', 'like', "%{$s}%");
            });
        }

        // Summary KPI Counts & Senior Accounting Financial Totals
        // GAAP / IFRS 15 Compliant: Separates Confirmed Revenue (Approved) from Pending Order Pipeline and Accounts Receivable (Approved Due)
        $kpiQuery = DB::table('sales')->where('order_source', 'telegram_bot');

        if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'all') {
            $kpiQuery->where('warehouse_id', $request->input('warehouse_id'));
        }

        if ($request->filled('from_date')) {
            $kpiQuery->whereDate('date', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $kpiQuery->whereDate('date', '<=', $request->input('to_date'));
        }

        $kpis = $kpiQuery->selectRaw("
                COUNT(*) as total_orders,
                COUNT(CASE WHEN bot_approval_status = 'pending' THEN 1 END) as pending_orders,
                COUNT(CASE WHEN bot_approval_status = 'approved' THEN 1 END) as approved_orders,
                COUNT(CASE WHEN bot_approval_status = 'rejected' THEN 1 END) as rejected_orders,
                COALESCE(SUM(CASE WHEN bot_approval_status != 'rejected' THEN grand_total ELSE 0 END), 0) as total_sales_amount,
                COALESCE(SUM(CASE WHEN bot_approval_status = 'approved' THEN grand_total ELSE 0 END), 0) as approved_sales_amount,
                COALESCE(SUM(CASE WHEN bot_approval_status = 'pending' THEN grand_total ELSE 0 END), 0) as pending_sales_amount,
                COALESCE(SUM(CASE WHEN bot_approval_status != 'rejected' THEN paid_amount ELSE 0 END), 0) as total_paid_amount,
                COALESCE(SUM(CASE WHEN bot_approval_status = 'approved' THEN due_amount ELSE 0 END), 0) as approved_due_amount,
                COALESCE(SUM(CASE WHEN bot_approval_status != 'rejected' THEN due_amount ELSE 0 END), 0) as total_due_amount
            ")->first();

        $perPage = min(5000, max(1, (int)$request->input('per_page', 50)));
        $orders = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $orders->items(),
            'kpis' => [
                'total_orders' => (int)($kpis->total_orders ?? 0),
                'pending_orders' => (int)($kpis->pending_orders ?? 0),
                'approved_orders' => (int)($kpis->approved_orders ?? 0),
                'rejected_orders' => (int)($kpis->rejected_orders ?? 0),
                'total_sales_amount' => (float)($kpis->total_sales_amount ?? 0),
                'approved_sales_amount' => (float)($kpis->approved_sales_amount ?? 0),
                'pending_sales_amount' => (float)($kpis->pending_sales_amount ?? 0),
                'total_paid_amount' => (float)($kpis->total_paid_amount ?? 0),
                'approved_due_amount' => (float)($kpis->approved_due_amount ?? 0),
                'total_due_amount' => (float)($kpis->total_due_amount ?? 0),
            ],
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * 👨‍💼 POS Staff API: Get Single Bot Order Details
     */
    public function getBotOrder($id)
    {
        $sale = Sale::with([
            'items:id,sale_id,product_id,product_code,product_name,variant_name,unit_name,unit_price,quantity,subtotal',
            'payments:id,sale_id,reference_no,payment_date,payment_type,amount,received_amount,change_amount,notes,user_name,user_role,created_by',
            'customer:id,name,phone',
        ])
        ->where('order_source', 'telegram_bot')
        ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $sale,
        ]);
    }

    /**
     * 👨‍💼 POS Staff API: Approve Bot Order (Depletes Stock & Notifies Customer)
     */
    public function approveOrder(Request $request, $id)
    {
        $sale = Sale::with(['items', 'customer'])->where('order_source', 'telegram_bot')->findOrFail($id);

        if ($sale->bot_approval_status === 'approved') {
            return response()->json([
                'status' => 'error',
                'message' => 'This bot order is already approved.',
            ], 422);
        }

        if ($sale->bot_approval_status === 'rejected') {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot approve an order that was previously rejected.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $unitService = app(ProductUnitConversionService::class);
            $fifoService = app(FifoInventoryService::class);
            $totalSaleCogs = 0.0;

            // Deplete stock from warehouse and compute FIFO COGS
            foreach ($sale->items as $item) {
                $product = Product::find($item->product_id);
                if (!$product) continue;

                $baseQty = (float)($item->base_quantity ?: $unitService->baseQuantity($item->quantity, $item->conversion_rate ?: 1));

                $unitService->applyStock(
                    $product,
                    $sale->warehouse_id,
                    $item->product_variant_id,
                    $baseQty,
                    'out',
                    'sale',
                    'sale',
                    (string)$sale->id,
                    'Telegram Bot Order approved by staff'
                );

                $fallbackCost = (float)($product->cost_price ?: ($product->purchase_price ?: 0));
                $depletion = $fifoService->depleteFifo(
                    warehouseId: (int)$sale->warehouse_id,
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

            $user = auth()->user();
            $approvedBy = $user ? $user->name : 'Super Admin';

            $sale->update([
                'status' => 'Completed',
                'bot_approval_status' => 'approved',
                'bot_approved_by' => $approvedBy,
                'bot_approved_at' => now(),
                'total_cogs' => round($totalSaleCogs, 2),
                'notes' => trim($sale->notes . " | Approved by {$approvedBy} on " . now()->format('Y-m-d H:i')),
            ]);

            DB::commit();

            $sale->refresh();
            $sale->load(['items', 'customer', 'outlet']);

            // Notify Customer via Telegram with 80mm POS Voucher Image
            try {
                $this->botService->notifyCustomerOrderApproved($sale);
            } catch (\Throwable $ne) {
                Log::warning('Telegram approve notify error: ' . $ne->getMessage());
            }

            return response()->json([
                'status' => 'success',
                'message' => "Order #{$sale->reference_no} approved and stock depleted successfully.",
                'data' => $sale->fresh(['items']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Order approval failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 👨‍💼 POS Staff API: Reject Bot Order (Notifies Customer with Reason)
     */
    public function rejectOrder(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $sale = Sale::where('order_source', 'telegram_bot')->findOrFail($id);

        if ($sale->bot_approval_status === 'approved') {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot reject an order that has already been approved and fulfilled.',
            ], 422);
        }

        $reason = trim($validated['reason']);
        $user = auth()->user();
        $rejectedBy = $user ? $user->name : 'Super Admin';

        // 🛡️ Accounting Ledger Integrity:
        // Void/delete any preliminary unverified payment records tied to this rejected order
        SalePayment::where('sale_id', $sale->id)->delete();

        $sale->update([
            'status' => 'Cancelled',
            'bot_approval_status' => 'rejected',
            'bot_approved_by' => null,
            'bot_rejected_by' => $rejectedBy,
            'bot_rejected_at' => now(),
            'bot_rejection_reason' => $reason,
            'paid_amount' => 0.0,
            'due_amount' => 0.0, // 🛡️ Cancelled/rejected order has ZERO due amount
            'payment_status' => 'Unpaid',
            'notes' => trim($sale->notes . " | Rejected by {$rejectedBy} on " . now()->format('Y-m-d H:i') . ": {$reason}"),
        ]);

        // Notify Customer via Telegram
        try {
            $this->botService->notifyCustomerOrderRejected($sale, $reason);
        } catch (\Throwable $ne) {
            Log::warning('Telegram reject notify error: ' . $ne->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => "Order #{$sale->reference_no} rejected.",
            'data' => $sale,
        ]);
    }

    /**
     * 🧾 Get or Generate 80mm POS Voucher Image for a Bot Order
     */
    public function getVoucherImage($id)
    {
        $sale = Sale::with(['items', 'customer', 'outlet'])->where('order_source', 'telegram_bot')->findOrFail($id);
        $voucherService = app(\App\Services\BotVoucherImageService::class);
        $voucher = $voucherService->generateVoucherImage($sale);

        return response()->json([
            'status' => 'success',
            'data' => [
                'reference_no' => $sale->reference_no,
                'voucher_url' => $voucher['url'],
                'voucher_path' => $voucher['path'],
            ],
        ]);
    }

    /**
     * 👨‍💼 POS Staff API: Get Bot Settings
     */
    public function getBotSettings()
    {
        $loader = function () {
            $warehouseId = (int)Setting::get('telegram_bot_warehouse_id', 1);
            $warehouse = Outlet::find($warehouseId);

            $paymentMethodsRaw = Setting::get('telegram_bot_payment_methods', '["Cash on Delivery", "KBZPay", "WavePay"]');
            $paymentMethods = is_string($paymentMethodsRaw) ? json_decode($paymentMethodsRaw, true) : $paymentMethodsRaw;

            return [
                'telegram_bot_enabled' => Setting::get('telegram_bot_enabled', '0') === '1',
                'telegram_bot_token' => Setting::get('telegram_bot_token', ''),
                'telegram_bot_username' => Setting::get('telegram_bot_username', ''),
                'telegram_bot_warehouse_id' => $warehouse?->id ?? 1,
                'telegram_bot_warehouse_name' => $warehouse?->name ?? 'Default Warehouse',
                'telegram_bot_auto_approve' => Setting::get('telegram_bot_auto_approve', '0') === '1',
                'telegram_bot_allow_unpaid' => Setting::get('telegram_bot_allow_unpaid', '1') === '1',
                'telegram_bot_allow_paid' => Setting::get('telegram_bot_allow_paid', '1') === '1',
                'telegram_bot_allow_partial' => Setting::get('telegram_bot_allow_partial', '0') === '1',
                'telegram_bot_reject_duplicate_slip' => Setting::get('telegram_bot_reject_duplicate_slip', '1') === '1',
                'telegram_bot_min_partial_pct' => (float)Setting::get('telegram_bot_min_partial_pct', '30'),
                'telegram_bot_allow_out_of_stock_orders' => Setting::get('telegram_bot_allow_out_of_stock_orders', '0') === '1',
                'telegram_bot_hide_out_of_stock' => Setting::get('telegram_bot_hide_out_of_stock', '0') === '1',
                'telegram_bot_tma_enabled' => Setting::get('telegram_bot_tma_enabled', '0') === '1',
                'telegram_bot_tma_url' => Setting::get('telegram_bot_tma_url', ''),
                'telegram_bot_menu_button_text' => Setting::get('telegram_bot_menu_button_text', '🛍️ Shop Now'),
                'telegram_bot_restock_notify_enabled' => Setting::get('telegram_bot_restock_notify_enabled', '0') === '1',
                'telegram_bot_payment_methods' => $paymentMethods ?: ['Cash on Delivery', 'KBZPay', 'WavePay'],
                'telegram_bot_payment_info' => Setting::get('telegram_bot_payment_info', ''),
                'telegram_bot_kpay_info' => Setting::get('telegram_bot_kpay_info', ''),
                'telegram_bot_wave_info' => Setting::get('telegram_bot_wave_info', ''),
                'telegram_bot_ayapay_info' => Setting::get('telegram_bot_ayapay_info', ''),
                'telegram_bot_cbpay_info' => Setting::get('telegram_bot_cbpay_info', ''),
                'telegram_bot_other_bank_info' => Setting::get('telegram_bot_other_bank_info', ''),
                'telegram_bot_welcome_msg' => Setting::get('telegram_bot_welcome_msg', ''),
                'telegram_bot_catalog_per_page' => (int)Setting::get('telegram_bot_catalog_per_page', 3),
                'telegram_bot_catalog_style' => Setting::get('telegram_bot_catalog_style', 'card_feed'),
                'telegram_bot_catalog_menu_msg' => Setting::get('telegram_bot_catalog_menu_msg', ''),
                'telegram_bot_no_products_msg' => Setting::get('telegram_bot_no_products_msg', ''),
                'telegram_bot_select_variant_msg' => Setting::get('telegram_bot_select_variant_msg', ''),
                'telegram_bot_select_qty_msg' => Setting::get('telegram_bot_select_qty_msg', ''),
                'telegram_bot_search_prompt_msg' => Setting::get('telegram_bot_search_prompt_msg', ''),
                'telegram_bot_out_of_stock_msg' => Setting::get('telegram_bot_out_of_stock_msg', ''),
                'telegram_bot_invalid_qty_msg' => Setting::get('telegram_bot_invalid_qty_msg', ''),
                'telegram_bot_invalid_phone_msg' => Setting::get('telegram_bot_invalid_phone_msg', ''),
                'telegram_bot_select_payment_msg' => Setting::get('telegram_bot_select_payment_msg', ''),
                'telegram_bot_unexpected_slip_msg' => Setting::get('telegram_bot_unexpected_slip_msg', ''),
                'telegram_bot_order_error_msg' => Setting::get('telegram_bot_order_error_msg', ''),
                'telegram_bot_order_success_msg' => Setting::get('telegram_bot_order_success_msg', ''),
                'telegram_bot_order_approved_msg' => Setting::get('telegram_bot_order_approved_msg', ''),
                'telegram_bot_order_rejected_msg' => Setting::get('telegram_bot_order_rejected_msg', ''),
                'telegram_bot_order_dispatched_msg' => Setting::get('telegram_bot_order_dispatched_msg', ''),
                'telegram_bot_webhook_url' => Setting::get('telegram_bot_webhook_url', ''),
                'telegram_bot_webhook_mode' => Setting::get('telegram_bot_webhook_mode', '0') === '1',
                'telegram_bot_webhook_secret' => Setting::get('telegram_bot_webhook_secret', ''),
                'telegram_bot_payment_instructions_msg' => Setting::get('telegram_bot_payment_instructions_msg', ''),
                'telegram_bot_contact_info_msg' => Setting::get('telegram_bot_contact_info_msg', ''),
                'telegram_bot_no_orders_msg' => Setting::get('telegram_bot_no_orders_msg', ''),
                'telegram_bot_ask_phone_msg' => Setting::get('telegram_bot_ask_phone_msg', ''),
                'telegram_bot_ask_address_msg' => Setting::get('telegram_bot_ask_address_msg', ''),
                'telegram_bot_ask_slip_msg' => Setting::get('telegram_bot_ask_slip_msg', ''),
                'telegram_bot_cancelled_msg' => Setting::get('telegram_bot_cancelled_msg', ''),
                'telegram_bot_no_categories_msg' => Setting::get('telegram_bot_no_categories_msg', ''),
                'telegram_bot_no_brands_msg' => Setting::get('telegram_bot_no_brands_msg', ''),
                'telegram_bot_photo_error_msg' => Setting::get('telegram_bot_photo_error_msg', ''),
                'telegram_admin_new_order_msg' => Setting::get('telegram_admin_new_order_msg', ''),
                'telegram_admin_chat_ids' => Setting::get('telegram_admin_chat_ids', ''),

                // 🔘 Customizable Button Texts & Icons
                'telegram_bot_btn_catalog' => Setting::get('telegram_bot_btn_catalog', '🛍️ ကုန်ပစ္စည်းများ ကြည့်မည်'),
                'telegram_bot_btn_orders' => Setting::get('telegram_bot_btn_orders', '📦 တင်ထားသော Order များ'),
                'telegram_bot_btn_payment' => Setting::get('telegram_bot_btn_payment', 'ℹ️ ငွေလွှဲရန် အချက်အလက်'),
                'telegram_bot_btn_reorder' => Setting::get('telegram_bot_btn_reorder', '🔄 ယခင်မှာယူခဲ့သည်များ ထပ်မှာမည် (Re-Order)'),
                'telegram_bot_enable_reorder' => Setting::get('telegram_bot_enable_reorder', '0') === '1',
                'telegram_bot_btn_contact' => Setting::get('telegram_bot_btn_contact', '📞 ဆိုင်နှင့် ဆက်သွယ်ရန်'),
                'telegram_bot_btn_all_products' => Setting::get('telegram_bot_btn_all_products', '📦 ပစ္စည်းအားလုံး ကြည့်မည် (All Products)'),
                'telegram_bot_btn_categories' => Setting::get('telegram_bot_btn_categories', '📂 အမျိုးအစားအလိုက် (Categories)'),
                'telegram_bot_btn_brands' => Setting::get('telegram_bot_btn_brands', '🏷️ တံဆိပ်အလိုက် (Brands)'),
                'telegram_bot_btn_search' => Setting::get('telegram_bot_btn_search', '🔎 အမည် / ကုတ်နံပါတ်ဖြင့် ရှာမည် (Search)'),
                'telegram_bot_btn_home' => Setting::get('telegram_bot_btn_home', '🏠 ပင်မ မီနူး (Home)'),
                'telegram_bot_btn_cancel' => Setting::get('telegram_bot_btn_cancel', '❌ မမှာတော့ပါ (Cancel)'),
                'telegram_bot_show_catalog_btn_all' => Setting::get('telegram_bot_show_catalog_btn_all', '1') === '1',
                'telegram_bot_show_catalog_btn_categories' => Setting::get('telegram_bot_show_catalog_btn_categories', '1') === '1',
                'telegram_bot_show_catalog_btn_brands' => Setting::get('telegram_bot_show_catalog_btn_brands', '1') === '1',
                'telegram_bot_show_catalog_btn_search' => Setting::get('telegram_bot_show_catalog_btn_search', '1') === '1',
                'telegram_bot_show_catalog_btn_home' => Setting::get('telegram_bot_show_catalog_btn_home', '1') === '1',
                'telegram_bot_show_catalog_btn_cancel' => Setting::get('telegram_bot_show_catalog_btn_cancel', '1') === '1',
                'telegram_bot_show_catalog_nav_categories' => Setting::get('telegram_bot_show_catalog_nav_categories', Setting::get('telegram_bot_show_catalog_btn_categories', '1')) === '1',
                'telegram_bot_show_catalog_nav_brands' => Setting::get('telegram_bot_show_catalog_nav_brands', Setting::get('telegram_bot_show_catalog_btn_brands', '1')) === '1',
                'telegram_bot_show_catalog_nav_main_menu' => Setting::get('telegram_bot_show_catalog_nav_main_menu', Setting::get('telegram_bot_show_catalog_btn_home', '1')) === '1',
                'telegram_bot_show_catalog_nav_cancel' => Setting::get('telegram_bot_show_catalog_nav_cancel', Setting::get('telegram_bot_show_catalog_btn_cancel', '1')) === '1',
                'telegram_bot_show_catalog_nav_checkout' => Setting::get('telegram_bot_show_catalog_nav_checkout', '1') === '1',
                'telegram_bot_show_catalog_nav_cart' => Setting::get('telegram_bot_show_catalog_nav_cart', '1') === '1',
                'telegram_bot_btn_catalog_nav_checkout' => Setting::get('telegram_bot_btn_catalog_nav_checkout', '💳 အော်ဒါတင်မည်'),
                'telegram_bot_btn_catalog_nav_cart' => Setting::get('telegram_bot_btn_catalog_nav_cart', '🛒 ခြင်းတောင်း'),
                'telegram_bot_btn_view_details' => Setting::get('telegram_bot_btn_view_details', '🔍 အသေးစိတ်/ပုံ'),
                'telegram_bot_btn_buy_catalog' => Setting::get('telegram_bot_btn_buy_catalog', '🛒 မှာမည်'),
                'telegram_bot_btn_select_variant_catalog' => Setting::get('telegram_bot_btn_select_variant_catalog', '🎨 ဆိုဒ်ရွေးမည်'),
                'telegram_bot_btn_preorder_catalog' => Setting::get('telegram_bot_btn_preorder_catalog', '⏳ ကြိုတင်မှာမည်'),
                'telegram_bot_btn_order_now' => Setting::get('telegram_bot_btn_order_now', '🛒 မှာယူမည် (Order Now)'),
                'telegram_bot_btn_select_variant' => Setting::get('telegram_bot_btn_select_variant', '🎨 ဆိုဒ်ရွေး၍ မှာယူမည်'),
                'telegram_bot_btn_back' => Setting::get('telegram_bot_btn_back', '🔙 နောက်သို့'),
                'telegram_bot_btn_main_menu' => Setting::get('telegram_bot_btn_main_menu', '🔙 ပင်မ မီနူးသို့'),
                'telegram_bot_btn_prev' => Setting::get('telegram_bot_btn_prev', '◀️ ရှေ့သို့'),
                'telegram_bot_btn_next' => Setting::get('telegram_bot_btn_next', 'နောက်သို့ ▶️'),
                'telegram_bot_btn_back_to_catalog' => Setting::get('telegram_bot_btn_back_to_catalog', '🔙 ကတ်တလောက်သို့ ပြန်သွားမည်'),
                'telegram_bot_btn_qty_1' => Setting::get('telegram_bot_btn_qty_1', '1 ခု'),
                'telegram_bot_btn_qty_2' => Setting::get('telegram_bot_btn_qty_2', '2 ခု'),
                'telegram_bot_btn_qty_3' => Setting::get('telegram_bot_btn_qty_3', '3 ခု'),
                'telegram_bot_btn_qty_5' => Setting::get('telegram_bot_btn_qty_5', '5 ခု'),
                'telegram_bot_btn_custom_qty' => Setting::get('telegram_bot_btn_custom_qty', '🔢 အခြားအရေအတွက်'),
                'telegram_bot_btn_back_variant' => Setting::get('telegram_bot_btn_back_variant', '🔙 အခြားဆိုဒ် ပြန်ရွေးမည်'),
                'telegram_bot_btn_cod' => Setting::get('telegram_bot_btn_cod', '💵 ပစ္စည်းရောက်မှငွေချေ (COD)'),
                'telegram_bot_btn_kpay' => Setting::get('telegram_bot_btn_kpay', '📱 KBZPay ဖြင့်လွှဲမည်'),
                'telegram_bot_btn_wave' => Setting::get('telegram_bot_btn_wave', '📱 WavePay ဖြင့်လွှဲမည်'),
                'telegram_bot_btn_cart' => Setting::get('telegram_bot_btn_cart', '🛒 ဝယ်မည့်ပစ္စည်းများ'),
                'telegram_bot_btn_checkout' => Setting::get('telegram_bot_btn_checkout', '📦 မှာမည့်ပစ္စည်းများ Order တင်မည်'),
                'telegram_bot_btn_clear_cart' => Setting::get('telegram_bot_btn_clear_cart', '🗑️ ခြင်းတောင်း ရှင်းမည်'),
                'telegram_bot_btn_continue_shopping' => Setting::get('telegram_bot_btn_continue_shopping', 'တခြားပစ္စည်းကြည့်ဦးမယ်'),
                'telegram_bot_cart_badge_style' => Setting::get('telegram_bot_cart_badge_style', 'none'),
                'telegram_bot_cart_badge_hide_when_empty' => filter_var(Setting::get('telegram_bot_cart_badge_hide_when_empty', false), FILTER_VALIDATE_BOOLEAN),
                'telegram_bot_delivery_store_pickup_fee' => (float)Setting::get('telegram_bot_delivery_store_pickup_fee', 0),
                'telegram_bot_delivery_city_fee' => (float)Setting::get('telegram_bot_delivery_city_fee', 2500),
                'telegram_bot_delivery_express_fee' => (float)Setting::get('telegram_bot_delivery_express_fee', 3000),

                // ⚡ Simplified Checkout & Quick Order Flow Controls
                'telegram_bot_checkout_quick_cod' => Setting::get('telegram_bot_checkout_quick_cod', '1') === '1',
                'telegram_bot_checkout_skip_township' => Setting::get('telegram_bot_checkout_skip_township', '1') === '1',
                'telegram_bot_require_payment_slip' => Setting::get('telegram_bot_require_payment_slip', '1') === '1',

                // 📍 Location Input Mode & Native Sharing Buttons
                'telegram_bot_location_input_mode' => Setting::get('telegram_bot_location_input_mode', 'both_button_and_text'),
                'telegram_bot_btn_share_location' => Setting::get('telegram_bot_btn_share_location', '📍 လက်ရှိတည်နေရာ ပေးပို့မည် (Share GPS Location)'),
                'telegram_bot_btn_share_phone' => Setting::get('telegram_bot_btn_share_phone', '📱 ကျွန်ုပ်၏ ဖုန်းနံပါတ် ပေးပို့မည် (Share Phone)'),

                // 🚚 Delivery Labels & Selection
                'telegram_bot_delivery_select_msg' => Setting::get('telegram_bot_delivery_select_msg', ''),
                'telegram_bot_delivery_store_pickup_label' => Setting::get('telegram_bot_delivery_store_pickup_label', '🏢 ဆိုင်တွင် ကိုယ်တိုင်လာယူမည် (Store Pickup)'),
                'telegram_bot_delivery_city_label' => Setting::get('telegram_bot_delivery_city_label', '🚚 မြို့တွင်း အိမ်အရောက်ပို့ (City Delivery)'),
                'telegram_bot_delivery_express_label' => Setting::get('telegram_bot_delivery_express_label', '📦 နယ်ဝေး ကားဂိတ်ပို့ဆောင် (Highway Bus Gate)'),

                // 🛒 Cart Messages & Acknowledgments
                'telegram_bot_cart_empty_msg' => Setting::get('telegram_bot_cart_empty_msg', ''),
                'telegram_bot_cart_cleared_msg' => Setting::get('telegram_bot_cart_cleared_msg', ''),
                'telegram_bot_duplicate_slip_msg' => Setting::get('telegram_bot_duplicate_slip_msg', ''),
                'telegram_bot_phone_saved_msg' => Setting::get('telegram_bot_phone_saved_msg', ''),
                'telegram_bot_address_saved_msg' => Setting::get('telegram_bot_address_saved_msg', ''),
                'telegram_bot_location_received_msg' => Setting::get('telegram_bot_location_received_msg', ''),
                'telegram_bot_invalid_slip_doc_msg' => Setting::get('telegram_bot_invalid_slip_doc_msg', ''),

                // 🛒 Cart Customizations & Visibility Controls
                'telegram_bot_cart_header_title' => Setting::get('telegram_bot_cart_header_title', '🛒 ယူထားတဲ့ စာရင်း'),
                'telegram_bot_cart_show_header' => Setting::get('telegram_bot_cart_show_header', '1') === '1',
                'telegram_bot_cart_total_label' => Setting::get('telegram_bot_cart_total_label', '💰 စုစုပေါင်း:'),
                'telegram_bot_cart_show_total' => Setting::get('telegram_bot_cart_show_total', '1') === '1',
                'telegram_bot_cart_show_unit_price' => Setting::get('telegram_bot_cart_show_unit_price', '1') === '1',
                'telegram_bot_cart_btn_inc' => Setting::get('telegram_bot_cart_btn_inc', '➕'),
                'telegram_bot_cart_btn_dec' => Setting::get('telegram_bot_cart_btn_dec', '➖'),
                'telegram_bot_cart_btn_del' => Setting::get('telegram_bot_cart_btn_del', '🗑️'),
                'telegram_bot_cart_show_btn_inc' => Setting::get('telegram_bot_cart_show_btn_inc', '1') === '1',
                'telegram_bot_cart_show_btn_dec' => Setting::get('telegram_bot_cart_show_btn_dec', '1') === '1',
                'telegram_bot_cart_show_btn_del' => Setting::get('telegram_bot_cart_show_btn_del', '1') === '1',
                'telegram_bot_cart_show_item_name' => Setting::get('telegram_bot_cart_show_item_name', '1') === '1',
                'telegram_bot_cart_show_editor' => Setting::get('telegram_bot_cart_show_editor', '1') === '1',
                'telegram_bot_cart_btn_done' => Setting::get('telegram_bot_cart_btn_done', '✅ Done'),
                'telegram_bot_cart_show_done_btn' => Setting::get('telegram_bot_cart_show_done_btn', '1') === '1',
                'telegram_bot_cart_btn_checkout_inline' => Setting::get('telegram_bot_cart_btn_checkout_inline', '📦 မှာမည့်ပစ္စည်းများ Order တင်မည်'),
                'telegram_bot_cart_show_checkout_btn' => Setting::get('telegram_bot_cart_show_checkout_btn', '1') === '1',
                'telegram_bot_btn_edit_cart' => Setting::get('telegram_bot_btn_edit_cart', '✏️ စာရင်း ပြင်မယ်'),
                'telegram_bot_cart_show_edit_btn' => Setting::get('telegram_bot_cart_show_edit_btn', '1') === '1',
                'telegram_bot_cart_show_continue_btn' => Setting::get('telegram_bot_cart_show_continue_btn', '1') === '1',
                'telegram_bot_cart_footer_msg' => Setting::get('telegram_bot_cart_footer_msg', ''),
                'telegram_bot_cart_show_toast' => Setting::get('telegram_bot_cart_show_toast', '0') === '1',
                'telegram_bot_cart_toast_style' => Setting::get('telegram_bot_cart_toast_style', 'toast'),
                'telegram_bot_cart_show_flash_notice' => Setting::get('telegram_bot_cart_show_flash_notice', '0') === '1',
                'telegram_bot_cart_toast_inc' => Setting::get('telegram_bot_cart_toast_inc', 'အရေအတွက် တိုးလိုက်ပါသည် (+1)'),
                'telegram_bot_cart_toast_dec' => Setting::get('telegram_bot_cart_toast_dec', 'အရေအတွက် လျှော့ချလိုက်ပါသည် (-1)'),
                'telegram_bot_cart_toast_del' => Setting::get('telegram_bot_cart_toast_del', 'ပစ္စည်းအား ခြင်းတောင်းမှ ဖယ်ရှားလိုက်ပါပြီ'),
                'telegram_bot_cart_updated_header' => Setting::get('telegram_bot_cart_updated_header', '🛒 *ခြင်းတောင်း အပ်ဒိတ် လုပ်လိုက်ပါပြီရှင်!*'),
                'telegram_bot_cart_item_added_tpl' => Setting::get('telegram_bot_cart_item_added_tpl', '✅ *{product_name}* x {quantity} {unit} အား ခြင်းတောင်းထဲသို့ ထည့်သွင်းပြီးပါပြီ။'),
                'telegram_bot_cart_items_heading' => Setting::get('telegram_bot_cart_items_heading', '🛍️ *လူကြီးမင်း ခြင်းတောင်းထဲရှိ ပစ္စည်းများ:*'),
                'telegram_bot_cart_updated_footer_prompt' => Setting::get('telegram_bot_cart_updated_footer_prompt', "အော်ဒါတင်လိုပါက '💳 အော်ဒါတင်မည်' ကို နှိပ်ပါ (သို့မဟုတ်) အခြားပစ္စည်းများ ဆက်လက်ရွေးချယ်နိုင်ပါသည်-"),
                'telegram_bot_show_cart_updated_btn_checkout' => Setting::get('telegram_bot_show_cart_updated_btn_checkout', '1') === '1',
                'telegram_bot_show_cart_updated_btn_view' => Setting::get('telegram_bot_show_cart_updated_btn_view', '1') === '1',
                'telegram_bot_show_cart_updated_btn_continue' => Setting::get('telegram_bot_show_cart_updated_btn_continue', '1') === '1',
                'telegram_bot_show_cart_updated_btn_clear' => Setting::get('telegram_bot_show_cart_updated_btn_clear', '1') === '1',
                'telegram_bot_show_cart_updated_btn_menu' => Setting::get('telegram_bot_show_cart_updated_btn_menu', '1') === '1',

                // 👁️ Product Catalog Field Visibility Controls
                'telegram_bot_show_product_code' => Setting::get('telegram_bot_show_product_code', '1') === '1',
                'telegram_bot_show_product_category' => Setting::get('telegram_bot_show_product_category', '1') === '1',
                'telegram_bot_show_product_sub_category' => Setting::get('telegram_bot_show_product_sub_category', '1') === '1',
                'telegram_bot_show_product_brand' => Setting::get('telegram_bot_show_product_brand', '1') === '1',
                'telegram_bot_show_product_price' => Setting::get('telegram_bot_show_product_price', '1') === '1',
                'telegram_bot_show_product_stock' => Setting::get('telegram_bot_show_product_stock', '1') === '1',
                'telegram_bot_show_product_details' => Setting::get('telegram_bot_show_product_details', '0') === '1',
                'telegram_bot_show_product_image' => Setting::get('telegram_bot_show_product_image', '1') === '1',
                'telegram_bot_show_product_variants' => Setting::get('telegram_bot_show_product_variants', '1') === '1',

                // 🧭 Navigation Drilldown Controls
                'telegram_bot_direct_categories' => Setting::get('telegram_bot_direct_categories', '0') === '1',
                'telegram_bot_category_drilldown_sub' => Setting::get('telegram_bot_category_drilldown_sub', '1') === '1',
                'telegram_bot_brand_drilldown_category' => Setting::get('telegram_bot_brand_drilldown_category', '1') === '1',

                // 🤖 Bot Operating Mode & AI Sales Agent Configuration
                'telegram_bot_mode' => $this->aiAgentService->getOperatingMode(),
                'telegram_bot_ai_enabled' => $this->aiAgentService->isEnabled(),
                'telegram_bot_ai_provider' => Setting::get('telegram_bot_ai_provider', 'gemini'),
                'telegram_bot_ai_api_key' => Setting::get('telegram_bot_ai_api_key', ''),
                'telegram_bot_ai_model' => Setting::get('telegram_bot_ai_model', 'gemini-2.0-flash'),
                'telegram_bot_ai_persona' => Setting::get('telegram_bot_ai_persona', 'polite_sales'),
                'telegram_bot_ai_custom_persona' => Setting::get('telegram_bot_ai_custom_persona', ''),
                'telegram_bot_ai_custom_prompt' => Setting::get('telegram_bot_ai_custom_prompt', ''),
                'telegram_bot_ai_show_photos' => Setting::get('telegram_bot_ai_show_photos', '1'),

                // 🔘 Extended Button & Delivery Visibility Controls Across All Sections
                'telegram_bot_show_btn_catalog' => Setting::get('telegram_bot_show_btn_catalog', '1') === '1',
                'telegram_bot_show_btn_cart' => Setting::get('telegram_bot_show_btn_cart', '1') === '1',
                'telegram_bot_show_btn_orders' => Setting::get('telegram_bot_show_btn_orders', '1') === '1',
                'telegram_bot_show_btn_checkout' => Setting::get('telegram_bot_show_btn_checkout', '1') === '1',
                'telegram_bot_show_btn_payment' => Setting::get('telegram_bot_show_btn_payment', '1') === '1',
                'telegram_bot_show_btn_contact' => Setting::get('telegram_bot_show_btn_contact', '1') === '1',
                'telegram_bot_show_btn_reorder' => Setting::get('telegram_bot_show_btn_reorder', Setting::get('telegram_bot_enable_reorder', '0')) === '1',
                'telegram_bot_show_btn_buy_catalog' => Setting::get('telegram_bot_show_btn_buy_catalog', '1') === '1',
                'telegram_bot_show_btn_select_variant_catalog' => Setting::get('telegram_bot_show_btn_select_variant_catalog', '1') === '1',
                'telegram_bot_show_btn_order_now' => Setting::get('telegram_bot_show_btn_order_now', '1') === '1',
                'telegram_bot_show_btn_select_variant' => Setting::get('telegram_bot_show_btn_select_variant', '1') === '1',
                'telegram_bot_show_btn_back' => Setting::get('telegram_bot_show_btn_back', '1') === '1',
                'telegram_bot_show_btn_prev' => Setting::get('telegram_bot_show_btn_prev', '1') === '1',
                'telegram_bot_show_btn_next' => Setting::get('telegram_bot_show_btn_next', '1') === '1',
                'telegram_bot_show_btn_back_to_catalog' => Setting::get('telegram_bot_show_btn_back_to_catalog', '1') === '1',
                'telegram_bot_show_btn_qty_1' => Setting::get('telegram_bot_show_btn_qty_1', '1') === '1',
                'telegram_bot_show_btn_qty_2' => Setting::get('telegram_bot_show_btn_qty_2', '1') === '1',
                'telegram_bot_show_btn_qty_3' => Setting::get('telegram_bot_show_btn_qty_3', '1') === '1',
                'telegram_bot_show_btn_qty_5' => Setting::get('telegram_bot_show_btn_qty_5', '1') === '1',
                'telegram_bot_show_btn_custom_qty' => Setting::get('telegram_bot_show_btn_custom_qty', '1') === '1',
                'telegram_bot_show_btn_back_variant' => Setting::get('telegram_bot_show_btn_back_variant', '1') === '1',
                'telegram_bot_show_btn_kpay' => Setting::get('telegram_bot_show_btn_kpay', '1') === '1',
                'telegram_bot_show_btn_wave' => Setting::get('telegram_bot_show_btn_wave', '1') === '1',
                'telegram_bot_show_btn_share_phone' => Setting::get('telegram_bot_show_btn_share_phone', '1') === '1',
                'telegram_bot_show_delivery_pickup' => Setting::get('telegram_bot_show_delivery_pickup', '1') === '1',
                'telegram_bot_show_delivery_city' => Setting::get('telegram_bot_show_delivery_city', '1') === '1',
                'telegram_bot_show_delivery_express' => Setting::get('telegram_bot_show_delivery_express', '1') === '1',
            ];
        };

        $data = app()->runningUnitTests()
            ? $loader()
            : Cache::remember('tg_bot_api_settings_data', 60, $loader);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * 👨‍💼 POS Staff API: Update Bot Settings
     */
    public function updateBotSettings(Request $request)
    {
        if ($request->has('telegram_bot_ai_show_photos')) {
            $rawPhotos = $request->input('telegram_bot_ai_show_photos');
            if (is_bool($rawPhotos)) {
                $request->merge(['telegram_bot_ai_show_photos' => $rawPhotos ? '1' : '0']);
            } elseif (is_numeric($rawPhotos)) {
                $request->merge(['telegram_bot_ai_show_photos' => ((int)$rawPhotos === 1) ? '1' : '0']);
            }
        }

        if ($request->has('telegram_bot_mode') && $request->input('telegram_bot_mode') === 'ai_only') {
            $request->merge(['telegram_bot_mode' => 'hybrid']);
        }

        $validated = $request->validate([
            'telegram_bot_enabled' => 'nullable|boolean',
            'telegram_bot_token' => 'nullable|string',
            'telegram_bot_username' => 'nullable|string',
            'telegram_bot_warehouse_id' => 'nullable|integer|exists:outlets,id',
            'telegram_bot_auto_approve' => 'nullable|boolean',
            'telegram_bot_allow_unpaid' => 'nullable|boolean',
            'telegram_bot_allow_paid' => 'nullable|boolean',
            'telegram_bot_allow_partial' => 'nullable|boolean',
            'telegram_bot_min_partial_pct' => 'nullable|numeric|min:1|max:100',
            'telegram_bot_allow_out_of_stock_orders' => 'nullable|boolean',
            'telegram_bot_hide_out_of_stock' => 'nullable|boolean',
            'telegram_bot_tma_enabled' => 'nullable|boolean',
            'telegram_bot_tma_url' => 'nullable|string|max:500',
            'telegram_bot_menu_button_text' => 'nullable|string|max:100',
            'telegram_bot_restock_notify_enabled' => 'nullable|boolean',
            'telegram_bot_payment_methods' => 'nullable|array',
            'telegram_bot_payment_info' => 'nullable|string',
            'telegram_bot_kpay_info' => 'nullable|string',
            'telegram_bot_wave_info' => 'nullable|string',
            'telegram_bot_ayapay_info' => 'nullable|string',
            'telegram_bot_cbpay_info' => 'nullable|string',
            'telegram_bot_other_bank_info' => 'nullable|string',
            'telegram_bot_catalog_per_page' => 'nullable|integer|min:1|max:10',
            'telegram_bot_catalog_style' => 'nullable|string|in:card_feed,single_summary',
            'telegram_bot_welcome_msg' => 'nullable|string',
            'telegram_bot_catalog_menu_msg' => 'nullable|string',
            'telegram_bot_no_products_msg' => 'nullable|string',
            'telegram_bot_select_variant_msg' => 'nullable|string',
            'telegram_bot_select_qty_msg' => 'nullable|string',
            'telegram_bot_search_prompt_msg' => 'nullable|string',
            'telegram_bot_out_of_stock_msg' => 'nullable|string',
            'telegram_bot_invalid_qty_msg' => 'nullable|string',
            'telegram_bot_invalid_phone_msg' => 'nullable|string',
            'telegram_bot_select_payment_msg' => 'nullable|string',
            'telegram_bot_unexpected_slip_msg' => 'nullable|string',
            'telegram_bot_order_error_msg' => 'nullable|string',
            'telegram_bot_order_success_msg' => 'nullable|string',
            'telegram_bot_order_approved_msg' => 'nullable|string',
            'telegram_bot_order_rejected_msg' => 'nullable|string',
            'telegram_bot_payment_instructions_msg' => 'nullable|string',
            'telegram_bot_contact_info_msg' => 'nullable|string',
            'telegram_bot_no_orders_msg' => 'nullable|string',
            'telegram_bot_ask_phone_msg' => 'nullable|string',
            'telegram_bot_ask_address_msg' => 'nullable|string',
            'telegram_bot_ask_slip_msg' => 'nullable|string',
            'telegram_bot_cancelled_msg' => 'nullable|string',
            'telegram_bot_no_categories_msg' => 'nullable|string',
            'telegram_bot_no_brands_msg' => 'nullable|string',
            'telegram_bot_photo_error_msg' => 'nullable|string',
            'telegram_admin_new_order_msg' => 'nullable|string',
            'telegram_admin_chat_ids' => 'nullable|string',

            // 🔘 Buttons
            'telegram_bot_btn_catalog' => 'nullable|string|max:100',
            'telegram_bot_btn_orders' => 'nullable|string|max:100',
            'telegram_bot_btn_payment' => 'nullable|string|max:100',
            'telegram_bot_btn_reorder' => 'nullable|string|max:100',
            'telegram_bot_enable_reorder' => 'nullable|boolean',
            'telegram_bot_btn_contact' => 'nullable|string|max:100',
            'telegram_bot_btn_all_products' => 'nullable|string|max:100',
            'telegram_bot_btn_categories' => 'nullable|string|max:100',
            'telegram_bot_btn_brands' => 'nullable|string|max:100',
            'telegram_bot_btn_search' => 'nullable|string|max:100',
            'telegram_bot_btn_home' => 'nullable|string|max:100',
            'telegram_bot_btn_cancel' => 'nullable|string|max:100',
            'telegram_bot_show_catalog_btn_all' => 'nullable|boolean',
            'telegram_bot_show_catalog_btn_categories' => 'nullable|boolean',
            'telegram_bot_show_catalog_btn_brands' => 'nullable|boolean',
            'telegram_bot_show_catalog_btn_search' => 'nullable|boolean',
            'telegram_bot_show_catalog_btn_home' => 'nullable|boolean',
            'telegram_bot_show_catalog_btn_cancel' => 'nullable|boolean',
            'telegram_bot_show_catalog_nav_categories' => 'nullable|boolean',
            'telegram_bot_show_catalog_nav_brands' => 'nullable|boolean',
            'telegram_bot_show_catalog_nav_main_menu' => 'nullable|boolean',
            'telegram_bot_show_catalog_nav_cancel' => 'nullable|boolean',
            'telegram_bot_show_catalog_nav_checkout' => 'nullable|boolean',
            'telegram_bot_show_catalog_nav_cart' => 'nullable|boolean',
            'telegram_bot_btn_catalog_nav_checkout' => 'nullable|string|max:100',
            'telegram_bot_btn_catalog_nav_cart' => 'nullable|string|max:100',
            'telegram_bot_btn_view_details' => 'nullable|string|max:100',
            'telegram_bot_btn_buy_catalog' => 'nullable|string|max:100',
            'telegram_bot_btn_select_variant_catalog' => 'nullable|string|max:100',
            'telegram_bot_btn_preorder_catalog' => 'nullable|string|max:100',
            'telegram_bot_btn_order_now' => 'nullable|string|max:100',
            'telegram_bot_btn_select_variant' => 'nullable|string|max:100',
            'telegram_bot_btn_back' => 'nullable|string|max:100',
            'telegram_bot_btn_main_menu' => 'nullable|string|max:100',
            'telegram_bot_btn_prev' => 'nullable|string|max:100',
            'telegram_bot_btn_next' => 'nullable|string|max:100',
            'telegram_bot_btn_back_to_catalog' => 'nullable|string|max:100',
            'telegram_bot_btn_qty_1' => 'nullable|string|max:100',
            'telegram_bot_btn_qty_2' => 'nullable|string|max:100',
            'telegram_bot_btn_qty_3' => 'nullable|string|max:100',
            'telegram_bot_btn_qty_5' => 'nullable|string|max:100',
            'telegram_bot_btn_custom_qty' => 'nullable|string|max:100',
            'telegram_bot_btn_back_variant' => 'nullable|string|max:100',
            'telegram_bot_btn_cod' => 'nullable|string|max:100',
            'telegram_bot_btn_kpay' => 'nullable|string|max:100',
            'telegram_bot_btn_wave' => 'nullable|string|max:100',
            'telegram_bot_btn_cart' => 'nullable|string|max:100',
            'telegram_bot_btn_checkout' => 'nullable|string|max:100',
            'telegram_bot_btn_clear_cart' => 'nullable|string|max:100',
            'telegram_bot_btn_continue_shopping' => 'nullable|string|max:100',
            'telegram_bot_cart_badge_style' => 'nullable|string|in:none,count_only,count_unit,count_price,types_and_count',
            'telegram_bot_cart_badge_hide_when_empty' => 'nullable|boolean',
            'telegram_bot_delivery_store_pickup_fee' => 'nullable|numeric|min:0',
            'telegram_bot_delivery_city_fee' => 'nullable|numeric|min:0',
            'telegram_bot_delivery_express_fee' => 'nullable|numeric|min:0',
            'telegram_bot_checkout_quick_cod' => 'nullable|boolean',
            'telegram_bot_checkout_skip_township' => 'nullable|boolean',
            'telegram_bot_require_payment_slip' => 'nullable|boolean',
            'telegram_bot_reject_duplicate_slip' => 'nullable|boolean',
            'telegram_bot_order_dispatched_msg' => 'nullable|string|max:1000',
            'telegram_bot_webhook_url' => 'nullable|string|max:255',
            'telegram_bot_webhook_secret' => 'nullable|string|max:100',

            // 📍 Location Input Mode & Native Sharing Buttons
            'telegram_bot_location_input_mode' => 'nullable|string|in:both_button_and_text,text_only',
            'telegram_bot_btn_share_location' => 'nullable|string|max:100',
            'telegram_bot_btn_share_phone' => 'nullable|string|max:100',

            // 🚚 Delivery Labels & Selection
            'telegram_bot_delivery_select_msg' => 'nullable|string',
            'telegram_bot_delivery_store_pickup_label' => 'nullable|string|max:100',
            'telegram_bot_delivery_city_label' => 'nullable|string|max:100',
            'telegram_bot_delivery_express_label' => 'nullable|string|max:100',

            // 🛒 Cart Messages & Acknowledgments
            'telegram_bot_cart_empty_msg' => 'nullable|string',
            'telegram_bot_cart_cleared_msg' => 'nullable|string',
            'telegram_bot_duplicate_slip_msg' => 'nullable|string',
            'telegram_bot_phone_saved_msg' => 'nullable|string',
            'telegram_bot_address_saved_msg' => 'nullable|string',
            'telegram_bot_location_received_msg' => 'nullable|string',
            'telegram_bot_invalid_slip_doc_msg' => 'nullable|string',

            // 🛒 Cart Customizations & Visibility Controls
            'telegram_bot_cart_header_title' => 'nullable|string|max:100',
            'telegram_bot_cart_show_header' => 'nullable|boolean',
            'telegram_bot_cart_total_label' => 'nullable|string|max:100',
            'telegram_bot_cart_show_total' => 'nullable|boolean',
            'telegram_bot_cart_show_unit_price' => 'nullable|boolean',
            'telegram_bot_cart_btn_inc' => 'nullable|string|max:50',
            'telegram_bot_cart_btn_dec' => 'nullable|string|max:50',
            'telegram_bot_cart_btn_del' => 'nullable|string|max:50',
            'telegram_bot_cart_show_btn_inc' => 'nullable|boolean',
            'telegram_bot_cart_show_btn_dec' => 'nullable|boolean',
            'telegram_bot_cart_show_btn_del' => 'nullable|boolean',
            'telegram_bot_cart_show_item_name' => 'nullable|boolean',
            'telegram_bot_cart_show_editor' => 'nullable|boolean',
            'telegram_bot_cart_btn_done' => 'nullable|string|max:100',
            'telegram_bot_cart_show_done_btn' => 'nullable|boolean',
            'telegram_bot_cart_btn_checkout_inline' => 'nullable|string|max:100',
            'telegram_bot_cart_show_checkout_btn' => 'nullable|boolean',
            'telegram_bot_btn_edit_cart' => 'nullable|string|max:100',
            'telegram_bot_cart_show_edit_btn' => 'nullable|boolean',
            'telegram_bot_cart_show_continue_btn' => 'nullable|boolean',
            'telegram_bot_cart_footer_msg' => 'nullable|string|max:1000',
            'telegram_bot_cart_show_toast' => 'nullable|boolean',
            'telegram_bot_cart_toast_style' => 'nullable|string|in:toast,alert',
            'telegram_bot_cart_show_flash_notice' => 'nullable|boolean',
            'telegram_bot_cart_toast_inc' => 'nullable|string|max:200',
            'telegram_bot_cart_toast_dec' => 'nullable|string|max:200',
            'telegram_bot_cart_toast_del' => 'nullable|string|max:200',
            'telegram_bot_cart_updated_header' => 'nullable|string|max:200',
            'telegram_bot_cart_item_added_tpl' => 'nullable|string|max:500',
            'telegram_bot_cart_items_heading' => 'nullable|string|max:200',
            'telegram_bot_cart_updated_footer_prompt' => 'nullable|string|max:500',
            'telegram_bot_show_cart_updated_btn_checkout' => 'nullable|boolean',
            'telegram_bot_show_cart_updated_btn_view' => 'nullable|boolean',
            'telegram_bot_show_cart_updated_btn_continue' => 'nullable|boolean',
            'telegram_bot_show_cart_updated_btn_clear' => 'nullable|boolean',
            'telegram_bot_show_cart_updated_btn_menu' => 'nullable|boolean',

            // 👁️ Product Catalog Field Visibility Controls
            'telegram_bot_show_product_code' => 'nullable|boolean',
            'telegram_bot_show_product_category' => 'nullable|boolean',
            'telegram_bot_show_product_sub_category' => 'nullable|boolean',
            'telegram_bot_show_product_brand' => 'nullable|boolean',
            'telegram_bot_show_product_price' => 'nullable|boolean',
            'telegram_bot_show_product_stock' => 'nullable|boolean',
            'telegram_bot_show_product_details' => 'nullable|boolean',
            'telegram_bot_show_product_image' => 'nullable|boolean',
            'telegram_bot_show_product_variants' => 'nullable|boolean',

            // 🧭 Navigation Drilldown Controls
            'telegram_bot_direct_categories' => 'nullable|boolean',
            'telegram_bot_category_drilldown_sub' => 'nullable|boolean',
            'telegram_bot_brand_drilldown_category' => 'nullable|boolean',

            // 🤖 Bot Operating Mode & AI Sales Agent Controls
            'telegram_bot_mode' => 'nullable|string|in:standard,hybrid',
            'telegram_bot_ai_enabled' => 'nullable|boolean',
            'telegram_bot_ai_provider' => 'nullable|string|in:gemini,openai',
            'telegram_bot_ai_api_key' => 'nullable|string|max:500',
            'telegram_bot_ai_model' => 'nullable|string|max:100',
            'telegram_bot_ai_persona' => 'nullable|string|in:polite_sales,professional,friendly,custom',
            'telegram_bot_ai_custom_persona' => 'nullable|string|max:1500',
            'telegram_bot_ai_custom_prompt' => 'nullable|string|max:3000',
            'telegram_bot_ai_show_photos' => 'nullable|string|in:0,1',

            // 🔘 Extended Button & Delivery Visibility Controls Across All Sections
            'telegram_bot_show_btn_catalog' => 'nullable|boolean',
            'telegram_bot_show_btn_cart' => 'nullable|boolean',
            'telegram_bot_show_btn_orders' => 'nullable|boolean',
            'telegram_bot_show_btn_checkout' => 'nullable|boolean',
            'telegram_bot_show_btn_payment' => 'nullable|boolean',
            'telegram_bot_show_btn_contact' => 'nullable|boolean',
            'telegram_bot_show_btn_reorder' => 'nullable|boolean',
            'telegram_bot_show_btn_buy_catalog' => 'nullable|boolean',
            'telegram_bot_show_btn_select_variant_catalog' => 'nullable|boolean',
            'telegram_bot_show_btn_order_now' => 'nullable|boolean',
            'telegram_bot_show_btn_select_variant' => 'nullable|boolean',
            'telegram_bot_show_btn_back' => 'nullable|boolean',
            'telegram_bot_show_btn_prev' => 'nullable|boolean',
            'telegram_bot_show_btn_next' => 'nullable|boolean',
            'telegram_bot_show_btn_back_to_catalog' => 'nullable|boolean',
            'telegram_bot_show_btn_qty_1' => 'nullable|boolean',
            'telegram_bot_show_btn_qty_2' => 'nullable|boolean',
            'telegram_bot_show_btn_qty_3' => 'nullable|boolean',
            'telegram_bot_show_btn_qty_5' => 'nullable|boolean',
            'telegram_bot_show_btn_custom_qty' => 'nullable|boolean',
            'telegram_bot_show_btn_back_variant' => 'nullable|boolean',
            'telegram_bot_show_btn_kpay' => 'nullable|boolean',
            'telegram_bot_show_btn_wave' => 'nullable|boolean',
            'telegram_bot_show_btn_share_phone' => 'nullable|boolean',
            'telegram_bot_show_delivery_pickup' => 'nullable|boolean',
            'telegram_bot_show_delivery_city' => 'nullable|boolean',
            'telegram_bot_show_delivery_express' => 'nullable|boolean',
        ]);

        if ($request->has('telegram_bot_ai_show_photos')) {
            Setting::set('telegram_bot_ai_show_photos', $request->input('telegram_bot_ai_show_photos', '1'));
        }

        if (isset($validated['telegram_bot_mode'])) {
            Setting::set('telegram_bot_mode', $validated['telegram_bot_mode']);
            // Synchronize legacy boolean
            $isAiActive = $validated['telegram_bot_mode'] !== 'standard';
            Setting::set('telegram_bot_ai_enabled', $isAiActive ? '1' : '0');
            $validated['telegram_bot_ai_enabled'] = $isAiActive;
        } elseif (isset($validated['telegram_bot_ai_enabled'])) {
            $isAiActive = (bool)$validated['telegram_bot_ai_enabled'];
            Setting::set('telegram_bot_mode', $isAiActive ? 'hybrid' : 'standard');
        }

        if (isset($validated['telegram_bot_token'])) {
            $newToken = trim((string)$validated['telegram_bot_token']);
            $currentToken = trim((string)Setting::get('telegram_bot_token', ''));

            if (!empty($newToken) && $newToken !== $currentToken) {
                // Validate new token with Telegram getMe API
                try {
                    $res = \Illuminate\Support\Facades\Http::timeout(10)
                        ->withoutVerifying()
                        ->get("https://api.telegram.org/bot{$newToken}/getMe");

                    if ($res->successful() && ($res->json('ok') === true)) {
                        $botData = $res->json('result');
                        if (!empty($botData['username'])) {
                            $validated['telegram_bot_username'] = '@' . $botData['username'];
                            Setting::set('telegram_bot_username', '@' . $botData['username']);
                        }
                        // Delete webhook on new token to allow polling immediately
                        \Illuminate\Support\Facades\Http::timeout(10)
                            ->withoutVerifying()
                            ->post("https://api.telegram.org/bot{$newToken}/deleteWebhook");
                    } else {
                        $desc = $res->json('description') ?? 'Unauthorized';
                        $status = $res->status();
                        return response()->json([
                            'status' => 'error',
                            'message' => "Telegram Bot Token ချိတ်ဆက်မှု မအောင်မြင်ပါ ({$desc} - HTTP {$status})။ Telegram @BotFather မှ Token ကို ပြန်လည်စစ်ဆေးပါ။",
                        ], 422);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Telegram token verify error: ' . $e->getMessage());
                }
            }
            $validated['telegram_bot_token'] = $newToken;
        }

        // Ensure cart button icons have solid defaults if submitted as empty
        if (isset($validated['telegram_bot_cart_btn_inc']) && trim((string)$validated['telegram_bot_cart_btn_inc']) === '') {
            $validated['telegram_bot_cart_btn_inc'] = '➕';
        }
        if (isset($validated['telegram_bot_cart_btn_dec']) && trim((string)$validated['telegram_bot_cart_btn_dec']) === '') {
            $validated['telegram_bot_cart_btn_dec'] = '➖';
        }
        if (isset($validated['telegram_bot_cart_btn_del']) && trim((string)$validated['telegram_bot_cart_btn_del']) === '') {
            $validated['telegram_bot_cart_btn_del'] = '🗑️';
        }

        foreach ($validated as $key => $val) {
            if ($key === 'telegram_bot_payment_methods' && is_array($val)) {
                Setting::set($key, json_encode($val));
            } elseif (is_bool($val)) {
                Setting::set($key, $val ? '1' : '0');
            } else {
                Setting::set($key, (string)($val ?? ''));
            }
        }

        // 🔄 Sync or Remove [ 🛍️ Shop Now ] Telegram Menu Button based on telegram_bot_tma_enabled
        if (isset($validated['telegram_bot_tma_enabled'])) {
            $tmaEnabled = (bool)$validated['telegram_bot_tma_enabled'];
            $tmaUrl = trim((string)($validated['telegram_bot_tma_url'] ?? Setting::get('telegram_bot_tma_url', '')));
            $btnText = trim((string)($validated['telegram_bot_menu_button_text'] ?? Setting::get('telegram_bot_menu_button_text', '🛍️ Shop Now')));
            if (empty($btnText)) {
                $btnText = '🛍️ Shop Now';
            }

            try {
                if ($tmaEnabled && !empty($tmaUrl) && str_starts_with(strtolower($tmaUrl), 'https://')) {
                    $this->botService->setChatMenuButton($tmaUrl, $btnText);
                } else {
                    $this->botService->setChatMenuButton(null);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Auto-sync Telegram menu button failed: ' . $e->getMessage());
            }
        }

        // Update warehouse name cache if warehouse changed
        if (!empty($validated['telegram_bot_warehouse_id'])) {
            $outlet = Outlet::find($validated['telegram_bot_warehouse_id']);
            if ($outlet) {
                Setting::set('telegram_bot_warehouse_name', $outlet->name);
            }
        }

        // Invalidate cached API settings payload and bot navigation caches
        Cache::forget('tg_bot_api_settings_data');
        Cache::forget('tg_bot_active_categories_v2');
        Cache::forget('tg_bot_active_categories');
        Cache::forget('tg_bot_active_brands_v2');
        Cache::forget('tg_bot_active_brands');

        return response()->json([
            'status' => 'success',
            'message' => 'Telegram Bot settings saved successfully.',
            'data' => $this->getBotSettings()->getData()->data,
        ]);
    }

    /**
     * 👨‍💼 POS Staff API: Test Bot Token Connectivity
     */
    public function testBotToken(Request $request)
    {
        $token = trim((string)($request->input('token') ?: Setting::get('telegram_bot_token', '')));
        if (empty($token)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bot Token is required to test connection.',
            ], 422);
        }

        try {
            $res = \Illuminate\Support\Facades\Http::timeout(10)
                ->withoutVerifying()
                ->get("https://api.telegram.org/bot{$token}/getMe");

            if ($res->successful() && ($res->json('ok') === true)) {
                $botData = $res->json('result');
                // Auto update username
                if (!empty($botData['username'])) {
                    Setting::set('telegram_bot_username', '@' . $botData['username']);
                }
                // Also delete webhook so polling works smoothly
                \Illuminate\Support\Facades\Http::timeout(10)
                    ->withoutVerifying()
                    ->post("https://api.telegram.org/bot{$token}/deleteWebhook");

                // Auto synchronize Telegram Menu Commands
                try {
                    $this->botService->syncBotCommands();
                } catch (\Throwable $ce) {
                    \Illuminate\Support\Facades\Log::warning('Auto sync commands error: ' . $ce->getMessage());
                }

                return response()->json([
                    'status' => 'success',
                    'message' => "Connected successfully to Telegram Bot @{$botData['username']} ({$botData['first_name']})",
                    'data' => $botData,
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Connection failed: ' . ($res->json('description') ?? 'Invalid token or network error'),
            ], 400);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Telegram testBotToken failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Telegram connection error: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * 🤖 POS Staff API: Sync Telegram Bot Menu Commands
     */
    public function syncBotCommands()
    {
        $res = $this->botService->syncBotCommands();
        if (!empty($res['ok'])) {
            return response()->json([
                'status' => 'success',
                'message' => 'Telegram Bot Commands Menu synced successfully with Telegram.',
                'data' => $res,
            ]);
        }
        return response()->json([
            'status' => 'error',
            'message' => $res['description'] ?? 'Failed to sync commands with Telegram.',
        ], 422);
    }

    /**
     * 🚚 POS Staff API: Dispatch Order to Customer with Rider / Tracking Details
     */
    public function dispatchOrder(Request $request, $id)
    {
        $validated = $request->validate([
            'rider_name' => 'nullable|string|max:150',
            'rider_phone' => 'nullable|string|max:50',
            'tracking_code' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $sale = Sale::with(['items', 'customer'])->where('order_source', 'telegram_bot')->findOrFail($id);

        $riderName = trim($validated['rider_name'] ?? '');
        $riderPhone = trim($validated['rider_phone'] ?? '');
        $trackingCode = trim($validated['tracking_code'] ?? '');
        $notes = trim($validated['notes'] ?? '');

        $dispatchNote = "Dispatched: " . implode(' | ', array_filter([
            $riderName ? "Rider: {$riderName}" : null,
            $riderPhone ? "Phone: {$riderPhone}" : null,
            $trackingCode ? "Tracking: {$trackingCode}" : null,
            $notes ?: null,
        ]));

        $sale->update([
            'notes' => trim($sale->notes . " | " . $dispatchNote),
        ]);

        // Notify customer via Telegram
        $notified = false;
        try {
            $notified = $this->botService->notifyCustomerOrderDispatched(
                sale: $sale,
                riderName: $riderName ?: null,
                riderPhone: $riderPhone ?: null,
                trackingCode: $trackingCode ?: null,
                notes: $notes ?: null
            );
        } catch (\Throwable $e) {
            Log::warning('Dispatch notification error: ' . $e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => "Order #{$sale->reference_no} marked as dispatched." . ($notified ? " Customer notified via Telegram." : ""),
            'data' => $sale->fresh(['items']),
            'notified' => $notified,
        ]);
    }

    /**
     * 🌐 Telegram Webhook Registration
     */
    public function setWebhook(Request $request)
    {
        $validated = $request->validate([
            'webhook_url' => 'required|url',
            'secret_token' => 'nullable|string|max:100',
        ]);

        $secret = $validated['secret_token'] ?? Setting::get('telegram_bot_webhook_secret', '');
        if (empty($secret)) {
            $secret = bin2hex(random_bytes(16));
            Setting::set('telegram_bot_webhook_secret', $secret);
        }

        $res = $this->botService->setWebhook($validated['webhook_url'], $secret);
        if (!empty($res['ok'])) {
            Setting::set('telegram_bot_webhook_url', $validated['webhook_url']);
            Setting::set('telegram_bot_webhook_mode', '1');
            return response()->json([
                'status' => 'success',
                'message' => 'Telegram Webhook registered successfully.',
                'data' => $res,
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => $res['description'] ?? 'Failed to register webhook with Telegram.',
        ], 422);
    }

    /**
     * 🌐 Delete Telegram Webhook
     */
    public function deleteWebhook()
    {
        $res = $this->botService->deleteWebhook();
        if (!empty($res['ok'])) {
            Setting::set('telegram_bot_webhook_mode', '0');
            return response()->json([
                'status' => 'success',
                'message' => 'Telegram Webhook deleted successfully. Bot returned to Polling mode.',
                'data' => $res,
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => $res['description'] ?? 'Failed to delete webhook.',
        ], 422);
    }

    /**
     * 🌐 Get Telegram Webhook Info
     */
    public function getWebhookInfo()
    {
        $res = $this->botService->getWebhookInfo();
        return response()->json([
            'status' => 'success',
            'data' => $res,
        ]);
    }

    /**
     * 🔔 Public / TMA: Subscribe to Restock Alert
     */
    public function subscribeRestock(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'telegram_chat_id' => 'required|string|max:64',
            'customer_name' => 'nullable|string|max:191',
            'username' => 'nullable|string|max:191',
        ]);

        $sub = BotRestockSubscriber::firstOrCreate([
            'telegram_chat_id' => $validated['telegram_chat_id'],
            'product_id' => $validated['product_id'],
            'product_variant_id' => $validated['variant_id'] ?? null,
            'is_notified' => false,
        ], [
            'customer_name' => $validated['customer_name'] ?? null,
            'username' => $validated['username'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => '🔔 ပစ္စည်းပြန်ရောက်ရှိချိန်တွင် သင့်ထံသို့ အလိုအလျောက် သတင်းစကား ပို့ပေးပါမည်ရှင်။',
            'data' => $sub,
        ]);
    }

    /**
     * 📋 Staff / POS: List Restock Subscribers & Demand Leads
     */
    public function listRestockSubscribers(Request $request)
    {
        $query = BotRestockSubscriber::with(['product:id,name,code', 'variant:id,name'])
            ->orderBy('created_at', 'desc');

        if ($request->has('is_notified')) {
            $query->where('is_notified', $request->boolean('is_notified'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->paginate(25),
        ]);
    }

    /**
     * 🔘 Staff / POS: Sync Telegram Menu Button [ 🛍️ Shop Now ]
     */
    public function syncMenuButton(Request $request)
    {
        if ($request->has('url')) {
            $reqUrl = trim($request->input('url', ''));
            Setting::set('telegram_bot_tma_url', $reqUrl);
            $tmaUrl = $reqUrl;
        } else {
            $tmaUrl = trim(Setting::get('telegram_bot_tma_url', ''));
        }

        if ($request->has('tma_enabled')) {
            $tmaEnabled = $request->boolean('tma_enabled');
            Setting::set('telegram_bot_tma_enabled', $tmaEnabled ? '1' : '0');
        } else {
            $tmaEnabled = Setting::get('telegram_bot_tma_enabled', '1') === '1';
        }

        if (empty($tmaUrl)) {
            $tmaUrl = url('/bot/shop');
        }

        if ($tmaEnabled) {
            if (empty($tmaUrl)) {
                $tmaUrl = url('/bot/shop');
            }
            // Telegram Bot API strictly enforces HTTPS for WebApp URLs
            if (!str_starts_with(strtolower($tmaUrl), 'https://')) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Telegram Mini App (TMA) အတွက် HTTPS လင့်ခ်သာ အသုံးပြုနိုင်ပါသည် (လက်ရှိ URL မှာ '{$tmaUrl}' ဖြစ်နေပါသည်)။ Localhost (127.0.0.1) တွင် စမ်းသပ်ပါက Cloudflare Tunnel သို့မဟုတ် Ngrok HTTPS URL (ဥပမာ- https://xxx.trycloudflare.com/bot/shop) အား 'Custom Mini App URL' တွင် ထည့်သွင်းပြီး သိမ်းဆည်းပေးပါရန်။",
                ], 422);
            }

            if (str_contains(strtolower($tmaUrl), 'developers.cloudflare.com') || str_contains(strtolower($tmaUrl), 'cloudflare.com/website-terms')) {
                return response()->json([
                    'status' => 'error',
                    'message' => "ထည့်သွင်းထားသော URL မှာ Cloudflare လမ်းညွှန်စာမျက်နှာ (Documentation) ဖြစ်နေပါသည်။ PowerShell Terminal ဘောင်ကွက်ထဲရှိ https://xxxx.trycloudflare.com/bot/shop လင့်ခ်အစစ်ကို ကူးယူထည့်သွင်းပေးပါရန်။",
                ], 422);
            }

            $buttonText = $request->input('text', Setting::get('telegram_bot_menu_button_text', '🛍️ Shop Now'));
            $res = $this->botService->setChatMenuButton($tmaUrl, $buttonText);
            return response()->json([
                'status' => (!empty($res['ok'])) ? 'success' : 'error',
                'message' => (!empty($res['ok'])) 
                    ? 'Telegram [ 🛍️ Shop Now ] Menu Button အား အောင်မြင်စွာ ဖွင့်ပြီး ချိတ်ဆက်ပြီးပါပြီ!' 
                    : ($res['description'] ?? 'Failed to sync menu button with Telegram.'),
                'data' => $res,
            ]);
        } else {
            // Disabled: Immediately remove the [ 🛍️ Shop Now ] menu button from Telegram
            $res = $this->botService->setChatMenuButton(null);
            return response()->json([
                'status' => (!empty($res['ok'])) ? 'success' : 'error',
                'message' => (!empty($res['ok'])) 
                    ? 'Telegram [ 🛍️ Shop Now ] Menu Button အား အောင်မြင်စွာ ပိတ်ပြီး ဖျောက်ထားလိုက်ပါပြီ!' 
                    : ($res['description'] ?? 'Failed to remove menu button from Telegram.'),
                'data' => $res,
            ]);
        }
    }

    /**
     * 🧪 Test AI Agent Connection (Gemini / OpenAI)
     */
    public function testAiAgent(Request $request)
    {
        $provider = $request->input('provider', Setting::get('telegram_bot_ai_provider', 'gemini'));
        $apiKey = $request->input('api_key', Setting::get('telegram_bot_ai_api_key', ''));
        $model = $request->input('model', Setting::get('telegram_bot_ai_model', ''));

        $res = $this->aiAgentService->testConnection($provider, $apiKey, $model);

        return response()->json([
            'status' => $res['ok'] ? 'success' : 'error',
            'message' => $res['message'] ?? 'Connection test completed.',
            'data' => $res,
        ], $res['ok'] ? 200 : 422);
    }

    /**
     * 🎮 Simulate AI Response (POS Settings Playground)
     */
    public function simulateAiChat(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:1000',
        ]);

        $query = $request->input('query');
        $chatId = 'simulation_' . ($request->user()?->id ?? 'guest');
        $res = $this->aiAgentService->handleCustomerQuery($chatId, 'POS Staff (Test)', $query, '', true);

        if (!$res) {
            return response()->json([
                'status' => 'error',
                'message' => 'AI Agent returned empty response. Please check API Key and settings.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => $res,
        ]);
    }

    /**
     * 💬 POS Live Chat: Get List of Customer Conversations
     */
    public function listBotChats(Request $request)
    {
        $search = trim((string)$request->input('search', ''));
        $filter = $request->input('filter', 'all'); // 'all', 'unread', 'needs_human'

        // Get unique telegram_chat_ids with latest message ID
        $latestPerChat = BotChatMessage::select('telegram_chat_id', DB::raw('MAX(id) as max_id'))
            ->groupBy('telegram_chat_id');

        $query = BotChatMessage::joinSub($latestPerChat, 'latest_chat', function ($join) {
            $join->on('bot_chat_messages.id', '=', 'latest_chat.max_id');
        })
        ->orderBy('bot_chat_messages.created_at', 'desc');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('bot_chat_messages.customer_name', 'like', "%{$search}%")
                  ->orWhere('bot_chat_messages.telegram_username', 'like', "%{$search}%")
                  ->orWhere('bot_chat_messages.message_text', 'like', "%{$search}%")
                  ->orWhere('bot_chat_messages.telegram_chat_id', 'like', "%{$search}%");
            });
        }

        $allLatestMessages = $query->get();

        // Map conversations with unread counts and status
        $allConversations = $allLatestMessages->map(function ($latest) {
            $chatId = $latest->telegram_chat_id;
            
            $unreadCount = BotChatMessage::where('telegram_chat_id', $chatId)
                ->where('is_read', false)
                ->count();

            $isAiPaused = $this->aiAgentService->isAiPausedForChat($chatId);

            // Check for unhandled escalations (needs_human occurred after the latest staff reply)
            $lastStaffMessageId = BotChatMessage::where('telegram_chat_id', $chatId)
                ->where('sender_type', 'staff')
                ->max('id') ?? 0;

            $hasUnresolvedEscalation = BotChatMessage::where('telegram_chat_id', $chatId)
                ->where('needs_human', true)
                ->where('id', '>', $lastStaffMessageId)
                ->exists();

            $manualNeedsHuman = Cache::get("tg_needs_human_{$chatId}");

            // A chat needs human if:
            // 1. Manually flagged
            // 2. OR AI is paused (Human Staff Mode active)
            // 3. OR Unresolved customer/AI escalation exists
            // 4. OR Customer sent the latest message and AI did not reply (or is paused)
            $isLatestFromCustomer = ($latest->sender_type === 'customer');
            $hasNeedsHuman = false;
            if ($manualNeedsHuman !== null) {
                $hasNeedsHuman = (bool)$manualNeedsHuman;
            } else {
                $hasNeedsHuman = $isAiPaused || $hasUnresolvedEscalation || ($isLatestFromCustomer && $isAiPaused);
            }

            $cartItems = Cache::get("tg_cart_{$chatId}", []);
            $orderCount = Sale::where('telegram_chat_id', (string)$chatId)->count();

            return [
                'telegram_chat_id' => $chatId,
                'customer_name' => $latest->customer_name ?: 'Customer',
                'telegram_username' => $latest->telegram_username ?: '',
                'last_message' => [
                    'text' => $latest->message_text,
                    'sender_type' => $latest->sender_type,
                    'created_at' => $latest->created_at->toIso8601String(),
                    'created_at_human' => $latest->created_at->diffForHumans(),
                ],
                'unread_count' => $unreadCount,
                'needs_human' => $hasNeedsHuman,
                'is_ai_paused' => $isAiPaused,
                'cart_item_count' => count($cartItems),
                'order_count' => $orderCount,
            ];
        });

        // Compute counts across all conversations
        $counts = [
            'all' => $allConversations->count(),
            'unread' => $allConversations->filter(fn($c) => $c['unread_count'] > 0)->count(),
            'needs_human' => $allConversations->filter(fn($c) => $c['needs_human'] === true)->count(),
        ];

        // Filter according to active tab
        $filtered = $allConversations;
        if ($filter === 'unread') {
            $filtered = $allConversations->filter(fn($c) => $c['unread_count'] > 0)->values();
        } elseif ($filter === 'needs_human') {
            $filtered = $allConversations->filter(fn($c) => $c['needs_human'] === true)->values();
        } else {
            $filtered = $allConversations->values();
        }

        return response()->json([
            'status' => 'success',
            'data' => $filtered,
            'counts' => $counts,
            'pagination' => [
                'current_page' => 1,
                'last_page' => 1,
                'total' => $filtered->count(),
            ],
        ]);
    }

    /**
     * 💬 POS Live Chat: Get Full Chat History & Context for a Customer
     */
    public function getChatMessages(Request $request, $chatId)
    {
        // 1. Mark customer messages as read if not explicitly false
        $markRead = filter_var($request->input('mark_read', true), FILTER_VALIDATE_BOOLEAN);
        if ($markRead) {
            BotChatMessage::where('telegram_chat_id', (string)$chatId)
                ->where('is_read', false)
                ->update(['is_read' => true]);
        }

        // 2. Fetch latest messages (up to 300) in chronological order so recent conversation is always visible
        $messages = BotChatMessage::with('staff:id,name')
            ->where('telegram_chat_id', (string)$chatId)
            ->orderBy('id', 'desc')
            ->take(300)
            ->get()
            ->sortBy('id')
            ->values();

        // 3. Customer Info, Live Cart & Orders Context
        $cartSummary = Cache::get("tg_cart_{$chatId}", []);
        $recentOrders = Sale::where('telegram_chat_id', (string)$chatId)
            ->latest('id')
            ->take(5)
            ->get(['id', 'reference_no', 'grand_total', 'paid_amount', 'status', 'bot_approval_status', 'payment_status', 'created_at']);

        $latestSale = $recentOrders->first();
        $customer = null;
        if ($latestSale && !empty($latestSale->customer_phone)) {
            $customer = Customer::where('phone', $latestSale->customer_phone)->first();
        } elseif ($latestSale && !empty($latestSale->customer_id)) {
            $customer = Customer::find($latestSale->customer_id);
        }

        if (!$customer) {
            $lastCustMsg = BotChatMessage::where('telegram_chat_id', (string)$chatId)->latest('id')->first();
            $customer = [
                'name' => $lastCustMsg?->customer_name ?? $latestSale?->customer_name ?? 'Telegram Customer',
                'username' => $lastCustMsg?->telegram_username ?? $latestSale?->telegram_username ?? '',
                'phone' => $latestSale?->customer_phone ?? '',
                'shipping_address' => $latestSale?->shipping_address ?? '',
            ];
        }
        $isAiPaused = $this->aiAgentService->isAiPausedForChat($chatId);

        return response()->json([
            'status' => 'success',
            'data' => [
                'messages' => $messages,
                'is_ai_paused' => $isAiPaused,
                'cart' => $cartSummary,
                'recent_orders' => $recentOrders,
                'customer' => $customer,
            ],
        ]);
    }

    /**
     * 💬 POS Live Chat: Send Staff Reply or Product Card to Customer
     */
    public function sendStaffChatMessage(Request $request, $chatId)
    {
        $validated = $request->validate([
            'text' => 'nullable|string|max:2000',
            'product_id' => 'nullable|integer|exists:products,id',
        ]);

        $text = trim($validated['text'] ?? '');
        $productId = $validated['product_id'] ?? null;

        if (empty($text) && empty($productId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Message text or Product selection is required.',
            ], 422);
        }

        $product = $productId ? Product::find($productId) : null;
        $staffUserId = $request->user()?->id;

        $res = $this->botService->sendStaffMessageToCustomer($chatId, $text, $staffUserId, $product);

        if (!empty($res['ok'])) {
            Cache::forget("tg_needs_human_{$chatId}");
            return response()->json([
                'status' => 'success',
                'message' => 'Message delivered to customer Telegram successfully.',
                'data' => $res,
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => $res['description'] ?? 'Failed to send message to Telegram.',
        ], 422);
    }

    /**
     * 🤖 POS Live Chat: Toggle AI Auto-Mode ON / OFF for a Specific Customer
     */
    public function toggleChatAiMode(Request $request, $chatId)
    {
        $mode = $request->input('mode', 'toggle'); // 'ai', 'human', or 'toggle'

        $isCurrentlyPaused = $this->aiAgentService->isAiPausedForChat($chatId);

        if ($mode === 'human' || ($mode === 'toggle' && !$isCurrentlyPaused)) {
            $this->aiAgentService->pauseAiForChat($chatId, 120);
            $newPaused = true;
        } else {
            $this->aiAgentService->resumeAiForChat($chatId);
            $newPaused = false;
        }

        return response()->json([
            'status' => 'success',
            'message' => $newPaused ? 'AI paused for this customer (Human Staff Mode active).' : 'AI Auto-Mode resumed for this customer.',
            'data' => [
                'is_ai_paused' => $newPaused,
            ],
        ]);
    }

    /**
     * 💬 POS Live Chat: Mark Conversation as Read
     */
    public function markChatAsRead(Request $request, $chatId)
    {
        BotChatMessage::where('telegram_chat_id', (string)$chatId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'status' => 'success',
            'message' => 'Marked as read.',
        ]);
    }

    /**
     * 💬 POS Live Chat: Mark Conversation as Unread
     */
    public function markChatAsUnread(Request $request, $chatId)
    {
        $lastCustomerMsg = BotChatMessage::where('telegram_chat_id', (string)$chatId)
            ->where('sender_type', 'customer')
            ->latest('id')
            ->first();

        if ($lastCustomerMsg) {
            $lastCustomerMsg->update(['is_read' => false]);
        } else {
            BotChatMessage::where('telegram_chat_id', (string)$chatId)
                ->latest('id')
                ->update(['is_read' => false]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Marked as unread.',
        ]);
    }

    /**
     * 💬 POS Live Chat: Toggle Needs Human Flag
     */
    public function toggleChatNeedsHuman(Request $request, $chatId)
    {
        $status = $request->input('needs_human');
        $current = Cache::get("tg_needs_human_{$chatId}", false);
        $newStatus = ($status !== null) ? (bool)$status : !$current;

        Cache::put("tg_needs_human_{$chatId}", $newStatus, now()->addDays(7));

        if ($newStatus) {
            BotChatMessage::where('telegram_chat_id', (string)$chatId)
                ->latest('id')
                ->update(['needs_human' => true]);
        }

        return response()->json([
            'status' => 'success',
            'message' => $newStatus ? 'Flagged as requiring human attention.' : 'Human attention resolved.',
            'data' => [
                'needs_human' => $newStatus,
            ],
        ]);
    }
}

