<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferenceCounter extends Model
{
    use HasFactory;

    protected $table = 'reference_counters';

    protected $fillable = [
        'type',
        'prefix',
        'outlet_id',
        'last_seq',
    ];

    protected $casts = [
        'outlet_id' => 'integer',
        'last_seq'  => 'integer',
    ];
}
