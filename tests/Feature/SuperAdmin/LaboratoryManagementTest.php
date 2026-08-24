<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\RoomType;
use App\Models\Department;
use App\Models\Laboratory;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

class LaboratoryManagementTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private function superAdmin(): User
    {
        return $this->activeUser(['role' => 'super_admin', 'role_level' => 'primary']);
    }

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs($this->superAdmin());
    }

    private function laboratory(array $attributes = []): Laboratory
    {
        return Laboratory::create(array_merge([
            'name' => 'Lab ' . str()->random(6),
            'code' => 'LAB-' . str()->upper(str()->random(6)),
            'department_id' => $this->department()->id,
        ], $attributes));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_lists_laboratories_with_department_and_counts(): void
    {
        $this->actingAsSuperAdmin();
        $this->laboratory(['name' => 'Lab Jaringan']);

        $resp = $this->getJson('/api/super-admin/laboratories');

        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('department', $data[0]);
        $this->assertArrayHasKey('users_count', $data[0]);
        $this->assertArrayHasKey('rooms_count', $data[0]);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_laboratory(): void
    {
        $this->actingAsSuperAdmin();
        $department = $this->department();

        $resp = $this->postJson('/api/super-admin/laboratories', [
            'name' => 'Lab Elektronika',
            'code' => 'LAB-ELEK',
            'department_id' => $department->id,
        ]);

        $resp->assertCreated();
        $this->assertDatabaseHas('laboratories', [
            'name' => 'Lab Elektronika',
            'code' => 'LAB-ELEK',
            'department_id' => $department->id,
        ]);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $this->actingAsSuperAdmin();
        $this->laboratory(['code' => 'LAB-DUP']);

        $resp = $this->postJson('/api/super-admin/laboratories', [
            'name' => 'Lab Lain',
            'code' => 'LAB-DUP',
            'department_id' => $this->department()->id,
        ]);

        $resp->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_store_rejects_nonexistent_department(): void
    {
        $this->actingAsSuperAdmin();

        $resp = $this->postJson('/api/super-admin/laboratories', [
            'name' => 'Lab Lain',
            'code' => 'LAB-XX',
            'department_id' => 999999,
        ]);

        $resp->assertUnprocessable()->assertJsonValidationErrors('department_id');
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_renames_and_reassigns_department(): void
    {
        $this->actingAsSuperAdmin();
        $lab = $this->laboratory();
        $newDepartment = $this->department();

        $resp = $this->patchJson("/api/super-admin/laboratories/{$lab->id}", [
            'name' => 'Lab Baru',
            'code' => $lab->code,
            'department_id' => $newDepartment->id,
        ]);

        $resp->assertOk();
        $this->assertDatabaseHas('laboratories', [
            'id' => $lab->id,
            'name' => 'Lab Baru',
            'department_id' => $newDepartment->id,
        ]);
    }

    public function test_update_allows_keeping_own_code(): void
    {
        $this->actingAsSuperAdmin();
        $lab = $this->laboratory(['code' => 'LAB-SELF']);

        $resp = $this->patchJson("/api/super-admin/laboratories/{$lab->id}", [
            'name' => 'Lab Self Renamed',
            'code' => 'LAB-SELF',
            'department_id' => $lab->department_id,
        ]);

        $resp->assertOk();
    }

    public function test_update_rejects_code_already_used_by_another_laboratory(): void
    {
        $this->actingAsSuperAdmin();
        $this->laboratory(['code' => 'LAB-TAKEN']);
        $lab = $this->laboratory(['code' => 'LAB-FREE']);

        $resp = $this->patchJson("/api/super-admin/laboratories/{$lab->id}", [
            'name' => $lab->name,
            'code' => 'LAB-TAKEN',
            'department_id' => $lab->department_id,
        ]);

        $resp->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_unused_laboratory(): void
    {
        $this->actingAsSuperAdmin();
        $lab = $this->laboratory();

        $resp = $this->deleteJson("/api/super-admin/laboratories/{$lab->id}");

        $resp->assertOk();
        $this->assertDatabaseMissing('laboratories', ['id' => $lab->id]);
    }

    public function test_destroy_blocked_when_laboratory_has_assigned_users(): void
    {
        $this->actingAsSuperAdmin();
        $lab = $this->laboratory();
        $this->activeUser(['role' => 'tendik', 'laboratory_id' => $lab->id]);

        $resp = $this->deleteJson("/api/super-admin/laboratories/{$lab->id}");

        $resp->assertStatus(409)->assertJsonPath('code', 'laboratory_in_use');
        $this->assertDatabaseHas('laboratories', ['id' => $lab->id]);
    }

    public function test_destroy_blocked_when_laboratory_owns_rooms(): void
    {
        $this->actingAsSuperAdmin();
        $lab = $this->laboratory();
        Room::create([
            'code' => 'ROOM-' . str()->upper(str()->random(6)),
            'name' => 'Test Lab Room',
            'type' => RoomType::Laboratory,
            'capacity' => 20,
            'location' => 'Test Building',
            'owning_laboratory_id' => $lab->id,
        ]);

        $resp = $this->deleteJson("/api/super-admin/laboratories/{$lab->id}");

        $resp->assertStatus(409)->assertJsonPath('code', 'laboratory_in_use');
        $this->assertDatabaseHas('laboratories', ['id' => $lab->id]);
    }

    // ── access control ───────────────────────────────────────────────────────

    public function test_non_superadmin_cannot_manage_laboratories(): void
    {
        Sanctum::actingAs($this->activeUser(['role' => 'tendik']));

        $this->getJson('/api/super-admin/laboratories')->assertForbidden();
        $this->postJson('/api/super-admin/laboratories', [])->assertForbidden();
    }
}
