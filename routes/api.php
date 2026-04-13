<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SuperAdmin\UserController as SuperAdminUserController;

use App\Http\Controllers\ProfileController;

/*
|--------------------------------------------------------------------------
| Public Routes (tidak perlu login)
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:api')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
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
        $path = storage_path('app/public/' . $folder . '/' . $filename);
        if (!file_exists($path)) {
            abort(404);
        }
        return response()->file($path);
    })->where('filename', '.*');

    Route::middleware('auth:sanctum')->group(function () {

        Route::post('/logout', [AuthController::class, 'logout']);

        // Mahasiswa Profile
        Route::get('/profile', [ProfileController::class, 'getProfile']);
        Route::put('/profile', [ProfileController::class, 'updateProfile']);
        Route::post('/profile', [ProfileController::class, 'updateProfile']); // For FormData method spoofing

        // Get all users (umum)
        Route::get('/users', [UserController::class, 'index']);


        /*
        |----------------------------------------------------------------------
        | 1. Super Admin Dashboard
        |----------------------------------------------------------------------
        */
        Route::middleware('role:super_admin')->prefix('super-admin')->group(function () {
            Route::get('/dashboard/stats', [\App\Http\Controllers\SuperAdmin\DashboardController::class, 'getStats']);
            Route::get('/dashboard', function () {
                return response()->json(['message' => 'Halaman Dasbord Super Admin']);
            });
            Route::get('/reports/login-activity', [\App\Http\Controllers\SuperAdmin\UserController::class, 'loginReport']);
            Route::get('/reports/admin-logs', [\App\Http\Controllers\SuperAdmin\UserController::class, 'activityLog']);

            // Template Operations
            Route::post('/templates/update-pdf', [\App\Http\Controllers\SuperAdmin\TemplateController::class, 'updatePdf']);

            // Bulk Operations & Export (Place above /{user} wildcard)
            Route::post('/users/bulk-import', [\App\Http\Controllers\SuperAdmin\UserController::class, 'bulkImport']);
            Route::get('/users/import-template', [\App\Http\Controllers\SuperAdmin\UserController::class, 'importTemplate']);
            Route::get('/users/export', [\App\Http\Controllers\SuperAdmin\UserController::class, 'export']);

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
            Route::get('/dashboard', function () {
                return response()->json(['message' => 'Halaman Dasbord Khusus Tendik']);
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
            Route::prefix('scholarship')->group(function () {
                Route::get('/step-1', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'getStep1']);
                Route::post('/step-1', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'saveStep1']);
                Route::post('/step-2', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'saveStep2']);
                Route::post('/step-3', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'saveStep3']);
                Route::post('/submit', [\App\Http\Controllers\Mahasiswa\ScholarshipController::class, 'submitApplication']);
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
