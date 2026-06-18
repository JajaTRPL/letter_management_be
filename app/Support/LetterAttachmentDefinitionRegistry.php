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
     * Supporting-document definitions only. Workflow transitions and
     * authorization remain in their dedicated services.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
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
            'max_kb' => 2048,
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
