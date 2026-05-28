<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratKeteranganAktifApplication;
use App\Services\SuratKeteranganAktifPreviewGenerationException;
use App\Services\SuratKeteranganAktifPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Phase S.4 wiring contract: each SKA workflow transition must generate the
 * matching phase artifact via SuratKeteranganAktifPreviewGenerationService
 * BEFORE persisting the status mutation. If artifact generation throws, the
 * status, actor, and nomor_surat fields must remain untouched. Kadep approval
 * no longer runs the legacy DomPDF bridge; the private mahasiswa_review
 * artifact is the runtime source of truth.
 */
class SuratKeteranganAktifTransitionArtifactTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    // ------------------------------------------------------------------
    // Mahasiswa submit -> tendik_review
    // ------------------------------------------------------------------

    public function test_submit_generates_tendik_review_artifact_before_status_mutation(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_DRAFT,
            'assigned_to' => null,
            'submitted_at' => null,
        ]);

        $mock = $this->expectPreviewGenerationCall(
            phase: LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            overridesAssertion: function (array $overrides) {
                $this->assertSame(SuratKeteranganAktifApplication::STATUS_SUBMITTED, $overrides['status']);
                $this->assertArrayHasKey('submitted_at', $overrides);
                $this->assertArrayHasKey('tanggal_surat', $overrides);
            },
        );

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-keterangan-aktif/submit')
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNotNull($fresh->submitted_at);
        $mock->shouldHaveReceived('generateForPhase')->once();
    }

    public function test_submit_artifact_failure_leaves_status_unchanged_and_returns_503(): void
    {
        $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_DRAFT,
            'assigned_to' => null,
            'submitted_at' => null,
        ]);

        $this->mockPreviewGenerationThrows();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-keterangan-aktif/submit')
            ->assertStatus(503);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->submitted_at);
    }

    // ------------------------------------------------------------------
    // Tendik approve -> prodi_review
    // ------------------------------------------------------------------

    public function test_tendik_approve_generates_prodi_review_artifact_with_pending_nomor_surat(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);

        $captured = [];
        $mock = $this->expectPreviewGenerationCall(
            phase: LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            overridesAssertion: function (array $overrides) use (&$captured) {
                $captured = $overrides;
            },
        );

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/approve", [
                'nomor_surat' => 'AKT-WIRE-001',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK);

        $this->assertSame(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK, $captured['status']);
        $this->assertSame('AKT-WIRE-001', $captured['nomor_surat']);
        $this->assertSame($tendik->id, $captured['tendik_approved_by']);
        $this->assertArrayHasKey('tendik_approved_at', $captured);
        $this->assertArrayHasKey('tanggal_surat', $captured);

        $fresh = $application->fresh();
        $this->assertSame('AKT-WIRE-001', $fresh->nomor_surat);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by);
        $mock->shouldHaveReceived('generateForPhase')->once();
    }

    public function test_tendik_approve_artifact_failure_leaves_status_nomor_and_actor_unchanged(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);

        $this->mockPreviewGenerationThrows();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/approve", [
                'nomor_surat' => 'SHOULD-NOT-PERSIST',
            ])
            ->assertStatus(503);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->nomor_surat);
        $this->assertNull($fresh->tendik_approved_by);
        $this->assertNull($fresh->tendik_approved_at);
    }

    // ------------------------------------------------------------------
    // Kaprodi/Sekprodi approve -> departemen_review
    // ------------------------------------------------------------------

    public function test_kaprodi_approve_generates_departemen_review_artifact(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-WIRE-002',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $captured = [];
        $mock = $this->expectPreviewGenerationCall(
            phase: LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            overridesAssertion: function (array $overrides) use (&$captured) {
                $captured = $overrides;
            },
        );

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI);

        $this->assertSame(SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI, $captured['status']);
        $this->assertSame($kaprodi->id, $captured['kaprodi_approved_by']);
        $this->assertArrayHasKey('kaprodi_approved_at', $captured);

        $fresh = $application->fresh();
        $this->assertSame($kaprodi->id, $fresh->kaprodi_approved_by);
        $mock->shouldHaveReceived('generateForPhase')->once();
    }

    public function test_kaprodi_approve_artifact_failure_leaves_status_and_kaprodi_fields_unchanged(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-WIRE-003',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->mockPreviewGenerationThrows();

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertStatus(503);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertNull($fresh->kaprodi_approved_by);
        $this->assertNull($fresh->kaprodi_approved_at);
    }

    public function test_kaprodi_approve_wrong_prodi_still_forbidden(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);

        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$student] = $this->completeMahasiswa([], [], $studentProgram);

        $otherDept = $this->department();
        $otherProgram = $this->studyProgram($otherDept);
        $wrongKaprodi = $this->akademik('kaprodi', [
            'study_program_id' => $otherProgram->id,
            'department_id' => $otherDept->id,
        ]);

        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-WIRE-004',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $mock = Mockery::mock(SuratKeteranganAktifPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')->never();
        $this->app->instance(SuratKeteranganAktifPreviewGenerationService::class, $mock);

        $this->actingAs($wrongKaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertForbidden();

        $this->assertSame(
            SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            $application->fresh()->status,
        );
    }

    // ------------------------------------------------------------------
    // Kadep/Sekdep approve -> mahasiswa_review
    // ------------------------------------------------------------------

    public function test_kadep_approve_generates_mahasiswa_review_artifact_without_legacy_pdf_bridge(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-WIRE-005',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => now(),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $captured = [];
        $previewMock = $this->expectPreviewGenerationCall(
            phase: LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            overridesAssertion: function (array $overrides) use (&$captured) {
                $captured = $overrides;
            },
        );

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW);

        $this->assertSame(SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW, $captured['status']);
        $this->assertSame($kadep->id, $captured['kadep_approved_by']);

        $fresh = $application->fresh();
        $this->assertSame($kadep->id, $fresh->kadep_approved_by);
        $previewMock->shouldHaveReceived('generateForPhase')->once();
    }

    public function test_kadep_approve_artifact_failure_leaves_status_and_kadep_fields_unchanged(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-WIRE-006',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => now(),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $this->mockPreviewGenerationThrows();

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertStatus(503);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertNull($fresh->kadep_approved_by);
        $this->assertNull($fresh->kadep_approved_at);
    }

    public function test_kadep_approve_wrong_department_still_forbidden(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');

        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$student] = $this->completeMahasiswa([], [], $studentProgram);

        $otherDept = $this->department();
        $wrongKadep = $this->akademik('kadep', ['department_id' => $otherDept->id]);

        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-WIRE-008',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => now(),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $mock = Mockery::mock(SuratKeteranganAktifPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')->never();
        $this->app->instance(SuratKeteranganAktifPreviewGenerationService::class, $mock);

        $this->actingAs($wrongKadep, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertForbidden();

        $this->assertSame(
            SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            $application->fresh()->status,
        );
    }

    // ------------------------------------------------------------------
    // Cache hit + race
    // ------------------------------------------------------------------

    public function test_cache_hit_does_not_duplicate_ready_artifact_at_transition(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);

        $callCount = 0;
        $mock = Mockery::mock(SuratKeteranganAktifPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->andReturnUsing(function ($appArg, string $phase) use (&$callCount) {
                $callCount++;
                return LetterDocumentArtifact::make([
                    'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
                    'application_id' => $appArg->getKey(),
                    'phase' => $phase,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(SuratKeteranganAktifPreviewGenerationService::class, $mock);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/approve", [
                'nomor_surat' => 'AKT-WIRE-009',
            ])
            ->assertOk();

        $this->assertSame(
            1,
            $callCount,
            'Controller must call the preview service exactly once per transition; cache-hit short-circuit is the service\'s responsibility, not the controller\'s.',
        );
    }

    public function test_race_status_change_after_artifact_returns_409(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);

        $mock = Mockery::mock(SuratKeteranganAktifPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function ($appArg, string $phase) use ($application) {
                // Simulate another actor (or admin) flipping the status between
                // artifact success and the row-locked recheck.
                SuratKeteranganAktifApplication::whereKey($application->getKey())
                    ->update(['status' => SuratKeteranganAktifApplication::STATUS_REJECTED]);

                return LetterDocumentArtifact::make([
                    'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
                    'application_id' => $appArg->getKey(),
                    'phase' => $phase,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(SuratKeteranganAktifPreviewGenerationService::class, $mock);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/approve", [
                'nomor_surat' => 'AKT-WIRE-010',
            ])
            ->assertStatus(409);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_REJECTED, $fresh->status);
        $this->assertNull($fresh->tendik_approved_by);
        $this->assertNull($fresh->nomor_surat);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Bind a mock that captures and asserts on the override payload for the
     * given phase. Returns the mock so callers can verify call count.
     */
    private function expectPreviewGenerationCall(
        string $phase,
        ?callable $overridesAssertion = null,
    ): Mockery\MockInterface {
        $mock = Mockery::mock(SuratKeteranganAktifPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->andReturnUsing(function ($application, string $actualPhase, array $overrides) use ($phase, $overridesAssertion) {
                $this->assertSame($phase, $actualPhase);
                if ($overridesAssertion) {
                    $overridesAssertion($overrides);
                }

                return LetterDocumentArtifact::make([
                    'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
                    'application_id' => $application->getKey(),
                    'phase' => $actualPhase,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(SuratKeteranganAktifPreviewGenerationService::class, $mock);

        return $mock;
    }

    private function mockPreviewGenerationThrows(): void
    {
        $mock = Mockery::mock(SuratKeteranganAktifPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->andThrow(new SuratKeteranganAktifPreviewGenerationException('forced for test'));
        $this->app->instance(SuratKeteranganAktifPreviewGenerationService::class, $mock);
    }

}
