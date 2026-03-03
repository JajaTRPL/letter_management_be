<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Periksa apakah role user sesuai dengan yang diizinkan.
     *
     * Cara pakai di routes:
     *   ->middleware('role:admin')
     *   ->middleware('role:admin,super_admin')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles)) {
            return response()->json([
                'message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses halaman ini.',
            ], 403);
        }

        return $next($request);
    }
}
