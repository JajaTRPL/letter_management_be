<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;

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
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']); // Tambah User Baru
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
        Route::middleware('role:kadep,kaprodi,sekprodi,sekdep')->prefix('pejabat')->group(function () {
            Route::get('/dashboard', function () {
                return response()->json(['message' => 'Halaman Dasbord Pejabat (Struktural)']);
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
