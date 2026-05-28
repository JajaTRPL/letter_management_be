<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Services\BeasiswaPreviewGenerationException;
use App\Services\BeasiswaPreviewGenerationService;
use App\Services\LetterDocumentArtifactService;
use App\Services\LetterDocumentAccessService;
use App\Services\MahasiswaProfileDataService;
use App\Services\ScholarshipAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

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
            'profile_summary' => $this->profileDataService->profileSummaryForUser($user),
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
            'slip_gaji_ayah' => 'nullable|file|mimes:pdf|max:2048',
            'slip_gaji_ibu' => 'nullable|file|mimes:pdf|max:2048',
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
    public function submitApplication(
        Request $request,
        ScholarshipAutomationService $automationService,
        BeasiswaPreviewGenerationService $previewGenerationService,
    )
    {
        // Defense-in-depth: the FE renders an explicit declaration checkbox and gates the
        // submit button on it, but the API must also refuse submission when the declaration
        // is not accepted so direct/console submissions cannot bypass it.
        $validator = Validator::make($request->all(), [
            'declaration_accepted' => 'required|accepted',
        ], [
            'declaration_accepted.required' => 'Anda harus menyetujui pernyataan kebenaran data sebelum mengirim.',
            'declaration_accepted.accepted' => 'Anda harus menyetujui pernyataan kebenaran data sebelum mengirim.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first('declaration_accepted'),
                'errors' => $validator->errors(),
            ], 422);
        }

        $application = $this->editableApplicationQuery(Auth::id())->firstOrFail();
        $submittedAt = now();

        try {
            $previewGenerationService->generateForPhase(
                $application,
                LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
                [
                    'status' => ScholarshipApplication::STATUS_SUBMITTED,
                    'submitted_at' => $submittedAt,
                    'tanggal_surat' => $submittedAt,
                ],
                Auth::id(),
            );
        } catch (BeasiswaPreviewGenerationException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dokumen pratinjau pengajuan belum dapat dibuat. Silakan coba lagi.',
            ], 503);
        }

        try {
            [$application, $assignedTendik] = DB::transaction(function () use ($application, $automationService, $submittedAt) {
                $lockedApplication = ScholarshipApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $lockedApplication->user_id !== (int) Auth::id()
                    || !in_array($lockedApplication->status, [
                        ScholarshipApplication::STATUS_DRAFT,
                        ScholarshipApplication::STATUS_REVISION,
                    ], true)
                ) {
                    throw new RuntimeException('Scholarship application is no longer submittable.');
                }

                $lockedApplication->status = ScholarshipApplication::STATUS_SUBMITTED;
                $lockedApplication->submitted_at = $submittedAt;
                $lockedApplication->save();

                $assignedTendik = $automationService->assignApplication($lockedApplication);

                return [$lockedApplication->fresh(), $assignedTendik];
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => 'Pengajuan sudah berubah dan tidak dapat dikirim ulang.',
            ], 409);
        }

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

    /**
     * Read-only view of a single application owned by the authenticated Mahasiswa.
     * Lets the FE render the existing-submission detail instead of opening a new
     * draft when a Submitted+ application already exists. Status is never mutated.
     */
    public function showForMahasiswa(ScholarshipApplication $application)
    {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        $application = $this->redactGeneratedDocumentPath(
            $application->load([
                'mahasiswaProfile.keluarga',
                'mahasiswaProfile.scholarshipHistories',
                'user.studyProgram.department.faculty',
                'user.department.faculty',
            ])
        );
        $application->setAttribute('letter_type', ScholarshipApplication::LETTER_TYPE);

        return response()->json([
            'application' => $this->applicationPayload($application),
            'profile_summary' => $this->profileDataService->profileSummaryForApplication($application),
        ]);
    }

    public function complete(
        ScholarshipApplication $application,
        LetterDocumentArtifactService $artifactService,
    )
    {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        if (!$this->documentAccessService->canComplete($application)) {
            return response()->json([
                'message' => 'Pengajuan tidak berada pada tahap review mahasiswa.'
            ], 403);
        }

        $artifactError = $this->completionArtifactError($application, $artifactService);
        if ($artifactError) {
            return $artifactError;
        }

        $completedAt = now();

        try {
            $application = DB::transaction(function () use ($application, $completedAt) {
                $lockedApplication = ScholarshipApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $lockedApplication->user_id !== (int) Auth::id()
                    || $lockedApplication->status !== ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW
                ) {
                    throw new RuntimeException('Scholarship application is no longer completable.');
                }

                $lockedApplication->update([
                    'status' => ScholarshipApplication::STATUS_COMPLETED,
                    'student_reviewed_at' => $lockedApplication->student_reviewed_at ?? $completedAt,
                    'completed_at' => $completedAt,
                ]);

                return $lockedApplication->fresh();
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => 'Pengajuan sudah berubah dan tidak dapat diselesaikan ulang.',
            ], 409);
        }

        return response()->json([
            'message' => 'Pengajuan berhasil diselesaikan.',
            'application' => $this->applicationPayload($application),
        ]);
    }

    private function completionArtifactError(
        ScholarshipApplication $application,
        LetterDocumentArtifactService $artifactService,
    ): ?JsonResponse {
        $artifact = $artifactService->latestArtifact(
            ScholarshipApplication::LETTER_TYPE,
            (int) $application->getKey(),
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        if (!$artifact) {
            return $this->completionArtifactUnavailable();
        }

        if ($artifact->status === LetterDocumentArtifact::STATUS_GENERATING) {
            return $this->completionArtifactErrorResponse(
                'Dokumen final masih sedang dibuat. Silakan coba lagi beberapa saat.',
                'artifact_generating',
                409,
            );
        }

        if ($artifact->status === LetterDocumentArtifact::STATUS_FAILED) {
            return $this->completionArtifactErrorResponse(
                'Dokumen final belum dapat tersedia karena proses pembuatan terakhir gagal. Silakan coba lagi nanti.',
                'artifact_failed',
                503,
            );
        }

        if (
            $artifact->status !== LetterDocumentArtifact::STATUS_READY
            || !$this->isExpectedPrivateArtifactPdfPath($artifact->pdf_path)
            || !Storage::disk('local')->exists($artifact->pdf_path)
        ) {
            return $this->completionArtifactUnavailable();
        }

        return null;
    }

    private function completionArtifactUnavailable(): JsonResponse
    {
        return $this->completionArtifactErrorResponse(
            'Dokumen final PDF belum tersedia.',
            'artifact_unavailable',
            404,
        );
    }

    private function completionArtifactErrorResponse(string $message, string $reason, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'reason' => $reason,
        ], $status);
    }

    private function isExpectedPrivateArtifactPdfPath(?string $path): bool
    {
        if (!is_string($path) || trim($path) === '') {
            return false;
        }

        $path = str_replace('\\', '/', trim($path));

        return !str_contains($path, '..')
            && str_starts_with(
                $path,
                'letter-document-artifacts/' . ScholarshipApplication::LETTER_TYPE . '/',
            )
            && str_ends_with(strtolower($path), '.pdf');
    }

    public function finalDownload(
        ScholarshipApplication $application,
        LetterDocumentArtifactService $artifactService,
    ) {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        if ($application->status !== ScholarshipApplication::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'Dokumen final hanya tersedia setelah pengajuan selesai.',
            ], 403);
        }

        $artifact = $artifactService->latestReadyArtifact(
            ScholarshipApplication::LETTER_TYPE,
            $application->id,
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
        );

        if (!$artifact || !$artifact->pdf_path || !Storage::disk('local')->exists($artifact->pdf_path)) {
            return response()->json([
                'message' => 'Dokumen final PDF belum tersedia.',
                'reason' => 'artifact_unavailable',
            ], 404);
        }

        $response = response()->download(
            Storage::disk('local')->path($artifact->pdf_path),
            $this->finalPdfFilename($application),
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );

        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
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
        $application->setAttribute('generated_docx_path', null);

        return $application;
    }

    private function forMahasiswaApplicationListResponse(ScholarshipApplication $application): array
    {
        $application = $this->redactGeneratedDocumentPath($application);
        $application->setAttribute('letter_type', ScholarshipApplication::LETTER_TYPE);

        return $this->applicationPayload($application);
    }

    private function applicationPayload(ScholarshipApplication $application): array
    {
        $application = $this->redactGeneratedDocumentPath($application);
        $application->loadMissing([
            'mahasiswaProfile.keluarga',
            'user.studyProgram.department.faculty',
            'user.department.faculty',
        ]);

        return $this->profileDataService->applicationPayload($application);
    }

    private function finalPdfFilename(ScholarshipApplication $application): string
    {
        return 'surat-permohonan-beasiswa-' . $application->id . '.pdf';
    }
}
