<?php

namespace App\Models;

use App\Enums\RoomBookingStatus;
use Illuminate\Database\Eloquent\Model;

class RoomBookingStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'room_booking_request_id',
        'from_status',
        'to_status',
        'actor_id',
        'note',
    ];

    protected $casts = [
        'room_booking_request_id' => 'integer',
        'from_status' => RoomBookingStatus::class,
        'to_status' => RoomBookingStatus::class,
        'actor_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function roomBookingRequest()
    {
        return $this->belongsTo(RoomBookingRequest::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
