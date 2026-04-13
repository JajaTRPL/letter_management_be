<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\UsersExport;
use App\Exports\MultiUsersExport;
use Maatwebsite\Excel\Excel as ExcelFormat;

class UserController extends Controller
{
    /**
     * Mengambil daftar semua user (Hanya Super Admin).
     */
    public function index(Request $request)
    {
        $users = User::where('role', '!=', 'super_admin')
            ->with('mahasiswaProfile')
            ->select('id', 'name', 'email', 'role', 'status', 'created_at')
            ->get();

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
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:mahasiswa,tendik,akademik,super_admin',
            'sub_role' => 'nullable|in:kadep,kaprodi,sekprodi,sekdep',
            'assigned_tasks' => 'nullable|array',
            'nim' => 'nullable|string|unique:mahasiswa_profiles,nim',
            'fakultas' => 'nullable|string',
            'program_studi' => 'nullable|string',
            'tanggal_lahir' => 'nullable|date',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'sub_role' => $validated['sub_role'] ?? null,
            'assigned_tasks' => $validated['assigned_tasks'] ?? null,
            'status' => 'Active'
        ]);

        // Create Profile if Mahasiswa
        if ($user->role === 'mahasiswa') {
            \App\Models\MahasiswaProfile::create([
                'user_id' => $user->id,
                'nim' => $request->nim,
                'fakultas' => $request->fakultas,
                'program_studi' => $request->program_studi,
                'tanggal_lahir' => $request->tanggal_lahir,
            ]);
        }

        // LOG ACTION
        ActivityLog::create([
            'user_id' => Auth::id(),
            'type' => 'admin',
            'action' => 'Tambah User',
            'target_user' => $user->email,
            'details' => "Menambahkan user baru dengan role: {$user->role}",
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
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
            'data' => $user->load('mahasiswaProfile')
        ]);
    }

    /**
     * Update data user (Hanya Super Admin).
     */
    public function update(Request $request, User $user)
    {
        $oldRole = $user->role;

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
            'role' => 'nullable|in:mahasiswa,tendik,akademik,super_admin',
            'sub_role' => 'nullable|in:kadep,kaprodi,sekprodi,sekdep',
            'assigned_tasks' => 'nullable|array',
            'status' => 'nullable|in:Active,Inactive,Blocked',
            // Mahasiswa details
            'nim' => 'sometimes|nullable|string|unique:mahasiswa_profiles,nim,' . ($user->mahasiswaProfile->id ?? 'NULL'),
            'fakultas' => 'sometimes|nullable|string',
            'program_studi' => 'sometimes|nullable|string',
            'tanggal_lahir' => 'sometimes|nullable|date',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        // Update Profile if Mahasiswa
        if ($user->role === 'mahasiswa') {
            \App\Models\MahasiswaProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'nim' => $request->nim ?? $user->mahasiswaProfile?->nim,
                    'fakultas' => $request->fakultas ?? $user->mahasiswaProfile?->fakultas,
                    'program_studi' => $request->program_studi ?? $user->mahasiswaProfile?->program_studi,
                    'tanggal_lahir' => $request->tanggal_lahir ?? $user->mahasiswaProfile?->tanggal_lahir,
                ]
            );
        }

        // LOG ACTION
        $details = "Update data user.";
        if (isset($validated['role']) && $oldRole !== $validated['role']) {
            $details .= " Perubahan role dari {$oldRole} ke {$validated['role']}.";
        }

        ActivityLog::create([
            'user_id' => Auth::id(),
            'type' => 'admin',
            'action' => 'Update User',
            'target_user' => $user->email,
            'details' => $details,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
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
        if ($user->role === 'super_admin') {
            return response()->json(['message' => 'Tidak dapat menghapus akun Super Admin'], 403);
        }

        $targetEmail = $user->email;
        $user->delete();

        // LOG ACTION
        ActivityLog::create([
            'user_id' => Auth::id(),
            'type' => 'admin',
            'action' => 'Hapus User',
            'target_user' => $targetEmail,
            'details' => "Menghapus user permanen.",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
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
        ActivityLog::create([
            'user_id' => Auth::id(),
            'type' => 'admin',
            'action' => 'Blokir User',
            'target_user' => $user->email,
            'details' => "Status user diubah menjadi Blocked.",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
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
        $user->status = 'Active';
        $user->save();

        // LOG ACTION
        ActivityLog::create([
            'user_id' => Auth::id(),
            'type' => 'admin',
            'action' => 'Unblock User',
            'target_user' => $user->email,
            'details' => "Status user dikembalikan ke Active.",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
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
            'today' => ActivityLog::where('type', 'login')->whereDate('created_at', $now->toDateString())->count(),
            'this_week' => ActivityLog::where('type', 'login')->where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'last_1_month' => ActivityLog::where('type', 'login')->where('created_at', '>=', $now->copy()->subMonth())->count(),
            'last_3_months' => ActivityLog::where('type', 'login')->where('created_at', '>=', $now->copy()->subMonths(3))->count(),
            'last_6_months' => ActivityLog::where('type', 'login')->where('created_at', '>=', $now->copy()->subMonths(6))->count(),
            'last_12_months' => ActivityLog::where('type', 'login')->where('created_at', '>=', $now->copy()->subYear())->count(),
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
        $logs = ActivityLog::with('user:id,name,email')
            ->where('type', '!=', 'login') // Hanya munculkan CRUD (admin actions)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'message' => 'Log aktivitas admin berhasil diambil',
            'data' => $logs
        ]);
    }

    /**
     * Export data user ke CSV atau XLSX (Hanya Super Admin).
     */
    public function export(Request $request)
    {
        $format = $request->query('format', 'csv');
        $role = $request->query('role');

        $fileName = 'users_export_' . now()->format('Ymd_His');
        $export = new UsersExport($role);

        if ($format === 'xlsx') {
            if (!$role) {
                return Excel::download(new MultiUsersExport, $fileName . '.xlsx');
            }
            return Excel::download($export, $fileName . '.xlsx');
        }

        // Manual CSV Export for better reliability
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName.csv",
        ];

        return response()->stream(function () use ($export) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID', 'Nama', 'Email', 'Role', 'Status', 'NIM', 'Fakultas', 'Prodi', 'Tanggal Lahir', 'Dibuat Pada']);

            foreach ($export->collection() as $user) {
                fputcsv($file, $export->map($user));
            }
            fclose($file);
        }, 200, $headers);
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
                'sub_role' => $data[3] ?? null,
                'password' => $data[4] ?? 'password123',
            ];

            $validator = Validator::make($input, [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'role' => 'required|in:mahasiswa,tendik,akademik',
                'sub_role' => 'nullable|in:kadep,kaprodi,sekprodi,sekdep',
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
                'status' => 'Active'
            ]);

            $successCount++;
        }

        fclose($handle);

        // LOG ACTION
        ActivityLog::create([
            'user_id' => Auth::id(),
            'type' => 'admin',
            'action' => 'Bulk Import User',
            'target_user' => 'Multiple Users',
            'details' => "Berhasil mengimpor {$successCount} user. Gagal: {$errorCount}.",
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
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

    /**
     * Download template CSV untuk import user (Hanya Super Admin).
     */
    public function importTemplate()
    {
        $fileName = 'template_import_users.csv';
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0'
        ];

        return response()->stream(function () {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['name', 'email', 'role', 'sub_role', 'password']);
            fputcsv($file, ['John Doe', 'john.doe@example.com', 'mahasiswa', '', 'password123']);
            fputcsv($file, ['Jane Staff', 'jane.staff@example.com', 'akademik', 'kadep', 'password123']);
            fclose($file);
        }, 200, $headers);
    }
}
