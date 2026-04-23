<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\MahasiswaProfile;
use App\Models\KeluargaMahasiswa;
use App\Models\ScholarshipApplication;
use App\Services\ScholarshipAutomationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ScholarshipController extends Controller
{
    /**
     * Get the current draft or a new application with auto-filled user data.
     */
    public function getStep1()
    {
        $user = Auth::user();
        $application = ScholarshipApplication::where('user_id', $user->id)
            ->where('status', 'Draft')
            ->with('mahasiswaProfile.keluarga')
            ->first();

        return response()->json([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'application' => $application
        ]);
    }

    /**
     * Save Process 1: Biodata
     */
    public function saveStep1(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nim' => 'required|string',
            'faculty' => 'required|string',
            'study_program' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = Auth::user();

        // Update Profile
        $profile = \App\Models\MahasiswaProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'nim' => $request->nim,
                'fakultas' => $request->faculty,
                'program_studi' => $request->study_program,
            ]
        );

        $application = ScholarshipApplication::updateOrCreate(
            ['user_id' => $user->id, 'status' => 'Draft'],
            ['mahasiswa_profile_id' => $profile->id]
        );

        return response()->json([
            'message' => 'Process 1 saved successfully',
            'application' => $application->load('mahasiswaProfile.keluarga')
        ]);
    }

    /**
     * Save Process 2: Personal, Family & Siblings
     */
    public function saveStep2(Request $request)
    {
        $user = Auth::user();
        $application = ScholarshipApplication::where('user_id', $user->id)
            ->where('status', 'Draft')
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'pob' => 'required|string',
            'dob' => 'required|date',
            'gender' => 'required|in:Laki-laki,Perempuan',
            'origin_address' => 'required|string',
            'jogja_address' => 'required|string',
            'signature' => 'nullable|string', // Base64 signature
            'father_name' => 'required|string',
            'father_job' => 'required|string',
            'father_income' => 'required|numeric',
            'father_status' => 'required|in:Hidup,Meninggal',
            'father_death_date' => 'required_if:father_status,Meninggal|nullable|date',
            'mother_name' => 'required|string',
            'mother_job' => 'required|string',
            'mother_income' => 'required|numeric',
            'mother_status' => 'required|in:Hidup,Meninggal',
            'mother_death_date' => 'required_if:mother_status,Meninggal|nullable|date',
            'guardian_name' => 'required_if:father_status,Meninggal,mother_status,Meninggal|nullable|string',
            'guardian_job' => 'required_if:father_status,Meninggal,mother_status,Meninggal|nullable|string',
            'guardian_income' => 'required_if:father_status,Meninggal,mother_status,Meninggal|nullable|numeric',
            'guardian_status' => 'required_if:father_status,Meninggal,mother_status,Meninggal|nullable|in:Hidup,Meninggal',
            'guardian_death_date' => 'required_if:guardian_status,Meninggal|nullable|date',
            'siblings' => 'nullable|array',
            'siblings.*.name' => 'required|string',
            'siblings.*.job_or_school' => 'required|string',
            'siblings.*.marital_status' => 'required|in:Belum Kawin,Kawin',
            'siblings.*.relation' => 'required|in:Kakak,Adik',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // 1. Update Profile
        $profile = $user->mahasiswaProfile ?? \App\Models\MahasiswaProfile::create(['user_id' => $user->id]);

        $profileData = array_filter([
            'tempat_lahir' => $request->pob,
            'tanggal_lahir' => $request->dob,
            'jenis_kelamin' => $request->gender === 'Laki-laki' ? 'L' : ($request->gender === 'Perempuan' ? 'P' : null),
            'alamat_asal' => $request->origin_address,
            'alamat_domisili' => $request->jogja_address,
            'no_hp' => $request->phone,
        ], function($value) {
            return !is_null($value) && $value !== '';
        });

        // Handle signature
        if ($request->filled('signature')) {
            $imageData = $request->signature;
            $fileName = 'signatures/' . $user->id . '_' . time() . '.png';
            $image = str_replace('data:image/png;base64,', '', $imageData);
            $image = str_replace(' ', '+', $image);
            Storage::disk('public')->put($fileName, base64_decode($image));
            $profileData['tanda_tangan_path'] = $fileName;
        }

        $profile->update($profileData);

        // 2. Update Family (Ayah, Ibu, Wali) - Hanya jika ada datanya
        if ($request->filled('father_name')) {
            $this->updateFamilyMember($profile, 'ayah', [
                'nama_lengkap' => $request->father_name,
                'pekerjaan' => $request->father_job,
                'penghasilan' => $request->father_income,
                'status_hidup' => strtolower($request->father_status),
                'tanggal_meninggal' => $request->father_death_date,
            ]);
        }

        if ($request->filled('mother_name')) {
            $this->updateFamilyMember($profile, 'ibu', [
                'nama_lengkap' => $request->mother_name,
                'pekerjaan' => $request->mother_job,
                'penghasilan' => $request->mother_income,
                'status_hidup' => strtolower($request->mother_status),
                'tanggal_meninggal' => $request->mother_death_date,
            ]);
        }

        if ($request->filled('guardian_name')) {
            $this->updateFamilyMember($profile, 'wali', [
                'nama_lengkap' => $request->guardian_name,
                'pekerjaan' => $request->guardian_job,
                'penghasilan' => $request->guardian_income,
                'status_hidup' => strtolower($request->guardian_status),
                'tanggal_meninggal' => $request->guardian_death_date,
            ]);
        }

        // 3. Update Siblings
        if ($request->has('siblings')) {
            $profile->keluarga()->where('jenis_relasi', 'saudara')->delete();
            foreach ($request->siblings as $sib) {
                $profile->keluarga()->create([
                    'jenis_relasi' => 'saudara',
                    'nama_lengkap' => $sib['name'],
                    'pekerjaan' => $sib['job_or_school'],
                    'status_hidup' => 'hidup',
                    'status_kawin' => $sib['marital_status'],
                    'keterangan' => $sib['relation'],
                ]);
            }
        }

        return response()->json([
            'message' => 'Process 2 saved successfully',
            'application' => $application->load('mahasiswaProfile.keluarga')
        ]);
    }

    private function updateFamilyMember($profile, $relation, $data)
    {
        // Filter out empty values to avoid overwriting with null
        $filteredData = array_filter($data, function($value) {
            return !is_null($value) && $value !== '';
        });

        if (empty($filteredData)) return null;

        return $profile->keluarga()->updateOrCreate(
            ['jenis_relasi' => $relation],
            $filteredData
        );
    }

    /**
     * Save Process 3: Academic & History
     */
    public function saveStep3(Request $request)
    {
        $application = ScholarshipApplication::where('user_id', Auth::id())
            ->where('status', 'Draft')
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'scholarship_name' => 'required|string',
            'current_semester' => 'required|integer',
            'family_dependents' => 'required|integer',
            'gpa_last_2_semesters' => 'required|numeric',
            'ipk' => 'required|numeric',
            'sks_last_2_semesters' => 'required|integer',
            'total_sks_passed' => 'required|integer',
            'on_leave' => 'required|in:Sudah,Belum',
            'leave_semester' => 'required_if:on_leave,Sudah|nullable|integer',
            'thesis_status' => 'required|in:Sudah,Belum',
            'exam_plan_date' => 'nullable|date',
            'has_scholarship_history' => 'required|boolean',
            'scholarship_histories' => 'nullable|array|max:5',
            'scholarship_histories.*.nama_beasiswa' => 'required_with:scholarship_histories|string',
            'scholarship_histories.*.periode' => 'required_with:scholarship_histories|string',
            'scholarship_histories.*.jumlah' => 'required_with:scholarship_histories|string',
            'scholarship_histories.*.status' => 'required_with:scholarship_histories|in:Aktif,Selesai',
            'ktm' => 'nullable|file|mimes:png,jpg,pdf|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Handle KTM file upload
        if ($request->hasFile('ktm')) {
            $path = $request->file('ktm')->store('ktm_files', 'public');
            $application->ktm_path = $path;
        }

        // Handle sync to profile's scholarship_histories
        if ($request->has('scholarship_histories')) {
            $profile = \App\Models\MahasiswaProfile::where('user_id', \Illuminate\Support\Facades\Auth::id())->first();
            if ($profile) {
                $profile->scholarshipHistories()->delete();
                foreach ($request->scholarship_histories as $sh) {
                    $profile->scholarshipHistories()->create([
                        'nama_beasiswa' => $sh['nama_beasiswa'] ?? '',
                        'periode' => $sh['periode'] ?? '',
                        'jumlah' => $sh['jumlah'] ?? '',
                        'status' => $sh['status'] ?? 'Selesai',
                    ]);
                }
            }
        }

        $application->update($request->except(['ktm', 'scholarship_histories']));

        return response()->json([
            'message' => 'Step 3 saved successfully',
            'application' => $application->load('mahasiswaProfile.keluarga')
        ]);
    }

    /**
     * Process 4: Preview and Submit
     */
    public function submitApplication(ScholarshipAutomationService $automationService)
    {
        $application = ScholarshipApplication::where('user_id', Auth::id())
            ->where('status', 'Draft')
            ->firstOrFail();

        // 1. Mark as submitted
        $application->status = 'Submitted';
        $application->submitted_at = now();
        $application->save();

        // 2. Automate: Assign to Tendik
        $assignedTendik = $automationService->assignApplication($application);

        // 3. Automate: Generate populated Word Document from Google Doc Template
        $documentPath = $automationService->generateDocument($application);

        // 4. Notify Tendik (Email & Dashboard)
        if ($assignedTendik) {
            $application->load('mahasiswaProfile');
            $assignedTendik->notify(new \App\Notifications\ScholarshipSubmittedNotification($application));
        }

        return response()->json([
            'message' => 'Aplikasi berhasil dikirim dan sedang diproses oleh staf beasiswa.',
            'application' => $application->load('mahasiswaProfile.keluarga'),
            'assigned_to' => $assignedTendik ? $assignedTendik->name : null,
            'docx_path' => $documentPath
        ]);
    }
}
