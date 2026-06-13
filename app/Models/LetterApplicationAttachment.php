<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LetterApplicationAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'letter_type',
        'application_id',
        'document_key',
        'original_filename',
        'mime_type',
        'size_bytes',
        'storage_disk',
        'storage_path',
        'checksum_sha256',
        'uploaded_by',
        'retention_deleted_at',
        'retention_status',
        'retention_manifest_path',
    ];

    protected $casts = [
        'application_id' => 'integer',
        'size_bytes' => 'integer',
        'uploaded_by' => 'integer',
        'retention_deleted_at' => 'datetime',
    ];

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
