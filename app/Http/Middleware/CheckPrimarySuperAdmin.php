<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPrimarySuperAdmin
{
    /**
     * Only allows Primary Super Admins through.
     */
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $user = auth()->user();

        if ($user->role !== 'super_admin' || $user->role_level !== 'primary') {
            return response()->json([
                'message' => 'Akses ditolak. Hanya Primary Super Admin yang dapat melakukan aksi ini.'
            ], 403);
        }

        return $next($request);
    }
}
