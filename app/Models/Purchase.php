<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'supplier_id',
        'supplier_name',
        'warehouse_name',
        'date',
        'status', // Received, Pending, Ordered
        'tax_rate',
        'tax_amount',
        'discount',
        'shipping',
        'grand_total',
        'paid_amount',
        'due_amount',
        'payment_status', // Paid, Unpaid, Partial
        'payment_type', // Cash, Card, Bank Transfer, Cheque, etc.
        'notes',
        'created_by',
    ];

    protected $casts = [
        'tax_rate' => 'float',
        'tax_amount' => 'float',
        'discount' => 'float',
        'shipping' => 'float',
        'grand_total' => 'float',
        'paid_amount' => 'float',
        'due_amount' => 'float',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseItem::class, 'purchase_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
