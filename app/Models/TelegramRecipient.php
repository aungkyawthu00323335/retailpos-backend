<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramRecipient extends Model
{
    protected $table = 'telegram_recipients';

    protected $fillable = [
        'name',
        'chat_id',
        'outlet_id',
        'is_active',
        'notify_daily_summary',
        'notify_register_close',
    ];

    protected $casts = [
        'outlet_id' => 'integer',
        'is_active' => 'boolean',
        'notify_daily_summary' => 'boolean',
        'notify_register_close' => 'boolean',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }
}
