<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratPengantarMagangApplication;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Phase-aware Magang preview orchestration. It renders a private DOCX and PDF
 * artifact for the two-section final contract without changing workflow state,
 * generated_pdf_path, public generated files, or the legacy DomPDF path.
 */
class SuratPengantarMagangPreviewGenerationService
{
    public function __construct(
        private SuratPengantarMagangPhaseResolver $phaseResolver,
        private LetterDocumentSourceHashService $sourceHashService,
        private LetterDocumentArtifactService $artifactService,
        private SuratPengantarMagangDocumentGenerationService $documentGenerationService,
        private DocumentConverter $documentConverter,
        private int $lockTtlSeconds = 120,
        private int $lockWaitSeconds = 10,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides In-memory render values such as
     *     nomor_surat_pengantar, nomor_surat_tugas, tanggal_surat, or official_kadep.
     */
    public function generateForCurrentPhase(
        SuratPengantarMagangApplication $application,
        array $overrides = [],
        ?int $generatedBy = null,
    ): LetterDocumentArtifact {
        $phase = $this->phaseResolver->phaseFor($application);
        if ($phase === null) {
            throw new SuratPengantarMagangPreviewGenerationException('Magang preview phase is unavailable for this status.', [
                'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
                'application_id' => $application->getKey(),
                'status' => $application->getAttribute('status'),
            ]);
        }

        return $this->generateForPhase($application, $phase, $overrides, $generatedBy);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function generateForPhase(
        SuratPengantarMagangApplication $application,
        string $phase,
        array $overrides = [],
        ?int $generatedBy = null,
    ): LetterDocumentArtifact {
        $this->assertValidPhase($phase);

        $renderOverrides = $this->renderOverrides($application, $phase, $overrides);
        $renderApplication = $this->applicationSnapshot($application, $renderOverrides);
        $phaseFlags = $this->phaseResolver->phaseFlagsFor($renderApplication, $phase);
        $sourceHash = $this->sourceHashService->hashForMagang(
            $application,
            $phase,
            $phaseFlags,
            $renderOverrides,
        );

        $cached = $this->artifactService->findReadyBySourceHash(
            SuratPengantarMagangApplication::LETTER_TYPE,
            (int) $application->getKey(),
            $phase,
            $sourceHash,
        );
        if ($cached) {
            return $cached;
        }

        return $this->withPhaseLock($application, $phase, function () use (
            $application,
            $phase,
            $sourceHash,
            $renderOverrides,
            $generatedBy,
        ) {
            $cached = $this->artifactService->findReadyBySourceHash(
                SuratPengantarMagangApplication::LETTER_TYPE,
                (int) $application->getKey(),
                $phase,
                $sourceHash,
            );
            if ($cached) {
                return $cached;
            }

            $artifact = $this->artifactService->createGenerating(
                SuratPengantarMagangApplication::LETTER_TYPE,
                (int) $application->getKey(),
                $phase,
                $sourceHash,
                $generatedBy,
            );
            $pdfPath = null;

            try {
                $docxPath = $this->documentGenerationService->generateDocumentForPhase(
                    $application,
                    $phase,
                    $renderOverrides,
                );
                if (!Storage::disk('local')->exists($docxPath)) {
                    throw new SuratPengantarMagangPreviewGenerationException('Magang preview DOCX generation failed.', [
                        'artifact_id' => $artifact->getKey(),
                        'application_id' => $application->getKey(),
                        'phase' => $phase,
                        'docx_path' => $docxPath,
                    ]);
                }

                $artifact->update(['docx_path' => $docxPath]);
                $pdfPath = $this->previewPdfPath($application, $phase);
                $this->ensureLocalDirectory(dirname($pdfPath));

                $this->documentConverter->convertDocxToPdf(
                    Storage::disk('local')->path($docxPath),
                    Storage::disk('local')->path($pdfPath),
                );

                if (!Storage::disk('local')->exists($pdfPath)) {
                    throw new SuratPengantarMagangPreviewGenerationException('Magang preview PDF was not written.', [
                        'artifact_id' => $artifact->getKey(),
                        'application_id' => $application->getKey(),
                        'phase' => $phase,
                        'pdf_path' => $pdfPath,
                    ]);
                }

                return $this->artifactService->markReady($artifact, $pdfPath, $docxPath);
            } catch (Throwable $exception) {
                if ($pdfPath && Storage::disk('local')->exists($pdfPath)) {
                    Storage::disk('local')->delete($pdfPath);
                }

                $this->artifactService->markFailed($artifact, $exception->getMessage());

                if ($exception instanceof SuratPengantarMagangPreviewGenerationException) {
                    throw $exception;
                }

                throw new SuratPengantarMagangPreviewGenerationException('Magang preview artifact generation failed.', [
                    'artifact_id' => $artifact->getKey(),
                    'application_id' => $application->getKey(),
                    'phase' => $phase,
                    'source_hash' => $sourceHash,
                ], $exception);
            }
        });
    }

    public function lockKeyFor(SuratPengantarMagangApplication $application, string $phase): string
    {
        return 'letter-document-artifacts:'
            . SuratPengantarMagangApplication::LETTER_TYPE
            . ':'
            . (int) $application->getKey()
            . ':'
            . $phase;
    }

    /**
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    private function withPhaseLock(SuratPengantarMagangApplication $application, string $phase, callable $callback): mixed
    {
        $lock = Cache::lock($this->lockKeyFor($application, $phase), $this->lockTtlSeconds);

        try {
            if ($this->lockWaitSeconds <= 0) {
                if (!$lock->get()) {
                    throw new LockTimeoutException('Unable to acquire Magang preview generation lock.');
                }

                try {
                    return $callback();
                } finally {
                    $lock->release();
                }
            }

            return $lock->block($this->lockWaitSeconds, $callback);
        } catch (LockTimeoutException $exception) {
            throw new SuratPengantarMagangPreviewGenerationException('Magang preview generation is already in progress.', [
                'application_id' => $application->getKey(),
                'phase' => $phase,
                'lock_key' => $this->lockKeyFor($application, $phase),
            ], $exception);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function renderOverrides(SuratPengantarMagangApplication $application, string $phase, array $overrides): array
    {
        if (!array_key_exists('tanggal_surat', $overrides)) {
            $overrides['tanggal_surat'] = $this->resolveTanggalSurat($application, $phase);
        }

        return $overrides;
    }

    private function resolveTanggalSurat(SuratPengantarMagangApplication $application, string $phase): Carbon
    {
        if ($phase === LetterDocumentArtifact::PHASE_TENDIK_REVIEW) {
            return $this->dateOnly(
                $application->getAttribute('submitted_at')
                    ?? $application->getAttribute('created_at')
                    ?? Carbon::now(),
            );
        }

        return $this->dateOnly(
            $application->getAttribute('tendik_approved_at')
                ?? $application->getAttribute('submitted_at')
                ?? $application->getAttribute('created_at')
                ?? Carbon::now(),
        );
    }

    private function dateOnly(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        return Carbon::parse($value)->startOfDay();
    }

    private function previewPdfPath(SuratPengantarMagangApplication $application, string $phase): string
    {
        return 'letter-document-artifacts/'
            . SuratPengantarMagangApplication::LETTER_TYPE
            . '/'
            . (int) $application->getKey()
            . '/'
            . $phase
            . '/preview_'
            . time()
            . '_'
            . str_replace('.', '', uniqid('', true))
            . '.pdf';
    }

    private function ensureLocalDirectory(string $directory): void
    {
        if (!Storage::disk('local')->exists($directory)) {
            Storage::disk('local')->makeDirectory($directory);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function applicationSnapshot(
        SuratPengantarMagangApplication $application,
        array $overrides,
    ): SuratPengantarMagangApplication {
        $snapshot = $application->newInstance($application->getAttributes(), true);
        $snapshot->setAttribute($application->getKeyName(), $application->getKey());
        $snapshot->exists = $application->exists;
        $snapshot->setRelations($application->getRelations());

        foreach ([
            'status',
            'nomor_surat_pengantar',
            'nomor_surat_tugas',
            'jabatan_penerima',
            'nama_perusahaan',
            'alamat_jalan',
            'alamat_kelurahan',
            'alamat_kecamatan',
            'alamat_kota_kabupaten',
            'alamat_provinsi',
            'kode_pos',
            'tgl_mulai',
            'tgl_selesai',
            'dosen_pembimbing_dpa',
            'peran',
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

    private function assertValidPhase(string $phase): void
    {
        if (!in_array($phase, LetterDocumentArtifact::PHASES, true)) {
            throw new SuratPengantarMagangPreviewGenerationException('Unsupported Magang preview phase.', [
                'phase' => $phase,
            ]);
        }
    }
}
