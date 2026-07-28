<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacilityType extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_predefined',
        'is_active',
    ];

    protected $casts = [
        'is_predefined' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function roomFacilities(): HasMany
    {
        return $this->hasMany(RoomFacility::class);
    }

    public function scopePredefined(Builder $query): Builder
    {
        return $query->where('is_predefined', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
