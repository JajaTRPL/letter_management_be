<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A single durable in-app notification owned by one recipient. Read and
 * resolved are independent lifecycle flags: reading is a UI act (never touches
 * the domain), resolving is domain-controlled (the required action no longer
 * applies). Records are written only through NotificationWriter, which enforces
 * the (recipient, dedup_key) idempotency contract.
 */
class AppNotification extends Model
{
    protected $fillable = [
        'public_id',
        'recipient_user_id',
        'recipient_role',
        'recipient_subrole',
        'recipient_scope_id',
        'event_type',
        'category',
        'priority',
        'title',
        'body',
        'subject_type',
        'subject_public_id',
        'action_route_key',
        'action_label',
        'dedup_key',
        'schema_version',
        'occurred_at',
        'read_at',
        'resolved_at',
        'expires_at',
        'superseded_by_id',
    ];

    protected $casts = [
        'recipient_user_id' => 'integer',
        'recipient_scope_id' => 'integer',
        'category' => NotificationCategory::class,
        'priority' => NotificationPriority::class,
        'schema_version' => 'integer',
        'occurred_at' => 'datetime',
        'read_at' => 'datetime',
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime',
        'superseded_by_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            $notification->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function supersededBy()
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /** Only the owner may ever see or mutate a notification (IDOR guard). */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('recipient_user_id', $userId);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Inbox ordering: unresolved urgent/action-required first, then unresolved
     * reminders, then newest updates, then resolved history — all newest-first
     * within a tier. Expressed as portable ordering columns so PostgreSQL and
     * the SQLite test lane agree.
     */
    public function scopeInboxOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN resolved_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw(
                "CASE\n"
                ."    WHEN resolved_at IS NULL AND category = 'action_required' THEN 0\n"
                ."    WHEN resolved_at IS NULL AND category = 'reminder' THEN 1\n"
                ."    WHEN resolved_at IS NULL THEN 2\n"
                ."    ELSE 3\n"
                .'END'
            )
            ->orderByRaw(
                "CASE priority\n"
                ."    WHEN 'urgent' THEN 0\n"
                ."    WHEN 'high' THEN 1\n"
                ."    WHEN 'normal' THEN 2\n"
                ."    ELSE 3\n"
                .'END'
            )
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }
}
