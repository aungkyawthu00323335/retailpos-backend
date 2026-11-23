<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatically transition stale open quotations whose expiry_date has passed.
Schedule::command('quotations:expire')->hourly()->withoutOverlapping();

// Dispatch scheduled daily Telegram business summary at the store's custom configured time.
Schedule::command('telegram:send-scheduled-daily')->everyMinute()->withoutOverlapping();
