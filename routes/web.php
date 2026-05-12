<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/storage/{folder}/{filename}', function ($folder, $filename) {
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

    // Public storage is intentionally closed. Private uploads and generated documents
    // must go through /api/storage or workflow-specific preview/download endpoints.
    abort(403);
})->where('filename', '.*');
