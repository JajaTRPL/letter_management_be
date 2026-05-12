<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AcademicSignatoryService
{
    public function __construct(
        private AcademicRoutingService $routingService,
        private AcademicContextService $academicContextService
    )
    {
    }

    public function officialKaprodiForApplication(Model $application): ?User
    {
        $studyProgramId = $this->routingService->studentStudyProgramId($application);

        return $studyProgramId
            ? $this->academicContextService->currentKaprodiForStudyProgram($studyProgramId)
            : null;
    }

    public function officialSekprodiForApplication(Model $application): ?User
    {
        $studyProgramId = $this->routingService->studentStudyProgramId($application);

        return $studyProgramId
            ? $this->academicContextService->currentSekprodiForStudyProgram($studyProgramId)
            : null;
    }

    public function officialKadepForApplication(Model $application): ?User
    {
        $departmentId = $this->routingService->studentDepartmentId($application);

        return $departmentId
            ? $this->academicContextService->currentKadepForDepartment($departmentId)
            : null;
    }

    public function officialSekdepForApplication(Model $application): ?User
    {
        $departmentId = $this->routingService->studentDepartmentId($application);

        return $departmentId
            ? $this->academicContextService->currentSekdepForDepartment($departmentId)
            : null;
    }

    public function nipLikeValue(?User $user): string
    {
        if (!$user) {
            return '-';
        }

        $nip = trim((string) $user->nip);

        return $nip !== '' ? $nip : '-';
    }

    public function signaturePath(?User $user): ?string
    {
        $path = trim((string) ($user?->signature_path ?? ''));

        return $path !== '' ? $path : null;
    }

    public function globalParafPath(): ?string
    {
        $path = config('surat.global_paraf_path', resource_path('system/paraf.png'));
        $path = is_string($path) ? trim($path) : '';

        return $path !== '' ? $path : null;
    }
}
