<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
    return response()->file($path);
})->where('filename', '.*');
