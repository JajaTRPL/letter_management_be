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

    /**
     * Stamped signatures (TTD + cap) live on the public disk under a per-user
     * directory instead of a users column: the directory holds at most one
     * randomly named file, so the user id alone is not enough to guess the URL.
     */
    public const STAMPED_SIGNATURE_DIRECTORY = 'profiles/signatures-stamped';

    public const SIGNATURE_VARIANT_DEFAULT = 'default';

    public const SIGNATURE_VARIANT_STAMPED = 'stamped';

    public const SIGNATURE_VARIANTS = [
        self::SIGNATURE_VARIANT_DEFAULT,
        self::SIGNATURE_VARIANT_STAMPED,
    ];

    public function stampedSignatureDirectory(User $user): string
    {
        return self::STAMPED_SIGNATURE_DIRECTORY . '/' . (int) $user->getKey();
    }

    /**
     * Public-disk relative path of the user's stamped signature, or null.
     */
    public function stampedSignatureDiskPath(?User $user): ?string
    {
        if (!$user || !$user->getKey()) {
            return null;
        }

        $disk = Storage::disk('public');
        $files = array_values(array_filter(
            $disk->files($this->stampedSignatureDirectory($user)),
            static fn (string $file): bool => preg_match('/\.(png|jpe?g|webp)$/i', $file) === 1,
        ));

        if ($files === []) {
            return null;
        }

        // Normally a single file; if a replace left a stray one, use the newest.
        usort($files, static fn (string $a, string $b): int => $disk->lastModified($b) <=> $disk->lastModified($a));

        return $files[0];
    }

    /**
     * Stamped signature in the same `/storage/...` URL form as users.signature_path.
     */
    public function stampedSignaturePath(?User $user): ?string
    {
        $diskPath = $this->stampedSignatureDiskPath($user);

        return $diskPath !== null ? Storage::url($diskPath) : null;
    }

    public function normalizeSignatureVariant(mixed $variant): string
    {
        return $variant === self::SIGNATURE_VARIANT_STAMPED
            ? self::SIGNATURE_VARIANT_STAMPED
            : self::SIGNATURE_VARIANT_DEFAULT;
    }

    /**
     * Returns the Kadep model to hand to document rendering for the chosen
     * signature variant. For the stamped variant this is an unsaved in-memory
     * copy whose signature_path points at the stamped image, so every renderer
     * and the source-hash service pick it up through the existing
     * `official_kadep` override without any schema change. Returns null when
     * the stamped variant is requested but no stamped signature was uploaded.
     */
    public function kadepForSignatureVariant(?User $kadep, string $variant): ?User
    {
        if (!$kadep || $variant !== self::SIGNATURE_VARIANT_STAMPED) {
            return $kadep;
        }

        $stampedPath = $this->stampedSignaturePath($kadep);
        if ($stampedPath === null) {
            return null;
        }

        $renderKadep = clone $kadep;
        $renderKadep->setAttribute('signature_path', $stampedPath);

        return $renderKadep;
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

    /**
     * Public-disk path of the paraf uploaded (Profil -> Paraf) by the current
     * Kaprodi of the student's study program, or null when there is no active
     * Kaprodi or they have not uploaded one. The Prodi paraf on a letter is
     * always the Kaprodi's — even when the Sekprodi did the approval.
     */
    public function kaprodiParafPublicPath(Model $application): ?string
    {
        $kaprodi = $this->officialKaprodiForApplication($application);
        $publicPath = $this->normalizePublicStoragePath((string) $this->signaturePath($kaprodi));
        if (!$publicPath || !Storage::disk('public')->exists($publicPath)) {
            return null;
        }

        return $publicPath;
    }

    /**
     * Absolute, DOCX-embeddable file for the Prodi paraf on this application:
     * the Kaprodi's own uploaded paraf (WebP converted to PNG). Null when the
     * Kaprodi has not uploaded one: there is deliberately no fallback image,
     * the Kaprodi must upload their paraf first.
     */
    public function prodiParafFilePath(Model $application): ?string
    {
        $publicPath = $this->kaprodiParafPublicPath($application);
        if (!$publicPath) {
            return null;
        }

        $docxPath = app(\App\Support\SignatureImageNormalizer::class)
            ->docxCompatiblePath(Storage::disk('public')->path($publicPath));

        // Same footprint as the reference paraf, whatever the upload's size.
        return app(\App\Support\SignatureFootprintNormalizer::class)->paraf($docxPath);
    }

    /**
     * Absolute, DOCX-embeddable file for the Kadep signature that is printed
     * (regular or stamped, whichever `signature_path` the render user has),
     * normalized so every upload prints with the same footprint. Null when
     * the file is missing.
     */
    public function kadepSignatureFilePath(?User $kadep): ?string
    {
        $publicPath = $this->normalizePublicStoragePath((string) $this->signaturePath($kadep));
        if (!$publicPath || !Storage::disk('public')->exists($publicPath)) {
            return null;
        }

        $docxPath = app(\App\Support\SignatureImageNormalizer::class)
            ->docxCompatiblePath(Storage::disk('public')->path($publicPath));

        return app(\App\Support\SignatureFootprintNormalizer::class)->kadepSignature($docxPath);
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
