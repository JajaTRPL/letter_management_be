<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BeasiswaPreviewGenerationService
{
    public function __construct(
        private BeasiswaPhaseResolver $phaseResolver,
        private LetterDocumentSourceHashService $sourceHashService,
        private LetterDocumentArtifactService $artifactService,
        private ScholarshipAutomationService $scholarshipAutomationService,
        private DocumentConverter $documentConverter,
        private int $lockTtlSeconds = 120,
        private int $lockWaitSeconds = 10,
    ) {
    }

    /**
     * Generate or reuse a READY DOCX+PDF preview artifact for the application's
     * current Beasiswa phase. This does not mutate workflow status or legacy
     * generated_docx_path/generated_pdf_path compatibility columns.
     *
     * @param array<string, mixed> $pendingOverrides In-memory render values such as nomor_surat or tanggal_surat.
     */
    public function generateForCurrentPhase(
        ScholarshipApplication $application,
        array $pendingOverrides = [],
        ?int $generatedBy = null,
    ): LetterDocumentArtifact {
        $phase = $this->phaseResolver->phaseFor($application);
        if ($phase === null) {
            throw new BeasiswaPreviewGenerationException('Beasiswa preview phase is unavailable for this status.', [
                'letter_type' => ScholarshipApplication::LETTER_TYPE,
                'application_id' => $application->getKey(),
                'status' => $application->getAttribute('status'),
            ]);
        }

        return $this->generateForPhase($application, $phase, $pendingOverrides, $generatedBy);
    }

    /**
     * @param array<string, mixed> $pendingOverrides
     */
    public function generateForPhase(
        ScholarshipApplication $application,
        string $phase,
        array $pendingOverrides = [],
        ?int $generatedBy = null,
    ): LetterDocumentArtifact {
        $this->assertValidPhase($phase);

        $renderOverrides = $this->renderOverrides($application, $phase, $pendingOverrides);
        $renderApplication = $this->applicationSnapshot($application, $renderOverrides);
        $phaseFlags = $this->phaseResolver->phaseFlagsFor($renderApplication, $phase);
        $sourceHash = $this->sourceHashService->hashForBeasiswa(
            $application,
            $phase,
            $phaseFlags,
            $renderOverrides,
        );

        $cached = $this->artifactService->findReadyBySourceHash(
            ScholarshipApplication::LETTER_TYPE,
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
                ScholarshipApplication::LETTER_TYPE,
                (int) $application->getKey(),
                $phase,
                $sourceHash,
            );
            if ($cached) {
                return $cached;
            }

            $artifact = $this->artifactService->createGenerating(
                ScholarshipApplication::LETTER_TYPE,
                (int) $application->getKey(),
                $phase,
                $sourceHash,
                $generatedBy,
            );
            $pdfPath = null;

            try {
                $docxPath = $this->scholarshipAutomationService->generateDocumentForPhase(
                    $application,
                    $phase,
                    $renderOverrides,
                );
                if ($docxPath === false || !Storage::disk('local')->exists($docxPath)) {
                    throw new BeasiswaPreviewGenerationException('Beasiswa preview DOCX generation failed.', [
                        'artifact_id' => $artifact->getKey(),
                        'application_id' => $application->getKey(),
                        'phase' => $phase,
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
                    throw new BeasiswaPreviewGenerationException('Beasiswa preview PDF was not written.', [
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

                if ($exception instanceof BeasiswaPreviewGenerationException) {
                    throw $exception;
                }

                throw new BeasiswaPreviewGenerationException('Beasiswa preview artifact generation failed.', [
                    'artifact_id' => $artifact->getKey(),
                    'application_id' => $application->getKey(),
                    'phase' => $phase,
                    'source_hash' => $sourceHash,
                ], $exception);
            }
        });
    }

    public function lockKeyFor(ScholarshipApplication $application, string $phase): string
    {
        return 'letter-document-artifacts:'
            . ScholarshipApplication::LETTER_TYPE
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
    private function withPhaseLock(ScholarshipApplication $application, string $phase, callable $callback): mixed
    {
        $lock = Cache::lock($this->lockKeyFor($application, $phase), $this->lockTtlSeconds);

        try {
            if ($this->lockWaitSeconds <= 0) {
                if (!$lock->get()) {
                    throw new LockTimeoutException('Unable to acquire Beasiswa preview generation lock.');
                }

                try {
                    return $callback();
                } finally {
                    $lock->release();
                }
            }

            return $lock->block($this->lockWaitSeconds, $callback);
        } catch (LockTimeoutException $exception) {
            throw new BeasiswaPreviewGenerationException('Beasiswa preview generation is already in progress.', [
                'application_id' => $application->getKey(),
                'phase' => $phase,
                'lock_key' => $this->lockKeyFor($application, $phase),
            ], $exception);
        }
    }

    /**
     * @param array<string, mixed> $pendingOverrides
     * @return array<string, mixed>
     */
    private function renderOverrides(ScholarshipApplication $application, string $phase, array $pendingOverrides): array
    {
        if (!array_key_exists('tanggal_surat', $pendingOverrides)) {
            $pendingOverrides['tanggal_surat'] = $this->resolveTanggalSurat($application, $phase);
        }

        return $pendingOverrides;
    }

    private function resolveTanggalSurat(ScholarshipApplication $application, string $phase): Carbon
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

    private function previewPdfPath(ScholarshipApplication $application, string $phase): string
    {
        return 'letter-document-artifacts/'
            . ScholarshipApplication::LETTER_TYPE
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

    private function assertValidPhase(string $phase): void
    {
        if (!in_array($phase, LetterDocumentArtifact::PHASES, true)) {
            throw new BeasiswaPreviewGenerationException('Unsupported Beasiswa preview phase.', [
                'phase' => $phase,
            ]);
        }
    }
}
