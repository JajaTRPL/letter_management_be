<?php

namespace Tests\Feature\Workflow;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TendikRiwayatTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    // ── Response contract ─────────────────────────────────────────────────────

    public function test_riwayat_returns_tasks_key(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->assertJsonStructure(['tasks']);
    }

    // ── Auth / role guards ────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/tendik/riwayat')->assertUnauthorized();
    }

    public function test_mahasiswa_role_is_forbidden(): void
    {
        $mahasiswa = $this->activeUser(['role' => 'mahasiswa']);

        $this->actingAs($mahasiswa, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertForbidden();
    }

    public function test_akademik_role_is_forbidden(): void
    {
        $akademik = $this->akademik('kaprodi');

        $this->actingAs($akademik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertForbidden();
    }

    // ── Beasiswa: included historical statuses ────────────────────────────────

    public function test_riwayat_includes_rejected_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status'      => ScholarshipApplication::STATUS_REJECTED,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');

        $this->assertTrue(collect($tasks)->contains('id', $app->id));
    }

    public function test_riwayat_includes_revision_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status'      => ScholarshipApplication::STATUS_REVISION,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');

        $this->assertTrue(collect($tasks)->contains('id', $app->id));
    }

    public function test_riwayat_includes_approved_kaprodi_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status'      => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');

        $this->assertTrue(collect($tasks)->contains('id', $app->id));
    }

    public function test_riwayat_includes_ready_for_student_review_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status'      => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');

        $this->assertTrue(collect($tasks)->contains('id', $app->id));
    }

    public function test_riwayat_includes_completed_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status'      => ScholarshipApplication::STATUS_COMPLETED,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');

        $this->assertTrue(collect($tasks)->contains('id', $app->id));
    }

    // ── Beasiswa: excluded active statuses ────────────────────────────────────

    public function test_riwayat_excludes_submitted_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status'      => ScholarshipApplication::STATUS_SUBMITTED,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');

        $this->assertFalse(collect($tasks)->contains('id', $app->id));
    }

    public function test_riwayat_excludes_approved_tendik_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status'      => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');

        $this->assertFalse(collect($tasks)->contains('id', $app->id));
    }

    public function test_riwayat_excludes_draft_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status'      => ScholarshipApplication::STATUS_DRAFT,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');

        $this->assertFalse(collect($tasks)->contains('id', $app->id));
    }

    // ── Cross-Tendik isolation ────────────────────────────────────────────────

    public function test_tendik_b_cannot_see_tendik_a_assigned_riwayat(): void
    {
        $tendikA = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $tendikB = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendikA->id,
            'status'      => ScholarshipApplication::STATUS_REJECTED,
        ]);

        $tasks = $this->actingAs($tendikB, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');

        $this->assertFalse(collect($tasks)->contains('id', $app->id));
    }

    // ── Assigned-tasks type isolation ─────────────────────────────────────────

    public function test_tendik_without_magang_task_cannot_see_magang_riwayat(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]); // no magang
        $app = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_REJECTED,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');

        $this->assertFalse(collect($tasks)->contains('id', $app->id));
    }

    public function test_tendik_with_magang_task_sees_magang_riwayat(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $app = $this->magangApplication(null, [
            'assigned_to' => $tendik->id,
            'status'      => SuratPengantarMagangApplication::STATUS_REJECTED,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->json('tasks');

        $this->assertTrue(collect($tasks)->contains('id', $app->id));
    }

    // ── Sarpras Tendik sees nothing (not persuratan) ──────────────────────────

    public function test_sarpras_tendik_sees_empty_riwayat(): void
    {
        $sarpras = $this->tendikSarpras();
        $this->scholarshipApplication(null, ['status' => ScholarshipApplication::STATUS_REJECTED]);

        $this->actingAs($sarpras, 'sanctum')
            ->getJson('/api/tendik/riwayat')
            ->assertOk()
            ->assertJsonPath('tasks', []);
    }
}
