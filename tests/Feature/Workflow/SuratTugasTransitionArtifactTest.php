<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratTugasApplication;
use App\Models\User;
use App\Services\SuratTugasPreviewGenerationException;
use App\Services\SuratTugasPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Tests\Feature\Workflow\Support\SuratTugasFakeDocumentConverter;
use Tests\Feature\Workflow\Support\SuratTugasFakeDocumentGenerationService;

/**
 * Each actionable Surat Tugas transition generates its private phase artifact
 * BEFORE the locked state update. Artifact failure leaves state/actor/number
 * untouched; a race that flips status mid-flight yields 409 without overwrite.
 */
class SuratTugasTransitionArtifactTest extends TestCase
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
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
        ]);
        $this->attachRegistryDocument($application, SuratTugasApplication::LETTER_TYPE, 'proposal', 'Proposal ST.pdf');
        $this->attachRegistryDocument($application, SuratTugasApplication::LETTER_TYPE, 'surat_pengantar_magang', 'Pengantar ST.pdf');

        $this->expectPreviewGenerationCall(
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            function ($renderApplication, array $overrides, ?int $generatedBy) use ($application, $student): void {
                $this->assertSame(SuratTugasApplication::STATUS_DRAFT, $application->fresh()->status);
                $this->assertSame(SuratTugasApplication::STATUS_SUBMITTED, $overrides['status']);
                $this->assertSame('2026-05-25 09:10:20', $overrides['submitted_at']->toDateTimeString());
                $this->assertSame($student->id, $generatedBy);
            },
        );

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-tugas/submit')
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);

        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertSame($tendik->id, $fresh->assigned_to);
    }

    public function test_revision_submit_generates_artifact_and_clears_revision_fields(): void
    {
        $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_REVISION,
            'revision_note' => 'Perbaiki data.',
            'rejection_reason' => 'Alasan lama.',
        ]);
        $this->attachRegistryDocument($application, SuratTugasApplication::LETTER_TYPE, 'proposal', 'Proposal ST.pdf');
        $this->attachRegistryDocument($application, SuratTugasApplication::LETTER_TYPE, 'surat_pengantar_magang', 'Pengantar ST.pdf');

        $this->expectPreviewGenerationCall(LetterDocumentArtifact::PHASE_TENDIK_REVIEW);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-tugas/submit')
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->revision_note);
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_submit_artifact_failure_leaves_workflow_state_unchanged_and_no_orphan(): void
    {
        $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
        ]);
        $proposal = $this->attachRegistryDocument($application, SuratTugasApplication::LETTER_TYPE, 'proposal', 'Proposal ST.pdf');
        $pengantar = $this->attachRegistryDocument($application, SuratTugasApplication::LETTER_TYPE, 'surat_pengantar_magang', 'Pengantar ST.pdf');

        $this->mockPreviewGenerationThrows();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-tugas/submit')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Dokumen pratinjau pengajuan belum dapat dibuat. Silakan coba lagi.');

        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->assigned_to);
        // Supporting uploads (from the draft) survive the failed submit; no READY
        // artifact orphan is produced.
        $this->assertTrue(Storage::disk('local')->exists($proposal->storage_path));
        $this->assertTrue(Storage::disk('local')->exists($pengantar->storage_path));
        $this->assertSame(0, LetterDocumentArtifact::query()
            ->where('status', LetterDocumentArtifact::STATUS_READY)
            ->count());
    }

    public function test_tendik_approve_generates_prodi_review_artifact_before_state(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication(null, [
            'status' => SuratTugasApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'nomor_surat_tugas' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $this->expectPreviewGenerationCall(
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            function ($renderApplication, array $overrides, ?int $generatedBy) use ($application, $tendik): void {
                $this->assertSame(SuratTugasApplication::STATUS_SUBMITTED, $application->fresh()->status);
                $this->assertSame(SuratTugasApplication::STATUS_APPROVED_TENDIK, $overrides['status']);
                $this->assertSame('ST/WIRE/001', $overrides['nomor_surat_tugas']);
                $this->assertSame($tendik->id, $overrides['tendik_approved_by']);
                $this->assertSame($tendik->id, $generatedBy);
            },
        );

        $response = $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-tugas/{$application->id}/approve", [
                'nomor_surat_tugas' => 'ST/WIRE/001',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_APPROVED_TENDIK)
            ->assertJsonPath('application.nomor_surat_tugas', 'ST/WIRE/001');
        $this->assertArrayNotHasKey('proposal_kegiatan_magang_path', $response->json('application'));
        $this->assertArrayNotHasKey('surat_pengantar_magang_path', $response->json('application'));

        $fresh = $application->fresh();
        $this->assertSame('ST/WIRE/001', $fresh->nomor_surat_tugas);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by);
    }

    public function test_tendik_approve_requires_nomor_surat_tugas_and_failure_does_not_mutate(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);

        // Missing number → 422, no artifact generation, no mutation.
        $missingNumber = $this->suratTugasApplication(null, [
            'status' => SuratTugasApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
        ]);
        $this->mockPreviewGenerationNever();
        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-tugas/{$missingNumber->id}/approve", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nomor_surat_tugas']);
        $this->assertSame(SuratTugasApplication::STATUS_SUBMITTED, $missingNumber->fresh()->status);
        $this->assertNull($missingNumber->fresh()->nomor_surat_tugas);

        // Artifact failure → 503, number/actor/status unchanged.
        $failing = $this->suratTugasApplication(null, [
            'status' => SuratTugasApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'nomor_surat_tugas' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);
        $this->mockPreviewGenerationThrows();
        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-tugas/{$failing->id}/approve", [
                'nomor_surat_tugas' => 'ST/FAIL',
            ])
            ->assertStatus(503);
        $fresh = $failing->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->nomor_surat_tugas);
        $this->assertNull($fresh->tendik_approved_at);
        $this->assertNull($fresh->tendik_approved_by);
    }

    public function test_kaprodi_approve_generates_departemen_review_and_failure_or_wrong_scope_does_not_persist(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat_tugas' => 'ST/002',
            'tendik_approved_at' => Carbon::parse('2026-05-24 08:00:00'),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->expectPreviewGenerationCall(
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            function ($renderApplication, array $overrides) use ($kaprodi): void {
                $this->assertSame(SuratTugasApplication::STATUS_APPROVED_KAPRODI, $overrides['status']);
                $this->assertSame($kaprodi->id, $overrides['kaprodi_approved_by']);
                $this->assertSame('2026-05-24 08:00:00', $overrides['tanggal_surat']->toDateTimeString());
            },
        );

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_APPROVED_KAPRODI);
        $this->assertSame($kaprodi->id, $application->fresh()->kaprodi_approved_by);

        $failure = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat_tugas' => 'ST/FAIL-PRODI',
            'tendik_approved_at' => now(),
        ]);
        $this->mockPreviewGenerationThrows();
        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$failure->id}/approve")
            ->assertStatus(503);
        $this->assertSame(SuratTugasApplication::STATUS_APPROVED_TENDIK, $failure->fresh()->status);
        $this->assertNull($failure->fresh()->kaprodi_approved_by);

        $wrongProgram = $this->studyProgram($this->department());
        $wrongKaprodi = $this->akademik('kaprodi', ['study_program_id' => $wrongProgram->id]);
        $wrongScope = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
        ]);
        $this->mockPreviewGenerationNever();
        $this->actingAs($wrongKaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$wrongScope->id}/approve")
            ->assertForbidden();
        $this->assertNull($wrongScope->fresh()->kaprodi_approved_by);
    }

    public function test_kadep_approve_generates_mahasiswa_artifact_and_failure_or_wrong_dept_does_not_persist(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat_tugas' => 'ST/003',
            'tendik_approved_at' => Carbon::parse('2026-05-24 08:00:00'),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => Carbon::parse('2026-05-24 09:00:00'),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $this->expectPreviewGenerationCall(
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            function ($renderApplication, array $overrides, ?int $generatedBy) use ($application, $kadep): void {
                $this->assertSame(SuratTugasApplication::STATUS_APPROVED_KAPRODI, $application->fresh()->status);
                $this->assertSame(SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW, $overrides['status']);
                $this->assertSame($kadep->id, $overrides['kadep_approved_by']);
                $this->assertInstanceOf(User::class, $overrides['official_kadep']);
                $this->assertSame($kadep->id, $generatedBy);
            },
        );
        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW);

        $this->assertSame($kadep->id, $application->fresh()->kadep_approved_by);

        $failure = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat_tugas' => 'ST/FAIL-KADEP',
            'tendik_approved_at' => now(),
        ]);
        $this->mockPreviewGenerationThrows();
        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$failure->id}/approve")
            ->assertStatus(503);
        $fresh = $failure->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertNull($fresh->kadep_approved_by);

        $otherDepartment = $this->department();
        $wrongKadep = $this->akademik('kadep', ['department_id' => $otherDepartment->id]);
        $wrongScope = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_APPROVED_KAPRODI,
        ]);
        $this->mockPreviewGenerationNever();
        $this->actingAs($wrongKadep, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$wrongScope->id}/approve")
            ->assertForbidden();
        $this->assertNull($wrongScope->fresh()->kadep_approved_by);
    }

    public function test_ready_artifact_cache_hit_does_not_duplicate_artifact_on_transition(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication(null, [
            'status' => SuratTugasApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $preGenerator = new SuratTugasFakeDocumentGenerationService();
        $preConverter = new SuratTugasFakeDocumentConverter();
        $this->bindPreviewStack($preGenerator, $preConverter);

        $ready = app(SuratTugasPreviewGenerationService::class)->generateForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            [
                'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
                'nomor_surat_tugas' => 'ST/CACHE',
                'tendik_approved_at' => Carbon::now(),
                'tendik_approved_by' => $tendik->id,
                'tanggal_surat' => Carbon::now(),
            ],
            $tendik->id,
        );

        $transitionGenerator = new SuratTugasFakeDocumentGenerationService();
        $transitionConverter = new SuratTugasFakeDocumentConverter();
        $this->bindPreviewStack($transitionGenerator, $transitionConverter);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-tugas/{$application->id}/approve", [
                'nomor_surat_tugas' => 'ST/CACHE',
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
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication(null, [
            'status' => SuratTugasApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'nomor_surat_tugas' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $mock = Mockery::mock(SuratTugasPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function () use ($application): LetterDocumentArtifact {
                SuratTugasApplication::whereKey($application->getKey())
                    ->update(['status' => SuratTugasApplication::STATUS_REJECTED]);

                return LetterDocumentArtifact::make([
                    'phase' => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(SuratTugasPreviewGenerationService::class, $mock);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-tugas/{$application->id}/approve", [
                'nomor_surat_tugas' => 'ST/RACE',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Pengajuan sudah berubah dan tidak dapat diverifikasi ulang.');

        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_REJECTED, $fresh->status);
        $this->assertNull($fresh->nomor_surat_tugas);
        $this->assertNull($fresh->tendik_approved_by);
    }

    private function expectPreviewGenerationCall(string $phase, ?callable $assertion = null): Mockery\MockInterface
    {
        $mock = Mockery::mock(SuratTugasPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function ($application, string $actualPhase, array $overrides = [], ?int $generatedBy = null) use ($phase, $assertion) {
                $this->assertSame($phase, $actualPhase);
                if ($assertion) {
                    $assertion($application, $overrides, $generatedBy);
                }

                return LetterDocumentArtifact::make([
                    'letter_type' => SuratTugasApplication::LETTER_TYPE,
                    'application_id' => $application->getKey(),
                    'phase' => $actualPhase,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(SuratTugasPreviewGenerationService::class, $mock);

        return $mock;
    }

    private function mockPreviewGenerationThrows(): void
    {
        $mock = Mockery::mock(SuratTugasPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andThrow(new SuratTugasPreviewGenerationException('forced Surat Tugas preview failure'));
        $this->app->instance(SuratTugasPreviewGenerationService::class, $mock);
    }

    private function mockPreviewGenerationNever(): void
    {
        $mock = Mockery::mock(SuratTugasPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')->never();
        $this->app->instance(SuratTugasPreviewGenerationService::class, $mock);
    }

    private function bindPreviewStack(
        SuratTugasFakeDocumentGenerationService $generator,
        SuratTugasFakeDocumentConverter $converter,
    ): void {
        $this->app->instance(\App\Services\SuratTugasDocumentGenerationService::class, $generator);
        $this->app->instance(\App\Services\DocumentConverter::class, $converter);
        $this->app->forgetInstance(SuratTugasPreviewGenerationService::class);
    }
}
