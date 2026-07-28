<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\MahasiswaProfile;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AUDIT (behaviour-locking, no policy change): proves the intended
 * pre-provisioned staff -> matching Google login -> existing-role-preserved flow
 * still holds. The Google credential AUTHENTICATES an identity; it must never act
 * as a role-registration mechanism, must never duplicate the local user, and must
 * never silently create or change a password. Matching is by VERIFIED email; role,
 * status and password remain governed by the local record.
 */
class GoogleLoginPreProvisionedFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test-client-id']);
    }

    public function test_preprovisioned_complete_staff_is_authenticated_role_and_status_preserved_not_duplicated(): void
    {
        // A fully-provisioned SuperAdmin (whose status the completion service never
        // touches) with an existing local password and no google_id yet.
        $admin = $this->user([
            'email' => 'kepala.tu@ugm.ac.id',
            'name' => 'Kepala TU',
            'role' => 'super_admin',
            'role_level' => 'primary',
            'status' => UserStatus::Active,
            'password' => 'Sup3r-Kuat-2026',
            'password_must_rotate' => false,
            'google_id' => null,
        ]);
        $passwordHashBefore = $admin->fresh()->password;

        $this->fakeGoogleTokens([
            'admin-token' => ['email' => 'kepala.tu@ugm.ac.id', 'sub' => 'google-admin-sub'],
        ]);

        $this->postJson('/api/auth/google', ['credential' => 'admin-token'])
            ->assertOk()
            ->assertJsonPath('user.id', $admin->id)          // existing record, not a new one
            ->assertJsonPath('user.role', 'super_admin')     // role preserved
            ->assertJsonPath('user.role_level', 'primary')
            ->assertJsonPath('user.status', UserStatus::Active->value)
            ->assertJsonPath('needs_completion', false);

        // No duplicate account for this email.
        $this->assertSame(1, User::where('email', 'kepala.tu@ugm.ac.id')->count());

        $fresh = $admin->fresh();
        $this->assertSame('super_admin', $fresh->role);
        $this->assertSame(UserStatus::Active, $fresh->status);
        $this->assertSame('google-admin-sub', $fresh->google_id, 'Google identity is linked on first login.');
        // Scope 6: Google login did NOT create or change the local password.
        $this->assertSame($passwordHashBefore, $fresh->password, 'Google login must not touch the password.');

        // The pre-provisioned password login still works after Google linking.
        $this->postJson('/api/login', [
            'email' => 'kepala.tu@ugm.ac.id',
            'password' => 'Sup3r-Kuat-2026',
        ])->assertOk()->assertJsonPath('user.role', 'super_admin');
    }

    public function test_preprovisioned_tendik_role_variant_is_preserved_through_google_login(): void
    {
        // Sarpras / Laboran / Kepala Lab are all role=tendik with a tendik_role.
        // A complete persuratan tendik (nip present, no laboratory needed) stays Active.
        $tendik = $this->user([
            'email' => 'staff.persuratan@ugm.ac.id',
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
            'nip' => '198203032008011002',
            'status' => UserStatus::Active,
        ]);

        $this->fakeGoogleTokens([
            'tendik-token' => ['email' => 'staff.persuratan@ugm.ac.id', 'sub' => 'google-tendik-sub'],
        ]);

        $this->postJson('/api/auth/google', ['credential' => 'tendik-token'])
            ->assertOk()
            ->assertJsonPath('user.role', 'tendik')
            ->assertJsonPath('user.tendik_role', 'persuratan')
            ->assertJsonPath('user.status', UserStatus::Active->value)
            ->assertJsonPath('needs_completion', false);

        $fresh = $tendik->fresh();
        $this->assertSame('tendik', $fresh->role);
        $this->assertSame('persuratan', $fresh->tendik_role);
        $this->assertSame(UserStatus::Active, $fresh->status);
    }

    public function test_google_login_matches_by_verified_email_and_does_not_overwrite_an_existing_linked_identity(): void
    {
        // DOCUMENTS actual identity model: lookup is by verified email; the stored
        // google_id (sub) is captured once and is NOT overwritten on later logins.
        $tendik = $this->user([
            'email' => 'linked.staff@ugm.ac.id',
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
            'nip' => '197711112003121001',
            'status' => UserStatus::Active,
            'google_id' => 'google-sub-ORIGINAL',
        ]);

        // Same verified institutional email, but the token carries a different sub.
        $this->fakeGoogleTokens([
            'other-sub-token' => ['email' => 'linked.staff@ugm.ac.id', 'sub' => 'google-sub-DIFFERENT'],
        ]);

        $this->postJson('/api/auth/google', ['credential' => 'other-sub-token'])
            ->assertOk()
            ->assertJsonPath('user.id', $tendik->id)
            ->assertJsonPath('user.role', 'tendik');

        $fresh = $tendik->fresh();
        $this->assertSame('tendik', $fresh->role, 'Role is never changed by Google login.');
        $this->assertSame(
            'google-sub-ORIGINAL',
            $fresh->google_id,
            'Existing linked google_id is preserved (link-once); it is not overwritten.'
        );
    }

    public function test_existing_active_mahasiswa_google_login_preserves_role_and_active_status(): void
    {
        $department = Department::create(['code' => 'DTEDI', 'name' => 'Departemen TEDI']);
        $program = StudyProgram::create([
            'code' => 'TRPL',
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
            'department_id' => $department->id,
        ]);

        $mahasiswa = $this->user([
            'email' => 'existing.student@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'status' => UserStatus::Active,
            'password' => null,
            'study_program_id' => $program->id,
            'department_id' => $department->id,
            'google_id' => 'google-student-sub',
        ]);
        MahasiswaProfile::create([
            'user_id' => $mahasiswa->id,
            'nim' => '23/123456/SV/10001',
        ]);

        $this->fakeGoogleTokens([
            'student-token' => ['email' => 'existing.student@mail.ugm.ac.id', 'sub' => 'google-student-sub'],
        ]);

        $this->postJson('/api/auth/google', ['credential' => 'student-token'])
            ->assertOk()
            ->assertJsonPath('user.id', $mahasiswa->id)
            ->assertJsonPath('user.role', 'mahasiswa')
            ->assertJsonPath('user.status', UserStatus::Active->value)
            ->assertJsonPath('needs_completion', false);

        $this->assertSame(1, User::where('email', 'existing.student@mail.ugm.ac.id')->count());
        // Google-only account stays password-less: no accidental default password.
        $this->assertNull($mahasiswa->fresh()->password);
    }

    public function test_google_login_matches_a_preprovisioned_account_stored_with_mixed_case_email(): void
    {
        // Regression: admins may provision an email with mixed case. The verified
        // Google email is lowercased, so a case-sensitive lookup would miss the
        // account and wrongly reject the staffer (or duplicate a student). The
        // lookup must be case-insensitive.
        $staff = $this->user([
            'email' => 'Nama.Orang@ugm.ac.id',
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
            'nip' => '198501012010011003',
            'status' => UserStatus::Active,
        ]);

        $this->fakeGoogleTokens([
            'mixed-case-token' => ['email' => 'nama.orang@ugm.ac.id', 'sub' => 'sub-mixed-case'],
        ]);

        $this->postJson('/api/auth/google', ['credential' => 'mixed-case-token'])
            ->assertOk()
            ->assertJsonPath('user.id', $staff->id)
            ->assertJsonPath('user.role', 'tendik');

        // Matched the existing account, linked it, created no duplicate.
        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', ['nama.orang@ugm.ac.id'])->count());
        $this->assertSame('sub-mixed-case', $staff->fresh()->google_id);
    }

    public function test_google_login_accepts_both_google_issuer_forms(): void
    {
        $this->user(['email' => 'iss.one@ugm.ac.id', 'role' => 'tendik', 'tendik_role' => 'persuratan', 'nip' => '198001012006041001']);
        $this->user(['email' => 'iss.two@ugm.ac.id', 'role' => 'tendik', 'tendik_role' => 'persuratan', 'nip' => '198001012006041002']);

        $this->fakeGoogleTokens([
            'bare-issuer' => ['iss' => 'accounts.google.com', 'email' => 'iss.one@ugm.ac.id', 'sub' => 'sub-iss-one'],
            'https-issuer' => ['iss' => 'https://accounts.google.com', 'email' => 'iss.two@ugm.ac.id', 'sub' => 'sub-iss-two'],
        ]);

        $this->postJson('/api/auth/google', ['credential' => 'bare-issuer'])->assertOk();
        $this->postJson('/api/auth/google', ['credential' => 'https-issuer'])->assertOk();
    }

    public function test_google_login_rejects_a_non_google_issuer(): void
    {
        $this->user(['email' => 'victim@ugm.ac.id', 'role' => 'super_admin', 'role_level' => 'primary']);

        // A token that is otherwise well-formed (correct aud, verified UGM email) but
        // carries a forged/foreign issuer must be rejected cleanly, not authenticated.
        $this->fakeGoogleTokens([
            'forged-issuer' => ['iss' => 'https://accounts.evil.example.com', 'email' => 'victim@ugm.ac.id', 'sub' => 'sub-forged'],
        ]);

        $this->postJson('/api/auth/google', ['credential' => 'forged-issuer'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Token Google tidak valid atau sudah kedaluwarsa.');

        // The pre-provisioned account is untouched: no Google identity was linked.
        $this->assertNull(User::where('email', 'victim@ugm.ac.id')->firstOrFail()->google_id);
    }

    public function test_a_google_sub_cannot_be_bound_to_two_local_users_db_guarantee(): void
    {
        // DB integrity (users_google_id_unique): a single stable Google identity can
        // never be bound to two local accounts, which also fails-closed the concurrent
        // first-time-linking race at the storage layer.
        $this->user(['email' => 'holder@ugm.ac.id', 'role' => 'tendik', 'tendik_role' => 'persuratan', 'google_id' => 'exclusive-sub']);

        $this->expectException(QueryException::class);
        $this->user(['email' => 'other@ugm.ac.id', 'role' => 'tendik', 'tendik_role' => 'persuratan', 'google_id' => 'exclusive-sub']);
    }

    private function fakeGoogleTokens(array $tokens): void
    {
        Http::fake(function (HttpRequest $request) use ($tokens) {
            $token = $request->data()['id_token'] ?? '';
            $identity = $tokens[$token] ?? null;

            if (! $identity) {
                return Http::response([], 401);
            }

            return Http::response(array_merge([
                'iss' => 'accounts.google.com',
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
            'email' => uniqid('user-', true).'@ugm.ac.id',
            'password' => 'password123',
            'role' => 'mahasiswa',
            'status' => UserStatus::Active,
        ], $attributes));
    }
}
