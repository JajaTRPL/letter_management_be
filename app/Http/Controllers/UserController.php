<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Mengambil daftar user berdasarkan role yang sedang login.
     * - super_admin : melihat semua user (id, name, role, email)
     * - admin       : hanya melihat user dengan role 'user' (tanpa kolom role)
     */
    public function index(Request $request)
    {
        $currentUser = $request->user();

        if ($currentUser->role === 'super_admin') {
            // Super admin melihat semua user dengan detail lengkap
            $users = User::select('id', 'name', 'email', 'role', 'created_at')->get();
        } else {
            // Role lain hanya melihat daftar mahasiswa (untuk keperluan surat menyurat dsb)
            $users = User::select('id', 'name', 'email', 'role')
                ->where('role', 'mahasiswa')
                ->get();
        }

        return response()->json([
            'message' => 'Daftar User berhasil diambil',
            'count' => $users->count(),
            'data' => $users,
        ], 200);
    }

    /**
     * Membuat user baru (Hanya Super Admin).
     */
    public function store(Request $request)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:mahasiswa,tendik_1,tendik_2,tendik_3,tendik_4,tendik_5,tendik_6,tendik_7,tendik_8,kadep,kaprodi,sekprodi,sekdep'
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'role' => $validated['role']
        ]);

        return response()->json([
            'message' => 'User berhasil dibuat',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role
            ]
        ], 201);
    }
}
