<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Enums\UserStatus;

class CheckUserStatus
{
    /**
     * Defense-in-depth: reject any authenticated request from a Suspended user.
     * This catches cases where a token wasn't properly revoked during suspension.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status === UserStatus::Suspended) {
            // Force-revoke any remaining tokens
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Akun Anda telah disuspend. Silakan hubungi admin.',
            ], 403);
        }

        return $next($request);
    }
}
