<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SuperAdmin-governed review-SLA policy per workflow domain (scope). See the
 * create migration for the governance contract; thresholds are validated and
 * ordered by WorkflowReviewSlaPolicyService before they ever reach here.
 */
class WorkflowReviewSlaPolicy extends Model
{
    protected $fillable = [
        'scope',
        'enabled',
        'warning_minutes',
        'overdue_minutes',
        'escalation_minutes',
        'updated_by',
        'enabled_updated_by',
        'enabled_at',
        'disabled_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'warning_minutes' => 'integer',
        'overdue_minutes' => 'integer',
        'escalation_minutes' => 'integer',
        'updated_by' => 'integer',
        'enabled_updated_by' => 'integer',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function enabledUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_updated_by');
    }
}
