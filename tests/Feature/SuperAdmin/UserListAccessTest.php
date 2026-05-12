<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

class UserListAccessTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function superAdmin(): User
    {
        return $this->activeUser([
            'role'       => 'super_admin',
            'role_level' => 'primary',
        ]);
    }

    private function tendik(): User
    {
        return $this->activeUser([
            'role'       => 'tendik',
            'tendik_role' => 'persuratan',
        ]);
    }

    private function akademik(): User
    {
        return $this->activeUser([
            'role'     => 'akademik',
            'sub_role' => 'kaprodi',
        ]);
    }

    // ── GET /api/users — removed, must return 404 for all roles ──────────────

    public function test_general_users_endpoint_returns_404_for_mahasiswa(): void
    {
        [$mahasiswa] = $this->completeMahasiswa();
        Sanctum::actingAs($mahasiswa);

        $this->getJson('/api/users')->assertStatus(404);
    }

    public function test_general_users_endpoint_returns_404_for_tendik(): void
    {
        Sanctum::actingAs($this->tendik());

        $this->getJson('/api/users')->assertStatus(404);
    }

    public function test_general_users_endpoint_returns_404_for_akademik(): void
    {
        Sanctum::actingAs($this->akademik());

        $this->getJson('/api/users')->assertStatus(404);
    }

    public function test_general_users_endpoint_returns_404_for_super_admin(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/users')->assertStatus(404);
    }

    // ── GET /api/super-admin/users — Super Admin only ────────────────────────

    public function test_super_admin_users_endpoint_accessible_by_super_admin(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/super-admin/users')->assertOk();
    }

    public function test_super_admin_users_endpoint_forbidden_for_mahasiswa(): void
    {
        [$mahasiswa] = $this->completeMahasiswa();
        Sanctum::actingAs($mahasiswa);

        $this->getJson('/api/super-admin/users')->assertStatus(403);
    }

    public function test_super_admin_users_endpoint_forbidden_for_tendik(): void
    {
        Sanctum::actingAs($this->tendik());

        $this->getJson('/api/super-admin/users')->assertStatus(403);
    }

    public function test_super_admin_users_endpoint_forbidden_for_akademik(): void
    {
        Sanctum::actingAs($this->akademik());

        $this->getJson('/api/super-admin/users')->assertStatus(403);
    }
}
