<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            $tz = \App\Models\Setting::get('timezone', config('app.timezone', 'Asia/Yangon'));
            if (!empty($tz) && in_array($tz, timezone_identifiers_list(), true)) {
                date_default_timezone_set($tz);
                config(['app.timezone' => $tz]);
            }
        } catch (\Throwable $e) {
            // Silently fall back during initial setup/migration
        }
    }
}
