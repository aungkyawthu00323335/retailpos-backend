<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpensePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'reference_no',
        'payment_date',
        'payment_time',
        'payment_type',
        'amount',
        'received_amount',
        'change_amount',
        'notes',
        'created_by',
        'user_id',
        'user_name',
        'user_role',
    ];

    protected $casts = [
        'amount'          => 'float',
        'received_amount' => 'float',
        'change_amount'   => 'float',
        'user_id'         => 'integer',
        'expense_id'      => 'integer',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
