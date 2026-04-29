<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\MahasiswaProfile;
use App\Models\KeluargaMahasiswa;

class ProfileController extends Controller
{
    /**
     * Get the authenticated user's profile
     */
    public function getProfile()
    {
        $user = Auth::user();

        if ($user->role === 'mahasiswa') {
            // Eager-load relational chain for study program → department → faculty
            $user->load('studyProgram.department.faculty');

            $profile = $user->mahasiswaProfile;
            if (!$profile) {
                $profile = $user->mahasiswaProfile()->create([]);
            }
            $profile->load('keluarga', 'scholarshipHistories');
            $completeness = $this->checkProfileCompleteness($profile, $user);

            return response()->json([
                'user' => array_merge(
                    $user->only(['name', 'email', 'role', 'sub_role']),
                    ['study_program' => $user->studyProgram ? [
                        'code' => $user->studyProgram->code,
                        'name' => $user->studyProgram->name,
                        'department' => $user->studyProgram->department ? [
                            'code' => $user->studyProgram->department->code,
                            'name' => $user->studyProgram->department->name,
                            'faculty' => $user->studyProgram->department->faculty ? [
                                'name' => $user->studyProgram->department->faculty->name,
                            ] : ($profile->fakultas ? ['name' => $profile->fakultas] : null),
                        ] : null,
                    ] : ($profile->program_studi ? [
                        'name' => $profile->program_studi,
                        'department' => $profile->fakultas ? [
                            'faculty' => ['name' => $profile->fakultas]
                        ] : null
                    ] : null)]
                ),
                'profile' => $profile,
                'completeness' => $completeness
            ]);
        }

        // For non-mahasiswa (staff, akademik, super_admin)
        return response()->json([
            'user' => $user->only(['name', 'email', 'role', 'sub_role', 'status']),
            'profile' => [
                'pas_foto_path' => $user->photo_path,
                'tanda_tangan_path' => $user->signature_path,
            ]
        ]);
    }

    /**
     * Check if the student profile is complete
     */
    private function checkProfileCompleteness($profile, $user = null)
    {
        $missingFields = [];

        // Check relational fields via user model instead of legacy string columns
        if ($user && !$user->study_program_id) {
            $missingFields[] = 'Program Studi';
        }
        if ($user && (!$user->studyProgram || !$user->studyProgram->department)) {
            // Departemen & Fakultas are derived — only flag if prodi is missing
        }

        $fields = [
            'nim' => 'NIM',
            'tempat_lahir' => 'Tempat Lahir',
            'tanggal_lahir' => 'Tanggal Lahir',
            'jenis_kelamin' => 'Jenis Kelamin',
            'no_hp' => 'No. HP',
            'alamat_asal' => 'Alamat Asal',
            'alamat_domisili' => 'Alamat Domisili',
            'pas_foto_path' => 'Pas Foto',
            'tanda_tangan_path' => 'Tanda Tangan',
        ];

        foreach ($fields as $field => $label) {
            if ($profile->$field === null || $profile->$field === '') {
                $missingFields[] = $label;
            }
        }

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
            'missing_fields' => $missingFields
        ];
    }

    /**
     * Update the authenticated user's profile
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        if ($user->role === 'mahasiswa') {
            // Validate basic profile input
            $validatedProfile = $request->validate([
                'tempat_lahir' => 'nullable|string|max:100',
                'tanggal_lahir' => 'nullable|date',
                'jenis_kelamin' => 'nullable|in:L,P',
                'no_hp' => 'nullable|string|max:20',
                'alamat_asal' => 'nullable|string',
                'alamat_domisili' => 'nullable|string',
            ]);

            $profile = $user->mahasiswaProfile()->firstOrCreate([]);

            // Handle File Uploads
            if ($request->hasFile('pas_foto')) {
                $path = $request->file('pas_foto')->store('profiles/fotos', 'public');
                $validatedProfile['pas_foto_path'] = \Illuminate\Support\Facades\Storage::url($path);
            }

            if ($request->hasFile('tanda_tangan')) {
                $path = $request->file('tanda_tangan')->store('profiles/signatures', 'public');
                $validatedProfile['tanda_tangan_path'] = \Illuminate\Support\Facades\Storage::url($path);
            }

            // Sanitize empty date fields for PostgreSQL
            if (isset($validatedProfile['tanggal_lahir']) && $validatedProfile['tanggal_lahir'] === '') {
                $validatedProfile['tanggal_lahir'] = null;
            }

            $profile->update($validatedProfile);

            // Update keluargas if provided
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

            // Handle scholarship histories if provided
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

            return response()->json([
                'message' => 'Profil mahasiswa berhasil diperbarui',
                'profile' => $profile->load('keluarga', 'scholarshipHistories')
            ]);
        }

        // For Staff / Akademik
        $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        if ($request->filled('name')) $user->name = $request->name;
        if ($request->filled('email')) $user->email = $request->email;
        if ($request->filled('password')) $user->password = Hash::make($request->password);

        if ($request->hasFile('pas_foto')) {
            $path = $request->file('pas_foto')->store('profiles/fotos', 'public');
            $user->photo_path = \Illuminate\Support\Facades\Storage::url($path);
        }

        if ($request->hasFile('tanda_tangan')) {
            $path = $request->file('tanda_tangan')->store('profiles/signatures', 'public');
            $user->signature_path = \Illuminate\Support\Facades\Storage::url($path);
        }

        $user->save();

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
