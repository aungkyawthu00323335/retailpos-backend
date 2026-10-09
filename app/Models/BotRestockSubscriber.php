<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotRestockSubscriber extends Model
{
    use HasFactory;

    protected $table = 'bot_restock_subscribers';

    protected $fillable = [
        'telegram_chat_id',
        'product_id',
        'product_variant_id',
        'customer_name',
        'username',
        'is_notified',
        'notified_at',
    ];

    protected $casts = [
        'is_notified' => 'boolean',
        'notified_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function scopePending($query)
    {
        return $query->where('is_notified', false);
    }
}
