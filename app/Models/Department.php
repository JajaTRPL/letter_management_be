<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = ['code', 'name', 'faculty_id'];

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    public function studyPrograms()
    {
        return $this->hasMany(StudyProgram::class);
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
            ->whereRaw("UPPER(TRIM(code)) <> 'PDQJMSZS85'");
    }
}
