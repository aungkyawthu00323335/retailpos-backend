<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class TelegramSetting extends Model
{
    protected $table = 'telegram_settings';

    protected $fillable = [
        'bot_token',
        'is_enabled',
        'auto_send_register_close',
        'auto_send_daily_enabled',
        'auto_send_daily_time',
        'last_auto_daily_sent_date',
        'last_auto_daily_sent_at',
        'include_profit',
        'include_registers',
        'message_language',
        'updated_by',
    ];

    protected $hidden = [
        'bot_token',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'auto_send_register_close' => 'boolean',
        'auto_send_daily_enabled' => 'boolean',
        'include_profit' => 'boolean',
        'include_registers' => 'boolean',
        'last_auto_daily_sent_at' => 'datetime',
    ];

    public static function current(): self
    {
        $setting = static::query()->orderBy('id')->first();
        if (!$setting) {
            $setting = static::create([
                'is_enabled' => false,
                'auto_send_register_close' => true,
                'auto_send_daily_enabled' => false,
                'auto_send_daily_time' => '20:00',
                'include_profit' => true,
                'include_registers' => true,
                'message_language' => 'my',
            ]);
        }

        return $setting;
    }

    public function getNormalizedScheduleTime(): string
    {
        $raw = trim((string)($this->auto_send_daily_time ?? ''));
        if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $raw)) {
            return $raw;
        }
        return '20:00';
    }

    public function setPlainBotToken(?string $token): void
    {
        $trimmed = trim((string)$token);
        if ($trimmed === '') {
            $this->bot_token = null;
            return;
        }

        try {
            $this->bot_token = 'enc:' . Crypt::encryptString($trimmed);
        } catch (\Throwable $e) {
            $this->bot_token = $trimmed;
        }
    }

    public function getPlainBotToken(): string
    {
        $raw = (string)($this->attributes['bot_token'] ?? '');
        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, 'enc:')) {
            try {
                return Crypt::decryptString(substr($raw, 4));
            } catch (\Throwable $e) {
                return '';
            }
        }

        return $raw;
    }

    public function getMaskedBotToken(): string
    {
        $plain = $this->getPlainBotToken();
        if ($plain === '') {
            return '';
        }

        $len = strlen($plain);
        if ($len <= 10) {
            return str_repeat('*', max(4, $len));
        }

        return substr($plain, 0, 6) . '********' . substr($plain, -4);
    }
}
