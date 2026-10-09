<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 🛍️ Telegram Mini App (TMA) Touch Storefront
Route::get('/bot/shop', [\App\Http\Controllers\TelegramMiniAppController::class, 'index'])->name('bot.shop');
