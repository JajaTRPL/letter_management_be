<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use App\Services\DocumentConverter;
use App\Services\SuratPengantarMagangDocumentGenerationService;
use App\Services\SuratPengantarMagangPreviewGenerationException;
use App\Services\SuratPengantarMagangPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Magang.4 transition contract: each actionable workflow transition generates
 * its private phase artifact before the locked state update. Kadep approval
 * must not invoke the retired public-PDF compatibility bridge.
 */
class SuratPengantarMagangTransitionArtifactTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-25 09:10:20'));
        Cache::flush();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    public function test_submit_generates_tendik_review_artifact_before_status_mutation(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
        ]);
        $this->attachRegistryDocument($application, SuratPengantarMagangApplication::LETTER_TYPE, 'proposal', 'Proposal Magang.pdf');

        $this->expectPreviewGenerationCall(
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            function (SuratPengantarMagangApplication $renderApplication, array $overrides, ?int $generatedBy) use ($application, $student): void {
                $this->assertSame(SuratPengantarMagangApplication::STATUS_DRAFT, $application->fresh()->status);
                $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $overrides['status']);
                $this->assertSame('2026-05-25 09:10:20', $overrides['submitted_at']->toDateTimeString());
                $this->assertSame('2026-05-25 09:10:20', $overrides['tanggal_surat']->toDateTimeString());
                $this->assertSame($student->id, $generatedBy);
            },
        );

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-pengantar-magang/submit')
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertSame('2026-05-25 09:10:20', $fresh->submitted_at?->toDateTimeString());
        $this->assertSame($tendik->id, $fresh->assigned_to);
    }

    public function test_revision_submit_generates_artifact_and_clears_revision_fields(): void
    {
        $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_REVISION,
            'revision_note' => 'Perbaiki data.',
            'rejection_reason' => 'Alasan lama.',
        ]);
        $this->attachRegistryDocument($application, SuratPengantarMagangApplication::LETTER_TYPE, 'proposal', 'Proposal Magang.pdf');

        $this->expectPreviewGenerationCall(LetterDocumentArtifact::PHASE_TENDIK_REVIEW);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-pengantar-magang/submit')
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->revision_note);
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_submit_artifact_failure_leaves_workflow_state_unchanged(): void
    {
        $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
        ]);
        $this->attachRegistryDocument($application, SuratPengantarMagangApplication::LETTER_TYPE, 'proposal', 'Proposal Magang.pdf');

        $this->mockPreviewGenerationThrows();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-pengantar-magang/submit')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Dokumen pratinjau pengajuan belum dapat dibuat. Silakan coba lagi.');

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->assigned_to);
    }

    public function test_tendik_approve_generates_prodi_review_artifact_with_pending_dual_numbers(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'nomor_surat' => null,
            'nomor_surat_pengantar' => null,
            'nomor_surat_tugas' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $this->expectPreviewGenerationCall(
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            function (SuratPengantarMagangApplication $renderApplication, array $overrides, ?int $generatedBy) use ($application, $tendik): void {
                $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $application->fresh()->status);
                $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, $overrides['status']);
                $this->assertSame('MAG/PENGANTAR/WIRE/001', $overrides['nomor_surat_pengantar']);
                $this->assertSame('MAG/TUGAS/WIRE/001', $overrides['nomor_surat_tugas']);
                $this->assertSame('2026-05-25 09:10:20', $overrides['tendik_approved_at']->toDateTimeString());
                $this->assertSame($tendik->id, $overrides['tendik_approved_by']);
                $this->assertSame($tendik->id, $generatedBy);
            },
        );

        $response = $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/approve", [
                'nomor_surat_pengantar' => 'MAG/PENGANTAR/WIRE/001',
                'nomor_surat_tugas' => 'MAG/TUGAS/WIRE/001',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK)
            ->assertJsonPath('application.nomor_surat_pengantar', 'MAG/PENGANTAR/WIRE/001')
            ->assertJsonPath('application.nomor_surat_tugas', 'MAG/TUGAS/WIRE/001');
        $this->assertArrayNotHasKey('proposal_kegiatan_magang_path', $response->json('application'));

        $fresh = $application->fresh();
        $this->assertSame('MAG/PENGANTAR/WIRE/001', $fresh->nomor_surat);
        $this->assertSame('MAG/PENGANTAR/WIRE/001', $fresh->nomor_surat_pengantar);
        $this->assertSame('MAG/TUGAS/WIRE/001', $fresh->nomor_surat_tugas);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by);
    }

    public function test_tendik_failure_and_legacy_one_number_payload_do_not_mutate_approval(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $failedApplication = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'nomor_surat' => null,
            'nomor_surat_pengantar' => null,
            'nomor_surat_tugas' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $this->mockPreviewGenerationThrows();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$failedApplication->id}/approve", [
                'nomor_surat_pengantar' => 'MAG/PENGANTAR/FAIL',
                'nomor_surat_tugas' => 'MAG/TUGAS/FAIL',
            ])
            ->assertStatus(503);

        $fresh = $failedApplication->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->nomor_surat);
        $this->assertNull($fresh->nomor_surat_pengantar);
        $this->assertNull($fresh->nomor_surat_tugas);
        $this->assertNull($fresh->tendik_approved_at);
        $this->assertNull($fresh->tendik_approved_by);

        $legacyApplication = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);
        $this->mockPreviewGenerationNever();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$legacyApplication->id}/approve", [
                'nomor_surat' => 'MAG/LEGACY/ONLY',
            ])
            ->assertUnprocessable()
            // S1 (Magang standalone): only nomor_surat_pengantar is required now.
            ->assertJsonValidationErrors(['nomor_surat_pengantar'])
            ->assertJsonMissingValidationErrors(['nomor_surat_tugas']);

        $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $legacyApplication->fresh()->status);
        $this->assertNull($legacyApplication->fresh()->nomor_surat);
    }

    public function test_kaprodi_approve_generates_departemen_review_and_failure_or_wrong_scope_does_not_persist(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat_pengantar' => 'MAG/PENGANTAR/002',
            'nomor_surat_tugas' => 'MAG/TUGAS/002',
            'tendik_approved_at' => Carbon::parse('2026-05-24 08:00:00'),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->expectPreviewGenerationCall(
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            function (SuratPengantarMagangApplication $renderApplication, array $overrides) use ($kaprodi): void {
                $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI, $overrides['status']);
                $this->assertSame($kaprodi->id, $overrides['kaprodi_approved_by']);
                $this->assertSame('2026-05-24 08:00:00', $overrides['tanggal_surat']->toDateTimeString());
            },
        );

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI);
        $this->assertSame($kaprodi->id, $application->fresh()->kaprodi_approved_by);

        $failureApplication = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat_pengantar' => 'MAG/PENGANTAR/FAIL-PRODI',
            'nomor_surat_tugas' => 'MAG/TUGAS/FAIL-PRODI',
            'tendik_approved_at' => now(),
        ]);
        $this->mockPreviewGenerationThrows();
        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$failureApplication->id}/approve")
            ->assertStatus(503);
        $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, $failureApplication->fresh()->status);
        $this->assertNull($failureApplication->fresh()->kaprodi_approved_by);

        $wrongProgram = $this->studyProgram($this->department());
        $wrongKaprodi = $this->akademik('kaprodi', ['study_program_id' => $wrongProgram->id]);
        $wrongScopeApplication = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
        ]);
        $this->mockPreviewGenerationNever();
        $this->actingAs($wrongKaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$wrongScopeApplication->id}/approve")
            ->assertForbidden();
        $this->assertNull($wrongScopeApplication->fresh()->kaprodi_approved_by);
    }

    public function test_kadep_approve_generates_mahasiswa_artifact_without_legacy_pdf_mutation(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'MAG/PENGANTAR/003',
            'nomor_surat_pengantar' => 'MAG/PENGANTAR/003',
            'nomor_surat_tugas' => 'MAG/TUGAS/003',
            'tendik_approved_at' => Carbon::parse('2026-05-24 08:00:00'),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => Carbon::parse('2026-05-24 09:00:00'),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $this->expectPreviewGenerationCall(
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            function (SuratPengantarMagangApplication $renderApplication, array $overrides, ?int $generatedBy) use ($application, $kadep): void {
                $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI, $application->fresh()->status);
                $this->assertSame(SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW, $overrides['status']);
                $this->assertSame($kadep->id, $overrides['kadep_approved_by']);
                $this->assertSame('2026-05-24 08:00:00', $overrides['tanggal_surat']->toDateTimeString());
                $this->assertInstanceOf(User::class, $overrides['official_kadep']);
                $this->assertSame($kadep->id, $generatedBy);
            },
        );
        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW)
            ->assertJsonPath('application.generated_pdf_path', null);

        $fresh = $application->fresh();
        $this->assertSame($kadep->id, $fresh->kadep_approved_by);
        Storage::disk('public')->assertMissing('surat-pengantar-magang/generated/legacy-bridge.pdf');
    }

    public function test_kadep_artifact_failure_or_wrong_department_does_not_persist(): void
    {
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat_pengantar' => 'MAG/PENGANTAR/FAIL-KADEP',
            'nomor_surat_tugas' => 'MAG/TUGAS/FAIL-KADEP',
            'tendik_approved_at' => now(),
        ]);

        $this->mockPreviewGenerationThrows();

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertStatus(503);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertNull($fresh->kadep_approved_at);
        $this->assertNull($fresh->kadep_approved_by);

        $otherDepartment = $this->department();
        $wrongKadep = $this->akademik('kadep', ['department_id' => $otherDepartment->id]);
        $wrongScopeApplication = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
        ]);
        $this->mockPreviewGenerationNever();

        $this->actingAs($wrongKadep, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$wrongScopeApplication->id}/approve")
            ->assertForbidden();
        $this->assertNull($wrongScopeApplication->fresh()->kadep_approved_by);
    }

    public function test_ready_artifact_cache_hit_does_not_duplicate_artifact_on_transition(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $preGenerator = new PhaseMagang4FakeDocumentGenerationService();
        $preConverter = new PhaseMagang4FakeDocumentConverter();
        $this->bindPreviewStack($preGenerator, $preConverter);

        $ready = app(SuratPengantarMagangPreviewGenerationService::class)->generateForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            [
                'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
                'nomor_surat_pengantar' => 'MAG/PENGANTAR/CACHE',
                'nomor_surat_tugas' => 'MAG/TUGAS/CACHE',
                'tendik_approved_at' => Carbon::now(),
                'tendik_approved_by' => $tendik->id,
                'tanggal_surat' => Carbon::now(),
            ],
            $tendik->id,
        );

        $transitionGenerator = new PhaseMagang4FakeDocumentGenerationService();
        $transitionConverter = new PhaseMagang4FakeDocumentConverter();
        $this->bindPreviewStack($transitionGenerator, $transitionConverter);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/approve", [
                'nomor_surat_pengantar' => 'MAG/PENGANTAR/CACHE',
                'nomor_surat_tugas' => 'MAG/TUGAS/CACHE',
            ])
            ->assertOk();

        $this->assertSame(0, $transitionGenerator->calls);
        $this->assertSame(0, $transitionConverter->calls);
        $this->assertSame(1, LetterDocumentArtifact::query()
            ->where('source_hash', $ready->source_hash)
            ->where('status', LetterDocumentArtifact::STATUS_READY)
            ->count());
    }

    public function test_locked_recheck_conflict_does_not_overwrite_state_after_artifact_generation(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'nomor_surat' => null,
            'nomor_surat_pengantar' => null,
            'nomor_surat_tugas' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $mock = Mockery::mock(SuratPengantarMagangPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function () use ($application): LetterDocumentArtifact {
                SuratPengantarMagangApplication::whereKey($application->getKey())
                    ->update(['status' => SuratPengantarMagangApplication::STATUS_REJECTED]);

                return LetterDocumentArtifact::make([
                    'phase' => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(SuratPengantarMagangPreviewGenerationService::class, $mock);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/approve", [
                'nomor_surat_pengantar' => 'MAG/PENGANTAR/RACE',
                'nomor_surat_tugas' => 'MAG/TUGAS/RACE',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Pengajuan sudah berubah dan tidak dapat diverifikasi ulang.');

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_REJECTED, $fresh->status);
        $this->assertNull($fresh->nomor_surat);
        $this->assertNull($fresh->nomor_surat_pengantar);
        $this->assertNull($fresh->nomor_surat_tugas);
        $this->assertNull($fresh->tendik_approved_by);
    }

    private function expectPreviewGenerationCall(string $phase, ?callable $assertion = null): Mockery\MockInterface
    {
        $mock = Mockery::mock(SuratPengantarMagangPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function ($application, string $actualPhase, array $overrides = [], ?int $generatedBy = null) use ($phase, $assertion) {
                $this->assertSame($phase, $actualPhase);
                if ($assertion) {
                    $assertion($application, $overrides, $generatedBy);
                }

                return LetterDocumentArtifact::make([
                    'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
                    'application_id' => $application->getKey(),
                    'phase' => $actualPhase,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(SuratPengantarMagangPreviewGenerationService::class, $mock);

        return $mock;
    }

    private function mockPreviewGenerationThrows(): void
    {
        $mock = Mockery::mock(SuratPengantarMagangPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andThrow(new SuratPengantarMagangPreviewGenerationException('forced Magang preview failure'));
        $this->app->instance(SuratPengantarMagangPreviewGenerationService::class, $mock);
    }

    private function mockPreviewGenerationNever(): void
    {
        $mock = Mockery::mock(SuratPengantarMagangPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')->never();
        $this->app->instance(SuratPengantarMagangPreviewGenerationService::class, $mock);
    }

    private function bindPreviewStack(
        PhaseMagang4FakeDocumentGenerationService $generator,
        PhaseMagang4FakeDocumentConverter $converter,
    ): void {
        $this->app->instance(SuratPengantarMagangDocumentGenerationService::class, $generator);
        $this->app->instance(DocumentConverter::class, $converter);
        $this->app->forgetInstance(SuratPengantarMagangPreviewGenerationService::class);
    }
}

class PhaseMagang4FakeDocumentGenerationService extends SuratPengantarMagangDocumentGenerationService
{
    public int $calls = 0;

    public function __construct()
    {
    }

    public function generateDocumentForPhase(
        SuratPengantarMagangApplication $application,
        string $phase,
        array $overrides = [],
    ): string {
        $this->calls++;

        $path = 'letter-document-artifacts/'
            . SuratPengantarMagangApplication::LETTER_TYPE
            . '/'
            . $application->id
            . '/'
            . $phase
            . '/source_magang4_fake_'
            . $this->calls
            . '.docx';
        Storage::disk('local')->put($path, 'fake Magang docx');

        return $path;
    }
}

class PhaseMagang4FakeDocumentConverter implements DocumentConverter
{
    public int $calls = 0;

    public function convertDocxToPdf(string $sourceDocxAbsolutePath, string $destPdfAbsolutePath): void
    {
        $this->calls++;
        file_put_contents($destPdfAbsolutePath, '%PDF-1.4 fake Magang PDF');
    }
}
