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
            if (static::$cachedTimezone === null) {
                $tz = Setting::get('timezone', config('app.timezone', 'Asia/Yangon'));
                if (!empty($tz) && in_array($tz, timezone_identifiers_list(), true)) {
                    static::$cachedTimezone = $tz;
                } else {
                    static::$cachedTimezone = config('app.timezone', 'Asia/Yangon');
                }
            }
            date_default_timezone_set(static::$cachedTimezone);
            config(['app.timezone' => static::$cachedTimezone]);
        } catch (\Throwable $e) {
            // Silently fall back to default during initial setup/migration
        }

        return $next($request);
    }
}
