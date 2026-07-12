<?php

namespace App\Models;

use App\Enums\RoomBookingCancellationStatus;
use App\Enums\RoomBookingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomBookingRequest extends Model
{
    use HasFactory;

    /**
     * Derived (never stored) effective statuses. The stored status column
     * keeps the original five values; these are read projections only.
     */
    public const EFFECTIVE_STATUS_COMPLETED = 'completed';

    public const EFFECTIVE_STATUS_EXPIRED = 'expired';

    public const EFFECTIVE_STATUS_UNDER_REVIEW = 'under_review';

    /**
     * workflow_version and submission_iteration are deliberately NOT
     * mass-assignable: they are server-owned lifecycle fields written only
     * via forceFill/direct assignment inside trusted domain services
     * (RoomBookingTransitionService).
     */
    protected $fillable = [
        'requester_id',
        'room_id',
        'activity_name',
        'purpose',
        'participant_count',
        'start_at',
        'end_at',
    ];

    protected $casts = [
        'requester_id' => 'integer',
        'room_id' => 'integer',
        'participant_count' => 'integer',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'status' => RoomBookingStatus::class,
        'workflow_version' => 'integer',
        'submission_iteration' => 'integer',
        'review_started_at' => 'datetime',
        'review_started_by' => 'integer',
        'reviewer_id' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /** Approved and the activity window has ended. */
    public function isCompleted(): bool
    {
        return $this->status === RoomBookingStatus::Approved
            && $this->end_at !== null
            && $this->end_at->lessThanOrEqualTo(now(config('app.timezone')));
    }

    /** Still pending a decision while the activity start has already passed. */
    public function isExpired(): bool
    {
        return in_array($this->status, [
            RoomBookingStatus::Submitted,
            RoomBookingStatus::RevisionRequested,
        ], true)
            && $this->start_at !== null
            && $this->start_at->lessThanOrEqualTo(now(config('app.timezone')));
    }

    public function effectiveStatus(): string
    {
        if ($this->isCompleted()) {
            return self::EFFECTIVE_STATUS_COMPLETED;
        }

        if ($this->isExpired()) {
            return self::EFFECTIVE_STATUS_EXPIRED;
        }

        if (
            $this->status === RoomBookingStatus::Submitted
            && $this->review_started_at !== null
        ) {
            return self::EFFECTIVE_STATUS_UNDER_REVIEW;
        }

        return $this->status->value;
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function reviewStartedBy()
    {
        return $this->belongsTo(User::class, 'review_started_by');
    }

    public function statusHistories()
    {
        return $this->hasMany(RoomBookingStatusHistory::class);
    }

    public function attachments()
    {
        return $this->hasMany(RoomBookingAttachment::class);
    }

    public function suratPeminjamanAttachment()
    {
        return $this->hasOne(RoomBookingAttachment::class)
            ->where('document_type', RoomBookingAttachment::DOCUMENT_SURAT_PEMINJAMAN);
    }

    public function submissionSnapshots()
    {
        return $this->hasMany(RoomBookingSubmissionSnapshot::class);
    }

    public function workflowEvents()
    {
        return $this->hasMany(RoomBookingWorkflowEvent::class);
    }

    public function cancellationRequests()
    {
        return $this->hasMany(RoomBookingCancellationRequest::class);
    }

    public function activeCancellationRequest()
    {
        return $this->hasOne(RoomBookingCancellationRequest::class)
            ->where('status', RoomBookingCancellationStatus::Pending->value)
            ->where('active_pending_guard', true);
    }

    public function revisionRequestHistory()
    {
        return $this->hasOne(RoomBookingStatusHistory::class)
            ->where('to_status', RoomBookingStatus::RevisionRequested->value)
            ->oldestOfMany('created_at');
    }

    public function hasRevisionBeenRequested(): bool
    {
        if ($this->relationLoaded('revisionRequestHistory')) {
            return $this->revisionRequestHistory !== null;
        }

        return $this->revisionRequestHistory()->exists();
    }

    public function hasPendingCancellationRequest(): bool
    {
        if ($this->relationLoaded('activeCancellationRequest')) {
            return $this->activeCancellationRequest !== null;
        }

        return $this->activeCancellationRequest()->exists();
    }
}
