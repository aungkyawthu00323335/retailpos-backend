<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Expense;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SettingController extends Controller
{
    public function getSettings()
    {
        $defaults = [
            'logo_url' => '',
            'company_name' => 'အလင်းသစ် (Ah Linn Thit)',
            'default_email' => 'support@ahlinnthit.com',
            'company_email' => 'support@ahlinnthit.com',
            'company_phone' => '1234567890',
            'developed_by' => 'Ah Linn Thit',
            'footer' => 'All rights reserved by အလင်းသစ် (Ah Linn Thit)',
            'receipt_footer' => 'All rights reserved by အလင်းသစ် (Ah Linn Thit)',
            'default_customer' => 'walk-in-customer',
            'default_supplier' => '',
            'default_warehouse' => 'warehouse',
            'timezone' => 'Asia/Yangon',
            'country' => 'Myanmar',
            'state' => 'Yangon',
            'city' => 'Yangon',
            'postal_code' => '11181',
            'date_format' => 'YYYY-MM-DD',
            'address' => 'No. 124, Merchant Road, Yangon, Myanmar',
            'company_address' => 'No. 124, Merchant Road, Yangon, Myanmar',
            'default_currency' => 'USD',
            'currency_symbol' => '$',
            'currency_position' => 'Prefix',
            'default_language' => 'my',
            'receipt_header' => 'Welcome to အလင်းသစ် (Ah Linn Thit)!',
            'tax_rate' => '5',
            'tax_type' => 'percentage',
            'default_tax_type' => 'percentage',
            'default_tax_value' => '5',
            'discount_type' => 'fixed',
            'default_discount_type' => 'fixed',
            'default_discount_value' => '0',
            'barcode_symbology' => 'CODE128',
            'receipt_width' => '80mm',
            // 🏷️ Transaction Reference Prefixes
            'purchase_prefix' => 'PO_',
            'purchase_return_prefix' => 'PR_',
            'sale_prefix' => 'SA_',
            'sale_return_prefix' => 'SR_',
            'adjustment_prefix' => 'AD_',
            'transfer_prefix' => 'TR_',
            'expense_prefix' => 'EXP_',
            'quotation_prefix' => 'QUO_',
            'pos_prefix' => 'POS_',
            'pos_hide_out_of_stock' => '0',
            'delete_security_pin' => '',
            'product_image_compression_mode' => 'hd',
            'pricing_mode' => 'retail_and_wholesale',
            // 🧾 POS Voucher Customization Settings
            'show_voucher_logo' => '1',
            'show_voucher_shop_name' => '1',
            'show_voucher_address' => '1',
            'show_voucher_phone' => '1',
            'show_voucher_email' => '1',
            'show_voucher_footer' => '1',
            'voucher_footer_text' => 'ကျေးဇူးတင်ပါသည်၊ နောက်လည်း လာရောက်အားပေးပါရန် ဖိတ်ခေါ်အပ်ပါသည်။',
            'voucher_paper_size' => '80mm',
            // 🤖 Telegram Bot Commerce Settings
            'telegram_bot_enabled' => '0',
            'telegram_bot_token' => '',
            'telegram_bot_username' => '',
            'telegram_bot_warehouse_id' => '1',
            'telegram_bot_warehouse_name' => '',
            'telegram_bot_auto_approve' => '0',
            'telegram_bot_allow_unpaid' => '1',
            'telegram_bot_allow_paid' => '1',
            'telegram_bot_allow_partial' => '0',
            'telegram_bot_min_partial_pct' => '30',
            'telegram_bot_payment_methods' => json_encode(['Cash on Delivery', 'KBZPay', 'WavePay']),
            'telegram_bot_payment_info' => "KBZPay: 09123456789 (U Tin Win)\nWavePay: 09123456789 (U Tin Win)\nCB Bank: 001234567890 (Ah Linn Thit)",
            'telegram_bot_welcome_msg' => 'မင်္ဂလာပါရှင်။ အလင်းသစ် (Ah Linn Thit) မှ ကြိုဆိုပါသည်ရှင်။ အောက်ပါ Menu မှတဆင့် ကုန်ပစ္စည်းများအား စိတ်ကြိုက် ရွေးချယ်ဝယ်ယူနိုင်ပါသည်ရှင်။',
            'telegram_admin_chat_ids' => '',
        ];

        $settings = Setting::all()->pluck('value', 'key')->toArray();
        $merged = array_merge($defaults, $settings);

        return response()->json([
            'status' => 'success',
            'data' => $merged,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->all();

        $prefixModelMap = [
            'purchase_prefix' => Purchase::class,
            'purchase_return_prefix' => PurchaseReturn::class,
            'sale_prefix' => Sale::class,
            'sale_return_prefix' => SaleReturn::class,
            'adjustment_prefix' => StockAdjustment::class,
            'transfer_prefix' => StockTransfer::class,
            'expense_prefix' => Expense::class,
            'quotation_prefix' => Quotation::class,
        ];

        $defaultPrefixes = [
            'purchase_prefix' => 'PO_',
            'purchase_return_prefix' => 'PR_',
            'sale_prefix' => 'SA_',
            'sale_return_prefix' => 'SR_',
            'adjustment_prefix' => 'AD_',
            'transfer_prefix' => 'TR_',
            'expense_prefix' => 'EXP_',
            'quotation_prefix' => 'QUO_',
            'pos_prefix' => 'POS_',
        ];

        foreach ($data as $key => $value) {
            $strVal = is_array($value) ? json_encode($value) : (string)$value;

            // If default_language changed, validate and normalize supported language codes ('my', 'en')
            if ($key === 'default_language') {
                $strVal = in_array(strtolower($strVal), ['my', 'en'], true) ? strtolower($strVal) : 'my';
            }

            // Check if prefix actually changed before updating setting
            $hasPrefixChanged = false;
            if (isset($prefixModelMap[$key])) {
                $currentVal = Setting::get($key, $defaultPrefixes[$key] ?? '');
                $hasPrefixChanged = (trim((string) $currentVal) !== trim($strVal));
            }

            Setting::set($key, $strVal);

            // If a prefix actually changed, cascade the new prefix to existing transaction records
            if (isset($prefixModelMap[$key]) && !empty($strVal) && $hasPrefixChanged) {
                $this->updateModelPrefixes($prefixModelMap[$key], trim($strVal));
            }

            // If timezone changed, dynamically set PHP and Laravel timezone immediately
            if ($key === 'timezone' && !empty($strVal) && in_array($strVal, timezone_identifiers_list(), true)) {
                date_default_timezone_set($strVal);
                config(['app.timezone' => $strVal]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Settings updated successfully',
        ]);
    }

    /**
     * Updates all existing records of a model with the new prefix via fast bulk updates
     */
    protected function updateModelPrefixes($modelClass, string $newPrefix): void
    {
        if (empty($newPrefix)) return;
        try {
            $instance = new $modelClass();
            $table = $instance->getTable();

            // Fetch only id and reference_no in chunks of 500 without hydrating full Eloquent models
            DB::table($table)
                ->whereNotNull('reference_no')
                ->where('reference_no', '!=', '')
                ->select(['id', 'reference_no'])
                ->orderBy('id')
                ->chunk(500, function ($records) use ($table, $newPrefix) {
                    $updates = [];
                    foreach ($records as $record) {
                        $ref = (string) ($record->reference_no ?? '');
                        if (empty($ref)) continue;

                        // Extract pure numeric or sequential suffix starting from first digit (e.g. "2026-00976", "1114187")
                        if (preg_match('/(\d.*)$/', $ref, $matches)) {
                            $suffix = $matches[1];
                        } else {
                            // Fallback: strip all leading alpha-separator chunks
                            $suffix = preg_replace('/^([A-Za-z]+[_\-\/:]*)+/', '', $ref);
                            if (empty($suffix)) {
                                $suffix = (string) ($record->id ?? '1');
                            }
                        }
                        $newRef = $newPrefix . $suffix;
                        if ($newRef !== $ref) {
                            $updates[$record->id] = $newRef;
                        }
                    }

                    if (!empty($updates)) {
                        // Fast batch update via single SQL CASE WHEN statement
                        $cases = [];
                        $ids = [];
                        foreach ($updates as $id => $val) {
                            $escapedVal = addslashes($val);
                            $cases[] = "WHEN id = {$id} THEN '{$escapedVal}'";
                            $ids[] = (int) $id;
                        }
                        $idsList = implode(',', $ids);
                        $casesSql = implode(' ', $cases);
                        DB::statement(
                            "UPDATE `{$table}` SET `reference_no` = CASE {$casesSql} END WHERE `id` IN ({$idsList})"
                        );
                    }
                });
        } catch (\Throwable $e) {
            Log::warning("Error cascading prefix update for {$modelClass}: " . $e->getMessage());
        }
    }
}
