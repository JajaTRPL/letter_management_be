<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\MahasiswaProfile;
use App\Helpers\NimHelper;
use App\Enums\UserStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class GoogleAuthController extends Controller
{
    /**
     * Allowed email domains for Google login.
     */
    private const ALLOWED_DOMAINS = ['mail.ugm.ac.id', 'ugm.ac.id'];

    /**
     * Handle Google login via ID token (credential).
     *
     * Strategy: ID token only (verified via Google's tokeninfo endpoint).
     * The frontend obtains the credential via google.accounts.id (One Tap / Sign-In button).
     * The audience (aud) is checked against GOOGLE_CLIENT_ID to prevent token reuse from other apps.
     */
    public function login(Request $request)
    {
        $request->validate([
            'credential' => 'required|string',
        ]);

        $payload = $this->verifyIdToken($request->credential);

        if (!$payload) {
            return response()->json([
                'message' => 'Token Google tidak valid atau sudah kedaluwarsa.',
            ], 401);
        }

        $email         = $payload['email'] ?? null;
        $googleId      = $payload['sub'] ?? null;
        $name          = $payload['name'] ?? ($payload['given_name'] ?? 'User');
        $avatar        = $payload['picture'] ?? null;
        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$email || !$emailVerified) {
            return response()->json([
                'message' => 'Email Google tidak terverifikasi.',
            ], 401);
        }

        // Domain restriction
        $domain = substr($email, strpos($email, '@') + 1);
        if (!in_array(strtolower($domain), self::ALLOWED_DOMAINS)) {
            return response()->json([
                'message' => 'Hanya email @mail.ugm.ac.id dan @ugm.ac.id yang diizinkan.',
            ], 403);
        }

        // Lookup by email (primary identifier) — NOT by google_id
        $user = User::where('email', strtolower($email))->first();
        $isNewUser = false;

        if ($user) {
            // Suspended check
            if ($user->status === UserStatus::Suspended) {
                return response()->json([
                    'message' => 'Akun Anda telah disuspend. Silakan hubungi admin.',
                ], 403);
            }

            // Silent auto-link: attach google_id if not yet set
            if (!$user->google_id) {
                $user->google_id  = $googleId;
                $user->avatar_url = $avatar;
                $user->save();
            }
        } else {
            // Auto-create: new mahasiswa with pending_profile
            $user = User::create([
                'name'       => $name,
                'email'      => strtolower($email),
                'google_id'  => $googleId,
                'avatar_url' => $avatar,
                'password'   => null,
                'role'       => 'mahasiswa',
                'status'     => UserStatus::PendingProfile,
            ]);

            MahasiswaProfile::create([
                'user_id'     => $user->id,
                'data_source' => 'google_sync',
            ]);

            $isNewUser = true;
        }

        $user->tokens()->delete();

        // Track login activity
        if (!$isNewUser) {
            $user->last_login_at = now();
            $user->save();
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'          => $isNewUser ? 'Akun berhasil dibuat' : 'Login berhasil',
            'token'            => $token,
            'needs_completion' => $user->status === UserStatus::PendingProfile,
            'user' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'role'       => $user->role,
                'sub_role'   => $user->sub_role,
                'role_level' => $user->role_level,
                'status'     => $user->status,
                'avatar_url' => $user->avatar_url,
            ],
        ]);
    }

    /**
     * Complete profile for pending_profile users.
     * Required: nim, study_program_id.
     */
    public function completeProfile(Request $request)
    {
        $user = $request->user();

        if ($user->status !== UserStatus::PendingProfile) {
            return response()->json(['message' => 'Profil sudah lengkap.'], 400);
        }

        $validator = Validator::make($request->all(), [
            'nim'              => 'required|string|unique:mahasiswa_profiles,nim,' . ($user->mahasiswaProfile?->id ?? 'NULL') . ',id',
            'study_program_id' => 'required|exists:study_programs,id',
        ], [
            'nim.required'              => 'NIM wajib diisi.',
            'nim.unique'                => 'NIM sudah terdaftar di sistem.',
            'study_program_id.required' => 'Program Studi wajib dipilih.',
            'study_program_id.exists'   => 'Program Studi tidak ditemukan.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user->update([
            'study_program_id' => $request->study_program_id,
            'status'           => UserStatus::Active,
        ]);

        MahasiswaProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'nim'         => NimHelper::normalize($request->nim),
                'data_source' => $user->mahasiswaProfile?->data_source ?? 'google_sync',
            ]
        );

        return response()->json([
            'message' => 'Profil berhasil dilengkapi',
            'user' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'role'       => $user->role,
                'sub_role'   => $user->sub_role,
                'role_level' => $user->role_level,
                'status'     => UserStatus::Active,
            ],
        ]);
    }

    /**
     * Verify Google ID token via Google's tokeninfo endpoint.
     *
     * Security: Checks that 'aud' matches our configured GOOGLE_CLIENT_ID.
     * This prevents tokens issued for other Google apps from being accepted.
     */
    private function verifyIdToken(string $idToken): ?array
    {
        try {
            $response = Http::timeout(5)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $payload = $response->json();

            // CRITICAL: Verify audience matches our client ID
            $clientId = config('services.google.client_id');
            if (!$clientId || ($payload['aud'] ?? '') !== $clientId) {
                \Log::warning('Google auth: aud mismatch or missing client_id', [
                    'expected' => $clientId,
                    'got'      => $payload['aud'] ?? 'none',
                ]);
                return null;
            }

            return $payload;
        } catch (\Exception $e) {
            \Log::error('Google token verification failed: ' . $e->getMessage());
            return null;
        }
    }
}
