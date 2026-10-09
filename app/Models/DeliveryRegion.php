<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryRegion extends Model
{
    use HasFactory;

    protected $table = 'delivery_regions';

    protected $fillable = [
        'name',
        'name_mm',
        'code',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function townships(): HasMany
    {
        return $this->hasMany(DeliveryTownship::class, 'delivery_region_id')->orderBy('sort_order')->orderBy('name');
    }

    public function activeTownships(): HasMany
    {
        return $this->hasMany(DeliveryTownship::class, 'delivery_region_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function getDisplayNameAttribute(): string
    {
        return !empty($this->name_mm) ? $this->name_mm : $this->name;
    }
}
