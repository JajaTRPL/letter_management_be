<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LetterRetentionAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'action_key',
        'letter_type',
        'application_id',
        'subject_type',
        'subject_id',
        'category',
        'action',
        'status',
        'storage_disk',
        'storage_path_hash',
        'checksum_sha256',
        'eligible_at',
        'executed_at',
        'error_code',
        'manifest_reference',
        'metadata',
    ];

    protected $casts = [
        'application_id' => 'integer',
        'subject_id' => 'integer',
        'eligible_at' => 'datetime',
        'executed_at' => 'datetime',
        'metadata' => 'array',
    ];
}
