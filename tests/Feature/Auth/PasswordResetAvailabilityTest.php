<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Email-based password reset is an EMAIL-DEPENDENT feature. Without an
 * operational mail path it must not pretend to work (no dead ends), and it must
 * never hand a Google-only (password-less) account an OTP that would let it set
 * a password — a login path that account was never meant to have.
 */
class PasswordResetAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_reset_endpoints_report_a_clear_unavailable_state_when_disabled(): void
    {
        config(['password_reset.enabled' => false]);
        $user = $this->passwordUser();

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(503)
            ->assertJsonPath('code', 'PASSWORD_RESET_UNAVAILABLE')
            ->assertJsonPath('message', 'Reset kata sandi lewat email belum tersedia. Silakan masuk dengan akun Google UGM, atau hubungi admin jika perlu bantuan.');

        $this->postJson('/api/verify-token', ['email' => $user->email, 'token' => '123456'])
            ->assertStatus(503)->assertJsonPath('code', 'PASSWORD_RESET_UNAVAILABLE');

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'reset_token' => str_repeat('a', 64),
            'password' => 'Baru-Kuat-123',
            'password_confirmation' => 'Baru-Kuat-123',
        ])->assertStatus(503)->assertJsonPath('code', 'PASSWORD_RESET_UNAVAILABLE');

        // Nothing was sent and no token row was created.
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_public_config_reflects_reset_availability(): void
    {
        config(['password_reset.enabled' => false]);
        $this->getJson('/api/auth/config')->assertOk()->assertJsonPath('password_reset_enabled', false);

        config(['password_reset.enabled' => true]);
        $this->getJson('/api/auth/config')->assertOk()->assertJsonPath('password_reset_enabled', true);
    }

    public function test_disabling_gates_every_account_including_google_only(): void
    {
        // Setting a local password via VERIFIED reset is an intentional opt-in for
        // Google-only accounts (covered by ForgotPasswordHardeningTest). But when
        // the feature is turned off there is no dead end for ANYONE: a Google-only
        // account also gets the clear unavailable response, never a silent nothing.
        config(['password_reset.enabled' => false]);
        $googleOnly = User::factory()->create([
            'status' => UserStatus::Active,
            'password' => null,
            'role' => 'mahasiswa',
        ]);

        $this->postJson('/api/forgot-password', ['email' => $googleOnly->email])
            ->assertStatus(503)
            ->assertJsonPath('code', 'PASSWORD_RESET_UNAVAILABLE');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_a_password_account_still_gets_a_code_when_reset_is_enabled(): void
    {
        config(['password_reset.enabled' => true]);
        $staff = $this->passwordUser();

        $this->postJson('/api/forgot-password', ['email' => $staff->email])
            ->assertOk()
            ->assertJsonPath('message', 'Jika email terdaftar, kode verifikasi telah dikirim.');

        $this->assertDatabaseHas('password_reset_tokens', ['email' => strtolower($staff->email)]);
    }

    private function passwordUser(): User
    {
        return User::factory()->create([
            'status' => UserStatus::Active,
            'password' => Hash::make('Rahasia-Kuat-123'),
        ]);
    }
}
