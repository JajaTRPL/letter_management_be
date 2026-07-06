<?php

namespace App\Http\Controllers;

use App\Services\PasswordCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordRotationController extends Controller
{
    public function __construct(
        private PasswordCredentialService $passwordCredentials,
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $expiresAt = $token instanceof PersonalAccessToken
            ? $token->expires_at
            : null;

        return response()->json([
            'success' => true,
            'code' => 'PASSWORD_ROTATION_REQUIRED',
            'message' => 'Untuk keamanan akun, Anda perlu mengganti kata sandi sebelum menggunakan sistem.',
            'expires_at' => $expiresAt?->toIso8601String(),
            'expires_in' => $expiresAt
                ? max(0, (int) now()->diffInSeconds($expiresAt, false))
                : null,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => [
                'required',
                'confirmed',
                Password::min(10)->mixedCase()->letters()->numbers()->symbols(),
            ],
        ]);

        $completed = $this->passwordCredentials->completeRequiredRotation(
            $request->user(),
            $validated['password'],
        );

        if (!$completed) {
            return response()->json([
                'success' => false,
                'code' => 'PASSWORD_ROTATION_NOT_REQUIRED',
                'message' => 'Penggantian kata sandi wajib tidak lagi diperlukan.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Kata sandi berhasil diperbarui. Silakan login kembali.',
        ]);
    }
}
