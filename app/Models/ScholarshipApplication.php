<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScholarshipApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'mahasiswa_profile_id',
        'scholarship_name',
        'study_level',
        'current_semester',
        'family_dependents',
        'gpa_last_2_semesters',
        'ipk',
        'sks_last_2_semesters',
        'total_sks_passed',
        'on_leave',
        'leave_semester',
        'thesis_status',
        'exam_plan_month',
        'exam_plan_year',
        'has_scholarship_history',
        'history_source',
        'history_period',
        'history_amount',
        'history_status',
        'ktm_path',
        'status',
    ];

    protected $casts = [
        'history_amount' => 'decimal:2',
    ];

    protected $appends = [
        'nim',
        'faculty',
        'study_program',
        'pob',
        'dob',
        'gender',
        'origin_address',
        'jogja_address',
        'signature_path',
        'father_name',
        'father_job',
        'father_income',
        'father_status',
        'father_death_date',
        'mother_name',
        'mother_job',
        'mother_income',
        'mother_status',
        'mother_death_date',
        'guardian_name',
        'guardian_job',
        'guardian_income',
        'guardian_status',
        'guardian_death_date',
        'siblings'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function mahasiswaProfile()
    {
        return $this->belongsTo(MahasiswaProfile::class);
    }

    // Accessors for Normalization Compatibility
    public function getNimAttribute()
    {
        return $this->mahasiswaProfile?->nim;
    }
    public function getFacultyAttribute()
    {
        return $this->mahasiswaProfile?->fakultas;
    }
    public function getStudyProgramAttribute()
    {
        return $this->mahasiswaProfile?->program_studi;
    }
    public function getPobAttribute()
    {
        return $this->mahasiswaProfile?->tempat_lahir;
    }
    public function getDobAttribute()
    {
        return $this->mahasiswaProfile?->tanggal_lahir;
    }
    public function getGenderAttribute()
    {
        $g = $this->mahasiswaProfile?->jenis_kelamin;
        return $g === 'L' ? 'Laki-laki' : ($g === 'P' ? 'Perempuan' : null);
    }
    public function getOriginAddressAttribute()
    {
        return $this->mahasiswaProfile?->alamat_asal;
    }
    public function getJogjaAddressAttribute()
    {
        return $this->mahasiswaProfile?->alamat_domisili;
    }
    public function getSignaturePathAttribute()
    {
        return $this->mahasiswaProfile?->tanda_tangan_path;
    }

    // Family Accessors
    protected function getFamilyMember($relation)
    {
        return $this->mahasiswaProfile?->keluarga->where('jenis_relasi', $relation)->first();
    }

    public function getFatherNameAttribute()
    {
        return $this->getFamilyMember('ayah')?->nama_lengkap;
    }
    public function getFatherJobAttribute()
    {
        return $this->getFamilyMember('ayah')?->pekerjaan;
    }
    public function getFatherIncomeAttribute()
    {
        return $this->getFamilyMember('ayah')?->penghasilan;
    }
    public function getFatherStatusAttribute()
    {
        return ucfirst($this->getFamilyMember('ayah')?->status_hidup ?? 'Hidup');
    }
    public function getFatherDeathDateAttribute()
    {
        return $this->getFamilyMember('ayah')?->tanggal_meninggal;
    }

    public function getMotherNameAttribute()
    {
        return $this->getFamilyMember('ibu')?->nama_lengkap;
    }
    public function getMotherJobAttribute()
    {
        return $this->getFamilyMember('ibu')?->pekerjaan;
    }
    public function getMotherIncomeAttribute()
    {
        return $this->getFamilyMember('ibu')?->penghasilan;
    }
    public function getMotherStatusAttribute()
    {
        return ucfirst($this->getFamilyMember('ibu')?->status_hidup ?? 'Hidup');
    }
    public function getMotherDeathDateAttribute()
    {
        return $this->getFamilyMember('ibu')?->tanggal_meninggal;
    }

    public function getGuardianNameAttribute()
    {
        return $this->getFamilyMember('wali')?->nama_lengkap;
    }
    public function getGuardianJobAttribute()
    {
        return $this->getFamilyMember('wali')?->pekerjaan;
    }
    public function getGuardianIncomeAttribute()
    {
        return $this->getFamilyMember('wali')?->penghasilan;
    }
    public function getGuardianStatusAttribute()
    {
        return ucfirst($this->getFamilyMember('wali')?->status_hidup ?? 'Hidup');
    }
    public function getGuardianDeathDateAttribute()
    {
        return $this->getFamilyMember('wali')?->tanggal_meninggal;
    }

    public function getSiblingsAttribute()
    {
        return $this->mahasiswaProfile?->keluarga->where('jenis_relasi', 'saudara')->map(function ($s) {
            return [
                'name' => $s->nama_lengkap,
                'job_or_school' => $s->pekerjaan,
                'marital_status' => $s->status_kawin,
                'relation' => $s->keterangan
            ];
        })->values()->toArray();
    }
}
