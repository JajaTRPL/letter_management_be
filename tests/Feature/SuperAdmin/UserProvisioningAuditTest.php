<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * Provisioning auditability (Scope E) + email normalization (Scope D), on the
 * existing canonical ActivityLog architecture — no parallel audit system.
 */
class UserProvisioningAuditTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private function superAdmin(): User
    {
        return $this->activeUser(['role' => 'super_admin', 'role_level' => 'primary']);
    }

    public function test_store_normalizes_email_to_lowercase_and_is_attributed(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/super-admin/users', [
            'name' => 'Budi Santoso',
            'email' => 'Budi.Santoso@ugm.ac.id',
            'password' => 'Rahasia-Kuat-123',
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
            'nip' => '198001012006041010',
        ])->assertCreated();

        // Stored lowercase so the case-insensitive Google/password login can match it.
        $this->assertDatabaseHas('users', ['email' => 'budi.santoso@ugm.ac.id']);
        $this->assertDatabaseMissing('users', ['email' => 'Budi.Santoso@ugm.ac.id']);

        $log = ActivityLog::where('action', 'Tambah User')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);            // who
        $this->assertSame('budi.santoso@ugm.ac.id', $log->target_user);
        $this->assertNotNull($log->created_at);                  // when
    }

    public function test_update_audit_records_email_subrole_scope_and_status_changes_old_to_new(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $program = $this->studyProgram();
        $newProgram = $this->studyProgram();
        $target = $this->activeUser([
            'email' => 'lama.staff@ugm.ac.id',
            'role' => 'akademik',
            'sub_role' => 'kaprodi',
            'study_program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);

        $this->putJson("/api/super-admin/users/{$target->id}", [
            'email' => 'Baru.Staff@ugm.ac.id',
            'sub_role' => 'sekprodi',
            'study_program_id' => $newProgram->id,
            'status' => UserStatus::Suspended->value,
        ])->assertOk();

        $target->refresh();
        $this->assertSame('baru.staff@ugm.ac.id', $target->email);

        $log = ActivityLog::where('action', 'Update User')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringContainsString('email dari lama.staff@ugm.ac.id ke baru.staff@ugm.ac.id', $log->details);
        $this->assertStringContainsString('sub-role dari kaprodi ke sekprodi', $log->details);
        $this->assertStringContainsString('program studi dari '.$program->id.' ke '.$newProgram->id, $log->details);
        $this->assertStringContainsString('status dari Active ke Suspended', $log->details);
    }

    public function test_update_without_provisioning_changes_keeps_a_clean_audit_line(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);

        $target = $this->activeUser([
            'email' => 'stable.staff@ugm.ac.id',
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
        ]);

        $this->putJson("/api/super-admin/users/{$target->id}", [
            'name' => 'Nama Diperbarui',
        ])->assertOk();

        $log = ActivityLog::where('action', 'Update User')->latest('id')->first();
        $this->assertSame('Update data user.', $log->details);
    }
}
