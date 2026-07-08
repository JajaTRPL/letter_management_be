<?php

namespace App\Providers;

use App\Services\DocumentConverter;
use App\Services\GotenbergDocumentConverter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Phase 1 foundation: bind the DocumentConverter interface to the
        // configured driver. Today only Gotenberg is implemented; future
        // drivers (e.g. direct LibreOffice subprocess) plug in here without
        // changing call sites. Workflow controllers do not call this in
        // Phase 1 — wiring lands in Phase 2.
        $this->app->singleton(DocumentConverter::class, function ($app) {
            $driver = config('document_converter.driver', 'gotenberg');

            return match ($driver) {
                'gotenberg' => new GotenbergDocumentConverter(
                    $app->make(HttpFactory::class),
                    (string) config('document_converter.url', 'http://localhost:3000'),
                    (int) config('document_converter.timeout_seconds', 60),
                    (int) config('document_converter.connect_timeout_seconds', 5),
                ),
                default => throw new \RuntimeException(
                    "Unknown document_converter.driver: {$driver}",
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(100)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('password-rotation', function (Request $request) {
            $identity = $request->user()?->getAuthIdentifier() ?: 'guest';

            return Limit::perMinute(
                max(1, (int) config('password_rotation.max_attempts_per_minute', 6))
            )->by($identity.'|'.$request->ip());
        });

        RateLimiter::for('peminjaman-attachment', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();
            $identity = $userId ? $userId.'|'.$request->ip() : $request->ip();

            return Limit::perMinute(30)->by($identity);
        });

        // Room management (CP2): separate buckets per concern so photo
        // browsing can never starve management mutations and vice versa.
        $roomLimiterIdentity = function (Request $request): string {
            $userId = $request->user()?->getAuthIdentifier();

            return $userId ? $userId.'|'.$request->ip() : $request->ip();
        };

        RateLimiter::for('room-media-upload', fn (Request $request) => Limit::perMinute(20)->by($roomLimiterIdentity($request)));
        RateLimiter::for('room-media-view', fn (Request $request) => Limit::perMinute(120)->by($roomLimiterIdentity($request)));
        RateLimiter::for('room-template', fn (Request $request) => Limit::perMinute(30)->by($roomLimiterIdentity($request)));
        RateLimiter::for('room-manage', fn (Request $request) => Limit::perMinute(30)->by($roomLimiterIdentity($request)));
    }
}
