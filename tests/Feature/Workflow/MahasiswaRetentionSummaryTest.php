<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MahasiswaRetentionSummaryTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-06-10 09:00:00');
        Carbon::setTestNow($this->now);
        Storage::fake('local');
        Storage::fake('archive');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_mahasiswa_detail_payloads_expose_generic_retention_summary_for_all_letter_types(): void
    {
        [$student] = $this->completeMahasiswa();
        $applications = [
            [
                'endpoint' => '/api/mahasiswa/surat-permohonan-beasiswa/%d',
                'letter_type' => ScholarshipApplication::LETTER_TYPE,
                'application' => $this->completedApplication(
                    $this->scholarshipApplication($student),
                    ScholarshipApplication::STATUS_COMPLETED,
                ),
            ],
            [
                'endpoint' => '/api/mahasiswa/surat-keterangan-aktif/%d',
                'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
                'application' => $this->completedApplication(
                    $this->aktifApplication($student),
                    SuratKeteranganAktifApplication::STATUS_COMPLETED,
                ),
            ],
            [
                'endpoint' => '/api/mahasiswa/proses-luar-negeri/%d',
                'letter_type' => ProsesLuarNegeriApplication::LETTER_TYPE,
                'application' => $this->completedApplication(
                    $this->prosesLuarNegeriApplication($student),
                    ProsesLuarNegeriApplication::STATUS_COMPLETED,
                ),
            ],
            [
                'endpoint' => '/api/mahasiswa/surat-pengantar-magang/%d',
                'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
                'application' => $this->completedApplication(
                    $this->magangApplication($student),
                    SuratPengantarMagangApplication::STATUS_COMPLETED,
                ),
            ],
            [
                'endpoint' => '/api/mahasiswa/surat-tugas/%d',
                'letter_type' => SuratTugasApplication::LETTER_TYPE,
                'application' => $this->completedApplication(
                    $this->suratTugasApplication($student),
                    SuratTugasApplication::STATUS_COMPLETED,
                ),
            ],
        ];

        foreach ($applications as $case) {
            /** @var Model $application */
            $application = $case['application'];
            $artifact = $this->readyFinalArtifact($application, $case['letter_type']);

            $response = $this->actingAs($student, 'sanctum')
                ->getJson(sprintf($case['endpoint'], $application->getKey()))
                ->assertOk()
                ->assertJsonPath('application.retention_summary.final_download_state', 'active')
                ->assertJsonPath('application.retention_summary.final_download_available', true)
                ->assertJsonPath('application.retention_summary.final_download_expires_at', $this->now->copy()->addDays(20)->toIso8601String());

            $content = $response->getContent();
            $this->assertStringNotContainsString($artifact->pdf_path, $content);
            $this->assertStringNotContainsString('archive_path', $content);
            $this->assertStringNotContainsString('checksum_sha256', $content);
        }
    }

    public function test_summary_countdown_starts_only_for_completed_with_completed_at(): void
    {
        [$student] = $this->completeMahasiswa();
        $submitted = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'completed_at' => null,
        ]);
        $completedWithoutTimestamp = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_COMPLETED,
            'completed_at' => null,
        ]);
        $this->readyFinalArtifact($submitted, ScholarshipApplication::LETTER_TYPE);
        $this->readyFinalArtifact($completedWithoutTimestamp, ScholarshipApplication::LETTER_TYPE);

        foreach ([$submitted, $completedWithoutTimestamp] as $application) {
            $this->actingAs($student, 'sanctum')
                ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}")
                ->assertOk()
                ->assertJsonPath('application.retention_summary.completed_at', null)
                ->assertJsonPath('application.retention_summary.final_download_available', false)
                ->assertJsonPath('application.retention_summary.final_download_expires_at', null)
                ->assertJsonPath('application.retention_summary.final_download_state', 'not_started');
        }
    }

    public function test_archived_final_pdf_is_not_student_available_and_archive_metadata_is_not_serialized(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->completedApplication(
            $this->scholarshipApplication($student),
            ScholarshipApplication::STATUS_COMPLETED,
            31,
        );
        $artifact = $this->readyFinalArtifact($application, ScholarshipApplication::LETTER_TYPE);
        Storage::disk('local')->delete($artifact->pdf_path);
        $artifact->forceFill([
            'archive_disk' => 'archive',
            'archive_path' => 'final-pdfs/surat-permohonan-beasiswa/' . $application->id . '/archived.pdf',
            'archive_checksum_sha256' => hash('sha256', '%PDF archived'),
            'archived_at' => $this->now->copy()->subDay(),
            'retention_status' => 'archived',
        ])->save();

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.retention_summary.final_download_available', false)
            ->assertJsonPath('application.retention_summary.final_download_state', 'archived');

        $content = $response->getContent();
        $this->assertStringNotContainsString((string) $artifact->archive_path, $content);
        $this->assertStringNotContainsString('archive_disk', $content);
        $this->assertStringNotContainsString('archive_path', $content);
        $this->assertStringNotContainsString('archive_checksum_sha256', $content);
    }

    public function test_supporting_document_retention_deleted_state_has_no_preview_metadata(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->completedApplication(
            $this->scholarshipApplication($student),
            ScholarshipApplication::STATUS_COMPLETED,
            14,
        );
        $this->readyFinalArtifact($application, ScholarshipApplication::LETTER_TYPE);
        $this->attachBeasiswaRequiredDocuments($application);

        LetterApplicationAttachment::query()
            ->where('letter_type', ScholarshipApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->get()
            ->each(function (LetterApplicationAttachment $attachment): void {
                Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
                $attachment->forceFill([
                    'retention_deleted_at' => $this->now,
                    'retention_status' => 'deleted',
                ])->save();
            });

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.retention_summary.supporting_documents_state', 'deleted')
            ->assertJsonPath('application.supporting_documents.transkrip_nilai.exists', false)
            ->assertJsonPath('application.supporting_documents.transkrip_nilai.preview_available', false)
            ->assertJsonPath('application.supporting_documents.slip_gaji_ayah.exists', false)
            ->assertJsonPath('application.supporting_documents.slip_gaji_ibu.exists', false);
    }

    private function completedApplication(Model $application, string $completedStatus, int $daysAgo = 10): Model
    {
        $application->forceFill([
            'status' => $completedStatus,
            'completed_at' => $this->now->copy()->subDays($daysAgo),
            'student_reviewed_at' => $this->now->copy()->subDays($daysAgo),
        ])->save();

        return $application->refresh();
    }

    private function readyFinalArtifact(Model $application, string $letterType): LetterDocumentArtifact
    {
        $path = 'letter-document-artifacts/'
            . $letterType
            . '/'
            . $application->getKey()
            . '/'
            . LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW
            . '/final.pdf';
        Storage::disk('local')->put($path, '%PDF final');

        return LetterDocumentArtifact::create([
            'letter_type' => $letterType,
            'application_id' => $application->getKey(),
            'phase' => LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            'version' => 1,
            'docx_path' => null,
            'pdf_path' => $path,
            'source_hash' => hash('sha256', $letterType . ':' . $application->getKey()),
            'status' => LetterDocumentArtifact::STATUS_READY,
            'error_message' => null,
            'generated_by' => $application->getAttribute('user_id'),
            'generated_at' => $this->now,
        ]);
    }
}
