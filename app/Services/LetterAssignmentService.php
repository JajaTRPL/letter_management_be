<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Notifications\NotificationProjector;
use App\Support\LetterTypeRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

class LetterAssignmentService
{
    public function canHandle(User $user, string $letterType): bool
    {
        $canonicalKey = $this->canonicalKey($letterType);
        if (
            !$canonicalKey
            || $user->role !== 'tendik'
            || $user->tendik_role !== 'persuratan'
            || !is_array($user->assigned_tasks)
        ) {
            return false;
        }

        return collect($user->assigned_tasks)->contains(function ($task) use ($canonicalKey) {
            if (!is_string($task)) {
                return false;
            }

            return LetterTypeRegistry::canonicalize($task) === $canonicalKey;
        });
    }

    /**
     * Eligibility-by-letter-type check. Retained for backwards compatibility
     * with any caller that wants the canonical "letter type is admin-letter"
     * predicate without consulting assigned_tasks. New per-application access
     * gates must use canHandle (strict assigned_tasks), not this helper.
     *
     * Returns false for non-Persuratan sub-roles (Sarpras / Kepala Lab /
     * Laboran) — no admin-letter workflow exists for them.
     */
    public function canHandleAsTeam(User $user, string $letterType): bool
    {
        $canonicalKey = $this->canonicalKey($letterType);
        if (!$canonicalKey || $user->role !== 'tendik' || $user->tendik_role !== 'persuratan') {
            return false;
        }

        return in_array($canonicalKey, LetterTypeRegistry::canonicalKeys(), true);
    }

    /**
     * Backwards-compatible alias that now requires strict assigned_tasks
     * (canHandle). Previously this OR'd in canHandleAsTeam to grant any
     * Persuratan Tendik view/act access to every admin-letter; that produced
     * cross-assignment data exposure where a Tendik assigned only to Beasiswa
     * could read/act on Magang/Aktif/PLN rows. Read and action access now
     * follow assigned_tasks scope exclusively.
     */
    public function canHandleAny(User $user, string $letterType): bool
    {
        return $this->canHandle($user, $letterType);
    }

    /**
     * Team-scope feed visibility: returns the bare query (no assigned_to
     * filter) for any Persuratan Tendik whose assigned_tasks include this
     * letter type. Other Persuratan and non-Persuratan get an empty result
     * without leaking rows.
     */
    public function applyTeamFeedVisibility(Builder $query, User $user, string $letterType): Builder
    {
        if (!$this->canHandle($user, $letterType)) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function eligiblePersuratanTendikQuery(string $letterType): Builder
    {
        $canonicalKey = $this->canonicalKey($letterType);
        $query = User::query()
            ->where('role', 'tendik')
            ->where('tendik_role', 'persuratan')
            ->where('status', UserStatus::Active);

        if (!$canonicalKey) {
            return $query->whereRaw('1 = 0');
        }

        $keys = LetterTypeRegistry::assignmentKeysFor($canonicalKey, true);

        return $query->where(function (Builder $q) use ($keys) {
            foreach ($keys as $key) {
                $q->orWhereJsonContains('assigned_tasks', $key);
            }
        });
    }

    public function findEligiblePersuratanTendik(string $letterType): ?User
    {
        return $this->eligiblePersuratanTendikQuery($letterType)->first();
    }

    public function assignToEligibleTendik(Model $application, string $letterType): ?User
    {
        $assignedTendik = $this->findEligiblePersuratanTendik($letterType);

        if ($assignedTendik) {
            $application->update(['assigned_to' => $assignedTendik->id]);
        }

        // C7N1: the assignment seam is the single point every letter type reaches
        // at submit AND resubmit, and it knows the concrete assignee — so the
        // Persuratan queue-item notification is projected here (resolved via app()
        // to avoid coupling this widely-used service's constructor). A null
        // assignee is a SuperAdmin health anomaly, handled inside the projector.
        app(NotificationProjector::class)
            ->projectLetterAssigned($application, $letterType, $assignedTendik);

        return $assignedTendik;
    }

    public function applyFeedVisibility(
        Builder $query,
        User $user,
        string $letterType,
        ?array $unassignedStatuses = null
    ): Builder {
        if (!$this->canHandle($user, $letterType)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($user, $unassignedStatuses) {
            $query->where('assigned_to', $user->id)
                ->orWhere(function (Builder $query) use ($unassignedStatuses) {
                    $query->whereNull('assigned_to');

                    if ($unassignedStatuses !== null) {
                        $query->whereIn('status', $unassignedStatuses);
                    }
                });
        });
    }

    public function applyFeedVisibilityToQueryBuilder(
        QueryBuilder $query,
        User $user,
        string $letterType,
        ?array $unassignedStatuses = null
    ): QueryBuilder {
        if (!$this->canHandle($user, $letterType)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (QueryBuilder $query) use ($user, $unassignedStatuses) {
            $query->where('assigned_to', $user->id)
                ->orWhere(function (QueryBuilder $query) use ($unassignedStatuses) {
                    $query->whereNull('assigned_to');

                    if ($unassignedStatuses !== null) {
                        $query->whereIn('status', $unassignedStatuses);
                    }
                });
        });
    }

    private function canonicalKey(string $letterType): ?string
    {
        $normalizedKey = strtolower(trim($letterType));

        foreach (LetterTypeRegistry::canonicalKeys() as $canonicalKey) {
            if (strtolower($canonicalKey) === $normalizedKey) {
                return $canonicalKey;
            }
        }

        return null;
    }
}
