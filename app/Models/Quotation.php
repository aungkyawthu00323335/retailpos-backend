<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'customer_id',
        'customer_name',
        'warehouse_name',
        'date',
        'tax_rate',
        'tax_amount',
        'discount',
        'shipping',
        'grand_total',
        'status', // Sent, Pending
        'notes',
        'created_by',
    ];

    protected $casts = [
        'tax_rate' => 'float',
        'tax_amount' => 'float',
        'discount' => 'float',
        'shipping' => 'float',
        'grand_total' => 'float',
    ];

    public function items()
    {
        return $this->hasMany(QuotationItem::class, 'quotation_id');
    }
}
