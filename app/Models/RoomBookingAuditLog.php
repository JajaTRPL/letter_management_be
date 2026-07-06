<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomBookingAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'room_booking_request_id',
        'room_booking_attachment_id',
        'actor_id',
        'action',
        'document_type',
        'original_name',
        'size_bytes',
        'checksum_sha256',
        'storage_path_hash',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'room_booking_request_id' => 'integer',
        'room_booking_attachment_id' => 'integer',
        'actor_id' => 'integer',
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    public function roomBookingRequest(): BelongsTo
    {
        return $this->belongsTo(RoomBookingRequest::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(RoomBookingAttachment::class, 'room_booking_attachment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
