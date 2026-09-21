<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_category_id',
        'parent_category_name',
        'name',
        'description',
        'status',
    ];

    public function products()
    {
        return $this->hasMany(Product::class, 'sub_category_id');
    }

    public function parentCategory()
    {
        return $this->belongsTo(ProductCategory::class, 'parent_category_id');
    }
}
