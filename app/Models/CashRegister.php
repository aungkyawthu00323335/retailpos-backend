<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashRegister extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'register_name',
        'user_id',
        'user_name',
        'warehouse_id',
        'warehouse_name',
        'opening_cash',
        'closing_cash',
        'expected_cash',
        'cash_difference',
        'status',
        'opened_at',
        'closed_at',
        'opening_notes',
        'closing_notes',
        'denominations',
    ];

    protected $casts = [
        'opening_cash' => 'float',
        'closing_cash' => 'float',
        'expected_cash' => 'float',
        'cash_difference' => 'float',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'denominations' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Outlet::class, 'warehouse_id');
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'cash_register_id');
    }

    public function transactions()
    {
        return $this->hasMany(CashRegisterTransaction::class, 'cash_register_id');
    }
}
