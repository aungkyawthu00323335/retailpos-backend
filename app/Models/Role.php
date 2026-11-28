<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'permissions',
        'is_default',
        'status',
    ];

    protected $attributes = [
        'status' => 'Active',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_default' => 'boolean',
    ];

    public function isActive(): bool
    {
        return strcasecmp(trim((string) ($this->status ?? '')), 'active') === 0;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
