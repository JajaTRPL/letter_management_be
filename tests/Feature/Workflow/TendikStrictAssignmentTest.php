<?php

namespace Tests\Feature\Workflow;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Strict-assignment authorization contract for Tendik. Read and action access
 * follow assigned_tasks exclusively. There is no "team scope" shortcut that
 * grants cross-assignment access.
 */
class TendikStrictAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    // -------------------------------------------------------------------
    // Read access: assigned letter type can view; non-assigned gets 403.
    // -------------------------------------------------------------------

    public function test_tendik_assigned_to_beasiswa_can_view_beasiswa_detail(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication();

        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/scholarship/{$application->id}")
            ->assertOk();

        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}")
            ->assertOk();
    }

    public function test_tendik_not_assigned_to_beasiswa_cannot_view_beasiswa_detail(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication();

        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/scholarship/{$application->id}")
            ->assertForbidden();

        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}")
            ->assertForbidden();
    }

    public function test_multiple_tendik_assigned_to_beasiswa_can_all_view_beasiswa_detail(): void
    {
        $tendikA = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $tendikB = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $tendikC = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $tendikD = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $application = $this->scholarshipApplication(null, [
            'assigned_to' => $tendikA->id,
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);

        foreach ([$tendikA, $tendikB, $tendikC, $tendikD] as $tendik) {
            $this->actingAs($tendik, 'sanctum')
                ->getJson("/api/tendik/scholarship/{$application->id}")
                ->assertOk();
        }
    }

    public function test_tendik_assigned_to_magang_can_view_magang_detail(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication();

        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-pengantar-magang/{$application->id}")
            ->assertOk();
    }

    public function test_tendik_not_assigned_to_magang_cannot_view_magang_detail(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->magangApplication();

        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-pengantar-magang/{$application->id}")
            ->assertForbidden();
    }

    public function test_tendik_assigned_to_all_letter_types_can_view_all_letter_details(): void
    {
        $tendik = $this->tendikPersuratan([
            ScholarshipApplication::LETTER_TYPE,
            SuratPengantarMagangApplication::LETTER_TYPE,
            SuratKeteranganAktifApplication::LETTER_TYPE,
            ProsesLuarNegeriApplication::LETTER_TYPE,
        ]);

        $scholarship = $this->scholarshipApplication();
        $magang = $this->magangApplication();
        $aktif = $this->aktifApplication();
        $pln = $this->prosesLuarNegeriApplication();

        $urls = [
            "/api/tendik/scholarship/{$scholarship->id}",
            "/api/tendik/surat-permohonan-beasiswa/{$scholarship->id}",
            "/api/tendik/surat-pengantar-magang/{$magang->id}",
            "/api/tendik/surat-keterangan-aktif/{$aktif->id}",
            "/api/tendik/proses-luar-negeri/{$pln->id}",
        ];

        foreach ($urls as $url) {
            $this->actingAs($tendik, 'sanctum')
                ->getJson($url)
                ->assertOk();
        }
    }

    // -------------------------------------------------------------------
    // Action access: assigned + actionable status. Non-actionable status
    // or unassigned letter type both refuse.
    // -------------------------------------------------------------------

    public function test_assigned_tendik_can_act_when_status_is_actionable(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);

        $this->mockBeasiswaPreviewGenerationForApprove();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/scholarship/{$application->id}/approve", [
                'nomor_surat' => 'STRICT/001/2026',
            ])
            ->assertOk();

        $application->refresh();
        $this->assertSame(ScholarshipApplication::STATUS_APPROVED_TENDIK, $application->status);
    }

    public function test_assigned_tendik_cannot_approve_beasiswa_from_non_submitted_statuses(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $previousVerifier = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        foreach ([
            ScholarshipApplication::STATUS_APPROVED_TENDIK,
            ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            ScholarshipApplication::STATUS_COMPLETED,
            ScholarshipApplication::STATUS_REJECTED,
            ScholarshipApplication::STATUS_REVISION,
        ] as $index => $status) {
            $originalApprovedAt = now()->subDays($index + 1)->startOfSecond();
            $originalNomorSurat = "ORIGINAL-BEA-{$index}";

            $application = $this->scholarshipApplication(null, [
                'status' => $status,
                'assigned_to' => $previousVerifier->id,
                'nomor_surat' => $originalNomorSurat,
                'tendik_approved_at' => $originalApprovedAt,
                'tendik_approved_by' => $previousVerifier->id,
            ]);

            $this->actingAs($tendik, 'sanctum')
                ->patchJson("/api/tendik/scholarship/{$application->id}/approve", [
                    'nomor_surat' => 'SHOULD-NOT-APPLY',
                ])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Pengajuan tidak berada pada tahap verifikasi Tendik.');

            $application->refresh();
            $this->assertSame($status, $application->status);
            $this->assertSame($previousVerifier->id, $application->assigned_to);
            $this->assertSame($originalNomorSurat, $application->nomor_surat);
            $this->assertSame($originalApprovedAt->toDateTimeString(), $application->tendik_approved_at?->toDateTimeString());
            $this->assertSame($previousVerifier->id, $application->tendik_approved_by);
        }
    }

    public function test_assigned_tendik_cannot_act_on_non_actionable_status_for_magang(): void
    {
        // Verifies that even when scope passes, the workflow gate refuses
        // non-actionable transitions.
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/approve", [
                'nomor_surat' => 'STRICT/MAG/002/2026',
            ])
            ->assertStatus(422);

        $application->refresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, $application->status);
    }

    public function test_unassigned_tendik_cannot_act_even_when_status_is_submitted(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/scholarship/{$application->id}/approve", [
                'nomor_surat' => 'STRICT/003/2026',
            ])
            ->assertForbidden();

        $application->refresh();
        $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $application->status);
        $this->assertNull($application->tendik_approved_by);
        $this->assertNull($application->nomor_surat);
    }

    public function test_unassigned_tendik_cannot_revise_or_reject(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $magang = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$magang->id}/revise", [
                'note' => 'should be denied',
            ])
            ->assertForbidden();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$magang->id}/reject", [
                'reason' => 'should be denied',
            ])
            ->assertForbidden();

        $magang->refresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $magang->status);
        $this->assertNull($magang->revised_by);
        $this->assertNull($magang->rejected_by);
    }

    // -------------------------------------------------------------------
    // Read access from history/processed statuses still works.
    // -------------------------------------------------------------------

    public function test_assigned_tendik_can_view_history_statuses(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        foreach ([
            ScholarshipApplication::STATUS_APPROVED_TENDIK,
            ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            ScholarshipApplication::STATUS_COMPLETED,
            ScholarshipApplication::STATUS_REJECTED,
            ScholarshipApplication::STATUS_REVISION,
        ] as $status) {
            $application = $this->scholarshipApplication(null, ['status' => $status]);
            $this->actingAs($tendik, 'sanctum')
                ->getJson("/api/tendik/scholarship/{$application->id}")
                ->assertOk();
        }
    }
}
