<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

class SetAppTimezone
{
    protected static ?string $cachedTimezone = null;

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
            $tz = app()->runningUnitTests()
                ? Setting::getTimezoneFast(config('app.timezone', 'Asia/Yangon'))
                : (static::$cachedTimezone ??= Setting::getTimezoneFast(config('app.timezone', 'Asia/Yangon')));

            if (!empty($tz) && @date_default_timezone_set($tz)) {
                config(['app.timezone' => $tz]);
            }
        } catch (\Throwable $e) {
            // Silently fall back to default during initial setup/migration
        }

        return $next($request);
    }
}
