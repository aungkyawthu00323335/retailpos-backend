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
        'credit_limit',
        'total_visits',
        'avatar',
        'status',
        'notes',
    ];

    protected $casts = [
        'reward_points' => 'integer',
        'total_spent' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'total_visits' => 'integer',
    ];

    protected $appends = [];

    protected $hidden = [
        'receivable_due_sum',
        'open_return_credit_sum',
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
            return max(0.0, round((float)$this->attributes['total_due'], 2));
        }
        if (array_key_exists('receivable_due_sum', $this->attributes)) {
            $grossDue = (float)($this->attributes['receivable_due_sum'] ?? 0.0);
            $openReturnCredit = array_key_exists('open_return_credit_sum', $this->attributes)
                ? (float)($this->attributes['open_return_credit_sum'] ?? 0.0)
                : 0.0;
            return max(0.0, round($grossDue - $openReturnCredit, 2));
        }
        if ($this->exists && $this->getKey() !== null) {
            $grossDue = (float)$this->sales()
                ->tap(fn($q) => self::applyReceivableScope($q))
                ->where('due_amount', '>', 0)
                ->sum('due_amount');
            $openReturnCredit = (float)$this->saleReturns()
                ->tap(fn($q) => self::applyActiveReturnCreditScope($q))
                ->sum('due_amount');
            return max(0.0, round($grossDue - $openReturnCredit, 2));
        }
        return 0.0;
    }

    /**
     * Restricts a sales query to genuine posted Accounts Receivable:
     * excludes voided / cancelled / draft / pending / ordered sales (no revenue recognised yet).
     */
    public static function applyReceivableScope($query)
    {
        return $query
            ->where(function ($q) {
                $q->whereNull('status')->orWhereNotIn('status', [
                    'Voided', 'voided', 'VOIDED',
                    'Cancelled', 'cancelled', 'CANCELLED',
                    'Canceled', 'canceled', 'CANCELED',
                    'Draft', 'draft', 'DRAFT',
                    'Pending', 'pending', 'PENDING',
                    'Ordered', 'ordered', 'ORDERED',
                ]);
            });
    }

    /**
     * Restricts a sale_returns query to active unrefunded credit memos offsetting customer AR.
     */
    public static function applyActiveReturnCreditScope($query)
    {
        return self::applyReceivableScope($query)->where('due_amount', '>', 0);
    }
}
