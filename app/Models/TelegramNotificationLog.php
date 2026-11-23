<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramNotificationLog extends Model
{
    protected $table = 'telegram_notification_logs';

    protected $fillable = [
        'event_type',
        'recipient_name',
        'chat_id',
        'outlet_name',
        'triggered_by',
        'status',
        'message_preview',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];
}
