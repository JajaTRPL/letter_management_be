<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        
        if ($user->role !== 'mahasiswa') {
            return response()->json(['message' => 'Unauthorized role'], 403);
        }

        $profile = $user->mahasiswaProfile;
        
        if (!$profile) {
            $profile = $user->mahasiswaProfile()->create([]);
        }

        // Always load keluarga
        $profile->load('keluarga');

        return response()->json([
            'user' => $user->only(['name', 'email', 'role']),
            'profile' => $profile,
        ]);
    }

    /**
     * Update the authenticated user's profile
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

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

        $profile->update($validatedProfile);

        // Update keluargas if provided
        if ($request->has('keluarga')) {
            $keluargaData = $request->input('keluarga'); // expected array of family members
            
            // Delete existing to replace (atau bisa sync berdasar ID jika ada form complex)
            $profile->keluarga()->delete();
            
            foreach ($keluargaData as $kel) {
                // Ensure required fields exist in input
                if (!empty($kel['nama_lengkap']) && !empty($kel['jenis_relasi'])) {
                    $profile->keluarga()->create([
                        'jenis_relasi' => $kel['jenis_relasi'],
                        'nama_lengkap' => $kel['nama_lengkap'],
                        'pekerjaan' => $kel['pekerjaan'] ?? null,
                        'penghasilan' => $kel['penghasilan'] ?? null,
                        'status_hidup' => $kel['status_hidup'] ?? 'hidup',
                        'tanggal_meninggal' => $kel['tanggal_meninggal'] ?? null,
                        'status_kawin' => $kel['status_kawin'] ?? null,
                        'keterangan' => $kel['keterangan'] ?? null,
                    ]);
                }
            }
        }

        return response()->json([
            'message' => 'Profil berhasil diperbarui',
            'profile' => $profile->load('keluarga')
        ]);
    }
}
