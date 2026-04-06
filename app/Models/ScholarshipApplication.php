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
}
