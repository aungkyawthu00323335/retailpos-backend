<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'tier',
        'reward_points',
        'total_spent',
        'total_visits',
        'avatar',
        'status',
        'notes',
    ];

    protected $casts = [
        'reward_points' => 'integer',
        'total_spent' => 'decimal:2',
        'total_visits' => 'integer',
    ];
}
