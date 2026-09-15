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
        'product_variant_id',
        'unit_id',
        'unit_name',
        'conversion_rate',
        'product_code',
        'product_name',
        'unit_price',
        'base_unit_price',
        'quantity',
        'base_quantity',
        'tax',
        'discount',
        'subtotal',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'unit_id' => 'integer',
        'conversion_rate' => 'float',
        'unit_price' => 'float',
        'base_unit_price' => 'float',
        'quantity' => 'float',
        'base_quantity' => 'float',
        'tax' => 'float',
        'discount' => 'float',
        'subtotal' => 'float',
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }
}
