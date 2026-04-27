<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\ResetPasswordTokenMail;
use App\Enums\UserStatus;

class AuthController extends Controller
{
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

        // Track login activity (NOT status — status is lifecycle only)
        $user->last_login_at = now();
        $user->save();

        // Hapus token lama agar tidak numpuk
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'needs_completion' => $user->status === UserStatus::PendingProfile,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'sub_role' => $user->sub_role,
                'role_level' => $user->role_level,
                'status' => $user->status,
                'assigned_tasks' => $user->assigned_tasks,
            ],
        ], 200);
    }

    /**
     * Step 1: Request Token
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = trim($request->email);

        // Check if user exists manually
        $userExists = DB::table('users')->where('email', $email)->exists();
        if (!$userExists) {
            return response()->json([
                'message' => 'Email tidak valid'
            ], 400);
        }

        // Gunakan RateLimiter bawaan Laravel agar user bisa request pertama (di halaman forgot)
        // lalu request kedua (di halaman verifikasi) secara instan, baru delay 1 menit setelahnya.
        $key = 'resend_token_' . $email;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 2)) {
            $secondsLeft = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
            return response()->json([
                'message' => 'Tunggu 1 menit untuk mengirim ulang token',
                'seconds_left' => $secondsLeft
            ], 429);
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 60);

        // Hapus limitasi manual dengan created_at agar tidak memblokir percobaan ke-2 (kirim ulang pertama)
        $existingToken = DB::table('password_reset_tokens')->where('email', $email)->first();

        // Generate 6-digit token
        $token = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Save to database
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($token),
                'created_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addMinutes(15),
                'is_verified' => false
            ]
        );

        // Send real email
        try {
            Mail::to($email)->send(new ResetPasswordTokenMail($token));
        } catch (\Exception $e) {
            // Log the error but don't show to user to avoid 500
            \Log::error('Mail sending failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Token berhasil dibuat, tetapi gagal mengirim email. Pastikan pengaturan SMTP di .env sudah benar.',
                'token_simulation' => $token // Simulation for testing if SMTP fails
            ], 200);
        }

        return response()->json([
            'message' => 'Token reset password telah dikirim ke email Anda.',
            'token_simulation' => config('mail.default') === 'log' ? $token : null
        ], 200);
    }

    /**
     * Step 2: Verify Token
     */
    public function verifyToken(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'token' => 'required|string|size:6',
        ]);

        $reset = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$reset || !Hash::check($request->token, $reset->token)) {
            return response()->json(['message' => 'Token tidak valid'], 400);
        }

        if (Carbon::parse($reset->expires_at)->isPast()) {
            return response()->json(['message' => 'Token telah kedaluwarsa'], 400);
        }

        // Mark as verified
        DB::table('password_reset_tokens')->where('email', $request->email)->update([
            'is_verified' => true
        ]);

        return response()->json(['message' => 'Token berhasil diverifikasi'], 200);
    }

    /**
     * Step 3: Reset Password
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'password' => 'required|min:8|confirmed',
        ]);

        $reset = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$reset || !$reset->is_verified) {
            return response()->json(['message' => 'Silakan verifikasi token terlebih dahulu'], 400);
        }

        if (Carbon::parse($reset->expires_at)->isPast()) {
            return response()->json(['message' => 'Sesi reset password telah kedaluwarsa'], 400);
        }

        // Update password
        $user = \App\Models\User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'message' => 'Password berhasil direset. Silakan login kembali.',
        ], 200);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // Logout: revoke token only — do NOT change status
        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil',
        ]);
    }
}
