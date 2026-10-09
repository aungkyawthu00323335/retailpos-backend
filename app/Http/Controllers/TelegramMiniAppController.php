<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class TelegramMiniAppController extends Controller
{
    /**
     * Display the Telegram Mini App (TMA) Catalog & Cart
     */
    public function index(Request $request)
    {
        $storeName = Setting::get('company_name', Setting::get('store_name', 'Retail Online'));
        $storeLogo = Setting::get('company_logo', null);
        $currency = Setting::get('currency_symbol', 'MMK');

        return view('bot.tma', [
            'storeName' => $storeName,
            'storeLogo' => $storeLogo,
            'currency' => $currency,
        ]);
    }
}
