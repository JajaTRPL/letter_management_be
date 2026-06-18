<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LetterRetentionPolicy extends Model
{
    protected $fillable = [
        'scope',
        'supporting_document_retention_days',
        'intermediate_artifact_retention_days',
        'final_pdf_active_days',
        'archive_retention_days',
        'updated_by',
    ];

    protected $casts = [
        'supporting_document_retention_days' => 'integer',
        'intermediate_artifact_retention_days' => 'integer',
        'final_pdf_active_days' => 'integer',
        'archive_retention_days' => 'integer',
        'updated_by' => 'integer',
    ];
}
