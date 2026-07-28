<?php

namespace App\Models;

use App\Enums\RoomBookingReturnStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RoomBookingReturnRequest extends Model
{
    protected $guarded = [
        'id', 'status', 'version', 'active_pending_guard', 'decided_by',
        'key_received_at', 'verified_at',
    ];

    protected $hidden = [
        'evidence_disk', 'evidence_path', 'evidence_checksum_sha256',
    ];

    protected $casts = [
        'room_booking_occurrence_id' => 'integer',
        'requester_id' => 'integer',
        'supersedes_id' => 'integer',
        'status' => RoomBookingReturnStatus::class,
        'active_pending_guard' => 'boolean',
        'version' => 'integer',
        'evidence_size_bytes' => 'integer',
        'submitted_at' => 'datetime',
        'decided_by' => 'integer',
        'key_received_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function occurrence()
    {
        return $this->belongsTo(RoomBookingOccurrence::class, 'room_booking_occurrence_id');
    }

    public function supersedes()
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
