<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * Kadep/Sekdep per department and Kaprodi/Sekprodi per study program may only
 * have one non-suspended holder, because rendering and routing pick one.
 */
class AcademicPositionUniquenessTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->activeUser(['role' => 'super_admin', 'role_level' => 'primary']));
    }

    private function storePayload(string $subRole, array $scope, string $email): array
    {
        return array_merge([
            'name' => 'Pejabat Baru',
            'email' => $email,
            'password' => 'Rahasia-Kuat-123',
            'role' => 'akademik',
            'sub_role' => $subRole,
        ], $scope);
    }

    public function test_second_kadep_and_sekdep_in_same_department_are_rejected(): void
    {
        $program = $this->studyProgram();
        foreach (['kadep', 'sekdep'] as $subRole) {
            $this->activeUser([
                'role' => 'akademik',
                'sub_role' => $subRole,
                'department_id' => $program->department_id,
            ]);

            $this->postJson('/api/super-admin/users', $this->storePayload(
                $subRole,
                ['department_id' => $program->department_id],
                "{$subRole}.kedua@ugm.ac.id",
            ))
                ->assertStatus(422)
                ->assertJsonPath('code', 'ACADEMIC_POSITION_TAKEN');

            $this->assertDatabaseMissing('users', ['email' => "{$subRole}.kedua@ugm.ac.id"]);
        }
    }

    public function test_second_kaprodi_and_sekprodi_in_same_program_are_rejected(): void
    {
        $program = $this->studyProgram();
        foreach (['kaprodi', 'sekprodi'] as $subRole) {
            $this->activeUser([
                'role' => 'akademik',
                'sub_role' => $subRole,
                'study_program_id' => $program->id,
                'department_id' => $program->department_id,
            ]);

            $this->postJson('/api/super-admin/users', $this->storePayload(
                $subRole,
                ['study_program_id' => $program->id],
                "{$subRole}.kedua@ugm.ac.id",
            ))
                ->assertStatus(422)
                ->assertJsonPath('code', 'ACADEMIC_POSITION_TAKEN');
        }
    }

    public function test_position_is_free_in_other_scope_or_after_old_holder_is_suspended(): void
    {
        $program = $this->studyProgram();
        $otherProgram = $this->studyProgram();
        $old = $this->activeUser([
            'role' => 'akademik',
            'sub_role' => 'kaprodi',
            'study_program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);

        $this->postJson('/api/super-admin/users', $this->storePayload(
            'kaprodi',
            ['study_program_id' => $otherProgram->id],
            'kaprodi.lain@ugm.ac.id',
        ))->assertCreated();

        $old->update(['status' => UserStatus::Suspended]);

        $this->postJson('/api/super-admin/users', $this->storePayload(
            'kaprodi',
            ['study_program_id' => $program->id],
            'kaprodi.pengganti@ugm.ac.id',
        ))->assertCreated();
    }

    public function test_update_into_a_taken_position_is_rejected_but_unrelated_edits_pass(): void
    {
        $program = $this->studyProgram();
        $this->activeUser([
            'role' => 'akademik',
            'sub_role' => 'kadep',
            'department_id' => $program->department_id,
        ]);
        $sekdep = $this->activeUser([
            'role' => 'akademik',
            'sub_role' => 'sekdep',
            'department_id' => $program->department_id,
        ]);

        $this->putJson("/api/super-admin/users/{$sekdep->id}", ['sub_role' => 'kadep'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACADEMIC_POSITION_TAKEN');
        $this->assertSame('sekdep', $sekdep->fresh()->sub_role);

        $this->putJson("/api/super-admin/users/{$sekdep->id}", ['name' => 'Nama Diperbarui'])
            ->assertOk();
    }

    public function test_unsuspending_a_second_holder_is_rejected(): void
    {
        $program = $this->studyProgram();
        $old = $this->activeUser([
            'role' => 'akademik',
            'sub_role' => 'kadep',
            'department_id' => $program->department_id,
        ]);
        $old->update(['status' => UserStatus::Suspended]);
        $this->activeUser([
            'role' => 'akademik',
            'sub_role' => 'kadep',
            'department_id' => $program->department_id,
        ]);

        $this->patchJson("/api/super-admin/users/{$old->id}/unblock")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACADEMIC_POSITION_TAKEN');
        $this->assertSame(UserStatus::Suspended, $old->fresh()->status);

        $this->putJson("/api/super-admin/users/{$old->id}", ['status' => UserStatus::Active->value])
            ->assertStatus(422);
    }
}
