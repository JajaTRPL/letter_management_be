<?php

namespace App\Models;

use App\Enums\RoomBookingCancellationStatus;
use App\Enums\RoomBookingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomBookingCancellationRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_booking_request_id',
        'requested_by',
        'requester_name_snapshot',
        'requester_role_snapshot',
        'reason',
        'status',
        'booking_status_snapshot',
        'booking_workflow_version_at_request',
        'requested_at',
        'decided_by',
        'decision_actor_name_snapshot',
        'decision_actor_role_snapshot',
        'decision_actor_scope_type',
        'decision_actor_scope_id',
        'decided_at',
        'decision_note',
        'active_pending_guard',
    ];

    protected $casts = [
        'room_booking_request_id' => 'integer',
        'requested_by' => 'integer',
        'status' => RoomBookingCancellationStatus::class,
        'booking_status_snapshot' => RoomBookingStatus::class,
        'booking_workflow_version_at_request' => 'integer',
        'requested_at' => 'datetime',
        'decided_by' => 'integer',
        'decision_actor_scope_id' => 'integer',
        'decided_at' => 'datetime',
        'active_pending_guard' => 'boolean',
    ];

    public function booking()
    {
        return $this->belongsTo(RoomBookingRequest::class, 'room_booking_request_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decisionActor()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === RoomBookingCancellationStatus::Pending
            && $this->active_pending_guard === true;
    }
}
