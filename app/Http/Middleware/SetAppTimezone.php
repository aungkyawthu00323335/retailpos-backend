<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

class SetAppTimezone
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            if (Schema::hasTable('settings')) {
                $tz = Setting::get('timezone', config('app.timezone', 'Asia/Yangon'));
                if (!empty($tz) && in_array($tz, timezone_identifiers_list(), true)) {
                    date_default_timezone_set($tz);
                    config(['app.timezone' => $tz]);
                }
            }
        } catch (\Throwable $e) {
            // Silently fall back to default during initial setup/migration
        }

        return $next($request);
    }
}
