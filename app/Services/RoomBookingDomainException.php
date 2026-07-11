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

    public const BOOKING_START_PASSED = 'booking_start_passed';

    public const PROTECTED_BUSINESS_RECORD = 'protected_business_record';

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
