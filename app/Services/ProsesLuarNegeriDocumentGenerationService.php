<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use ZipArchive;

/**
 * Phase-aware PLN DOCX generator. Writes a private DOCX source artifact to the
 * local disk and never mutates application state, public storage paths, or the
 * letter_document_artifacts ledger. DomPDF is never invoked from this path;
 * PDF conversion belongs to the later orchestration phase.
 */
class ProsesLuarNegeriDocumentGenerationService
{
    private const KADEP_TTD_PLACEHOLDER = 'ttd_kadep_luar_negeri';

    private const KADEP_TTD_IMAGE_DIMENSIONS = [
        'width' => 150,
        'height' => 80,
        'ratio' => true,
    ];

    private const PARAF_PLACEHOLDER = 'paraf_luar_negeri';

    private const PARAF_IMAGE_DIMENSIONS = [
        'width' => 80,
        'height' => 24,
        'ratio' => true,
    ];

    public function __construct(
        private ProsesLuarNegeriPhaseResolver $phaseResolver,
        private AcademicSignatoryService $signatoryService,
        private LetterDocumentSourceHashService $sourceHashService,
    ) {
    }

    /**
     * Generate a filled PLN DOCX for a preview phase without mutating workflow
     * state, legacy generated path columns, or artifact ledger rows.
     *
     * @param array<string, mixed> $overrides Pending render values: nomor_surat,
     *     tanggal_surat, official_kadep, plus the same status/timestamp fields
     *     supported by LetterDocumentSourceHashService for hash stability.
     */
    public function generateDocumentForPhase(
        ProsesLuarNegeriApplication $application,
        string $phase,
        array $overrides = [],
    ): string {
        $this->assertValidPhase($phase);

        $tempTemplatePath = null;
        $tempOutputPath = null;
        $localPath = null;

        try {
            $templateContent = $this->loadTemplateContent();
            $tempDirectory = storage_path('app/temp/proses-luar-negeri');
            if (!is_dir($tempDirectory) && !mkdir($tempDirectory, 0775, true) && !is_dir($tempDirectory)) {
                throw new RuntimeException('Unable to create temporary PLN document directory.');
            }

            $tempTemplatePath = $tempDirectory . DIRECTORY_SEPARATOR . 'template_' . $application->getKey() . '_' . uniqid('', true) . '.docx';
            if (file_put_contents($tempTemplatePath, $templateContent) === false) {
                throw new RuntimeException('Unable to write temporary PLN template.');
            }

            $renderApplication = $this->applicationSnapshot($application, $overrides);
            $renderApplication->loadMissing([
                'user.studyProgram.department.faculty',
                'user.department.faculty',
                'mahasiswaProfile',
            ]);

            $phaseFlags = $this->phaseResolver->phaseFlagsFor($renderApplication, $phase);
            $payload = $this->sourceHashService->canonicalPlnPayload(
                $renderApplication,
                $phase,
                $phaseFlags,
                $overrides,
            );

            $templateProcessor = new TemplateProcessor($tempTemplatePath);
            $this->mapTextPlaceholders($templateProcessor, $payload);
            $this->mapImagePlaceholders($templateProcessor, $renderApplication, $phaseFlags, $overrides);

            $directory = 'letter-document-artifacts/'
                . ProsesLuarNegeriApplication::LETTER_TYPE
                . '/'
                . $application->getKey()
                . '/'
                . $phase;
            if (!Storage::disk('local')->exists($directory)) {
                Storage::disk('local')->makeDirectory($directory);
            }

            $filename = 'source_' . time() . '_' . str_replace('.', '', uniqid('', true)) . '.docx';
            $localPath = $directory . '/' . $filename;
            $savePath = Storage::disk('local')->path($localPath);
            $saveDirectory = dirname($savePath);
            if (!is_dir($saveDirectory) && !mkdir($saveDirectory, 0775, true) && !is_dir($saveDirectory)) {
                throw new RuntimeException('Unable to create PLN document output directory.');
            }

            $tempOutputPath = $tempDirectory . DIRECTORY_SEPARATOR . 'generated_' . $application->getKey() . '_' . uniqid('', true) . '.docx';
            $templateProcessor->saveAs($tempOutputPath);

            if (!is_file($tempOutputPath) || filesize($tempOutputPath) <= 0) {
                throw new RuntimeException('Generated PLN phase document is empty or missing.');
            }

            $this->assertNoUnresolvedPlaceholders($tempOutputPath);

            if (!@rename($tempOutputPath, $savePath)) {
                if (!@copy($tempOutputPath, $savePath)) {
                    throw new RuntimeException('Unable to move generated PLN phase document into local storage.');
                }
                @unlink($tempOutputPath);
            }

            if (!Storage::disk('local')->exists($localPath)) {
                throw new RuntimeException('Generated PLN phase document was not saved.');
            }

            return $localPath;
        } catch (\Throwable $exception) {
            if ($localPath && Storage::disk('local')->exists($localPath)) {
                Storage::disk('local')->delete($localPath);
            }

            throw $exception;
        } finally {
            foreach ([$tempTemplatePath, $tempOutputPath] as $tempPath) {
                if ($tempPath && is_file($tempPath)) {
                    @unlink($tempPath);
                }
            }
        }
    }

    private function loadTemplateContent(): string
    {
        $cachePath = config('surat.template_proses_luar_negeri_cache_path');
        if (!is_string($cachePath) || !is_file($cachePath) || !is_readable($cachePath)) {
            throw new RuntimeException('Dokumen PLN tidak dapat dibuat: template cache Proses Luar Negeri tidak tersedia.');
        }

        $content = file_get_contents($cachePath);
        if ($content === false || strlen($content) === 0) {
            throw new RuntimeException('Dokumen PLN tidak dapat dibuat: template cache Proses Luar Negeri kosong.');
        }

        if (!str_starts_with($content, 'PK')) {
            throw new RuntimeException('Dokumen PLN tidak dapat dibuat: template cache Proses Luar Negeri bukan DOCX valid.');
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mapTextPlaceholders(TemplateProcessor $templateProcessor, array $payload): void
    {
        $application = $payload['application'] ?? [];
        $student = $payload['student'] ?? [];
        $rendered = $payload['rendered'] ?? [];

        $values = [
            'nomor_surat_luar_negeri' => $this->displayText($application['nomor_surat'] ?? null),
            'no_paspor' => $this->displayText($application['no_paspor'] ?? null),
            'keperluan' => $this->displayText($application['keperluan'] ?? null),
            'nama' => $this->displayText($student['nama'] ?? null),
            'jenis_kelamin' => $this->displayText($student['jenis_kelamin'] ?? null),
            'tempat_lahir' => $this->displayText($student['tempat_lahir'] ?? null),
            'tanggal_lahir' => $this->displayText($student['tanggal_lahir'] ?? null),
            'nim' => $this->displayText($student['nim'] ?? null),
            'semester' => $this->displayText($student['semester'] ?? null),
            'prodi' => $this->displayText($student['prodi'] ?? null),
            'kode_prodi' => $this->displayText($student['kode_prodi'] ?? null),
            'departemen' => $this->displayText($rendered['departemen'] ?? null),
            'fakultas' => $this->displayText($rendered['fakultas'] ?? null),
            'tanggal_surat' => $this->displayText($rendered['tanggal_surat'] ?? null),
            'jabatan_kadep' => $this->displayText($rendered['jabatan_kadep'] ?? null),
            'nama_kadep' => $this->displayText($rendered['nama_kadep'] ?? null),
            'nip_kadep' => $this->displayText($rendered['nip_kadep'] ?? null),
        ];

        foreach ($values as $placeholder => $value) {
            $templateProcessor->setValue($placeholder, $value);
        }
    }

    /**
     * @param array{include_nomor_surat: bool, include_prodi_paraf: bool, include_kadep_signature: bool} $phaseFlags
     * @param array<string, mixed> $overrides
     */
    private function mapImagePlaceholders(
        TemplateProcessor $templateProcessor,
        ProsesLuarNegeriApplication $application,
        array $phaseFlags,
        array $overrides,
    ): void {
        if ($phaseFlags['include_kadep_signature']) {
            $officialKadep = $this->officialKadepForRender($application, $overrides);
            $signaturePath = $this->publicImageAbsolutePath($this->signatoryService->signaturePath($officialKadep));
            if (!$signaturePath) {
                throw new RuntimeException('Dokumen PLN tidak dapat dibuat: tanda tangan Kadep belum tersedia.');
            }

            $templateProcessor->setImageValue(self::KADEP_TTD_PLACEHOLDER, array_merge([
                'path' => $signaturePath,
            ], self::KADEP_TTD_IMAGE_DIMENSIONS));
        } else {
            $templateProcessor->setValue(self::KADEP_TTD_PLACEHOLDER, '');
        }

        if ($phaseFlags['include_prodi_paraf']) {
            $parafPath = $this->signatoryService->globalParafFilePath();
            if (!$parafPath || !is_file($parafPath)) {
                throw new RuntimeException('Dokumen PLN tidak dapat dibuat: paraf belum tersedia.');
            }

            $templateProcessor->setImageValue(self::PARAF_PLACEHOLDER, array_merge([
                'path' => $parafPath,
            ], self::PARAF_IMAGE_DIMENSIONS));
        } else {
            $templateProcessor->setValue(self::PARAF_PLACEHOLDER, '');
        }
    }

    /**
     * Coerce nullable canonical values into a render-safe string. Empty values
     * surface as '-' so the DOCX never contains an unresolved ${placeholder}.
     */
    private function displayText(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : '-';
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function applicationSnapshot(
        ProsesLuarNegeriApplication $application,
        array $overrides,
    ): ProsesLuarNegeriApplication {
        $snapshot = $application->newInstance($application->getAttributes(), true);
        $snapshot->setAttribute($application->getKeyName(), $application->getKey());
        $snapshot->exists = $application->exists;
        $snapshot->setRelations($application->getRelations());

        foreach ([
            'status',
            'nomor_surat',
            'submitted_at',
            'tendik_approved_at',
            'tendik_approved_by',
            'kaprodi_approved_at',
            'kaprodi_approved_by',
            'kadep_approved_at',
            'kadep_approved_by',
        ] as $attribute) {
            if (array_key_exists($attribute, $overrides)) {
                $snapshot->setAttribute($attribute, $overrides[$attribute]);
            }
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function officialKadepForRender(ProsesLuarNegeriApplication $application, array $overrides): ?User
    {
        foreach (['official_kadep', 'kadep_user'] as $key) {
            if (($overrides[$key] ?? null) instanceof User) {
                return $overrides[$key];
            }
        }

        return $this->signatoryService->officialKadepForApplication($application);
    }

    private function publicImageAbsolutePath(?string $path): ?string
    {
        $publicPath = $this->normalizePublicStoragePath($path);
        if (!$publicPath || !Storage::disk('public')->exists($publicPath)) {
            return null;
        }

        return Storage::disk('public')->path($publicPath);
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

    private function assertNoUnresolvedPlaceholders(string $absoluteDocxPath): void
    {
        $unresolved = [];
        foreach ($this->wordXmlEntries($absoluteDocxPath) as $xml) {
            preg_match_all('/\$\{[^}]+\}/', $xml, $matches);
            $unresolved = array_merge($unresolved, $matches[0] ?? []);
        }

        $unresolved = array_values(array_unique($unresolved));
        sort($unresolved);

        if ($unresolved !== []) {
            throw new RuntimeException('Generated PLN phase document contains unresolved placeholders: ' . implode(', ', $unresolved));
        }
    }

    /**
     * @return array<string, string>
     */
    private function wordXmlEntries(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open generated PLN DOCX: {$path}");
        }

        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || !$this->isTemplateXmlEntry($name)) {
                    continue;
                }

                $contents = $zip->getFromName($name);
                if (is_string($contents)) {
                    $entries[$name] = $contents;
                }
            }

            if ($entries === []) {
                throw new RuntimeException("Generated PLN DOCX has no inspectable Word XML entries: {$path}");
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    private function isTemplateXmlEntry(string $name): bool
    {
        return $name === 'word/document.xml'
            || preg_match('/^word\/header\d+\.xml$/', $name) === 1
            || preg_match('/^word\/footer\d+\.xml$/', $name) === 1
            || in_array($name, ['word/footnotes.xml', 'word/endnotes.xml'], true);
    }

    private function assertValidPhase(string $phase): void
    {
        if (!in_array($phase, LetterDocumentArtifact::PHASES, true)) {
            throw new InvalidArgumentException("Unsupported PLN document phase: {$phase}");
        }
    }
}
