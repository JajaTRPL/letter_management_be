<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use App\Models\StudyProgram;
use App\Helpers\NimHelper;
use App\Helpers\DateHelper;
use App\Services\ActivityLogService;
use App\Enums\UserStatus;
use App\Support\LetterTypeRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\UsersExport;
use App\Exports\MultiUsersExport;
use Maatwebsite\Excel\Excel as ExcelFormat;

class UserController extends Controller
{
    /**
     * Mengambil daftar user dengan pagination, search, dan filter.
     *
     * Query params:
     *   page, per_page (max 100, default 25)
     *   role, status, study_program_id, department_id
     *   search  — matches name, email, nip, or mahasiswaProfile.nim
     */
    public function index(Request $request)
    {
        $perPage    = min((int) $request->get('per_page', 25), 100);
        $search     = $request->get('search');
        $role       = $request->get('role');
        $status     = $request->get('status');
        $studyProgramId = $request->get('study_program_id');
        $departmentId   = $request->get('department_id');

        $query = User::with([
            'mahasiswaProfile:id,user_id,nim,tanggal_lahir',
            'studyProgram:id,code,name,department_id',
            'studyProgram.department:id,code,name,faculty_id',
            'studyProgram.department.faculty:id,code,name',
            'department:id,code,name',
            'laboratory:id,code,name',
        ])
        ->select('id', 'name', 'email', 'nip', 'role', 'sub_role', 'tendik_role', 'laboratory_id', 'study_program_id', 'department_id', 'role_level', 'status', 'assigned_tasks', 'created_at')
        ->orderBy('created_at', 'desc');

        if ($role) {
            $query->where('role', $role);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $likeOp = \DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $pattern = "%{$search}%";
            $query->where(function ($q) use ($pattern, $likeOp) {
                $q->where('name', $likeOp, $pattern)
                  ->orWhere('email', $likeOp, $pattern)
                  ->orWhere('nip', $likeOp, $pattern)
                  ->orWhereHas('mahasiswaProfile', function ($pq) use ($pattern, $likeOp) {
                      $pq->where('nim', $likeOp, $pattern);
                  });
            });
        }

        if ($studyProgramId) {
            $query->where('study_program_id', (int) $studyProgramId);
        }

        if ($departmentId) {
            $query->where('department_id', (int) $departmentId);
        }

        $paginated = $query->paginate($perPage);

        return response()->json([
            'message' => 'Seluruh daftar user berhasil diambil',
            'data'    => $paginated->items(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Membuat user baru (Hanya Super Admin).
     * Untuk membuat super_admin baru, harus Primary Super Admin (enforced via middleware).
     */
    public function store(Request $request)
    {
        if (
            auth()->user()->role === 'super_admin' &&
            auth()->user()->role_level === 'secondary' &&
            $request->role === 'super_admin'
        ) {
            abort(403, 'Unauthorized to create super admin');
        }

        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'nip' => ['nullable', 'string', 'max:50', Rule::unique('users', 'nip')],
            'password' => 'required|string|min:8',
            'role' => 'required|in:mahasiswa,tendik,akademik,super_admin',
            'sub_role' => 'nullable|in:kadep,kaprodi,sekprodi,sekdep',
            'tendik_role' => 'nullable|in:persuratan,sarpras,kepala_lab,laboran',
            'laboratory_id' => 'nullable|integer|exists:laboratories,id',
            'study_program_id' => 'nullable|integer|exists:study_programs,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'role_level' => 'nullable|in:primary,secondary',
            'assigned_tasks' => 'nullable|array',
            'assigned_tasks.*' => ['string', Rule::in(LetterTypeRegistry::canonicalKeys())],
            'nim' => 'nullable|string|unique:mahasiswa_profiles,nim',
            'tanggal_lahir' => 'nullable|date',
        ];

        $validated = $request->validate($rules);
        $validated['nip'] = $this->normalizeNip($validated['nip'] ?? null);

        // Enforce akademik-specific validation
        if ($validated['role'] === 'akademik') {
            if (empty($validated['sub_role'])) {
                return response()->json(['message' => 'Jabatan akademik wajib diisi.'], 422);
            }
            if (in_array($validated['sub_role'], ['kaprodi', 'sekprodi'])) {
                if (empty($validated['study_program_id'])) {
                    return response()->json(['message' => 'Program studi wajib diisi untuk Kaprodi/Sekprodi.'], 422);
                }
                // Auto-derive department from study program
                $program = StudyProgram::find($validated['study_program_id']);
                $validated['department_id'] = $program?->department_id;
            } elseif (in_array($validated['sub_role'], ['kadep', 'sekdep'])) {
                if (empty($validated['department_id'])) {
                    return response()->json(['message' => 'Departemen wajib diisi untuk Kadep/Sekdep.'], 422);
                }
                $validated['study_program_id'] = null;
            }
        } else if ($validated['role'] === 'mahasiswa') {
            if (empty($validated['study_program_id'])) {
                return response()->json(['message' => 'Program Studi wajib dipilih untuk Mahasiswa.'], 422);
            }
        } else {
            $validated['study_program_id'] = null;
            $validated['department_id'] = null;
        }

        // Enforce tendik-specific validation
        if ($validated['role'] === 'tendik') {
            $validated['tendik_role'] = $validated['tendik_role'] ?? 'persuratan';
            if (in_array($validated['tendik_role'], ['kepala_lab', 'laboran'])) {
                if (empty($validated['laboratory_id'])) {
                    return response()->json(['message' => 'Laboratorium wajib dipilih jika peran adalah Kepala Lab atau Laboran.'], 422);
                }
            } else {
                $validated['laboratory_id'] = null;
            }
        } else {
            $validated['tendik_role'] = null;
            $validated['laboratory_id'] = null;
        }
        $validated['assigned_tasks'] = $this->assignedTasksForRole(
            $validated['role'],
            $validated['tendik_role'] ?? null,
            $validated['assigned_tasks'] ?? null
        );

        // Only Primary Super Admin can create super_admin accounts
        if ($validated['role'] === 'super_admin') {
            if (!Auth::user()->isPrimarySuperAdmin()) {
                return response()->json([
                    'message' => 'Hanya Primary Super Admin yang dapat membuat akun Super Admin baru.'
                ], 403);
            }
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'nip' => $validated['nip'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'sub_role' => $validated['role'] === 'akademik' ? ($validated['sub_role'] ?? null) : null,
            'tendik_role' => $validated['tendik_role'] ?? null,
            'laboratory_id' => $validated['laboratory_id'] ?? null,
            'study_program_id' => $validated['study_program_id'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'role_level' => $validated['role'] === 'super_admin' ? ($validated['role_level'] ?? 'secondary') : null,
            'assigned_tasks' => $validated['assigned_tasks'] ?? null,
            'status' => UserStatus::Active
        ]);

        // Create Profile if Mahasiswa
        if ($user->role === 'mahasiswa') {
            \App\Models\MahasiswaProfile::create([
                'user_id'     => $user->id,
                'nim'         => NimHelper::normalize($request->nim),
                'tanggal_lahir' => $request->tanggal_lahir,
                'data_source' => 'admin_create',
            ]);
        }

        // LOG ACTION
        ActivityLogService::log(
            'Tambah User',
            $user->email,
            "Menambahkan user baru dengan role: {$user->role}" . ($user->role_level ? " ({$user->role_level})" : "")
        );

        return response()->json([
            'message' => 'User berhasil dibuat',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'nip' => $user->nip,
                'role' => $user->role,
                'role_level' => $user->role_level,
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
        if ($user->id === auth()->id()) {
            if ($request->has('role') || $request->has('role_level')) {
                abort(403, 'Cannot modify your own role or role level');
            }
        }

        if (
            auth()->user()->role === 'super_admin' &&
            auth()->user()->role_level === 'secondary' &&
            $request->role_level === 'primary'
        ) {
            abort(403, 'Unauthorized to promote to primary');
        }

        if (
            auth()->user()->role === 'super_admin' &&
            auth()->user()->role_level === 'secondary' &&
            ($user->role === 'super_admin' || $request->role === 'super_admin')
        ) {
            abort(403, 'Unauthorized to edit or promote to super admin');
        }

        $oldRole = $user->role;
        $oldRoleLevel = $user->role_level;
        $currentUser = Auth::user();

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:users,email,' . $user->id,
            'nip' => ['nullable', 'string', 'max:50', Rule::unique('users', 'nip')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'role' => 'nullable|in:mahasiswa,tendik,akademik,super_admin',
            'sub_role' => 'nullable|in:kadep,kaprodi,sekprodi,sekdep',
            'tendik_role' => 'nullable|in:persuratan,sarpras,kepala_lab,laboran',
            'laboratory_id' => 'nullable|integer|exists:laboratories,id',
            'study_program_id' => 'nullable|integer|exists:study_programs,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'role_level' => 'nullable|in:primary,secondary',
            'assigned_tasks' => 'nullable|array',
            'assigned_tasks.*' => ['string', Rule::in(LetterTypeRegistry::canonicalKeys())],
            'status' => 'nullable|' . UserStatus::validationRule(),
            // Mahasiswa details
            'nim' => 'sometimes|nullable|string|unique:mahasiswa_profiles,nim,' . ($user->mahasiswaProfile->id ?? 'NULL'),
            'tanggal_lahir' => 'sometimes|nullable|date',
        ]);
        if (array_key_exists('nip', $validated)) {
            $validated['nip'] = $this->normalizeNip($validated['nip']);
        }

        // Super Admin role changes require Primary level
        $targetRole = $validated['role'] ?? $user->role;
        if ($targetRole === 'super_admin' || $user->role === 'super_admin') {
            if (!$currentUser->isPrimarySuperAdmin()) {
                return response()->json([
                    'message' => 'Hanya Primary Super Admin yang dapat mengubah akun Super Admin.'
                ], 403);
            }
        }

        // Prevent demoting the last Primary Super Admin
        if ($user->isPrimarySuperAdmin()) {
            $newRoleLevel = $validated['role_level'] ?? $user->role_level;
            $newRole = $validated['role'] ?? $user->role;
            
            if ($newRoleLevel !== 'primary' || $newRole !== 'super_admin') {
                $primaryCount = User::where('role', 'super_admin')
                    ->where('role_level', 'primary')
                    ->count();
                    
                if ($primaryCount <= 1) {
                    return response()->json([
                        'message' => 'Tidak dapat mengubah role. Sistem harus memiliki minimal 1 Primary Super Admin.'
                    ], 403);
                }
            }
        }

        // Ensure role_level only applies to super_admin
        $finalRole = $validated['role'] ?? $user->role;
        if ($finalRole !== 'super_admin') {
            $validated['role_level'] = null;
        }

        // Handle role-specific fields
        if (isset($validated['role'])) {
            if ($validated['role'] === 'super_admin') {
                $validated['role_level'] = $validated['role_level'] ?? $user->role_level ?? 'secondary';
                $validated['sub_role'] = null;
                $validated['study_program_id'] = null;
                $validated['department_id'] = null;
            } elseif ($validated['role'] === 'akademik') {
                $validated['role_level'] = null;
                $subRole = $validated['sub_role'] ?? $user->sub_role;
                if (in_array($subRole, ['kaprodi', 'sekprodi']) && !empty($validated['study_program_id'])) {
                    $program = StudyProgram::find($validated['study_program_id']);
                    $validated['department_id'] = $program?->department_id;
                } elseif (in_array($subRole, ['kadep', 'sekdep'])) {
                    $validated['study_program_id'] = null;
                }
            } else {
                $validated['role_level'] = null;
                $validated['sub_role'] = null;
                $validated['study_program_id'] = null;
                $validated['department_id'] = null;
            }

            // Handle tendik_role based on role
            if ($validated['role'] === 'tendik') {
                $validated['tendik_role'] = $validated['tendik_role'] ?? $user->tendik_role ?? 'persuratan';
                if (in_array($validated['tendik_role'], ['kepala_lab', 'laboran'])) {
                    // Check if laboratory_id is provided, otherwise fall back to user's existing laboratory_id
                    $labId = $validated['laboratory_id'] ?? $user->laboratory_id;
                    if (empty($labId)) {
                        return response()->json(['message' => 'Laboratorium wajib dipilih jika peran adalah Kepala Lab atau Laboran.'], 422);
                    }
                    $validated['laboratory_id'] = $labId;
                } else {
                    $validated['laboratory_id'] = null;
                }
            } else {
                $validated['tendik_role'] = null;
                $validated['laboratory_id'] = null;
            }
        }

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        if (
            array_key_exists('role', $validated)
            || array_key_exists('tendik_role', $validated)
            || array_key_exists('assigned_tasks', $validated)
        ) {
            $validated['assigned_tasks'] = $this->assignedTasksForRole(
                $validated['role'] ?? $user->role,
                $validated['tendik_role'] ?? $user->tendik_role,
                $validated['assigned_tasks'] ?? $user->assigned_tasks
            );
        }

        $user->update($validated);

        // Update Profile if Mahasiswa
        if ($user->role === 'mahasiswa') {
            \App\Models\MahasiswaProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'nim' => $request->nim ? NimHelper::normalize($request->nim) : $user->mahasiswaProfile?->nim,
                    'tanggal_lahir' => $request->tanggal_lahir ?? $user->mahasiswaProfile?->tanggal_lahir,
                ]
            );
        }

        // LOG ACTION
        $details = "Update data user.";
        if (isset($validated['role']) && $oldRole !== $validated['role']) {
            $details .= " Perubahan role dari {$oldRole} ke {$validated['role']}.";
        }
        if ($user->role === 'super_admin' && $oldRoleLevel !== $user->role_level) {
            $details .= " Perubahan level dari {$oldRoleLevel} ke {$user->role_level}.";
        }

        ActivityLogService::log('Update User', $user->email, $details);

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
        if (
            $user->role === 'super_admin' &&
            $user->role_level === 'primary'
        ) {
            abort(403, 'Primary super admin cannot be deleted');
        }

        if (
            auth()->user()->role === 'super_admin' &&
            auth()->user()->role_level === 'secondary' &&
            $user->role === 'super_admin'
        ) {
            abort(403, 'Unauthorized to delete super admin');
        }

        $currentUser = Auth::user();

        // Prevent deleting self
        if ($user->id === $currentUser->id) {
            return response()->json(['message' => 'Tidak dapat menghapus akun sendiri.'], 403);
        }

        // Super Admin deletion requires Primary level
        if ($user->role === 'super_admin') {
            if (!$currentUser->isPrimarySuperAdmin()) {
                return response()->json([
                    'message' => 'Hanya Primary Super Admin yang dapat menghapus akun Super Admin.'
                ], 403);
            }

            // Prevent deleting last Primary
            if ($user->isPrimarySuperAdmin()) {
                $primaryCount = User::where('role', 'super_admin')
                    ->where('role_level', 'primary')
                    ->count();
                    
                if ($primaryCount <= 1) {
                    return response()->json([
                        'message' => 'Tidak dapat menghapus Primary Super Admin terakhir.'
                    ], 403);
                }
            }
        }

        $targetEmail = $user->email;
        $user->delete();

        // LOG ACTION
        ActivityLogService::log('Hapus User', $targetEmail, 'Menghapus user permanen.');

        return response()->json([
            'message' => "User {$user->name} berhasil dihapus."
        ]);
    }

    /**
     * Suspend user (Hanya Super Admin).
     */
    public function block(User $user)
    {
        $currentUser = Auth::user();

        // Prevent suspending self
        if ($user->id === $currentUser->id) {
            return response()->json(['message' => 'Tidak dapat mensuspend akun sendiri.'], 403);
        }

        // Only Primary can suspend other super_admins
        if ($user->role === 'super_admin' && !$currentUser->isPrimarySuperAdmin()) {
            return response()->json([
                'message' => 'Hanya Primary Super Admin yang dapat mensuspend Super Admin lain.'
            ], 403);
        }

        $user->status = UserStatus::Suspended;
        $user->save();

        // Revoke all tokens immediately
        $user->tokens()->delete();

        // LOG ACTION
        ActivityLogService::log('Suspend User', $user->email, 'Status user diubah menjadi Suspended.');

        return response()->json([
            'message' => "User {$user->name} berhasil disuspend.",
            'data' => $user
        ]);
    }

    /**
     * Buka suspend user (Hanya Super Admin).
     * Restores to Pending_Profile if profile is incomplete, otherwise Active.
     */
    public function unblock(User $user)
    {
        if ($user->id === auth()->id()) {
            abort(403, 'Cannot unsuspend yourself');
        }

        if ($user->role === 'super_admin' && !auth()->user()->isPrimarySuperAdmin()) {
            abort(403, 'Unauthorized to unsuspend super admin');
        }

        // Restore to Pending_Profile if mahasiswa profile is incomplete
        if (
            $user->role === 'mahasiswa' &&
            (!$user->study_program_id || !$user->mahasiswaProfile || !$user->mahasiswaProfile->nim)
        ) {
            $user->status = UserStatus::PendingProfile;
        } else {
            $user->status = UserStatus::Active;
        }
        $user->save();

        // LOG ACTION
        ActivityLogService::log('Unsuspend User', $user->email, "Status user dikembalikan ke {$user->status}.");

        return response()->json([
            'message' => "Suspend user {$user->name} telah dibuka.",
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
            fputcsv($file, $export->headings());

            foreach ($export->collection() as $user) {
                fputcsv($file, $export->map($user));
            }
            fclose($file);
        }, 200, $headers);
    }



    /**
     * Validate a CSV file for mahasiswa import WITHOUT writing to DB.
     * Returns a preview with file_hash for consistency check on confirm.
     */
    public function validateImport(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt|max:2048',
        ]);

        $file = $request->file('file');

        // Generate SHA-256 hash for file consistency check
        $fileHash = hash_file('sha256', $file->getRealPath());

        $handle = fopen($file->getRealPath(), 'r');

        // Read and validate header row
        $header = fgetcsv($handle);
        if (!$header || count($header) < 4) {
            fclose($handle);
            return response()->json([
                'message' => 'Format header CSV tidak valid. Kolom wajib: name, email, nim, study_program_code',
            ], 422);
        }

        // Performance guard: count rows first
        $rowCount = 0;
        $startPos = ftell($handle);
        while (fgetcsv($handle, 1000, ',') !== false) $rowCount++;
        if ($rowCount > 5000) {
            fclose($handle);
            return response()->json([
                'message' => "File terlalu besar ({$rowCount} baris). Maksimal 5000 baris per import.",
            ], 422);
        }
        fseek($handle, $startPos); // reset to after header

        // Pre-load lookup data
        $studyProgramMap = StudyProgram::pluck('id', 'code')->toArray();
        $existingEmails = User::pluck('email')->map(fn($e) => strtolower($e))->toArray();
        $existingNims = \App\Models\MahasiswaProfile::pluck('nim')->toArray();

        $validRows = [];
        $invalidRows = [];
        $rowNumber = 1;
        $seenEmails = [];
        $seenNims = [];

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $rowNumber++;

            if (count($data) < 4) {
                $invalidRows[] = [
                    'row' => $rowNumber,
                    'data' => ['name' => $data[0] ?? '', 'email' => $data[1] ?? '', 'nim' => $data[2] ?? '', 'study_program_code' => '', 'tanggal_lahir' => ''],
                    'errors' => ['Format kolom tidak lengkap (minimal: name, email, nim, study_program_code).'],
                ];
                continue;
            }

            // Normalize date before building row
            $normalizedDate = DateHelper::normalizeDate(isset($data[4]) ? $data[4] : null);

            $row = [
                'name'                => trim($data[0]),
                'email'               => trim($data[1]),
                'nim'                 => NimHelper::normalize(trim($data[2])),
                'study_program_code'  => strtoupper(trim($data[3])),
                'tanggal_lahir'       => $normalizedDate,
            ];

            $errors = [];

            if (empty($row['name'])) $errors[] = 'Nama wajib diisi.';
            if (empty($row['email'])) $errors[] = 'Email wajib diisi.';
            if (empty($row['nim'])) $errors[] = 'NIM wajib diisi.';

            if ($row['email'] && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Format email tidak valid.';
            }
            if ($row['email'] && in_array(strtolower($row['email']), $existingEmails)) {
                // Not an error — will be merged during import
                // Only flag if the existing user is NOT mahasiswa (safety)
                $existingUser = User::where('email', strtolower($row['email']))->first();
                if ($existingUser && $existingUser->role !== 'mahasiswa') {
                    $errors[] = 'Email milik user non-mahasiswa. Tidak dapat di-merge.';
                }
            }
            if ($row['email'] && in_array(strtolower($row['email']), $seenEmails)) {
                $errors[] = 'Email duplikat dalam file CSV.';
            }
            if ($row['nim'] && in_array($row['nim'], $existingNims)) {
                // Check if this NIM belongs to the same user being merged
                $nimOwner = \App\Models\MahasiswaProfile::where('nim', $row['nim'])->first();
                $emailOwner = $row['email'] ? User::where('email', strtolower($row['email']))->first() : null;
                if (!$nimOwner || !$emailOwner || $nimOwner->user_id !== $emailOwner->id) {
                    $errors[] = 'NIM sudah terdaftar di sistem.';
                }
            }
            if ($row['nim'] && in_array($row['nim'], $seenNims)) {
                $errors[] = 'NIM duplikat dalam file CSV.';
            }

            $resolvedProgram = null;
            if (empty($row['study_program_code'])) {
                $errors[] = 'Kode Program Studi wajib diisi.';
            } elseif (!isset($studyProgramMap[$row['study_program_code']])) {
                $errors[] = "Kode Program Studi '{$row['study_program_code']}' tidak ditemukan.";
            } else {
                $resolvedProgram = $row['study_program_code'];
            }

            // Validate normalized date (null = not provided OR unrecognized format)
            if (isset($data[4]) && trim($data[4]) !== '' && $normalizedDate === null) {
                $errors[] = 'Format tanggal lahir tidak dikenali. Gunakan YYYY-MM-DD atau DD/MM/YYYY.';
            }

            if ($row['email']) $seenEmails[] = strtolower($row['email']);
            if ($row['nim']) $seenNims[] = $row['nim'];

            if (count($errors) > 0) {
                $invalidRows[] = ['row' => $rowNumber, 'data' => $row, 'errors' => $errors];
            } else {
                $validRows[] = ['row' => $rowNumber, 'data' => $row, 'resolved_program' => $resolvedProgram];
            }
        }

        fclose($handle);

        return response()->json([
            'message'      => 'Validasi selesai',
            'file_hash'    => $fileHash,
            'summary'      => [
                'total'   => count($validRows) + count($invalidRows),
                'valid'   => count($validRows),
                'invalid' => count($invalidRows),
            ],
            'valid_rows'   => $validRows,
            'invalid_rows' => $invalidRows,
        ]);
    }

    /**
     * Import data mahasiswa dari CSV (Hanya Super Admin).
     * Accepts optional file_hash param for consistency check with validateImport.
     */
    public function bulkImport(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt|max:2048',
            'file_hash' => 'nullable|string',
        ]);

        $file = $request->file('file');

        // File consistency check: verify hash matches validation step
        if ($request->file_hash) {
            $currentHash = hash_file('sha256', $file->getRealPath());
            if ($currentHash !== $request->file_hash) {
                return response()->json([
                    'message' => 'File telah berubah sejak validasi. Silakan validasi ulang.',
                ], 422);
            }
        }

        $handle = fopen($file->getRealPath(), 'r');
        fgetcsv($handle); // Skip header

        $studyProgramMap = StudyProgram::pluck('id', 'code')->toArray();
        $batchId = (string) \Illuminate\Support\Str::uuid();

        $successCount = 0;
        $failedCount = 0;
        $errors = [];
        $rowNumber = 1;

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $rowNumber++;

            if (count($data) < 4) {
                $errors[] = "Baris {$rowNumber}: Format kolom tidak lengkap.";
                $failedCount++;
                continue;
            }

            $normalizedDate = DateHelper::normalizeDate(isset($data[4]) ? $data[4] : null);

            $input = [
                'name'                => trim($data[0]),
                'email'               => trim($data[1]),
                'nim'                 => NimHelper::normalize(trim($data[2])),
                'study_program_code'  => strtoupper(trim($data[3])),
                'tanggal_lahir'       => $normalizedDate,
            ];

            // Check if user already exists (merge scenario: Google SSO user)
            $existingUser = User::where('email', $input['email'])->first();

            // Validate (skip email unique check if merging existing user)
            $emailRule = $existingUser ? 'required|email' : 'required|email|unique:users,email';
            $nimRule = 'required|string';
            // Check NIM uniqueness (skip if merging and profile already has this NIM)
            $existingProfile = $existingUser ? \App\Models\MahasiswaProfile::where('user_id', $existingUser->id)->first() : null;
            if (!$existingProfile || $existingProfile->nim !== $input['nim']) {
                $nimRule .= '|unique:mahasiswa_profiles,nim';
            }

            $validator = Validator::make($input, [
                'name'                => 'required|string|max:255',
                'email'               => $emailRule,
                'nim'                 => $nimRule,
                'study_program_code'  => 'required|string',
            ]);

            if ($validator->fails()) {
                $errors[] = "Baris {$rowNumber} ({$input['email']}): " . implode(', ', $validator->errors()->all());
                $failedCount++;
                continue;
            }

            $studyProgramId = $studyProgramMap[$input['study_program_code']] ?? null;
            if (!$studyProgramId) {
                $errors[] = "Baris {$rowNumber} ({$input['email']}): Kode Prodi '{$input['study_program_code']}' tidak ditemukan.";
                $failedCount++;
                continue;
            }

            $nimClean = preg_replace('/[^a-zA-Z0-9]/', '', $input['nim']);
            $dobFormatted = '';
            if ($input['tanggal_lahir']) {
                $parts = explode('-', $input['tanggal_lahir']);
                if (count($parts) === 3) {
                    $dobFormatted = $parts[2] . $parts[1] . $parts[0];
                }
            }

            if ($existingUser) {
                // Safety: only merge with mahasiswa users
                if ($existingUser->role !== 'mahasiswa') {
                    $errors[] = "Baris {$rowNumber} ({$input['email']}): Email milik user non-mahasiswa. Tidak dapat di-merge.";
                    $failedCount++;
                    continue;
                }

                // MERGE: update existing user (e.g., Google-created pending_profile)
                $existingUser->update([
                    'name'             => $input['name'],
                    'study_program_id' => $studyProgramId,
                    'status'           => UserStatus::Active,
                ]);
                // Set password if they don't have one (Google-only users)
                if (!$existingUser->password) {
                    $existingUser->update(['password' => Hash::make($nimClean . $dobFormatted)]);
                }

                \App\Models\MahasiswaProfile::updateOrCreate(
                    ['user_id' => $existingUser->id],
                    [
                        'nim'             => $input['nim'],
                        'tanggal_lahir'   => $input['tanggal_lahir'],
                        'import_batch_id' => $batchId,
                        'data_source'     => 'import_manual',
                    ]
                );
            } else {
                // CREATE: new user
                $user = User::create([
                    'name'             => $input['name'],
                    'email'            => $input['email'],
                    'password'         => Hash::make($nimClean . $dobFormatted),
                    'role'             => 'mahasiswa',
                    'study_program_id' => $studyProgramId,
                    'status'           => UserStatus::Active,
                ]);

                \App\Models\MahasiswaProfile::create([
                    'user_id'         => $user->id,
                    'nim'             => $input['nim'],
                    'tanggal_lahir'   => $input['tanggal_lahir'],
                    'import_batch_id' => $batchId,
                    'data_source'     => 'import_manual',
                ]);
            }

            $successCount++;
        }

        fclose($handle);

        ActivityLogService::log(
            'Bulk Import Mahasiswa',
            'Multiple Mahasiswa',
            "Batch: {$batchId}. Berhasil: {$successCount}. Gagal: {$failedCount}."
        );

        return response()->json([
            'message'  => 'Proses import mahasiswa selesai',
            'batch_id' => $batchId,
            'summary'  => [
                'success' => $successCount,
                'failed'  => $failedCount,
            ],
            'errors' => $errors,
        ], $successCount > 0 ? 200 : 422);
    }

    /**
     * Generate downloadable CSV of invalid rows from validation step.
     */
    public function importErrors(Request $request)
    {
        $request->validate([
            'invalid_rows' => 'required|array',
        ]);

        $invalidRows = $request->invalid_rows;
        $fileName = 'import_errors_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
        ];

        return response()->stream(function () use ($invalidRows) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Baris', 'Nama', 'Email', 'NIM', 'Kode Prodi', 'Tanggal Lahir', 'Error']);

            foreach ($invalidRows as $row) {
                $data = $row['data'] ?? [];
                fputcsv($file, [
                    $row['row'] ?? '-',
                    $data['name'] ?? '-',
                    $data['email'] ?? '-',
                    $data['nim'] ?? '-',
                    $data['study_program_code'] ?? '-',
                    $data['tanggal_lahir'] ?? '-',
                    implode('; ', $row['errors'] ?? []),
                ]);
            }

            fclose($file);
        }, 200, $headers);
    }

    /**
     * Download template CSV untuk import mahasiswa (Hanya Super Admin).
     */
    public function importTemplate()
    {
        $fileName = 'template_import_mahasiswa.csv';
        $headers = [
            'Content-type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['name', 'email', 'nim', 'study_program_code', 'tanggal_lahir']);
            fputcsv($file, ['John Doe', 'john.doe@mail.ugm.ac.id', '24/535278/SV/12345', 'TRPL', '2004-05-15']);
            fputcsv($file, ['Jane Smith', 'jane.smith@mail.ugm.ac.id', '24/535279/SV/12346', 'TRI', '2004-08-22']);
            fclose($file);
        }, 200, $headers);
    }

    private function assignedTasksForRole(string $role, ?string $tendikRole, ?array $assignedTasks): ?array
    {
        if ($role !== 'tendik' || $tendikRole !== 'persuratan') {
            return null;
        }

        return array_values(array_unique($assignedTasks ?? []));
    }

    private function normalizeNip(?string $nip): ?string
    {
        $nip = trim((string) $nip);

        return $nip !== '' ? $nip : null;
    }
}
