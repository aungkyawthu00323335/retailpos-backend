<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable audit record for offline sales synced after the originating cash register shift closed.
 */
class LateOfflineReconciliation extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'sale_reference_no',
        'warehouse_id',
        'warehouse_name',
        'original_cash_register_id',
        'syncing_open_register_id',
        'user_id',
        'user_name',
        'sale_date',
        'synced_at',
        'sale_grand_total',
        'cash_amount',
        'non_cash_amount',
        'payment_type',
        'reason',
        'status',
        'notes',
    ];

    protected $casts = [
        'sale_id' => 'integer',
        'warehouse_id' => 'integer',
        'original_cash_register_id' => 'integer',
        'syncing_open_register_id' => 'integer',
        'user_id' => 'integer',
        'sale_date' => 'datetime',
        'synced_at' => 'datetime',
        'sale_grand_total' => 'float',
        'cash_amount' => 'float',
        'non_cash_amount' => 'float',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function originalRegister()
    {
        return $this->belongsTo(CashRegister::class, 'original_cash_register_id');
    }

    public function syncingRegister()
    {
        return $this->belongsTo(CashRegister::class, 'syncing_open_register_id');
    }
}
