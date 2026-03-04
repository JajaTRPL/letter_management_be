<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SuperAdmin\UserController as SuperAdminUserController;

/*
|--------------------------------------------------------------------------
| Public Routes (tidak perlu login)
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:api')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']); // Fitur Reset Password Baru

    /*
    |--------------------------------------------------------------------------
    | Authenticated Routes (semua role yang sudah login)
    |--------------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('/logout', [AuthController::class, 'logout']);

        // Get all users (umum)
        Route::get('/users', [UserController::class, 'index']);

        /*
        |----------------------------------------------------------------------
        | 1. Super Admin Dashboard
        |----------------------------------------------------------------------
        */
        Route::middleware('role:super_admin')->prefix('super-admin')->group(function () {
            Route::get('/dashboard', function () {
                return response()->json(['message' => 'Halaman Dasbord Super Admin']);
            });
            Route::get('/users', [SuperAdminUserController::class, 'index']);
            Route::post('/users', [SuperAdminUserController::class, 'store']); // Tambah User Baru
            Route::get('/users/{user}', [SuperAdminUserController::class, 'show']); // Detail User
            Route::put('/users/{user}', [SuperAdminUserController::class, 'update']); // Update User
            Route::delete('/users/{user}', [SuperAdminUserController::class, 'destroy']); // Hapus User
            Route::patch('/users/{user}/block', [SuperAdminUserController::class, 'block']);
            Route::patch('/users/{user}/unblock', [SuperAdminUserController::class, 'unblock']);
            Route::get('/reports/login-activity', [SuperAdminUserController::class, 'loginReport']);
            Route::get('/reports/admin-logs', [SuperAdminUserController::class, 'activityLog']);

            // Bulk Operations
            Route::post('/users/bulk-import', [SuperAdminUserController::class, 'bulkImport']);
            Route::get('/users/export', [SuperAdminUserController::class, 'export']);
        });


        /*
        |----------------------------------------------------------------------
        | 2. Tendik (1-8) Dashboard
        |----------------------------------------------------------------------
        */
        Route::middleware('role:tendik_1,tendik_2,tendik_3,tendik_4,tendik_5,tendik_6,tendik_7,tendik_8')->prefix('tendik')->group(function () {
            Route::get('/dashboard', function () {
                return response()->json(['message' => 'Halaman Dasbord Khusus Tendik']);
            });
        });


        /*
        |----------------------------------------------------------------------
        | 3. Pejabat/Struktural (Kadep, Kaprodi, Sekprodi, Sekdep)
        |----------------------------------------------------------------------
        */
        Route::middleware('role:kadep,kaprodi,sekprodi,sekdep')->prefix('akademik')->group(function () {
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
        });


        /*
        |----------------------------------------------------------------------
        | Profil Umum
        |----------------------------------------------------------------------
        */
        Route::get('/profile', function (Request $request) {
            $user = $request->user();

            return response()->json([
                'message' => 'Anda masuk sebagai role: ' . $user->role,
                'user' => $user,
                'nama' => $user->name,
            ]);
        });


    });

});
