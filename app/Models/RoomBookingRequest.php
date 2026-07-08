<?php

namespace App\Models;

use App\Enums\RoomBookingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomBookingRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'requester_id',
        'room_id',
        'activity_name',
        'purpose',
        'participant_count',
        'start_at',
        'end_at',
        'status',
        'reviewer_id',
        'reviewed_at',
        'revision_note',
        'rejection_reason',
        'cancellation_reason',
    ];

    protected $casts = [
        'requester_id' => 'integer',
        'room_id' => 'integer',
        'participant_count' => 'integer',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'status' => RoomBookingStatus::class,
        'reviewer_id' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function statusHistories()
    {
        return $this->hasMany(RoomBookingStatusHistory::class);
    }

    public function attachments()
    {
        return $this->hasMany(RoomBookingAttachment::class);
    }

    public function suratPeminjamanAttachment()
    {
        return $this->hasOne(RoomBookingAttachment::class)
            ->where('document_type', RoomBookingAttachment::DOCUMENT_SURAT_PEMINJAMAN);
    }
}
