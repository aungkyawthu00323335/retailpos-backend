<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'name',
        'phone',
        'email',
        'tier',
        'reward_points',
        'total_spent',
        'total_visits',
        'avatar',
        'status',
        'notes',
    ];

    protected $casts = [
        'reward_points' => 'integer',
        'total_spent' => 'decimal:2',
        'total_visits' => 'integer',
    ];

    protected $appends = [];

    protected $hidden = [
        'receivable_due_sum',
    ];

    public function sales()
    {
        return $this->hasMany(Sale::class, 'customer_id');
    }

    public function saleReturns()
    {
        return $this->hasMany(SaleReturn::class, 'customer_id');
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class, 'customer_id');
    }

    public function getSalesCountAttribute(): int
    {
        if (array_key_exists('sales_count', $this->attributes)) {
            return (int)$this->attributes['sales_count'];
        }
        return 0;
    }

    public function getTotalDueAttribute(): float
    {
        if (array_key_exists('total_due', $this->attributes)) {
            return (float)$this->attributes['total_due'];
        }
        if (array_key_exists('receivable_due_sum', $this->attributes)) {
            return (float)($this->attributes['receivable_due_sum'] ?? 0.0);
        }
        return 0.0;
    }

    /**
     * Restricts a sales query to genuine Accounts Receivable:
     * excludes voided / cancelled / draft sales and Telegram bot orders
     * that are still awaiting approval or were rejected (no revenue recognised yet).
     */
    public static function applyReceivableScope($query)
    {
        return $query
            ->where(function ($q) {
                $q->whereNull('status')->orWhereNotIn('status', ['Voided', 'Cancelled', 'Draft']);
            })
            ->where(function ($q) {
                $q->whereNull('bot_approval_status')->orWhereNotIn('bot_approval_status', ['pending', 'rejected']);
            });
    }
}
