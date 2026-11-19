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
    protected static ?string $bootedTimezone = null;

    /**
     * Single-column fast timezone lookup used on request boot/middleware
     * so we avoid loading all rows (including large logos/templates) from settings.
     */
    public static function getTimezoneFast(string $default = 'Asia/Yangon'): string
    {
        if (!app()->runningUnitTests() && static::$bootedTimezone !== null) {
            return static::$bootedTimezone;
        }

        try {
            $tz = static::where('key', 'timezone')->value('value');
            $resolved = !empty($tz) ? (string)$tz : $default;
            if (!app()->runningUnitTests()) {
                static::$bootedTimezone = $resolved;
            }
            return $resolved;
        } catch (\Throwable $e) {
            return $default;
        }
    }

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
        if ($key === 'timezone') {
            static::$bootedTimezone = null;
        }
        unset(static::$runtimeCache[$key]);
        Cache::forget("sys_setting_{$key}");

        // Bump global settings version to notify runtime workers
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
        static::where('key', $key)->delete();
    }

    public static function clearRuntimeCache(): void
    {
        static::$allSettingsCache = null;
        static::$allSettingsLoadedAt = 0.0;
        static::$runtimeCache = [];
        static::$bootedTimezone = null;
    }
}
