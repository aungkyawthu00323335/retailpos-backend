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

    protected $appends = [
        'user_name',
        'user_role',
    ];

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
            ?? $this->user?->name 
            ?? $this->sale?->user_name 
            ?? $this->sale?->created_by;
    }

    public function getUserRoleAttribute(): ?string
    {
        return $this->attributes['user_role'] 
            ?? $this->user?->role 
            ?? $this->sale?->user_role 
            ?? 'Cashier';
    }
}
