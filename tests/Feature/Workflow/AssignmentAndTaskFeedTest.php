<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Services\ProsesLuarNegeriService;
use App\Services\ScholarshipAutomationService;
use App\Services\SuratKeteranganAktifService;
use App\Services\SuratPengantarMagangService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentAndTaskFeedTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_surat_types_includes_surat_keterangan_aktif(): void
    {
        $admin = $this->primarySuperAdmin();

        $types = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/surat-types')
            ->assertOk()
            ->json();

        $aktifType = collect($types)->firstWhere('key', SuratKeteranganAktifApplication::LETTER_TYPE);

        $this->assertNotNull($aktifType);
        $this->assertSame('Surat Keterangan Aktif', $aktifType['label']);
        $this->assertSame('administrasi', $aktifType['category']);
    }

    public function test_surat_types_includes_proses_luar_negeri(): void
    {
        $admin = $this->primarySuperAdmin();

        $types = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/surat-types')
            ->assertOk()
            ->json();

        $type = collect($types)->firstWhere('key', ProsesLuarNegeriApplication::LETTER_TYPE);

        $this->assertNotNull($type);
        $this->assertSame('Proses Luar Negeri', $type['label']);
        $this->assertSame('administrasi', $type['category']);
    }

    public function test_super_admin_assignment_accepts_surat_keterangan_aktif_key(): void
    {
        $admin = $this->primarySuperAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/super-admin/users', [
                'name' => 'Aktif Tendik',
                'email' => 'aktif-tendik@example.test',
                'password' => 'password123',
                'role' => 'tendik',
                'tendik_role' => 'persuratan',
                'assigned_tasks' => [SuratKeteranganAktifApplication::LETTER_TYPE],
            ])
            ->assertCreated();

        $this->assertSame(
            [SuratKeteranganAktifApplication::LETTER_TYPE],
            \App\Models\User::where('email', 'aktif-tendik@example.test')->firstOrFail()->assigned_tasks
        );
    }

    public function test_super_admin_assignment_accepts_proses_luar_negeri_key(): void
    {
        $admin = $this->primarySuperAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/super-admin/users', [
                'name' => 'Proses Luar Negeri Tendik',
                'email' => 'proses-luar-negeri-tendik@example.test',
                'password' => 'password123',
                'role' => 'tendik',
                'tendik_role' => 'persuratan',
                'assigned_tasks' => [ProsesLuarNegeriApplication::LETTER_TYPE],
            ])
            ->assertCreated();

        $this->assertSame(
            [ProsesLuarNegeriApplication::LETTER_TYPE],
            \App\Models\User::where('email', 'proses-luar-negeri-tendik@example.test')->firstOrFail()->assigned_tasks
        );
    }

    public function test_persuratan_tendik_with_aktif_key_can_receive_and_see_aktif_tasks(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication();

        $assigned = app(SuratKeteranganAktifService::class)->assignApplication($application);

        $this->assertTrue($assigned?->is($tendik));
        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'assigned_to' => $tendik->id,
        ]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk();

        $this->assertTrue(collect($response->json('tasks'))->contains(
            fn (array $row): bool => $row['letter_type'] === SuratKeteranganAktifApplication::LETTER_TYPE
                && $row['id'] === $application->id
                && $row['letter_label'] === 'Surat Keterangan Aktif'
                && $row['category'] === 'administrasi'
        ));
    }

    public function test_persuratan_tendik_with_proses_luar_negeri_key_can_receive_and_see_proses_luar_negeri_tasks(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication();

        $assigned = app(ProsesLuarNegeriService::class)->assignApplication($application);

        $this->assertTrue($assigned?->is($tendik));
        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'assigned_to' => $tendik->id,
        ]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk();

        $this->assertTrue(collect($response->json('tasks'))->contains(
            fn (array $row): bool => $row['letter_type'] === ProsesLuarNegeriApplication::LETTER_TYPE
                && $row['id'] === $application->id
                && $row['letter_label'] === 'Proses Luar Negeri'
                && $row['category'] === 'administrasi'
        ));
    }

    public function test_akademik_task_feed_includes_aktif_rows_with_canonical_metadata(): void
    {
        $kaprodi = $this->akademik('kaprodi');
        $application = $this->aktifApplication(null, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
        ]);

        $response = $this->actingAs($kaprodi, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk();

        $this->assertTrue(collect($response->json('tasks'))->contains(
            fn (array $row): bool => $row['letter_type'] === SuratKeteranganAktifApplication::LETTER_TYPE
                && $row['id'] === $application->id
                && $row['letter_label'] === 'Surat Keterangan Aktif'
                && $row['category'] === 'administrasi'
        ));
    }

    public function test_akademik_task_feed_includes_proses_luar_negeri_rows_with_canonical_metadata(): void
    {
        $kaprodi = $this->akademik('kaprodi');
        $application = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
        ]);

        $response = $this->actingAs($kaprodi, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk();

        $this->assertTrue(collect($response->json('tasks'))->contains(
            fn (array $row): bool => $row['letter_type'] === ProsesLuarNegeriApplication::LETTER_TYPE
                && $row['id'] === $application->id
                && $row['letter_label'] === 'Proses Luar Negeri'
                && $row['category'] === 'administrasi'
        ));
    }

    public function test_kadep_task_feed_includes_kaprodi_approved_aktif_rows(): void
    {
        $kadep = $this->akademik('kadep');
        $application = $this->aktifApplication(null, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $response = $this->actingAs($kadep, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk();

        $this->assertTrue(collect($response->json('tasks'))->contains(
            fn (array $row): bool => $row['letter_type'] === SuratKeteranganAktifApplication::LETTER_TYPE
                && $row['id'] === $application->id
        ));
    }

    public function test_kadep_task_feed_includes_kaprodi_approved_proses_luar_negeri_rows(): void
    {
        $kadep = $this->akademik('kadep');
        $application = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $response = $this->actingAs($kadep, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk();

        $this->assertTrue(collect($response->json('tasks'))->contains(
            fn (array $row): bool => $row['letter_type'] === ProsesLuarNegeriApplication::LETTER_TYPE
                && $row['id'] === $application->id
        ));
    }

    public function test_persuratan_tendik_with_magang_key_can_receive_and_see_magang_tasks(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication();

        $assigned = app(SuratPengantarMagangService::class)->assignApplication($application);

        $this->assertTrue($assigned?->is($tendik));
        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'assigned_to' => $tendik->id,
        ]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk();

        $this->assertTrue(collect($response->json('tasks'))->contains(
            fn (array $row): bool => $row['letter_type'] === SuratPengantarMagangApplication::LETTER_TYPE
                && $row['id'] === $application->id
        ));
    }

    public function test_persuratan_tendik_with_beasiswa_key_can_receive_and_see_beasiswa_tasks(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication();

        $assigned = app(ScholarshipAutomationService::class)->assignApplication($application);

        $this->assertTrue($assigned?->is($tendik));
        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'assigned_to' => $tendik->id,
        ]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk();

        $this->assertTrue(collect($response->json('tasks'))->contains(
            fn (array $row): bool => $row['letter_type'] === ScholarshipApplication::LETTER_TYPE
                && $row['id'] === $application->id
        ));
    }

    public function test_sarpras_tendik_cannot_see_persuratan_tasks(): void
    {
        $sarpras = $this->tendikSarpras();
        $this->prosesLuarNegeriApplication();
        $this->aktifApplication();
        $this->magangApplication();
        $this->scholarshipApplication();

        $this->actingAs($sarpras, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->assertJsonPath('stats.total_incoming', 0)
            ->assertJsonPath('stats.needs_verification', 0)
            ->assertJsonPath('tasks', []);
    }

    public function test_tendik_task_feed_uses_canonical_letter_types_to_distinguish_rows(): void
    {
        $tendik = $this->tendikPersuratan([
            ProsesLuarNegeriApplication::LETTER_TYPE,
            SuratKeteranganAktifApplication::LETTER_TYPE,
            SuratPengantarMagangApplication::LETTER_TYPE,
            ScholarshipApplication::LETTER_TYPE,
        ]);

        $this->prosesLuarNegeriApplication(null, ['assigned_to' => $tendik->id]);
        $this->aktifApplication(null, ['assigned_to' => $tendik->id]);
        $this->magangApplication(null, ['assigned_to' => $tendik->id]);
        $this->scholarshipApplication(null, ['assigned_to' => $tendik->id]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $letterTypes = collect($tasks)->pluck('letter_type')->values();

        $this->assertContains(ProsesLuarNegeriApplication::LETTER_TYPE, $letterTypes);
        $this->assertContains(SuratKeteranganAktifApplication::LETTER_TYPE, $letterTypes);
        $this->assertContains(SuratPengantarMagangApplication::LETTER_TYPE, $letterTypes);
        $this->assertContains(ScholarshipApplication::LETTER_TYPE, $letterTypes);
        $this->assertSame(
            [
                ProsesLuarNegeriApplication::LETTER_TYPE,
                SuratKeteranganAktifApplication::LETTER_TYPE,
                SuratPengantarMagangApplication::LETTER_TYPE,
                ScholarshipApplication::LETTER_TYPE,
            ],
            $letterTypes->unique()->sort()->values()->all()
        );
    }

    public function test_tendik_active_dashboard_excludes_rejected_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ScholarshipApplication::STATUS_REJECTED,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertFalse(collect($tasks)->contains('id', $app->id));
    }

    public function test_tendik_active_dashboard_excludes_approved_kaprodi_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertFalse(collect($tasks)->contains('id', $app->id));
    }

    public function test_tendik_active_dashboard_excludes_completed_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ScholarshipApplication::STATUS_COMPLETED,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertFalse(collect($tasks)->contains('id', $app->id));
    }

    public function test_tendik_active_dashboard_excludes_revision_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ScholarshipApplication::STATUS_REVISION,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertFalse(collect($tasks)->contains('id', $app->id));
    }

    public function test_tendik_active_dashboard_excludes_draft_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ScholarshipApplication::STATUS_DRAFT,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertFalse(collect($tasks)->contains('id', $app->id));
    }

    public function test_tendik_active_dashboard_includes_approved_tendik_beasiswa(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $app = $this->scholarshipApplication(null, [
            'assigned_to' => $tendik->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);

        $tasks = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertTrue(collect($tasks)->contains('id', $app->id));
    }

    public function test_legacy_short_assignment_keys_are_rejected_on_write(): void
    {
        $admin = $this->primarySuperAdmin();
        $target = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/super-admin/users', [
                'name' => 'Legacy Magang Tendik',
                'email' => 'legacy-magang@example.test',
                'password' => 'password123',
                'role' => 'tendik',
                'tendik_role' => 'persuratan',
                'assigned_tasks' => ['magang'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_tasks.0']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/super-admin/users/{$target->id}", [
                'assigned_tasks' => ['beasiswa'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_tasks.0']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/super-admin/users/{$target->id}", [
                'assigned_tasks' => ['aktif'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_tasks.0']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/super-admin/users/{$target->id}", [
                'assigned_tasks' => ['luar_negeri'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_tasks.0']);
    }
}
