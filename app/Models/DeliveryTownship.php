<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryTownship extends Model
{
    use HasFactory;

    protected $table = 'delivery_townships';

    protected $fillable = [
        'delivery_region_id',
        'name',
        'name_mm',
        'code',
        'delivery_fee',
        'free_delivery_threshold',
        'estimated_days',
        'is_cod_available',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'delivery_region_id' => 'integer',
        'delivery_fee' => 'float',
        'free_delivery_threshold' => 'float',
        'is_cod_available' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(DeliveryRegion::class, 'delivery_region_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return !empty($this->name_mm) ? $this->name_mm : $this->name;
    }
}
