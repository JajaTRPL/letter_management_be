<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratTugasApplication;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use ZipArchive;

/**
 * Phase-aware Surat Tugas DOCX generator. Writes only a private source DOCX and
 * never mutates workflow state or public/generated paths. Maps exactly the 18
 * placeholders of the validated Surat Tugas template; the tugas number, paraf,
 * and Kadep TTD are phase-gated.
 */
class SuratTugasDocumentGenerationService
{
    private const KADEP_TTD_PLACEHOLDERS = [
        'ttd_kadep_tugas' => 'include_kadep_ttd_tugas',
    ];

    private const KADEP_TTD_IMAGE_DIMENSIONS = [
        'width' => 150,
        'height' => 80,
        'ratio' => true,
    ];

    private const PARAF_PLACEHOLDERS = [
        'paraf_tugas',
    ];

    private const PARAF_IMAGE_DIMENSIONS = [
        'width' => 80,
        'height' => 24,
        'ratio' => true,
    ];

    public function __construct(
        private SuratTugasPhaseResolver $phaseResolver,
        private AcademicSignatoryService $signatoryService,
        private LetterDocumentSourceHashService $sourceHashService,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides Pending render values such as
     *     nomor_surat_tugas, tanggal_surat, or official_kadep.
     */
    public function generateDocumentForPhase(
        SuratTugasApplication $application,
        string $phase,
        array $overrides = [],
    ): string {
        $this->assertValidPhase($phase);

        $tempTemplatePath = null;
        $tempOutputPath = null;
        $localPath = null;

        try {
            $templateContent = $this->loadTemplateContent();
            $tempDirectory = storage_path('app/temp/surat-tugas');
            if (!is_dir($tempDirectory) && !mkdir($tempDirectory, 0775, true) && !is_dir($tempDirectory)) {
                throw new RuntimeException('Unable to create temporary Surat Tugas document directory.');
            }

            $tempTemplatePath = $tempDirectory . DIRECTORY_SEPARATOR . 'template_' . $application->getKey() . '_' . uniqid('', true) . '.docx';
            if (file_put_contents($tempTemplatePath, $templateContent) === false) {
                throw new RuntimeException('Unable to write temporary Surat Tugas template.');
            }

            $renderApplication = $this->applicationSnapshot($application, $overrides);
            $renderApplication->loadMissing([
                'user.studyProgram.department.faculty',
                'user.department.faculty',
                'mahasiswaProfile',
            ]);

            $phaseFlags = $this->phaseResolver->phaseFlagsFor($renderApplication, $phase);
            $payload = $this->sourceHashService->canonicalSuratTugasPayload(
                $renderApplication,
                $phase,
                $phaseFlags,
                $overrides,
            );
            $this->assertRequiredTextValues($payload, $phaseFlags);

            $templateProcessor = new TemplateProcessor($tempTemplatePath);
            $this->mapTextPlaceholders($templateProcessor, $payload, $phaseFlags);
            $this->mapImagePlaceholders($templateProcessor, $renderApplication, $phaseFlags, $overrides);

            $directory = 'letter-document-artifacts/'
                . SuratTugasApplication::LETTER_TYPE
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
                throw new RuntimeException('Unable to create Surat Tugas document output directory.');
            }

            $tempOutputPath = $tempDirectory . DIRECTORY_SEPARATOR . 'generated_' . $application->getKey() . '_' . uniqid('', true) . '.docx';
            $templateProcessor->saveAs($tempOutputPath);

            if (!is_file($tempOutputPath) || filesize($tempOutputPath) <= 0) {
                throw new RuntimeException('Generated Surat Tugas phase document is empty or missing.');
            }

            $this->assertNoUnresolvedPlaceholders($tempOutputPath);

            if (!@rename($tempOutputPath, $savePath)) {
                if (!@copy($tempOutputPath, $savePath)) {
                    throw new RuntimeException('Unable to move generated Surat Tugas phase document into local storage.');
                }
                @unlink($tempOutputPath);
            }

            if (!Storage::disk('local')->exists($localPath)) {
                throw new RuntimeException('Generated Surat Tugas phase document was not saved.');
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
        $cachePath = config('surat.template_surat_tugas_cache_path');
        if (!is_string($cachePath) || !is_file($cachePath) || !is_readable($cachePath)) {
            throw new RuntimeException('Dokumen Surat Tugas tidak dapat dibuat: template cache Surat Tugas tidak tersedia.');
        }

        $content = file_get_contents($cachePath);
        if ($content === false || strlen($content) === 0) {
            throw new RuntimeException('Dokumen Surat Tugas tidak dapat dibuat: template cache Surat Tugas kosong.');
        }

        if (!str_starts_with($content, 'PK')) {
            throw new RuntimeException('Dokumen Surat Tugas tidak dapat dibuat: template cache Surat Tugas bukan DOCX valid.');
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, bool> $phaseFlags
     */
    private function mapTextPlaceholders(TemplateProcessor $templateProcessor, array $payload, array $phaseFlags): void
    {
        $application = $payload['application'];
        $student = $payload['student'];
        $internship = $payload['internship'];
        $rendered = $payload['rendered'];

        $values = [
            'tanggal_surat' => $rendered['tanggal_surat'],
            'nomor_surat_tugas' => $phaseFlags['include_nomor_tugas']
                ? $application['nomor_surat_tugas']
                : '',
            'nama_perusahaan' => $application['nama_perusahaan'],
            'kegiatan' => $application['kegiatan'],
            'posisi' => $application['posisi'],
            'nama' => $student['nama'],
            'nim' => $student['nim'],
            'prodi' => $student['prodi'],
            'departemen' => $student['departemen'],
            'fakultas' => $student['fakultas'],
            'dpa' => $internship['dpa'],
            'tgl_mulai' => $internship['tgl_mulai'],
            'tgl_selesai' => $internship['tgl_selesai'],
            'jabatan_kadep' => $rendered['jabatan_kadep'],
            'nama_kadep' => $rendered['nama_kadep'],
            'nip_kadep' => $rendered['nip_kadep'],
        ];

        foreach ($values as $placeholder => $value) {
            $templateProcessor->setValue($placeholder, (string) $value);
        }
    }

    /**
     * @param array<string, bool> $phaseFlags
     * @param array<string, mixed> $overrides
     */
    private function mapImagePlaceholders(
        TemplateProcessor $templateProcessor,
        SuratTugasApplication $application,
        array $phaseFlags,
        array $overrides,
    ): void {
        $signaturePath = null;
        if ($phaseFlags['include_kadep_ttd_tugas']) {
            $officialKadep = $this->officialKadepForRender($application, $overrides);
            $signaturePath = $this->publicImageAbsolutePath($this->signatoryService->signaturePath($officialKadep));
            if (!$signaturePath) {
                throw new RuntimeException('Dokumen Surat Tugas tidak dapat dibuat: tanda tangan Kadep belum tersedia.');
            }
        }

        foreach (self::KADEP_TTD_PLACEHOLDERS as $placeholder => $flag) {
            if ($phaseFlags[$flag]) {
                $templateProcessor->setImageValue($placeholder, array_merge([
                    'path' => $signaturePath,
                ], self::KADEP_TTD_IMAGE_DIMENSIONS));
            } else {
                $templateProcessor->setValue($placeholder, '');
            }
        }

        $parafPath = null;
        if ($phaseFlags['include_paraf_tugas']) {
            $parafPath = $this->signatoryService->globalParafFilePath();
            if (!$parafPath || !is_file($parafPath)) {
                throw new RuntimeException('Dokumen Surat Tugas tidak dapat dibuat: paraf belum tersedia.');
            }
        }

        foreach (self::PARAF_PLACEHOLDERS as $placeholder) {
            if ($phaseFlags['include_' . $placeholder]) {
                $templateProcessor->setImageValue($placeholder, array_merge([
                    'path' => $parafPath,
                ], self::PARAF_IMAGE_DIMENSIONS));
            } else {
                $templateProcessor->setValue($placeholder, '');
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, bool> $phaseFlags
     */
    private function assertRequiredTextValues(array $payload, array $phaseFlags): void
    {
        $requirements = [
            'rendered.tanggal_surat' => 'tanggal surat',
            'application.nama_perusahaan' => 'nama perusahaan',
            'application.kegiatan' => 'kegiatan',
            'application.posisi' => 'posisi',
            'student.nama' => 'nama mahasiswa',
            'student.nim' => 'NIM mahasiswa',
            'student.prodi' => 'program studi mahasiswa',
            'student.departemen' => 'departemen mahasiswa',
            'student.fakultas' => 'fakultas mahasiswa',
            'internship.dpa' => 'DPA',
            'internship.tgl_mulai' => 'tanggal mulai',
            'internship.tgl_selesai' => 'tanggal selesai',
            'rendered.jabatan_kadep' => 'jabatan Kadep',
            'rendered.nama_kadep' => 'nama Kadep',
            'rendered.nip_kadep' => 'NIP Kadep',
        ];

        if ($phaseFlags['include_nomor_tugas']) {
            $requirements['application.nomor_surat_tugas'] = 'nomor surat tugas';
        }

        foreach ($requirements as $path => $label) {
            if (!$this->hasRequiredValue($this->valueAtPath($payload, $path))) {
                throw new RuntimeException("Dokumen Surat Tugas tidak dapat dibuat: {$label} wajib tersedia.");
            }
        }
    }

    private function hasRequiredValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        $value = trim((string) $value);

        return $value !== '' && $value !== '-';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function valueAtPath(array $payload, string $path): mixed
    {
        $value = $payload;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function applicationSnapshot(
        SuratTugasApplication $application,
        array $overrides,
    ): SuratTugasApplication {
        $snapshot = $application->newInstance($application->getAttributes(), true);
        $snapshot->setAttribute($application->getKeyName(), $application->getKey());
        $snapshot->exists = $application->exists;
        $snapshot->setRelations($application->getRelations());

        foreach ([
            'status',
            'nomor_surat_tugas',
            'nama_perusahaan',
            'kegiatan',
            'posisi',
            'tgl_mulai',
            'tgl_selesai',
            'dosen_pembimbing_dpa',
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
    private function officialKadepForRender(SuratTugasApplication $application, array $overrides): ?User
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
            throw new RuntimeException('Generated Surat Tugas phase document contains unresolved placeholders: ' . implode(', ', $unresolved));
        }
    }

    /**
     * @return array<string, string>
     */
    private function wordXmlEntries(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open generated Surat Tugas DOCX: {$path}");
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
                throw new RuntimeException("Generated Surat Tugas DOCX has no inspectable Word XML entries: {$path}");
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
            throw new InvalidArgumentException("Unsupported Surat Tugas document phase: {$phase}");
        }
    }
}
