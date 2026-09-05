<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role_id',
        'role',
        'branch',
        'avatar',
        'status',
        'api_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'outlet_user');
    }

    public function isSuperAdmin(): bool
    {
        if (strtolower((string) $this->role) === 'super admin') {
            return true;
        }

        if ($this->roleModel && $this->roleModel->slug === 'super_admin') {
            return true;
        }

        return false;
    }

    public function getAllowedOutlets()
    {
        if ($this->isSuperAdmin()) {
            return Outlet::where('is_active', true)->orderBy('id', 'asc')->get();
        }

        return $this->outlets()->where('is_active', true)->orderBy('outlets.id', 'asc')->get();
    }
}
