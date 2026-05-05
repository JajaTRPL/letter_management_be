<?php

namespace App\Models;

use App\Support\LetterWorkflowStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProsesLuarNegeriApplication extends Model
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
    public const LETTER_TYPE = 'proses-luar-negeri';

    public const STATUSES = LetterWorkflowStatus::ALL;

    protected $fillable = [
        'user_id',
        'mahasiswa_profile_id',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'semester',
        'nomor_paspor',
        'keperluan',
        'nomor_surat',
        'generated_pdf_path',
        'assigned_to',
        'status',
        'revision_note',
        'rejection_reason',
        'submitted_at',
        'tendik_approved_at',
        'kaprodi_approved_at',
        'kadep_approved_at',
        'student_reviewed_at',
        'completed_at',
    ];

    protected $casts = [
        'tanggal_lahir' => 'date',
        'submitted_at' => 'datetime',
        'tendik_approved_at' => 'datetime',
        'kaprodi_approved_at' => 'datetime',
        'kadep_approved_at' => 'datetime',
        'student_reviewed_at' => 'datetime',
        'completed_at' => 'datetime',
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
