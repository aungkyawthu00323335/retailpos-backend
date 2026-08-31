<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'warehouse_name',
        'date',
        'total_products',
        'total_quantity',
        'type', // Addition, Subtraction
        'notes',
        'created_by',
    ];

    public function items()
    {
        return $this->hasMany(StockAdjustmentItem::class, 'stock_adjustment_id');
    }
}
