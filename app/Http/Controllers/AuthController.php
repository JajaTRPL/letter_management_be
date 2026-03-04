<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        // Cek jika user diblokir
        if ($user->status === 'Blocked') {
            Auth::logout();
            return response()->json([
                'message' => 'Akun Anda telah diblokir. Silakan hubungi admin.',
            ], 403);
        }

        // Ubah status jadi Active
        $user->status = 'Active';
        $user->save();

        // Catat Log Login
        \App\Models\LoginLog::create([
            'user_id' => $user->id
        ]);

        // Hapus token lama agar tidak numpuk
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
            ],
        ], 200);
    }

    /**
     * Reset password publik (lupa password).
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'password' => 'required|min:8|confirmed',
        ]);

        $user = \App\Models\User::where('email', $request->email)->first();
        $user->password = \Illuminate\Support\Facades\Hash::make($request->password);
        $user->save();

        return response()->json([
            'message' => 'Password berhasil direset. Silakan login kembali.',
        ], 200);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // Ubah status jadi Inactive
        $user->status = 'Inactive';
        $user->save();

        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil',
        ]);
    }
}
