<?php

namespace Tests\Feature\Auth;

use App\Enums\PasswordSetMethod;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasswordOriginMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_origin_schema_is_additive_and_model_casts_are_available(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'password_set_method',
            'password_set_at',
            'password_set_by_user_id',
            'password_must_rotate',
        ]));

        $user = User::factory()->create([
            'password_set_method' => PasswordSetMethod::LegacyUnknown,
            'password_set_at' => now(),
            'password_must_rotate' => true,
        ])->fresh();

        $this->assertSame(PasswordSetMethod::LegacyUnknown, $user->password_set_method);
        $this->assertNotNull($user->password_set_at);
        $this->assertTrue($user->password_must_rotate);
    }

    public function test_verified_otp_reset_records_origin_and_clears_rotation_requirement(): void
    {
        $user = User::factory()->create([
            'role' => 'mahasiswa',
            'status' => UserStatus::Active,
            'password' => null,
            'password_set_method' => null,
            'password_set_at' => null,
            'password_set_by_user_id' => null,
            'password_must_rotate' => true,
        ]);
        $oldToken = $user->createToken('old-session')->plainTextToken;
        DB::table('sessions')->insert([
            'id' => 'metadata-reset-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $resetToken = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('123456'),
            'created_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'is_verified' => true,
            'attempts' => 0,
            'verified_at' => now(),
            'reset_token' => hash('sha256', $resetToken),
            'reset_token_expires_at' => now()->addMinutes(10),
            'used_at' => null,
        ]);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'NewMetadata1!',
            'password_confirmation' => 'NewMetadata1!',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NewMetadata1!', $user->password));
        $this->assertSame(PasswordSetMethod::ResetPasswordOtp, $user->password_set_method);
        $this->assertNotNull($user->password_set_at);
        $this->assertNull($user->password_set_by_user_id);
        $this->assertFalse($user->password_must_rotate);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/auth/profile-completion')
            ->assertUnauthorized();
    }

    public function test_super_admin_staff_password_create_and_update_are_attributed_and_revoke_access(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'role_level' => 'primary',
            'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/super-admin/users', [
            'name' => 'Metadata Staff',
            'email' => 'metadata.staff@ugm.ac.id',
            'password' => 'InitialStaff1!',
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
        ])->assertCreated();

        $staff = User::where('email', 'metadata.staff@ugm.ac.id')->firstOrFail();
        $this->assertSame(PasswordSetMethod::SuperAdminSet, $staff->password_set_method);
        $this->assertSame($admin->id, $staff->password_set_by_user_id);
        $this->assertNotNull($staff->password_set_at);
        $this->assertFalse($staff->password_must_rotate);

        $staffToken = $staff->createToken('staff-session')->plainTextToken;
        DB::table('sessions')->insert([
            'id' => 'metadata-admin-update-session',
            'user_id' => $staff->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->putJson("/api/super-admin/users/{$staff->id}", [
            'password' => 'UpdatedStaff1!',
        ])->assertOk();

        $staff->refresh();
        $this->assertTrue(Hash::check('UpdatedStaff1!', $staff->password));
        $this->assertSame(PasswordSetMethod::SuperAdminSet, $staff->password_set_method);
        $this->assertSame($admin->id, $staff->password_set_by_user_id);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $staff->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $staff->id]);

        $this->app['auth']->shouldUse('web');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$staffToken)
            ->getJson('/api/auth/profile-completion')
            ->assertUnauthorized();
    }

    public function test_staff_self_service_password_change_is_attributed_without_role_escalation(): void
    {
        $staff = User::factory()->create([
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
            'nip' => '198501012010011001',
            'status' => UserStatus::Active,
        ]);
        $oldToken = $staff->createToken('self-service-session')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->postJson('/api/profile', [
                'password' => 'SelfService1!',
                'password_confirmation' => 'SelfService1!',
                'role' => 'super_admin',
            ])
            ->assertOk();

        $staff->refresh();
        $this->assertTrue(Hash::check('SelfService1!', $staff->password));
        $this->assertSame('tendik', $staff->role);
        $this->assertSame(PasswordSetMethod::SelfServiceChange, $staff->password_set_method);
        $this->assertSame($staff->id, $staff->password_set_by_user_id);
        $this->assertNotNull($staff->password_set_at);
        $this->assertFalse($staff->password_must_rotate);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $staff->id]);
    }

    public function test_passwordless_mahasiswa_paths_keep_password_and_metadata_null(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'role_level' => 'primary',
            'status' => UserStatus::Active,
        ]);
        $departmentId = DB::table('departments')->insertGetId([
            'code' => 'META-DEPT',
            'name' => 'Metadata Department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $programId = DB::table('study_programs')->insertGetId([
            'code' => 'META-PROG',
            'name' => 'Metadata Program',
            'department_id' => $departmentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/super-admin/users', [
            'name' => 'Passwordless Metadata Student',
            'email' => 'passwordless.metadata@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'nim' => '24/535278/SV/54321',
            'tanggal_lahir' => '2004-05-04',
            'study_program_id' => $programId,
        ])->assertCreated();

        $student = User::where('email', 'passwordless.metadata@mail.ugm.ac.id')->firstOrFail();
        $this->assertNull($student->password);
        $this->assertNull($student->password_set_method);
        $this->assertNull($student->password_set_at);
        $this->assertNull($student->password_set_by_user_id);
        $this->assertFalse($student->password_must_rotate);
    }

    public function test_development_student_seeder_is_passwordless_and_staff_seed_is_attributed(): void
    {
        $this->seed(UserSeeder::class);

        $student = User::where('email', 'mahasiswa@mail.com')->firstOrFail();
        $staff = User::where('email', 'tendik@mail.com')->firstOrFail();

        $this->assertNull($student->password);
        $this->assertNull($student->password_set_method);
        $this->assertFalse($student->password_must_rotate);

        $this->assertNotNull($staff->password);
        $this->assertSame(PasswordSetMethod::SystemSeed, $staff->password_set_method);
        $this->assertNotNull($staff->password_set_at);
        $this->assertFalse($staff->password_must_rotate);
    }
}
