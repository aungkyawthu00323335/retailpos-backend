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

    public static function get(string $key, $default = null)
    {
        $now = microtime(true);
        // Fast in-memory hit (valid for 5 seconds in long-running processes)
        if (isset(static::$runtimeCache[$key])) {
            $cached = static::$runtimeCache[$key];
            if (($now - $cached['time']) < 5.0) {
                return $cached['value'];
            }
        }

        // Cache layer (30 seconds TTL)
        $value = Cache::remember("sys_setting_{$key}", 30, function () use ($key) {
            $setting = static::where('key', $key)->first();
            return $setting ? $setting->value : null;
        });

        $final = $value !== null ? $value : $default;
        static::$runtimeCache[$key] = [
            'value' => $final,
            'time' => $now,
        ];

        return $final;
    }

    public static function set(string $key, $value)
    {
        $encoded = is_array($value) ? json_encode($value) : (string) $value;

        // Invalidate both in-memory and Laravel cache
        unset(static::$runtimeCache[$key]);
        Cache::forget("sys_setting_{$key}");

        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $encoded]
        );
    }

    public static function clearRuntimeCache(): void
    {
        static::$runtimeCache = [];
    }
}
