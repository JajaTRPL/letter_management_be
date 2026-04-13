<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'sub_role',
        'status',
        'assigned_tasks',
    ];

    protected $casts = [
        'assigned_tasks' => 'array',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function activityLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function mahasiswaProfile()
    {
        return $this->hasOne(MahasiswaProfile::class);
    }
}