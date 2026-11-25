<?php

namespace App\Support;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;

class LetterAttachmentDefinitionRegistry
{
    /**
     * Total upload budget for all supporting documents of one letter type.
     * Each document's per-file cap is this budget split evenly across the
     * letter's document slots (e.g. 3 slots → 5 MB each), so a full
     * submission can never exceed the total.
     */
    public const TOTAL_BUDGET_KB = 15 * 1024;

    /**
     * Supporting-document definitions only. Workflow transitions and
     * authorization remain in their dedicated services.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $letters = self::definitions();

        foreach ($letters as &$letter) {
            $count = count($letter['documents']);
            if ($count === 0) {
                continue;
            }
            $perFileKb = intdiv(self::TOTAL_BUDGET_KB, $count);
            foreach ($letter['documents'] as &$document) {
                $document['max_kb'] = $perFileKb;
            }
            unset($document);
        }
        unset($letter);

        return $letters;
    }

    /**
     * Per-file size cap (KB) for a document, derived from the total budget.
     */
    public static function maxKb(string $letterType, string $documentKey): int
    {
        return (int) (self::document($letterType, $documentKey)['max_kb'] ?? self::TOTAL_BUDGET_KB);
    }

    /**
     * Human-readable size label for a cap, e.g. "5 MB" or "7,5 MB".
     */
    public static function formatKb(int $kb): string
    {
        $mb = round($kb / 1024, 1);
        $text = fmod($mb, 1.0) === 0.0 ? (string) (int) $mb : str_replace('.', ',', (string) $mb);

        return $text . ' MB';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function definitions(): array
    {
        return [
            ScholarshipApplication::LETTER_TYPE => [
                'application_model' => ScholarshipApplication::class,
                'documents' => [
                    'transkrip_nilai' => self::definition(
                        'Transkrip Nilai',
                        'surat-permohonan-beasiswa/transkrip-nilai',
                        true,
                        'transkrip_nilai_path',
                        'public',
                        'scholarships/transcripts/',
                    ),
                    'slip_gaji_ayah' => self::definition(
                        'Slip Gaji / Penghasilan Ayah/Wali',
                        'surat-permohonan-beasiswa/slip-gaji-ayah',
                        true,
                        'slip_gaji_ayah_path',
                        'public',
                        'scholarships/slips/',
                    ),
                    'slip_gaji_ibu' => self::definition(
                        'Slip Gaji / Penghasilan Ibu',
                        'surat-permohonan-beasiswa/slip-gaji-ibu',
                        true,
                        'slip_gaji_ibu_path',
                        'public',
                        'scholarships/slips/',
                    ),
                ],
            ],
            SuratPengantarMagangApplication::LETTER_TYPE => [
                'application_model' => SuratPengantarMagangApplication::class,
                'documents' => [
                    'proposal' => self::definition(
                        'Proposal Kegiatan Magang',
                        'surat-pengantar-magang/proposal',
                        true,
                        'proposal_kegiatan_magang_path',
                        'public',
                        'surat-pengantar-magang/proposals/',
                    ),
                ],
            ],
            SuratTugasApplication::LETTER_TYPE => [
                'application_model' => SuratTugasApplication::class,
                'documents' => [
                    'proposal' => self::definition(
                        'Proposal Kegiatan Magang',
                        'surat-tugas/proposal',
                        true,
                        'proposal_kegiatan_magang_path',
                        'local',
                        'surat-tugas/supporting/proposals/',
                    ),
                    'surat_pengantar_magang' => self::definition(
                        'Surat Pengantar Magang',
                        'surat-tugas/surat-pengantar-magang',
                        true,
                        'surat_pengantar_magang_path',
                        'local',
                        'surat-tugas/supporting/pengantar/',
                    ),
                ],
            ],
            SuratKeteranganAktifApplication::LETTER_TYPE => [
                'application_model' => SuratKeteranganAktifApplication::class,
                'documents' => [],
            ],
            ProsesLuarNegeriApplication::LETTER_TYPE => [
                'application_model' => ProsesLuarNegeriApplication::class,
                'documents' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forLetter(string $letterType): ?array
    {
        $canonicalType = LetterTypeRegistry::canonicalize($letterType);

        return $canonicalType ? (self::all()[$canonicalType] ?? null) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function document(string $letterType, string $documentKey): ?array
    {
        $letter = self::forLetter($letterType);

        return $letter['documents'][$documentKey] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function definition(
        string $label,
        string $privatePrefixSegment,
        bool $requiredOnSubmit,
        string $legacyAttribute,
        string $legacyDisk,
        string $legacyPrefix,
    ): array {
        return [
            'label' => $label,
            'required_on_submit' => $requiredOnSubmit,
            'mime_types' => ['application/pdf'],
            'max_kb' => self::TOTAL_BUDGET_KB, // overwritten in all()
            'storage_disk' => 'local',
            'storage_prefix' => 'letter-application-attachments/' . $privatePrefixSegment . '/',
            'preview' => true,
            'legacy' => [
                'attribute' => $legacyAttribute,
                'disk' => $legacyDisk,
                'prefix' => $legacyPrefix,
            ],
        ];
    }
}
