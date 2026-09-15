<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'sale_reference_no',
        'customer_id',
        'customer_name',
        'warehouse_id',
        'warehouse_name',
        'date',
        'status', // Completed, Pending
        'grand_total',
        'paid_amount',
        'due_amount',
        'payment_status',
        'payment_type',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'grand_total' => 'float',
        'paid_amount' => 'float',
        'due_amount' => 'float',
    ];
}
