<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;

class AcademicContextService
{
    public function currentKaprodiForStudyProgram(int|string|null $studyProgramId): ?User
    {
        return $this->resolveAkademikUser('kaprodi', 'study_program_id', $studyProgramId);
    }

    public function currentSekprodiForStudyProgram(int|string|null $studyProgramId): ?User
    {
        return $this->resolveAkademikUser('sekprodi', 'study_program_id', $studyProgramId);
    }

    public function currentKadepForDepartment(int|string|null $departmentId): ?User
    {
        return $this->resolveAkademikUser('kadep', 'department_id', $departmentId);
    }

    public function currentSekdepForDepartment(int|string|null $departmentId): ?User
    {
        return $this->resolveAkademikUser('sekdep', 'department_id', $departmentId);
    }

    private function resolveAkademikUser(string $subRole, string $scopeColumn, int|string|null $scopeId): ?User
    {
        $scopeId = $this->normalizeId($scopeId);
        if (!$scopeId) {
            return null;
        }

        return User::where('role', 'akademik')
            ->where('sub_role', $subRole)
            ->where($scopeColumn, $scopeId)
            ->where('status', UserStatus::Active)
            ->orderBy('id')
            ->first();
    }

    private function normalizeId(int|string|null $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $id = (int) $id;

        return $id > 0 ? $id : null;
    }
}
