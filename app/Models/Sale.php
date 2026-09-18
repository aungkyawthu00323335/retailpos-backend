<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'customer_id',
        'customer_name',
        'warehouse_id',
        'warehouse_name',
        'date',
        'status', // Completed, Pending, Draft
        'tax_rate',
        'tax_amount',
        'discount',
        'redeemed_points',
        'points_discount',
        'shipping',
        'subtotal',
        'grand_total',
        'paid_amount',
        'due_amount',
        'change_amount',
        'payment_status', // Paid, Unpaid, Partial
        'payment_type', // Cash, Credit Card, Bank Transfer, Cheque, WavePay, KBZPay, etc.
        'notes',
        'pos_flag',
        'cash_register_id',
        'created_by',
        'user_id',
        'user_name',
        'user_role',
        'total_cogs',
        'void_reason',
        'voided_by',
        'voided_at',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'tax_rate' => 'float',
        'tax_amount' => 'float',
        'discount' => 'float',
        'redeemed_points' => 'integer',
        'points_discount' => 'float',
        'shipping' => 'float',
        'subtotal' => 'float',
        'grand_total' => 'float',
        'total_cogs' => 'float',
        'paid_amount' => 'float',
        'due_amount' => 'float',
        'change_amount' => 'float',
        'pos_flag' => 'boolean',
        'cash_register_id' => 'integer',
        'voided_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function costLayers()
    {
        return $this->hasMany(SaleItemCostLayer::class, 'sale_id');
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class, 'sale_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }
}
