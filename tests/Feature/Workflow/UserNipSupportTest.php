<?php

namespace Tests\Feature\Workflow;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserNipSupportTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_user_model_and_super_admin_create_update_support_nip(): void
    {
        $admin = $this->primarySuperAdmin();
        $department = $this->department([
            'code' => 'DNIP',
            'name' => 'Departemen NIP',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/super-admin/users', [
                'name' => 'Kadep NIP Test',
                'email' => 'kadep.nip@example.test',
                'password' => 'password123',
                'role' => 'akademik',
                'sub_role' => 'kadep',
                'department_id' => $department->id,
                'nip' => '198001012006041001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.nip', '198001012006041001');

        $user = User::where('email', 'kadep.nip@example.test')->firstOrFail();
        $this->assertSame('198001012006041001', $user->nip);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/super-admin/users/{$user->id}", [
                'nip' => '197512122005011002',
            ])
            ->assertOk()
            ->assertJsonPath('data.nip', '197512122005011002');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'nip' => '197512122005011002',
        ]);
    }

    public function test_super_admin_nip_is_nullable_and_unique_when_supplied(): void
    {
        $admin = $this->primarySuperAdmin();
        $first = $this->akademik('kadep', ['nip' => '198001012006041001']);
        $second = $this->akademik('sekdep');

        $this->assertSame('198001012006041001', $first->fresh()->nip);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/super-admin/users/{$second->id}", [
                'nip' => '198001012006041001',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nip');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/super-admin/users/{$second->id}", [
                'nip' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.nip', null);
    }
}
