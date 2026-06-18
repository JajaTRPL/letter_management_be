<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Models\User;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ScholarshipAutomationService
{
    private const KADEP_TTD_PLACEHOLDERS = [
        'ttd_kadep_formulir',
        'ttd_kadep_rekomendasi',
        'ttd_kadep',
        'ttd_kadep',
    ];

    private const KADEP_TTD_IMAGE_DIMENSIONS = [
        'width' => 150,
        'height' => 80,
        'ratio' => true,
    ];

    private const PRODI_PARAF_PLACEHOLDERS = [
        'paraf_formulir',
        'paraf_rekomendasi',
        'paraf',
        'paraf',
    ];

    private const PRODI_PARAF_IMAGE_DIMENSIONS = [
        'width' => 80,
        'height' => 24,
        'ratio' => true,
    ];

    public function __construct(
        private LetterAssignmentService $assignmentService,
        private AcademicSignatoryService $signatoryService,
        private MahasiswaProfileDataService $profileDataService,
        private ?BeasiswaPhaseResolver $phaseResolver = null,
        private ?PasFotoNormalizer $pasFotoNormalizer = null,
    )
    {
        $this->phaseResolver ??= app(BeasiswaPhaseResolver::class);
        $this->pasFotoNormalizer ??= app(PasFotoNormalizer::class);
    }

    /**
     * Assign the application to an active Tendik Persuratan responsible for Beasiswa letters.
     */
    public function assignApplication(ScholarshipApplication $application): ?User
    {
        return $this->assignmentService->assignToEligibleTendik($application, ScholarshipApplication::LETTER_TYPE);
    }

    /**
     * Generate a filled DOCX for a preview phase without mutating workflow state
     * or compatibility generated_* columns. The returned path is relative to the
     * private local disk, not the public disk.
     *
     * @param array<string, mixed> $pendingOverrides In-memory render values such as nomor_surat or official_kadep.
     */
    public function generateDocumentForPhase(
        ScholarshipApplication $application,
        string $phase,
        array $pendingOverrides = [],
    ): string|false {
        $this->assertValidPhase($phase);

        $tempTemplatePath = null;
        $tempOutputPath = null;
        $localPath = null;

        try {
            $templateContent = $this->fetchTemplateContent();

            if ($templateContent === false) {
                Log::error("Failed to download template from Google Docs for application #{$application->id}");
                return false;
            }

            $tempDirectory = storage_path('app/temp/scholarships');
            if (!is_dir($tempDirectory) && !mkdir($tempDirectory, 0775, true) && !is_dir($tempDirectory)) {
                throw new RuntimeException("Unable to create temporary scholarship directory.");
            }

            $tempTemplatePath = $tempDirectory . DIRECTORY_SEPARATOR . 'phase_template_' . $application->id . '_' . uniqid('', true) . '.docx';
            if (file_put_contents($tempTemplatePath, $templateContent) === false) {
                throw new RuntimeException("Unable to write temporary scholarship template.");
            }

            $renderApplication = $this->applicationSnapshot($application, $pendingOverrides);
            $renderApplication->load([
                'mahasiswaProfile.keluarga',
                'mahasiswaProfile.scholarshipHistories',
                'user.studyProgram.department.faculty',
                'user.department.faculty',
            ]);

            $phaseFlags = $this->phaseResolver->phaseFlagsFor($renderApplication, $phase);
            $studentData = $this->profileDataService->forApplication($renderApplication);
            $profile = $renderApplication->mahasiswaProfile;

            if (!$profile) {
                throw new RuntimeException('Dokumen Beasiswa tidak dapat dibuat: profil mahasiswa tidak ditemukan.');
            }

            $family = $profile->keluarga;
            $officialKadep = $this->officialKadepForRender($renderApplication, $pendingOverrides);

            if ($phaseFlags['include_kadep_signature'] && !$officialKadep) {
                throw new RuntimeException('Dokumen Beasiswa tidak dapat dibuat: belum ada Ketua Departemen aktif untuk program studi mahasiswa.');
            }

            $templateProcessor = new TemplateProcessor($tempTemplatePath);
            $this->mapPhaseTextValues(
                $templateProcessor,
                $renderApplication,
                $studentData,
                $profile,
                $family,
                $officialKadep,
                $phaseFlags,
                $pendingOverrides,
            );
            $tempPasFotoPath = $this->preparePasFotoDerivative($profile->pas_foto_path ?? null, $tempDirectory);
            $this->mapPhaseImages($templateProcessor, $profile, $officialKadep, $phaseFlags, $tempPasFotoPath);

            $directory = 'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/' . $application->getKey() . '/' . $phase;
            if (!Storage::disk('local')->exists($directory)) {
                Storage::disk('local')->makeDirectory($directory);
            }

            $filename = 'source_' . time() . '_' . str_replace('.', '', uniqid('', true)) . '.docx';
            $localPath = $directory . '/' . $filename;
            $savePath = Storage::disk('local')->path($localPath);
            $saveDirectory = dirname($savePath);
            if (!is_dir($saveDirectory) && !mkdir($saveDirectory, 0775, true) && !is_dir($saveDirectory)) {
                throw new RuntimeException("Unable to create phase document directory.");
            }

            $tempOutputPath = $tempDirectory . DIRECTORY_SEPARATOR . 'phase_generated_' . $application->id . '_' . uniqid('', true) . '.docx';
            $templateProcessor->saveAs($tempOutputPath);

            if (!file_exists($tempOutputPath) || filesize($tempOutputPath) <= 0) {
                throw new RuntimeException("Generated scholarship phase document is empty or missing.");
            }

            if (!@rename($tempOutputPath, $savePath)) {
                if (!@copy($tempOutputPath, $savePath)) {
                    throw new RuntimeException("Unable to move generated scholarship phase document into local storage.");
                }
                @unlink($tempOutputPath);
            }

            if (!Storage::disk('local')->exists($localPath)) {
                throw new RuntimeException("Generated scholarship phase document was not saved.");
            }

            return $localPath;
        } catch (\Exception $e) {
            if ($localPath && Storage::disk('local')->exists($localPath)) {
                Storage::disk('local')->delete($localPath);
            }

            Log::error("Error generating phase document: " . $e->getMessage());
            return false;
        } finally {
            foreach ([$tempTemplatePath, $tempOutputPath, $tempPasFotoPath ?? null] as $tempPath) {
                if ($tempPath && file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            }
        }
    }

    protected function fetchTemplateContent(): string|false
    {
        $cachePath = config('surat.template_beasiswa_cache_path');

        // Use local cache when available — avoids live Google dependency on every generation
        if ($cachePath && is_file($cachePath) && is_readable($cachePath)) {
            $cached = file_get_contents($cachePath);
            if ($cached !== false && strlen($cached) > 0) {
                return $cached;
            }
        }

        // Cache miss — fetch from Google Docs
        $content = $this->fetchFromGoogle();

        if ($content === false || strlen($content) === 0) {
            return false;
        }

        // Basic DOCX validation: DOCX is a ZIP archive that starts with "PK"
        if (!str_starts_with($content, 'PK')) {
            Log::warning('Template fetched from Google Docs does not appear to be a valid DOCX (missing PK header).');
            return false;
        }

        // Persist to cache for future generations
        if ($cachePath) {
            $cacheDir = dirname($cachePath);
            if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
                Log::warning("Unable to create template cache directory: {$cacheDir}");
            } else {
                @file_put_contents($cachePath, $content);
            }
        }

        return $content;
    }

    protected function fetchFromGoogle(): string|false
    {
        $templateId = trim((string) config('surat.template_beasiswa_id', ''));
        if ($templateId === '') {
            Log::warning('Beasiswa template Google Doc ID is not configured.');

            return false;
        }

        $url = "https://docs.google.com/document/d/{$templateId}/export?format=docx";

        $options = [
            'http' => [
                'follow_location' => true,
                'max_redirects' => 5,
                'header' => "User-Agent: Mozilla/5.0\r\n",
            ],
        ];
        $context = stream_context_create($options);

        return @file_get_contents($url, false, $context);
    }

    private function indonesianDate(mixed $date = null): string
    {
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret',    4 => 'April',
            5 => 'Mei',     6 => 'Juni',      7 => 'Juli',     8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        if ($date instanceof \DateTimeInterface) {
            $d = (int) $date->format('d');
            $m = (int) $date->format('n');
            $y = (int) $date->format('Y');
        } elseif ($date !== null) {
            // Use PHP native date() so strtotime and formatting share the same timezone.
            $timestamp = strtotime((string) $date);
            if ($timestamp === false) {
                return '-';
            }
            $d = (int) date('d', $timestamp);
            $m = (int) date('n', $timestamp);
            $y = (int) date('Y', $timestamp);
        } else {
            // No-arg: use Carbon::now() so Carbon::setTestNow() works in tests.
            $now = Carbon::now();
            $d   = (int) $now->format('d');
            $m   = (int) $now->month;
            $y   = (int) $now->format('Y');
        }

        return sprintf('%02d', $d) . ' ' . $months[$m] . ' ' . $y;
    }

    private function mapFamilyData(TemplateProcessor $templateProcessor, $family)
    {
        $relations = ['ayah', 'ibu', 'wali'];
        foreach ($relations as $rel) {
            $data = $family->where('jenis_relasi', $rel)->first();
            if ($data) {
                $templateProcessor->setValue($rel . '_nama', $data->nama_lengkap ?? '-');
                $templateProcessor->setValue($rel . '_kerja', $data->pekerjaan ?? '-');
                $templateProcessor->setValue($rel . '_gaji', $data->penghasilan ? number_format($data->penghasilan, 0, ',', '.') : '-');
                $templateProcessor->setValue($rel . '_status', ucfirst($data->status_hidup ?? '-'));
                $templateProcessor->setValue($rel . '_tgl', $data->tanggal_meninggal ? $this->indonesianDate($data->tanggal_meninggal) : '-');
            } else {
                foreach(['nama', 'kerja', 'gaji', 'status', 'tgl'] as $k) $templateProcessor->setValue($rel . '_' . $k, '-');
            }
        }
    }

    private function mapNomorSuratRekomendasiPlaceholder(TemplateProcessor $templateProcessor, mixed $nomorSurat): void
    {
        $value = trim((string) ($nomorSurat ?? ''));
        $value = $value !== '' ? $value : '-';

        $templateProcessor->setValue('nomor_surat_rekomendasi', $value);
    }

    /**
     * The template composes `${jabatan_kadep} ${departemen}`. Keep the role
     * title semantic and strip any repeated unit prefix from the department.
     *
     * @param array<string, mixed> $studentData
     */
    private function mapKadepOfficeTitle(TemplateProcessor $templateProcessor, array $studentData): void
    {
        $departmentName = $studentData['department_display'] ?? '-';

        $templateProcessor->setValue('jabatan_kadep', $this->signatoryService->academicOfficeRoleTitle('kadep'));
        $templateProcessor->setValue('departemen', $this->signatoryService->academicOfficeUnitName('kadep', $departmentName));
    }

    /**
     * @param array{include_nomor_surat: bool, include_prodi_paraf: bool, include_kadep_signature: bool} $phaseFlags
     * @param array<string, mixed> $pendingOverrides
     */
    private function mapPhaseTextValues(
        TemplateProcessor $templateProcessor,
        ScholarshipApplication $application,
        array $studentData,
        $profile,
        $family,
        ?User $officialKadep,
        array $phaseFlags,
        array $pendingOverrides,
    ): void {
        $templateProcessor->setValue('nama', $studentData['name']);
        $templateProcessor->setValue('nim', $studentData['nim']);
        $templateProcessor->setValue('fakultas', $studentData['fakultas_display']);
        $templateProcessor->setValue('prodi', $studentData['program_studi_display']);
        $templateProcessor->setValue('email', $studentData['email']);

        $templateProcessor->setValue('ipk', (string)($application->ipk ?? '-'));
        $templateProcessor->setValue('ipk_total', (string)($application->ipk ?? '-'));
        $templateProcessor->setValue('ip_2', (string)($application->gpa_last_2_semesters ?? '-'));
        $templateProcessor->setValue('sks_2', (string)($application->sks_last_2_semesters ?? '-'));
        $templateProcessor->setValue('sksk', (string)($application->total_sks_passed ?? '-'));
        $templateProcessor->setValue('sks_total', (string)($application->total_sks_required ?? '-'));

        $templateProcessor->setValue('semester', (string)($application->current_semester ?? '-'));
        $templateProcessor->setValue('jenjang', $application->study_level ?? 'D4');
        $templateProcessor->setValue('angkatan', $studentData['angkatan']);
        $templateProcessor->setValue('tanggungan', (string)($application->family_dependents ?? '-'));

        $templateProcessor->setValue('cuti_status', $application->on_leave ?? 'Belum');
        $templateProcessor->setValue('cuti', $application->on_leave ?? 'Belum');
        $templateProcessor->setValue('skripsi_status', $application->thesis_status ?? 'Belum');
        $templateProcessor->setValue('rencana_ujian', $application->exam_plan_date
            ? $this->indonesianDate($application->exam_plan_date)
            : '-');
        $templateProcessor->setValue('beasiswa_nama', $application->scholarship_name ?? '-');
        $this->mapNomorSuratRekomendasiPlaceholder(
            $templateProcessor,
            $phaseFlags['include_nomor_surat'] ? $application->nomor_surat : null,
        );
        $templateProcessor->setValue('nama_kadep', $officialKadep?->name ?? '-');
        $templateProcessor->setValue('nip_kadep', $this->signatoryService->nipLikeValue($officialKadep));
        $this->mapKadepOfficeTitle($templateProcessor, $studentData);
        $templateProcessor->setValue('tanggal_surat', $this->indonesianDate($pendingOverrides['tanggal_surat'] ?? null));

        $templateProcessor->setValue('tempat_lahir', $studentData['tempat_lahir'] ?? '-');
        $templateProcessor->setValue('tanggal_lahir', $studentData['tanggal_lahir'] ? $this->indonesianDate($studentData['tanggal_lahir']) : '-');
        $templateProcessor->setValue('jenis_kelamin', $studentData['jenis_kelamin'] === 'L' ? 'Laki-laki' : ($studentData['jenis_kelamin'] === 'P' ? 'Perempuan' : '-'));
        $templateProcessor->setValue('no_hp', $studentData['no_hp'] ?? '-');
        $templateProcessor->setValue('alamat_asal', $studentData['alamat_asal'] ?? '-');
        $templateProcessor->setValue('alamat_domisili', $studentData['alamat_domisili'] ?? '-');

        $histories = $profile->scholarshipHistories;
        $hCount = $histories->count();
        $templateProcessor->setValue('h_status_teks', $hCount > 0 ? 'Pernah' : 'Belum Pernah');
        if ($hCount > 0) {
            $templateProcessor->cloneRow('h_sumber', $hCount);
            $i = 1;
            foreach ($histories as $h) {
                $templateProcessor->setValue('h_no#' . $i, $i . '.');
                $templateProcessor->setValue('h_sumber#' . $i, $h->nama_beasiswa ?? '-');
                $templateProcessor->setValue('h_periode#' . $i, $h->periode ?? '-');
                $templateProcessor->setValue('h_nominal#' . $i, is_numeric($h->jumlah) ? number_format((float)$h->jumlah, 0, ',', '.') : ($h->jumlah ?? '-'));
                $templateProcessor->setValue('h_masih#' . $i, $h->status ?? '-');
                $i++;
            }
        } else {
            $templateProcessor->setValue('h_no', '-');
            foreach (['h_sumber', 'h_periode', 'h_nominal', 'h_masih'] as $key) {
                $templateProcessor->setValue($key, '-');
            }
        }

        $this->mapFamilyData($templateProcessor, $family);

        $siblings = $family->where('jenis_relasi', 'saudara');
        if ($siblings->count() > 0) {
            $templateProcessor->cloneRow('s_nama', $siblings->count());
            $i = 1;
            foreach ($siblings as $sib) {
                $templateProcessor->setValue('s_no#' . $i, (string)$i);
                $templateProcessor->setValue('s_nama#' . $i, $sib->nama_lengkap);
                $templateProcessor->setValue('s_kerja#' . $i, $sib->pekerjaan ?? '-');
                $templateProcessor->setValue('s_status#' . $i, $sib->status_kawin ?? '-');
                $templateProcessor->setValue('s_ket#' . $i, $sib->keterangan ?? '-');
                $i++;
            }
        } else {
            foreach (['s_no', 's_nama', 's_kerja', 's_status', 's_ket'] as $key) {
                $templateProcessor->setValue($key, '-');
            }
        }
    }

    /**
     * @param array{include_nomor_surat: bool, include_prodi_paraf: bool, include_kadep_signature: bool} $phaseFlags
     */
    private function mapPhaseImages(
        TemplateProcessor $templateProcessor,
        $profile,
        ?User $officialKadep,
        array $phaseFlags,
        ?string $normalizedPasFotoPath = null,
    ): void
    {
        $this->setPasFotoOrFallback(
            $templateProcessor,
            'foto',
            $normalizedPasFotoPath,
            $profile->pas_foto_path,
            ['width' => 110, 'height' => 150, 'ratio' => false],
            '(Tidak Ada)',
        );

        $this->setImageOrFallback($templateProcessor, 'tanda_tangan', $profile->tanda_tangan_path, [
            'width' => 150,
            'height' => 80,
            'ratio' => true,
        ], '(Tidak Ada)');

        if ($phaseFlags['include_kadep_signature']) {
            $this->setImageOrFallbackForPlaceholders(
                $templateProcessor,
                self::KADEP_TTD_PLACEHOLDERS,
                $officialKadep?->signature_path,
                self::KADEP_TTD_IMAGE_DIMENSIONS,
                '(Tanda tangan belum tersedia)',
            );
        } else {
            $this->blankPlaceholders($templateProcessor, self::KADEP_TTD_PLACEHOLDERS);
        }

        if ($phaseFlags['include_prodi_paraf']) {
            $this->setLocalImageOrFallbackForPlaceholders(
                $templateProcessor,
                self::PRODI_PARAF_PLACEHOLDERS,
                $this->signatoryService->globalParafFilePath(),
                self::PRODI_PARAF_IMAGE_DIMENSIONS,
                '(Paraf belum tersedia)',
            );
        } else {
            $this->blankPlaceholders($templateProcessor, self::PRODI_PARAF_PLACEHOLDERS);
        }
    }

    /**
     * @param list<string> $placeholders
     * @param array<string, mixed> $dimensions
     */
    private function setImageOrFallbackForPlaceholders(
        TemplateProcessor $templateProcessor,
        array $placeholders,
        ?string $path,
        array $dimensions,
        string $fallback,
    ): void {
        foreach ($placeholders as $placeholder) {
            $this->setImageOrFallback($templateProcessor, $placeholder, $path, $dimensions, $fallback);
        }
    }

    /**
     * @param list<string> $placeholders
     * @param array<string, mixed> $dimensions
     */
    private function setLocalImageOrFallbackForPlaceholders(
        TemplateProcessor $templateProcessor,
        array $placeholders,
        ?string $path,
        array $dimensions,
        string $fallback,
    ): void {
        foreach ($placeholders as $placeholder) {
            $this->setLocalImageOrFallback($templateProcessor, $placeholder, $path, $dimensions, $fallback);
        }
    }

    /**
     * @param list<string> $placeholders
     */
    private function blankPlaceholders(TemplateProcessor $templateProcessor, array $placeholders): void
    {
        foreach ($placeholders as $placeholder) {
            $templateProcessor->setValue($placeholder, '');
        }
    }

    /**
     * Build a temp 600x800 JPEG derivative from the stored pas foto. Returns the
     * absolute temp path on success, or null if the source cannot be resolved or
     * GD fails — in which case generation falls back to the original file. The
     * original pas foto is never mutated.
     */
    private function preparePasFotoDerivative(?string $publicPath, string $tempDirectory): ?string
    {
        $resolved = $this->normalizePublicStoragePath($publicPath);
        if (!$resolved || !Storage::disk('public')->exists($resolved)) {
            return null;
        }

        try {
            return $this->pasFotoNormalizer->normalizeFromPath(
                Storage::disk('public')->path($resolved),
                $tempDirectory,
            );
        } catch (\Throwable $exception) {
            Log::warning('Pas foto normalization for document generation failed: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Insert the pas foto placeholder. Prefer the normalized temp derivative
     * when present; otherwise fall back to the original stored file so legacy
     * data still renders. Display dimensions are fixed; only embedded pixel
     * payload changes.
     */
    private function setPasFotoOrFallback(
        TemplateProcessor $templateProcessor,
        string $placeholder,
        ?string $normalizedPath,
        ?string $originalPublicPath,
        array $dimensions,
        string $fallback,
    ): void {
        if ($normalizedPath && is_file($normalizedPath)) {
            try {
                $templateProcessor->setImageValue($placeholder, array_merge([
                    'path' => $normalizedPath,
                ], $dimensions));
                return;
            } catch (\Throwable $exception) {
                Log::warning("Unable to insert normalized pas foto for placeholder {$placeholder}: " . $exception->getMessage());
            }
        }

        $this->setImageOrFallback($templateProcessor, $placeholder, $originalPublicPath, $dimensions, $fallback);
    }

    private function setImageOrFallback(
        TemplateProcessor $templateProcessor,
        string $placeholder,
        ?string $path,
        array $dimensions,
        string $fallback
    ): void {
        $publicPath = $this->normalizePublicStoragePath($path);

        if (!$publicPath || !Storage::disk('public')->exists($publicPath)) {
            $templateProcessor->setValue($placeholder, $fallback);
            return;
        }

        try {
            $templateProcessor->setImageValue($placeholder, array_merge([
                'path' => Storage::disk('public')->path($publicPath),
            ], $dimensions));
        } catch (\Throwable $exception) {
            Log::warning("Unable to insert scholarship image placeholder {$placeholder}: " . $exception->getMessage());
            $templateProcessor->setValue($placeholder, $fallback);
        }
    }

    private function setLocalImageOrFallback(
        TemplateProcessor $templateProcessor,
        string $placeholder,
        ?string $path,
        array $dimensions,
        string $fallback
    ): void {
        if (!$path || !is_file($path)) {
            $templateProcessor->setValue($placeholder, $fallback);
            return;
        }

        try {
            $templateProcessor->setImageValue($placeholder, array_merge([
                'path' => $path,
            ], $dimensions));
        } catch (\Throwable $exception) {
            Log::warning("Unable to insert scholarship image placeholder {$placeholder}: " . $exception->getMessage());
            $templateProcessor->setValue($placeholder, $fallback);
        }
    }

    private function normalizePublicStoragePath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

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

    private function assertValidPhase(string $phase): void
    {
        if (!in_array($phase, LetterDocumentArtifact::PHASES, true)) {
            throw new InvalidArgumentException("Unsupported Beasiswa document phase: {$phase}");
        }
    }

    /**
     * @param array<string, mixed> $pendingOverrides
     */
    private function applicationSnapshot(ScholarshipApplication $application, array $pendingOverrides): ScholarshipApplication
    {
        $snapshot = $application->newInstance($application->getAttributes(), true);
        $snapshot->setAttribute($application->getKeyName(), $application->getKey());
        $snapshot->exists = $application->exists;
        $snapshot->setRelations($application->getRelations());

        foreach (['nomor_surat'] as $attribute) {
            if (array_key_exists($attribute, $pendingOverrides)) {
                $snapshot->setAttribute($attribute, $pendingOverrides[$attribute]);
            }
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $pendingOverrides
     */
    private function officialKadepForRender(ScholarshipApplication $application, array $pendingOverrides): ?User
    {
        foreach (['official_kadep', 'kadep_user'] as $key) {
            if (($pendingOverrides[$key] ?? null) instanceof User) {
                return $pendingOverrides[$key];
            }
        }

        return $this->signatoryService->officialKadepForApplication($application);
    }
}
