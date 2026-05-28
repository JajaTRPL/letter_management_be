<?php

namespace Tests\Feature\Workflow;

use App\Enums\UserStatus;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TendikTeamScopeTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_unauthenticated_api_request_without_accept_json_returns_json_401(): void
    {
        $this->get('/api/tendik/dashboard/tasks')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    // -------------------------------------------------------------------
    // Visibility — scope=mine vs scope=team
    // -------------------------------------------------------------------

    public function test_scope_team_is_restricted_to_assigned_letter_types(): void
    {
        // Tendik has ONLY beasiswa in assigned_tasks. Under the strict
        // assignment model, even team scope must NOT surface other letter
        // types — read access follows assigned_tasks.
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $this->scholarshipApplication();
        $this->magangApplication();
        $this->aktifApplication();
        $this->prosesLuarNegeriApplication();

        // mine: only beasiswa, and only unassigned-Submitted Beasiswa.
        $mine = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json();
        $this->assertSame('mine', $mine['scope']);
        $mineLetterTypes = collect($mine['tasks'])->pluck('letter_type')->unique()->values()->all();
        $this->assertSame([ScholarshipApplication::LETTER_TYPE], $mineLetterTypes);

        // team: still scoped to the user's assigned letter types — Beasiswa only.
        $team = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?scope=team')
            ->assertOk()
            ->json();
        $this->assertSame('team', $team['scope']);
        $teamLetterTypes = collect($team['tasks'])->pluck('letter_type')->unique()->values()->all();
        $this->assertSame([ScholarshipApplication::LETTER_TYPE], $teamLetterTypes);
    }

    public function test_scope_team_shows_other_tendiks_rows_to_persuratan_helpers(): void
    {
        $tendikA = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $tendikB = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);

        $assignedToA = $this->magangApplication(null, [
            'assigned_to' => $tendikA->id,
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);

        // Mine for tendikB: should not include A's row.
        $bMine = $this->actingAs($tendikB, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');
        $this->assertFalse(collect($bMine)->contains('id', $assignedToA->id));

        // Team for tendikB: should include A's row (and the actor name).
        $bTeam = $this->actingAs($tendikB, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?scope=team')
            ->assertOk()
            ->json('tasks');
        $bTeamRow = collect($bTeam)->firstWhere('id', $assignedToA->id);
        $this->assertNotNull($bTeamRow);
        $this->assertSame($tendikA->id, $bTeamRow['assigned_to']);
        $this->assertSame($tendikA->name, $bTeamRow['assigned_tendik_name']);
    }

    public function test_scope_team_riwayat_shows_team_history_to_persuratan(): void
    {
        $tendikA = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $tendikB = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $historicAssignedToA = $this->scholarshipApplication(null, [
            'assigned_to' => $tendikA->id,
            'status' => ScholarshipApplication::STATUS_COMPLETED,
        ]);

        // Riwayat scope=mine for B excludes A's row.
        $bMine = $this->actingAs($tendikB, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');
        $this->assertFalse(collect($bMine)->contains('id', $historicAssignedToA->id));

        // Riwayat scope=team for B includes A's row.
        $bTeam = $this->actingAs($tendikB, 'sanctum')
            ->getJson('/api/tendik/riwayat?scope=team')
            ->assertOk()
            ->json('tasks');
        $this->assertTrue(collect($bTeam)->contains('id', $historicAssignedToA->id));
    }

    public function test_scope_team_for_non_persuratan_subroles_returns_empty(): void
    {
        // Seed data so a leaky implementation would return rows.
        $this->scholarshipApplication();
        $this->magangApplication();
        $this->aktifApplication();
        $this->prosesLuarNegeriApplication();

        foreach (['sarpras', 'kepala_lab', 'laboran'] as $subRole) {
            $tendik = $this->makeTendikSubrole($subRole);

            $response = $this->actingAs($tendik, 'sanctum')
                ->getJson('/api/tendik/dashboard/tasks?scope=team')
                ->assertOk();

            $response->assertJsonPath('stats.total_incoming', 0);
            $response->assertJsonPath('stats.needs_verification', 0);
            $response->assertJsonPath('tasks', []);

            $this->actingAs($tendik, 'sanctum')
                ->getJson('/api/tendik/riwayat?scope=team')
                ->assertOk()
                ->assertJsonPath('tasks', []);
        }
    }

    public function test_invalid_scope_returns_422(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?scope=garbage')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scope']);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat?scope=GLOBAL')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scope']);
    }

    // -------------------------------------------------------------------
    // Action — team authorization + actor recording
    // -------------------------------------------------------------------

    // -------------------------------------------------------------------
    // Detail authorization
    // -------------------------------------------------------------------

    public function test_persuratan_with_only_beasiswa_can_view_beasiswa_detail_but_not_other_letters(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $scholarship = $this->scholarshipApplication();
        $magang = $this->magangApplication();
        $aktif = $this->aktifApplication();
        $pln = $this->prosesLuarNegeriApplication();

        $allowedLabels = ['beasiswa_alias', 'beasiswa_canonical'];

        foreach ($this->adminLetterDetailUrls($scholarship, $magang, $aktif, $pln) as $label => $url) {
            $response = $this->actingAs($tendik, 'sanctum')->getJson($url);

            if (in_array($label, $allowedLabels, true)) {
                $response->assertOk()->assertJsonPath('application.id', $scholarship->id);
                continue;
            }

            // Non-Beasiswa detail must be denied for a Tendik whose
            // assigned_tasks does not include that letter type.
            $response->assertForbidden();
            $payload = $response->json();
            $this->assertIsArray($payload);
            $this->assertArrayNotHasKey('application', $payload);
            $this->assertArrayNotHasKey('student', $payload);
            $this->assertArrayNotHasKey('docx_url', $payload);
        }
    }

    public function test_sarpras_tendik_cannot_view_admin_letter_details(): void
    {
        $this->assertSubroleCannotViewAdminLetterDetails('sarpras');
    }

    public function test_kepala_lab_tendik_cannot_view_admin_letter_details(): void
    {
        $this->assertSubroleCannotViewAdminLetterDetails('kepala_lab');
    }

    public function test_laboran_tendik_cannot_view_admin_letter_details(): void
    {
        $this->assertSubroleCannotViewAdminLetterDetails('laboran');
    }

    public function test_persuratan_with_only_beasiswa_cannot_approve_magang(): void
    {
        // assigned_tasks deliberately excludes magang. Under the strict
        // assignment model, the action must be denied — no team-scope shortcut.
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $owningTendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);

        $magang = $this->magangApplication(null, [
            'assigned_to' => $owningTendik->id,
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$magang->id}/approve", [
                'nomor_surat' => '042/SPM/2026',
            ])
            ->assertForbidden();

        $magang->refresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $magang->status);
        $this->assertNull($magang->tendik_approved_by);
        $this->assertNull($magang->tendik_approved_at);
        $this->assertSame($owningTendik->id, $magang->assigned_to);
        $this->assertNull($magang->nomor_surat);
    }

    public function test_persuratan_with_only_magang_cannot_revise_aktif(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $aktif = $this->aktifApplication(null, [
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
            'assigned_to' => null,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$aktif->id}/revise", [
                'note' => 'Mohon perbaiki dokumen pendukung.',
            ])
            ->assertForbidden();

        $aktif->refresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_SUBMITTED, $aktif->status);
        $this->assertNull($aktif->revised_by);
        $this->assertNull($aktif->revised_at);
        $this->assertNull($aktif->assigned_to);
        $this->assertNull($aktif->revision_note);
    }

    public function test_persuratan_with_only_aktif_cannot_reject_pln(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $owningTendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);

        $pln = $this->prosesLuarNegeriApplication(null, [
            'assigned_to' => $owningTendik->id,
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$pln->id}/reject", [
                'reason' => 'Dokumen tidak lengkap.',
            ])
            ->assertForbidden();

        $pln->refresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_SUBMITTED, $pln->status);
        $this->assertNull($pln->rejected_by);
        $this->assertNull($pln->rejected_at);
        $this->assertSame($owningTendik->id, $pln->assigned_to);
        $this->assertNull($pln->rejection_reason);
    }

    public function test_persuratan_without_beasiswa_assignment_cannot_approve_beasiswa(): void
    {
        // Tendik assigned only to Magang attempts to approve a Beasiswa
        // submitted by another Tendik's pool. Strict assignment denies.
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $owningTendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $beasiswa = $this->scholarshipApplication(null, [
            'assigned_to' => $owningTendik->id,
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/scholarship/{$beasiswa->id}/approve", [
                'nomor_surat' => '007/SPB/2026',
            ])
            ->assertForbidden();

        $beasiswa->refresh();
        $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $beasiswa->status);
        $this->assertNull($beasiswa->tendik_approved_by);
        $this->assertNull($beasiswa->tendik_approved_at);
        $this->assertSame($owningTendik->id, $beasiswa->assigned_to);
        $this->assertNull($beasiswa->nomor_surat);
    }

    public function test_assigned_to_is_set_to_actor_when_initially_null(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $beasiswa = $this->scholarshipApplication(null, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'assigned_to' => null,
        ]);

        $this->mockBeasiswaPreviewGenerationForApprove();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/scholarship/{$beasiswa->id}/approve", [
                'nomor_surat' => '008/SPB/2026',
            ])
            ->assertOk();

        $beasiswa->refresh();
        $this->assertSame($tendik->id, $beasiswa->assigned_to);
        $this->assertSame($tendik->id, $beasiswa->tendik_approved_by);
        $this->assertSame('008/SPB/2026', $beasiswa->nomor_surat);
    }

    // -------------------------------------------------------------------
    // Action — non-Persuratan denial
    // -------------------------------------------------------------------

    public function test_non_persuratan_tendik_cannot_act_on_admin_letters(): void
    {
        $magang = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);

        foreach (['sarpras', 'kepala_lab', 'laboran'] as $subRole) {
            $tendik = $this->makeTendikSubrole($subRole);

            $this->actingAs($tendik, 'sanctum')
                ->patchJson("/api/tendik/surat-pengantar-magang/{$magang->id}/approve", [
                    'nomor_surat' => '999/X/2026',
                ])
                ->assertForbidden();

            $magang->refresh();
            // Untouched by the forbidden call.
            $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $magang->status);
            $this->assertNull($magang->tendik_approved_by);
        }
    }

    public function test_non_persuratan_tendik_cannot_revise_or_reject_admin_letters(): void
    {
        $aktif = $this->aktifApplication(null, [
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);

        $sarpras = $this->makeTendikSubrole('sarpras');

        $this->actingAs($sarpras, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$aktif->id}/revise", ['note' => 'x'])
            ->assertForbidden();

        $this->actingAs($sarpras, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$aktif->id}/reject", ['reason' => 'x'])
            ->assertForbidden();

        $aktif->refresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_SUBMITTED, $aktif->status);
        $this->assertNull($aktif->revised_by);
        $this->assertNull($aktif->rejected_by);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function makeTendikSubrole(string $subRole): User
    {
        return User::create([
            'name' => "Tendik {$subRole} " . uniqid(),
            'email' => $subRole . '-' . uniqid() . '@example.test',
            'password' => 'password',
            'role' => 'tendik',
            'tendik_role' => $subRole,
            'status' => UserStatus::Active,
            'assigned_tasks' => null,
        ]);
    }

    private function assertSubroleCannotViewAdminLetterDetails(string $subRole): void
    {
        $tendik = $this->makeTendikSubrole($subRole);

        $scholarship = $this->scholarshipApplication();
        $magang = $this->magangApplication();
        $aktif = $this->aktifApplication();
        $pln = $this->prosesLuarNegeriApplication();

        foreach ($this->adminLetterDetailUrls($scholarship, $magang, $aktif, $pln) as $url) {
            $response = $this->actingAs($tendik, 'sanctum')
                ->getJson($url)
                ->assertForbidden();

            $payload = $response->json();
            $this->assertIsArray($payload);
            $this->assertArrayNotHasKey('application', $payload);
            $this->assertArrayNotHasKey('student', $payload);
            $this->assertArrayNotHasKey('docx_url', $payload);
        }
    }

    private function adminLetterDetailUrls(
        ScholarshipApplication $scholarship,
        SuratPengantarMagangApplication $magang,
        SuratKeteranganAktifApplication $aktif,
        ProsesLuarNegeriApplication $pln
    ): array {
        return [
            'beasiswa_alias' => "/api/tendik/scholarship/{$scholarship->id}",
            'beasiswa_canonical' => "/api/tendik/surat-permohonan-beasiswa/{$scholarship->id}",
            'magang' => "/api/tendik/surat-pengantar-magang/{$magang->id}",
            'aktif' => "/api/tendik/surat-keterangan-aktif/{$aktif->id}",
            'pln' => "/api/tendik/proses-luar-negeri/{$pln->id}",
        ];
    }
}
