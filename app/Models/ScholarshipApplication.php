<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScholarshipApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
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
        'siblings'
    ];

    protected $casts = [
        'siblings' => 'array',
        'father_income' => 'decimal:2',
        'mother_income' => 'decimal:2',
        'guardian_income' => 'decimal:2',
        'history_amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
