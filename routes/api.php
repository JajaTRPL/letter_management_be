<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
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

    /*
    |--------------------------------------------------------------------------
    | Authenticated Routes (semua role yang sudah login)
    |--------------------------------------------------------------------------
    */
    // Route to serve PDF files directly bypassing IIS/Windows symlink issues
        Route::get('/storage/{folder}/{filename}', function ($folder, $filename) {
        $relativePath = str_replace('\\', '/', trim($folder . '/' . $filename, '/'));
        if ($relativePath === 'surat-pengantar-magang/generated' || str_starts_with($relativePath, 'surat-pengantar-magang/generated/')) {
            abort(403);
        }
        if ($relativePath === 'surat-keterangan-aktif/generated' || str_starts_with($relativePath, 'surat-keterangan-aktif/generated/')) {
            abort(403);
        }
        if ($relativePath === 'proses-luar-negeri/generated' || str_starts_with($relativePath, 'proses-luar-negeri/generated/')) {
            abort(403);
        }
        if ($relativePath === 'scholarships' || str_starts_with($relativePath, 'scholarships/') || str_contains($relativePath, '/scholarships/')) {
            abort(403);
        }

        $path = storage_path('app/public/' . $folder . '/' . $filename);
        if (!file_exists($path)) {
            abort(404);
        }
        return response()->download($path, basename($filename));
    })->where('filename', '.*');

    // Public proxy for Google Docs to allow PDF.js to fetch without headers
    Route::get('/templates/proxy-google-doc/{id}', [\App\Http\Controllers\SuperAdmin\TemplateController::class, 'proxyGoogleDoc']);

    Route::middleware(['auth:sanctum', 'check_status', 'profile_complete'])->group(function () {

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

        // Get all users (umum)
        Route::get('/users', [UserController::class, 'index']);

        // Academic hierarchy (accessible to all authenticated users)
        Route::get('/faculties', [FacultyController::class, 'index']);
        Route::get('/departments', [DepartmentController::class, 'index']);
        Route::get('/study-programs-grouped', [StudyProgramController::class, 'grouped']);


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

            // Template Operations
            Route::post('/templates/update-pdf', [\App\Http\Controllers\SuperAdmin\TemplateController::class, 'updatePdf']);

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
                    Route::patch('/{application}/approve', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'approve']);
                    Route::patch('/{application}/reject', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'reject']);
                    Route::patch('/{application}/revise', [\App\Http\Controllers\Tendik\TendikDashboardController::class, 'revise']);
                });
            }

            // Surat Pengantar Magang Review Actions
            Route::prefix('surat-pengantar-magang')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\SuratPengantarMagangController::class, 'showForReviewer']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\SuratPengantarMagangController::class, 'approveByTendik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\SuratPengantarMagangController::class, 'rejectByTendik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\SuratPengantarMagangController::class, 'reviseByTendik']);
            });

            Route::prefix('surat-keterangan-aktif')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'showForReviewer']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'approveByTendik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'rejectByTendik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'reviseByTendik']);
            });

            Route::prefix('proses-luar-negeri')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'showForReviewer']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'approveByTendik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'rejectByTendik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'reviseByTendik']);
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

            foreach (['scholarship', 'surat-permohonan-beasiswa'] as $scholarshipRoutePrefix) {
                Route::prefix($scholarshipRoutePrefix)->group(function () {
                    Route::get('/{application}', [\App\Http\Controllers\Akademik\AkademikDashboardController::class, 'show']);
                    Route::patch('/{application}/approve', [\App\Http\Controllers\Akademik\AkademikDashboardController::class, 'approve']);
                    Route::patch('/{application}/reject', [\App\Http\Controllers\Akademik\AkademikDashboardController::class, 'reject']);
                    Route::patch('/{application}/revise', [\App\Http\Controllers\Akademik\AkademikDashboardController::class, 'revise']);
                });
            }

            Route::prefix('surat-pengantar-magang')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\SuratPengantarMagangController::class, 'showForReviewer']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\SuratPengantarMagangController::class, 'approveByAkademik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\SuratPengantarMagangController::class, 'rejectByAkademik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\SuratPengantarMagangController::class, 'reviseByAkademik']);
            });

            Route::prefix('surat-keterangan-aktif')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'showForReviewer']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'approveByAkademik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'rejectByAkademik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'reviseByAkademik']);
            });

            Route::prefix('proses-luar-negeri')->group(function () {
                Route::get('/{application}', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'showForReviewer']);
                Route::patch('/{application}/approve', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'approveByAkademik']);
                Route::patch('/{application}/reject', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'rejectByAkademik']);
                Route::patch('/{application}/revise', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'reviseByAkademik']);
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
                    Route::get('/{application}/preview', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'preview']);
                    Route::post('/{application}/complete', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'complete']);
                    Route::get('/step-1', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'getStep1']);
                    Route::post('/step-1', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'saveStep1']);
                    Route::post('/step-2', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'saveStep2']);
                    Route::post('/step-3', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'saveStep3']);
                    Route::post('/submit', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'submitApplication']);
                });
            }

            Route::prefix('surat-pengantar-magang')->group(function () {
                Route::get('/applications', [\App\Http\Controllers\SuratPengantarMagangController::class, 'getApplications']);
                Route::get('/draft', [\App\Http\Controllers\SuratPengantarMagangController::class, 'getDraft']);
                Route::post('/draft', [\App\Http\Controllers\SuratPengantarMagangController::class, 'saveDraft']);
                Route::post('/submit', [\App\Http\Controllers\SuratPengantarMagangController::class, 'submitApplication']);
                Route::get('/{application}/preview', [\App\Http\Controllers\SuratPengantarMagangController::class, 'preview']);
                Route::post('/{application}/complete', [\App\Http\Controllers\SuratPengantarMagangController::class, 'complete']);
                Route::get('/{application}', [\App\Http\Controllers\SuratPengantarMagangController::class, 'showForMahasiswa']);
            });

            Route::prefix('surat-keterangan-aktif')->group(function () {
                Route::get('/applications', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'getApplications']);
                Route::get('/draft', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'getDraft']);
                Route::post('/draft', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'saveDraft']);
                Route::post('/submit', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'submitApplication']);
                Route::get('/{application}/preview', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'preview']);
                Route::post('/{application}/complete', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'complete']);
                Route::get('/{application}', [\App\Http\Controllers\SuratKeteranganAktifController::class, 'showForMahasiswa']);
            });

            Route::prefix('proses-luar-negeri')->group(function () {
                Route::get('/applications', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'getApplications']);
                Route::get('/draft', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'getDraft']);
                Route::post('/draft', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'saveDraft']);
                Route::post('/submit', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'submitApplication']);
                Route::get('/{application}/preview', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'preview']);
                Route::post('/{application}/complete', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'complete']);
                Route::get('/{application}', [\App\Http\Controllers\ProsesLuarNegeriController::class, 'showForMahasiswa']);
            });

            // Aktif Letter Routes
            Route::prefix('aktif')->group(function () {
                Route::get('/step-1', [\App\Http\Controllers\Mahasiswa\AktifLetterController::class, 'getStep1']);
                Route::post('/step-1', [\App\Http\Controllers\Mahasiswa\AktifLetterController::class, 'saveStep1']);
                Route::post('/submit', [\App\Http\Controllers\Mahasiswa\AktifLetterController::class, 'submitApplication']);
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
