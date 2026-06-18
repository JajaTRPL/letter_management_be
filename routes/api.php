<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SuperAdmin\UserController as SuperAdminUserController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\StudyProgramController;

use App\Http\Controllers\ProfileController;

/*
|--------------------------------------------------------------------------
| Public Routes (tidak perlu login)
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:api')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/auth/google', [\App\Http\Controllers\GoogleAuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/verify-token', [AuthController::class, 'verifyToken']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // Public proxy for Google Docs to allow PDF.js to fetch without headers
    Route::get('/templates/proxy-google-doc/{id}', [\App\Http\Controllers\SuperAdmin\TemplateController::class, 'proxyGoogleDoc']);

    Route::middleware(['auth:sanctum', 'check_status', 'profile_complete'])->group(function () {

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

        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/auth/complete-profile', [\App\Http\Controllers\GoogleAuthController::class, 'completeProfile']);

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
                Route::get('/candidates', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'candidates']);
                Route::get('/archives', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'archives']);
                Route::get('/actions', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'actions']);
                Route::post('/dry-run', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'dryRun']);
                Route::post('/execute', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'execute']);
                Route::post('/archives/{artifact}/restore', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'restore']);
                Route::post('/archives/{artifact}/purge', [\App\Http\Controllers\SuperAdmin\RetentionController::class, 'purge']);
            });

            // Template Operations
            Route::post('/templates/update-pdf', [\App\Http\Controllers\SuperAdmin\TemplateController::class, 'updatePdf']);
            Route::get('/templates', [\App\Http\Controllers\SuperAdmin\TemplateManagementController::class, 'index']);
            Route::post('/templates/{key}/refresh', [\App\Http\Controllers\SuperAdmin\TemplateManagementController::class, 'refresh'])
                ->where('key', '[a-z0-9\-]+');

            // Bulk Operations & Export (Place above /{user} wildcard)
            Route::post('/users/validate-import', [\App\Http\Controllers\SuperAdmin\UserController::class, 'validateImport']);
            Route::post('/users/bulk-import', [\App\Http\Controllers\SuperAdmin\UserController::class, 'bulkImport']);
            Route::post('/users/import-errors', [\App\Http\Controllers\SuperAdmin\UserController::class, 'importErrors']);
            Route::get('/users/import-template', [\App\Http\Controllers\SuperAdmin\UserController::class, 'importTemplate']);
            Route::get('/users/export', [\App\Http\Controllers\SuperAdmin\UserController::class, 'export']);

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
        });


        /*
        |----------------------------------------------------------------------
        | 2. Tendik (Tenaga Pendidik) Dashboard
        |----------------------------------------------------------------------
        */
        Route::middleware('role:tendik')->prefix('tendik')->group(function () {
            Route::get('/dashboard/tasks', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'getDashboardData']);
            Route::get('/riwayat', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'getRiwayatData']);
            
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
