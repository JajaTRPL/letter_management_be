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
    ];

    protected $casts = [
        'is_predefined' => 'boolean',
    ];

    public function roomFacilities(): HasMany
    {
        return $this->hasMany(RoomFacility::class);
    }

    public function scopePredefined(Builder $query): Builder
    {
        return $query->where('is_predefined', true);
    }
}
