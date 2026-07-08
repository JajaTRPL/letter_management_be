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
        'automation_enabled',
        'automation_updated_by',
        'automation_enabled_at',
        'automation_disabled_at',
    ];

    protected $casts = [
        'supporting_document_retention_days' => 'integer',
        'intermediate_artifact_retention_days' => 'integer',
        'final_pdf_active_days' => 'integer',
        'archive_retention_days' => 'integer',
        'updated_by' => 'integer',
        'automation_enabled' => 'boolean',
        'automation_updated_by' => 'integer',
        'automation_enabled_at' => 'datetime',
        'automation_disabled_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'last_run_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    public function automationUpdatedBy()
    {
        return $this->belongsTo(User::class, 'automation_updated_by');
    }
}
