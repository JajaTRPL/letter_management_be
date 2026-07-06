<?php

namespace App\Http\Middleware;

use Closure;
use App\Services\ProfileCompletionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileComplete
{
    public function __construct(
        private ProfileCompletionService $profileCompletionService
    ) {
    }

    /**
     * Routes that pending_profile users CAN access.
     */
    private const ALLOWED_ROUTES = [
        'api/auth/complete-profile',
        'api/auth/profile-completion',
        'api/logout',
        'api/me',
        'api/profile',
        'api/study-programs-grouped',
        'api/departments',
        'api/faculties',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $completion = $user
            ? $this->profileCompletionService->status($user)
            : ['needs_completion' => false];

        if ($user && $completion['needs_completion']) {
            // Allow specific routes needed for profile completion
            $path = $request->path();
            foreach (self::ALLOWED_ROUTES as $allowed) {
                if (str_starts_with($path, $allowed)) {
                    return $next($request);
                }
            }

            return response()->json([
                'message' => $completion['message'],
                'requires_completion' => true,
                'completion' => $completion,
            ], 403);
        }

        return $next($request);
    }
}
