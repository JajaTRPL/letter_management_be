<?php

namespace Tests\Feature\Workflow;

use App\Models\ProsesLuarNegeriApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for PLN's five actor-FK columns:
 *   tendik_approved_by, kaprodi_approved_by, kadep_approved_by,
 *   revised_by, rejected_by.
 *
 * The controller writes these at the appropriate workflow events; these
 * tests assert persistence without changing any production behavior,
 * routes, status semantics, document generation, schema, or migrations.
 */
class ProsesLuarNegeriActorPersistenceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_tendik_approve_persists_tendik_actor_and_timestamp(): void
    {
        $this->mockPlnPreviewGenerationAlwaysReady();
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/approve", [
                'nomor_surat' => 'PLN-ACTOR-001',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by);
        $this->assertNotNull($fresh->tendik_approved_at);
        $this->assertSame('PLN-ACTOR-001', $fresh->nomor_surat);
    }

    public function test_kaprodi_approve_persists_kaprodi_actor_and_timestamp(): void
    {
        $this->mockPlnPreviewGenerationAlwaysReady();
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'PLN-ACTOR-002',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertSame($kaprodi->id, $fresh->kaprodi_approved_by);
        $this->assertNotNull($fresh->kaprodi_approved_at);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by, 'Previous Tendik actor must not be overwritten.');
    }

    public function test_sekprodi_approve_persists_kaprodi_actor(): void
    {
        $this->mockPlnPreviewGenerationAlwaysReady();
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $sekprodi = $this->akademik('sekprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'PLN-ACTOR-003',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->actingAs($sekprodi, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertOk();

        $this->assertSame($sekprodi->id, $application->fresh()->kaprodi_approved_by);
    }

    public function test_kadep_approve_persists_kadep_actor_and_timestamp(): void
    {
        $this->mockPlnPreviewGenerationAlwaysReady();

        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'PLN-ACTOR-004',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => now(),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW, $fresh->status);
        $this->assertSame($kadep->id, $fresh->kadep_approved_by);
        $this->assertNotNull($fresh->kadep_approved_at);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by, 'Previous Tendik actor must not be overwritten.');
        $this->assertSame($kaprodi->id, $fresh->kaprodi_approved_by, 'Previous Kaprodi actor must not be overwritten.');
    }

    public function test_tendik_revise_persists_revised_actor_and_timestamp(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/revise", [
                'note' => 'Mohon lengkapi dokumen perjalanan.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_REVISION);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_REVISION, $fresh->status);
        $this->assertSame($tendik->id, $fresh->revised_by);
        $this->assertNotNull($fresh->revised_at);
    }

    public function test_tendik_reject_persists_rejected_actor_and_timestamp(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/reject", [
                'reason' => 'Data perjalanan tidak valid.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_REJECTED);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_REJECTED, $fresh->status);
        $this->assertSame($tendik->id, $fresh->rejected_by);
        $this->assertNotNull($fresh->rejected_at);
    }

    public function test_kaprodi_revise_persists_revised_actor(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'PLN-ACTOR-005',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/revise", [
                'note' => 'Mohon revisi keperluan agar lebih spesifik.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_REVISION);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_REVISION, $fresh->status);
        $this->assertSame($kaprodi->id, $fresh->revised_by);
        $this->assertNotNull($fresh->revised_at);
    }

    public function test_kadep_reject_persists_rejected_actor(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'PLN-ACTOR-006',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => now(),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/reject", [
                'reason' => 'Tidak sejalan dengan kebijakan departemen.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_REJECTED);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_REJECTED, $fresh->status);
        $this->assertSame($kadep->id, $fresh->rejected_by);
        $this->assertNotNull($fresh->rejected_at);
    }

}
