<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\MahasiswaProfile;
use App\Models\Department;
use App\Models\StudyProgram;
use App\Helpers\NimHelper;
use App\Enums\UserStatus;
use App\Services\ProfileCompletionService;
use App\Support\AuthTokenAbilities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class GoogleAuthController extends Controller
{
    /**
     * Allowed email domains for Google login.
     */
    private const ALLOWED_DOMAINS = ['mail.ugm.ac.id', 'ugm.ac.id'];

    /**
     * Only the UGM student domain may create a new Mahasiswa account.
     * Staff-domain accounts must be pre-provisioned by Super Admin.
     */
    private const STUDENT_SELF_REGISTRATION_DOMAIN = 'mail.ugm.ac.id';

    public function __construct(
        private ProfileCompletionService $profileCompletionService
    ) {
    }

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

        $email         = isset($payload['email']) ? strtolower(trim((string) $payload['email'])) : null;
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
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        if (!in_array($domain, self::ALLOWED_DOMAINS, true)) {
            return response()->json([
                'message' => 'Hanya email @mail.ugm.ac.id dan @ugm.ac.id yang diizinkan.',
            ], 403);
        }

        // Lookup by email (primary identifier) — NOT by google_id.
        // Case-insensitive: the verified Google email is normalized to lowercase,
        // but a pre-provisioned account may have been stored with mixed case by an
        // admin. Mirrors the case-insensitive lookup used by password login
        // (AuthController), so a matching account is found rather than wrongly
        // rejected as "belum terdaftar" or duplicated as a new mahasiswa.
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
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
            if ($domain !== self::STUDENT_SELF_REGISTRATION_DOMAIN) {
                return response()->json([
                    'message' => 'Akun belum terdaftar. Silakan hubungi Super Admin.',
                ], 403);
            }

            // Auto-create: new mahasiswa with pending_profile
            $user = User::create([
                'name'       => $name,
                'email'      => $email,
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

        $completion = $this->profileCompletionService->synchronizeStatus($user);
        $token = $user->createToken(
            'auth_token',
            AuthTokenAbilities::GOOGLE_FULL_ACCESS,
        )->plainTextToken;

        return response()->json([
            'message'          => $isNewUser ? 'Akun berhasil dibuat' : 'Login berhasil',
            'token'            => $token,
            'needs_completion' => $completion['needs_completion'],
            'completion'       => $completion,
            'user' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'role'       => $user->role,
                'sub_role'   => $user->sub_role,
                'tendik_role' => $user->tendik_role,
                'role_level' => $user->role_level,
                'status'     => $user->status,
                'avatar_url' => $user->avatar_url,
            ],
        ]);
    }

    /**
     * Complete only the missing, role-appropriate onboarding fields.
     */
    public function completeProfile(Request $request)
    {
        $user = $request->user();
        $completion = $this->profileCompletionService->status($user);

        if (!$completion['needs_completion']) {
            return response()->json(['message' => 'Profil sudah lengkap.'], 400);
        }

        if (!$completion['can_self_complete']) {
            return response()->json([
                'message' => $completion['message'],
                'completion' => $completion,
            ], 422);
        }

        $fields = $completion['fields'];
        $profileId = $user->mahasiswaProfile?->id;
        $input = $request->all();
        if (in_array('nim', $fields, true)) {
            $input['nim'] = NimHelper::normalize($request->input('nim'));
        }
        if (in_array('nip', $fields, true)) {
            $input['nip'] = trim((string) $request->input('nip'));
        }

        $rules = [
            'role' => 'prohibited',
            'sub_role' => 'prohibited',
            'tendik_role' => 'prohibited',
            'laboratory_id' => 'prohibited',
            'role_level' => 'prohibited',
            'status' => 'prohibited',
            'assigned_tasks' => 'prohibited',
            'email' => 'prohibited',
            'google_id' => 'prohibited',
            'nim' => in_array('nim', $fields, true)
                ? [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('mahasiswa_profiles', 'nim')->ignore($profileId),
                ]
                : 'prohibited',
            'nip' => in_array('nip', $fields, true)
                ? [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('users', 'nip')->ignore($user->id),
                ]
                : 'prohibited',
            'study_program_id' => in_array('study_program_id', $fields, true)
                ? ['required', 'integer']
                : 'prohibited',
            'department_id' => in_array('department_id', $fields, true)
                ? ['required', 'integer']
                : 'prohibited',
        ];

        $validator = Validator::make($input, $rules, [
            'nim.required' => 'NIM wajib diisi.',
            'nim.unique' => 'NIM sudah terdaftar di sistem.',
            'nip.required' => 'NIP wajib diisi.',
            'nip.unique' => 'NIP sudah terdaftar di sistem.',
            'study_program_id.required' => 'Program Studi wajib dipilih.',
            'department_id.required' => 'Departemen wajib dipilih.',
            '*.prohibited' => 'Field :attribute tidak boleh diubah melalui pelengkapan profil.',
        ]);

        $validator->after(function ($validator) use ($input, $fields) {
            if (
                in_array('nim', $fields, true)
                && !empty($input['nim'])
                && !NimHelper::validate($input['nim'])
            ) {
                $validator->errors()->add('nim', 'Format NIM tidak valid.');
            }

            if (
                in_array('study_program_id', $fields, true)
                && !empty($input['study_program_id'])
                && !StudyProgram::runtimeVisible()->whereKey($input['study_program_id'])->exists()
            ) {
                $validator->errors()->add('study_program_id', 'Program Studi tidak ditemukan.');
            }

            if (
                in_array('department_id', $fields, true)
                && !empty($input['department_id'])
                && !Department::runtimeVisible()->whereKey($input['department_id'])->exists()
            ) {
                $validator->errors()->add('department_id', 'Departemen tidak ditemukan.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        DB::transaction(function () use ($validated, $user, $fields) {
            $updates = [];

            if (in_array('nip', $fields, true)) {
                $updates['nip'] = $validated['nip'];
            }

            if (in_array('study_program_id', $fields, true)) {
                $program = StudyProgram::runtimeVisible()
                    ->whereKey($validated['study_program_id'])
                    ->firstOrFail();
                $updates['study_program_id'] = $program->id;
                $updates['department_id'] = $program->department_id;
            }

            if (in_array('department_id', $fields, true)) {
                $updates['department_id'] = $validated['department_id'];
                $updates['study_program_id'] = null;
            }

            if ($updates !== []) {
                $user->update($updates);
            }

            if (in_array('nim', $fields, true)) {
                MahasiswaProfile::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'nim' => $validated['nim'],
                        'data_source' => $user->mahasiswaProfile?->data_source ?? 'google_sync',
                    ]
                );
            }
        });

        $user->refresh();
        $completion = $this->profileCompletionService->synchronizeStatus($user);

        if ($completion['needs_completion']) {
            return response()->json([
                'message' => $completion['message'],
                'completion' => $completion,
            ], 422);
        }

        return response()->json([
            'message' => 'Profil berhasil dilengkapi',
            'completion' => $completion,
            'user' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'role'       => $user->role,
                'sub_role'   => $user->sub_role,
                'tendik_role' => $user->tendik_role,
                'role_level' => $user->role_level,
                'status'     => UserStatus::Active,
            ],
        ]);
    }

    public function profileCompletionStatus(Request $request)
    {
        return response()->json([
            'completion' => $this->profileCompletionService->status($request->user()),
        ]);
    }

    /**
     * Accepted issuer values for a Google-signed OpenID Connect ID token.
     *
     * @see https://developers.google.com/identity/openid-connect/openid-connect#validatinganidtoken
     */
    private const ALLOWED_ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    /**
     * Verify Google ID token via Google's tokeninfo endpoint.
     *
     * The tokeninfo endpoint validates the signature and expiry server-side (a
     * non-successful response means the token is invalid/expired). On top of that
     * we explicitly assert the two claims that bind the token to THIS app and to
     * Google as the issuer:
     *  - 'aud' must match our configured GOOGLE_CLIENT_ID (prevents token reuse from
     *    another Google app);
     *  - 'iss' must be a Google issuer (defense-in-depth: the check no longer relies
     *    solely on the transport being tokeninfo, so it stays correct even if the
     *    verification mechanism is later swapped for local JWK validation).
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

            // CRITICAL: Verify the issuer is Google.
            if (!in_array($payload['iss'] ?? '', self::ALLOWED_ISSUERS, true)) {
                \Log::warning('Google auth: unexpected issuer', [
                    'got' => $payload['iss'] ?? 'none',
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
