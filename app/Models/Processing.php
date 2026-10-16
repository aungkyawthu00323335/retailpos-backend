<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Processing extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'warehouse_id',
        'warehouse_name',
        'date',
        'output_product_id',
        'output_variant_id',
        'output_product_code',
        'output_product_name',
        'output_variant_name',
        'output_unit_id',
        'output_unit_name',
        'output_conversion_rate',
        'output_quantity',
        'output_base_quantity',
        'output_unit_cost',
        'total_input_cost',
        'additional_cost',
        'total_cost',
        'status',
        'notes',
        'user_id',
        'user_name',
        'created_by',
        'user_role',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'output_product_id' => 'integer',
        'output_variant_id' => 'integer',
        'output_unit_id' => 'integer',
        'output_conversion_rate' => 'float',
        'output_quantity' => 'float',
        'output_base_quantity' => 'float',
        'output_unit_cost' => 'float',
        'total_input_cost' => 'float',
        'additional_cost' => 'float',
        'total_cost' => 'float',
        'user_id' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(ProcessingItem::class, 'processing_id');
    }

    public function outputProduct()
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }

    public function outputVariant()
    {
        return $this->belongsTo(ProductVariant::class, 'output_variant_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Outlet::class, 'warehouse_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
