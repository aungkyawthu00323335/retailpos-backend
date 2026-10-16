<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'reference_no',
        'payment_date',
        'payment_type',
        'amount',
        'received_amount',
        'change_amount',
        'notes',
        'created_by',
        'user_id',
        'user_name',
        'user_role',
    ];

    protected $casts = [
        'amount' => 'float',
        'received_amount' => 'float',
        'change_amount' => 'float',
        'user_id' => 'integer',
    ];

    protected $appends = [];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getUserNameAttribute(): ?string
    {
        return $this->attributes['user_name'] 
            ?? $this->attributes['created_by'] 
            ?? null;
    }

    public function getUserRoleAttribute(): ?string
    {
        return $this->attributes['user_role'] 
            ?? 'Cashier';
    }
}
