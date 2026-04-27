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
        'exam_plan_date',
        'has_scholarship_history',
        'history_source',
        'history_period',
        'history_amount',
        'history_status',
        'ktm_path',
        'transkrip_nilai_path',
        'slip_gaji_ayah_path',
        'slip_gaji_ibu_path',
        'generated_docx_path',
        'assigned_to',
        'status',
        'submitted_at',
        'tendik_approved_at',
        'kaprodi_approved_at',
        'kadep_approved_at',
    ];

    protected $casts = [
        'history_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'tendik_approved_at' => 'datetime',
        'kaprodi_approved_at' => 'datetime',
        'kadep_approved_at' => 'datetime',
    ];

    protected $appends = [
        // Redundant profile data removed
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function mahasiswaProfile()
    {
        return $this->belongsTo(MahasiswaProfile::class);
    }

    public function assignedTendik()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
