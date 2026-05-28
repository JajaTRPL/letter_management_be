<?php

namespace App\Models;

use App\Support\LetterWorkflowStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScholarshipApplication extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = LetterWorkflowStatus::DRAFT;
    public const STATUS_SUBMITTED = LetterWorkflowStatus::SUBMITTED;
    public const STATUS_REVISION = LetterWorkflowStatus::REVISION;
    public const STATUS_REJECTED = LetterWorkflowStatus::REJECTED;
    public const STATUS_APPROVED_TENDIK = LetterWorkflowStatus::APPROVED_TENDIK;
    public const STATUS_APPROVED_KAPRODI = LetterWorkflowStatus::APPROVED_KAPRODI;
    public const STATUS_READY_FOR_STUDENT_REVIEW = LetterWorkflowStatus::READY_FOR_STUDENT_REVIEW;
    public const STATUS_COMPLETED = LetterWorkflowStatus::COMPLETED;
    public const LETTER_TYPE = 'surat-permohonan-beasiswa';

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
        'total_sks_required',
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
        'nomor_surat',
        'assigned_to',
        'status',
        'revision_note',
        'rejection_reason',
        'submitted_at',
        'tendik_approved_at',
        'tendik_approved_by',
        'kaprodi_approved_at',
        'kaprodi_approved_by',
        'kadep_approved_at',
        'kadep_approved_by',
        'revised_at',
        'revised_by',
        'rejected_at',
        'rejected_by',
        'student_reviewed_at',
        'completed_at',
    ];

    protected $casts = [
        'history_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'tendik_approved_at' => 'datetime',
        'kaprodi_approved_at' => 'datetime',
        'kadep_approved_at' => 'datetime',
        'revised_at' => 'datetime',
        'rejected_at' => 'datetime',
        'student_reviewed_at' => 'datetime',
        'completed_at' => 'datetime',
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

    public function kaprodiApprover()
    {
        return $this->belongsTo(User::class, 'kaprodi_approved_by');
    }

    public function kadepApprover()
    {
        return $this->belongsTo(User::class, 'kadep_approved_by');
    }

    public function tendikApprover()
    {
        return $this->belongsTo(User::class, 'tendik_approved_by');
    }

    public function reviser()
    {
        return $this->belongsTo(User::class, 'revised_by');
    }

    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
