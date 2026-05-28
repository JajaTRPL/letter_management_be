<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesAcademicApplications;
use App\Models\LetterDocumentArtifact;
use App\Models\SuratPengantarMagangApplication;
use App\Services\AcademicRoutingService;
use App\Services\AcademicSignatoryService;
use App\Services\LetterAssignmentService;
use App\Services\LetterDocumentAccessService;
use App\Services\LetterDocumentArtifactService;
use App\Services\MahasiswaProfileDataService;
use App\Services\SuratPengantarMagangPreviewGenerationException;
use App\Services\SuratPengantarMagangPreviewGenerationService;
use App\Services\SuratPengantarMagangService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class SuratPengantarMagangController extends Controller
{
    use AuthorizesAcademicApplications;

    public function __construct(
        private LetterDocumentAccessService $documentAccessService,
        private LetterAssignmentService $assignmentService,
        private AcademicRoutingService $academicRoutingService,
        private MahasiswaProfileDataService $profileDataService
    ) {
    }

    public function getApplications()
    {
        $applications = SuratPengantarMagangApplication::where('user_id', Auth::id())
            ->where('status', '!=', SuratPengantarMagangApplication::STATUS_DRAFT)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn ($application) => $this->forMahasiswaResponse($application)),
        ]);
    }

    public function getDraft()
    {
        $user = Auth::user();
        $user->load('studyProgram.department.faculty', 'mahasiswaProfile');

        $application = SuratPengantarMagangApplication::where('user_id', $user->id)
            ->whereIn('status', [
                SuratPengantarMagangApplication::STATUS_DRAFT,
                SuratPengantarMagangApplication::STATUS_REVISION,
            ])
            ->with('mahasiswaProfile')
            ->latest()
            ->first();

        return response()->json([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'study_program' => $user->studyProgram,
            ],
            'profile' => $user->mahasiswaProfile,
            'profile_summary' => $this->profileDataService->profileSummaryForUser($user),
            'application' => $application ? $this->forMahasiswaResponse($application) : null,
        ]);
    }

    public function saveDraft(Request $request)
    {
        $application = $this->getOrCreateDraftApplication();

        $validator = Validator::make($request->all(), $this->applicationRules($application, $request->all()));
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only([
            'nama_penerima',
            'jabatan_penerima',
            'nama_perusahaan',
            'alamat_perusahaan',
            'alamat_jalan',
            'alamat_kelurahan',
            'alamat_kecamatan',
            'alamat_kota_kabupaten',
            'alamat_provinsi',
            'kode_pos',
            'peran',
            'rentang_tanggal',
            'tgl_mulai',
            'tgl_selesai',
            'dosen_pembimbing_dpa',
        ]);

        if ($request->hasFile('proposal_kegiatan_magang')) {
            $path = $request->file('proposal_kegiatan_magang')
                ->store('surat-pengantar-magang/proposals', 'public');
            $this->deletePublicFile($application->proposal_kegiatan_magang_path);
            $data['proposal_kegiatan_magang_path'] = Storage::url($path);
        }

        $application->update($data);

        return response()->json([
            'message' => 'Draft Surat Pengantar Magang berhasil disimpan',
            'application' => $this->forMahasiswaResponse($application->fresh('mahasiswaProfile')),
        ]);
    }

    public function submitApplication(
        SuratPengantarMagangService $service,
        SuratPengantarMagangPreviewGenerationService $previewGenerationService,
    )
    {
        $application = SuratPengantarMagangApplication::where('user_id', Auth::id())
            ->whereIn('status', [
                SuratPengantarMagangApplication::STATUS_DRAFT,
                SuratPengantarMagangApplication::STATUS_REVISION,
            ])
            ->latest()
            ->firstOrFail();

        $applicationData = $application->toArray();
        $validator = Validator::make($applicationData, $this->submissionRules($applicationData));
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $submittedAt = now();
        $actorId = Auth::id();

        try {
            $previewGenerationService->generateForPhase(
                $application,
                LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
                [
                    'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
                    'submitted_at' => $submittedAt,
                    'tanggal_surat' => $submittedAt,
                ],
                $actorId,
            );
        } catch (SuratPengantarMagangPreviewGenerationException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dokumen pratinjau pengajuan belum dapat dibuat. Silakan coba lagi.',
            ], 503);
        }

        try {
            [$application, $assignedTendik] = DB::transaction(function () use ($application, $service, $submittedAt) {
                $lockedApplication = SuratPengantarMagangApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $lockedApplication->user_id !== (int) Auth::id()
                    || !in_array($lockedApplication->status, [
                        SuratPengantarMagangApplication::STATUS_DRAFT,
                        SuratPengantarMagangApplication::STATUS_REVISION,
                    ], true)
                ) {
                    throw new RuntimeException('Magang application is no longer submittable.');
                }

                $lockedApplication->update([
                    'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
                    'submitted_at' => $submittedAt,
                    'revision_note' => null,
                    'rejection_reason' => null,
                ]);

                $assignedTendik = $service->assignApplication($lockedApplication);

                return [$lockedApplication->fresh(), $assignedTendik];
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => 'Pengajuan sudah berubah dan tidak dapat dikirim ulang.',
            ], 409);
        }

        return response()->json([
            'message' => 'Pengajuan Surat Pengantar Magang berhasil dikirim',
            'application' => $this->forMahasiswaResponse($application->fresh('mahasiswaProfile')),
            'assigned_to' => $assignedTendik?->name,
        ]);
    }

    public function showForMahasiswa(SuratPengantarMagangApplication $application)
    {
        $this->documentAccessService->ensureOwner($application, Auth::user());
        $application->load([
            'user.studyProgram.department.faculty',
            'user.department.faculty',
            'mahasiswaProfile',
            'assignedTendik',
        ]);

        return response()->json([
            'application' => $this->forMahasiswaResponse($application),
            'profile_summary' => $this->profileDataService->profileSummaryForApplication($application),
        ]);
    }

    public function showForReviewer(SuratPengantarMagangApplication $application)
    {
        $this->authorizeTendikDetailIfApplicable(SuratPengantarMagangApplication::LETTER_TYPE);
        $this->authorizeAcademicDetailIfApplicable($application);

        // Load the canonical academic chain so the FE can render Prodi / Fakultas / Departemen
        // from the relation tree instead of the legacy mahasiswa_profiles.{program_studi,fakultas}
        // text columns (which may be null for admin-created accounts and are being deprecated).
        $application->load([
            'user.studyProgram.department.faculty',
            'user.department.faculty',
            'mahasiswaProfile',
            'assignedTendik',
        ]);

        return response()->json([
            'application' => $application,
            'profile_summary' => $this->profileDataService->profileSummaryForApplication($application),
        ]);
    }

    public function approveByTendik(
        Request $request,
        SuratPengantarMagangApplication $application,
        SuratPengantarMagangPreviewGenerationService $previewGenerationService,
    )
    {
        $this->authorizeTendikAction(SuratPengantarMagangApplication::LETTER_TYPE);

        $validator = Validator::make($request->all(), [
            'nomor_surat' => ['nullable', 'string', 'max:100'],
            'nomor_surat_pengantar' => ['required', 'string', 'max:100'],
            'nomor_surat_tugas' => ['required', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($application->status !== SuratPengantarMagangApplication::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Pengajuan tidak berada pada tahap verifikasi Tendik.'], 422);
        }

        $approvedAt = now();
        $actorId = Auth::id();
        $nomorPengantar = $request->input('nomor_surat_pengantar');
        $nomorTugas = $request->input('nomor_surat_tugas');

        try {
            $previewGenerationService->generateForPhase(
                $application,
                LetterDocumentArtifact::PHASE_PRODI_REVIEW,
                [
                    'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
                    'nomor_surat_pengantar' => $nomorPengantar,
                    'nomor_surat_tugas' => $nomorTugas,
                    'tendik_approved_at' => $approvedAt,
                    'tendik_approved_by' => $actorId,
                    'tanggal_surat' => $approvedAt,
                ],
                $actorId,
            );
        } catch (SuratPengantarMagangPreviewGenerationException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dokumen pratinjau verifikasi belum dapat dibuat. Silakan coba lagi.',
            ], 503);
        }

        try {
            $application = DB::transaction(function () use ($application, $approvedAt, $actorId, $nomorPengantar, $nomorTugas) {
                $lockedApplication = SuratPengantarMagangApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $actor = Auth::user()?->fresh();
                if (
                    !$actor
                    || !$this->assignmentService->canHandleAny($actor, SuratPengantarMagangApplication::LETTER_TYPE)
                    || $lockedApplication->status !== SuratPengantarMagangApplication::STATUS_SUBMITTED
                ) {
                    throw new RuntimeException('Magang application is no longer approvable by Tendik.');
                }

                $lockedApplication->update([
                    'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
                    // Preserve the single-number compatibility field while the final contract uses both numbers.
                    'nomor_surat' => $nomorPengantar,
                    'nomor_surat_pengantar' => $nomorPengantar,
                    'nomor_surat_tugas' => $nomorTugas,
                    'assigned_to' => $lockedApplication->assigned_to ?: $actorId,
                    'tendik_approved_at' => $approvedAt,
                    'tendik_approved_by' => $actorId,
                    'revision_note' => null,
                    'rejection_reason' => null,
                ]);

                return $lockedApplication->fresh();
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => 'Pengajuan sudah berubah dan tidak dapat diverifikasi ulang.',
            ], 409);
        }

        return response()->json([
            'message' => 'Pengajuan berhasil diverifikasi dan diteruskan ke Kaprodi/Sekprodi',
            'application' => $application,
        ]);
    }

    public function reviseByTendik(Request $request, SuratPengantarMagangApplication $application)
    {
        $this->authorizeTendikAction(SuratPengantarMagangApplication::LETTER_TYPE);

        return $this->markRevision($request, $application, [
            SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);
    }

    public function rejectByTendik(Request $request, SuratPengantarMagangApplication $application)
    {
        $this->authorizeTendikAction(SuratPengantarMagangApplication::LETTER_TYPE);

        return $this->markRejected($request, $application, [
            SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);
    }

    /**
     * Hard-gate Tendik action endpoints to Persuratan Tendik. Non-Persuratan
     * Tendik (Sarpras/Kepala Lab/Laboran) and non-Tendik roles get 403.
     */
    private function authorizeTendikAction(string $letterType): void
    {
        $user = Auth::user();
        if (!$this->assignmentService->canHandleAny($user, $letterType)) {
            abort(403, 'Tidak berwenang memproses pengajuan ini.');
        }
    }

    private function authorizeTendikDetailIfApplicable(string $letterType): void
    {
        $user = Auth::user();
        if ($user?->role !== 'tendik') {
            return;
        }

        if (!$this->assignmentService->canHandleAny($user, $letterType)) {
            abort(403, 'Tidak berwenang melihat detail pengajuan ini.');
        }
    }

    private function authorizeAcademicDetailIfApplicable(SuratPengantarMagangApplication $application): void
    {
        if (Auth::user()?->role !== 'akademik') {
            return;
        }

        $this->authorizeAcademicDetail($application, $this->academicRoutingService);
    }

    public function approveByAkademik(
        SuratPengantarMagangApplication $application,
        SuratPengantarMagangPreviewGenerationService $previewGenerationService,
        AcademicSignatoryService $signatoryService,
    )
    {
        $user = Auth::user();
        $subRole = $user->sub_role;
        $guardResponse = $this->guardAcademicAction(
            $application,
            $this->academicRoutingService,
            SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            'Pengajuan tidak berada pada tahap persetujuan Kaprodi/Sekprodi.',
            'Pengajuan tidak berada pada tahap persetujuan Kadep/Sekdep.'
        );
        if ($guardResponse) {
            return $guardResponse;
        }

        if (in_array($subRole, ['kaprodi', 'sekprodi'], true)) {
            if ($application->status !== SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK) {
                return response()->json(['message' => 'Pengajuan tidak berada pada tahap persetujuan Kaprodi/Sekprodi.'], 422);
            }

            $approvedAt = now();
            $actorId = Auth::id();
            $letterDate = $application->tendik_approved_at
                ?? $application->submitted_at
                ?? $application->created_at
                ?? $approvedAt;

            try {
                $previewGenerationService->generateForPhase(
                    $application,
                    LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
                    [
                        'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
                        'kaprodi_approved_at' => $approvedAt,
                        'kaprodi_approved_by' => $actorId,
                        'tanggal_surat' => $letterDate,
                    ],
                    $actorId,
                );
            } catch (SuratPengantarMagangPreviewGenerationException $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Dokumen pratinjau persetujuan Prodi belum dapat dibuat. Silakan coba lagi.',
                ], 503);
            }

            try {
                $application = DB::transaction(function () use ($application, $approvedAt, $actorId) {
                    $lockedApplication = SuratPengantarMagangApplication::query()
                        ->whereKey($application->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    $actor = Auth::user()?->fresh();
                    if (
                        !$actor
                        || !$this->academicRoutingService->isProdiApprover($actor)
                        || $lockedApplication->status !== SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK
                        || !$this->academicRoutingService->canHandleProdiStage($actor, $lockedApplication)
                    ) {
                        throw new RuntimeException('Magang application is no longer approvable by Prodi.');
                    }

                    $lockedApplication->update([
                        'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
                        'kaprodi_approved_at' => $approvedAt,
                        'kaprodi_approved_by' => $actorId,
                        'revision_note' => null,
                        'rejection_reason' => null,
                    ]);

                    return $lockedApplication->fresh();
                });
            } catch (RuntimeException $exception) {
                return response()->json([
                    'message' => 'Pengajuan sudah berubah dan tidak dapat disetujui ulang oleh Prodi.',
                ], 409);
            }

            return response()->json([
                'message' => 'Pengajuan disetujui dan diteruskan ke Kadep/Sekdep',
                'application' => $application,
            ]);
        }

        if (in_array($subRole, ['kadep', 'sekdep'], true)) {
            if ($application->status !== SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI) {
                return response()->json(['message' => 'Pengajuan tidak berada pada tahap persetujuan Kadep/Sekdep.'], 422);
            }

            $approvedAt = now();
            $actorId = Auth::id();
            $letterDate = $application->tendik_approved_at
                ?? $application->submitted_at
                ?? $application->created_at
                ?? $approvedAt;
            $officialKadep = $signatoryService->officialKadepForApplication($application);

            try {
                $previewGenerationService->generateForPhase(
                    $application,
                    LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
                    [
                        'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                        'kadep_approved_at' => $approvedAt,
                        'kadep_approved_by' => $actorId,
                        'official_kadep' => $officialKadep,
                        'tanggal_surat' => $letterDate,
                    ],
                    $actorId,
                );
            } catch (SuratPengantarMagangPreviewGenerationException $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Dokumen pratinjau review mahasiswa belum dapat dibuat. Silakan coba lagi.',
                ], 503);
            }

            try {
                $application = DB::transaction(function () use ($application, $approvedAt, $actorId) {
                    $lockedApplication = SuratPengantarMagangApplication::whereKey($application->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $actor = Auth::user()?->fresh();
                    if (
                        !$actor
                        || !$this->academicRoutingService->isDepartmentApprover($actor)
                        || $lockedApplication->status !== SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI
                        || !$this->academicRoutingService->canHandleDepartmentStage($actor, $lockedApplication)
                    ) {
                        throw new RuntimeException('Magang application is no longer approvable by Kadep/Sekdep.');
                    }

                    $lockedApplication->update([
                        'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                        'kadep_approved_at' => $approvedAt,
                        'kadep_approved_by' => $actorId,
                        'revision_note' => null,
                        'rejection_reason' => null,
                    ]);

                    return $lockedApplication->fresh();
                });
            } catch (RuntimeException $exception) {
                return response()->json([
                    'message' => 'Pengajuan sudah berubah dan tidak dapat disetujui ulang oleh Kadep/Sekdep.',
                ], 409);
            }

            return response()->json([
                'message' => 'Pengajuan disetujui dan menunggu review mahasiswa',
                'application' => $application,
            ]);
        }

        return response()->json(['message' => 'Sub-role akademik tidak dikenali.'], 403);
    }

    public function complete(
        SuratPengantarMagangApplication $application,
        LetterDocumentArtifactService $artifactService
    )
    {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        if ($application->status === SuratPengantarMagangApplication::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'Pengajuan sudah selesai.',
                'application' => $this->forMahasiswaResponse($application->fresh(['mahasiswaProfile', 'assignedTendik'])),
            ]);
        }

        if (!$this->documentAccessService->canComplete($application)) {
            return response()->json([
                'message' => 'Pengajuan belum berada pada tahap review mahasiswa.',
            ], 422);
        }

        $artifactError = $this->completionArtifactError($application, $artifactService);
        if ($artifactError) {
            return $artifactError;
        }

        $completedAt = now();

        try {
            $application = DB::transaction(function () use ($application, $completedAt) {
                $lockedApplication = SuratPengantarMagangApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $lockedApplication->user_id !== (int) Auth::id()
                    || $lockedApplication->status !== SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW
                ) {
                    throw new RuntimeException('Magang application is no longer completable.');
                }

                $lockedApplication->update([
                    'status' => SuratPengantarMagangApplication::STATUS_COMPLETED,
                    'student_reviewed_at' => $completedAt,
                    'completed_at' => $completedAt,
                ]);

                return $lockedApplication->fresh(['mahasiswaProfile', 'assignedTendik']);
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => 'Pengajuan sudah berubah dan tidak dapat diselesaikan ulang.',
            ], 409);
        }

        return response()->json([
            'message' => 'Pengajuan Surat Pengantar Magang telah diselesaikan.',
            'application' => $this->forMahasiswaResponse($application),
        ]);
    }

    private function completionArtifactError(
        SuratPengantarMagangApplication $application,
        LetterDocumentArtifactService $artifactService
    ): ?JsonResponse {
        $artifact = $artifactService->latestArtifact(
            SuratPengantarMagangApplication::LETTER_TYPE,
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
                'letter-document-artifacts/' . SuratPengantarMagangApplication::LETTER_TYPE . '/',
            )
            && str_ends_with(strtolower($path), '.pdf');
    }

    public function reviseByAkademik(Request $request, SuratPengantarMagangApplication $application)
    {
        $guardResponse = $this->guardAcademicAction(
            $application,
            $this->academicRoutingService,
            SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            'Pengajuan tidak dapat direvisi pada tahap ini.',
            'Pengajuan tidak dapat direvisi pada tahap ini.'
        );
        if ($guardResponse) {
            return $guardResponse;
        }

        return $this->markRevision($request, $application, [
            $application->status,
        ]);
    }

    public function rejectByAkademik(Request $request, SuratPengantarMagangApplication $application)
    {
        $guardResponse = $this->guardAcademicAction(
            $application,
            $this->academicRoutingService,
            SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            'Pengajuan tidak dapat ditolak pada tahap ini.',
            'Pengajuan tidak dapat ditolak pada tahap ini.'
        );
        if ($guardResponse) {
            return $guardResponse;
        }

        return $this->markRejected($request, $application, [
            $application->status,
        ]);
    }

    private function getOrCreateDraftApplication(): SuratPengantarMagangApplication
    {
        $user = Auth::user();
        $profile = $user->mahasiswaProfile ?? $user->mahasiswaProfile()->create([]);

        $editableApplication = SuratPengantarMagangApplication::where('user_id', $user->id)
            ->whereIn('status', [
                SuratPengantarMagangApplication::STATUS_DRAFT,
                SuratPengantarMagangApplication::STATUS_REVISION,
            ])
            ->latest()
            ->first();

        if ($editableApplication) {
            return $editableApplication;
        }

        return SuratPengantarMagangApplication::firstOrCreate(
            [
                'user_id' => $user->id,
                'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
            ],
            [
                'mahasiswa_profile_id' => $profile->id,
            ]
        );
    }

    private function applicationRules(?SuratPengantarMagangApplication $application = null, array $data = []): array
    {
        return array_merge([
            'nama_penerima' => 'required|string|max:255',
            'nama_perusahaan' => 'required|string|max:255',
            'alamat_perusahaan' => 'required|string|max:2000',
            'peran' => 'required|string|max:255',
            'rentang_tanggal' => 'required|string|max:255',
            'dosen_pembimbing_dpa' => 'required|string|max:255',
            'proposal_kegiatan_magang' => [
                $application?->proposal_kegiatan_magang_path ? 'nullable' : 'required',
                'file',
                'mimes:pdf',
                'max:2048',
            ],
        ], $this->finalContractInputRules($data));
    }

    private function submissionRules(array $data = []): array
    {
        return array_merge([
            'nama_penerima' => 'required|string|max:255',
            'nama_perusahaan' => 'required|string|max:255',
            'alamat_perusahaan' => 'required|string|max:2000',
            'peran' => 'required|string|max:255',
            'rentang_tanggal' => 'required|string|max:255',
            'dosen_pembimbing_dpa' => 'required|string|max:255',
            'proposal_kegiatan_magang_path' => 'required|string',
        ], $this->finalContractInputRules($data));
    }

    /**
     * Additive contract fields remain optional until the matching frontend
     * cutover; once both dates are supplied they must form a valid range.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function finalContractInputRules(array $data): array
    {
        $tglSelesaiRules = ['nullable', 'date'];
        if (!empty($data['tgl_mulai']) && !empty($data['tgl_selesai'])) {
            $tglSelesaiRules[] = 'after_or_equal:tgl_mulai';
        }

        return [
            'jabatan_penerima' => 'nullable|string|max:255',
            'alamat_jalan' => 'nullable|string|max:2000',
            'alamat_kelurahan' => 'nullable|string|max:255',
            'alamat_kecamatan' => 'nullable|string|max:255',
            'alamat_kota_kabupaten' => 'nullable|string|max:255',
            'alamat_provinsi' => 'nullable|string|max:255',
            'kode_pos' => 'nullable|string|max:20',
            'tgl_mulai' => 'nullable|date',
            'tgl_selesai' => $tglSelesaiRules,
        ];
    }

    private function markRevision(Request $request, SuratPengantarMagangApplication $application, array $allowedStatuses)
    {
        $validator = Validator::make($request->all(), [
            'note' => 'required|string|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!in_array($application->status, $allowedStatuses, true)) {
            return response()->json(['message' => 'Pengajuan tidak dapat direvisi pada tahap ini.'], 422);
        }

        $application->update([
            'status' => SuratPengantarMagangApplication::STATUS_REVISION,
            'revision_note' => $request->note,
            'rejection_reason' => null,
            'revised_at' => now(),
            'revised_by' => Auth::id(),
            'assigned_to' => $application->assigned_to ?: Auth::id(),
        ]);

        return response()->json([
            'message' => 'Permintaan revisi berhasil dikirim',
            'application' => $application->fresh(),
        ]);
    }

    private function markRejected(Request $request, SuratPengantarMagangApplication $application, array $allowedStatuses)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!in_array($application->status, $allowedStatuses, true)) {
            return response()->json(['message' => 'Pengajuan tidak dapat ditolak pada tahap ini.'], 422);
        }

        $application->update([
            'status' => SuratPengantarMagangApplication::STATUS_REJECTED,
            'rejection_reason' => $request->reason,
            'revision_note' => null,
            'rejected_at' => now(),
            'rejected_by' => Auth::id(),
            'assigned_to' => $application->assigned_to ?: Auth::id(),
        ]);

        return response()->json([
            'message' => 'Pengajuan berhasil ditolak',
            'application' => $application->fresh(),
        ]);
    }

    private function forMahasiswaResponse(SuratPengantarMagangApplication $application): SuratPengantarMagangApplication
    {
        $application->setAttribute('generated_pdf_path', null);

        return $application;
    }

    private function deletePublicFile(?string $filePath): void
    {
        $path = $this->publicDiskPath($filePath);
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
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

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return str_starts_with($path, 'surat-pengantar-magang/proposals/') ? $path : null;
    }
}
