<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomBookingAttachment extends Model
{
    public const DOCUMENT_SURAT_PEMINJAMAN = 'surat_peminjaman';

    protected $fillable = [
        'room_booking_request_id',
        'document_type',
        'original_name',
        'mime_type',
        'size_bytes',
        'storage_disk',
        'storage_path',
        'checksum_sha256',
        'uploaded_by',
    ];

    protected $casts = [
        'room_booking_request_id' => 'integer',
        'size_bytes' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function roomBookingRequest(): BelongsTo
    {
        return $this->belongsTo(RoomBookingRequest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(RoomBookingAuditLog::class);
    }
}
