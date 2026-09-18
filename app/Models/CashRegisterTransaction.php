<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashRegisterTransaction extends Model
{
    use HasFactory;

    protected $table = 'cash_register_transactions';

    protected $fillable = [
        'cash_register_id',
        'type',
        'amount',
        'reason',
        'authorized_by',
        'manager_pin_verified',
    ];

    protected $casts = [
        'amount' => 'float',
        'manager_pin_verified' => 'boolean',
    ];

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }
}