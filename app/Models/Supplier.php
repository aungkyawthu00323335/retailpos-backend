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
}
