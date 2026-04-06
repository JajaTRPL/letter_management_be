<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Mengambil daftar user umum (Mahasiswa).
     */
    public function index(Request $request)
    {
        // Role umum dapat melihat daftar Mahasiswa, Tendik, dan Akademik
        $users = User::select('id', 'name', 'email', 'role', 'status')
            ->whereIn('role', [
                'mahasiswa',
                'tendik',
                'kadep',
                'kaprodi',
                'sekprodi',
                'sekdep'
            ])
            ->get();

        return response()->json([
            'message' => 'Daftar User (Mahasiswa, Tendik, Akademik) berhasil diambil',
            'count' => $users->count(),
            'data' => $users,
        ], 200);
    }
}
