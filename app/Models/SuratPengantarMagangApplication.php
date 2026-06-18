<?php

namespace App\Models;

use App\Support\LetterWorkflowStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SuratPengantarMagangApplication extends Model
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
    public const LETTER_TYPE = 'surat-pengantar-magang';

    public const STATUSES = LetterWorkflowStatus::ALL;

    protected $fillable = [
        'user_id',
        'mahasiswa_profile_id',
        'nama_penerima',
        'jabatan_penerima',
        'nama_perusahaan',
        'alamat_perusahaan',
        'alamat_jalan',
        'alamat_kelurahan',
        'alamat_kecamatan',
        'alamat_kota_kabupaten',
        'alamat_provinsi',
        'kode_pos',
        'peran',
        'rentang_tanggal',
        'tgl_mulai',
        'tgl_selesai',
        'dosen_pembimbing_dpa',
        'nomor_surat',
        'nomor_surat_pengantar',
        'nomor_surat_tugas',
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
        'submitted_at' => 'datetime',
        'tgl_mulai' => 'date',
        'tgl_selesai' => 'date',
        'tendik_approved_at' => 'datetime',
        'kaprodi_approved_at' => 'datetime',
        'kadep_approved_at' => 'datetime',
        'revised_at' => 'datetime',
        'rejected_at' => 'datetime',
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
