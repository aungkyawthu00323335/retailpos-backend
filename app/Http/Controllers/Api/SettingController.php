<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

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
            'default_warehouse' => 'warehouse',
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

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => is_array($value) ? json_encode($value) : (string)$value]
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Settings updated successfully',
        ]);
    }
}
