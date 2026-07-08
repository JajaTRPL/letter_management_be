<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StudyProgram extends Model
{
    protected $fillable = ['code', 'name', 'department_id'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function scopeRuntimeVisible(Builder $query): Builder
    {
        return $query
            ->whereRaw("LOWER(TRIM(name)) NOT LIKE 'proof %'")
            ->whereRaw("UPPER(TRIM(code)) NOT LIKE 'PROOF%'")
            ->whereRaw("UPPER(TRIM(code)) NOT LIKE 'P2C1%'")
            ->whereRaw("UPPER(TRIM(code)) NOT LIKE 'P2C2%'")
            ->whereRaw("UPPER(TRIM(code)) NOT LIKE 'P2C3%'")
            ->whereHas('department', fn (Builder $department) => $department->runtimeVisible());
    }
}
