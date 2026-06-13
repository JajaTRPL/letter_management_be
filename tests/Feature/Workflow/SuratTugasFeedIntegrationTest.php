<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pass B: proves Surat Tugas application rows surface in the correct
 * Tendik/Akademik queues, riwayat buckets, dashboard counters and cursor
 * feed — through the canonical per-letter feed pattern — without disturbing
 * existing letters. No workflow controller/routes exist yet; rows are seeded
 * directly to verify feed/dashboard/riwayat aggregation only.
 */
class SuratTugasFeedIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    // ── Tendik actionable queue ────────────────────────────────────────────

    public function test_assigned_tendik_sees_submitted_surat_tugas_in_actionable_queue(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication(null, ['assigned_to' => $tendik->id]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertTrue(collect($tasks)->contains(
            fn (array $row): bool => $row['letter_type'] === SuratTugasApplication::LETTER_TYPE
                && $row['id'] === $application->id
        ));
    }

    public function test_unassigned_tendik_does_not_see_submitted_surat_tugas(): void
    {
        // Tendik assigned to a different letter type only.
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication();

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertFalse(collect($tasks)->contains(
            fn (array $row): bool => $row['letter_type'] === SuratTugasApplication::LETTER_TYPE
                && $row['id'] === $application->id
        ));
    }

    public function test_sarpras_tendik_does_not_see_surat_tugas(): void
    {
        $sarpras = $this->tendikSarpras();
        $this->suratTugasApplication();

        $this->actingAs($sarpras, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->assertJsonPath('stats.total_incoming', 0)
            ->assertJsonPath('stats.needs_verification', 0)
            ->assertJsonPath('tasks', []);
    }

    public function test_approved_tendik_surat_tugas_absent_from_tendik_actionable_queue(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertFalse(collect($tasks)->contains(
            fn (array $row): bool => $row['letter_type'] === SuratTugasApplication::LETTER_TYPE
                && $row['id'] === $application->id
        ));
    }

    // ── Tendik riwayat ─────────────────────────────────────────────────────

    public function test_tendik_riwayat_includes_historical_surat_tugas_and_excludes_active_submitted(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);

        $historical = collect([
            SuratTugasApplication::STATUS_APPROVED_TENDIK,
            SuratTugasApplication::STATUS_REVISION,
            SuratTugasApplication::STATUS_REJECTED,
            SuratTugasApplication::STATUS_APPROVED_KAPRODI,
            SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            SuratTugasApplication::STATUS_COMPLETED,
        ])->map(fn (string $status): SuratTugasApplication => $this->suratTugasApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => $status,
        ]));

        $submitted = $this->suratTugasApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratTugasApplication::STATUS_SUBMITTED,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');

        $historical->each(fn (SuratTugasApplication $task) => $this->assertTrue(
            collect($tasks)->contains(
                fn (array $row): bool => $row['letter_type'] === SuratTugasApplication::LETTER_TYPE && $row['id'] === $task->id
            ),
            "Expected historical Surat Tugas {$task->id} ({$task->status}) in Tendik riwayat."
        ));

        $this->assertFalse(collect($tasks)->contains(
            fn (array $row): bool => $row['letter_type'] === SuratTugasApplication::LETTER_TYPE && $row['id'] === $submitted->id
        ), 'Active Submitted Surat Tugas must not appear in Tendik riwayat.');
    }

    // ── Akademik Prodi queue ───────────────────────────────────────────────

    public function test_kaprodi_sees_approved_tendik_surat_tugas_in_scope_and_excludes_other_prodi(): void
    {
        $department = $this->department(['name' => 'DTEDI']);
        $prodi = $this->studyProgram($department, ['name' => 'TRPL']);
        $otherProdi = $this->studyProgram($this->department(['name' => 'Other']), ['name' => 'Other Program']);

        [$inScopeStudent] = $this->completeMahasiswa([], [], $prodi);
        [$outScopeStudent] = $this->completeMahasiswa([], [], $otherProdi);

        $inScope = $this->suratTugasApplication($inScopeStudent, ['status' => SuratTugasApplication::STATUS_APPROVED_TENDIK]);
        $outScope = $this->suratTugasApplication($outScopeStudent, ['status' => SuratTugasApplication::STATUS_APPROVED_TENDIK]);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $prodi->id]);

        $tasks = $this->actingAs($kaprodi, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertTrue(collect($tasks)->contains(
            fn (array $row): bool => $row['letter_type'] === SuratTugasApplication::LETTER_TYPE && $row['id'] === $inScope->id
        ));
        $this->assertFalse(collect($tasks)->contains(
            fn (array $row): bool => $row['letter_type'] === SuratTugasApplication::LETTER_TYPE && $row['id'] === $outScope->id
        ));
    }

    // ── Akademik Departemen queue ──────────────────────────────────────────

    public function test_kadep_sees_approved_kaprodi_surat_tugas_in_scope_and_excludes_other_dept(): void
    {
        $department = $this->department(['name' => 'DTEDI']);
        $prodi = $this->studyProgram($department, ['name' => 'TRPL']);
        $otherProdi = $this->studyProgram($this->department(['name' => 'Other']), ['name' => 'Other Program']);

        [$inScopeStudent] = $this->completeMahasiswa([], [], $prodi);
        [$outScopeStudent] = $this->completeMahasiswa([], [], $otherProdi);

        $inScope = $this->suratTugasApplication($inScopeStudent, ['status' => SuratTugasApplication::STATUS_APPROVED_KAPRODI]);
        $outScope = $this->suratTugasApplication($outScopeStudent, ['status' => SuratTugasApplication::STATUS_APPROVED_KAPRODI]);

        $kadep = $this->akademik('kadep', ['department_id' => $department->id]);

        $tasks = $this->actingAs($kadep, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertTrue(collect($tasks)->contains(
            fn (array $row): bool => $row['letter_type'] === SuratTugasApplication::LETTER_TYPE && $row['id'] === $inScope->id
        ));
        $this->assertFalse(collect($tasks)->contains(
            fn (array $row): bool => $row['letter_type'] === SuratTugasApplication::LETTER_TYPE && $row['id'] === $outScope->id
        ));
    }

    // ── Cursor feed identity ───────────────────────────────────────────────

    public function test_surat_tugas_cursor_row_has_standalone_identity(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $application = $this->suratTugasApplication(null, ['assigned_to' => $tendik->id]);

        $row = collect($this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=10')
            ->assertOk()
            ->assertJsonPath('meta.pagination_type', 'cursor')
            ->json('tasks'))
            ->firstWhere('id', $application->id);

        $this->assertNotNull($row, 'Surat Tugas row must be present in cursor feed.');
        $this->assertSame(SuratTugasApplication::LETTER_TYPE, $row['letter_type']);
        $this->assertSame('Surat Tugas', $row['letter_label']);
        $this->assertSame('Surat Tugas', $row['type']);
        $this->assertSame('Menunggu Verifikasi', $row['status']);
        $this->assertArrayNotHasKey('_sort_at', $row);
        $this->assertArrayNotHasKey('sort_timestamp', $row);
        // Standalone identity: never the Magang key/label.
        $this->assertNotSame(SuratPengantarMagangApplication::LETTER_TYPE, $row['letter_type']);
        $this->assertNotSame('Surat Pengantar Magang', $row['letter_label']);
    }

    // ── Dashboard counters ─────────────────────────────────────────────────

    public function test_tendik_counters_count_actionable_surat_tugas_in_canonical_pool(): void
    {
        // Mirrors the canonical Magang/Aktif/PLN pool semantics: an eligible
        // Persuratan Tendik sees BOTH rows assigned to them and unassigned-
        // Submitted rows (the shared intake pool). The status gate still limits
        // the actionable bucket to Submitted only — Approved_Tendik is excluded.
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);

        // Assigned + Submitted → counts (actionable, mine).
        $this->suratTugasApplication(null, ['assigned_to' => $tendik->id]);
        // Unassigned + Submitted → counts (actionable, shared pool).
        $this->suratTugasApplication();
        // Assigned but already Approved_Tendik → excluded by status gate.
        $this->suratTugasApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->assertJsonPath('stats.total_incoming', 2)
            ->assertJsonPath('stats.needs_verification', 2);
    }

    public function test_tendik_cursor_stats_count_assigned_actionable_surat_tugas(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $this->suratTugasApplication(null, ['assigned_to' => $tendik->id]);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?page_size=10')
            ->assertOk()
            ->assertJsonPath('meta.pagination_type', 'cursor')
            ->assertJsonPath('stats.total_incoming', 1)
            ->assertJsonPath('stats.needs_verification', 1);
    }

    // ── Number projection (centralized resolver) ───────────────────────────

    public function test_surat_tugas_number_is_null_before_approval_and_set_after(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);

        // Before approval: Submitted, no number → projected nomor_surat is null.
        $submitted = $this->suratTugasApplication(null, ['assigned_to' => $tendik->id]);
        $dashboardRow = collect($this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json('tasks'))
            ->firstWhere('id', $submitted->id);
        $this->assertNotNull($dashboardRow);
        $this->assertNull($dashboardRow['nomor_surat']);

        // After approval: Approved_Tendik with nomor_surat_tugas → projected via
        // the centralized resolver into the canonical nomor_surat field.
        $approved = $this->suratTugasApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat_tugas' => 'ST/RIWAYAT/009',
        ]);
        $riwayatRow = collect($this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks'))
            ->firstWhere('id', $approved->id);
        $this->assertNotNull($riwayatRow);
        $this->assertSame('ST/RIWAYAT/009', $riwayatRow['nomor_surat']);
    }

    public function test_existing_letter_number_projection_is_unchanged(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $beasiswa = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => 'BEA/2026/001',
        ]);

        $row = collect($this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks'))
            ->firstWhere('id', $beasiswa->id);

        $this->assertNotNull($row);
        $this->assertSame('BEA/2026/001', $row['nomor_surat']);
    }

    public function test_surat_tugas_does_not_leak_into_unrelated_letter_counters(): void
    {
        // A tendik with ONLY a beasiswa assignment must not gain Surat Tugas
        // visibility or counters even though Surat Tugas rows exist.
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $this->suratTugasApplication();
        $beasiswa = $this->scholarshipApplication(null, ['assigned_to' => $tendik->id]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->assertJsonPath('stats.total_incoming', 1)
            ->assertJsonPath('stats.needs_verification', 1);

        $tasks = $response->json('tasks');
        $this->assertTrue(collect($tasks)->contains(
            fn (array $row): bool => $row['letter_type'] === ScholarshipApplication::LETTER_TYPE && $row['id'] === $beasiswa->id
        ));
        $this->assertFalse(collect($tasks)->contains(
            fn (array $row): bool => $row['letter_type'] === SuratTugasApplication::LETTER_TYPE
        ));
    }
}
