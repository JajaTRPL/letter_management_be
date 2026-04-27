<?php

namespace App\Models;

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
}
