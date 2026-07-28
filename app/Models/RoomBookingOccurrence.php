<?php

namespace App\Models;

use App\Enums\RoomBookingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RoomBookingOccurrence extends Model
{
    protected $guarded = ['id', 'version', 'key_issued_at', 'key_issued_by'];

    protected $casts = [
        'room_booking_request_id' => 'integer',
        'sequence' => 'integer',
        'occurrence_date' => 'date:Y-m-d',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'return_due_at' => 'datetime',
        'version' => 'integer',
        'key_issued_at' => 'datetime',
        'key_issued_by' => 'integer',
    ];

    public function booking()
    {
        return $this->belongsTo(RoomBookingRequest::class, 'room_booking_request_id');
    }

    public function returnRequests()
    {
        return $this->hasMany(RoomBookingReturnRequest::class)->orderBy('submitted_at');
    }

    public function activeReturnRequest()
    {
        return $this->hasOne(RoomBookingReturnRequest::class)
            ->where('active_pending_guard', true);
    }

    public function acceptedReturnRequest()
    {
        return $this->hasOne(RoomBookingReturnRequest::class)
            ->where('status', 'accepted')->latestOfMany('verified_at');
    }

    /**
     * Operational eligibility: an occurrence is only actionable while its parent
     * booking is stored-status `approved`. Occurrences exist for every booking —
     * they are written at submission and backfilled for legacy rows — so
     * submitted/revision_requested/rejected/cancelled bookings all own occurrence
     * rows. Those rows stay for compatibility and audit, but they must never
     * reach an operational queue (Hari Ini, Penyerahan Kunci, Pengembalian,
     * Terlambat, Semua).
     */
    public function scopeOperationallyActionable(Builder $query): Builder
    {
        return $query->whereHas(
            'booking',
            fn (Builder $booking) => $booking->where('status', RoomBookingStatus::Approved->value),
        );
    }

    protected static function booted(): void
    {
        static::creating(function (self $occurrence): void {
            $occurrence->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
