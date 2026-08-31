<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'symbol',
        'rate',
        'is_default',
        'status',
    ];

    protected $casts = [
        'rate' => 'float',
        'is_default' => 'boolean',
    ];
}
