<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Variation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'variation_types',
        'status',
    ];

    protected $casts = [
        'variation_types' => 'array',
    ];

    protected $appends = [];

    public function getProductsCountAttribute(): int
    {
        if (array_key_exists('products_count', $this->attributes)) {
            return (int)$this->attributes['products_count'];
        }
        return 0;
    }
}