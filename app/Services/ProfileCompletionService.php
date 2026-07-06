<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\StudyProgram;
use App\Models\User;

class ProfileCompletionService
{
    private const ACADEMIC_PROGRAM_ROLES = ['kaprodi', 'sekprodi'];
    private const ACADEMIC_DEPARTMENT_ROLES = ['kadep', 'sekdep'];
    private const TENDIK_ROLES = ['persuratan', 'sarpras', 'kepala_lab', 'laboran'];
    private const LABORATORY_TENDIK_ROLES = ['kepala_lab', 'laboran'];

    public function status(User $user): array
    {
        $fields = [];
        $missing = [];
        $blockers = [];

        if ($user->role === 'mahasiswa') {
            if (!$user->mahasiswaProfile?->nim) {
                $fields[] = 'nim';
                $missing[] = 'NIM';
            }

            if (!$this->hasVisibleStudyProgram($user->study_program_id)) {
                $fields[] = 'study_program_id';
                $missing[] = 'Program Studi';
            }
        } elseif ($user->role === 'tendik') {
            if (!$this->hasText($user->nip)) {
                $fields[] = 'nip';
                $missing[] = 'NIP';
            }

            if (!in_array($user->tendik_role, self::TENDIK_ROLES, true)) {
                $missing[] = 'Peran Tendik';
                $blockers[] = 'Peran Tendik harus ditetapkan oleh Super Admin.';
            } elseif (
                in_array($user->tendik_role, self::LABORATORY_TENDIK_ROLES, true)
                && !$user->laboratory_id
            ) {
                $missing[] = 'Laboratorium';
                $blockers[] = 'Laboratorium harus ditetapkan oleh Super Admin.';
            }
        } elseif ($user->role === 'akademik') {
            if (!$this->hasText($user->nip)) {
                $fields[] = 'nip';
                $missing[] = 'NIP';
            }

            if (in_array($user->sub_role, self::ACADEMIC_PROGRAM_ROLES, true)) {
                if (!$this->hasVisibleStudyProgram($user->study_program_id)) {
                    $missing[] = 'Program Studi';
                    $blockers[] = 'Program Studi harus ditetapkan oleh Super Admin.';
                }
            } elseif (in_array($user->sub_role, self::ACADEMIC_DEPARTMENT_ROLES, true)) {
                if (!$this->hasVisibleDepartment($user->department_id)) {
                    $missing[] = 'Departemen';
                    $blockers[] = 'Departemen harus ditetapkan oleh Super Admin.';
                }
            } else {
                $missing[] = 'Jabatan Akademik';
                $blockers[] = 'Jabatan Akademik harus ditetapkan oleh Super Admin.';
            }
        }

        $needsCompletion = $missing !== [];

        return [
            'needs_completion' => $needsCompletion,
            'can_self_complete' => $needsCompletion && $blockers === [],
            'role' => $user->role,
            'sub_role' => $user->sub_role,
            'tendik_role' => $user->tendik_role,
            'fields' => $fields,
            'missing_fields' => $missing,
            'message' => $blockers !== []
                ? implode(' ', $blockers)
                : ($needsCompletion
                    ? 'Lengkapi ' . implode(' dan ', $missing) . ' sebelum mengakses sistem.'
                    : 'Profil sudah lengkap.'),
        ];
    }

    public function synchronizeStatus(User $user): array
    {
        $completion = $this->status($user);

        if (
            in_array($user->role, ['mahasiswa', 'tendik', 'akademik'], true)
            && $user->status !== UserStatus::Suspended
        ) {
            $target = $completion['needs_completion']
                ? UserStatus::PendingProfile
                : UserStatus::Active;

            if ($user->status !== $target) {
                $user->status = $target;
                $user->save();
            }
        }

        return $completion;
    }

    private function hasVisibleStudyProgram(int|string|null $id): bool
    {
        return $id !== null && StudyProgram::query()->runtimeVisible()->whereKey($id)->exists();
    }

    private function hasVisibleDepartment(int|string|null $id): bool
    {
        return $id !== null && Department::query()->runtimeVisible()->whereKey($id)->exists();
    }

    private function hasText(?string $value): bool
    {
        return trim((string) $value) !== '';
    }
}
