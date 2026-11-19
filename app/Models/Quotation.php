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
        'warehouse_id',
        'warehouse_name',
        'date',
        'tax_rate',
        'tax_amount',
        'discount',
        'shipping',
        'grand_total',
        'status', // Sent, Pending, Ordered, Expired
        'expiry_date',
        'notes',
        'created_by',
        'user_id',
        'user_name',
        'user_role',
    ];

    protected $casts = [
        'date' => 'datetime:Y-m-d H:i:s',
        'warehouse_id' => 'integer',
        'user_id' => 'integer',
        'expiry_date' => 'date:Y-m-d',
        'tax_rate' => 'float',
        'tax_amount' => 'float',
        'discount' => 'float',
        'shipping' => 'float',
        'grand_total' => 'float',
    ];

    public function items()
    {
        return $this->hasMany(QuotationItem::class, 'quotation_id')->orderBy('id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sale()
    {
        return $this->hasOne(Sale::class, 'quotation_id')
            ->whereNotIn('sales.status', ['Voided', 'Cancelled']);
    }
}
