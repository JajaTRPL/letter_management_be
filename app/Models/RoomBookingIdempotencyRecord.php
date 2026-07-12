<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomBookingIdempotencyRecord extends Model
{
    protected $fillable = [
        'actor_id',
        'actor_identity_snapshot',
        'room_booking_request_id',
        'action',
        'subject_key',
        'idempotency_key_hash',
        'payload_hash',
        'result_status_code',
        'response_schema_version',
        'safe_response_body',
        'completed_at',
        'expires_at',
    ];

    protected $casts = [
        'actor_id' => 'integer',
        'room_booking_request_id' => 'integer',
        'result_status_code' => 'integer',
        'response_schema_version' => 'integer',
        'safe_response_body' => 'array',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function booking()
    {
        return $this->belongsTo(RoomBookingRequest::class, 'room_booking_request_id');
    }
}
