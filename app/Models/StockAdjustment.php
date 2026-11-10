<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'warehouse_id',
        'warehouse_name',
        'date',
        'total_products',
        'total_quantity',
        'net_quantity',
        'gross_quantity',
        'type', // Addition, Subtraction, Mixed
        'reason_code',
        'notes',
        'user_id',
        'user_name',
        'created_by',
        'user_role',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'user_id' => 'integer',
        'total_products' => 'integer',
        'total_quantity' => 'float',
        'net_quantity' => 'float',
        'gross_quantity' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(StockAdjustmentItem::class, 'stock_adjustment_id');
    }
}
