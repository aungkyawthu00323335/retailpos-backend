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
            'tax_is_recoverable' => '0',
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

            // Historical posted voucher reference numbers are immutable accounting audit trail identifiers.
            // Updating prefix settings applies to future sequential documents only.

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
     * Deprecated no-op: mutating historical voucher reference numbers destroys accounting audit links.
     */
    protected function updateModelPrefixes($modelClass, string $newPrefix): void
    {
        // Intentionally disabled: prefix changes affect future generated numbers only.
    }
}
