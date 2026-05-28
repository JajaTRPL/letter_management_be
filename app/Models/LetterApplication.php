<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LetterApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'mahasiswa_profile_id',
        'type',
        'tujuan_surat',
        'keperluan',
        'pob',
        'dob',
        'gender',
        'parent_name',
        'parent_job',
        'parent_job_type',
        'parent_nip',
        'parent_rank',
        'parent_group',
        'parent_institution',
        'parent_employee_id',
        'parent_position',
        'parent_npwp',
        'parent_business_name',
        'status',
        'assigned_to',
        'submitted_at',
        'processed_at',
        'finished_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'processed_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function mahasiswaProfile()
    {
        return $this->belongsTo(MahasiswaProfile::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
