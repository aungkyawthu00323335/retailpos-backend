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

    protected $appends = [
        'sales_count',
        'total_due',
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

    public function getSalesCountAttribute()
    {
        if (array_key_exists('sales_count', $this->attributes)) {
            return (int)$this->attributes['sales_count'];
        }
        return $this->sales()->count();
    }

    public function getTotalDueAttribute()
    {
        return (float)($this->sales()->sum('due_amount') ?? 0.0);
    }
}
