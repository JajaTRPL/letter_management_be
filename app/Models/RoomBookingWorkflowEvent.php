<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Append-only workflow ledger for room bookings. One row per successful
 * business transition; rows are never updated or deleted through the
 * application, and actor snapshots keep the evidence readable after account
 * deactivation or deletion.
 */
class RoomBookingWorkflowEvent extends Model
{
    use HasFactory;

    public const EVENT_BOOKING_SUBMITTED = 'booking_submitted';

    public const EVENT_REVISION_REQUESTED = 'revision_requested';

    public const EVENT_BOOKING_RESUBMITTED = 'booking_resubmitted';

    public const EVENT_BOOKING_APPROVED = 'booking_approved';

    public const EVENT_BOOKING_REJECTED = 'booking_rejected';

    public const EVENT_BOOKING_CANCELLED = 'booking_cancelled';

    public const EVENT_LEGACY_BASELINE_IMPORTED = 'legacy_baseline_imported';

    public const EVENT_REVIEW_STARTED = 'review_started';

    public const EVENT_BOOKING_WITHDRAWN = 'booking_withdrawn';

    public const EVENT_CANCELLATION_REQUESTED = 'cancellation_requested';

    public const EVENT_CANCELLATION_REQUEST_WITHDRAWN = 'cancellation_request_withdrawn';

    public const EVENT_CANCELLATION_APPROVED = 'cancellation_approved';

    public const EVENT_CANCELLATION_REJECTED = 'cancellation_rejected';

    public const EVENT_OCCURRENCE_CREATED = 'occurrence_created';
    public const EVENT_KEY_ISSUED = 'key_issued';
    public const EVENT_USAGE_STARTED = 'usage_started';
    public const EVENT_USAGE_ENDED = 'usage_ended';
    public const EVENT_RETURN_DUE = 'return_due';
    public const EVENT_RETURN_SUBMITTED = 'return_submitted';
    public const EVENT_RETURN_RESUBMITTED = 'return_resubmitted';
    public const EVENT_RETURN_REVISION_REQUESTED = 'return_revision_requested';
    public const EVENT_RETURN_ACCEPTED = 'return_accepted';
    public const EVENT_RETURN_REJECTED = 'return_rejected';
    public const EVENT_RETURN_WITHDRAWN = 'return_withdrawn';
    public const EVENT_RETURN_OVERDUE = 'return_overdue';
    public const EVENT_KEY_RECEIVED_TIME_ADJUSTED = 'key_received_time_adjusted';

    public const EVENT_TYPES = [
        self::EVENT_BOOKING_SUBMITTED,
        self::EVENT_REVISION_REQUESTED,
        self::EVENT_BOOKING_RESUBMITTED,
        self::EVENT_BOOKING_APPROVED,
        self::EVENT_BOOKING_REJECTED,
        self::EVENT_BOOKING_CANCELLED,
        self::EVENT_LEGACY_BASELINE_IMPORTED,
        self::EVENT_REVIEW_STARTED,
        self::EVENT_BOOKING_WITHDRAWN,
        self::EVENT_CANCELLATION_REQUESTED,
        self::EVENT_CANCELLATION_REQUEST_WITHDRAWN,
        self::EVENT_CANCELLATION_APPROVED,
        self::EVENT_CANCELLATION_REJECTED,
        self::EVENT_OCCURRENCE_CREATED,
        self::EVENT_KEY_ISSUED,
        self::EVENT_USAGE_STARTED,
        self::EVENT_USAGE_ENDED,
        self::EVENT_RETURN_DUE,
        self::EVENT_RETURN_SUBMITTED,
        self::EVENT_RETURN_RESUBMITTED,
        self::EVENT_RETURN_REVISION_REQUESTED,
        self::EVENT_RETURN_ACCEPTED,
        self::EVENT_RETURN_REJECTED,
        self::EVENT_RETURN_WITHDRAWN,
        self::EVENT_RETURN_OVERDUE,
        self::EVENT_KEY_RECEIVED_TIME_ADJUSTED,
    ];

    protected $fillable = [
        'room_booking_request_id',
        'room_booking_occurrence_id',
        'event_type',
        'actor_id',
        'actor_name_snapshot',
        'actor_role_snapshot',
        'actor_subrole_snapshot',
        'actor_scope_type',
        'actor_scope_id',
        'recipient_user_id',
        'recipient_role',
        'previous_status',
        'resulting_status',
        'workflow_version_before',
        'workflow_version_after',
        'submission_iteration',
        'public_note',
        'internal_note',
        'safe_metadata',
        'correlation_id',
        'occurred_at',
    ];

    protected $casts = [
        'room_booking_request_id' => 'integer',
        'room_booking_occurrence_id' => 'integer',
        'actor_id' => 'integer',
        'actor_scope_id' => 'integer',
        'recipient_user_id' => 'integer',
        'workflow_version_before' => 'integer',
        'workflow_version_after' => 'integer',
        'submission_iteration' => 'integer',
        'safe_metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Room booking workflow events are immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Room booking workflow events cannot be deleted.');
        });
    }

    public function booking()
    {
        return $this->belongsTo(RoomBookingRequest::class, 'room_booking_request_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function occurrence()
    {
        return $this->belongsTo(RoomBookingOccurrence::class, 'room_booking_occurrence_id');
    }
}
