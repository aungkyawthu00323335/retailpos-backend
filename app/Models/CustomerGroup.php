<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'discount_percentage',
        'color',
        'description',
        'status',
    ];

    protected $casts = [
        'discount_percentage' => 'decimal:2',
    ];

    protected $appends = [
        'members_count',
    ];

    public function customers()
    {
        return $this->hasMany(Customer::class, 'tier', 'name');
    }

    public function getMembersCountAttribute()
    {
        if (array_key_exists('members_count', $this->attributes)) {
            return (int)$this->attributes['members_count'];
        }
        return $this->customers()->count();
    }
}
