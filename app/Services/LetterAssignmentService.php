<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
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
