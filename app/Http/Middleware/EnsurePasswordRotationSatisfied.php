<?php

namespace App\Http\Middleware;

use App\Support\AuthTokenAbilities;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordRotationSatisfied
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        // Stateful authentication and Sanctum::actingAs test tokens do not
        // expose persisted abilities. Preserve existing access while no
        // rotation is required, but fail closed when the account is flagged.
        if (!$token instanceof PersonalAccessToken || !$token->exists) {
            return $user->password_must_rotate
                ? $this->rotationRequired()
                : $next($request);
        }

        $abilities = array_values(array_filter(
            $token->abilities ?? [],
            static fn (mixed $ability): bool => is_string($ability)
        ));
        $hasAppAccess = in_array(AuthTokenAbilities::APP_ACCESS, $abilities, true);
        $hasGoogleProvenance = in_array(AuthTokenAbilities::AUTH_GOOGLE, $abilities, true);
        $hasRotationAbility = in_array(AuthTokenAbilities::PASSWORD_ROTATE, $abilities, true);
        $isLegacyWildcard = in_array('*', $abilities, true);

        if ($hasRotationAbility && !$hasAppAccess) {
            return $this->rotationRequired();
        }

        if ($user->password_must_rotate) {
            return $hasAppAccess && $hasGoogleProvenance
                ? $next($request)
                : $this->rotationRequired();
        }

        if ($hasAppAccess || $isLegacyWildcard) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'code' => 'TOKEN_ABILITY_REQUIRED',
            'message' => 'Token tidak memiliki izin untuk mengakses layanan ini.',
        ], 403);
    }

    private function rotationRequired(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'PASSWORD_ROTATION_REQUIRED',
            'message' => 'Untuk keamanan akun, ganti kata sandi sebelum melanjutkan.',
        ], 423);
    }
}
