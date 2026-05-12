<?php

namespace App\Services;

use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class AcademicRoutingService
{
    public function applyProdiStageScope(Builder $query, User $akademikUser): Builder
    {
        if (!$this->isProdiApprover($akademikUser) || !$akademikUser->study_program_id) {
            return $this->emptyScope($query);
        }

        $program = StudyProgram::find($akademikUser->study_program_id);
        if (!$program) {
            return $this->emptyScope($query);
        }

        return $query->where(function (Builder $query) use ($program) {
            $query->whereHas('user', function (Builder $userQuery) use ($program) {
                $userQuery->where('study_program_id', $program->id);
            });
        });
    }

    public function applyDepartmentStageScope(Builder $query, User $akademikUser): Builder
    {
        if (!$this->isDepartmentApprover($akademikUser) || !$akademikUser->department_id) {
            return $this->emptyScope($query);
        }

        $programIds = StudyProgram::where('department_id', $akademikUser->department_id)
            ->pluck('id')
            ->all();

        return $query->where(function (Builder $query) use ($akademikUser, $programIds) {
            $query->whereHas('user', function (Builder $userQuery) use ($akademikUser, $programIds) {
                $userQuery->where('department_id', $akademikUser->department_id);

                if ($programIds !== []) {
                    $userQuery->orWhereIn('study_program_id', $programIds);
                }
            });
        });
    }

    public function applyProdiStageQueryScope(QueryBuilder $query, User $akademikUser): QueryBuilder
    {
        if (!$this->isProdiApprover($akademikUser) || !$akademikUser->study_program_id) {
            return $this->emptyQueryScope($query);
        }

        $program = StudyProgram::find($akademikUser->study_program_id);
        if (!$program) {
            return $this->emptyQueryScope($query);
        }

        return $query->whereIn(
            'user_id',
            User::query()
                ->select('id')
                ->where('study_program_id', $program->id)
        );
    }

    public function applyDepartmentStageQueryScope(QueryBuilder $query, User $akademikUser): QueryBuilder
    {
        if (!$this->isDepartmentApprover($akademikUser) || !$akademikUser->department_id) {
            return $this->emptyQueryScope($query);
        }

        $programIds = StudyProgram::where('department_id', $akademikUser->department_id)
            ->pluck('id')
            ->all();

        return $query->whereIn(
            'user_id',
            User::query()
                ->select('id')
                ->where(function (Builder $userQuery) use ($akademikUser, $programIds) {
                    $userQuery->where('department_id', $akademikUser->department_id);

                    if ($programIds !== []) {
                        $userQuery->orWhereIn('study_program_id', $programIds);
                    }
                })
        );
    }

    private function isProdiApprover(User $user): bool
    {
        return $user->role === 'akademik'
            && in_array($user->sub_role, ['kaprodi', 'sekprodi'], true);
    }

    private function isDepartmentApprover(User $user): bool
    {
        return $user->role === 'akademik'
            && in_array($user->sub_role, ['kadep', 'sekdep'], true);
    }

    private function emptyScope(Builder $query): Builder
    {
        return $query->whereRaw('1 = 0');
    }

    private function emptyQueryScope(QueryBuilder $query): QueryBuilder
    {
        return $query->whereRaw('1 = 0');
    }
}
