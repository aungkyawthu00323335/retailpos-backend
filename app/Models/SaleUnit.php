<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'base_unit_id',
        'base_unit_name',
        'description',
        'status',
    ];

    public function baseUnit()
    {
        return $this->belongsTo(ProductUnit::class, 'base_unit_id');
    }
}
