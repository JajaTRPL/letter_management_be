<?php

namespace App\Services;

use App\Models\LetterDocumentArtifact;
use Illuminate\Support\Carbon;

/**
 * Foundation operations for the letter_document_artifacts ledger.
 *
 * Phase 1 scope: read-side resolution + write-side state-machine helpers.
 * This service does NOT call any DocumentConverter, does NOT mutate workflow
 * status, and is not invoked by any controller in this phase. Wiring lands
 * in Phase 2.
 */
class LetterDocumentArtifactService
{
    /**
     * Highest-version READY artifact for a given (letter, application, phase),
     * or null if none yet exists in that state.
     */
    public function latestReadyArtifact(string $letterType, int $applicationId, string $phase): ?LetterDocumentArtifact
    {
        return LetterDocumentArtifact::query()
            ->forApplication($letterType, $applicationId)
            ->forPhase($phase)
            ->ready()
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Highest-version artifact for a (letter, application, phase) regardless of
     * status. Useful for resuming an in-progress attempt or surfacing the most
     * recent failure to operators. Status MUST be inspected by the caller.
     */
    public function latestArtifact(string $letterType, int $applicationId, string $phase): ?LetterDocumentArtifact
    {
        return LetterDocumentArtifact::query()
            ->forApplication($letterType, $applicationId)
            ->forPhase($phase)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Idempotency lookup: return a READY artifact whose source_hash matches the
     * supplied input — used by callers that want to skip a re-conversion when
     * the canonical inputs have not changed. Returns the highest-version
     * matching row in case of ties.
     */
    public function findReadyBySourceHash(
        string $letterType,
        int $applicationId,
        string $phase,
        string $sourceHash,
    ): ?LetterDocumentArtifact {
        return LetterDocumentArtifact::query()
            ->forApplication($letterType, $applicationId)
            ->forPhase($phase)
            ->ready()
            ->where('source_hash', $sourceHash)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Next monotonic version for a (letter, application, phase). Returns 1 when
     * no prior row exists, otherwise (max(version) + 1). The caller is expected
     * to take a per-application lock before reading + inserting to avoid races.
     */
    public function nextVersion(string $letterType, int $applicationId, string $phase): int
    {
        $max = LetterDocumentArtifact::query()
            ->forApplication($letterType, $applicationId)
            ->forPhase($phase)
            ->max('version');

        return (int) ($max ?? 0) + 1;
    }

    /**
     * Persist a new artifact row with status=generating. docx_path/pdf_path are
     * typically null at this point — they will be filled in by markReady once
     * the converter writes the file.
     */
    public function createGenerating(
        string $letterType,
        int $applicationId,
        string $phase,
        string $sourceHash,
        ?int $generatedBy = null,
        ?string $docxPath = null,
    ): LetterDocumentArtifact {
        return LetterDocumentArtifact::create([
            'letter_type' => $letterType,
            'application_id' => $applicationId,
            'phase' => $phase,
            'version' => $this->nextVersion($letterType, $applicationId, $phase),
            'docx_path' => $docxPath,
            'pdf_path' => null,
            'source_hash' => $sourceHash,
            'status' => LetterDocumentArtifact::STATUS_GENERATING,
            'error_message' => null,
            'generated_by' => $generatedBy,
            'generated_at' => null,
        ]);
    }

    /**
     * Promote a generating artifact to ready and record paths. Returns the
     * freshly-refreshed model.
     */
    public function markReady(
        LetterDocumentArtifact $artifact,
        string $pdfPath,
        ?string $docxPath = null,
        ?Carbon $generatedAt = null,
    ): LetterDocumentArtifact {
        $attributes = [
            'status' => LetterDocumentArtifact::STATUS_READY,
            'pdf_path' => $pdfPath,
            'error_message' => null,
            'generated_at' => $generatedAt ?? Carbon::now(),
        ];
        if ($docxPath !== null) {
            $attributes['docx_path'] = $docxPath;
        }
        $artifact->update($attributes);

        return $artifact->refresh();
    }

    /**
     * Mark a generating artifact as failed, preserving an operator-readable
     * error message. The pdf_path is intentionally NOT cleared on failure so
     * if a converter wrote a partial file the operator can inspect it; the
     * caller is expected to clean up partial files separately.
     */
    public function markFailed(LetterDocumentArtifact $artifact, string $errorMessage): LetterDocumentArtifact
    {
        $artifact->update([
            'status' => LetterDocumentArtifact::STATUS_FAILED,
            'error_message' => $errorMessage,
            'generated_at' => Carbon::now(),
        ]);

        return $artifact->refresh();
    }
}
