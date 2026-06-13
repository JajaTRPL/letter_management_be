<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Services\LetterDocumentAccessService;
use App\Services\LetterFinalDownloadAccessResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FinalDownloadRetentionGateTest extends TestCase
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

    public function test_completed_but_expired_active_final_pdf_is_not_downloadable_for_all_letters(): void
    {
        [$student] = $this->completeMahasiswa();

        foreach ($this->finalDownloadCases($student, 31) as $case) {
            /** @var Model $application */
            $application = $case['application'];
            $artifact = $this->readyFinalArtifact($application, $case['letter_type']);

            Storage::disk('local')->assertExists($artifact->pdf_path);

            $response = $this->actingAs($student, 'sanctum')
                ->getJson(sprintf($case['endpoint'], $application->getKey()))
                ->assertNotFound()
                ->assertJsonPath('reason', 'artifact_unavailable');

            $this->assertStringNotContainsString($artifact->pdf_path, $response->getContent());
        }
    }

    public function test_active_completed_final_pdf_downloads_for_all_letters(): void
    {
        [$student] = $this->completeMahasiswa();

        foreach ($this->finalDownloadCases($student, 1) as $case) {
            /** @var Model $application */
            $application = $case['application'];
            $this->readyFinalArtifact($application, $case['letter_type']);

            $response = $this->actingAs($student, 'sanctum')
                ->get(sprintf($case['endpoint'], $application->getKey()))
                ->assertOk();

            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }
    }

    public function test_archived_final_pdf_is_not_downloadable_and_has_no_archive_fallback_for_all_letters(): void
    {
        [$student] = $this->completeMahasiswa();

        foreach ($this->finalDownloadCases($student, 31) as $case) {
            /** @var Model $application */
            $application = $case['application'];
            $artifact = $this->readyFinalArtifact($application, $case['letter_type']);
            $archivePath = 'final-pdfs/' . $case['letter_type'] . '/' . $application->getKey() . '/' . $artifact->id . '/archived.pdf';
            Storage::disk('archive')->put($archivePath, '%PDF archived');
            Storage::disk('local')->delete($artifact->pdf_path);
            $artifact->forceFill([
                'archive_disk' => 'archive',
                'archive_path' => $archivePath,
                'archive_checksum_sha256' => hash('sha256', '%PDF archived'),
                'archived_at' => $this->now->copy()->subDay(),
                'retention_status' => 'archived',
            ])->save();

            $response = $this->actingAs($student, 'sanctum')
                ->getJson(sprintf($case['endpoint'], $application->getKey()))
                ->assertNotFound()
                ->assertJsonPath('reason', 'artifact_unavailable');

            $content = $response->getContent();
            $this->assertStringNotContainsString($archivePath, $content);
            $this->assertStringNotContainsString('archive_path', $content);
            $this->assertStringNotContainsString('archive_checksum_sha256', $content);
            $this->assertStringNotContainsString('checksum_sha256', $content);
        }
    }

    public function test_storage_unavailable_is_rejected_safely_without_raw_storage_leak(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->completedApplication(
            $this->scholarshipApplication($student),
            ScholarshipApplication::STATUS_COMPLETED,
            1,
        );
        $artifact = $this->readyFinalArtifact($application, ScholarshipApplication::LETTER_TYPE);

        Storage::shouldReceive('disk')
            ->with('local')
            ->andThrow(new RuntimeException('local disk unavailable'));

        $response = $this->actingAs($student, 'sanctum')
            ->getJson(sprintf('/api/mahasiswa/surat-permohonan-beasiswa/%d/final-download', $application->getKey()))
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertStringNotContainsString((string) $artifact->pdf_path, $response->getContent());
        $this->assertStringNotContainsString('local disk unavailable', $response->getContent());
    }

    public function test_all_final_download_endpoints_delegate_to_shared_document_access_gate(): void
    {
        [$student] = $this->completeMahasiswa();
        $gate = Mockery::mock(LetterDocumentAccessService::class);
        $gate->shouldReceive('finalDownload')
            ->times(5)
            ->andReturn(LetterFinalDownloadAccessResult::denied(
                'Shared gate probe.',
                'shared_gate_probe',
                418,
            ));
        $this->app->instance(LetterDocumentAccessService::class, $gate);

        foreach ($this->finalDownloadCases($student, 1) as $case) {
            /** @var Model $application */
            $application = $case['application'];
            $this->actingAs($student, 'sanctum')
                ->getJson(sprintf($case['endpoint'], $application->getKey()))
                ->assertStatus(418)
                ->assertJsonPath('reason', 'shared_gate_probe');
        }
    }

    /**
     * @return array<int, array{endpoint: string, letter_type: string, application: Model}>
     */
    private function finalDownloadCases($student, int $completedDaysAgo): array
    {
        return [
            [
                'endpoint' => '/api/mahasiswa/surat-permohonan-beasiswa/%d/final-download',
                'letter_type' => ScholarshipApplication::LETTER_TYPE,
                'application' => $this->completedApplication(
                    $this->scholarshipApplication($student),
                    ScholarshipApplication::STATUS_COMPLETED,
                    $completedDaysAgo,
                ),
            ],
            [
                'endpoint' => '/api/mahasiswa/surat-keterangan-aktif/%d/final-download',
                'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
                'application' => $this->completedApplication(
                    $this->aktifApplication($student),
                    SuratKeteranganAktifApplication::STATUS_COMPLETED,
                    $completedDaysAgo,
                ),
            ],
            [
                'endpoint' => '/api/mahasiswa/proses-luar-negeri/%d/final-download',
                'letter_type' => ProsesLuarNegeriApplication::LETTER_TYPE,
                'application' => $this->completedApplication(
                    $this->prosesLuarNegeriApplication($student),
                    ProsesLuarNegeriApplication::STATUS_COMPLETED,
                    $completedDaysAgo,
                ),
            ],
            [
                'endpoint' => '/api/mahasiswa/surat-pengantar-magang/%d/final-download',
                'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
                'application' => $this->completedApplication(
                    $this->magangApplication($student),
                    SuratPengantarMagangApplication::STATUS_COMPLETED,
                    $completedDaysAgo,
                ),
            ],
            [
                'endpoint' => '/api/mahasiswa/surat-tugas/%d/final-download',
                'letter_type' => SuratTugasApplication::LETTER_TYPE,
                'application' => $this->completedApplication(
                    $this->suratTugasApplication($student),
                    SuratTugasApplication::STATUS_COMPLETED,
                    $completedDaysAgo,
                ),
            ],
        ];
    }

    private function completedApplication(Model $application, string $completedStatus, int $daysAgo): Model
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
