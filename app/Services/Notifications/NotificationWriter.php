<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one and only write path for durable notifications. Guarantees:
 *
 * - idempotency: (recipient_user_id, dedup_key) is unique, so replaying the
 *   same event or re-running the scheduler never produces duplicates;
 * - route-key safety: an intent carrying a non-allowlisted action route is
 *   rejected before any write;
 * - independent read/resolved lifecycle;
 * - domain-controlled resolution + supersession, queryable history preserved.
 *
 * The writer NEVER throws for a duplicate; callers (the projector) additionally
 * wrap invocation so a notification failure can never roll back or 500 the
 * authoritative workflow mutation.
 */
class NotificationWriter
{
    /**
     * Write (or idempotently reuse) a notification for the intent. Returns the
     * persisted row. A row already existing for (recipient, dedup_key) is
     * returned unchanged — the second processing is a no-op.
     */
    public function write(NotificationIntent $intent): AppNotification
    {
        if (! NotificationActionRoute::isAllowed($intent->actionRouteKey)) {
            throw new \InvalidArgumentException(
                "Disallowed notification action route: {$intent->actionRouteKey}",
            );
        }

        $now = Carbon::now(config('app.timezone'));

        $notification = DB::transaction(function () use ($intent, $now): AppNotification {
            $existing = AppNotification::query()
                ->where('recipient_user_id', $intent->recipient->id)
                ->where('dedup_key', $intent->dedupKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            // A newer notification supersedes older unresolved ones for the same
            // subject-action family (e.g. a fresh revision request supersedes the
            // stale one) — history stays queryable via superseded_by_id.
            $superseded = $this->supersede($intent, $now);

            $notification = AppNotification::create([
                'recipient_user_id' => $intent->recipient->id,
                'recipient_role' => (string) $intent->recipient->role,
                'recipient_subrole' => $intent->recipient->tendik_role
                    ?? $intent->recipient->sub_role,
                'recipient_scope_id' => $intent->recipient->laboratory_id,
                'event_type' => $intent->eventType,
                'category' => $intent->category,
                'priority' => $intent->priority,
                'title' => $intent->title,
                'body' => $intent->body,
                'subject_type' => $intent->subjectType,
                'subject_public_id' => $intent->subjectPublicId,
                'action_route_key' => $intent->actionRouteKey,
                'action_label' => $intent->actionLabel,
                'dedup_key' => $intent->dedupKey,
                'schema_version' => $intent->schemaVersion,
                'occurred_at' => $intent->occurredAt ?? $now,
                'expires_at' => $intent->expiresAt,
            ]);

            foreach ($superseded as $old) {
                $old->forceFill([
                    'superseded_by_id' => $notification->id,
                    'resolved_at' => $old->resolved_at ?? $now,
                ])->save();
            }

            return $notification;
        });

        // Unified email delivery: only a freshly-created row (never a dedup
        // reuse) queues an email, so replays/re-scans never re-send. The
        // mailable is `afterCommit`, so nothing is sent if an enclosing workflow
        // transaction rolls back. Best-effort — failure never bubbles.
        if ($notification->wasRecentlyCreated) {
            app(NotificationMailDeliverer::class)->deliver($notification);
        }

        return $notification;
    }

    /**
     * Resolve every unresolved notification for a subject whose dedup key starts
     * with one of the given prefixes. This is how a domain transition retires
     * the action it satisfied (revision resolved by resubmission, review by
     * decision, return-due by acceptance, overdue by acceptance/cancellation).
     *
     * @param  list<string>  $dedupPrefixes
     * @return int number of notifications resolved
     */
    public function resolveByDedupPrefix(
        int $recipientUserId,
        array $dedupPrefixes,
        ?Carbon $at = null,
    ): int {
        if ($dedupPrefixes === []) {
            return 0;
        }
        $at ??= Carbon::now(config('app.timezone'));

        return AppNotification::query()
            ->where('recipient_user_id', $recipientUserId)
            ->whereNull('resolved_at')
            ->where(function ($query) use ($dedupPrefixes): void {
                foreach ($dedupPrefixes as $prefix) {
                    $query->orWhere('dedup_key', 'like', $prefix.'%');
                }
            })
            ->update(['resolved_at' => $at]);
    }

    /**
     * Resolve every unresolved notification attached to a subject, optionally
     * limited to a set of event types. Used when a subject reaches a terminal
     * state (booking cancelled/rejected) and all its pending actions vanish.
     *
     * @param  list<string>  $eventTypes
     */
    public function resolveBySubject(
        string $subjectType,
        string $subjectPublicId,
        array $eventTypes = [],
        ?Carbon $at = null,
    ): int {
        $at ??= Carbon::now(config('app.timezone'));

        $query = AppNotification::query()
            ->where('subject_type', $subjectType)
            ->where('subject_public_id', $subjectPublicId)
            ->whereNull('resolved_at');
        if ($eventTypes !== []) {
            $query->whereIn('event_type', $eventTypes);
        }

        return $query->update(['resolved_at' => $at]);
    }

    /**
     * @return Collection<int, AppNotification>
     */
    private function supersede(NotificationIntent $intent, Carbon $now)
    {
        if ($intent->supersedesDedupPrefixes === []) {
            return collect();
        }

        return AppNotification::query()
            ->where('recipient_user_id', $intent->recipient->id)
            ->whereNull('resolved_at')
            ->whereNull('superseded_by_id')
            ->where(function ($query) use ($intent): void {
                foreach ($intent->supersedesDedupPrefixes as $prefix) {
                    $query->orWhere('dedup_key', 'like', $prefix.'%');
                }
            })
            ->lockForUpdate()
            ->get();
    }
}
