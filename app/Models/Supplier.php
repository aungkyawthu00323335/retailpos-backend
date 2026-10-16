<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'contact_person',
        'phone',
        'email',
        'category',
        'country',
        'city',
        'address',
        'balance_due',
        'payment_terms',
        'status',
        'notes',
    ];

    protected $casts = [
        'balance_due' => 'decimal:2',
    ];

    protected $appends = [];

    public function purchases()
    {
        return $this->hasMany(Purchase::class, 'supplier_id');
    }

    public function purchaseReturns()
    {
        return $this->hasMany(PurchaseReturn::class, 'supplier_id');
    }

    public function getPurchasesCountAttribute(): int
    {
        if (array_key_exists('purchases_count', $this->attributes)) {
            return (int)$this->attributes['purchases_count'];
        }
        return 0;
    }

    public function getTotalDueAttribute(): float
    {
        if (array_key_exists('total_due', $this->attributes)) {
            return (float)$this->attributes['total_due'];
        }
        return (float)($this->balance_due ?? 0.0);
    }
}
