<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotChatMessage extends Model
{
    protected $table = 'bot_chat_messages';

    protected $fillable = [
        'telegram_chat_id',
        'customer_name',
        'telegram_username',
        'sender_type',
        'staff_user_id',
        'message_type',
        'message_text',
        'metadata',
        'is_read',
        'needs_human',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_read' => 'boolean',
        'needs_human' => 'boolean',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
