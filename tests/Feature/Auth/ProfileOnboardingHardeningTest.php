<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\MahasiswaProfile;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileOnboardingHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_academic_options_hide_proof_rows_and_keep_real_rows(): void
    {
        $realDepartment = $this->department('DTEDI', 'Departemen Teknik Elektro dan Informatika');
        $realProgram = $this->program($realDepartment, 'TRPL', 'Teknologi Rekayasa Perangkat Lunak');
        $proofDepartment = $this->department('P2C1D202605211725528194', 'Proof Department Phase 2C1');
        $this->program($proofDepartment, 'P2C1P202605211725528194', 'Proof Study Program Phase 2C1');
        $emptyProofDepartment = $this->department('PDQJMSZS85', 'Proof Department QJMSZS85');
        $codedProofDepartment = $this->department(
            'P2C3D202605211746078638',
            'Temporary Department'
        );
        $codedProofProgram = $this->program(
            $realDepartment,
            'P2C3P202605211746078638',
            'Temporary Study Program'
        );

        Sanctum::actingAs($this->user([
            'role' => 'super_admin',
            'role_level' => 'primary',
        ]));

        $grouped = $this->getJson('/api/study-programs-grouped')
            ->assertOk()
            ->json();

        $encoded = json_encode($grouped);
        $this->assertStringContainsString($realDepartment->code, $encoded);
        $this->assertStringContainsString($realProgram->code, $encoded);
        $this->assertStringNotContainsString($proofDepartment->code, $encoded);
        $this->assertStringNotContainsString($emptyProofDepartment->code, $encoded);
        $this->assertStringNotContainsString($codedProofDepartment->code, $encoded);
        $this->assertStringNotContainsString($codedProofProgram->code, $encoded);

        $departments = $this->getJson('/api/departments')->assertOk()->json();
        $departmentCodes = array_column($departments, 'code');
        $this->assertContains($realDepartment->code, $departmentCodes);
        $this->assertNotContains($proofDepartment->code, $departmentCodes);
        $this->assertNotContains($emptyProofDepartment->code, $departmentCodes);
        $this->assertNotContains($codedProofDepartment->code, $departmentCodes);
    }

    public function test_mahasiswa_completion_requires_valid_unique_nim_and_visible_program(): void
    {
        $realDepartment = $this->department();
        $realProgram = $this->program($realDepartment);
        $proofDepartment = $this->department('P2C2D202605211736396859', 'Proof Department Phase 2C2');
        $proofProgram = $this->program(
            $proofDepartment,
            'P2C2P202605211736396859',
            'Proof Study Program Phase 2C2'
        );
        $codedProofProgram = $this->program(
            $realDepartment,
            'P2C3P202605211746078638',
            'Temporary Study Program'
        );
        $existing = $this->user(['email' => 'existing@student.test']);
        MahasiswaProfile::create([
            'user_id' => $existing->id,
            'nim' => '23/123456/SV/10001',
        ]);

        $student = $this->user([
            'email' => 'pending@student.test',
            'status' => UserStatus::PendingProfile,
        ]);
        MahasiswaProfile::create(['user_id' => $student->id, 'data_source' => 'google_sync']);
        Sanctum::actingAs($student);

        $this->getJson('/api/surat-types')
            ->assertForbidden()
            ->assertJsonPath('requires_completion', true);

        $this->postJson('/api/auth/complete-profile', [
            'nim' => 'invalid',
            'study_program_id' => $realProgram->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('nim');

        $this->postJson('/api/auth/complete-profile', [
            'nim' => '24/535278/SV/12345',
            'study_program_id' => $proofProgram->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('study_program_id');

        $this->postJson('/api/auth/complete-profile', [
            'nim' => '24/535278/SV/12345',
            'study_program_id' => $codedProofProgram->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('study_program_id');

        $this->postJson('/api/auth/complete-profile', [
            'nim' => ' 23/123456/sv/10001 ',
            'study_program_id' => $realProgram->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('nim');

        $this->postJson('/api/auth/complete-profile', [
            'nim' => '24/535278/SV/12345',
            'study_program_id' => $realProgram->id,
        ])
            ->assertOk()
            ->assertJsonPath('completion.needs_completion', false)
            ->assertJsonPath('user.role', 'mahasiswa');

        $student->refresh();
        $this->assertSame(UserStatus::Active, $student->status);
        $this->assertSame($realProgram->id, $student->study_program_id);
        $this->assertSame('24/535278/SV/12345', $student->mahasiswaProfile->nim);
    }

    public function test_completion_rejects_role_and_scope_tampering(): void
    {
        $program = $this->program($this->department());
        $student = $this->user(['status' => UserStatus::PendingProfile]);
        MahasiswaProfile::create(['user_id' => $student->id]);
        Sanctum::actingAs($student);

        $this->postJson('/api/auth/complete-profile', [
            'nim' => '24/535278/SV/12345',
            'study_program_id' => $program->id,
            'role' => 'super_admin',
            'sub_role' => 'kaprodi',
            'tendik_role' => 'kepala_lab',
            'laboratory_id' => 1,
            'department_id' => $program->department_id,
            'role_level' => 'primary',
            'status' => UserStatus::Active->value,
            'assigned_tasks' => ['beasiswa'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'role',
                'sub_role',
                'tendik_role',
                'laboratory_id',
                'department_id',
                'role_level',
                'status',
                'assigned_tasks',
            ]);

        $this->assertSame('mahasiswa', $student->fresh()->role);
    }

    public function test_tendik_is_gated_until_unique_nip_is_completed(): void
    {
        $existing = $this->user([
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
            'nip' => '199001012025011001',
        ]);
        $tendik = $this->user([
            'email' => 'pending.tendik@ugm.ac.id',
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
            'nip' => null,
            'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($tendik);

        $this->getJson('/api/auth/profile-completion')
            ->assertOk()
            ->assertJsonPath('completion.needs_completion', true)
            ->assertJsonPath('completion.fields.0', 'nip');

        $this->getJson('/api/surat-types')->assertForbidden();

        $this->postJson('/api/auth/complete-profile', [
            'nip' => ' ' . $existing->nip . ' ',
        ])->assertUnprocessable()->assertJsonValidationErrors('nip');

        $this->postJson('/api/auth/complete-profile', [
            'nip' => '198501012019031002',
        ])
            ->assertOk()
            ->assertJsonPath('completion.needs_completion', false)
            ->assertJsonPath('user.role', 'tendik');

        $tendik->refresh();
        $this->assertSame('198501012019031002', $tendik->nip);
        $this->assertSame(UserStatus::Active, $tendik->status);
        $this->getJson('/api/surat-types')->assertOk();
    }

    public function test_akademik_completion_accepts_only_nip_when_scope_is_preprovisioned(): void
    {
        $department = $this->department();
        $program = $this->program($department);

        $kaprodi = $this->user([
            'email' => 'kaprodi.pending@ugm.ac.id',
            'role' => 'akademik',
            'sub_role' => 'kaprodi',
            'nip' => null,
            'study_program_id' => $program->id,
            'department_id' => $department->id,
        ]);
        Sanctum::actingAs($kaprodi);

        $this->getJson('/api/auth/profile-completion')
            ->assertOk()
            ->assertJsonPath('completion.fields.0', 'nip')
            ->assertJsonCount(1, 'completion.fields')
            ->assertJsonPath('completion.can_self_complete', true);

        $this->postJson('/api/auth/complete-profile', [
            'nip' => '198001012006041001',
            'study_program_id' => $program->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('study_program_id');

        $this->postJson('/api/auth/complete-profile', [
            'nip' => '198001012006041001',
        ])->assertOk();

        $kaprodi->refresh();
        $this->assertSame($program->id, $kaprodi->study_program_id);
        $this->assertSame($department->id, $kaprodi->department_id);
        $this->assertSame('198001012006041001', $kaprodi->nip);

        $kadep = $this->user([
            'email' => 'kadep.pending@ugm.ac.id',
            'role' => 'akademik',
            'sub_role' => 'kadep',
            'nip' => null,
            'study_program_id' => null,
            'department_id' => $department->id,
        ]);
        Sanctum::actingAs($kadep);

        $this->getJson('/api/auth/profile-completion')
            ->assertOk()
            ->assertJsonPath('completion.fields.0', 'nip')
            ->assertJsonCount(1, 'completion.fields')
            ->assertJsonPath('completion.can_self_complete', true);

        $this->postJson('/api/auth/complete-profile', [
            'nip' => '197512122005011002',
        ])->assertOk();

        $kadep->refresh();
        $this->assertSame($department->id, $kadep->department_id);
        $this->assertNull($kadep->study_program_id);
        $this->assertSame('197512122005011002', $kadep->nip);
    }

    public function test_missing_admin_managed_staff_scope_cannot_be_self_assigned(): void
    {
        $laboratoryTendik = $this->user([
            'role' => 'tendik',
            'tendik_role' => 'laboran',
            'laboratory_id' => null,
            'nip' => null,
        ]);
        Sanctum::actingAs($laboratoryTendik);

        $this->getJson('/api/auth/profile-completion')
            ->assertOk()
            ->assertJsonPath('completion.needs_completion', true)
            ->assertJsonPath('completion.can_self_complete', false);

        $this->postJson('/api/auth/complete-profile', [
            'nip' => '198501012019031002',
            'laboratory_id' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('completion.can_self_complete', false);

        $akademik = $this->user([
            'role' => 'akademik',
            'sub_role' => 'kaprodi',
            'study_program_id' => null,
            'department_id' => null,
            'nip' => null,
        ]);
        Sanctum::actingAs($akademik);

        $this->getJson('/api/auth/profile-completion')
            ->assertOk()
            ->assertJsonPath('completion.can_self_complete', false)
            ->assertJsonPath('completion.fields.0', 'nip')
            ->assertJsonCount(1, 'completion.fields')
            ->assertJsonPath('completion.missing_fields.1', 'Program Studi')
            ->assertJsonPath('completion.message', 'Program Studi harus ditetapkan oleh Super Admin.');

        $this->postJson('/api/auth/complete-profile', [
            'nip' => '198001012006041001',
            'study_program_id' => $this->program($this->department('DOTHER', 'Departemen Lain'))->id,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('completion.can_self_complete', false);

        $this->assertNull($akademik->fresh()->nip);
        $this->assertNull($akademik->fresh()->study_program_id);
    }

    public function test_unknown_student_domain_can_self_onboard_only_as_mahasiswa(): void
    {
        config(['services.google.client_id' => 'test-client-id']);
        $this->fakeGoogleTokens([
            'student-token' => [
                'email' => 'new.student@mail.ugm.ac.id',
                'sub' => 'google-new-student',
                'role' => 'super_admin',
            ],
        ]);

        $this->postJson('/api/auth/google', ['credential' => 'student-token'])
            ->assertOk()
            ->assertJsonPath('user.role', 'mahasiswa')
            ->assertJsonPath('needs_completion', true);

        $this->assertDatabaseHas('users', [
            'email' => 'new.student@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'status' => UserStatus::PendingProfile->value,
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => 'new.student@mail.ugm.ac.id',
            'role' => 'super_admin',
        ]);

        $student = User::where('email', 'new.student@mail.ugm.ac.id')->firstOrFail();
        $this->assertNull($student->password);

        $this->postJson('/api/login', [
            'email' => $student->email,
            'password' => '24535278SV1234504052004',
        ])->assertUnauthorized();
    }

    public function test_unknown_staff_domain_cannot_self_register_as_mahasiswa(): void
    {
        config(['services.google.client_id' => 'test-client-id']);
        $this->fakeGoogleTokens([
            'unknown-staff-token' => [
                'email' => 'unknown.staff@ugm.ac.id',
                'sub' => 'google-unknown-staff',
            ],
        ]);

        $this->postJson('/api/auth/google', ['credential' => 'unknown-staff-token'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun belum terdaftar. Silakan hubungi Super Admin.');

        $this->assertDatabaseMissing('users', [
            'email' => 'unknown.staff@ugm.ac.id',
        ]);
    }

    public function test_preprovisioned_tendik_and_akademik_login_by_verified_email(): void
    {
        config(['services.google.client_id' => 'test-client-id']);
        $this->fakeGoogleTokens([
            'tendik-token' => [
                'email' => 'preprovisioned.tendik@ugm.ac.id',
                'sub' => 'google-tendik',
            ],
            'akademik-token' => [
                'email' => 'preprovisioned.akademik@ugm.ac.id',
                'sub' => 'google-akademik',
            ],
        ]);

        $tendik = $this->user([
            'email' => 'preprovisioned.tendik@ugm.ac.id',
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
            'nip' => null,
            'status' => UserStatus::Active,
        ]);
        $program = $this->program($this->department());
        $akademik = $this->user([
            'email' => 'preprovisioned.akademik@ugm.ac.id',
            'role' => 'akademik',
            'sub_role' => 'kaprodi',
            'study_program_id' => $program->id,
            'department_id' => $program->department_id,
            'nip' => null,
            'status' => UserStatus::Active,
        ]);

        $this->postJson('/api/auth/google', ['credential' => 'tendik-token'])
            ->assertOk()
            ->assertJsonPath('user.role', 'tendik')
            ->assertJsonPath('completion.fields.0', 'nip')
            ->assertJsonPath('needs_completion', true);

        $this->postJson('/api/auth/google', ['credential' => 'akademik-token'])
            ->assertOk()
            ->assertJsonPath('user.role', 'akademik')
            ->assertJsonPath('user.sub_role', 'kaprodi')
            ->assertJsonPath('completion.fields.0', 'nip')
            ->assertJsonPath('needs_completion', true);

        $this->assertSame('tendik', $tendik->fresh()->role);
        $this->assertSame(UserStatus::PendingProfile, $tendik->fresh()->status);
        $this->assertSame('akademik', $akademik->fresh()->role);
        $this->assertSame(UserStatus::PendingProfile, $akademik->fresh()->status);
    }

    public function test_google_login_rejects_wrong_audience_unverified_non_ugm_and_suspended_accounts(): void
    {
        config(['services.google.client_id' => 'test-client-id']);
        $this->fakeGoogleTokens([
            'wrong-audience-token' => [
                'aud' => 'another-client-id',
                'email' => 'wrong-audience@ugm.ac.id',
                'sub' => 'google-wrong-audience',
            ],
            'unverified-token' => [
                'email' => 'unverified@ugm.ac.id',
                'email_verified' => 'false',
                'sub' => 'google-unverified',
            ],
            'external-domain-token' => [
                'email' => 'external@example.com',
                'sub' => 'google-external',
            ],
            'suspended-token' => [
                'email' => 'suspended@ugm.ac.id',
                'sub' => 'google-suspended',
            ],
        ]);

        $this->user([
            'email' => 'suspended@ugm.ac.id',
            'role' => 'akademik',
            'sub_role' => 'kaprodi',
            'status' => UserStatus::Suspended,
        ]);

        $this->postJson('/api/auth/google', ['credential' => 'wrong-audience-token'])
            ->assertUnauthorized();
        $this->postJson('/api/auth/google', ['credential' => 'unverified-token'])
            ->assertUnauthorized();
        $this->postJson('/api/auth/google', ['credential' => 'external-domain-token'])
            ->assertForbidden();
        $this->postJson('/api/auth/google', ['credential' => 'suspended-token'])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'wrong-audience@ugm.ac.id']);
        $this->assertDatabaseMissing('users', ['email' => 'unverified@ugm.ac.id']);
        $this->assertDatabaseMissing('users', ['email' => 'external@example.com']);
    }

    public function test_normal_student_identity_is_immutable_and_super_admin_can_correct_identity_and_scope(): void
    {
        $oldDepartment = $this->department('DOLD', 'Departemen Lama');
        $oldProgram = $this->program($oldDepartment, 'POLD', 'Program Lama');
        $newDepartment = $this->department('DNEW', 'Departemen Baru');
        $newProgram = $this->program($newDepartment, 'PNEW', 'Program Baru');
        $proofDepartment = $this->department('P2C3D202605211746078638', 'Proof Department');
        $proofProgram = $this->program(
            $proofDepartment,
            'P2C3P202605211746078638',
            'Proof Study Program'
        );

        $student = $this->user([
            'email' => 'student.identity@ugm.ac.id',
            'study_program_id' => $oldProgram->id,
        ]);
        MahasiswaProfile::create([
            'user_id' => $student->id,
            'nim' => '23/123456/SV/10001',
        ]);

        Sanctum::actingAs($student);
        $this->postJson('/api/profile', [
            'nim' => '24/535278/SV/12345',
            'study_program_id' => $newProgram->id,
        ])->assertOk();

        $student->refresh();
        $this->assertSame($oldProgram->id, $student->study_program_id);
        $this->assertSame('23/123456/SV/10001', $student->mahasiswaProfile->nim);

        $primaryAdmin = $this->user([
            'email' => 'primary.admin@ugm.ac.id',
            'role' => 'super_admin',
            'role_level' => 'primary',
        ]);
        Sanctum::actingAs($primaryAdmin);

        $this->postJson('/api/super-admin/users', [
            'name' => 'Rejected Proof Student',
            'email' => 'rejected.proof@ugm.ac.id',
            'role' => 'mahasiswa',
            'nim' => '24/535279/SV/12346',
            'study_program_id' => $proofProgram->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('study_program_id');

        $this->putJson("/api/super-admin/users/{$student->id}", [
            'study_program_id' => $proofProgram->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('study_program_id');

        $this->putJson("/api/super-admin/users/{$student->id}", [
            'nim' => '24/535278/SV/12345',
            'study_program_id' => $newProgram->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.study_program_id', $newProgram->id);

        $student->refresh();
        $this->assertSame($newProgram->id, $student->study_program_id);
        $this->assertSame('24/535278/SV/12345', $student->mahasiswaProfile->nim);

        $kadep = $this->user([
            'email' => 'kadep.correction@ugm.ac.id',
            'role' => 'akademik',
            'sub_role' => 'kadep',
            'nip' => '198001012006041001',
            'department_id' => $oldDepartment->id,
        ]);

        $this->putJson("/api/super-admin/users/{$kadep->id}", [
            'department_id' => $proofDepartment->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');

        $this->putJson("/api/super-admin/users/{$kadep->id}", [
            'nip' => '197512122005011002',
            'department_id' => $newDepartment->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.nip', '197512122005011002')
            ->assertJsonPath('data.department_id', $newDepartment->id);

        $kadep->refresh();
        $this->assertSame('197512122005011002', $kadep->nip);
        $this->assertSame($newDepartment->id, $kadep->department_id);
    }

    private function fakeGoogleTokens(array $tokens): void
    {
        Http::fake(function (HttpRequest $request) use ($tokens) {
            $token = $request->data()['id_token'] ?? '';
            $identity = $tokens[$token] ?? null;

            if (!$identity) {
                return Http::response([], 401);
            }

            return Http::response(array_merge([
                'aud' => 'test-client-id',
                'email_verified' => 'true',
                'name' => 'Google User',
            ], $identity));
        });
    }

    private function user(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => uniqid('user-', true) . '@example.test',
            'password' => 'password123',
            'role' => 'mahasiswa',
            'status' => UserStatus::Active,
        ], $attributes));
    }

    private function department(
        string $code = 'DTEDI',
        string $name = 'Departemen Teknik Elektro dan Informatika'
    ): Department {
        return Department::create([
            'code' => $code,
            'name' => $name,
        ]);
    }

    private function program(
        Department $department,
        string $code = 'TRPL',
        string $name = 'Teknologi Rekayasa Perangkat Lunak'
    ): StudyProgram {
        return StudyProgram::create([
            'code' => $code,
            'name' => $name,
            'department_id' => $department->id,
        ]);
    }
}
