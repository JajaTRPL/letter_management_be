<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SuperAdmin\UserController as SuperAdminUserController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\PasswordRotationController;
use App\Http\Controllers\StudyProgramController;

use App\Http\Controllers\ProfileController;

/*
|--------------------------------------------------------------------------
| Public Routes (tidak perlu login)
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:api')->group(function () {
    // Password login carries an extra per-(email+IP) brute-force throttle on top
    // of the shared per-IP ceiling; scoped so it never IP-bans or locks accounts.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/google', [\App\Http\Controllers\GoogleAuthController::class, 'login']);
    // Public capability probe: lets the login screen render honestly (e.g. hide
    // the reset link when email-based reset is not operationally available).
    Route::get('/auth/config', [AuthController::class, 'publicConfig']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/verify-token', [AuthController::class, 'verifyToken']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // Public proxy for Google Docs to allow PDF.js to fetch without headers
    Route::get('/templates/proxy-google-doc/{id}', [\App\Http\Controllers\SuperAdmin\TemplateController::class, 'proxyGoogleDoc']);

    Route::middleware(['auth:sanctum', 'check_status'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get(
            '/auth/profile-completion',
            [\App\Http\Controllers\GoogleAuthController::class, 'profileCompletionStatus']
        )->middleware('password_rotation_satisfied');

        Route::middleware('password_rotation_token')
            ->prefix('auth/password-rotation')
            ->group(function () {
                Route::get('/', [PasswordRotationController::class, 'status']);
                Route::post('/', [PasswordRotationController::class, 'update'])
                    ->middleware('throttle:password-rotation');
            });
    });

    Route::middleware([
        'auth:sanctum',
        'check_status',
        'password_rotation_satisfied',
        'profile_complete',
    ])->group(function () {

        Route::get('/storage/{folder}/{filename}', function ($folder, $filename) {
            $user = auth()->user();
            $decodedPath = trim($folder . '/' . $filename, '/');

            for ($i = 0; $i < 3; $i++) {
                $next = rawurldecode($decodedPath);
                if ($next === $decodedPath) {
                    break;
                }
                $decodedPath = $next;
            }

            $relativePath = str_replace('\\', '/', trim($decodedPath, '/'));
            $segments = array_values(array_filter(explode('/', $relativePath), 'strlen'));
            if ($relativePath === '' || str_contains($relativePath, "\0") || in_array('..', $segments, true) || in_array('.', $segments, true)) {
                abort(403);
            }

            // Supporting documents are available only through their dedicated
            // authenticated inline-preview endpoints. Keep the generic raw
            // storage route closed even while legacy source files are retained.
            foreach (['scholarships/transcripts', 'scholarships/slips', 'surat-pengantar-magang/proposals', 'surat-tugas/supporting'] as $blocked) {
                if ($relativePath === $blocked || str_starts_with($relativePath, $blocked . '/')) {
                    abort(403);
                }
            }

            foreach (['surat-pengantar-magang/generated', 'surat-keterangan-aktif/generated', 'proses-luar-negeri/generated'] as $blocked) {
                if ($relativePath === $blocked || str_starts_with($relativePath, $blocked . '/')) {
                    abort(403);
                }
            }

            if (str_starts_with($relativePath, 'scholarships/') && str_ends_with($relativePath, '.docx')) {
                abort(403);
            }

            $storedUrl = \Illuminate\Support\Facades\Storage::url($relativePath);
            $storageCandidates = [$storedUrl, $relativePath, '/api/storage/' . $relativePath];

            if (str_starts_with($relativePath, 'profiles/fotos/')) {
                if (!in_array($user->role, ['tendik', 'akademik', 'super_admin'], true)) {
                    $profile = \App\Models\MahasiswaProfile::whereIn('pas_foto_path', $storageCandidates)->first();
                    abort_unless($profile && (int) $profile->user_id === (int) $user->id, 403);
                }
            } elseif (str_starts_with($relativePath, 'profiles/signatures/')) {
                // Signatures are owner-readable only — never cross-user, never public.
                // Generated documents that embed a signature read it server-side and
                // do not require this route, so blocking cross-user access here does
                // not regress document generation.
                if ($user->role === 'mahasiswa') {
                    $profile = \App\Models\MahasiswaProfile::whereIn('tanda_tangan_path', $storageCandidates)->first();
                    abort_unless($profile && (int) $profile->user_id === (int) $user->id, 403);
                } elseif (in_array($user->role, ['tendik', 'akademik', 'super_admin'], true)) {
                    $owner = \App\Models\User::whereIn('signature_path', $storageCandidates)->first();
                    abort_unless($owner && (int) $owner->id === (int) $user->id, 403);
                } else {
                    abort(403);
                }
            } else {
                abort(403);
            }

            abort_unless(\Illuminate\Support\Facades\Storage::disk('public')->exists($relativePath), 404);

            return response()->download(
                \Illuminate\Support\Facades\Storage::disk('public')->path($relativePath),
                basename($relativePath)
            );
        })->where('filename', '.*');

        Route::post('/auth/complete-profile', [\App\Http\Controllers\GoogleAuthController::class, 'completeProfile']);

        // C7N1 durable in-app notifications — recipient-owned for EVERY
        // authenticated role. Each handler scopes strictly to the caller's own
        // records (IDOR-safe); resolve state stays domain-controlled (no public
        // resolve endpoint). Read-state is the only user-mutable field.
        Route::prefix('notifications')->group(function () {
            Route::get('/', [\App\Http\Controllers\NotificationController::class, 'index']);
            Route::get('/unread-count', [\App\Http\Controllers\NotificationController::class, 'unreadCount']);
            Route::patch('/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllRead']);
            Route::patch('/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'markRead']);
            Route::patch('/{notification}/unread', [\App\Http\Controllers\NotificationController::class, 'markUnread']);
        });

        // Surat Analytics (accessible by all authenticated users)
        Route::get('/surat/average-duration', function () {
            $service = new \App\Services\SuratAnalyticsService();
            return response()->json($service->getAverageDurationByType());
        });

        // List of Active Surat Types
        Route::get('/surat-types', function () {
            return response()->json(\App\Support\LetterTypeRegistry::forApi());
        });

        // Mahasiswa Profile
        Route::get('/profile', [ProfileController::class, 'getProfile']);
        Route::put('/profile', [ProfileController::class, 'updateProfile']);
        Route::post('/profile', [ProfileController::class, 'updateProfile']); // For FormData method spoofing

        // Academic hierarchy (accessible to all authenticated users)
        Route::get('/faculties', [FacultyController::class, 'index']);
        Route::get('/departments', [DepartmentController::class, 'index']);
        Route::get('/study-programs-grouped', [StudyProgramController::class, 'grouped']);
        Route::get(
            '/laboratories',
            [\App\Http\Controllers\SuperAdmin\RoomBookingController::class, 'laboratories']
        )->middleware('role:super_admin');

        // Beasiswa supporting-document preview bytes (role-scoped inside controller).
        // Separate from /api/storage, which is intentionally closed to supporting
        // documents. Frontend fetches this endpoint and renders a PDF blob URL.
        Route::get(
            '/scholarship/{application}/supporting-documents/{field}/preview',
            [\App\Http\Controllers\ScholarshipSupportingDocumentController::class, 'preview']
        );

        // Surat Pengantar Magang supporting-document preview bytes (proposal).
        // Mirrors the Beasiswa endpoint above: role-scoped inside the controller,
        // inline (non-attachment) response so the FE renders it via PDF.js.
        Route::get(
            '/surat-pengantar-magang/{application}/supporting-documents/{field}/preview',
            [\App\Http\Controllers\MagangSupportingDocumentController::class, 'preview']
        );

        // Surat Tugas supporting-document preview bytes (proposal + uploaded
        // Surat Pengantar Magang PDF). Same role-scoped, inline pattern.
        Route::get(
            '/surat-tugas/{application}/supporting-documents/{field}/preview',
            [\App\Http\Controllers\SuratTugasSupportingDocumentController::class, 'preview']
        );

        Route::middleware('throttle:peminjaman-attachment')
            ->prefix('peminjaman-ruangan/{booking}/attachment/surat-peminjaman')
            ->group(function () {
                Route::get('/preview', [\App\Http\Controllers\RoomBookingAttachmentController::class, 'preview']);
                Route::get('/download', [\App\Http\Controllers\RoomBookingAttachmentController::class, 'download']);
            });
        Route::middleware('throttle:peminjaman-attachment')
            ->prefix('peminjaman-ruangan/returns/{returnRequest}/evidence')
            ->group(function () {
                Route::get('/preview', [\App\Http\Controllers\RoomBookingReturnEvidenceController::class, 'preview']);
                Route::get('/download', [\App\Http\Controllers\RoomBookingReturnEvidenceController::class, 'download']);
            });

        // Authenticated room photo delivery (catalog content): id+variant
        // addressing only — storage paths never appear in URLs.
        Route::get('/rooms/{room}/photos/{photo}/{variant}', [\App\Http\Controllers\RoomMediaController::class, 'show'])
            ->middleware('throttle:room-media-view')
            ->whereIn('variant', ['thumb', 'display', 'full']);

        /*
        |----------------------------------------------------------------------
        | Room Management (Super Admin + Tendik; fine-grained scope decided
        | by RoomPermissionResolver: sarpras=classroom, kalab=own lab,
        | laboran=all labs, super_admin=all).
        |----------------------------------------------------------------------
        */
        Route::middleware('role:super_admin,tendik')->prefix('room-management')->group(function () {
            Route::middleware('throttle:room-manage')->group(function () {
                Route::get('/rooms', [\App\Http\Controllers\RoomManagement\RoomController::class, 'index']);
                Route::post('/rooms', [\App\Http\Controllers\RoomManagement\RoomController::class, 'store']);
                Route::get('/rooms/{room}', [\App\Http\Controllers\RoomManagement\RoomController::class, 'show']);
                Route::patch('/rooms/{room}', [\App\Http\Controllers\RoomManagement\RoomController::class, 'update']);
                Route::post('/rooms/{room}/activate', [\App\Http\Controllers\RoomManagement\RoomController::class, 'activate']);
                Route::post('/rooms/{room}/deactivate', [\App\Http\Controllers\RoomManagement\RoomController::class, 'deactivate']);
                Route::delete('/rooms/bulk', [\App\Http\Controllers\RoomManagement\RoomController::class, 'bulkDestroy']);

                Route::get('/facility-types', [\App\Http\Controllers\RoomManagement\RoomFacilityController::class, 'facilityTypes']);
                Route::post('/facility-types', [\App\Http\Controllers\RoomManagement\RoomFacilityController::class, 'storeFacilityType']);
                Route::patch('/facility-types/{facilityType}', [\App\Http\Controllers\RoomManagement\RoomFacilityController::class, 'updateFacilityType']);
                Route::delete('/facility-types/{facilityType}', [\App\Http\Controllers\RoomManagement\RoomFacilityController::class, 'destroyFacilityType']);
                Route::get('/facility-types/{facilityType}/rooms', [\App\Http\Controllers\RoomManagement\RoomFacilityController::class, 'rooms']);
                Route::get('/rooms/{room}/facilities', [\App\Http\Controllers\RoomManagement\RoomFacilityController::class, 'index']);
                Route::put('/rooms/{room}/facilities', [\App\Http\Controllers\RoomManagement\RoomFacilityController::class, 'sync']);

                Route::get('/rooms/{room}/photos', [\App\Http\Controllers\RoomManagement\RoomPhotoController::class, 'index']);
                Route::delete('/rooms/{room}/photos/{photo}', [\App\Http\Controllers\RoomManagement\RoomPhotoController::class, 'destroy']);
                Route::post('/rooms/{room}/photos/{photo}/cover', [\App\Http\Controllers\RoomManagement\RoomPhotoController::class, 'setCover']);
                Route::patch('/rooms/{room}/photos/reorder', [\App\Http\Controllers\RoomManagement\RoomPhotoController::class, 'reorder']);

                Route::get('/rooms/{room}/templates', [\App\Http\Controllers\RoomManagement\RoomTemplateController::class, 'index']);
                Route::post('/rooms/{room}/templates/{template}/activate', [\App\Http\Controllers\RoomManagement\RoomTemplateController::class, 'activate']);
                Route::post('/rooms/{room}/templates/{template}/deactivate', [\App\Http\Controllers\RoomManagement\RoomTemplateController::class, 'deactivate']);

                Route::get('/rooms/{room}/audit-logs', [\App\Http\Controllers\RoomManagement\RoomAuditController::class, 'index']);
            });

            Route::post('/rooms/{room}/photos', [\App\Http\Controllers\RoomManagement\RoomPhotoController::class, 'store'])
                ->middleware('throttle:room-media-upload');
            Route::post('/rooms/{room}/templates', [\App\Http\Controllers\RoomManagement\RoomTemplateController::class, 'store'])
                ->middleware('throttle:room-template');
            Route::get('/rooms/{room}/templates/{template}/download', [\App\Http\Controllers\RoomManagement\RoomTemplateController::class, 'download'])
                ->middleware('throttle:room-template');
        });


        /*
        |----------------------------------------------------------------------
        | 1. Super Admin Dashboard
        |----------------------------------------------------------------------
        */
        Route::middleware('role:super_admin')->prefix('super-admin')->group(function () {
            Route::get('/dashboard/stats', [\App\Http\Controllers\SuperAdmin\DashboardController::class, 'getStats']);
            Route::get('/dashboard/monitoring', [\App\Http\Controllers\SuperAdmin\DashboardController::class, 'getMonitoringData']);
            Route::get('/dashboard', function () {
                return response()->json(['message' => 'Halaman Dasbord Super Admin']);
            });
            Route::get('/reports/login-activity', [\App\Http\Controllers\SuperAdmin\UserController::class, 'loginReport']);
            Route::get('/reports/admin-logs', [\App\Http\Controllers\SuperAdmin\UserController::class, 'activityLog']);

            // Global completed-letter retention controls. Super Admin only;
            // scheduler activation remains config-driven outside this API.
            Route::prefix('retention')->group(function () {
                Route::get('/overview', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'overview']);
                Route::get('/policy', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'policy']);
                Route::put('/policy', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'updatePolicy']);
                Route::get('/automation', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'automation']);
                Route::patch('/automation', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'updateAutomation']);
                Route::get('/candidates', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'candidates']);
                Route::get('/archives', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'archives']);
                Route::get('/actions', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'actions']);
                Route::post('/dry-run', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'dryRun']);
                Route::post('/execute', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'execute']);
                Route::post('/archives/{artifact}/restore', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'restore']);
                Route::post('/archives/{artifact}/purge', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'purge']);
            });

            // Workflow review-SLA governance. Super Admin only; database-managed
            // so policy changes need no code deploy. Ships disabled per scope.
            Route::prefix('review-sla')->group(function () {
                Route::get('/{scope}', [\App\Http\Controllers\SuperAdmin\WorkflowReviewSlaController::class, 'show']);
                Route::put('/{scope}', [\App\Http\Controllers\SuperAdmin\WorkflowReviewSlaController::class, 'update']);
            });

            // Review-speed analytics ("Kinerja Pemeriksaan"). Read-only, judged
            // against the review-SLA policy above so there is exactly one
            // definition of "too slow" in the system. Reports on stages and
            // organisational units — never on individual reviewers.
            Route::prefix('review-performance')->group(function () {
                Route::get('/', [\App\Http\Controllers\SuperAdmin\ReviewPerformanceController::class, 'index']);
                Route::get('/breakdown', [\App\Http\Controllers\SuperAdmin\ReviewPerformanceController::class, 'breakdown']);
                Route::get('/trend', [\App\Http\Controllers\SuperAdmin\ReviewPerformanceController::class, 'trend']);
            });

            // Template Operations
            Route::post('/templates/update-pdf', [\App\Http\Controllers\SuperAdmin\TemplateController::class, 'updatePdf']);
            Route::get('/templates', [\App\Http\Controllers\SuperAdmin\TemplateManagementController::class, 'index']);
            Route::post('/templates/{key}/refresh', [\App\Http\Controllers\SuperAdmin\TemplateManagementController::class, 'refresh'])
                ->where('key', '[a-z0-9\-]+');

            Route::prefix('delegated-activity-acknowledgements')->group(function () {
                Route::get('/', [\App\Http\Controllers\SuperAdmin\DelegatedActivityAcknowledgementController::class, 'index']);
                Route::get('/{acknowledgement}', [\App\Http\Controllers\SuperAdmin\DelegatedActivityAcknowledgementController::class, 'show']);
                Route::post('/{acknowledgement}/mark-escalation-seen', [\App\Http\Controllers\SuperAdmin\DelegatedActivityAcknowledgementController::class, 'markEscalationSeen']);
            });

            // Bulk Operations & Export (Place above /{user} wildcard).
            // Rate-limited: uploads/exports 10/min, template & history 30/min.
            Route::post('/users/validate-import', [\App\Http\Controllers\SuperAdmin\UserController::class, 'validateImport'])
                ->middleware('throttle:10,1,import-upload');
            Route::post('/users/bulk-import', [\App\Http\Controllers\SuperAdmin\UserController::class, 'bulkImport'])
                ->middleware('throttle:10,1,import-upload');
            Route::get('/users/import-template', [\App\Http\Controllers\SuperAdmin\UserController::class, 'importTemplate'])
                ->middleware('throttle:30,1,import-template');
            Route::get('/users/import-batches', [\App\Http\Controllers\SuperAdmin\UserController::class, 'importBatches'])
                ->middleware('throttle:30,1,import-history');
            Route::get('/users/import-batches/{importBatch}/errors', [\App\Http\Controllers\SuperAdmin\UserController::class, 'importBatchErrors'])
                ->middleware('throttle:10,1,import-errors');
            Route::get('/users/export', [\App\Http\Controllers\SuperAdmin\UserController::class, 'export'])
                ->middleware('throttle:10,1,users-export');

            Route::get('/departments', [DepartmentController::class, 'basicList']);

            Route::get('/users', [\App\Http\Controllers\SuperAdmin\UserController::class, 'index']);
            Route::post('/users', [\App\Http\Controllers\SuperAdmin\UserController::class, 'store']); // Tambah User Baru
            Route::get('/users/{user}', [\App\Http\Controllers\SuperAdmin\UserController::class, 'show']); // Detail User
            Route::put('/users/{user}', [\App\Http\Controllers\SuperAdmin\UserController::class, 'update']); // Update User
            Route::delete('/users/{user}', [\App\Http\Controllers\SuperAdmin\UserController::class, 'destroy']); // Hapus User
            Route::patch('/users/{user}/block', [\App\Http\Controllers\SuperAdmin\UserController::class, 'block']);
            Route::patch('/users/{user}/unblock', [\App\Http\Controllers\SuperAdmin\UserController::class, 'unblock']);

            // Academic Periods
            Route::get('/academic-periods', [\App\Http\Controllers\SuperAdmin\AcademicPeriodController::class, 'index']);
            Route::post('/academic-periods', [\App\Http\Controllers\SuperAdmin\AcademicPeriodController::class, 'store']);
            Route::get('/academic-periods/{academicPeriod}', [\App\Http\Controllers\SuperAdmin\AcademicPeriodController::class, 'show']);
            Route::put('/academic-periods/{academicPeriod}', [\App\Http\Controllers\SuperAdmin\AcademicPeriodController::class, 'update']);
            Route::delete('/academic-periods/{academicPeriod}', [\App\Http\Controllers\SuperAdmin\AcademicPeriodController::class, 'destroy']);
            Route::patch('/academic-periods/{academicPeriod}/toggle-active', [\App\Http\Controllers\SuperAdmin\AcademicPeriodController::class, 'toggleActive']);

            Route::prefix('peminjaman-ruangan')->group(function () {
                Route::get('/rooms', [\App\Http\Controllers\SuperAdmin\RoomBookingController::class, 'rooms']);
                Route::post('/rooms', [\App\Http\Controllers\SuperAdmin\RoomBookingController::class, 'storeRoom']);
                Route::get('/rooms/{room}', [\App\Http\Controllers\SuperAdmin\RoomBookingController::class, 'showRoom']);
                Route::put('/rooms/{room}', [\App\Http\Controllers\SuperAdmin\RoomBookingController::class, 'updateRoom']);
                Route::patch('/rooms/{room}/activate', [\App\Http\Controllers\SuperAdmin\RoomBookingController::class, 'activateRoom']);
                Route::patch('/rooms/{room}/deactivate', [\App\Http\Controllers\SuperAdmin\RoomBookingController::class, 'deactivateRoom']);
                Route::get('/calendar', [\App\Http\Controllers\SuperAdmin\RoomBookingController::class, 'calendar']);
                Route::get('/requests', [\App\Http\Controllers\SuperAdmin\RoomBookingController::class, 'requests']);
                Route::get('/requests/{booking}', [\App\Http\Controllers\SuperAdmin\RoomBookingController::class, 'showRequest']);
            });
        });


        /*
        |----------------------------------------------------------------------
        | 2. Tendik (Tenaga Pendidik) Dashboard
        |----------------------------------------------------------------------
        */
        Route::middleware('role:tendik')->prefix('tendik')->group(function () {
            // Own-stage review summary. Scope is derived from the authenticated
            // user; the request carries no stage or unit to tamper with.
            Route::get('/review-performance/me', [\App\Http\Controllers\ReviewPerformanceSelfController::class, 'show']);
            Route::get('/dashboard/tasks', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'getDashboardData']);
            Route::get('/riwayat', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'getRiwayatData']);

            Route::prefix('delegated-activity-acknowledgements')->group(function () {
                Route::get('/', [\App\Http\Controllers\Tendik\DelegatedActivityAcknowledgementController::class, 'index']);
                Route::get('/{acknowledgement}', [\App\Http\Controllers\Tendik\DelegatedActivityAcknowledgementController::class, 'show']);
                Route::post('/{acknowledgement}/acknowledge', [\App\Http\Controllers\Tendik\DelegatedActivityAcknowledgementController::class, 'acknowledge']);
            });

            Route::get('/peminjaman-ruangan/calendar', [\App\Http\Controllers\Tendik\RoomBookingController::class, 'calendar']);

            // Dashboard feed for Sarpras / Kepala Lab / Laboran. The letter
            // dashboard endpoint is structurally empty for these roles, so they
            // get their own. Role and scope are resolved from the session.
            Route::get('/peminjaman-ruangan/dashboard', [\App\Http\Controllers\Tendik\RoomBookingDashboardController::class, 'index']);

            Route::prefix('peminjaman-ruangan/requests')->group(function () {
                Route::get('/', [\App\Http\Controllers\Tendik\RoomBookingController::class, 'index']);
                Route::get('/{booking}', [\App\Http\Controllers\Tendik\RoomBookingController::class, 'show']);
                Route::patch('/{booking}/start-review', [\App\Http\Controllers\Tendik\RoomBookingController::class, 'startReview'])
                    ->middleware('throttle:room-booking-mutation');
                Route::patch('/{booking}/approve', [\App\Http\Controllers\Tendik\RoomBookingController::class, 'approve'])
                    ->middleware('throttle:room-booking-mutation');
                Route::patch('/{booking}/revise', [\App\Http\Controllers\Tendik\RoomBookingController::class, 'revise'])
                    ->middleware('throttle:room-booking-mutation');
                Route::patch('/{booking}/reject', [\App\Http\Controllers\Tendik\RoomBookingController::class, 'reject'])
                    ->middleware('throttle:room-booking-mutation');
            });
            Route::prefix('peminjaman-ruangan/operations')->group(function () {
                Route::get('/', [\App\Http\Controllers\Tendik\RoomBookingOccurrenceController::class, 'index']);
                Route::post('/{occurrence}/issue-key', [\App\Http\Controllers\Tendik\RoomBookingOccurrenceController::class, 'issueKey'])
                    ->middleware('throttle:room-booking-mutation');
                Route::post('/{occurrence}/return/accept', [\App\Http\Controllers\Tendik\RoomBookingOccurrenceController::class, 'accept'])
                    ->middleware('throttle:room-booking-mutation');
                Route::post('/{occurrence}/return/revise', [\App\Http\Controllers\Tendik\RoomBookingOccurrenceController::class, 'revise'])
                    ->middleware('throttle:room-booking-mutation');
                Route::post('/{occurrence}/return/reject', [\App\Http\Controllers\Tendik\RoomBookingOccurrenceController::class, 'reject'])
                    ->middleware('throttle:room-booking-mutation');
            });

            Route::prefix('peminjaman-ruangan/cancellation-requests')->group(function () {
                Route::get('/', [\App\Http\Controllers\Tendik\RoomBookingCancellationRequestController::class, 'index']);
                Route::get('/{cancellationRequest}', [\App\Http\Controllers\Tendik\RoomBookingCancellationRequestController::class, 'show']);
                Route::patch('/{cancellationRequest}/approve', [\App\Http\Controllers\Tendik\RoomBookingCancellationRequestController::class, 'approve'])
                    ->middleware('throttle:room-booking-mutation');
                Route::patch('/{cancellationRequest}/reject', [\App\Http\Controllers\Tendik\RoomBookingCancellationRequestController::class, 'reject'])
                    ->middleware('throttle:room-booking-mutation');
            });

            // Scholarship Review Actions
            foreach (['scholarship', 'surat-permohonan-beasiswa'] as $scholarshipRoutePrefix) {
                Route::prefix($scholarshipRoutePrefix)->group(function () {
                    Route::get('/{application}', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'show']);
                    Route::get('/{application}/generated-preview', [\App\Http\Controllers\BeasiswaGeneratedPreviewController::class, 'tendik']);
                    Route::patch('/{application}/approve', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'approve']);
                    Route::patch('/{application}/reject', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'reject']);
                    Route::patch('/{application}/revise', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'revise']);
                });
            }

            // Surat Pengantar Magang Review Actions
            Route::prefix('surat-pengantar-magang')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\SuratPengantarMagangController::class, 'showForReviewer']);
                Route::get('/{application}/generated-preview', [\App\Http\Controllers\SuratPengantarMagangGeneratedPreviewController::class, 'tendik']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\SuratPengantarMagangController::class, 'approveByTendik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\SuratPengantarMagangController::class, 'rejectByTendik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\SuratPengantarMagangController::class, 'reviseByTendik']);
            });

            Route::prefix('surat-keterangan-aktif')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'showForReviewer']);
                Route::get('/{application}/generated-preview', [\App\Http\Controllers\SuratKeteranganAktifGeneratedPreviewController::class, 'tendik']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'approveByTendik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'rejectByTendik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'reviseByTendik']);
            });

            Route::prefix('proses-luar-negeri')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'showForReviewer']);
                Route::get('/{application}/generated-preview', [\App\Http\Controllers\ProsesLuarNegeriGeneratedPreviewController::class, 'tendik']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'approveByTendik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'rejectByTendik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'reviseByTendik']);
            });

            Route::prefix('surat-tugas')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\SuratTugasController::class, 'showForReviewer']);
                Route::get('/{application}/generated-preview', [\App\Http\Controllers\SuratTugasGeneratedPreviewController::class, 'tendik']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\SuratTugasController::class, 'approveByTendik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\SuratTugasController::class, 'rejectByTendik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\SuratTugasController::class, 'reviseByTendik']);
            });

            // Letter Review Actions
            Route::get('/letter/{application}', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'showLetter']);
            Route::patch('/letter/{application}/approve', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'approveLetter']);
            Route::patch('/letter/{application}/reject', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'rejectLetter']);
            Route::patch('/letter/{application}/revise', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'reviseLetter']);

            Route::get('/dashboard', function () {
                return response()->json(['message' => 'Halaman Dasbord Khusus Tendik']);
            });
        });

        // Akademik (Kaprodi/Sekprodi) Routes
        Route::middleware('role:akademik')->prefix('akademik')->group(function () {
            // Own-stage review summary — Prodi for Kaprodi/Sekprodi, Departemen
            // for Kadep/Sekdep, resolved from the user, never from the request.
            Route::get('/review-performance/me', [\App\Http\Controllers\ReviewPerformanceSelfController::class, 'show']);
            Route::get('/dashboard/tasks', [\App\Http\Controllers\Akademik\AkademikDashboardController::class, 'getDashboardData']);
            Route::get('/riwayat', [\App\Http\Controllers\Akademik\AkademikDashboardController::class, 'getRiwayatData']);

            foreach (['scholarship', 'surat-permohonan-beasiswa'] as $scholarshipRoutePrefix) {
                Route::prefix($scholarshipRoutePrefix)->group(function () {
                    Route::get('/{application}', [\App\Http\Controllers\Akademik\AkademikDashboardController::class, 'show']);
                    Route::get('/{application}/generated-preview', [\App\Http\Controllers\BeasiswaGeneratedPreviewController::class, 'akademik']);
                    Route::patch('/{application}/approve', [\App\Http\Controllers\Akademik\AkademikDashboardController::class, 'approve']);
                    Route::patch('/{application}/reject', [\App\Http\Controllers\Akademik\AkademikDashboardController::class, 'reject']);
                    Route::patch('/{application}/revise', [\App\Http\Controllers\Akademik\AkademikDashboardController::class, 'revise']);
                });
            }

            Route::prefix('surat-pengantar-magang')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\SuratPengantarMagangController::class, 'showForReviewer']);
                Route::get('/{application}/generated-preview', [\App\Http\Controllers\SuratPengantarMagangGeneratedPreviewController::class, 'akademik']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\SuratPengantarMagangController::class, 'approveByAkademik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\SuratPengantarMagangController::class, 'rejectByAkademik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\SuratPengantarMagangController::class, 'reviseByAkademik']);
            });

            Route::prefix('surat-keterangan-aktif')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'showForReviewer']);
                Route::get('/{application}/generated-preview', [\App\Http\Controllers\SuratKeteranganAktifGeneratedPreviewController::class, 'akademik']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'approveByAkademik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'rejectByAkademik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'reviseByAkademik']);
            });

            Route::prefix('proses-luar-negeri')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'showForReviewer']);
                Route::get('/{application}/generated-preview', [\App\Http\Controllers\ProsesLuarNegeriGeneratedPreviewController::class, 'akademik']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'approveByAkademik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'rejectByAkademik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'reviseByAkademik']);
            });

            Route::prefix('surat-tugas')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\SuratTugasController::class, 'showForReviewer']);
                Route::get('/{application}/generated-preview', [\App\Http\Controllers\SuratTugasGeneratedPreviewController::class, 'akademik']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\SuratTugasController::class, 'approveByAkademik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\SuratTugasController::class, 'rejectByAkademik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\SuratTugasController::class, 'reviseByAkademik']);
            });
        });


        /*
        |----------------------------------------------------------------------
        | 3. Pejabat/Struktural (Akademik)
        |----------------------------------------------------------------------
        */
        Route::middleware('role:akademik')->prefix('akademik')->group(function () {
            Route::get('/dashboard', function () {
                return response()->json(['message' => 'Halaman Dasbord Akademik']);
            });
        });


        /*
        |----------------------------------------------------------------------
        | 4. Mahasiswa Dashboard
        |----------------------------------------------------------------------
        */
        Route::middleware('role:mahasiswa')->prefix('mahasiswa')->group(function () {
            Route::get('/dashboard', function () {
                return response()->json(['message' => 'Halaman Dasbord Mahasiswa']);
            });

            Route::prefix('peminjaman-ruangan')->group(function () {
                Route::get('/rooms', [\App\Http\Controllers\Mahasiswa\RoomBookingController::class, 'rooms']);
                Route::get('/rooms/{room}', [\App\Http\Controllers\Mahasiswa\RoomCatalogController::class, 'show']);
                Route::get('/rooms/{room}/template', [\App\Http\Controllers\Mahasiswa\RoomCatalogController::class, 'template'])
                    ->middleware('throttle:room-template');
                Route::get('/availability', [\App\Http\Controllers\Mahasiswa\RoomBookingController::class, 'availability']);
                Route::prefix('requests')->group(function () {
                    Route::get('/', [\App\Http\Controllers\Mahasiswa\RoomBookingController::class, 'index']);
                    Route::post('/', [\App\Http\Controllers\Mahasiswa\RoomBookingController::class, 'store'])
                        ->middleware('throttle:peminjaman-attachment');
                    Route::get('/{booking}', [\App\Http\Controllers\Mahasiswa\RoomBookingController::class, 'show']);
                    Route::put('/{booking}', [\App\Http\Controllers\Mahasiswa\RoomBookingController::class, 'update']);
                    Route::patch('/{booking}/submit', [\App\Http\Controllers\Mahasiswa\RoomBookingController::class, 'submit'])
                        ->middleware('throttle:room-booking-mutation');
                    Route::post('/{booking}/withdraw', [\App\Http\Controllers\Mahasiswa\RoomBookingController::class, 'withdraw'])
                        ->middleware('throttle:room-booking-mutation');
                    Route::post('/{booking}/cancellation-requests', [\App\Http\Controllers\Mahasiswa\RoomBookingCancellationRequestController::class, 'store'])
                        ->middleware('throttle:room-booking-mutation');
                    Route::get('/{booking}/cancellation-request', [\App\Http\Controllers\Mahasiswa\RoomBookingCancellationRequestController::class, 'show']);
                    Route::patch('/{booking}/cancellation-requests/{cancellationRequest}/withdraw', [\App\Http\Controllers\Mahasiswa\RoomBookingCancellationRequestController::class, 'withdraw'])
                        ->middleware('throttle:room-booking-mutation');
                });
                Route::post(
                    '/{booking}/attachment/surat-peminjaman',
                    [\App\Http\Controllers\RoomBookingAttachmentController::class, 'replace']
                )->middleware('throttle:peminjaman-attachment');
                Route::post('/occurrences/{occurrence}/return', [\App\Http\Controllers\Mahasiswa\RoomBookingOccurrenceController::class, 'submitReturn'])
                    ->middleware(['throttle:peminjaman-attachment', 'throttle:room-booking-mutation']);
                Route::post('/occurrences/{occurrence}/return/withdraw', [\App\Http\Controllers\Mahasiswa\RoomBookingOccurrenceController::class, 'withdrawReturn'])
                    ->middleware('throttle:room-booking-mutation');
            });

            // Scholarship Application Routes
            foreach (['scholarship', 'surat-permohonan-beasiswa'] as $scholarshipRoutePrefix) {
                Route::prefix($scholarshipRoutePrefix)->group(function () {
                    Route::get('/applications', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'getApplications']);
                    Route::get('/{application}/generated-preview', [\App\Http\Controllers\BeasiswaGeneratedPreviewController::class, 'mahasiswa']);
                    Route::get('/{application}/final-download', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'finalDownload']);
                    Route::post('/{application}/complete', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'complete']);
                    Route::get('/step-1', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'getStep1']);
                    Route::post('/step-1', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'saveStep1']);
                    Route::post('/step-2', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'saveStep2']);
                    Route::post('/step-3', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'saveStep3']);
                    Route::post('/submit', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'submitApplication']);
                    // Read-only detail. Declared last so static segments above take precedence
                    // over the dynamic {application} pattern (Laravel matches by registration order).
                    Route::get('/{application}', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'showForMahasiswa']);
                });
            }

            Route::prefix('surat-pengantar-magang')->group(function () {
                Route::get('/applications', [\App\Http\Controllers\SuratPengantarMagangController::class, 'getApplications']);
                Route::get('/draft', [\App\Http\Controllers\SuratPengantarMagangController::class, 'getDraft']);
                Route::post('/draft', [\App\Http\Controllers\SuratPengantarMagangController::class, 'saveDraft']);
                Route::post('/submit', [\App\Http\Controllers\SuratPengantarMagangController::class, 'submitApplication']);
                Route::get('/{application}/generated-preview', [\App\Http\Controllers\SuratPengantarMagangGeneratedPreviewController::class, 'mahasiswa']);
                Route::get('/{application}/final-download', \App\Http\Controllers\SuratPengantarMagangFinalDownloadController::class);
                Route::post('/{application}/complete', [\App\Http\Controllers\SuratPengantarMagangController::class, 'complete']);
                Route::get('/{application}', [\App\Http\Controllers\SuratPengantarMagangController::class, 'showForMahasiswa']);
            });

            Route::prefix('surat-tugas')->group(function () {
                Route::get('/applications', [\App\Http\Controllers\SuratTugasController::class, 'getApplications']);
                Route::get('/draft', [\App\Http\Controllers\SuratTugasController::class, 'getDraft']);
                Route::post('/draft', [\App\Http\Controllers\SuratTugasController::class, 'saveDraft']);
                Route::post('/submit', [\App\Http\Controllers\SuratTugasController::class, 'submitApplication']);
                Route::get('/{application}/generated-preview', [\App\Http\Controllers\SuratTugasGeneratedPreviewController::class, 'mahasiswa']);
                Route::get('/{application}/final-download', \App\Http\Controllers\SuratTugasFinalDownloadController::class);
                Route::post('/{application}/complete', [\App\Http\Controllers\SuratTugasController::class, 'complete']);
                Route::get('/{application}', [\App\Http\Controllers\SuratTugasController::class, 'showForMahasiswa']);
            });

            Route::prefix('surat-keterangan-aktif')->group(function () {
                Route::get('/applications', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'getApplications']);
                Route::get('/draft', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'getDraft']);
                Route::post('/draft', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'saveDraft']);
                Route::post('/submit', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'submitApplication']);
                Route::get('/{application}/generated-preview', [\App\Http\Controllers\SuratKeteranganAktifGeneratedPreviewController::class, 'mahasiswa']);
                Route::get('/{application}/final-download', \App\Http\Controllers\SuratKeteranganAktifFinalDownloadController::class);
                Route::post('/{application}/complete', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'complete']);
                Route::get('/{application}', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'showForMahasiswa']);
            });

            Route::prefix('proses-luar-negeri')->group(function () {
                Route::get('/applications', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'getApplications']);
                Route::get('/draft', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'getDraft']);
                Route::post('/draft', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'saveDraft']);
                Route::post('/submit', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'submitApplication']);
                Route::get('/{application}/generated-preview', [\App\Http\Controllers\ProsesLuarNegeriGeneratedPreviewController::class, 'mahasiswa']);
                Route::get('/{application}/final-download', \App\Http\Controllers\ProsesLuarNegeriFinalDownloadController::class);
                Route::post('/{application}/complete', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'complete']);
                Route::get('/{application}', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'showForMahasiswa']);
            });

        });


        /*
        |----------------------------------------------------------------------
        | Profil Umum
        |----------------------------------------------------------------------
        */
        // Route dipindah ke atas (baris 31)


    });

});
