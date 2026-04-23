<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MahasiswaProfile extends Model
{
    //
    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function keluarga()
    {
        return $this->hasMany(KeluargaMahasiswa::class, 'mahasiswa_profile_id');
    }

    public function scholarshipHistories()
    {
        return $this->hasMany(ScholarshipHistory::class, 'mahasiswa_profile_id');
    }
}
