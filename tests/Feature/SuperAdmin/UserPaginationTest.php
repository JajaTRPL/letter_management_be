<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

class UserPaginationTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private function superAdmin(): User
    {
        return $this->activeUser([
            'role'       => 'super_admin',
            'role_level' => 'primary',
        ]);
    }

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs($this->superAdmin());
    }

    // ── Response shape ────────────────────────────────────────────────────────

    public function test_index_returns_paginated_shape(): void
    {
        $this->actingAsSuperAdmin();

        $resp = $this->getJson('/api/super-admin/users?role=super_admin');

        $resp->assertOk()
             ->assertJsonStructure([
                 'message',
                 'data',
                 'meta' => ['current_page', 'per_page', 'total', 'last_page'],
             ]);
    }

    // ── Default per_page ──────────────────────────────────────────────────────

    public function test_default_per_page_is_25(): void
    {
        $this->actingAsSuperAdmin();

        $resp = $this->getJson('/api/super-admin/users?role=super_admin');

        $resp->assertOk()
             ->assertJsonPath('meta.per_page', 25);
    }

    // ── Per-page cap ──────────────────────────────────────────────────────────

    public function test_per_page_capped_at_100(): void
    {
        $this->actingAsSuperAdmin();

        $resp = $this->getJson('/api/super-admin/users?role=super_admin&per_page=999');

        $resp->assertOk()
             ->assertJsonPath('meta.per_page', 100);
    }

    // ── Role filter ───────────────────────────────────────────────────────────

    public function test_role_filter_returns_only_matching_role(): void
    {
        $this->actingAsSuperAdmin();
        $this->activeUser(['role' => 'tendik']);

        $resp = $this->getJson('/api/super-admin/users?role=super_admin');

        $resp->assertOk();
        $data = $resp->json('data');
        foreach ($data as $user) {
            $this->assertSame('super_admin', $user['role']);
        }
    }

    // ── Name search ───────────────────────────────────────────────────────────

    public function test_search_filters_by_name(): void
    {
        $this->actingAsSuperAdmin();
        $this->activeUser(['role' => 'tendik', 'name' => 'Budi Santoso']);
        $this->activeUser(['role' => 'tendik', 'name' => 'Andi Wijaya']);

        $resp = $this->getJson('/api/super-admin/users?role=tendik&search=budi');

        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertCount(1, $data);
        $this->assertStringContainsStringIgnoringCase('budi', $data[0]['name']);
    }

    // ── Email search ──────────────────────────────────────────────────────────

    public function test_search_filters_by_email(): void
    {
        $this->actingAsSuperAdmin();
        $this->activeUser(['role' => 'tendik', 'email' => 'zeta@ugm.ac.id']);
        $this->activeUser(['role' => 'tendik', 'email' => 'alpha@ugm.ac.id']);

        $resp = $this->getJson('/api/super-admin/users?role=tendik&search=zeta');

        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertCount(1, $data);
        $this->assertStringContainsStringIgnoringCase('zeta', $data[0]['email']);
    }

    // ── NIP search ────────────────────────────────────────────────────────────

    public function test_search_filters_by_nip(): void
    {
        $this->actingAsSuperAdmin();
        $this->activeUser(['role' => 'tendik', 'nip' => '199001012020031001']);
        $this->activeUser(['role' => 'tendik', 'nip' => '198501012019031002']);

        $resp = $this->getJson('/api/super-admin/users?role=tendik&search=199001');

        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertCount(1, $data);
        $this->assertStringContainsString('199001', $data[0]['nip']);
    }

    // ── Non-superadmin forbidden ──────────────────────────────────────────────

    public function test_non_superadmin_cannot_access_user_list(): void
    {
        Sanctum::actingAs($this->activeUser(['role' => 'tendik']));

        $this->getJson('/api/super-admin/users')->assertForbidden();
    }

    // ── Akademik users include study_program relation ─────────────────────────

    public function test_akademik_users_include_study_program_data(): void
    {
        $this->actingAsSuperAdmin();
        $this->activeUser(['role' => 'akademik']);

        $resp = $this->getJson('/api/super-admin/users?role=akademik');

        $resp->assertOk();
        $data = $resp->json('data');
        foreach ($data as $user) {
            // study_program key must be present (nullable is fine)
            $this->assertArrayHasKey('study_program', $user);
        }
    }

    // ── Pagination page navigation ────────────────────────────────────────────

    public function test_page_2_returns_correct_current_page_in_meta(): void
    {
        $this->actingAsSuperAdmin();

        $resp = $this->getJson('/api/super-admin/users?role=super_admin&page=2&per_page=1');

        $resp->assertOk()
             ->assertJsonPath('meta.current_page', 2)
             ->assertJsonPath('meta.per_page', 1);
    }
}
