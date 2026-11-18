<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\UserStatus;
use App\Models\MahasiswaProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A @mail.ugm.ac.id Google login with no matching account auto-creates a
 * Mahasiswa. When Super Admin then assigns that same email to a non-Mahasiswa
 * role, the auto-created account must become that role — not be rejected as
 * "email taken" (which used to push admins into a second, duplicate account).
 */
class AutoMahasiswaRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'budi.staf@mail.ugm.ac.id';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test-client-id']);
        Http::fake(function (HttpRequest $request) {
            if (($request->data()['id_token'] ?? '') !== 'budi-token') {
                return Http::response([], 401);
            }

            return Http::response([
                'iss' => 'accounts.google.com',
                'aud' => 'test-client-id',
                'email_verified' => 'true',
                'email' => self::EMAIL,
                'sub' => 'google-budi-sub',
                'name' => 'Budi Staf',
            ]);
        });
    }

    public function test_assigning_staff_role_to_auto_created_mahasiswa_email_converts_that_account(): void
    {
        // 1. First Google login auto-creates a Mahasiswa.
        $this->postJson('/api/auth/google', ['credential' => 'budi-token'])
            ->assertOk()
            ->assertJsonPath('user.role', 'mahasiswa');
        $auto = User::where('email', self::EMAIL)->firstOrFail();
        $auto->createToken('mahasiswa-session');

        // 2. Super Admin assigns the same email to Tendik Persuratan.
        Sanctum::actingAs($this->primaryAdmin());
        $this->postJson('/api/super-admin/users', [
            'name' => 'Budi Staf',
            'email' => strtoupper(self::EMAIL),
            'password' => 'InitialStaff1!',
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
        ])
            ->assertOk()
            ->assertJsonPath('converted_from_auto_mahasiswa', true)
            ->assertJsonPath('data.id', $auto->id)
            ->assertJsonPath('data.role', 'tendik');

        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', [self::EMAIL])->count());
        $auto->refresh();
        $this->assertSame('tendik', $auto->role);
        $this->assertSame('persuratan', $auto->tendik_role);
        $this->assertSame(UserStatus::Active, $auto->status);
        $this->assertSame('google-budi-sub', $auto->google_id);
        $this->assertSame(0, $auto->tokens()->count(), 'Mahasiswa sessions must be revoked');

        // 3. Next Google login lands on the same account with the assigned role.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/google', ['credential' => 'budi-token'])
            ->assertOk()
            ->assertJsonPath('user.id', $auto->id)
            ->assertJsonPath('user.role', 'tendik')
            ->assertJsonPath('user.tendik_role', 'persuratan');
        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', [self::EMAIL])->count());
    }

    public function test_admin_created_mahasiswa_is_never_converted_implicitly(): void
    {
        $student = User::create([
            'name' => 'Real Student',
            'email' => self::EMAIL,
            'password' => null,
            'role' => 'mahasiswa',
            'status' => UserStatus::Active,
        ]);
        MahasiswaProfile::create(['user_id' => $student->id, 'data_source' => 'admin_create']);

        Sanctum::actingAs($this->primaryAdmin());
        $this->postJson('/api/super-admin/users', [
            'name' => 'Budi Staf',
            'email' => self::EMAIL,
            'password' => 'InitialStaff1!',
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame('mahasiswa', $student->fresh()->role);
    }

    public function test_creating_another_mahasiswa_with_auto_created_email_is_still_rejected(): void
    {
        $this->postJson('/api/auth/google', ['credential' => 'budi-token'])->assertOk();

        Sanctum::actingAs($this->primaryAdmin());
        $this->postJson('/api/super-admin/users', [
            'name' => 'Budi Staf',
            'email' => self::EMAIL,
            'role' => 'mahasiswa',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(1, User::where('email', self::EMAIL)->count());
    }

    private function primaryAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'role_level' => 'primary',
            'status' => UserStatus::Active,
        ]);
    }
}
