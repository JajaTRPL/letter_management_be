<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use App\Models\StudyProgram;
use App\Helpers\NimHelper;
use App\Helpers\DateHelper;
use App\Services\ActivityLogService;
use App\Services\MahasiswaImportException;
use App\Services\MahasiswaImportService;
use App\Enums\UserStatus;
use App\Support\LetterTypeRegistry;
use App\Support\SpreadsheetSafety;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ArraySheetExport;
use App\Exports\MahasiswaImportTemplateExport;
use App\Exports\UsersExport;
use App\Exports\UsersExportBundle;
use App\Exports\MultiUsersExport;
use Maatwebsite\Excel\Excel as ExcelFormat;

class UserController extends Controller
{
    public function __construct(
        private MahasiswaImportService $mahasiswaImporter,
    ) {
    }

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
            // Direct department relation (Kadep/Sekdep) must also expose faculty
            // so the frontend can resolve Fakultas without falling back to '-'.
            'department:id,code,name,faculty_id',
            'department.faculty:id,code,name',
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
     *
     * Privacy-safe by default: tanggal_lahir (PII) only with include_pii=1.
     * Password/token/google_id are never part of the export contract.
     * Every export is recorded in the activity log.
     */
    public function export(Request $request)
    {
        $request->validate([
            'format' => 'nullable|in:csv,xlsx',
            'role' => 'nullable|in:mahasiswa,tendik,akademik',
            'include_pii' => 'nullable|boolean',
            'export_reason' => 'nullable|string|max:500',
        ]);

        $format = $request->query('format', 'csv');
        $role = $request->query('role');
        $includePii = $request->boolean('include_pii');

        // Governance: exporting PII requires a stated, audited reason.
        if ($includePii) {
            $request->validate(
                ['export_reason' => 'required|string|min:5|max:500'],
                [
                    'export_reason.required' => 'Alasan ekspor data pribadi wajib diisi.',
                    'export_reason.min' => 'Alasan ekspor data pribadi minimal 5 karakter.',
                ]
            );
        }

        $fileName = 'users_export_' . now()->format('Ymd_His');
        $export = new UsersExport($role, $includePii);
        $rowCount = $export->query()->count();

        ActivityLogService::log(
            'Export Users',
            $role ?: 'semua-role',
            sprintf(
                'Ekspor data user. Format: %s. Role: %s. Data pribadi: %s. Baris: %d.',
                $format,
                $role ?: 'semua',
                $includePii ? 'ya' : 'tidak',
                $rowCount
            ) . ($includePii ? ' Alasan: ' . $request->input('export_reason') : '')
        );

        if ($format === 'xlsx') {
            // Bundle appends an "Info Ekspor" provenance sheet (who/when/
            // filters/PII flag/classification) after the data sheet(s).
            return Excel::download(
                new UsersExportBundle($role, $includePii, Auth::user()->name, $rowCount),
                $fileName . '.xlsx'
            );
        }

        // Manual CSV export: streams row-by-row via cursor (memory-safe).
        $headers = [
            'Content-type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=$fileName.csv",
        ];

        return response()->stream(function () use ($export) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($file, $export->headings());

            foreach ($export->query()->cursor() as $user) {
                fputcsv($file, $export->map($user));
            }
            fclose($file);
        }, 200, $headers);
    }



    /**
     * Dry-run: validate a CSV/XLSX file for mahasiswa import WITHOUT
     * touching users/profiles. Persists an ImportBatch (+ row plan) for
     * audit and the server-side error report, and returns the plan
     * summary (create/update/skip/fail) for the confirmation UI.
     */
    public function validateImport(Request $request)
    {
        $this->validateUploadedImportFile($request);

        $file = $request->file('file');
        $override = $request->boolean('override_existing_active');

        // Governance: overriding active-student data is the riskiest path —
        // Primary Super Admin only, and a stated reason is mandatory.
        if ($override) {
            if (!Auth::user()->isPrimarySuperAdmin()) {
                return response()->json([
                    'message' => 'Hanya Primary Super Admin yang dapat menggunakan mode perbarui data mahasiswa aktif.',
                ], 403);
            }

            $request->validate(
                ['override_reason' => 'required|string|min:5|max:500'],
                [
                    'override_reason.required' => 'Alasan penggunaan mode perbarui data wajib diisi.',
                    'override_reason.min' => 'Alasan penggunaan mode perbarui data minimal 5 karakter.',
                ]
            );
        }

        $fileHash = hash_file('sha256', $file->getRealPath());

        try {
            $parsed = $this->mahasiswaImporter->parse($file);
            $plan = $this->mahasiswaImporter->plan($parsed['rows'], $override);
        } catch (MahasiswaImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($plan['summary']['total'] === 0) {
            return response()->json([
                'message' => 'File tidak berisi baris data. Isi data pada template lalu unggah ulang.',
            ], 422);
        }

        $batch = ImportBatch::create([
            'uuid' => (string) Str::uuid(),
            'kind' => ImportBatch::KIND_VERIFIED_MAHASISWA,
            'template_version' => MahasiswaImportService::TEMPLATE_VERSION,
            'source_format' => $parsed['source_format'],
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_hash' => $fileHash,
            'uploaded_by_user_id' => Auth::id(),
            'status' => ImportBatch::STATUS_VALIDATED,
            'override_existing_active' => $override,
            'override_reason' => $override ? $request->input('override_reason') : null,
            'total_rows' => $plan['summary']['total'],
            'valid_rows' => $plan['summary']['valid'],
            'invalid_rows' => $plan['summary']['invalid'],
            'expires_at' => now()->addDays(90),
        ]);

        $this->mahasiswaImporter->persistRows($batch, $plan['rows']);

        $validPreview = [];
        $invalidRows = [];
        foreach ($plan['rows'] as $entry) {
            $rowData = [
                'name' => $entry['data']['name'],
                'email' => $entry['data']['email'],
                'nim' => $entry['data']['nim'],
                'study_program_code' => $entry['data']['study_program_code'],
            ];

            if ($entry['status'] === ImportBatchRow::STATUS_INVALID) {
                if (count($invalidRows) < 100) {
                    $invalidRows[] = [
                        'row' => $entry['row_number'],
                        'data' => $rowData,
                        'errors' => $entry['errors'],
                    ];
                }
            } elseif (count($validPreview) < 10) {
                $validPreview[] = [
                    'row' => $entry['row_number'],
                    'action' => $entry['action'],
                    'data' => $rowData,
                    'note' => $entry['note'],
                ];
            }
        }

        return response()->json([
            'message' => 'Validasi selesai',
            'batch_id' => $batch->uuid,
            'file_hash' => $fileHash,
            'template_version' => MahasiswaImportService::TEMPLATE_VERSION,
            'source_format' => $parsed['source_format'],
            'summary' => $plan['summary'],
            'valid_rows' => $validPreview,
            'invalid_rows' => $invalidRows,
            'invalid_rows_truncated' => $plan['summary']['invalid'] > count($invalidRows),
        ]);
    }

    /**
     * Confirm import (Hanya Super Admin).
     *
     * Requires the batch_id + file_hash issued by validateImport and the
     * same file; the plan is recomputed against current DB state (using the
     * override flag stored on the batch) and committed in one transaction.
     */
    public function bulkImport(Request $request)
    {
        $this->validateUploadedImportFile($request);
        $request->validate([
            'batch_id' => 'required|string',
            'file_hash' => 'required|string',
        ]);

        $batch = ImportBatch::where('uuid', $request->input('batch_id'))->first();
        if (!$batch) {
            return response()->json([
                'message' => 'Sesi validasi tidak ditemukan. Silakan validasi ulang file.',
            ], 422);
        }
        if ($batch->status !== ImportBatch::STATUS_VALIDATED) {
            return response()->json([
                'message' => 'Batch impor ini sudah diproses. Silakan validasi ulang file.',
            ], 422);
        }

        if ($batch->override_existing_active && !Auth::user()->isPrimarySuperAdmin()) {
            return response()->json([
                'message' => 'Hanya Primary Super Admin yang dapat mengonfirmasi impor dengan mode perbarui data.',
            ], 403);
        }

        $file = $request->file('file');
        $currentHash = hash_file('sha256', $file->getRealPath());
        if ($currentHash !== $batch->file_hash || $request->input('file_hash') !== $batch->file_hash) {
            return response()->json([
                'message' => 'File telah berubah sejak validasi. Silakan validasi ulang.',
            ], 422);
        }

        // Snapshot the dry-run counts before commit() overwrites them, so we
        // can tell the operator when results drifted since validation.
        $validatedValid = $batch->valid_rows;
        $validatedInvalid = $batch->invalid_rows;

        try {
            $parsed = $this->mahasiswaImporter->parse($file);
            $plan = $this->mahasiswaImporter->plan($parsed['rows'], $batch->override_existing_active);
            $counts = $this->mahasiswaImporter->commit($batch, $plan, Auth::id());
        } catch (MahasiswaImportException $e) {
            $batch->update(['status' => ImportBatch::STATUS_FAILED]);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $batch->update(['status' => ImportBatch::STATUS_FAILED]);

            throw $e;
        }

        // Summary only — never row-level PII in the activity log. The
        // override reason is admin-authored text, part of the audit trail.
        ActivityLogService::log(
            'Bulk Import Mahasiswa',
            'Multiple Mahasiswa',
            "Batch: {$batch->uuid}. Dibuat: {$counts['created']}. Diperbarui: {$counts['updated']}. "
                . "Dilewati: {$counts['skipped']}. Gagal: {$counts['failed']}."
                . ($batch->override_existing_active ? " Mode perbarui aktif. Alasan: {$batch->override_reason}" : '')
        );

        $processed = $counts['created'] + $counts['updated'] + $counts['skipped'];

        $driftNote = null;
        if (
            $counts['failed'] !== $validatedInvalid
            || ($counts['created'] + $counts['updated'] + $counts['skipped']) !== $validatedValid
        ) {
            $driftNote = 'Sebagian hasil berbeda dari pratinjau validasi karena data di sistem berubah '
                . 'sejak file divalidasi. Periksa riwayat impor untuk detailnya.';
        }

        return response()->json([
            'message' => 'Proses impor mahasiswa selesai',
            'batch_id' => $batch->uuid,
            'drift_note' => $driftNote,
            'summary' => [
                'total' => array_sum($counts),
                'created' => $counts['created'],
                'updated' => $counts['updated'],
                'skipped' => $counts['skipped'],
                'failed' => $counts['failed'],
            ],
        ], $processed > 0 ? 200 : 422);
    }

    /**
     * Riwayat batch impor (Hanya Super Admin).
     */
    public function importBatches(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:validated,completed,failed,cancelled',
            'source_format' => 'nullable|in:csv,xlsx',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $query = ImportBatch::with('uploader:id,name,email')
            ->withCount('errorRows')
            ->orderByDesc('created_at');

        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->query('source_format')) {
            $query->where('source_format', $request->query('source_format'));
        }
        if ($request->query('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->query('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $paginated = $query->paginate((int) $request->query('per_page', 10));

        return response()->json([
            'message' => 'Riwayat impor berhasil diambil',
            'data' => collect($paginated->items())->map(fn (ImportBatch $batch) => [
                'batch_id' => $batch->uuid,
                'kind' => $batch->kind,
                'status' => $batch->status,
                'source_format' => $batch->source_format,
                'template_version' => $batch->template_version,
                'original_filename' => $batch->original_filename,
                'override_existing_active' => $batch->override_existing_active,
                'override_reason' => $batch->override_reason,
                'uploaded_by' => $batch->uploader?->name,
                'total_rows' => $batch->total_rows,
                'valid_rows' => $batch->valid_rows,
                'invalid_rows' => $batch->invalid_rows,
                'created_count' => $batch->created_count,
                'updated_count' => $batch->updated_count,
                'skipped_count' => $batch->skipped_count,
                'failed_count' => $batch->failed_count,
                'has_error_report' => $batch->error_rows_count > 0,
                'error_report_expired' => $batch->error_rows_count === 0
                    && ($batch->invalid_rows > 0 || $batch->failed_count > 0),
                'created_at' => $batch->created_at?->toIso8601String(),
                'completed_at' => $batch->completed_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Laporan error batch impor dari data server (CSV/XLSX), bukan
     * echo dari payload klien.
     */
    public function importBatchErrors(Request $request, ImportBatch $importBatch)
    {
        $request->validate(['format' => 'nullable|in:csv,xlsx']);
        $format = $request->query('format', 'csv');

        $rows = $importBatch->errorRows()->orderBy('row_number')->get();
        if ($rows->isEmpty()) {
            $hadErrors = $importBatch->invalid_rows > 0 || $importBatch->failed_count > 0;
            if ($hadErrors && $importBatch->expires_at?->isPast()) {
                return response()->json([
                    'message' => 'Laporan error batch ini sudah melewati masa penyimpanan dan telah dihapus.',
                ], 410);
            }

            return response()->json([
                'message' => 'Tidak ada baris error pada batch impor ini.',
            ], 404);
        }

        $header = ['Baris', 'Nama', 'Email', 'NIM', 'Error'];
        $data = $rows->map(fn (ImportBatchRow $row) => SpreadsheetSafety::escapeRow([
            $row->row_number,
            $row->display_name ?? '-',
            $row->email ?? '-',
            $row->nim ?? '-',
            implode('; ', $row->errors_json ?? []),
        ]))->all();

        $fileName = 'laporan_error_import_' . substr($importBatch->uuid, 0, 8) . '_' . now()->format('Ymd_His');

        if ($format === 'xlsx') {
            return Excel::download(
                new ArraySheetExport(
                    'Error Impor',
                    array_merge([$header], $data),
                    ['A' => 8, 'B' => 28, 'C' => 34, 'D' => 24, 'E' => 80],
                    freezeHeader: true,
                    boldFirstRow: true,
                ),
                $fileName . '.xlsx'
            );
        }

        return response()->stream(function () use ($header, $data) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $header);
            foreach ($data as $row) {
                fputcsv($file, $row);
            }
            fclose($file);
        }, 200, [
            'Content-type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename={$fileName}.csv",
        ]);
    }

    /**
     * Download template import mahasiswa v2, CSV atau XLSX (Hanya Super Admin).
     * Backend-generated + authenticated; there is no public template path.
     */
    public function importTemplate(Request $request)
    {
        $request->validate(['format' => 'nullable|in:csv,xlsx']);
        $format = $request->query('format', 'csv');
        $version = MahasiswaImportService::TEMPLATE_VERSION;

        if ($format === 'xlsx') {
            return Excel::download(
                new MahasiswaImportTemplateExport(),
                "template_import_mahasiswa_verified_{$version}.xlsx"
            );
        }

        $fileName = "template_import_mahasiswa_verified_{$version}.csv";
        $headers = [
            'Content-type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        // Sample rows use the reserved CONTOH prodi code: they demonstrate
        // the format but can never be imported as real students.
        return response()->stream(function () {
            $sampleCode = MahasiswaImportService::SAMPLE_PROGRAM_CODE;
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($file, MahasiswaImportService::HEADERS);
            fputcsv($file, SpreadsheetSafety::escapeRow([
                'Contoh: Budi Santoso', 'budi.contoh@mail.ugm.ac.id', '24/535278/SV/12345', $sampleCode, '2004-05-15',
            ]));
            fputcsv($file, SpreadsheetSafety::escapeRow([
                'Contoh: Siti Rahma', 'siti.contoh@mail.ugm.ac.id', '24/535279/SV/12346', $sampleCode, '',
            ]));
            fclose($file);
        }, 200, $headers);
    }

    /**
     * Shared upload validation for validate-import and bulk-import.
     * CSV ≤ 2 MB, XLSX ≤ 5 MB, .xls rejected outright.
     */
    private function validateUploadedImportFile(Request $request): void
    {
        $request->validate(
            [
                'file' => 'required|file|mimes:csv,txt,xlsx|max:5120',
            ],
            [
                'file.mimes' => 'Format file harus CSV atau XLSX. File .xls tidak didukung.',
                'file.max' => 'Ukuran file maksimal 5 MB.',
            ]
        );

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'xls') {
            throw ValidationException::withMessages([
                'file' => ['File .xls tidak didukung. Gunakan CSV atau XLSX.'],
            ]);
        }

        if (in_array($extension, ['csv', 'txt'], true) && $file->getSize() > 2 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'file' => ['Ukuran file CSV maksimal 2 MB.'],
            ]);
        }
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
