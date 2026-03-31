<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KeluargaMahasiswa extends Model
{
    //
    protected $guarded = ['id', 'mahasiswa_profile_id'];

    public function mahasiswaProfile()
    {
        return $this->belongsTo(MahasiswaProfile::class);
    }
}
