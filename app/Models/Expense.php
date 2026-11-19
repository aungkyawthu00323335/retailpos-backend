<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'warehouse_id',
        'warehouse_name',
        'expense_category_id',
        'expense_category_name',
        'title',
        'amount',
        'paid_amount',
        'due_amount',
        'payment_status',
        'payment_method',
        'payment_method_id',
        'cash_register_id',
        'transfer_id',
        'date',
        'details',
        'created_by',
        'user_id',
        'user_name',
        'user_role',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'payment_method_id' => 'integer',
        'cash_register_id' => 'integer',
        'transfer_id' => 'integer',
        'user_id' => 'integer',
        'amount' => 'float',
        'paid_amount' => 'float',
        'due_amount' => 'float',
    ];

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function payments()
    {
        return $this->hasMany(ExpensePayment::class, 'expense_id');
    }

    public function transfer()
    {
        return $this->belongsTo(StockTransfer::class, 'transfer_id');
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    protected static function booted(): void
    {
        $invalidate = static function (): void {
            \App\Services\PurchaseAccountingService::bumpListPrefix('expense_list_summary_v1');
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }
}
