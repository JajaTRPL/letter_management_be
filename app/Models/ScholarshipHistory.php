<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScholarshipHistory extends Model
{
    protected $guarded = ['id'];

    public function mahasiswaProfile()
    {
        return $this->belongsTo(MahasiswaProfile::class, 'mahasiswa_profile_id');
    }
}
