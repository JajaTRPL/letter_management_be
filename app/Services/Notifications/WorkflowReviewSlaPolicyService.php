<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\WorkflowReviewSlaPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves and mutates the SuperAdmin-governed review-SLA policy per workflow
 * domain (scope). Single source of truth for "is review-SLA active, and what are
 * its thresholds" — the scanner reads it, the SuperAdmin surface writes through
 * it. Safe by construction: a missing row / uninstalled schema resolves to the
 * config defaults (which ship DISABLED), so nothing is emitted until a
 * SuperAdmin explicitly enables it.
 */
class WorkflowReviewSlaPolicyService
{
    public const SCOPE_ROOM_BOOKING = 'room_booking';

    public const SCOPE_LETTER = 'letter';

    // Canonical review-SLA notification event types, shared by every scope's
    // scanner and by the projector resolution, so "a review obligation was
    // resolved" means the same thing across domains.
    public const EVENT_WARNING = 'review_sla_warning';

    public const EVENT_OVERDUE = 'review_sla_overdue';

    public const EVENT_ESCALATION = 'review_sla_escalation';

    /** @var list<string> */
    public const EVENT_TYPES = [self::EVENT_WARNING, self::EVENT_OVERDUE, self::EVENT_ESCALATION];

    /** Absolute guard rails (minutes) independent of any per-scope policy. */
    public const MIN_MINUTES = 5;

    public const MAX_MINUTES = 30 * 24 * 60; // 30 days

    /** @return list<string> */
    public static function scopes(): array
    {
        return [self::SCOPE_ROOM_BOOKING, self::SCOPE_LETTER];
    }

    /** @return array{enabled:bool,warning_minutes:int,overdue_minutes:int,escalation_minutes:int} */
    public function defaults(): array
    {
        return [
            'enabled' => (bool) config('notifications.review_sla.enabled', false),
            'warning_minutes' => (int) config('notifications.review_sla.warning_minutes', 24 * 60),
            'overdue_minutes' => (int) config('notifications.review_sla.overdue_minutes', 2 * 24 * 60),
            'escalation_minutes' => (int) config('notifications.review_sla.escalation_minutes', 3 * 24 * 60),
        ];
    }

    /**
     * The effective policy for a scope: the persisted row when present, else the
     * config defaults. Never throws — a resolver on the scanner hot path must
     * degrade to "disabled defaults" rather than fail a scheduled run.
     *
     * @return array{enabled:bool,warning_minutes:int,overdue_minutes:int,escalation_minutes:int}
     */
    public function current(string $scope): array
    {
        $defaults = $this->defaults();
        if (! $this->schemaReady()) {
            return $defaults;
        }

        $policy = WorkflowReviewSlaPolicy::query()->where('scope', $scope)->first();
        if (! $policy) {
            return $defaults;
        }

        return [
            'enabled' => (bool) $policy->enabled,
            'warning_minutes' => (int) $policy->warning_minutes,
            'overdue_minutes' => (int) $policy->overdue_minutes,
            'escalation_minutes' => (int) $policy->escalation_minutes,
        ];
    }

    /** The persisted row (or a fresh default instance) for the SuperAdmin surface. */
    public function policyModel(string $scope): WorkflowReviewSlaPolicy
    {
        if (! $this->schemaReady()) {
            throw new RuntimeException('workflow_review_sla_schema_not_ready');
        }

        return WorkflowReviewSlaPolicy::query()->firstOrNew(
            ['scope' => $scope],
            $this->defaults(),
        );
    }

    /**
     * Persist a validated policy change. Ordering + bounds are asserted here as
     * defense-in-depth even though the controller validates first, so no caller
     * can write an impossible policy. Toggling enabled records who/when for the
     * higher-impact governance action separately from threshold edits.
     *
     * @param  array{enabled?:bool,warning_minutes?:int,overdue_minutes?:int,escalation_minutes?:int}  $values
     */
    public function update(string $scope, array $values, User $actor): WorkflowReviewSlaPolicy
    {
        if (! $this->schemaReady()) {
            throw new RuntimeException('workflow_review_sla_schema_not_ready');
        }

        $policy = $this->policyModel($scope);
        $wasEnabled = (bool) ($policy->exists ? $policy->enabled : false);

        $merged = array_merge([
            'enabled' => $policy->enabled ?? false,
            'warning_minutes' => $policy->warning_minutes,
            'overdue_minutes' => $policy->overdue_minutes,
            'escalation_minutes' => $policy->escalation_minutes,
        ], $values);

        $this->assertValid($merged);

        $policy->fill([
            'scope' => $scope,
            'enabled' => (bool) $merged['enabled'],
            'warning_minutes' => (int) $merged['warning_minutes'],
            'overdue_minutes' => (int) $merged['overdue_minutes'],
            'escalation_minutes' => (int) $merged['escalation_minutes'],
            'updated_by' => $actor->id,
        ]);

        if ((bool) $merged['enabled'] !== $wasEnabled) {
            $now = Carbon::now(config('app.timezone'));
            $policy->enabled_updated_by = $actor->id;
            if ((bool) $merged['enabled']) {
                $policy->enabled_at = $now;
            } else {
                $policy->disabled_at = $now;
            }
        }

        $policy->save();

        return $policy->refresh();
    }

    /** @param array{enabled?:bool,warning_minutes:int,overdue_minutes:int,escalation_minutes:int} $values */
    public function assertValid(array $values): void
    {
        $warning = (int) $values['warning_minutes'];
        $overdue = (int) $values['overdue_minutes'];
        $escalation = (int) $values['escalation_minutes'];

        $labels = [
            'warning' => 'waktu mulai diingatkan',
            'overdue' => 'waktu dianggap terlambat',
            'escalation' => 'waktu naik ke SuperAdmin',
        ];
        foreach (['warning' => $warning, 'overdue' => $overdue, 'escalation' => $escalation] as $key => $minutes) {
            if ($minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES) {
                throw new InvalidArgumentException("Nilai {$labels[$key]} di luar batas yang diizinkan.");
            }
        }

        if (! ($warning <= $overdue && $overdue <= $escalation)) {
            throw new InvalidArgumentException(
                'Urutan waktu belum tepat: waktu mulai diingatkan harus paling awal, lalu waktu dianggap terlambat, lalu waktu naik ke SuperAdmin.',
            );
        }
    }

    public function schemaReady(): bool
    {
        return Schema::hasTable('workflow_review_sla_policies')
            && Schema::hasColumn('workflow_review_sla_policies', 'enabled')
            && Schema::hasColumn('workflow_review_sla_policies', 'warning_minutes');
    }
}
