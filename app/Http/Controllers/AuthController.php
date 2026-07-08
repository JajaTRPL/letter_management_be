<?php

namespace App\Http\Controllers;

use App\Enums\UserStatus;
use App\Enums\PasswordSetMethod;
use App\Mail\ResetPasswordTokenMail;
use App\Models\User;
use App\Services\ProfileCompletionService;
use App\Services\PasswordCredentialService;
use App\Support\AuthTokenAbilities;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AuthController extends Controller
{
    private const RESET_REQUEST_MESSAGE = 'Jika email terdaftar, kode verifikasi telah dikirim.';

    public function __construct(
        private ProfileCompletionService $profileCompletionService,
        private PasswordCredentialService $passwordCredentials,
    ) {
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Email atau password salah',
            ], 401);
        }

        $user = Auth::user();

        // Deny login if account is suspended
        if ($user->status === UserStatus::Suspended) {
            Auth::logout();
            return response()->json([
                'message' => 'Akun Anda telah disuspend. Silakan hubungi admin.',
            ], 403);
        }

        if ($this->usesLegacyPredictableStudentPassword($user, $request->string('password')->toString())) {
            Auth::logout();

            return response()->json([
                'message' => 'Password awal tidak lagi berlaku. Gunakan Google UGM atau Lupa Kata Sandi.',
            ], 401);
        }

        if ($user->password_must_rotate) {
            Auth::logout();
            $this->passwordCredentials->revokeAccess($user);

            $expiryMinutes = max(1, (int) config('password_rotation.token_expiry_minutes', 15));
            $expiresAt = now()->addMinutes($expiryMinutes);
            $rotationToken = $user->createToken(
                'password_rotation',
                AuthTokenAbilities::PASSWORD_ROTATION_ONLY,
                $expiresAt,
            )->plainTextToken;

            return response()->json([
                'success' => false,
                'code' => 'PASSWORD_ROTATION_REQUIRED',
                'message' => 'Untuk keamanan akun, Anda perlu mengganti kata sandi sebelum menggunakan sistem.',
                'rotation_token' => $rotationToken,
                'expires_in' => $expiryMinutes * 60,
            ], 423);
        }

        // Track login activity (NOT status — status is lifecycle only)
        $user->last_login_at = now();
        $user->save();
        $completion = $this->profileCompletionService->synchronizeStatus($user);

        // Hapus token lama agar tidak numpuk
        $user->tokens()->delete();

        $token = $user->createToken(
            'auth_token',
            AuthTokenAbilities::LOCAL_FULL_ACCESS,
        )->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'needs_completion' => $completion['needs_completion'],
            'completion' => $completion,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'sub_role' => $user->sub_role,
                'tendik_role' => $user->tendik_role,
                'role_level' => $user->role_level,
                'status' => $user->status,
                'assigned_tasks' => $user->assigned_tasks,
            ],
        ], 200);
    }

    /**
     * Step 1: Request a short-lived email OTP.
     */
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $email = $this->normalizeEmail($validated['email']);
        $rateLimit = $this->consumeResetRequestRateLimits($email, (string) $request->ip());

        if ($rateLimit !== null) {
            return response()->json([
                'message' => 'Terlalu banyak permintaan. Silakan coba lagi nanti.',
                'seconds_left' => $rateLimit,
            ], 429);
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        // Unknown and ineligible accounts deliberately follow the same response path.
        if (!$user || $user->status !== UserStatus::Active) {
            return response()->json(['message' => self::RESET_REQUEST_MESSAGE]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = Hash::make($code);
        $now = now();
        $expiryMinutes = (int) config('password_reset.otp_expiry_minutes', 10);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => $codeHash,
                'created_at' => $now,
                'expires_at' => $now->copy()->addMinutes($expiryMinutes),
                'is_verified' => false,
                'attempts' => 0,
                'verified_at' => null,
                'reset_token' => null,
                'reset_token_expires_at' => null,
                'used_at' => null,
            ]
        );

        try {
            Mail::to($user->email)->send(new ResetPasswordTokenMail($code, $expiryMinutes));
        } catch (Throwable $exception) {
            Log::warning('Password reset email delivery failed.', [
                'email_hash' => hash('sha256', $email),
                'exception' => $exception::class,
            ]);

            if (!$this->mayExposeSimulationCode()) {
                DB::table('password_reset_tokens')
                    ->where('email', $email)
                    ->where('token', $codeHash)
                    ->update(['used_at' => now()]);
            }
        }

        $response = ['message' => self::RESET_REQUEST_MESSAGE];
        if ($this->mayExposeSimulationCode()) {
            $response['token_simulation'] = $code;
        }

        return response()->json($response);
    }

    /**
     * Step 2: Exchange a valid one-time OTP for a short-lived reset token.
     */
    public function verifyToken(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'token' => 'required|digits:6',
        ]);

        $email = $this->normalizeEmail($validated['email']);
        $maxAttempts = (int) config('password_reset.max_attempts', 5);

        $result = DB::transaction(function () use ($email, $validated, $maxAttempts) {
            $reset = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if (!$reset || $reset->used_at || $reset->is_verified) {
                return [
                    'status' => 422,
                    'message' => 'Kode verifikasi tidak valid atau sudah digunakan.',
                ];
            }

            if (!$reset->expires_at || Carbon::parse($reset->expires_at)->isPast()) {
                return [
                    'status' => 422,
                    'message' => 'Kode verifikasi telah kedaluwarsa. Silakan minta kode baru.',
                ];
            }

            if ((int) $reset->attempts >= $maxAttempts) {
                return [
                    'status' => 429,
                    'message' => 'Terlalu banyak percobaan. Silakan minta kode baru.',
                ];
            }

            if (!Hash::check($validated['token'], $reset->token)) {
                $attempts = (int) $reset->attempts + 1;
                DB::table('password_reset_tokens')
                    ->where('email', $email)
                    ->update(['attempts' => $attempts]);

                return [
                    'status' => $attempts >= $maxAttempts ? 429 : 422,
                    'message' => $attempts >= $maxAttempts
                        ? 'Terlalu banyak percobaan. Silakan minta kode baru.'
                        : 'Kode verifikasi tidak valid.',
                ];
            }

            $resetToken = Str::random(64);
            $now = now();

            DB::table('password_reset_tokens')
                ->where('email', $email)
                ->update([
                    'is_verified' => true,
                    'verified_at' => $now,
                    'reset_token' => hash('sha256', $resetToken),
                    'reset_token_expires_at' => $now->copy()->addMinutes(
                        (int) config('password_reset.reset_token_expiry_minutes', 10)
                    ),
                ]);

            return [
                'status' => 200,
                'message' => 'Kode berhasil diverifikasi.',
                'reset_token' => $resetToken,
            ];
        });

        return response()->json(
            array_filter([
                'message' => $result['message'],
                'reset_token' => $result['reset_token'] ?? null,
            ], static fn ($value) => $value !== null),
            $result['status']
        );
    }

    /**
     * Step 3: Rotate the credential and revoke existing access.
     */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'reset_token' => 'required|string|size:64',
            'password' => [
                'required',
                'confirmed',
                Password::min(10)->mixedCase()->letters()->numbers()->symbols(),
            ],
        ]);

        $email = $this->normalizeEmail($validated['email']);
        $result = DB::transaction(function () use ($email, $validated) {
            $reset = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            $providedTokenHash = hash('sha256', $validated['reset_token']);
            $validReset = $reset
                && !$reset->used_at
                && $reset->is_verified
                && $reset->reset_token
                && hash_equals($reset->reset_token, $providedTokenHash)
                && $reset->reset_token_expires_at
                && Carbon::parse($reset->reset_token_expires_at)->isFuture();

            if (!$validReset) {
                return [
                    'status' => 422,
                    'message' => 'Sesi reset kata sandi tidak valid atau telah kedaluwarsa.',
                ];
            }

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if (!$user || $user->status !== UserStatus::Active) {
                return [
                    'status' => 422,
                    'message' => 'Sesi reset kata sandi tidak valid atau telah kedaluwarsa.',
                ];
            }

            $this->passwordCredentials->fill(
                $user,
                $validated['password'],
                PasswordSetMethod::ResetPasswordOtp,
            );
            if (Schema::hasColumn('users', 'remember_token')) {
                $user->setRememberToken(Str::random(60));
            }
            $user->save();

            // Password reset is credential rotation: revoke bearer tokens and
            // every server-side database session associated with this account.
            $this->passwordCredentials->revokeAccess($user);

            DB::table('password_reset_tokens')
                ->where('email', $email)
                ->update([
                    'token' => Hash::make(Str::random(64)),
                    'expires_at' => now(),
                    'is_verified' => false,
                    'reset_token' => null,
                    'reset_token_expires_at' => null,
                    'used_at' => now(),
                ]);

            return [
                'status' => 200,
                'message' => 'Kata sandi berhasil direset. Silakan login kembali.',
            ];
        });

        return response()->json([
            'message' => $result['message'],
        ], $result['status']);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // Revoke the current bearer token or stateful web session only.
        // Account lifecycle status is never changed by logout.
        $token = $user?->currentAccessToken();

        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
        }

        return response()->json([
            'message' => 'Logout berhasil',
        ]);
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function consumeResetRequestRateLimits(string $email, string $ip): ?int
    {
        $emailHash = hash('sha256', $email);
        $ipHash = hash('sha256', $ip);
        $cooldownKey = "password-reset:cooldown:{$emailHash}:{$ipHash}";
        $emailKey = "password-reset:email:{$emailHash}";
        $ipKey = "password-reset:ip:{$ipHash}";

        $limits = [
            [$cooldownKey, 1],
            [$emailKey, (int) config('password_reset.email_max_requests', 5)],
            [$ipKey, (int) config('password_reset.ip_max_requests', 20)],
        ];

        $retryAfter = 0;
        foreach ($limits as [$key, $maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $retryAfter = max($retryAfter, RateLimiter::availableIn($key));
            }
        }

        if ($retryAfter > 0) {
            return $retryAfter;
        }

        RateLimiter::hit(
            $cooldownKey,
            (int) config('password_reset.resend_cooldown_seconds', 60)
        );
        RateLimiter::hit(
            $emailKey,
            (int) config('password_reset.request_window_seconds', 600)
        );
        RateLimiter::hit(
            $ipKey,
            (int) config('password_reset.request_window_seconds', 600)
        );

        return null;
    }

    private function mayExposeSimulationCode(): bool
    {
        return (bool) config('password_reset.simulation', false)
            && app()->environment('local');
    }

    private function usesLegacyPredictableStudentPassword(User $user, string $password): bool
    {
        if ($user->role !== 'mahasiswa') {
            return false;
        }

        $profile = $user->loadMissing('mahasiswaProfile')->mahasiswaProfile;
        $nim = preg_replace('/[^a-zA-Z0-9]/', '', (string) $profile?->nim);

        if ($nim === '') {
            return false;
        }

        $dateOfBirth = '';
        if ($profile?->tanggal_lahir) {
            $dateOfBirth = Carbon::parse($profile->tanggal_lahir)->format('dmY');
        }

        return hash_equals($nim.$dateOfBirth, $password);
    }
}
