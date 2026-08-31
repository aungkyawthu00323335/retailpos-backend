<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'reference_no',
        'payment_date',
        'payment_type',
        'amount',
        'received_amount',
        'change_amount',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'received_amount' => 'float',
        'change_amount' => 'float',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
