<?php

namespace App\Providers;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\RoomBookingWorkflowEvent;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Observers\LetterApplicationNotificationObserver;
use App\Observers\RoomBookingWorkflowEventObserver;
use App\Services\Analytics\LetterReviewDurationCollector;
use App\Services\Analytics\ReviewDurationAggregator;
use App\Services\Analytics\ReviewPerformanceService;
use App\Services\Analytics\ReviewSampleConfidencePolicy;
use App\Services\Analytics\RoomBookingReviewDurationCollector;
use App\Services\DocumentConverter;
use App\Services\GotenbergDocumentConverter;
use App\Services\Notifications\WorkflowReviewSlaPolicyService;
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

        // Review-performance analytics: one collector per reviewable domain.
        // Adding a third domain later means writing one collector and adding it
        // to this tag — no other file changes, because everything downstream
        // works on ReviewDurationSample and knows nothing about the domains.
        $this->app->tag([
            LetterReviewDurationCollector::class,
            RoomBookingReviewDurationCollector::class,
        ], 'review-duration-collectors');

        $this->app->bind(ReviewPerformanceService::class, fn ($app) => new ReviewPerformanceService(
            $app->tagged('review-duration-collectors'),
            $app->make(ReviewDurationAggregator::class),
            $app->make(ReviewSampleConfidencePolicy::class),
            $app->make(WorkflowReviewSlaPolicyService::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(100)->by($request->user()?->id ?: $request->ip());
        });

        // Password login: a temporary brute-force throttle scoped to (email + IP)
        // — NOT a broad IP ban and NOT an account lockout. Two users behind one
        // campus/NAT IP never share a bucket, and a normal retry on your own
        // account is never blocked. When it does trip, the response is a clear,
        // localized, recoverable 429 (never the misleading "wrong password").
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
            $key = ($email !== '' ? $email : 'guest').'|'.$request->ip();

            return Limit::perMinute(max(1, (int) config('auth.login_throttle_per_minute', 10)))
                ->by($key)
                ->response(function (Request $request, array $headers) {
                    $retryAfter = (int) ($headers['Retry-After'] ?? 60);

                    return response()->json([
                        'message' => "Terlalu banyak percobaan masuk untuk akun ini. Silakan coba lagi dalam {$retryAfter} detik.",
                        'seconds_left' => $retryAfter,
                    ], 429, $headers);
                });
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

        RateLimiter::for('room-booking-mutation', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();
            $identity = $userId ? $userId.'|'.$request->ip() : $request->ip();

            return Limit::perMinute(max(
                1,
                (int) config('room_booking.mutation_rate_limit_per_minute', 20),
            ))->by($identity);
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

        // C7N1: project durable notifications from the immutable room-booking
        // workflow ledger. Synchronous observer → same transaction as the
        // mutation (atomic); the projector isolates its own failures so a
        // notification defect never rolls back or 500s the workflow.
        RoomBookingWorkflowEvent::observe(RoomBookingWorkflowEventObserver::class);

        // C7N1: administrative letters share one status vocabulary and applicant
        // relation, so one observer projects the applicant + academic matrix
        // from every type's persisted status transition (Persuratan queue items
        // come from the assignment seam). Same atomic + failure-isolated model.
        foreach ([
            SuratKeteranganAktifApplication::class,
            SuratPengantarMagangApplication::class,
            ProsesLuarNegeriApplication::class,
            SuratTugasApplication::class,
            ScholarshipApplication::class,
        ] as $letterModel) {
            $letterModel::observe(LetterApplicationNotificationObserver::class);
        }
    }
}
