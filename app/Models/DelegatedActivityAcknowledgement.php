<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DelegatedActivityAcknowledgement extends Model
{
    use HasFactory;

    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_ESCALATED,
        self::STATUS_VOIDED,
    ];

    public const URGENCY_URGENT = 'urgent';
    public const URGENCY_NORMAL = 'normal';
    public const URGENCY_LOW_RISK = 'low_risk';

    public const URGENCIES = [
        self::URGENCY_URGENT,
        self::URGENCY_NORMAL,
        self::URGENCY_LOW_RISK,
    ];

    public const EFFECTIVE_STATUS_OVERDUE = 'overdue';

    protected $fillable = [
        'domain_type',
        'subject_type',
        'subject_id',
        'idempotency_key',
        'delegated_actor_id',
        'accountable_user_id',
        'accountable_role',
        'represented_scope_type',
        'represented_scope_id',
        'activity_type',
        'activity_summary',
        'internal_note',
        'student_facing_note',
        'before_state',
        'after_state',
        'status',
        'urgency',
        'performed_at',
        'acknowledgement_due_at',
        'acknowledged_at',
        'acknowledged_by',
        'acknowledgement_note',
        'escalated_at',
        'escalation_seen_by_superadmin_at',
    ];

    protected $casts = [
        'subject_id' => 'integer',
        'delegated_actor_id' => 'integer',
        'accountable_user_id' => 'integer',
        'represented_scope_id' => 'integer',
        'before_state' => 'array',
        'after_state' => 'array',
        'performed_at' => 'datetime',
        'acknowledgement_due_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'acknowledged_by' => 'integer',
        'escalated_at' => 'datetime',
        'escalation_seen_by_superadmin_at' => 'datetime',
    ];

    public function delegatedActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_actor_id');
    }

    public function accountableUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accountable_user_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PENDING_REVIEW
            && $this->acknowledgement_due_at !== null
            && $this->acknowledgement_due_at->lt(now(config('app.timezone')));
    }

    public function effectiveStatus(): string
    {
        return $this->isOverdue() ? self::EFFECTIVE_STATUS_OVERDUE : $this->status;
    }

    public function canBeAcknowledged(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_REVIEW,
            self::STATUS_ESCALATED,
        ], true);
    }

    /**
     * A task warrants SuperAdmin attention only when the normal review chain
     * has stalled: it was explicitly escalated, or the pending review is
     * overdue. Single source of truth for the mark-escalation-seen
     * permission (API resource flag AND service guard).
     */
    public function needsSuperAdminEscalationAttention(): bool
    {
        return $this->status === self::STATUS_ESCALATED || $this->isOverdue();
    }

    public function overdueHours(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return max(0, (int) floor($this->acknowledgement_due_at->diffInHours(now(config('app.timezone')))));
    }

    public function overdueDays(): int
    {
        $hours = $this->overdueHours();

        return $hours > 0 ? (int) ceil($hours / 24) : 0;
    }

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_REVIEW);
    }

    public function scopeAcknowledged(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACKNOWLEDGED);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->pendingReview()
            ->whereNotNull('acknowledgement_due_at')
            ->where('acknowledgement_due_at', '<', now(config('app.timezone')));
    }

    public function scopeForAccountableUser(Builder $query, User|int $user): Builder
    {
        return $query->where('accountable_user_id', $user instanceof User ? $user->id : $user);
    }

    public function scopeForRepresentedScope(Builder $query, string $scopeType, int $scopeId): Builder
    {
        return $query
            ->where('represented_scope_type', $scopeType)
            ->where('represented_scope_id', $scopeId);
    }

    public function scopeForDomain(Builder $query, string $domainType): Builder
    {
        return $query->where('domain_type', $domainType);
    }

    public function scopeForSubject(Builder $query, string $subjectType, int $subjectId): Builder
    {
        return $query
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId);
    }

    public function scopeVisibleToSuperAdmin(Builder $query): Builder
    {
        return $query;
    }
}
