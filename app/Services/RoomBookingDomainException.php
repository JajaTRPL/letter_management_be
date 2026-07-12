<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class RoomBookingDomainException extends RuntimeException
{
    public const INVALID_TRANSITION = 'invalid_transition';

    public const BOOKING_CONFLICT = 'booking_conflict';

    public const UNAUTHORIZED_ACTION = 'unauthorized_action';

    public const INACTIVE_ROOM = 'inactive_room';

    public const CAPACITY_EXCEEDED = 'capacity_exceeded';

    public const INVALID_TIME_RANGE = 'invalid_time_range';

    public const CROSS_MIDNIGHT = 'cross_midnight';

    public const START_NOT_FUTURE = 'start_not_future';

    public const MISSING_LABORATORY_OWNERSHIP = 'missing_laboratory_ownership';

    public const NOTE_REQUIRED = 'note_required';

    public const REASON_REQUIRED = 'reason_required';

    public const ATTACHMENT_REQUIRED = 'attachment_required';

    public const INVALID_ATTACHMENT = 'invalid_attachment';

    public const BOOKING_START_PASSED = 'booking_start_passed';

    public const PROTECTED_BUSINESS_RECORD = 'protected_business_record';

    public const STALE_WORKFLOW_VERSION = 'stale_workflow_version';

    public const PENDING_CANCELLATION_REQUEST = 'pending_cancellation_request';

    public const REVIEW_ALREADY_STARTED = 'review_already_started';

    public const WITHDRAWAL_CUTOFF_PASSED = 'cutoff_passed';

    public const REVISION_ALREADY_REQUESTED = 'revision_already_requested';

    public const REQUIRES_CANCELLATION_REVIEW = 'requires_cancellation_review';

    public const FINAL_BOOKING_STATE = 'final_booking_state';

    public const BOOKING_EXPIRED = 'booking_expired';

    public const CANCELLATION_REQUEST_NOT_ALLOWED = 'cancellation_request_not_allowed';

    public const CANCELLATION_REQUEST_ALREADY_RESOLVED = 'cancellation_request_already_resolved';

    public const IDEMPOTENCY_KEY_REUSED = 'idempotency_key_reused';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
