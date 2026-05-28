<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
        if (!$studyProgramId) {
            return null;
        }

        return $this->academicContextService->currentKaprodiForStudyProgram($studyProgramId);
    }

    public function officialSekprodiForApplication(Model $application): ?User
    {
        $studyProgramId = $this->routingService->studentStudyProgramId($application);
        if (!$studyProgramId) {
            return null;
        }

        return $this->academicContextService->currentSekprodiForStudyProgram($studyProgramId);
    }

    public function officialKadepForApplication(Model $application): ?User
    {
        $departmentId = $this->routingService->studentDepartmentId($application);
        if (!$departmentId) {
            return null;
        }

        return $this->academicContextService->currentKadepForDepartment($departmentId);
    }

    public function officialSekdepForApplication(Model $application): ?User
    {
        $departmentId = $this->routingService->studentDepartmentId($application);
        if (!$departmentId) {
            return null;
        }

        return $this->academicContextService->currentSekdepForDepartment($departmentId);
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

    public function formatAcademicOfficeTitle(string $roleKey, ?string $unitName): string
    {
        $rolePosition = $this->academicOfficeRolePosition($roleKey);
        $unitType = $this->academicOfficeUnitType($roleKey);
        $normalizedUnitName = $this->academicOfficeUnitName($roleKey, $unitName);

        return $this->squish(implode(' ', array_filter([
            $rolePosition,
            $unitType,
            $normalizedUnitName !== '-' ? $normalizedUnitName : null,
        ])));
    }

    public function academicOfficeRoleTitle(string $roleKey): string
    {
        return $this->squish($this->academicOfficeRolePosition($roleKey) . ' ' . $this->academicOfficeUnitType($roleKey));
    }

    public function academicOfficeUnitName(string $roleKey, ?string $unitName): string
    {
        $unitName = $this->squish((string) $unitName);
        if ($unitName === '' || $unitName === '-') {
            return '-';
        }

        $unitType = $this->academicOfficeUnitType($roleKey);
        $unitName = $this->stripLeadingPattern($unitName, '/^(Ketua|Sekretaris)(?:\s+|$)/iu');

        if ($unitType === 'Departemen') {
            $unitName = $this->stripLeadingPattern($unitName, '/^Departemen(?:\s+|$)/iu');
        } elseif ($unitType === 'Program Studi') {
            $unitName = $this->stripLeadingPattern($unitName, '/^Program\s+Studi(?:\s+|$)/iu');
        }

        $unitName = $this->squish($unitName);

        return $unitName !== '' ? $unitName : '-';
    }

    public function globalParafPath(): ?string
    {
        $path = config('surat.global_paraf_path', resource_path('system/paraf.png'));
        $path = is_string($path) ? trim($path) : '';

        return $path !== '' ? $path : null;
    }

    public function globalParafFilePath(): ?string
    {
        $path = $this->globalParafPath();
        if (!$path) {
            return null;
        }

        if ($this->isAbsolutePath($path)) {
            return is_file($path) ? $path : null;
        }

        $publicPath = $this->normalizePublicStoragePath($path);
        if (!$publicPath || !Storage::disk('public')->exists($publicPath)) {
            return null;
        }

        return Storage::disk('public')->path($publicPath);
    }

    public function globalParafExists(): bool
    {
        return $this->globalParafFilePath() !== null;
    }

    public function publicImageDataUri(?string $path): ?string
    {
        $publicPath = $this->normalizePublicStoragePath((string) $path);
        if (!$publicPath || !Storage::disk('public')->exists($publicPath)) {
            return null;
        }

        $mimeType = Storage::disk('public')->mimeType($publicPath) ?: 'image/png';
        $content = Storage::disk('public')->get($publicPath);

        return 'data:' . $mimeType . ';base64,' . base64_encode($content);
    }

    public function globalParafDataUri(): ?string
    {
        $path = $this->globalParafFilePath();
        if (!$path || !is_file($path)) {
            return null;
        }

        $mimeType = mime_content_type($path) ?: 'image/png';
        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        return 'data:' . $mimeType . ';base64,' . base64_encode($content);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private function normalizePublicStoragePath(string $path): ?string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'api/storage/')) {
            $path = substr($path, strlen('api/storage/'));
        }

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    private function academicOfficeRolePosition(string $roleKey): string
    {
        return match (strtolower($roleKey)) {
            'kadep', 'kaprodi' => 'Ketua',
            'sekdep', 'sekprodi' => 'Sekretaris',
            default => $this->squish($roleKey),
        };
    }

    private function academicOfficeUnitType(string $roleKey): string
    {
        return match (strtolower($roleKey)) {
            'kadep', 'sekdep' => 'Departemen',
            'kaprodi', 'sekprodi' => 'Program Studi',
            default => '',
        };
    }

    private function stripLeadingPattern(string $value, string $pattern): string
    {
        do {
            $previous = $value;
            $value = preg_replace($pattern, '', $value) ?? $value;
            $value = $this->squish($value);
        } while ($value !== $previous);

        return $value;
    }

    private function squish(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
