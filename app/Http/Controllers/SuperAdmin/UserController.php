<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AdminLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    /**
     * Mengambil daftar semua user (Hanya Super Admin).
     */
    public function index(Request $request)
    {
        $users = User::select('id', 'name', 'email', 'role', 'status', 'created_at')->get();

        return response()->json([
            'message' => 'Seluruh daftar user berhasil diambil',
            'count' => $users->count(),
            'data' => $users,
        ], 200);
    }

    /**
     * Membuat user baru (Hanya Super Admin).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:mahasiswa,tendik_1,tendik_2,tendik_3,tendik_4,tendik_5,tendik_6,tendik_7,tendik_8,kadep,kaprodi,sekprodi,sekdep'
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role']
        ]);

        // LOG ACTION
        AdminLog::create([
            'admin_id' => Auth::id(),
            'action' => 'Tambah User',
            'target_user' => $user->email,
            'details' => "Menambahkan user baru dengan role: {$user->role}"
        ]);

        return response()->json([
            'message' => 'User berhasil dibuat',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
            ]
        ], 201);
    }

    /**
     * Detail user (Hanya Super Admin).
     */
    public function show(User $user)
    {
        return response()->json([
            'message' => 'Detail user berhasil diambil',
            'data' => $user
        ]);
    }

    /**
     * Update data user (Hanya Super Admin).
     */
    public function update(Request $request, User $user)
    {
        $oldRole = $user->role;

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8',
            'role' => 'sometimes|in:mahasiswa,tendik_1,tendik_2,tendik_3,tendik_4,tendik_5,tendik_6,tendik_7,tendik_8,kadep,kaprodi,sekprodi,sekdep,super_admin',
            'status' => 'sometimes|in:Active,Inactive,Blocked'
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        // LOG ACTION
        $details = "Update data user.";
        if (isset($validated['role']) && $oldRole !== $validated['role']) {
            $details .= " Perubahan role dari {$oldRole} ke {$validated['role']}.";
        }

        AdminLog::create([
            'admin_id' => Auth::id(),
            'action' => 'Update User',
            'target_user' => $user->email,
            'details' => $details
        ]);

        return response()->json([
            'message' => 'User berhasil diperbarui',
            'data' => $user
        ]);
    }

    /**
     * Hapus user (Hanya Super Admin).
     */
    public function destroy(User $user)
    {
        $targetEmail = $user->email;
        $user->delete();

        // LOG ACTION
        AdminLog::create([
            'admin_id' => Auth::id(),
            'action' => 'Hapus User',
            'target_user' => $targetEmail,
            'details' => "Menghapus user permanen."
        ]);

        return response()->json([
            'message' => "User {$user->name} berhasil dihapus."
        ]);
    }

    /**
     * Blokir user (Hanya Super Admin).
     */
    public function block(User $user)
    {
        $user->status = 'Blocked';
        $user->save();

        // Putus semua sesi login user yang diblokir
        $user->tokens()->delete();

        // LOG ACTION
        AdminLog::create([
            'admin_id' => Auth::id(),
            'action' => 'Blokir User',
            'target_user' => $user->email,
            'details' => "Status user diubah menjadi Blocked."
        ]);

        return response()->json([
            'message' => "User {$user->name} berhasil diblokir.",
            'data' => $user
        ]);
    }

    /**
     * Buka blokir user (Hanya Super Admin).
     */
    public function unblock(User $user)
    {
        $user->status = 'Inactive';
        $user->save();

        // LOG ACTION
        AdminLog::create([
            'admin_id' => Auth::id(),
            'action' => 'Unblock User',
            'target_user' => $user->email,
            'details' => "Status user dikembalikan ke Inactive."
        ]);

        return response()->json([
            'message' => "Blokir user {$user->name} telah dibuka.",
            'data' => $user
        ]);
    }

    /**
     * Laporan aktivitas login (Hanya Super Admin).
     */
    public function loginReport()
    {
        $now = now();

        $report = [
            'today' => \App\Models\LoginLog::whereDate('created_at', $now->toDateString())->count(),
            'this_week' => \App\Models\LoginLog::where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'last_1_month' => \App\Models\LoginLog::where('created_at', '>=', $now->copy()->subMonth())->count(),
            'last_3_months' => \App\Models\LoginLog::where('created_at', '>=', $now->copy()->subMonths(3))->count(),
            'last_6_months' => \App\Models\LoginLog::where('created_at', '>=', $now->copy()->subMonths(6))->count(),
            'last_12_months' => \App\Models\LoginLog::where('created_at', '>=', $now->copy()->subYear())->count(),
        ];

        return response()->json([
            'message' => 'Laporan aktivitas login berhasil diambil',
            'data' => $report
        ]);
    }

    /**
     * Laporan aktivitas admin (Hanya Super Admin).
     */
    public function activityLog()
    {
        $logs = AdminLog::with('admin:id,name,email')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'message' => 'Log aktivitas admin berhasil diambil',
            'data' => $logs
        ]);
    }

    /**
     * Export data user ke CSV (Hanya Super Admin).
     */
    public function export()
    {
        $users = User::all(['id', 'name', 'email', 'role', 'status', 'created_at']);

        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=users_export_' . now()->format('Ymd_His') . '.csv',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0'
        ];

        $callback = function () use ($users) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID', 'Name', 'Email', 'Role', 'Status', 'Created At']);

            foreach ($users as $user) {
                fputcsv($file, [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->role,
                    $user->status,
                    $user->created_at
                ]);
            }
            fclose($file);
        };

        return new StreamedResponse($callback, 200, $headers);
    }

    /**
     * Import data user dari CSV (Hanya Super Admin).
     */
    public function bulkImport(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt|max:2048',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        // Skip header
        fgetcsv($handle);

        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        $rowCount = 1;

        while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
            $rowCount++;

            // Expected format: name, email, role, password
            if (count($data) < 4) {
                $errors[] = "Baris {$rowCount}: Format kolom tidak lengkap.";
                $errorCount++;
                continue;
            }

            $input = [
                'name' => $data[0],
                'email' => $data[1],
                'role' => $data[2],
                'password' => $data[3],
            ];

            $validator = Validator::make($input, [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'role' => 'required|in:mahasiswa,tendik_1,tendik_2,tendik_3,tendik_4,tendik_5,tendik_6,tendik_7,tendik_8,kadep,kaprodi,sekprodi,sekdep',
                'password' => 'required|string|min:8',
            ]);

            if ($validator->fails()) {
                $errors[] = "Baris {$rowCount} ({$input['email']}): " . implode(', ', $validator->errors()->all());
                $errorCount++;
                continue;
            }

            User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'role' => $input['role'],
                'password' => Hash::make($input['password']),
            ]);

            $successCount++;
        }

        fclose($handle);

        // LOG ACTION
        AdminLog::create([
            'admin_id' => Auth::id(),
            'action' => 'Bulk Import User',
            'target_user' => 'Multiple Users',
            'details' => "Berhasil mengimpor {$successCount} user. Gagal: {$errorCount}."
        ]);

        return response()->json([
            'message' => 'Proses bulk import selesai',
            'summary' => [
                'success' => $successCount,
                'failed' => $errorCount,
            ],
            'errors' => $errors
        ], $successCount > 0 ? 200 : 422);
    }
}
