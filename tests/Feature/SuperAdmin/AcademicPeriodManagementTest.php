<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\AcademicPeriod;
use App\Models\User;
use App\Services\AcademicContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

class AcademicPeriodManagementTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function superAdmin(array $attributes = []): User
    {
        return $this->activeUser(array_merge([
            'role'       => 'super_admin',
            'role_level' => 'primary',
        ], $attributes));
    }

    private function baseUrl(string $suffix = ''): string
    {
        return '/api/super-admin/academic-periods' . $suffix;
    }

    private function validPeriodPayload(array $overrides = []): array
    {
        // year_start and semester_order are auto-derived via the API Request pipeline.
        // They are still included here so this helper can also be used for direct
        // AcademicPeriod::create() calls (which bypass prepareForValidation).
        return array_merge([
            'academic_year'  => '2025/2026',
            'year_start'     => 2025,
            'semester_type'  => AcademicPeriod::SEMESTER_TYPE_GENAP,
            'semester_order' => 2,
            'start_date'     => '2026-02-01',
            'end_date'       => '2026-07-31',
            'is_active'      => false,
        ], $overrides);
    }

    private function activePeriodRecord(array $overrides = []): AcademicPeriod
    {
        return AcademicPeriod::create(array_merge([
            'academic_year'  => '2025/2026',
            'year_start'     => 2025,
            'semester_type'  => AcademicPeriod::SEMESTER_TYPE_GENAP,
            'semester_order' => 2,
            'start_date'     => now()->subMonth()->toDateString(),
            'end_date'       => now()->addMonth()->toDateString(),
            'is_active'      => true,
        ], $overrides));
    }

    // -----------------------------------------------------------------------
    // Test 1 – List
    // -----------------------------------------------------------------------

    public function test_super_admin_can_list_academic_periods(): void
    {
        $admin = $this->superAdmin();
        $this->activePeriodRecord();
        AcademicPeriod::create($this->validPeriodPayload(['is_active' => false]));

        Sanctum::actingAs($admin);

        $response = $this->getJson($this->baseUrl());

        $response->assertStatus(200)
            ->assertJsonPath('count', 2)
            ->assertJsonStructure(['message', 'count', 'data']);
    }

    // -----------------------------------------------------------------------
    // Test 2 – Create
    // -----------------------------------------------------------------------

    public function test_super_admin_can_create_an_academic_period(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson($this->baseUrl(), $this->validPeriodPayload());

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Periode akademik berhasil dibuat')
            ->assertJsonPath('data.academic_year', '2025/2026')
            ->assertJsonPath('data.semester_type', AcademicPeriod::SEMESTER_TYPE_GENAP);

        $this->assertDatabaseHas('academic_periods', [
            'academic_year' => '2025/2026',
            'year_start'    => 2025,
        ]);
    }

    // -----------------------------------------------------------------------
    // Test 3 – Update
    // -----------------------------------------------------------------------

    public function test_super_admin_can_update_an_academic_period(): void
    {
        $admin  = $this->superAdmin();
        $period = AcademicPeriod::create($this->validPeriodPayload());

        Sanctum::actingAs($admin);

        $response = $this->putJson($this->baseUrl('/' . $period->id), [
            'start_date' => '2026-03-01',
            'end_date'   => '2026-08-31',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Periode akademik berhasil diperbarui')
            ->assertJsonPath('data.id', $period->id);

        $refreshed = AcademicPeriod::find($period->id);
        $this->assertSame('2026-03-01', $refreshed->start_date->toDateString());
        $this->assertSame('2026-08-31', $refreshed->end_date->toDateString());
    }

    // -----------------------------------------------------------------------
    // Test 4 – Delete
    // -----------------------------------------------------------------------

    public function test_super_admin_can_delete_an_academic_period(): void
    {
        $admin  = $this->superAdmin();
        $period = AcademicPeriod::create($this->validPeriodPayload());

        Sanctum::actingAs($admin);

        $response = $this->deleteJson($this->baseUrl('/' . $period->id));

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Periode akademik berhasil dihapus');

        $this->assertDatabaseMissing('academic_periods', ['id' => $period->id]);
    }

    // -----------------------------------------------------------------------
    // Test 5 – Toggle active
    // -----------------------------------------------------------------------

    public function test_super_admin_can_toggle_active(): void
    {
        $admin  = $this->superAdmin();
        $period = AcademicPeriod::create($this->validPeriodPayload(['is_active' => false]));

        Sanctum::actingAs($admin);

        $response = $this->patchJson($this->baseUrl('/' . $period->id . '/toggle-active'));

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue((bool) AcademicPeriod::find($period->id)->is_active);
    }

    // -----------------------------------------------------------------------
    // Test 6 – Reject invalid academic_year format
    // -----------------------------------------------------------------------

    public function test_reject_invalid_academic_year_format(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson($this->baseUrl(), $this->validPeriodPayload([
            'academic_year' => '2025-2026', // wrong separator
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['academic_year']);
    }

    // -----------------------------------------------------------------------
    // Test 7 – Auto-derive year_start and semester_order from submitted fields
    // -----------------------------------------------------------------------

    public function test_store_derives_year_start_and_semester_order_automatically(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        // Submit only the user-facing fields; backend must derive year_start=2025, semester_order=2
        $response = $this->postJson($this->baseUrl(), [
            'academic_year' => '2025/2026',
            'semester_type' => AcademicPeriod::SEMESTER_TYPE_GENAP,
            'start_date'    => '2026-02-01',
            'end_date'      => '2026-07-31',
            'is_active'     => false,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('academic_periods', [
            'academic_year'  => '2025/2026',
            'year_start'     => 2025,
            'semester_order' => 2,
        ]);
    }

    public function test_store_derives_semester_order_1_for_ganjil(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson($this->baseUrl(), [
            'academic_year' => '2025/2026',
            'semester_type' => AcademicPeriod::SEMESTER_TYPE_GANJIL,
            'start_date'    => '2025-08-01',
            'end_date'      => '2026-01-31',
            'is_active'     => false,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('academic_periods', [
            'academic_year'  => '2025/2026',
            'year_start'     => 2025,
            'semester_order' => 1,
        ]);
    }

    public function test_rejects_pendek_as_semester_type(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson($this->baseUrl(), $this->validPeriodPayload([
            'semester_type' => 'pendek',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['semester_type']);
    }

    // -----------------------------------------------------------------------
    // Test 8 – Reject end_date before start_date
    // -----------------------------------------------------------------------

    public function test_reject_end_date_before_start_date(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson($this->baseUrl(), $this->validPeriodPayload([
            'start_date' => '2026-07-31',
            'end_date'   => '2026-02-01', // before start
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    // -----------------------------------------------------------------------
    // Test 9 – Reject invalid semester_type
    // -----------------------------------------------------------------------

    public function test_reject_invalid_semester_type(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson($this->baseUrl(), $this->validPeriodPayload([
            'semester_type' => 'invalid_type',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['semester_type']);
    }

    // -----------------------------------------------------------------------
    // Strict single-active-period semantics
    // -----------------------------------------------------------------------

    public function test_creating_active_period_deactivates_existing_active_period(): void
    {
        $admin = $this->superAdmin();
        $previouslyActive = $this->activePeriodRecord([
            'start_date' => '2026-01-01',
            'end_date'   => '2026-06-30',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson($this->baseUrl(), $this->validPeriodPayload([
            'is_active'  => true,
            'start_date' => '2026-08-01',
            'end_date'   => '2027-01-31',
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.is_active', true);

        $this->assertFalse((bool) AcademicPeriod::find($previouslyActive->id)->is_active,
            'Previously active period must be deactivated when a new active period is created.');
        $this->assertSame(1, AcademicPeriod::where('is_active', true)->count(),
            'At most one period may be active at a time.');
    }

    public function test_creating_inactive_period_does_not_touch_existing_active(): void
    {
        $admin = $this->superAdmin();
        $existingActive = $this->activePeriodRecord([
            'start_date' => '2026-01-01',
            'end_date'   => '2026-06-30',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson($this->baseUrl(), $this->validPeriodPayload([
            'is_active'  => false,
            'start_date' => '2026-08-01',
            'end_date'   => '2027-01-31',
        ]));

        $response->assertStatus(201);

        $this->assertTrue((bool) AcademicPeriod::find($existingActive->id)->is_active,
            'Inactive create must not affect the existing active period.');
        $this->assertSame(1, AcademicPeriod::where('is_active', true)->count());
    }

    public function test_updating_period_to_active_deactivates_previously_active_period(): void
    {
        $admin = $this->superAdmin();

        $previouslyActive = $this->activePeriodRecord([
            'academic_year' => '2024/2025',
            'year_start'    => 2024,
            'semester_type' => AcademicPeriod::SEMESTER_TYPE_GANJIL,
            'semester_order' => 1,
            'start_date'    => '2024-08-01',
            'end_date'      => '2025-01-31',
            'is_active'     => true,
        ]);

        $period = AcademicPeriod::create($this->validPeriodPayload([
            'is_active'  => false,
            'start_date' => '2026-02-01',
            'end_date'   => '2026-07-31',
        ]));

        Sanctum::actingAs($admin);

        $response = $this->putJson($this->baseUrl('/' . $period->id), [
            'is_active' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', true);

        $this->assertFalse((bool) AcademicPeriod::find($previouslyActive->id)->is_active);
        $this->assertSame(1, AcademicPeriod::where('is_active', true)->count());
    }

    public function test_updating_active_period_dates_keeps_it_active_without_self_deactivation(): void
    {
        $admin  = $this->superAdmin();
        $period = $this->activePeriodRecord([
            'start_date' => '2026-02-01',
            'end_date'   => '2026-07-31',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson($this->baseUrl('/' . $period->id), [
            'start_date' => '2026-02-15',
            'end_date'   => '2026-08-15',
        ]);

        $response->assertStatus(200);
        $this->assertTrue((bool) AcademicPeriod::find($period->id)->is_active,
            'Updating only dates on an active period must keep it active (self-exclusion in deactivate-others step).');
        $this->assertSame(1, AcademicPeriod::where('is_active', true)->count());
    }

    public function test_toggle_active_deactivates_previously_active_period(): void
    {
        $admin = $this->superAdmin();
        $previouslyActive = $this->activePeriodRecord([
            'start_date' => '2026-01-01',
            'end_date'   => '2026-06-30',
        ]);
        $candidate = AcademicPeriod::create($this->validPeriodPayload([
            'is_active'  => false,
            'start_date' => '2026-08-01',
            'end_date'   => '2027-01-31',
        ]));

        Sanctum::actingAs($admin);

        $this->patchJson($this->baseUrl('/' . $candidate->id . '/toggle-active'))
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', true);

        $this->assertFalse((bool) AcademicPeriod::find($previouslyActive->id)->is_active);
        $this->assertSame(1, AcademicPeriod::where('is_active', true)->count());
    }

    public function test_toggle_inactive_does_not_affect_other_periods(): void
    {
        $admin = $this->superAdmin();
        $period = $this->activePeriodRecord();
        $other  = AcademicPeriod::create($this->validPeriodPayload([
            'is_active'  => false,
            'start_date' => '2026-08-01',
            'end_date'   => '2027-01-31',
        ]));

        Sanctum::actingAs($admin);

        $this->patchJson($this->baseUrl('/' . $period->id . '/toggle-active'))
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse((bool) AcademicPeriod::find($other->id)->is_active);
        $this->assertSame(0, AcademicPeriod::where('is_active', true)->count(),
            'Deactivating the only active period leaves zero active periods.');
    }

    public function test_at_most_one_period_is_active_after_create_update_toggle_sequence(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $a = $this->postJson($this->baseUrl(), $this->validPeriodPayload([
            'is_active'  => true,
            'start_date' => '2026-01-01',
            'end_date'   => '2026-06-30',
        ]))->assertStatus(201)->json('data.id');

        $b = $this->postJson($this->baseUrl(), $this->validPeriodPayload([
            'is_active'  => true,
            'start_date' => '2026-08-01',
            'end_date'   => '2027-01-31',
        ]))->assertStatus(201)->json('data.id');

        $this->putJson($this->baseUrl('/' . $a), ['is_active' => true])->assertStatus(200);

        $this->patchJson($this->baseUrl('/' . $b . '/toggle-active'))->assertStatus(200);

        $this->assertSame(1, AcademicPeriod::where('is_active', true)->count(),
            'Sequence of create-active → create-active → update-active → toggle must end with exactly one active.');
    }

    // -----------------------------------------------------------------------
    // currentAcademicPeriod() date-range semantics
    // -----------------------------------------------------------------------

    public function test_current_academic_period_returns_active_when_today_is_in_range(): void
    {
        $this->activePeriodRecord([
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date'   => now()->addDays(10)->toDateString(),
        ]);

        $resolved = app(AcademicContextService::class)->currentAcademicPeriod();

        $this->assertNotNull($resolved);
        $this->assertTrue((bool) $resolved->is_active);
    }

    public function test_current_academic_period_returns_null_when_only_active_is_outside_date_range(): void
    {
        $this->activePeriodRecord([
            'start_date' => '2020-01-01',
            'end_date'   => '2020-06-30',
        ]);

        $resolved = app(AcademicContextService::class)->currentAcademicPeriod();

        $this->assertNull($resolved,
            'currentAcademicPeriod() must return null when the active period is outside today\'s date range.');
    }

    public function test_current_academic_period_returns_null_when_no_active_period_exists(): void
    {
        AcademicPeriod::create($this->validPeriodPayload(['is_active' => false]));

        $resolved = app(AcademicContextService::class)->currentAcademicPeriod();

        $this->assertNull($resolved);
    }

    public function test_semester_calculation_angkatan_2024_ganjil_returns_3(): void
    {
        $this->activePeriodRecord([
            'academic_year'  => '2025/2026',
            'year_start'     => 2025,
            'semester_type'  => AcademicPeriod::SEMESTER_TYPE_GANJIL,
            'semester_order' => 1,
            'start_date'     => now()->subMonth()->toDateString(),
            'end_date'       => now()->addMonth()->toDateString(),
        ]);

        [$student] = $this->completeMahasiswa([], [
            'nim' => '24/123456/SV/00001',
        ]);

        $this->assertSame(3, app(AcademicContextService::class)->studentCurrentSemester($student));
    }

    public function test_semester_calculation_angkatan_2024_genap_returns_4(): void
    {
        $this->activePeriodRecord([
            'academic_year'  => '2025/2026',
            'year_start'     => 2025,
            'semester_type'  => AcademicPeriod::SEMESTER_TYPE_GENAP,
            'semester_order' => 2,
            'start_date'     => now()->subMonth()->toDateString(),
            'end_date'       => now()->addMonth()->toDateString(),
        ]);

        [$student] = $this->completeMahasiswa([], [
            'nim' => '24/123456/SV/00001',
        ]);

        $this->assertSame(4, app(AcademicContextService::class)->studentCurrentSemester($student));
    }

    // -----------------------------------------------------------------------
    // Test 14 – AcademicContextService resolves created period
    // -----------------------------------------------------------------------

    public function test_academic_context_service_resolves_created_period(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson($this->baseUrl(), $this->validPeriodPayload([
            'is_active'  => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date'   => now()->addMonth()->toDateString(),
        ]));

        $response->assertStatus(201);

        $service = app(AcademicContextService::class);
        $resolved = $service->currentAcademicPeriod();

        $this->assertNotNull($resolved);
        $this->assertSame('2025/2026', $resolved->academic_year);
        $this->assertSame(AcademicPeriod::SEMESTER_TYPE_GENAP, $resolved->semester_type);
    }

    // -----------------------------------------------------------------------
    // Test 15 – Existing AcademicContextService tests still pass
    // -----------------------------------------------------------------------

    public function test_existing_academic_context_service_behavior_is_unaffected(): void
    {
        // Create an active period via API, then verify the service resolves it
        // and that student semester calculation still works.
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->postJson($this->baseUrl(), $this->validPeriodPayload([
            'is_active'  => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date'   => now()->addMonth()->toDateString(),
        ]))->assertStatus(201);

        [$student] = $this->completeMahasiswa([], [
            'nim' => '22/493038/SV/20654',
        ]);

        $service = app(AcademicContextService::class);
        $context = $service->studentAcademicContext($student);

        $this->assertSame('2025/2026', $context['current_academic_year']);
        $this->assertSame(AcademicPeriod::SEMESTER_TYPE_GENAP, $context['current_semester_type']);
        $this->assertSame(8, $context['current_semester']);
    }
}
