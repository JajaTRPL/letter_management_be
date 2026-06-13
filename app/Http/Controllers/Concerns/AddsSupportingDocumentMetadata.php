<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ScholarshipApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Services\LetterAttachmentMetadataService;
use App\Services\LetterRetentionSummaryService;
use Illuminate\Database\Eloquent\Model;

trait AddsSupportingDocumentMetadata
{
    protected function withSupportingDocumentMetadata(
        Model $application,
        string $letterType,
        LetterAttachmentMetadataService $metadataService,
        ?LetterRetentionSummaryService $retentionSummaryService = null,
    ): Model {
        $application->setAttribute(
            'supporting_documents',
            $metadataService->forApplication($application, $letterType),
        );

        if ($retentionSummaryService) {
            $application->setAttribute(
                'retention_summary',
                $retentionSummaryService->forApplication($application, $letterType),
            );
        }

        return $this->withRetiredAttachmentFieldsHidden($application, $letterType);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function withSupportingDocumentMetadataPayload(
        array $payload,
        Model $application,
        string $letterType,
        LetterAttachmentMetadataService $metadataService,
        ?LetterRetentionSummaryService $retentionSummaryService = null,
    ): array {
        $payload['supporting_documents'] = $metadataService->forApplication($application, $letterType);

        if ($retentionSummaryService) {
            $payload['retention_summary'] = $retentionSummaryService->forApplication($application, $letterType);
        }

        return $this->withoutRetiredAttachmentResponseFields($payload, $letterType);
    }

    /**
     * @return string[]
     */
    private function retiredAttachmentResponseFields(string $letterType): array
    {
        return match ($letterType) {
            ScholarshipApplication::LETTER_TYPE => [
                'transkrip_nilai_path',
                'slip_gaji_ayah_path',
                'slip_gaji_ibu_path',
                'ktm_path',
            ],
            SuratPengantarMagangApplication::LETTER_TYPE => [
                'proposal_kegiatan_magang_path',
            ],
            SuratTugasApplication::LETTER_TYPE => [
                'proposal_kegiatan_magang_path',
                'surat_pengantar_magang_path',
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withoutRetiredAttachmentResponseFields(array $payload, string $letterType): array
    {
        foreach ($this->retiredAttachmentResponseFields($letterType) as $field) {
            unset($payload[$field]);
        }

        return $payload;
    }

    protected function withRetiredAttachmentFieldsHidden(Model $application, string $letterType): Model
    {
        $application->makeHidden($this->retiredAttachmentResponseFields($letterType));

        return $application;
    }
}
