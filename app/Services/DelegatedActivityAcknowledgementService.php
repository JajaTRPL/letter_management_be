<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DelegatedActivityAcknowledgement;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DelegatedActivityAcknowledgementService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createTask(array $payload): DelegatedActivityAcknowledgement
    {
        return $this->createTaskWithOutcome($payload)['acknowledgement'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{outcome: 'created'|'existing', acknowledgement: DelegatedActivityAcknowledgement}
     */
    public function createTaskWithOutcome(array $payload): array
    {
        $validated = $this->validateCreatePayload($payload);

        $idempotencyKey = $this->blankToNull($validated['idempotency_key'] ?? null);
        if ($idempotencyKey !== null) {
            $existing = DelegatedActivityAcknowledgement::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return [
                    'outcome' => 'existing',
                    'acknowledgement' => $existing->loadMissing(['delegatedActor', 'accountableUser', 'acknowledgedBy']),
                ];
            }
        }

        $performedAt = $this->carbon($validated['performed_at'] ?? now(config('app.timezone')));
        $urgency = $validated['urgency'] ?? config('delegated_acknowledgements.sla.default_urgency', DelegatedActivityAcknowledgement::URGENCY_NORMAL);
        $dueAt = array_key_exists('acknowledgement_due_at', $validated)
            ? $this->nullableCarbon($validated['acknowledgement_due_at'])
            : $this->calculateDueAt((string) $validated['activity_type'], (string) $urgency, $performedAt);

        try {
            $task = DB::transaction(function () use ($validated, $idempotencyKey, $performedAt, $urgency, $dueAt) {
                $task = DelegatedActivityAcknowledgement::create([
                    'domain_type' => $validated['domain_type'],
                    'subject_type' => $this->blankToNull($validated['subject_type'] ?? null),
                    'subject_id' => $validated['subject_id'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'delegated_actor_id' => $validated['delegated_actor_id'],
                    'accountable_user_id' => $validated['accountable_user_id'],
                    'accountable_role' => $validated['accountable_role'],
                    'represented_scope_type' => $this->blankToNull($validated['represented_scope_type'] ?? null),
                    'represented_scope_id' => $validated['represented_scope_id'] ?? null,
                    'activity_type' => $validated['activity_type'],
                    'activity_summary' => $validated['activity_summary'],
                    'internal_note' => $this->blankToNull($validated['internal_note'] ?? null),
                    'student_facing_note' => $this->blankToNull($validated['student_facing_note'] ?? null),
                    'before_state' => $validated['before_state'] ?? null,
                    'after_state' => $validated['after_state'] ?? null,
                    'status' => $validated['status'] ?? DelegatedActivityAcknowledgement::STATUS_PENDING_REVIEW,
                    'urgency' => $urgency,
                    'performed_at' => $performedAt,
                    'acknowledgement_due_at' => $dueAt,
                    'escalated_at' => $this->nullableCarbon($validated['escalated_at'] ?? null),
                ]);

                $this->recordActivity(
                    User::find($task->delegated_actor_id),
                    'Delegated activity recorded',
                    $task,
                    'Aktivitas delegasi dicatat untuk peninjauan pejabat penanggung jawab.',
                );

                return $task->fresh(['delegatedActor', 'accountableUser', 'acknowledgedBy']);
            });

            return [
                'outcome' => 'created',
                'acknowledgement' => $task,
            ];
        } catch (QueryException $exception) {
            if ($idempotencyKey !== null && $this->isUniqueConstraintViolation($exception)) {
                $existing = DelegatedActivityAcknowledgement::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return [
                        'outcome' => 'existing',
                        'acknowledgement' => $existing->loadMissing(['delegatedActor', 'accountableUser', 'acknowledgedBy']),
                    ];
                }
            }

            throw $exception;
        }
    }

    public function calculateDueAt(string $activityType, string $urgency, CarbonInterface $performedAt): Carbon
    {
        $rule = $this->slaRule($activityType, $urgency);
        $amount = max(0, (int) ($rule['amount'] ?? 0));
        $unit = (string) ($rule['unit'] ?? 'calendar_day');
        $dueAt = Carbon::instance($performedAt)->copy();

        if ($unit === 'working_day') {
            return $this->addWorkingDays($dueAt, $amount);
        }

        return $dueAt->addDays($amount);
    }

    public function acknowledge(
        DelegatedActivityAcknowledgement $task,
        User $actor,
        ?string $note = null,
    ): DelegatedActivityAcknowledgement {
        return DB::transaction(function () use ($task, $actor, $note) {
            $lockedTask = DelegatedActivityAcknowledgement::query()
                ->lockForUpdate()
                ->findOrFail($task->id);

            if (! $lockedTask->canBeAcknowledged()) {
                throw new DomainException('Aktivitas delegasi tidak dapat ditinjau pada status saat ini.');
            }

            if ((int) $lockedTask->delegated_actor_id === (int) $actor->id) {
                throw new AuthorizationException('Pelaksana delegasi tidak dapat meninjau aktivitasnya sendiri.');
            }

            if ((int) $lockedTask->accountable_user_id !== (int) $actor->id) {
                throw new AuthorizationException('Anda tidak berwenang meninjau aktivitas delegasi ini.');
            }

            $lockedTask->update([
                'status' => DelegatedActivityAcknowledgement::STATUS_ACKNOWLEDGED,
                'acknowledged_at' => now(config('app.timezone')),
                'acknowledged_by' => $actor->id,
                'acknowledgement_note' => $this->blankToNull($note),
            ]);

            $this->recordActivity(
                $actor,
                'Delegated activity acknowledged',
                $lockedTask,
                'Aktivitas delegasi dikonfirmasi sudah ditinjau.',
            );

            return $lockedTask->fresh(['delegatedActor', 'accountableUser', 'acknowledgedBy']);
        });
    }

    public function markEscalationSeen(
        DelegatedActivityAcknowledgement $task,
        User $superAdmin,
    ): DelegatedActivityAcknowledgement {
        if ($superAdmin->role !== 'super_admin') {
            throw new AuthorizationException('Hanya Super Admin yang dapat menandai eskalasi sebagai sudah dilihat.');
        }

        return DB::transaction(function () use ($task, $superAdmin) {
            $lockedTask = DelegatedActivityAcknowledgement::query()
                ->lockForUpdate()
                ->findOrFail($task->id);

            if ($lockedTask->status === DelegatedActivityAcknowledgement::STATUS_ACKNOWLEDGED) {
                throw new DomainException('Aktivitas delegasi sudah ditinjau.');
            }

            if ($lockedTask->status === DelegatedActivityAcknowledgement::STATUS_VOIDED) {
                throw new DomainException('Aktivitas delegasi sudah dibatalkan.');
            }

            $lockedTask->update([
                'escalation_seen_by_superadmin_at' => now(config('app.timezone')),
            ]);

            $this->recordActivity(
                $superAdmin,
                'Delegated activity escalation seen',
                $lockedTask,
                'Eskalasi aktivitas delegasi ditandai sudah dilihat oleh Super Admin.',
            );

            return $lockedTask->fresh(['delegatedActor', 'accountableUser', 'acknowledgedBy']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSummaryForAccountable(User $user): array
    {
        return $this->summary(DelegatedActivityAcknowledgement::query()->forAccountableUser($user));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildSummaryForSuperAdmin(array $filters = []): array
    {
        $query = DelegatedActivityAcknowledgement::query()->visibleToSuperAdmin();

        $this->applySummaryFilters($query, $filters);

        return $this->summary($query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateCreatePayload(array $payload): array
    {
        $validator = Validator::make($payload, [
            'domain_type' => ['required', 'string', 'max:64'],
            'subject_type' => ['nullable', 'string', 'max:128'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:160'],
            'delegated_actor_id' => ['required', 'integer', 'exists:users,id'],
            'accountable_user_id' => ['required', 'integer', 'exists:users,id'],
            'accountable_role' => ['required', 'string', 'max:64'],
            'represented_scope_type' => ['nullable', 'string', 'max:64'],
            'represented_scope_id' => ['nullable', 'integer', 'min:1'],
            'activity_type' => ['required', 'string', 'max:96'],
            'activity_summary' => ['required', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'student_facing_note' => ['nullable', 'string', 'max:5000'],
            'before_state' => ['nullable', 'array'],
            'after_state' => ['nullable', 'array'],
            'status' => ['sometimes', 'string', Rule::in(DelegatedActivityAcknowledgement::STATUSES)],
            'urgency' => ['sometimes', 'string', Rule::in(DelegatedActivityAcknowledgement::URGENCIES)],
            'performed_at' => ['sometimes', 'date'],
            'acknowledgement_due_at' => ['sometimes', 'nullable', 'date'],
            'escalated_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $validated = $validator->validate();
        $this->assertNoPrivateFileReferences($validated);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNoPrivateFileReferences(array $payload): void
    {
        foreach (['activity_summary', 'internal_note', 'student_facing_note', 'before_state', 'after_state'] as $field) {
            if (array_key_exists($field, $payload) && $this->containsPrivateFileReference($payload[$field])) {
                throw ValidationException::withMessages([
                    $field => 'Referensi file privat tidak boleh disimpan pada aktivitas delegasi.',
                ]);
            }
        }
    }

    private function containsPrivateFileReference(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsPrivateFileReference($item)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_string($value)) {
            return false;
        }

        $normalized = strtolower($value);

        return str_contains($normalized, '/' . 'storage' . '/')
            || str_contains($normalized, 'room-booking' . '-attachments');
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return in_array($sqlState, ['23000', '23505'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function slaRule(string $activityType, string $urgency): array
    {
        $activityRule = config("delegated_acknowledgements.sla.activity_types.{$activityType}");
        if (is_array($activityRule)) {
            $activityUrgency = $activityRule['urgency'] ?? $urgency;

            return array_merge(
                config("delegated_acknowledgements.sla.urgencies.{$activityUrgency}", []),
                $activityRule,
            );
        }

        return config("delegated_acknowledgements.sla.urgencies.{$urgency}", []);
    }

    private function addWorkingDays(Carbon $date, int $days): Carbon
    {
        $remaining = $days;

        while ($remaining > 0) {
            $date->addDay();
            if (! $date->isWeekend()) {
                $remaining--;
            }
        }

        return $date;
    }

    private function carbon(mixed $value): Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->copy();
        }

        return Carbon::parse($value, config('app.timezone'));
    }

    private function nullableCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->carbon($value);
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<DelegatedActivityAcknowledgement>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySummaryFilters($query, array $filters): void
    {
        foreach ([
            'status',
            'urgency',
            'domain_type',
            'activity_type',
            'accountable_user_id',
            'delegated_actor_id',
            'represented_scope_type',
            'represented_scope_id',
        ] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['due_before'])) {
            $query->where('acknowledgement_due_at', '<=', $filters['due_before']);
        }

        if (isset($filters['due_after'])) {
            $query->where('acknowledgement_due_at', '>=', $filters['due_after']);
        }

        if (array_key_exists('overdue', $filters)) {
            if (filter_var($filters['overdue'], FILTER_VALIDATE_BOOLEAN)) {
                $query->overdue();

                return;
            }

            $query->where(function ($query) {
                $query
                    ->where('status', '!=', DelegatedActivityAcknowledgement::STATUS_PENDING_REVIEW)
                    ->orWhereNull('acknowledgement_due_at')
                    ->orWhere('acknowledgement_due_at', '>=', now(config('app.timezone')));
            });
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<DelegatedActivityAcknowledgement>  $baseQuery
     * @return array<string, mixed>
     */
    private function summary($baseQuery): array
    {
        $oldestDueAt = (clone $baseQuery)
            ->pendingReview()
            ->whereNotNull('acknowledgement_due_at')
            ->orderBy('acknowledgement_due_at')
            ->value('acknowledgement_due_at');

        return [
            'pending_count' => (clone $baseQuery)->pendingReview()->count(),
            'overdue_count' => (clone $baseQuery)->overdue()->count(),
            'oldest_due_at' => $oldestDueAt ? Carbon::parse($oldestDueAt, config('app.timezone'))->toIso8601String() : null,
            'acknowledged_count' => (clone $baseQuery)->acknowledged()->count(),
            'escalated_count' => (clone $baseQuery)->where('status', DelegatedActivityAcknowledgement::STATUS_ESCALATED)->count(),
        ];
    }

    private function recordActivity(
        ?User $actor,
        string $action,
        DelegatedActivityAcknowledgement $task,
        string $details,
    ): void {
        if (! $actor) {
            return;
        }

        ActivityLog::create([
            'user_id' => $actor->id,
            'type' => 'delegated_activity',
            'action' => $action,
            'target_user' => (string) ($task->accountableUser?->email ?? $task->accountable_user_id ?? '-'),
            'details' => $details,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
