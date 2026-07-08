<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\CheckRole;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => CheckRole::class,
            'primary_admin' => \App\Http\Middleware\CheckPrimarySuperAdmin::class,
            'check_status' => \App\Http\Middleware\CheckUserStatus::class,
            'password_rotation_satisfied' => \App\Http\Middleware\EnsurePasswordRotationSatisfied::class,
            'password_rotation_token' => \App\Http\Middleware\EnsurePasswordRotationToken::class,
            'profile_complete' => \App\Http\Middleware\EnsureProfileComplete::class,
        ]);

        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : '/login',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, \Throwable $e): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
