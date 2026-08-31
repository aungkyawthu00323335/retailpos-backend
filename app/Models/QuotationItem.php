<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'product_id',
        'product_code',
        'product_name',
        'unit_price',
        'quantity',
        'tax',
        'discount',
        'subtotal',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'quantity' => 'float',
        'tax' => 'float',
        'discount' => 'float',
        'subtotal' => 'float',
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }
}
