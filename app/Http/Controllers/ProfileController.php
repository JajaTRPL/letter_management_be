<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Models\MahasiswaProfile;
use App\Models\KeluargaMahasiswa;
use App\Models\User;
use App\Services\MahasiswaProfileDataService;
use App\Services\PasFotoNormalizer;

class ProfileController extends Controller
{
    private const PROFILE_ASSET_PREFIXES = [
        'profiles/fotos/',
        'profiles/signatures/',
        'signatures/',
    ];

    public function __construct(
        private MahasiswaProfileDataService $profileDataService,
        private PasFotoNormalizer $pasFotoNormalizer
    ) {
    }

    /**
     * Get the authenticated user's profile
     */
    public function getProfile()
    {
        $user = Auth::user();

        if ($user->role === 'mahasiswa') {
            $user->load('studyProgram.department.faculty');

            $profile = $user->mahasiswaProfile;
            if (!$profile) {
                $profile = $user->mahasiswaProfile()->create([]);
            }
            $profile->load('keluarga', 'scholarshipHistories');
            $normalized = $this->profileDataService->forUser($user);
            $student = $this->profileDataService->studentForUser($user);
            $readiness = $this->profileDataService->readinessForUser($user);
            $completeness = $this->profileDataService->completionForUser($user, $readiness);

            return response()->json([
                'user' => array_merge(
                    $user->only(['name', 'email', 'role', 'sub_role']),
                    ['study_program' => $this->studyProgramResponse($normalized)]
                ),
                'normalized' => $normalized,
                'student' => $student,
                'profile' => $profile,
                'completeness' => $completeness,
                'readiness' => $readiness,
            ]);
        }

        // For non-mahasiswa (staff, akademik, super_admin) — Bundle 6 baseline shape preserved.
        // Additive fields (id, tendik_role, nip, assigned_tasks, created_at) let the Tendik
        // profile bind to real data without breaking the existing key set consumers rely on.
        $user->loadMissing([
            'studyProgram:id,code,name,department_id',
            'studyProgram.department:id,code,name,faculty_id',
            'studyProgram.department.faculty:id,code,name',
            'department:id,code,name,faculty_id',
            'department.faculty:id,code,name',
        ]);

        return response()->json([
            'user' => array_merge(
                $user->only(['name', 'email', 'role', 'sub_role', 'status']),
                [
                    'id' => $user->id,
                    'tendik_role' => $user->tendik_role,
                    'nip' => $user->nip,
                    'assigned_tasks' => $user->assigned_tasks ?? [],
                    'created_at' => optional($user->created_at)->toIso8601String(),
                ],
                $this->nonMahasiswaScopeResponse($user)
            ),
            'profile' => [
                'pas_foto_path' => $user->photo_path,
                'tanda_tangan_path' => $user->signature_path,
            ]
        ]);
    }

    private function studyProgramResponse(array $normalized): ?array
    {
        if (
            $normalized['study_program_id'] === null
            && $normalized['program_studi_display'] === '-'
        ) {
            return null;
        }

        return [
            'id' => $normalized['study_program_id'],
            'code' => $normalized['study_program_code'],
            'name' => $normalized['program_studi_display'],
            'department' => $normalized['department_id'] || $normalized['department_display'] !== '-' ? [
                'id' => $normalized['department_id'],
                'code' => $normalized['department_code'],
                'name' => $normalized['department_display'],
                'faculty' => $normalized['faculty_id'] || $normalized['fakultas_display'] !== '-' ? [
                    'id' => $normalized['faculty_id'],
                    'name' => $normalized['fakultas_display'],
                ] : null,
            ] : null,
        ];
    }

    private function nonMahasiswaScopeResponse(User $user): array
    {
        $studyProgram = $user->studyProgram;
        $department = $user->department;
        $faculty = $department?->faculty ?? $studyProgram?->department?->faculty;

        return [
            'study_program_id' => $user->study_program_id,
            'study_program_code' => $studyProgram?->code,
            'study_program_name' => $studyProgram?->name,
            'study_program' => $studyProgram ? [
                'id' => $studyProgram->id,
                'code' => $studyProgram->code,
                'name' => $studyProgram->name,
                'department' => $studyProgram->department ? [
                    'id' => $studyProgram->department->id,
                    'code' => $studyProgram->department->code,
                    'name' => $studyProgram->department->name,
                    'faculty' => $studyProgram->department->faculty ? [
                        'id' => $studyProgram->department->faculty->id,
                        'code' => $studyProgram->department->faculty->code,
                        'name' => $studyProgram->department->faculty->name,
                    ] : null,
                ] : null,
            ] : null,
            'department_id' => $user->department_id,
            'department_code' => $department?->code,
            'department_name' => $department?->name,
            'department' => $department ? [
                'id' => $department->id,
                'code' => $department->code,
                'name' => $department->name,
                'faculty' => $department->faculty ? [
                    'id' => $department->faculty->id,
                    'code' => $department->faculty->code,
                    'name' => $department->faculty->name,
                ] : null,
            ] : null,
            'faculty_id' => $faculty?->id,
            'faculty_code' => $faculty?->code,
            'faculty_name' => $faculty?->name,
            'faculty' => $faculty ? [
                'id' => $faculty->id,
                'code' => $faculty->code,
                'name' => $faculty->name,
            ] : null,
        ];
    }

    private function publicDiskPath(?string $filePath): ?string
    {
        $filePath = trim((string) $filePath);
        if ($filePath === '') {
            return null;
        }

        $filePath = str_replace('\\', '/', $filePath);
        $parts = parse_url($filePath);
        if ($parts === false) {
            return null;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            if (!$this->isLocalAppUrl($parts)) {
                return null;
            }

            $path = $parts['path'] ?? '';
        } else {
            $path = $filePath;
        }

        $path = str_replace('\\', '/', $path);
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                break;
            }
            $path = $decoded;
        }

        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'api/storage/')) {
            $path = substr($path, strlen('api/storage/'));
        }

        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        if (
            $path === ''
            || str_contains($path, "\0")
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || $this->isConfiguredGlobalParafPath($path)
        ) {
            return null;
        }

        foreach (self::PROFILE_ASSET_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $path;
            }
        }

        return null;
    }

    private function isLocalAppUrl(array $urlParts): bool
    {
        $host = $urlParts['host'] ?? null;
        if (!$host) {
            return false;
        }

        $scheme = strtolower((string) ($urlParts['scheme'] ?? ''));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (!$appHost) {
            return false;
        }

        return strcasecmp($host, $appHost) === 0;
    }

    private function isConfiguredGlobalParafPath(string $diskPath): bool
    {
        $configured = config('surat.global_paraf_path');
        if (!is_string($configured) || trim($configured) === '') {
            return false;
        }

        $configuredPath = str_replace('\\', '/', trim($configured));
        $parts = parse_url($configuredPath);
        if ($parts === false) {
            return false;
        }

        $configuredPath = $parts['path'] ?? $configuredPath;
        $configuredPath = ltrim(str_replace('\\', '/', $configuredPath), '/');

        if (str_starts_with($configuredPath, 'storage/')) {
            $configuredPath = substr($configuredPath, strlen('storage/'));
        }

        if (str_starts_with($configuredPath, 'api/storage/')) {
            $configuredPath = substr($configuredPath, strlen('api/storage/'));
        }

        return $configuredPath !== '' && $configuredPath === $diskPath;
    }

    private function deleteReplacedProfileFile(array $replacement, User $user): void
    {
        $oldPath = $this->publicDiskPath($replacement['old'] ?? null);
        $newPath = $this->publicDiskPath($replacement['new'] ?? null);

        if (!$oldPath || ($newPath && $oldPath === $newPath)) {
            return;
        }

        if ($this->profileAssetPathIsStillReferenced($oldPath, $user)) {
            Log::warning('Skipped deleting profile file still referenced by another profile asset.', [
                'user_id' => $user->id,
                'disk_path' => $oldPath,
                'field' => $replacement['field'] ?? null,
            ]);
            return;
        }

        $this->deletePublicFile($oldPath, $user->id);
    }

    private function profileAssetPathIsStillReferenced(string $diskPath, User $user): bool
    {
        $candidates = $this->storageValueCandidates($diskPath);

        $otherUserReference = User::where('id', '<>', $user->id)
            ->where(function ($query) use ($candidates) {
                $query->whereIn('photo_path', $candidates)
                    ->orWhereIn('signature_path', $candidates);
            })
            ->exists();

        if ($otherUserReference) {
            return true;
        }

        $currentUserOtherFieldReference = User::whereKey($user->id)
            ->where(function ($query) use ($candidates) {
                $query->whereIn('photo_path', $candidates)
                    ->orWhereIn('signature_path', $candidates);
            })
            ->exists();

        if ($currentUserOtherFieldReference) {
            return true;
        }

        return MahasiswaProfile::where('user_id', '<>', $user->id)
            ->where(function ($query) use ($candidates) {
                $query->whereIn('pas_foto_path', $candidates)
                    ->orWhereIn('tanda_tangan_path', $candidates);
            })
            ->exists()
            || MahasiswaProfile::where('user_id', $user->id)
                ->where(function ($query) use ($candidates) {
                    $query->whereIn('pas_foto_path', $candidates)
                        ->orWhereIn('tanda_tangan_path', $candidates);
                })
                ->exists();
    }

    private function storageValueCandidates(string $diskPath): array
    {
        $storageUrl = Storage::url($diskPath);
        $appUrl = rtrim((string) config('app.url'), '/');

        $candidates = [
            $diskPath,
            $storageUrl,
            ltrim($storageUrl, '/'),
            '/api/storage/' . ltrim($diskPath, '/'),
            'api/storage/' . ltrim($diskPath, '/'),
        ];

        if ($appUrl !== '') {
            $candidates[] = $appUrl . $storageUrl;
            $candidates[] = $appUrl . '/api/storage/' . ltrim($diskPath, '/');
        }

        return array_values(array_unique($candidates));
    }

    private function deletePublicFile(?string $filePath, ?int $userId = null): void
    {
        $path = $this->publicDiskPath($filePath);

        if (!$path) {
            if ($filePath) {
                Log::warning('Skipped deleting profile file outside allowed storage paths.', [
                    'user_id' => $userId,
                    'file_path' => $filePath,
                ]);
            }
            return;
        }

        try {
            if (Storage::disk('public')->exists($path) && !Storage::disk('public')->delete($path)) {
                Log::warning('Failed to delete profile file.', [
                    'user_id' => $userId,
                    'file_path' => $filePath,
                    'disk_path' => $path,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Exception while deleting profile file.', [
                'user_id' => $userId,
                'file_path' => $filePath,
                'disk_path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update the authenticated user's profile
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        if ($user->role === 'mahasiswa') {
            $validatedProfile = $request->validate([
                'tempat_lahir' => 'nullable|string|max:100',
                'tanggal_lahir' => 'nullable|date',
                'jenis_kelamin' => 'nullable|in:L,P',
                'no_hp' => 'nullable|string|max:20',
                'alamat_asal' => 'nullable|string',
                'alamat_domisili' => 'nullable|string',
                'pas_foto' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
                'tanda_tangan' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            ]);
            unset($validatedProfile['pas_foto'], $validatedProfile['tanda_tangan']);

            $profile = $user->mahasiswaProfile()->firstOrCreate([]);
            $replacedFiles = [];
            $newFiles = [];

            try {
                if ($request->hasFile('pas_foto')) {
                    try {
                        $path = $this->pasFotoNormalizer->normalize($request->file('pas_foto'));
                    } catch (\RuntimeException $e) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'pas_foto' => [$e->getMessage()],
                        ]);
                    }
                    $newFiles[] = $path;
                    $replacedFiles[] = [
                        'old' => $profile->pas_foto_path,
                        'new' => $path,
                        'field' => 'mahasiswa_profiles.pas_foto_path',
                    ];
                    $validatedProfile['pas_foto_path'] = Storage::url($path);
                }

                if ($request->hasFile('tanda_tangan')) {
                    $path = $request->file('tanda_tangan')->store('profiles/signatures', 'public');
                    if (!$path) {
                        throw new \RuntimeException('Failed to store profile signature.');
                    }
                    $newFiles[] = $path;
                    $replacedFiles[] = [
                        'old' => $profile->tanda_tangan_path,
                        'new' => $path,
                        'field' => 'mahasiswa_profiles.tanda_tangan_path',
                    ];
                    $validatedProfile['tanda_tangan_path'] = Storage::url($path);
                }

                if (isset($validatedProfile['tanggal_lahir']) && $validatedProfile['tanggal_lahir'] === '') {
                    $validatedProfile['tanggal_lahir'] = null;
                }

                $profile = DB::transaction(function () use ($profile, $validatedProfile, $request) {
                    $profile->update($validatedProfile);

                    if ($request->has('keluarga')) {
                        $keluargaData = $request->input('keluarga');
                        if (is_string($keluargaData)) {
                            $keluargaData = json_decode($keluargaData, true) ?? [];
                        }
                        $profile->keluarga()->delete();
                        foreach ($keluargaData as $kel) {
                            if (!empty($kel['nama_lengkap']) && !empty($kel['jenis_relasi'])) {
                                $profile->keluarga()->create([
                                    'jenis_relasi' => $kel['jenis_relasi'],
                                    'nama_lengkap' => $kel['nama_lengkap'],
                                    'pekerjaan' => $kel['pekerjaan'] ?? null,
                                    'penghasilan' => $kel['penghasilan'] ?? null,
                                    'status_hidup' => $kel['status_hidup'] ?? 'hidup',
                                    'tanggal_meninggal' => !empty($kel['tanggal_meninggal']) ? $kel['tanggal_meninggal'] : null,
                                    'status_kawin' => $kel['status_kawin'] ?? null,
                                    'keterangan' => $kel['keterangan'] ?? null,
                                ]);
                            }
                        }
                    }

                    if ($request->has('scholarship_histories')) {
                        $profile->scholarshipHistories()->delete();
                        $histories = $request->input('scholarship_histories');
                        if (is_string($histories)) {
                            $histories = json_decode($histories, true) ?? [];
                        }
                        $count = 0;
                        foreach ($histories as $hist) {
                            if (!empty($hist['nama_beasiswa']) && $count < 5) {
                                $profile->scholarshipHistories()->create([
                                    'nama_beasiswa' => $hist['nama_beasiswa'],
                                    'periode' => $hist['periode'] ?? null,
                                    'jumlah' => $hist['jumlah'] ?? null,
                                    'status' => $hist['status'] ?? 'Selesai',
                                ]);
                                $count++;
                            }
                        }
                    }

                    return $profile;
                });

                foreach ($replacedFiles as $replacement) {
                    $this->deleteReplacedProfileFile($replacement, $user);
                }

                foreach ($newFiles as $newFile) {
                    Log::info('Profile file upload committed.', [
                        'user_id' => $user->id,
                        'file_path' => Storage::url($newFile),
                    ]);
                }
            } catch (\Throwable $e) {
                foreach ($newFiles as $newFile) {
                    $this->deletePublicFile($newFile, $user->id);
                }

                Log::error('Profile update failed after file upload.', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            return response()->json([
                'message' => 'Profil mahasiswa berhasil diperbarui',
                'profile' => $profile->load('keluarga', 'scholarshipHistories')
            ]);
        }

        // For Staff / Akademik / Super Admin self-profile.
        // Self-editable: name, nip, password, pas_foto, tanda_tangan.
        // Email remains account identity and is managed exclusively through
        // SuperAdmin\UserController::update by an authorized Super Admin.
        // Role / status / tendik_role / sub_role / assigned_tasks / scope
        // fields are admin-managed only and are never read off this request.
        $request->validate([
            'name' => 'nullable|string|max:255',
            'nip' => ['nullable', 'string', 'max:50', Rule::unique('users', 'nip')->ignore($user->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'pas_foto' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'tanda_tangan' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        if ($request->filled('name')) $user->name = trim((string) $request->input('name'));
        if ($request->has('nip')) $user->nip = $this->normalizeNip($request->input('nip'));
        if ($request->filled('password')) $user->password = Hash::make($request->password);

        $replacedFiles = [];
        $newFiles = [];

        try {
            if ($request->hasFile('pas_foto')) {
                $path = $request->file('pas_foto')->store('profiles/fotos', 'public');
                if (!$path) {
                    throw new \RuntimeException('Failed to store profile photo.');
                }
                $newFiles[] = $path;
                $replacedFiles[] = [
                    'old' => $user->photo_path,
                    'new' => $path,
                    'field' => 'users.photo_path',
                ];
                $user->photo_path = Storage::url($path);
            }

            if ($request->hasFile('tanda_tangan')) {
                $path = $request->file('tanda_tangan')->store('profiles/signatures', 'public');
                if (!$path) {
                    throw new \RuntimeException('Failed to store profile signature.');
                }
                $newFiles[] = $path;
                $replacedFiles[] = [
                    'old' => $user->signature_path,
                    'new' => $path,
                    'field' => 'users.signature_path',
                ];
                $user->signature_path = Storage::url($path);
            }

            DB::transaction(function () use ($user) {
                $user->save();
            });

            foreach ($replacedFiles as $replacement) {
                $this->deleteReplacedProfileFile($replacement, $user);
            }

            foreach ($newFiles as $newFile) {
                Log::info('Profile file upload committed.', [
                    'user_id' => $user->id,
                    'file_path' => Storage::url($newFile),
                ]);
            }
        } catch (\Throwable $e) {
            foreach ($newFiles as $newFile) {
                $this->deletePublicFile($newFile, $user->id);
            }

            Log::error('Profile update failed after file upload.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return response()->json([
            'message' => 'Profil berhasil diperbarui',
            'user' => array_merge(
                $user->only(['name', 'email', 'role', 'sub_role']),
                ['nip' => $user->nip]
            ),
            'profile' => [
                'pas_foto_path' => $user->photo_path,
                'tanda_tangan_path' => $user->signature_path,
            ]
        ]);
    }

    /**
     * Trim NIP and convert blank to null, matching SuperAdmin\UserController.
     */
    private function normalizeNip(?string $nip): ?string
    {
        $nip = trim((string) $nip);

        return $nip !== '' ? $nip : null;
    }
}
