<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Mail\ResetPasswordTokenMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ForgotPasswordHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        config([
            'app.env' => 'testing',
            'password_reset.simulation' => false,
            'password_reset.otp_expiry_minutes' => 10,
            'password_reset.reset_token_expiry_minutes' => 10,
            'password_reset.max_attempts' => 5,
            'password_reset.resend_cooldown_seconds' => 60,
            'password_reset.request_window_seconds' => 600,
            'password_reset.email_max_requests' => 5,
            'password_reset.ip_max_requests' => 20,
        ]);
    }

    public function test_unknown_email_returns_generic_success_without_mail_or_reset_record(): void
    {
        $response = $this->postJson('/api/forgot-password', [
            'email' => 'unknown@example.test',
        ]);

        $response->assertOk()->assertExactJson([
            'message' => 'Jika email terdaftar, kode verifikasi telah dikirim.',
        ]);
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'unknown@example.test',
        ]);
    }

    public function test_active_user_receives_mail_with_generic_response_and_hashed_otp(): void
    {
        $user = User::factory()->create(['email' => 'person@example.test']);

        $response = $this->postJson('/api/forgot-password', [
            'email' => '  PERSON@example.test ',
        ]);

        $response->assertOk()->assertExactJson([
            'message' => 'Jika email terdaftar, kode verifikasi telah dikirim.',
        ]);

        $code = $this->lastMailedCode($user->email);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $reset = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $this->assertNotNull($reset);
        $this->assertNotSame($code, $reset->token);
        $this->assertTrue(Hash::check($code, $reset->token));
        $this->assertSame(0, (int) $reset->attempts);
        $this->assertNull($reset->reset_token);
        $this->assertArrayNotHasKey('token_simulation', $response->json());
    }

    public function test_suspended_and_pending_users_receive_generic_success_without_mail(): void
    {
        foreach ([UserStatus::Suspended, UserStatus::PendingProfile] as $index => $status) {
            Mail::fake();
            $user = User::factory()->create([
                'email' => "ineligible{$index}@example.test",
                'status' => $status,
            ]);

            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$index}"])
                ->postJson('/api/forgot-password', ['email' => $user->email])
                ->assertOk()
                ->assertExactJson([
                    'message' => 'Jika email terdaftar, kode verifikasi telah dikirim.',
                ]);

            Mail::assertNothingSent();
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        }
    }

    public function test_known_unknown_and_suspended_accounts_have_the_same_request_response(): void
    {
        $active = User::factory()->create(['email' => 'active@example.test']);
        $suspended = User::factory()->create([
            'email' => 'suspended@example.test',
            'status' => UserStatus::Suspended,
        ]);

        $responses = [
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
                ->postJson('/api/forgot-password', ['email' => $active->email]),
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.2'])
                ->postJson('/api/forgot-password', ['email' => 'missing@example.test']),
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.3'])
                ->postJson('/api/forgot-password', ['email' => $suspended->email]),
        ];

        foreach ($responses as $response) {
            $response->assertOk()->assertExactJson([
                'message' => 'Jika email terdaftar, kode verifikasi telah dikirim.',
            ]);
        }
    }

    public function test_request_is_rate_limited_by_email_and_by_ip(): void
    {
        config([
            'password_reset.email_max_requests' => 1,
            'password_reset.ip_max_requests' => 99,
        ]);
        $user = User::factory()->create(['email' => 'limited@example.test']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])
            ->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.2'])
            ->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(429);

        Cache::flush();
        config([
            'password_reset.email_max_requests' => 99,
            'password_reset.ip_max_requests' => 1,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.3.1'])
            ->postJson('/api/forgot-password', ['email' => 'first@example.test'])
            ->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.3.1'])
            ->postJson('/api/forgot-password', ['email' => 'second@example.test'])
            ->assertStatus(429);
    }

    public function test_simulation_code_is_local_only_and_disabled_by_default(): void
    {
        $user = User::factory()->create();

        $normal = $this->postJson('/api/forgot-password', ['email' => $user->email]);
        $this->assertArrayNotHasKey('token_simulation', $normal->json());

        Cache::flush();
        config(['password_reset.simulation' => true]);
        $this->app->detectEnvironment(static fn () => 'production');
        $production = $this->withServerVariables(['REMOTE_ADDR' => '10.0.4.2'])
            ->postJson('/api/forgot-password', ['email' => $user->email]);
        $this->assertArrayNotHasKey('token_simulation', $production->json());

        Cache::flush();
        config(['password_reset.simulation' => true]);
        $this->app->detectEnvironment(static fn () => 'local');
        $local = $this->withServerVariables(['REMOTE_ADDR' => '10.0.4.3'])
            ->postJson('/api/forgot-password', ['email' => $user->email]);
        $local->assertOk()->assertJsonStructure(['message', 'token_simulation']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $local->json('token_simulation'));
    }

    public function test_resend_cooldown_blocks_the_same_email_and_ip_for_60_seconds(): void
    {
        $user = User::factory()->create();
        $request = fn () => $this->withServerVariables(['REMOTE_ADDR' => '10.0.4.4'])
            ->postJson('/api/forgot-password', ['email' => $user->email]);

        $request()->assertOk();
        $request()
            ->assertStatus(429)
            ->assertJsonPath('message', 'Terlalu banyak permintaan. Silakan coba lagi nanti.')
            ->assertJsonPath('seconds_left', 60);

        $this->travel(61)->seconds();
        $request()->assertOk();
        Mail::assertSentCount(2);
    }

    public function test_expired_invalid_and_attempt_limited_otps_are_rejected(): void
    {
        config(['password_reset.max_attempts' => 2]);
        $user = User::factory()->create();
        $code = $this->requestCode($user, '10.0.5.1');

        $this->postJson('/api/verify-token', [
            'email' => $user->email,
            'token' => '000000',
        ])->assertStatus(422);
        $this->postJson('/api/verify-token', [
            'email' => $user->email,
            'token' => '111111',
        ])->assertStatus(429);
        $this->postJson('/api/verify-token', [
            'email' => $user->email,
            'token' => $code,
        ])->assertStatus(429);

        Cache::flush();
        $expiredUser = User::factory()->create();
        $expiredCode = $this->requestCode($expiredUser, '10.0.5.2');
        DB::table('password_reset_tokens')
            ->where('email', $expiredUser->email)
            ->update(['expires_at' => now()->subSecond()]);

        $this->postJson('/api/verify-token', [
            'email' => $expiredUser->email,
            'token' => $expiredCode,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Kode verifikasi telah kedaluwarsa. Silakan minta kode baru.']);
    }

    public function test_new_request_invalidates_old_otp_and_any_verified_reset_token(): void
    {
        $user = User::factory()->create();
        $firstCode = $this->requestCode($user, '10.0.6.1');
        $firstResetToken = $this->verifyCode($user, $firstCode);

        $secondCode = $this->requestCode($user, '10.0.6.2');

        $this->postJson('/api/verify-token', [
            'email' => $user->email,
            'token' => $firstCode,
        ])->assertStatus(422);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'reset_token' => $firstResetToken,
            'password' => 'NewSecure1!',
            'password_confirmation' => 'NewSecure1!',
        ])->assertStatus(422);

        $this->postJson('/api/verify-token', [
            'email' => $user->email,
            'token' => $secondCode,
        ])->assertOk();
    }

    public function test_otp_is_one_time_and_verification_returns_reset_state_not_login(): void
    {
        $user = User::factory()->create();
        $code = $this->requestCode($user, '10.0.7.1');

        $response = $this->postJson('/api/verify-token', [
            'email' => $user->email,
            'token' => $code,
        ]);

        $response->assertOk()->assertJsonStructure(['message', 'reset_token']);
        $this->assertSame(64, strlen($response->json('reset_token')));
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertArrayNotHasKey('user', $response->json());
        $storedResetToken = DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->value('reset_token');
        $this->assertNotSame($response->json('reset_token'), $storedResetToken);
        $this->assertSame(hash('sha256', $response->json('reset_token')), $storedResetToken);

        $this->postJson('/api/verify-token', [
            'email' => $user->email,
            'token' => $code,
        ])->assertStatus(422);
    }

    public function test_reset_token_expires_and_is_scoped_to_the_verified_email(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstToken = $this->verifyCode(
            $firstUser,
            $this->requestCode($firstUser, '10.0.7.2')
        );
        $secondToken = $this->verifyCode(
            $secondUser,
            $this->requestCode($secondUser, '10.0.7.3')
        );

        $this->postJson('/api/reset-password', [
            'email' => $secondUser->email,
            'reset_token' => $firstToken,
            'password' => 'NewSecure1!',
            'password_confirmation' => 'NewSecure1!',
        ])->assertStatus(422);

        DB::table('password_reset_tokens')
            ->where('email', $firstUser->email)
            ->update(['reset_token_expires_at' => now()->subSecond()]);

        $this->postJson('/api/reset-password', [
            'email' => $firstUser->email,
            'reset_token' => $firstToken,
            'password' => 'NewSecure1!',
            'password_confirmation' => 'NewSecure1!',
        ])->assertStatus(422);

        $this->postJson('/api/reset-password', [
            'email' => $secondUser->email,
            'reset_token' => $secondToken,
            'password' => 'SecondSecure1!',
            'password_confirmation' => 'SecondSecure1!',
        ])->assertOk();
    }

    public function test_reset_requires_confirmation_and_a_strong_password(): void
    {
        $user = User::factory()->create();
        $code = $this->requestCode($user, '10.0.8.1');
        $resetToken = $this->verifyCode($user, $code);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'weakpass',
            'password_confirmation' => 'different',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_successful_reset_hashes_password_revokes_tokens_and_sessions_and_cannot_be_reused(): void
    {
        $user = User::factory()->create([
            'role' => 'super_admin',
            'password' => Hash::make('OldSecure1!'),
        ]);
        $oldToken = $user->createToken('old-session')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/auth/profile-completion')
            ->assertOk();

        DB::table('sessions')->insert([
            'id' => 'existing-database-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ]);

        $code = $this->requestCode($user, '10.0.9.1');
        $resetToken = $this->verifyCode($user, $code);
        $payload = [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'NewSecure1!',
            'password_confirmation' => 'NewSecure1!',
        ];

        $response = $this->postJson('/api/reset-password', $payload);
        $response->assertOk()->assertExactJson([
            'message' => 'Kata sandi berhasil direset. Silakan login kembali.',
        ]);

        $this->assertTrue(Hash::check('NewSecure1!', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/auth/profile-completion')
            ->assertUnauthorized();

        $this->app['auth']->shouldUse('web');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/reset-password', $payload)->assertStatus(422);
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'OldSecure1!',
        ])->assertUnauthorized();
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'NewSecure1!',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_google_only_active_account_can_set_a_local_password_after_verification(): void
    {
        $user = User::factory()->create([
            'email' => 'google.only@ugm.ac.id',
            'role' => 'tendik',
            'tendik_role' => 'persuratan',
            'nip' => null,
            'status' => UserStatus::Active,
            'google_id' => 'google-user-123',
            'password' => null,
        ]);
        $code = $this->requestCode($user, '10.0.10.1');
        $resetToken = $this->verifyCode($user, $code);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'LocalSecure1!',
            'password_confirmation' => 'LocalSecure1!',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('LocalSecure1!', $user->password));
        $this->assertSame('tendik', $user->role);
        $this->assertSame('persuratan', $user->tendik_role);
        $this->assertSame('google-user-123', $user->google_id);
        $this->assertSame(UserStatus::Active, $user->status);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'LocalSecure1!',
        ])->assertOk()
            ->assertJsonPath('needs_completion', true)
            ->assertJsonPath('user.role', 'tendik')
            ->assertJsonPath('user.status', UserStatus::PendingProfile->value);

        $this->withHeader('Authorization', 'Bearer '.$login->json('token'))
            ->getJson('/api/surat-types')
            ->assertForbidden()
            ->assertJsonPath('requires_completion', true);
    }

    public function test_mailable_has_professional_html_and_plain_text_without_sensitive_reset_state(): void
    {
        $mail = new ResetPasswordTokenMail('123456', 10);
        $this->assertSame(
            'Kode Verifikasi Reset Kata Sandi — Sistem Persuratan DTEDI',
            $mail->envelope()->subject
        );

        $html = $mail->render();
        $text = view('emails.reset-token-text', [
            'code' => $mail->code,
            'expiryMinutes' => $mail->expiryMinutes,
        ])->render();

        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('123456', $body);
            $this->assertStringContainsString('10 menit', $body);
            $this->assertStringContainsString('Jangan membagikan kode', $body);
            $this->assertStringContainsString('Jika Anda tidak meminta reset kata sandi', $body);
            $this->assertStringNotContainsString('reset_token', $body);
            $this->assertStringNotContainsString('password', strtolower($body));
            $this->assertStringNotContainsString('debug', strtolower($body));
        }
    }

    private function requestCode(User $user, string $ip): string
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertExactJson([
                'message' => 'Jika email terdaftar, kode verifikasi telah dikirim.',
            ]);

        return $this->lastMailedCode($user->email);
    }

    private function lastMailedCode(string $email): string
    {
        $code = null;
        Mail::assertSent(ResetPasswordTokenMail::class, function (ResetPasswordTokenMail $mail) use ($email, &$code) {
            if ($mail->hasTo($email)) {
                $code = $mail->code;
                return true;
            }

            return false;
        });

        $this->assertNotNull($code);

        return $code;
    }

    private function verifyCode(User $user, string $code): string
    {
        $response = $this->postJson('/api/verify-token', [
            'email' => $user->email,
            'token' => $code,
        ]);

        $response->assertOk()->assertJsonStructure(['reset_token']);

        return $response->json('reset_token');
    }
}
