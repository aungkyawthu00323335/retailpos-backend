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
use Illuminate\Support\Facades\Log;

class SettingController extends Controller
{
    public function getSettings()
    {
        $defaults = [
            'logo_url' => '',
            'company_name' => 'Infy Retail POS',
            'default_email' => 'support@infypos.com',
            'company_email' => 'support@infypos.com',
            'company_phone' => '1234567890',
            'developed_by' => 'infyom',
            'footer' => 'All rights reserved by InfyOm Technologies',
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
            'receipt_header' => 'Welcome to Infy Retail POS!',
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
            'purchase_return_prefix' => PurchaseReturn::class,
            'sale_prefix' => Sale::class,
            'sale_return_prefix' => SaleReturn::class,
            'adjustment_prefix' => StockAdjustment::class,
            'transfer_prefix' => StockTransfer::class,
            'expense_prefix' => Expense::class,
            'quotation_prefix' => Quotation::class,
        ];

        foreach ($data as $key => $value) {
            $strVal = is_array($value) ? json_encode($value) : (string)$value;
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $strVal]
            );

            // If a prefix changed, cascade the new prefix to existing transaction records
            if (isset($prefixModelMap[$key]) && !empty($strVal)) {
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
     * Updates all existing records of a model with the new prefix
     */
    protected function updateModelPrefixes($modelClass, string $newPrefix): void
    {
        if (empty($newPrefix)) return;
        try {
            $records = $modelClass::all();
            foreach ($records as $record) {
                $ref = (string) ($record->reference_no ?? '');
                if (!empty($ref)) {
                    // Extract suffix by removing any previous alpha characters and separators
                    $suffix = preg_replace('/^[A-Za-z]+[_\-\/:]*/', '', $ref);
                    if (empty($suffix)) {
                        $suffix = $ref;
                    }
                    $newRef = $newPrefix . $suffix;
                    if ($newRef !== $ref) {
                        $record->reference_no = $newRef;
                        $record->save();
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Error cascading prefix update for {$modelClass}: " . $e->getMessage());
        }
    }
}
