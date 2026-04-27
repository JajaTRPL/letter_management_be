<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileComplete
{
    /**
     * Routes that pending_profile users CAN access.
     */
    private const ALLOWED_ROUTES = [
        'api/auth/complete-profile',
        'api/logout',
        'api/me',
        'api/study-programs-grouped',
        'api/departments',
        'api/faculties',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user &&
            $user->role === 'mahasiswa' &&
            (
                !$user->study_program_id ||
                !$user->mahasiswaProfile ||
                !$user->mahasiswaProfile->nim
            )
        ) {
            // Allow specific routes needed for profile completion
            $path = $request->path();
            foreach (self::ALLOWED_ROUTES as $allowed) {
                if (str_starts_with($path, $allowed)) {
                    return $next($request);
                }
            }

            return response()->json([
                'message' => 'Profil belum lengkap. Silakan lengkapi NIM dan Program Studi.',
                'requires_completion' => true,
            ], 403);
        }

        return $next($request);
    }
}
