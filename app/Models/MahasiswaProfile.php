<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MahasiswaProfile extends Model
{
    //
    protected $guarded = ['id', 'user_id', 'nim', 'fakultas', 'program_studi'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function keluarga()
    {
        return $this->hasMany(KeluargaMahasiswa::class, 'mahasiswa_profile_id');
    }
}
