<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'color',
        'description',
        'status',
    ];

    protected $appends = [
        'suppliers_count',
        'total_due',
    ];

    public function suppliers()
    {
        return $this->hasMany(Supplier::class, 'category', 'name');
    }

    public function getSuppliersCountAttribute()
    {
        if (array_key_exists('suppliers_count', $this->attributes)) {
            return (int) $this->attributes['suppliers_count'];
        }
        return $this->suppliers()->count();
    }

    public function getTotalDueAttribute()
    {
        if (array_key_exists('total_due', $this->attributes)) {
            return (float) $this->attributes['total_due'];
        }
        return (float) ($this->suppliers()->sum('balance_due') ?? 0.00);
    }
}
