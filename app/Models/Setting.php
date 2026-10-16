<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * In-memory cache for ultra-fast repeated lookups
     *
     * @var array<string, array{value: mixed, time: float}>
     */
    protected static array $runtimeCache = [];
    protected static ?array $allSettingsCache = null;
    protected static float $allSettingsLoadedAt = 0.0;
    protected static ?string $settingsVersion = null;

    public static function get(string $key, $default = null)
    {
        if (app()->runningUnitTests()) {
            $setting = static::where('key', $key)->first();
            return $setting !== null ? $setting->value : $default;
        }

        $now = microtime(true);

        if (static::$allSettingsCache !== null && ($now - static::$allSettingsLoadedAt) < 10.0) {
            if (array_key_exists($key, static::$allSettingsCache)) {
                $val = static::$allSettingsCache[$key];
                return $val !== null ? $val : $default;
            }
            return $default;
        }

        // Fast version check to instantly sync cross-process changes (e.g. POS admin saved settings)
        $currentVersion = Cache::get('sys_settings_version');
        $isVersionStale = ($currentVersion !== null && $currentVersion !== static::$settingsVersion);
        $isTimeExpired = (static::$allSettingsCache === null || ($now - static::$allSettingsLoadedAt) > 10.0);

        if ($isVersionStale || $isTimeExpired) {
            try {
                static::$allSettingsCache = static::pluck('value', 'key')->toArray();
                static::$allSettingsLoadedAt = $now;
                static::$settingsVersion = $currentVersion;
                static::$runtimeCache = [];
            } catch (\Throwable $e) {
                // Database might be unavailable during early bootstrap
            }
        }

        if (static::$allSettingsCache !== null && array_key_exists($key, static::$allSettingsCache)) {
            $val = static::$allSettingsCache[$key];
            return $val !== null ? $val : $default;
        }

        // 2. Fast single in-memory hit
        if (isset(static::$runtimeCache[$key])) {
            $cached = static::$runtimeCache[$key];
            if (($now - $cached['time']) < 10.0) {
                return $cached['value'];
            }
        }

        // 3. Fallback database query
        $value = Cache::remember("sys_setting_{$key}", 10, function () use ($key) {
            $setting = static::where('key', $key)->first();
            return $setting ? $setting->value : null;
        });

        $final = $value !== null ? $value : $default;
        static::$runtimeCache[$key] = [
            'value' => $final,
            'time' => $now,
        ];
        if (static::$allSettingsCache !== null) {
            static::$allSettingsCache[$key] = $final;
        }

        return $final;
    }

    public static function set(string $key, $value)
    {
        $encoded = is_array($value) ? json_encode($value) : (string) $value;

        // Invalidate in-memory and Laravel cache
        if (static::$allSettingsCache !== null) {
            static::$allSettingsCache[$key] = $encoded;
        }
        unset(static::$runtimeCache[$key]);
        Cache::forget("sys_setting_{$key}");
        if (str_starts_with($key, 'telegram_') || str_starts_with($key, 'tg_')) {
            Cache::forget('tg_bot_api_settings_data');
            Cache::forget('tg_bot_active_categories_v2');
            Cache::forget('tg_bot_active_categories');
            Cache::forget('tg_bot_active_brands_v2');
            Cache::forget('tg_bot_active_brands');
        }

        // Bump global settings version to instantly notify background daemons (telegram:poll, queue workers)
        $newVersion = uniqid('set_', true);
        static::$settingsVersion = $newVersion;
        Cache::forever('sys_settings_version', $newVersion);

        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $encoded]
        );
    }

    public static function forget(string $key): void
    {
        if (static::$allSettingsCache !== null) {
            unset(static::$allSettingsCache[$key]);
        }
        unset(static::$runtimeCache[$key]);
        Cache::forget("sys_setting_{$key}");
        if (str_starts_with($key, 'telegram_') || str_starts_with($key, 'tg_')) {
            Cache::forget('tg_bot_api_settings_data');
        }
        static::where('key', $key)->delete();
    }

    public static function clearRuntimeCache(): void
    {
        static::$allSettingsCache = null;
        static::$allSettingsLoadedAt = 0.0;
        static::$runtimeCache = [];
    }
}
