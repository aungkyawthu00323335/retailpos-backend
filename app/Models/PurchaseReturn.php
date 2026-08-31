<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'purchase_reference_no',
        'supplier_id',
        'supplier_name',
        'warehouse_name',
        'date',
        'status', // Completed, Pending
        'grand_total',
        'paid_amount',
        'due_amount',
        'payment_status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'grand_total' => 'float',
        'paid_amount' => 'float',
        'due_amount' => 'float',
    ];
}
