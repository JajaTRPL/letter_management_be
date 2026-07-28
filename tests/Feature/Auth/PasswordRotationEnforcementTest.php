<?php

namespace Tests\Feature\Auth;

use App\Enums\PasswordSetMethod;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\AuthTokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PasswordRotationEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_login_issues_explicit_full_token_and_preserves_safe_failure_responses(): void
    {
        $user = $this->localUser([
            'email' => 'local.login@ugm.ac.id',
            'password' => Hash::make('CurrentSecure1!'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'CurrentSecure1!',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonMissingPath('rotation_token');

        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertNotNull($token);
        $this->assertSame(AuthTokenAbilities::LOCAL_FULL_ACCESS, $token->abilities);
        $this->assertNull($token->expires_at);

        $this->resetAuthenticationState();
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'WrongSecure1!',
        ])->assertUnauthorized()
            ->assertExactJson(['message' => 'Email atau password salah']);

        $suspended = $this->localUser([
            'email' => 'suspended.login@ugm.ac.id',
            'password' => Hash::make('CurrentSecure1!'),
            'status' => UserStatus::Suspended,
        ]);

        $this->postJson('/api/login', [
            'email' => $suspended->email,
            'password' => 'CurrentSecure1!',
        ])->assertForbidden()
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('rotation_token');
    }

    public function test_flagged_local_login_returns_only_a_short_lived_rotation_token_and_revokes_access(): void
    {
        $user = $this->localUser([
            'email' => 'rotation.login@ugm.ac.id',
            'password' => Hash::make('CurrentSecure1!'),
            'password_must_rotate' => true,
            'last_login_at' => null,
        ]);
        $oldToken = $user->createToken('old-session')->plainTextToken;
        DB::table('sessions')->insert([
            'id' => 'rotation-login-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'CurrentSecure1!',
        ])->assertStatus(423)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'PASSWORD_ROTATION_REQUIRED')
            ->assertJsonPath('expires_in', 900)
            ->assertJsonStructure(['rotation_token'])
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user')
            ->assertJsonMissingPath('password_set_method')
            ->assertJsonMissingPath('password_must_rotate')
            ->assertJsonMissingPath('reset_token');

        $this->assertSame([
            'success',
            'code',
            'message',
            'rotation_token',
            'expires_in',
        ], array_keys($response->json()));
        $this->assertStringNotContainsString($user->password, $response->getContent());

        $this->assertNull(PersonalAccessToken::findToken($oldToken));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);

        $rotationToken = PersonalAccessToken::findToken($response->json('rotation_token'));
        $this->assertNotNull($rotationToken);
        $this->assertSame(AuthTokenAbilities::PASSWORD_ROTATION_ONLY, $rotationToken->abilities);
        $this->assertNotNull($rotationToken->expires_at);
        $this->assertTrue($rotationToken->expires_at->between(
            now()->addMinutes(14),
            now()->addMinutes(16),
        ));
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_normal_route_middleware_blocks_rotation_local_and_wildcard_tokens_but_allows_google(): void
    {
        $user = $this->localUser();
        $rotationToken = $user->createToken(
            'rotation',
            AuthTokenAbilities::PASSWORD_ROTATION_ONLY,
            now()->addMinutes(15),
        )->plainTextToken;
        $localToken = $user->createToken(
            'local',
            AuthTokenAbilities::LOCAL_FULL_ACCESS,
        )->plainTextToken;
        $googleToken = $user->createToken(
            'google',
            AuthTokenAbilities::GOOGLE_FULL_ACCESS,
        )->plainTextToken;
        $legacyToken = $user->createToken('legacy')->plainTextToken;

        $user->forceFill(['password_must_rotate' => true])->save();

        foreach ([$rotationToken, $localToken, $legacyToken] as $blockedToken) {
            $this->getJsonWithBearer('/api/auth/profile-completion', $blockedToken)
                ->assertStatus(423)
                ->assertJsonPath('code', 'PASSWORD_ROTATION_REQUIRED');
        }

        $this->getJsonWithBearer('/api/auth/profile-completion', $googleToken)
            ->assertOk()
            ->assertJsonStructure(['completion']);
    }

    public function test_rotation_status_and_logout_accept_the_rotation_token(): void
    {
        $user = $this->localUser([
            'password' => Hash::make('CurrentSecure1!'),
            'password_must_rotate' => true,
        ]);
        $rotationToken = $this->rotationLoginToken($user, 'CurrentSecure1!');

        $status = $this->getJsonWithBearer('/api/auth/password-rotation', $rotationToken)
            ->assertOk()
            ->assertJsonPath('code', 'PASSWORD_ROTATION_REQUIRED')
            ->assertJsonStructure(['expires_at', 'expires_in']);

        $this->assertSame([
            'success',
            'code',
            'message',
            'expires_at',
            'expires_in',
        ], array_keys($status->json()));
        $this->assertStringNotContainsString($user->email, $status->getContent());
        $this->assertStringNotContainsString($user->password, $status->getContent());
        $status->assertJsonMissingPath('password_set_method')
            ->assertJsonMissingPath('password_must_rotate')
            ->assertJsonMissingPath('password_set_at')
            ->assertJsonMissingPath('password_set_by_user_id')
            ->assertJsonMissingPath('reset_token');

        $this->assertGreaterThan(0, $status->json('expires_in'));
        $this->assertLessThanOrEqual(900, $status->json('expires_in'));

        $this->postJsonWithBearer('/api/logout', [], $rotationToken)
            ->assertOk()
            ->assertExactJson(['message' => 'Logout berhasil']);
        $this->assertNull(PersonalAccessToken::findToken($rotationToken));

        $this->getJsonWithBearer('/api/auth/password-rotation', $rotationToken)
            ->assertUnauthorized();
    }

    public function test_logout_accepts_a_full_app_token_without_profile_completion_dependency(): void
    {
        $user = $this->localUser([
            'email' => 'logout.full@ugm.ac.id',
            'password' => Hash::make('CurrentSecure1!'),
            'nip' => null,
        ]);
        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'CurrentSecure1!',
        ])->assertOk();
        $fullToken = $login->json('token');

        $this->postJsonWithBearer('/api/logout', [], $fullToken)
            ->assertOk()
            ->assertExactJson(['message' => 'Logout berhasil']);

        $this->assertNull(PersonalAccessToken::findToken($fullToken));
    }

    public function test_successful_rotation_updates_metadata_invalidates_reset_state_and_revokes_every_session(): void
    {
        $user = $this->localUser([
            'email' => 'rotation.success@ugm.ac.id',
            'password' => Hash::make('CurrentSecure1!'),
            'password_set_method' => PasswordSetMethod::LegacyUnknown,
            'password_must_rotate' => true,
        ]);
        $rotationToken = $this->rotationLoginToken($user, 'CurrentSecure1!');
        $concurrentToken = $user->createToken(
            'concurrent',
            AuthTokenAbilities::GOOGLE_FULL_ACCESS,
        )->plainTextToken;

        DB::table('sessions')->insert([
            'id' => 'rotation-completion-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('123456'),
            'created_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'is_verified' => true,
            'attempts' => 1,
            'verified_at' => now(),
            'reset_token' => hash('sha256', Str::random(64)),
            'reset_token_expires_at' => now()->addMinutes(10),
            'used_at' => null,
        ]);

        $this->postJsonWithBearer('/api/auth/password-rotation', [
            'password' => 'NewRotation1!',
            'password_confirmation' => 'NewRotation1!',
        ], $rotationToken)->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Kata sandi berhasil diperbarui. Silakan login kembali.',
            ])
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('rotation_token');

        $user->refresh();
        $this->assertTrue(Hash::check('NewRotation1!', $user->password));
        $this->assertSame(PasswordSetMethod::SelfServiceChange, $user->password_set_method);
        $this->assertSame($user->id, $user->password_set_by_user_id);
        $this->assertNotNull($user->password_set_at);
        $this->assertFalse($user->password_must_rotate);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertNull(PersonalAccessToken::findToken($rotationToken));
        $this->assertNull(PersonalAccessToken::findToken($concurrentToken));

        $resetState = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $this->assertNotNull($resetState);
        $this->assertFalse((bool) $resetState->is_verified);
        $this->assertNull($resetState->reset_token);
        $this->assertNull($resetState->reset_token_expires_at);
        $this->assertNotNull($resetState->used_at);

        $this->getJsonWithBearer('/api/auth/password-rotation', $rotationToken)
            ->assertUnauthorized();

        $this->resetAuthenticationState();
        $newLogin = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'NewRotation1!',
        ])->assertOk()
            ->assertJsonStructure(['token']);
        $newToken = PersonalAccessToken::findToken($newLogin->json('token'));
        $this->assertSame(AuthTokenAbilities::LOCAL_FULL_ACCESS, $newToken?->abilities);
    }

    public function test_rotation_rejects_weak_password_and_current_password_reuse(): void
    {
        $user = $this->localUser([
            'password' => Hash::make('CurrentSecure1!'),
            'password_must_rotate' => true,
        ]);
        $rotationToken = $this->rotationLoginToken($user, 'CurrentSecure1!');

        $this->postJsonWithBearer('/api/auth/password-rotation', [
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ], $rotationToken)->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJsonWithBearer('/api/auth/password-rotation', [
            'password' => 'CurrentSecure1!',
            'password_confirmation' => 'CurrentSecure1!',
        ], $rotationToken)->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertTrue($user->fresh()->password_must_rotate);
        $this->assertNotNull(PersonalAccessToken::findToken($rotationToken));
    }

    public function test_rotation_endpoints_reject_expired_forged_and_full_app_tokens(): void
    {
        $user = $this->localUser([
            'password_must_rotate' => true,
        ]);
        $expired = $user->createToken(
            'expired-rotation',
            AuthTokenAbilities::PASSWORD_ROTATION_ONLY,
            now()->subMinute(),
        )->plainTextToken;
        $fullToken = $user->createToken(
            'full-token',
            AuthTokenAbilities::LOCAL_FULL_ACCESS,
        )->plainTextToken;

        $this->getJsonWithBearer('/api/auth/password-rotation', $expired)
            ->assertUnauthorized();
        $this->getJsonWithBearer('/api/auth/password-rotation', 'forged-token')
            ->assertUnauthorized();
        $this->postJsonWithBearer('/api/auth/password-rotation', [
            'password' => 'NewRotation1!',
            'password_confirmation' => 'NewRotation1!',
        ], $expired)->assertUnauthorized();
        $this->postJsonWithBearer('/api/auth/password-rotation', [
            'password' => 'NewRotation1!',
            'password_confirmation' => 'NewRotation1!',
        ], 'forged-token')->assertUnauthorized();
        $this->getJsonWithBearer('/api/auth/password-rotation', $fullToken)
            ->assertForbidden()
            ->assertJsonPath('code', 'ROTATION_TOKEN_REQUIRED');
    }

    public function test_rotation_submit_has_a_dedicated_rate_limit(): void
    {
        config(['password_rotation.max_attempts_per_minute' => 2]);

        $user = $this->localUser([
            'password' => Hash::make('CurrentSecure1!'),
            'password_must_rotate' => true,
        ]);
        $rotationToken = $this->rotationLoginToken($user, 'CurrentSecure1!');
        $weakPayload = [
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ];

        $this->postJsonWithBearer('/api/auth/password-rotation', $weakPayload, $rotationToken)
            ->assertUnprocessable();
        $this->postJsonWithBearer('/api/auth/password-rotation', $weakPayload, $rotationToken)
            ->assertUnprocessable();
        $this->postJsonWithBearer('/api/auth/password-rotation', $weakPayload, $rotationToken)
            ->assertTooManyRequests();
    }

    public function test_google_login_uses_explicit_abilities_and_keeps_rotation_flag_without_blocking_access(): void
    {
        config(['services.google.client_id' => 'test-client-id']);
        $this->fakeGoogleTokens([
            'active-google-token' => [
                'email' => 'hybrid.user@ugm.ac.id',
                'sub' => 'google-hybrid-user',
            ],
            'suspended-google-token' => [
                'email' => 'hybrid.suspended@ugm.ac.id',
                'sub' => 'google-hybrid-suspended',
            ],
        ]);

        $user = $this->localUser([
            'email' => 'hybrid.user@ugm.ac.id',
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
            'nip' => '198501012010011001',
            'password_must_rotate' => true,
        ]);
        $this->localUser([
            'email' => 'hybrid.suspended@ugm.ac.id',
            'status' => UserStatus::Suspended,
            'password_must_rotate' => true,
        ]);

        $login = $this->postJson('/api/auth/google', [
            'credential' => 'active-google-token',
        ])->assertOk()
            ->assertJsonPath('user.role', 'tendik')
            ->assertJsonStructure(['token']);

        $googleToken = PersonalAccessToken::findToken($login->json('token'));
        $this->assertNotNull($googleToken);
        $this->assertSame(AuthTokenAbilities::GOOGLE_FULL_ACCESS, $googleToken->abilities);
        $this->assertTrue($user->fresh()->password_must_rotate);

        $this->getJsonWithBearer('/api/auth/profile-completion', $login->json('token'))
            ->assertOk();

        $this->resetAuthenticationState();
        $this->postJson('/api/auth/google', [
            'credential' => 'suspended-google-token',
        ])->assertForbidden();
    }

    private function localUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'super_admin',
            'role_level' => 'primary',
            'status' => UserStatus::Active,
            'password' => Hash::make('CurrentSecure1!'),
            'password_must_rotate' => false,
        ], $attributes));
    }

    private function rotationLoginToken(User $user, string $password): string
    {
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertStatus(423)
            ->assertJsonPath('code', 'PASSWORD_ROTATION_REQUIRED');

        return (string) $response->json('rotation_token');
    }

    private function resetAuthenticationState(): void
    {
        $this->app['auth']->shouldUse('web');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function getJsonWithBearer(string $uri, string $token)
    {
        $this->resetAuthenticationState();

        return $this->getJson($uri, [
            'Authorization' => 'Bearer '.$token,
        ]);
    }

    private function postJsonWithBearer(string $uri, array $data, string $token)
    {
        $this->resetAuthenticationState();

        return $this->postJson($uri, $data, [
            'Authorization' => 'Bearer '.$token,
        ]);
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
                'iss' => 'accounts.google.com',
                'aud' => 'test-client-id',
                'email_verified' => 'true',
                'name' => 'Google User',
            ], $identity));
        });
    }
}
