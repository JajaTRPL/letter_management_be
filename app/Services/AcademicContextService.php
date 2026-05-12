<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Helpers\NimHelper;
use App\Models\AcademicPeriod;
use App\Models\User;
use Illuminate\Support\Carbon;

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

    public function currentAcademicPeriod(): ?AcademicPeriod
    {
        $today = Carbon::today()->toDateString();

        return AcademicPeriod::active()
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();
    }

    public function studentCurrentSemester(User $student): ?int
    {
        return $this->studentCurrentSemesterFromAngkatan($this->studentAngkatan($student));
    }

    public function studentCurrentSemesterFromAngkatan(?int $angkatan): ?int
    {
        return $this->calculateCurrentSemester($angkatan, $this->currentAcademicPeriod());
    }

    public function studentAcademicContext(User $student): array
    {
        $period = $this->currentAcademicPeriod();
        $angkatan = $this->studentAngkatan($student);

        return [
            'academic_period_id' => $period?->id,
            'current_academic_year' => $period?->academic_year,
            'current_semester_type' => $period?->semester_type,
            'current_semester_order' => $period?->semester_order,
            'current_semester' => $this->calculateCurrentSemester($angkatan, $period),
        ];
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

    private function studentAngkatan(User $student): ?int
    {
        $student->loadMissing('mahasiswaProfile');

        $nim = $student->mahasiswaProfile?->nim;
        if (!$nim && array_key_exists('nim', $student->getAttributes())) {
            $nim = $student->getAttribute('nim');
        }

        $angkatan = NimHelper::deriveAngkatan($nim);

        return ctype_digit($angkatan) ? (int) $angkatan : null;
    }

    private function calculateCurrentSemester(?int $angkatan, ?AcademicPeriod $period): ?int
    {
        if (!$angkatan || !$period) {
            return null;
        }

        $semester = (($period->year_start - $angkatan) * 2) + $period->semester_order;

        return $semester > 0 ? $semester : null;
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
