<?php

namespace App\Http\Middleware;

use App\Support\AuthTokenAbilities;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordRotationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (
            !$token instanceof PersonalAccessToken
            || $token->abilities !== AuthTokenAbilities::PASSWORD_ROTATION_ONLY
            || !$token->expires_at
            || $token->expires_at->isPast()
        ) {
            return response()->json([
                'success' => false,
                'code' => 'ROTATION_TOKEN_REQUIRED',
                'message' => 'Token penggantian kata sandi diperlukan.',
            ], 403);
        }

        return $next($request);
    }
}
