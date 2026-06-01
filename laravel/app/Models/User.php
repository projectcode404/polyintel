<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

final class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;
    use HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function isAnalyst(): bool
    {
        return $this->hasRole('analyst');
    }

    public function getRoleLabelAttribute(): string
    {
        return match (true) {
            $this->isAdmin()   => 'Admin',
            $this->isAnalyst() => 'Analyst',
            default            => 'Unknown',
        };
    }

    public function getRoleBadgeClassAttribute(): string
    {
        return match (true) {
            $this->isAdmin()   => 'bg-red-lt',
            $this->isAnalyst() => 'bg-blue-lt',
            default            => 'bg-secondary-lt',
        };
    }
}
