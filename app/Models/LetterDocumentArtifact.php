<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LetterDocumentArtifact extends Model
{
    use HasFactory;

    public const STATUS_GENERATING = 'generating';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    public const PHASE_TENDIK_REVIEW = 'tendik_review';
    public const PHASE_PRODI_REVIEW = 'prodi_review';
    public const PHASE_DEPARTEMEN_REVIEW = 'departemen_review';
    public const PHASE_MAHASISWA_REVIEW = 'mahasiswa_review';

    public const PHASES = [
        self::PHASE_TENDIK_REVIEW,
        self::PHASE_PRODI_REVIEW,
        self::PHASE_DEPARTEMEN_REVIEW,
        self::PHASE_MAHASISWA_REVIEW,
    ];

    public const STATUSES = [
        self::STATUS_GENERATING,
        self::STATUS_READY,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'letter_type',
        'application_id',
        'phase',
        'version',
        'docx_path',
        'pdf_path',
        'source_hash',
        'status',
        'error_message',
        'generated_by',
        'generated_at',
    ];

    protected $casts = [
        'application_id' => 'integer',
        'version' => 'integer',
        'generated_by' => 'integer',
        'generated_at' => 'datetime',
    ];

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function scopeForApplication(Builder $query, string $letterType, int $applicationId): Builder
    {
        return $query
            ->where('letter_type', $letterType)
            ->where('application_id', $applicationId);
    }

    public function scopeForPhase(Builder $query, string $phase): Builder
    {
        return $query->where('phase', $phase);
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY);
    }
}
