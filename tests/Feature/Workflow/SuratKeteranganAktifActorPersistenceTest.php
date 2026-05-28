<?php

namespace Tests\Feature\Workflow;

use App\Models\SuratKeteranganAktifApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the five SKA actor-FK columns:
 *   tendik_approved_by, kaprodi_approved_by, kadep_approved_by,
 *   revised_by, rejected_by.
 *
 * The controller already writes these columns at the appropriate workflow
 * events; the existing smoke test exercises the lifecycle but never asserts
 * that the actor IDs persist. These tests close that observability gap
 * without changing any production behavior, status semantics, document
 * generation, storage gates, routes, schema, or migrations.
 */
class SuratKeteranganAktifActorPersistenceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        // Workflow transitions now wire to the SKA preview generation service;
        // mock it permissively so per-transition tests stay focused on actor
        // persistence and do not depend on the artifact pipeline.
        $this->mockSkaPreviewGenerationAlwaysReady();
    }

    public function test_tendik_approve_persists_tendik_actor_and_timestamp(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/approve", [
                'nomor_surat' => 'AKT-ACTOR-001',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by);
        $this->assertNotNull($fresh->tendik_approved_at);
        $this->assertSame('AKT-ACTOR-001', $fresh->nomor_surat);
    }

    public function test_kaprodi_approve_persists_kaprodi_actor_and_timestamp(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-ACTOR-002',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertSame($kaprodi->id, $fresh->kaprodi_approved_by);
        $this->assertNotNull($fresh->kaprodi_approved_at);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by, 'Previous Tendik actor must not be overwritten.');
    }

    public function test_sekprodi_approve_persists_kaprodi_actor(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $sekprodi = $this->akademik('sekprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-ACTOR-003',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->actingAs($sekprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertOk();

        $this->assertSame($sekprodi->id, $application->fresh()->kaprodi_approved_by);
    }

    public function test_kadep_approve_persists_kadep_actor_and_timestamp(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-ACTOR-004',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => now(),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW, $fresh->status);
        $this->assertSame($kadep->id, $fresh->kadep_approved_by);
        $this->assertNotNull($fresh->kadep_approved_at);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by, 'Previous Tendik actor must not be overwritten.');
        $this->assertSame($kaprodi->id, $fresh->kaprodi_approved_by, 'Previous Kaprodi actor must not be overwritten.');
    }

    public function test_tendik_revise_persists_revised_actor_and_timestamp(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/revise", [
                'note' => 'Mohon perjelas keperluan pengajuan.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_REVISION);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_REVISION, $fresh->status);
        $this->assertSame($tendik->id, $fresh->revised_by);
        $this->assertNotNull($fresh->revised_at);
    }

    public function test_tendik_reject_persists_rejected_actor_and_timestamp(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/reject", [
                'reason' => 'Data pengajuan tidak valid.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_REJECTED);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_REJECTED, $fresh->status);
        $this->assertSame($tendik->id, $fresh->rejected_by);
        $this->assertNotNull($fresh->rejected_at);
    }

    public function test_kaprodi_revise_persists_revised_actor(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-ACTOR-005',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/revise", [
                'note' => 'Mohon lengkapi data orang tua.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_REVISION);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_REVISION, $fresh->status);
        $this->assertSame($kaprodi->id, $fresh->revised_by);
        $this->assertNotNull($fresh->revised_at);
    }

    public function test_kadep_reject_persists_rejected_actor(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-ACTOR-006',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => now(),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/reject", [
                'reason' => 'Bertentangan dengan kebijakan departemen.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_REJECTED);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_REJECTED, $fresh->status);
        $this->assertSame($kadep->id, $fresh->rejected_by);
        $this->assertNotNull($fresh->rejected_at);
    }

}
