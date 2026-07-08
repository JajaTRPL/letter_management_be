<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomAuditLog extends Model
{
    public const SUBJECT_ROOM = 'room';
    public const SUBJECT_PHOTO = 'photo';
    public const SUBJECT_FACILITY = 'facility';
    public const SUBJECT_TEMPLATE = 'template';

    public const SUBJECTS = [
        self::SUBJECT_ROOM,
        self::SUBJECT_PHOTO,
        self::SUBJECT_FACILITY,
        self::SUBJECT_TEMPLATE,
    ];

    public $timestamps = false;

    protected $fillable = [
        'room_id',
        'laboratory_id',
        'subject_type',
        'subject_id',
        'action',
        'actor_id',
        'details',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'room_id' => 'integer',
        'laboratory_id' => 'integer',
        'subject_id' => 'integer',
        'actor_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
