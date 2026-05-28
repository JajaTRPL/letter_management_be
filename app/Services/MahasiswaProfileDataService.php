<?php

namespace App\Services;

use App\Helpers\NimHelper;
use App\Models\MahasiswaProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class MahasiswaProfileDataService
{
    public function __construct(private AcademicContextService $academicContextService)
    {
    }

    public function forUser(User $user): array
    {
        $user->loadMissing([
            'mahasiswaProfile',
            'studyProgram.department.faculty',
            'department.faculty',
        ]);

        return $this->buildNormalizedData($user, $user->getRelationValue('mahasiswaProfile'));
    }

    public function forApplication(Model $application): array
    {
        $application->loadMissing([
            'user.studyProgram.department.faculty',
            'user.department.faculty',
            'user.mahasiswaProfile',
            'mahasiswaProfile',
        ]);

        $user = $application->getRelationValue('user');
        $profile = $application->getRelationValue('mahasiswaProfile')
            ?: $user?->getRelationValue('mahasiswaProfile');

        if (!$user && $profile instanceof MahasiswaProfile) {
            $profile->loadMissing('user.studyProgram.department.faculty', 'user.department.faculty');
            $user = $profile->getRelationValue('user');
        }

        return $this->buildNormalizedData($user, $profile);
    }

    public function studentForUser(User $user): array
    {
        return $this->studentFromNormalized($this->forUser($user));
    }

    public function studentForApplication(Model $application): array
    {
        return $this->studentFromNormalized($this->forApplication($application));
    }

    /**
     * Stable additive profile projection for letter form and detail endpoints.
     *
     * @return array<string, mixed>
     */
    public function profileSummaryForUser(User $user): array
    {
        $normalized = $this->forUser($user);

        return $this->profileSummaryFromNormalized(
            $normalized,
            $user->getRelationValue('mahasiswaProfile')
        );
    }

    /**
     * Stable additive profile projection for an existing letter application.
     *
     * @return array<string, mixed>
     */
    public function profileSummaryForApplication(Model $application): array
    {
        $normalized = $this->forApplication($application);
        $profile = $application->getRelationValue('mahasiswaProfile')
            ?: $application->getRelationValue('user')?->getRelationValue('mahasiswaProfile');

        return $this->profileSummaryFromNormalized(
            $normalized,
            $profile
        );
    }

    public function applicationPayload(Model $application): array
    {
        $normalized = $this->forApplication($application);
        $payload = $application->toArray();
        $payload['student'] = $this->studentFromNormalized($normalized);
        $payload['normalized_student'] = $normalized;
        $payload['mahasiswa_profile'] = $this->profileCompatibilityPayload(
            $payload['mahasiswa_profile'] ?? [],
            $normalized
        );

        return $payload;
    }

    public function readinessForUser(User $user): array
    {
        $normalized = $this->forUser($user);

        $academicMissing = [];
        if (!$normalized['study_program_id']) {
            $academicMissing[] = 'Program Studi';
        }
        if (!$normalized['department_id']) {
            $academicMissing[] = 'Departemen';
        }
        if (!$normalized['faculty_id']) {
            $academicMissing[] = 'Fakultas';
        }

        $profileMissing = [];
        $profileLabels = [
            'nim' => 'NIM',
            'tempat_lahir' => 'Tempat Lahir',
            'tanggal_lahir' => 'Tanggal Lahir',
            'jenis_kelamin' => 'Jenis Kelamin',
            'no_hp' => 'No. HP',
            'alamat_asal' => 'Alamat Asal',
            'alamat_domisili' => 'Alamat Domisili',
            'pas_foto_path' => 'Pas Foto',
            'tanda_tangan_path' => 'Tanda Tangan',
        ];

        foreach ($profileLabels as $field => $label) {
            if (!$this->hasValue($normalized[$field] ?? null)) {
                $profileMissing[] = $label;
            }
        }

        return [
            'academic_master_data' => [
                'is_complete' => $academicMissing === [],
                'missing_fields' => $academicMissing,
            ],
            'editable_personal_profile_data' => [
                'is_complete' => $profileMissing === [],
                'missing_fields' => $profileMissing,
            ],
            'workflow_actor_data' => [
                'is_complete' => null,
                'missing_fields' => [],
                'note' => 'Workflow actor and academic role readiness are evaluated outside student profile completeness.',
            ],
        ];
    }

    private function buildNormalizedData(?User $user, ?MahasiswaProfile $profile): array
    {
        $program = $user?->getRelationValue('studyProgram');
        $department = $program?->department ?: $user?->getRelationValue('department');
        $faculty = $program?->department?->faculty ?: $department?->faculty;
        $nim = $this->firstFilled(
            $profile?->nim,
            $user && array_key_exists('nim', $user->getAttributes()) ? $user->getAttribute('nim') : null
        );
        $academicContext = $user
            ? $this->academicContextService->studentAcademicContext($user)
            : $this->emptyAcademicContext();

        return [
            'user_id' => $user?->id,
            'name' => $this->firstFilled($user?->name, $profile?->nama_lengkap, '-'),
            'email' => $this->firstFilled($user?->email, '-'),
            'nim' => $this->firstFilled($nim, '-'),
            'angkatan' => NimHelper::deriveAngkatan($nim),

            'study_program_id' => $program?->id,
            'study_program_code' => $program?->code,
            'study_program_name' => $this->firstFilled($program?->name, '-'),
            'program_studi_display' => $this->firstFilled($program?->name, '-'),

            'department_id' => $department?->id,
            'department_code' => $department?->code,
            'department_name' => $this->firstFilled($department?->name, '-'),
            'department_display' => $this->firstFilled($department?->name, '-'),

            'faculty_id' => $faculty?->id,
            'faculty_name' => $this->firstFilled($faculty?->name, '-'),
            'fakultas_display' => $this->firstFilled($faculty?->name, '-'),

            'tempat_lahir' => $profile?->tempat_lahir,
            'tanggal_lahir' => $profile?->tanggal_lahir,
            'jenis_kelamin' => $profile?->jenis_kelamin,
            'no_hp' => $profile?->no_hp,
            'alamat_asal' => $profile?->alamat_asal,
            'alamat_domisili' => $profile?->alamat_domisili,
            'pas_foto_path' => $profile?->pas_foto_path,
            'tanda_tangan_path' => $profile?->tanda_tangan_path,

            'academic_period_id' => $academicContext['academic_period_id'],
            'current_academic_year' => $academicContext['current_academic_year'],
            'current_semester_type' => $academicContext['current_semester_type'],
            'current_semester_order' => $academicContext['current_semester_order'],
            'current_semester' => $academicContext['current_semester'],

            'raw_profile_id' => $profile?->id,
        ];
    }

    private function studentFromNormalized(array $normalized): array
    {
        return [
            'name' => $normalized['name'],
            'email' => $normalized['email'],
            'nim' => $normalized['nim'],
            'angkatan' => $normalized['angkatan'],
            'program_studi_display' => $normalized['program_studi_display'],
            'department_display' => $normalized['department_display'],
            'fakultas_display' => $normalized['fakultas_display'],
            'current_academic_year' => $normalized['current_academic_year'],
            'current_semester_type' => $normalized['current_semester_type'],
            'current_semester' => $normalized['current_semester'],
            'study_program' => [
                'id' => $normalized['study_program_id'],
                'code' => $normalized['study_program_code'],
                'name' => $normalized['study_program_name'],
            ],
            'department' => [
                'id' => $normalized['department_id'],
                'code' => $normalized['department_code'],
                'name' => $normalized['department_name'],
            ],
            'faculty' => [
                'id' => $normalized['faculty_id'],
                'name' => $normalized['faculty_name'],
            ],
            'profile' => [
                'tempat_lahir' => $normalized['tempat_lahir'],
                'tanggal_lahir' => $normalized['tanggal_lahir'],
                'jenis_kelamin' => $normalized['jenis_kelamin'],
                'no_hp' => $normalized['no_hp'],
                'alamat_asal' => $normalized['alamat_asal'],
                'alamat_domisili' => $normalized['alamat_domisili'],
                'pas_foto_path' => $normalized['pas_foto_path'],
                'tanda_tangan_path' => $normalized['tanda_tangan_path'],
            ],
            'academic_context' => [
                'academic_period_id' => $normalized['academic_period_id'],
                'current_academic_year' => $normalized['current_academic_year'],
                'current_semester_type' => $normalized['current_semester_type'],
                'current_semester_order' => $normalized['current_semester_order'],
                'current_semester' => $normalized['current_semester'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function profileSummaryFromNormalized(array $normalized, ?MahasiswaProfile $profile): array
    {
        return [
            'full_name' => $this->displayValueToNull($normalized['name'] ?? null),
            'nim' => $this->displayValueToNull($normalized['nim'] ?? null),
            'email' => $this->displayValueToNull($normalized['email'] ?? null),
            'faculty' => $this->firstFilled(
                $this->displayValueToNull($normalized['faculty_name'] ?? null),
                $profile?->fakultas
            ),
            'study_program' => $this->firstFilled(
                $this->displayValueToNull($normalized['study_program_name'] ?? null),
                $profile?->program_studi
            ),
            'study_program_code' => $this->displayValueToNull($normalized['study_program_code'] ?? null),
            'department' => $this->displayValueToNull($normalized['department_name'] ?? null),
            'tempat_lahir' => $this->blankToNull($profile?->tempat_lahir),
            'tanggal_lahir' => $this->dateOnly($profile?->tanggal_lahir),
            'jenis_kelamin' => $this->blankToNull($profile?->jenis_kelamin),
            'current_semester' => $normalized['current_semester'] ?? null,
        ];
    }

    private function profileCompatibilityPayload(mixed $profilePayload, array $normalized): array
    {
        $profilePayload = is_array($profilePayload) ? $profilePayload : [];
        $profilePayload['id'] = $profilePayload['id'] ?? $normalized['raw_profile_id'];
        $profilePayload['nama_lengkap'] = $normalized['name'];
        $profilePayload['email'] = $normalized['email'];
        $profilePayload['nim'] = $normalized['nim'];
        $profilePayload['angkatan'] = $normalized['angkatan'];
        $profilePayload['program_studi'] = $normalized['program_studi_display'];
        $profilePayload['fakultas'] = $normalized['fakultas_display'];
        $profilePayload['department'] = $normalized['department_display'];
        $profilePayload['department_code'] = $normalized['department_code'];
        $profilePayload['current_academic_year'] = $normalized['current_academic_year'];
        $profilePayload['current_semester_type'] = $normalized['current_semester_type'];
        $profilePayload['current_semester'] = $normalized['current_semester'];
        $profilePayload['no_telp'] = $normalized['no_hp'];

        return $profilePayload;
    }

    private function emptyAcademicContext(): array
    {
        return [
            'academic_period_id' => null,
            'current_academic_year' => null,
            'current_semester_type' => null,
            'current_semester_order' => null,
            'current_semester' => null,
        ];
    }

    private function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $value = $this->blankToNull($value);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function displayValueToNull(mixed $value): ?string
    {
        $value = $this->blankToNull($value);

        return $value === '-' ? null : $value;
    }

    private function dateOnly(mixed $value): ?string
    {
        $value = $this->blankToNull($value);
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasValue(mixed $value): bool
    {
        return $this->blankToNull($value) !== null && $value !== '-';
    }
}
