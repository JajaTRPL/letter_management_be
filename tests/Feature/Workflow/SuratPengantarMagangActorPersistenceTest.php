<?php

namespace Tests\Feature\Workflow;

use App\Models\SuratPengantarMagangApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for Magang's five actor-FK columns:
 *   tendik_approved_by, kaprodi_approved_by, kadep_approved_by,
 *   revised_by, rejected_by.
 *
 * Pins the controller's current actor-write contract so later
 * standardization phases cannot silently lose audit data.
 */
class SuratPengantarMagangActorPersistenceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_tendik_approve_persists_tendik_actor_and_timestamp(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);

        $this->mockMagangPreviewGenerationAlwaysReady();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/approve", [
                'nomor_surat_pengantar' => 'MAG-ACTOR-PENGANTAR-001',
                'nomor_surat_tugas' => 'MAG-ACTOR-TUGAS-001',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by);
        $this->assertNotNull($fresh->tendik_approved_at);
        $this->assertSame('MAG-ACTOR-PENGANTAR-001', $fresh->nomor_surat);
    }

    public function test_kaprodi_approve_persists_kaprodi_actor_and_timestamp(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'MAG-ACTOR-002',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->mockMagangPreviewGenerationAlwaysReady();

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertSame($kaprodi->id, $fresh->kaprodi_approved_by);
        $this->assertNotNull($fresh->kaprodi_approved_at);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by, 'Previous Tendik actor must not be overwritten.');
    }

    public function test_sekprodi_approve_persists_kaprodi_actor(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $sekprodi = $this->akademik('sekprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'MAG-ACTOR-003',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->mockMagangPreviewGenerationAlwaysReady();

        $this->actingAs($sekprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertOk();

        $this->assertSame($sekprodi->id, $application->fresh()->kaprodi_approved_by);
    }

    public function test_kadep_approve_persists_kadep_actor_and_timestamp(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'MAG-ACTOR-004',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => now(),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $this->mockMagangPreviewGenerationAlwaysReady();

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW, $fresh->status);
        $this->assertSame($kadep->id, $fresh->kadep_approved_by);
        $this->assertNotNull($fresh->kadep_approved_at);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by, 'Previous Tendik actor must not be overwritten.');
        $this->assertSame($kaprodi->id, $fresh->kaprodi_approved_by, 'Previous Kaprodi actor must not be overwritten.');
    }

    public function test_tendik_revise_persists_revised_actor_and_timestamp(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/revise", [
                'note' => 'Mohon lengkapi alamat perusahaan.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_REVISION);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_REVISION, $fresh->status);
        $this->assertSame($tendik->id, $fresh->revised_by);
        $this->assertNotNull($fresh->revised_at);
    }

    public function test_tendik_reject_persists_rejected_actor_and_timestamp(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/reject", [
                'reason' => 'Data perusahaan tidak valid.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_REJECTED);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_REJECTED, $fresh->status);
        $this->assertSame($tendik->id, $fresh->rejected_by);
        $this->assertNotNull($fresh->rejected_at);
    }

    public function test_kaprodi_revise_persists_revised_actor(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'MAG-ACTOR-005',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/revise", [
                'note' => 'Mohon perjelas peran magang.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_REVISION);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_REVISION, $fresh->status);
        $this->assertSame($kaprodi->id, $fresh->revised_by);
        $this->assertNotNull($fresh->revised_at);
    }

    public function test_kadep_reject_persists_rejected_actor(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'MAG-ACTOR-006',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => now(),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/reject", [
                'reason' => 'Tidak sesuai dengan kebijakan departemen.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_REJECTED);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_REJECTED, $fresh->status);
        $this->assertSame($kadep->id, $fresh->rejected_by);
        $this->assertNotNull($fresh->rejected_at);
    }

}
