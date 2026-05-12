<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\ScholarshipApplication;
use App\Services\LetterDocumentAccessService;
use App\Services\MahasiswaProfileDataService;
use App\Services\ScholarshipAutomationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ScholarshipController extends Controller
{
    public function __construct(
        private LetterDocumentAccessService $documentAccessService,
        private MahasiswaProfileDataService $profileDataService
    )
    {
    }

    /**
     * Get the current draft or a new application with auto-filled user data.
     */
    public function getStep1()
    {
        $user = Auth::user();
        $user->load('studyProgram.department.faculty', 'department.faculty', 'mahasiswaProfile');
        $application = $this->editableApplicationQuery($user->id)
            ->with(['mahasiswaProfile.keluarga', 'user.studyProgram.department.faculty', 'user.department.faculty'])
            ->first();

        return response()->json([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'student' => $this->profileDataService->studentForUser($user),
            'application' => $application ? $this->applicationPayload($this->redactGeneratedDocumentPath($application)) : null
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
            ['nim' => $request->nim]
        );

        $application = $this->editableApplicationQuery($user->id)->first();

        if ($application) {
            $application->update(['mahasiswa_profile_id' => $profile->id]);
        } else {
            $application = ScholarshipApplication::create([
                'user_id' => $user->id,
                'mahasiswa_profile_id' => $profile->id,
                'status' => ScholarshipApplication::STATUS_DRAFT,
            ]);
        }

        return response()->json([
            'message' => 'Process 1 saved successfully',
            'application' => $this->applicationPayload(
                $this->redactGeneratedDocumentPath($application->load(['mahasiswaProfile.keluarga', 'user.studyProgram.department.faculty', 'user.department.faculty']))
            )
        ]);
    }

    /**
     * Save Process 2: Personal, Family & Siblings
     */
    public function saveStep2(Request $request)
    {
        $user = Auth::user();
        $application = $this->editableApplicationQuery($user->id)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'pob' => 'required|string',
            'dob' => 'required|date',
            'gender' => 'required|in:Laki-laki,Perempuan',
            'origin_address' => 'required|string',
            'jogja_address' => 'required|string',
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
            'application' => $this->applicationPayload(
                $this->redactGeneratedDocumentPath($application->load(['mahasiswaProfile.keluarga', 'user.studyProgram.department.faculty', 'user.department.faculty']))
            )
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
        $application = $this->editableApplicationQuery(Auth::id())->firstOrFail();

        $validator = Validator::make($request->all(), [
            'scholarship_name' => 'required|string',
            'current_semester' => 'required|integer',
            'family_dependents' => 'required|integer',
            'gpa_last_semesters' => 'required|numeric',
            'ipk' => 'required|numeric',
            'sks_last_semesters' => 'required|integer',
            'total_sks_passed' => 'required|integer',
            'total_sks_required' => 'nullable|integer|min:0',
            'on_leave' => 'required|in:Sudah,Belum',
            'leave_semester' => 'required_if:on_leave,Sudah|nullable|integer',
            'thesis_status' => 'required|in:Sudah,Belum',
            'exam_plan_date' => 'nullable|date',
            'has_scholarship_history' => 'required',
            'scholarship_histories' => 'nullable|array|max:5',
            'scholarship_histories.*.nama_beasiswa' => 'required_with:scholarship_histories|string',
            'scholarship_histories.*.periode' => 'required_with:scholarship_histories|string',
            'scholarship_histories.*.jumlah' => 'required_with:scholarship_histories|string',
            'scholarship_histories.*.status' => 'required_with:scholarship_histories|in:Aktif,Selesai',
            'transkrip_nilai' => 'nullable|file|mimes:pdf|max:2048',
            'slip_gaji_ayah' => 'nullable|file|mimes:png,jpg,pdf|max:2048',
            'slip_gaji_ibu' => 'nullable|file|mimes:png,jpg,pdf|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Handle File Uploads
        if ($request->hasFile('transkrip_nilai')) {
            $path = $request->file('transkrip_nilai')->store('scholarships/transcripts', 'public');
            $application->transkrip_nilai_path = Storage::url($path);
        }
        if ($request->hasFile('slip_gaji_ayah')) {
            $path = $request->file('slip_gaji_ayah')->store('scholarships/slips', 'public');
            $application->slip_gaji_ayah_path = Storage::url($path);
        }
        if ($request->hasFile('slip_gaji_ibu')) {
            $path = $request->file('slip_gaji_ibu')->store('scholarships/slips', 'public');
            $application->slip_gaji_ibu_path = Storage::url($path);
        }
        $application->save();

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

        $updateData = $request->except(['transkrip_nilai', 'slip_gaji_ayah', 'slip_gaji_ibu', 'scholarship_histories']);
        
        // Map frontend fields to backend columns
        if (isset($updateData['gpa_last_semesters'])) {
            $updateData['gpa_last_2_semesters'] = $updateData['gpa_last_semesters'];
            unset($updateData['gpa_last_semesters']);
        }
        if (isset($updateData['sks_last_semesters'])) {
            $updateData['sks_last_2_semesters'] = $updateData['sks_last_semesters'];
            unset($updateData['sks_last_semesters']);
        }

        $application->update($updateData);

        return response()->json([
            'message' => 'Step 3 saved successfully',
            'application' => $this->applicationPayload(
                $this->redactGeneratedDocumentPath($application->load(['mahasiswaProfile.keluarga', 'user.studyProgram.department.faculty', 'user.department.faculty']))
            )
        ]);
    }

    /**
     * Process 4: Preview and Submit
     */
    public function submitApplication(ScholarshipAutomationService $automationService)
    {
        $application = $this->editableApplicationQuery(Auth::id())->firstOrFail();

        // 1. Mark as submitted
        $application->status = ScholarshipApplication::STATUS_SUBMITTED;
        $application->submitted_at = now();
        $application->save();

        // 2. Automate: Assign to Tendik
        $assignedTendik = $automationService->assignApplication($application);

        // 3. Notify Tendik (Email & Dashboard)
        if ($assignedTendik) {
            $application->load('mahasiswaProfile');
            $assignedTendik->notify(new \App\Notifications\ScholarshipSubmittedNotification($application));
        }

        return response()->json([
            'message' => 'Aplikasi berhasil dikirim dan sedang diproses oleh staf beasiswa.',
            'application' => $this->applicationPayload(
                $this->redactGeneratedDocumentPath($application->load(['mahasiswaProfile.keluarga', 'user.studyProgram.department.faculty', 'user.department.faculty']))
            ),
            'assigned_to' => $assignedTendik ? $assignedTendik->name : null,
            'docx_path' => null
        ]);
    }

    /**
     * Get all applications for the authenticated Mahasiswa
     */
    public function getApplications()
    {
        $applications = ScholarshipApplication::where('user_id', Auth::id())
            ->where('status', '!=', ScholarshipApplication::STATUS_DRAFT)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (ScholarshipApplication $application) {
                return $this->forMahasiswaApplicationListResponse($application);
            });

        return response()->json([
            'applications' => $applications
        ]);
    }

    public function preview(ScholarshipApplication $application)
    {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        if (!$this->documentAccessService->canPreview($application)) {
            return response()->json([
                'message' => 'Dokumen belum tersedia untuk direview.'
            ], 403);
        }

        $path = $this->documentAccessService->resolveGeneratedDocumentPath($application, 'generated_docx_path');
        if (!$path) {
            return response()->json([
                'message' => 'Dokumen pengajuan belum tersedia.'
            ], 404);
        }

        return response()->file($path, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'inline; filename="' . $this->generatedDocumentFilename($application) . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function complete(ScholarshipApplication $application)
    {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        if (!$this->documentAccessService->canComplete($application)) {
            return response()->json([
                'message' => 'Pengajuan tidak berada pada tahap review mahasiswa.'
            ], 403);
        }

        if (!$this->documentAccessService->resolveGeneratedDocumentPath($application, 'generated_docx_path')) {
            return response()->json([
                'message' => 'Dokumen pengajuan belum tersedia.'
            ], 404);
        }

        $application->update([
            'status' => ScholarshipApplication::STATUS_COMPLETED,
            'student_reviewed_at' => $application->student_reviewed_at ?? now(),
            'completed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Pengajuan berhasil diselesaikan.',
            'application' => $this->applicationPayload($application->fresh()),
        ]);
    }

    private function editableApplicationQuery($userId)
    {
        return ScholarshipApplication::where('user_id', $userId)
            ->whereIn('status', [
                ScholarshipApplication::STATUS_REVISION,
                ScholarshipApplication::STATUS_DRAFT,
            ])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [ScholarshipApplication::STATUS_REVISION])
            ->latest();
    }

    private function redactGeneratedDocumentPath(ScholarshipApplication $application): ScholarshipApplication
    {
        return $this->documentAccessService->redactGeneratedPathIfNeeded($application, 'generated_docx_path');
    }

    private function forMahasiswaApplicationListResponse(ScholarshipApplication $application): array
    {
        $application = $this->redactGeneratedDocumentPath($application);
        $application->setAttribute('letter_type', ScholarshipApplication::LETTER_TYPE);

        return $this->applicationPayload($application);
    }

    private function applicationPayload(ScholarshipApplication $application): array
    {
        $application->loadMissing([
            'mahasiswaProfile.keluarga',
            'user.studyProgram.department.faculty',
            'user.department.faculty',
        ]);

        return $this->profileDataService->applicationPayload($application);
    }

    private function generatedDocumentFilename(ScholarshipApplication $application): string
    {
        $nim = $application->mahasiswaProfile?->nim ?: $application->id;
        $safeNim = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $nim);

        return 'Surat_Permohonan_Beasiswa_' . $safeNim . '.docx';
    }
}
