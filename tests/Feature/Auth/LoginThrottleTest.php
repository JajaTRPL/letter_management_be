<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Login protection is a TEMPORARY brute-force throttle scoped to (email + IP) —
 * never an IP-wide ban and never an account lockout. A normal mistake stays
 * recoverable, a suspended/non-UGM rejection is a clear 403 (not a fake "wrong
 * password"), and the throttle resets on its own.
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // A tiny limit keeps the test fast + deterministic; production default is 10.
        config(['auth.login_throttle_per_minute' => 2]);
    }

    public function test_throttle_is_per_email_and_ip_not_a_broad_ip_ban(): void
    {
        $user = $this->activeUser();
        $wrong = fn (string $email) => $this->postJson('/api/login', ['email' => $email, 'password' => 'nope']);

        // Two wrong attempts on your own account are fine — a normal mistake.
        $wrong($user->email)->assertStatus(401);
        $wrong($user->email)->assertStatus(401);

        // The third trips a TEMPORARY, clearly-communicated throttle — with a
        // recoverable countdown, and never the misleading "wrong password".
        $throttled = $wrong($user->email)->assertStatus(429);
        $this->assertStringContainsString('Terlalu banyak percobaan masuk', (string) $throttled->json('message'));
        $this->assertStringNotContainsString('password salah', (string) $throttled->json('message'));
        $this->assertIsInt($throttled->json('seconds_left'));

        // A DIFFERENT account from the SAME IP is untouched — no IP-wide lock.
        $this->postJson('/api/login', ['email' => $this->activeUser()->email, 'password' => 'nope'])
            ->assertStatus(401);
    }

    public function test_throttle_is_temporary_and_resets_on_its_own(): void
    {
        $user = $this->activeUser();
        $attempt = fn () => $this->postJson('/api/login', ['email' => $user->email, 'password' => 'nope']);

        $attempt();
        $attempt();
        $attempt()->assertStatus(429);

        // No lock: after the window passes the same account can try again.
        $this->travel(61)->seconds();
        $attempt()->assertStatus(401);
    }

    public function test_correct_password_within_the_limit_still_logs_in(): void
    {
        $user = $this->activeUser('Rahasia-Kuat-123');

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'salah'])->assertStatus(401);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Rahasia-Kuat-123'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_wrong_password_never_locks_or_suspends_the_account(): void
    {
        $user = $this->activeUser();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'salah'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Email atau password salah');

        // The account is not mutated into any locked/suspended state.
        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_suspended_account_is_a_clear_403_not_a_wrong_password(): void
    {
        $user = $this->activeUser('Rahasia-Kuat-123', ['status' => UserStatus::Suspended]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Rahasia-Kuat-123'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Akun Anda telah disuspend. Silakan hubungi admin.');
    }

    public function test_non_ugm_google_login_is_a_clear_403_not_a_lock(): void
    {
        config(['services.google.client_id' => 'test-client-id']);
        $this->fakeGoogleTokens([
            'outsider-token' => ['email' => 'someone@gmail.com', 'sub' => 'google-outsider'],
        ]);

        $this->postJson('/api/auth/google', ['credential' => 'outsider-token'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Hanya email @mail.ugm.ac.id dan @ugm.ac.id yang diizinkan.');

        // A rejected outsider creates no account and no lock.
        $this->assertDatabaseMissing('users', ['email' => 'someone@gmail.com']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function activeUser(string $password = 'Rahasia-Kuat-123', array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatus::Active,
            'password' => Hash::make($password),
            'password_must_rotate' => false,
        ], $attributes));
    }

    /** @param array<string, array<string, mixed>> $tokens */
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
}
