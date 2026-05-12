<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\MahasiswaProfile;
use App\Models\KeluargaMahasiswa;
use App\Services\MahasiswaProfileDataService;

class ProfileController extends Controller
{
    public function __construct(private MahasiswaProfileDataService $profileDataService)
    {
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
            $completeness = $this->checkProfileCompleteness($profile, $readiness);

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
        return response()->json([
            'user' => $user->only(['name', 'email', 'role', 'sub_role', 'status']),
            'profile' => [
                'pas_foto_path' => $user->photo_path,
                'tanda_tangan_path' => $user->signature_path,
            ]
        ]);
    }

    /**
     * Check if the student profile is complete (mahasiswa flow).
     */
    private function checkProfileCompleteness($profile, array $readiness)
    {
        $missingFields = array_merge(
            $readiness['academic_master_data']['missing_fields'] ?? [],
            $readiness['editable_personal_profile_data']['missing_fields'] ?? []
        );

        $keluarga = $profile->keluarga;

        $ayah = $keluarga->where('jenis_relasi', 'ayah')->first();
        if (!$ayah || $ayah->nama_lengkap === null || $ayah->nama_lengkap === '') {
            $missingFields[] = 'Data Ayah';
        }

        $ibu = $keluarga->where('jenis_relasi', 'ibu')->first();
        if (!$ibu || $ibu->nama_lengkap === null || $ibu->nama_lengkap === '') {
            $missingFields[] = 'Data Ibu';
        }

        return [
            'is_complete' => count($missingFields) === 0,
            'missing_fields' => $missingFields,
            'categories' => $readiness,
        ];
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

    private function publicDiskPath(?string $filePath): ?string
    {
        if (!$filePath) {
            return null;
        }

        $path = parse_url($filePath, PHP_URL_PATH) ?: $filePath;
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'api/storage/')) {
            $path = substr($path, strlen('api/storage/'));
        }

        $allowedPrefixes = [
            'profiles/fotos/',
            'profiles/signatures/',
            'signatures/',
        ];

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $path;
            }
        }

        return null;
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
            $oldFiles = [];
            $newFiles = [];

            try {
                if ($request->hasFile('pas_foto')) {
                    $path = $request->file('pas_foto')->store('profiles/fotos', 'public');
                    if (!$path) {
                        throw new \RuntimeException('Failed to store profile photo.');
                    }
                    $newFiles[] = $path;
                    $oldFiles[] = $profile->pas_foto_path;
                    $validatedProfile['pas_foto_path'] = Storage::url($path);
                }

                if ($request->hasFile('tanda_tangan')) {
                    $path = $request->file('tanda_tangan')->store('profiles/signatures', 'public');
                    if (!$path) {
                        throw new \RuntimeException('Failed to store profile signature.');
                    }
                    $newFiles[] = $path;
                    $oldFiles[] = $profile->tanda_tangan_path;
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

                foreach ($oldFiles as $oldFile) {
                    $this->deletePublicFile($oldFile, $user->id);
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

        // For Staff / Akademik — Bundle 6 baseline shape preserved.
        $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8|confirmed',
            'pas_foto' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'tanda_tangan' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        if ($request->filled('name')) $user->name = $request->name;
        if ($request->filled('email')) $user->email = $request->email;
        if ($request->filled('password')) $user->password = Hash::make($request->password);

        $oldFiles = [];
        $newFiles = [];

        try {
            if ($request->hasFile('pas_foto')) {
                $path = $request->file('pas_foto')->store('profiles/fotos', 'public');
                if (!$path) {
                    throw new \RuntimeException('Failed to store profile photo.');
                }
                $newFiles[] = $path;
                $oldFiles[] = $user->photo_path;
                $user->photo_path = Storage::url($path);
            }

            if ($request->hasFile('tanda_tangan')) {
                $path = $request->file('tanda_tangan')->store('profiles/signatures', 'public');
                if (!$path) {
                    throw new \RuntimeException('Failed to store profile signature.');
                }
                $newFiles[] = $path;
                $oldFiles[] = $user->signature_path;
                $user->signature_path = Storage::url($path);
            }

            DB::transaction(function () use ($user) {
                $user->save();
            });

            foreach ($oldFiles as $oldFile) {
                $this->deletePublicFile($oldFile, $user->id);
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
            'user' => $user->only(['name', 'email', 'role', 'sub_role']),
            'profile' => [
                'pas_foto_path' => $user->photo_path,
                'tanda_tangan_path' => $user->signature_path,
            ]
        ]);
    }
}
