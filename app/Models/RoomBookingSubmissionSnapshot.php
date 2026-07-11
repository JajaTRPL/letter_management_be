<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Immutable evidence of one authoritative submission (initial submit or
 * resubmit). Rows are write-once: the model refuses updates and deletes at
 * the application layer, and the booking FK is restrictOnDelete so cascade
 * paths cannot erase them either.
 */
class RoomBookingSubmissionSnapshot extends Model
{
    use HasFactory;

    public const PROVENANCE_NATIVE_SUBMISSION = 'native_submission';
    public const PROVENANCE_NATIVE_RESUBMISSION = 'native_resubmission';
    public const PROVENANCE_LEGACY_CURRENT_STATE = 'legacy_current_state';

    public const SCHEMA_VERSION = 1;

    protected $fillable = [
        'room_booking_request_id',
        'submission_iteration',
        'schema_version',
        'payload',
        'payload_checksum',
        'attachment_id',
        'attachment_checksum',
        'submitted_by',
        'requester_name_snapshot',
        'requester_identifier_snapshot',
        'requester_role_snapshot',
        'room_id_snapshot',
        'room_name_snapshot',
        'room_type_snapshot',
        'laboratory_id_snapshot',
        'laboratory_name_snapshot',
        'submitted_at',
        'provenance',
    ];

    protected $casts = [
        'room_booking_request_id' => 'integer',
        'submission_iteration' => 'integer',
        'schema_version' => 'integer',
        'payload' => 'array',
        'attachment_id' => 'integer',
        'submitted_by' => 'integer',
        'room_id_snapshot' => 'integer',
        'laboratory_id_snapshot' => 'integer',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Room booking submission snapshots are immutable.');
        });
        static::deleting(function (): never {
            throw new RuntimeException('Room booking submission snapshots cannot be deleted.');
        });
    }

    public function booking()
    {
        return $this->belongsTo(RoomBookingRequest::class, 'room_booking_request_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
