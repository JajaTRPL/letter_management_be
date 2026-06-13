<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AddsSupportingDocumentMetadata;
use App\Http\Controllers\Concerns\AuthorizesAcademicApplications;
use App\Models\LetterDocumentArtifact;
use App\Models\SuratTugasApplication;
use App\Services\AcademicRoutingService;
use App\Services\AcademicSignatoryService;
use App\Services\LetterAssignmentService;
use App\Services\LetterAttachmentMetadataService;
use App\Services\LetterAttachmentRequirementService;
use App\Services\LetterAttachmentUploadService;
use App\Services\LetterDocumentAccessService;
use App\Services\LetterDocumentArtifactService;
use App\Services\LetterRetentionSummaryService;
use App\Services\MahasiswaProfileDataService;
use App\Services\SuratTugasPreviewGenerationException;
use App\Services\SuratTugasPreviewGenerationService;
use App\Services\SuratTugasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Standalone Surat Tugas workflow runtime. Mirrors the canonical Magang
 * controller conventions exactly, with two differences mandated by the Surat
 * Tugas contract: (1) supporting PDFs (proposal + uploaded Surat Pengantar
 * Magang) are stored on the PRIVATE local disk under surat-tugas/supporting/*
 * (never the public disk / never /api/storage), and (2) Tendik approval
 * requires nomor_surat_tugas. No Magang identity, model, route, artifact, or
 * record is reused; the uploaded Surat Pengantar Magang is a supporting file
 * only.
 */
class SuratTugasController extends Controller
{
    use AuthorizesAcademicApplications;
    use AddsSupportingDocumentMetadata;

    public function __construct(
        private LetterDocumentAccessService $documentAccessService,
        private LetterAssignmentService $assignmentService,
        private AcademicRoutingService $academicRoutingService,
        private MahasiswaProfileDataService $profileDataService,
        private LetterAttachmentMetadataService $attachmentMetadataService,
        private LetterRetentionSummaryService $retentionSummaryService,
    ) {
    }

    public function getApplications()
    {
        $applications = SuratTugasApplication::where('user_id', Auth::id())
            ->where('status', '!=', SuratTugasApplication::STATUS_DRAFT)
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

        $application = SuratTugasApplication::where('user_id', $user->id)
            ->whereIn('status', [
                SuratTugasApplication::STATUS_DRAFT,
                SuratTugasApplication::STATUS_REVISION,
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

    public function saveDraft(Request $request, LetterAttachmentUploadService $attachmentUploadService)
    {
        $application = $this->getOrCreateDraftApplication();

        $validator = Validator::make($request->all(), $this->applicationRules($request->all()));
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only([
            'nama_perusahaan',
            'kegiatan',
            'posisi',
            'dosen_pembimbing_dpa',
            'tgl_mulai',
            'tgl_selesai',
        ]);

        // Supporting uploads converge onto the shared private attachment registry.
        // The upload service owns the write, registry row, checksum metadata, and
        // after-commit replacement cleanup.
        foreach (['proposal', 'surat_pengantar_magang'] as $documentKey) {
            $fileField = $documentKey === 'proposal' ? 'proposal_kegiatan_magang' : 'surat_pengantar_magang';
            if ($request->hasFile($fileField)) {
                $attachmentUploadService->store(
                    $application,
                    SuratTugasApplication::LETTER_TYPE,
                    $documentKey,
                    $request->file($fileField),
                    Auth::id(),
                );
            }
        }

        $application->update($data);

        return response()->json([
            'message' => 'Draft Surat Tugas berhasil disimpan',
            'application' => $this->forMahasiswaResponse($application->fresh('mahasiswaProfile')),
        ]);
    }

    public function submitApplication(
        SuratTugasService $service,
        SuratTugasPreviewGenerationService $previewGenerationService,
        LetterAttachmentRequirementService $attachmentRequirementService,
    ) {
        $application = SuratTugasApplication::where('user_id', Auth::id())
            ->whereIn('status', [
                SuratTugasApplication::STATUS_DRAFT,
                SuratTugasApplication::STATUS_REVISION,
            ])
            ->latest()
            ->firstOrFail();

        $applicationData = $application->toArray();
        $validator = Validator::make($applicationData, $this->submissionRules($applicationData));
        $this->addMissingAttachmentRequirementErrors(
            $validator,
            $attachmentRequirementService,
            SuratTugasApplication::LETTER_TYPE,
            (int) $application->getKey(),
        );
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
                    'status' => SuratTugasApplication::STATUS_SUBMITTED,
                    'submitted_at' => $submittedAt,
                    'tanggal_surat' => $submittedAt,
                ],
                $actorId,
            );
        } catch (SuratTugasPreviewGenerationException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dokumen pratinjau pengajuan belum dapat dibuat. Silakan coba lagi.',
            ], 503);
        }

        try {
            [$application, $assignedTendik] = DB::transaction(function () use ($application, $service, $submittedAt) {
                $lockedApplication = SuratTugasApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $lockedApplication->user_id !== (int) Auth::id()
                    || !in_array($lockedApplication->status, [
                        SuratTugasApplication::STATUS_DRAFT,
                        SuratTugasApplication::STATUS_REVISION,
                    ], true)
                ) {
                    throw new RuntimeException('Surat Tugas application is no longer submittable.');
                }

                $lockedApplication->update([
                    'status' => SuratTugasApplication::STATUS_SUBMITTED,
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
            'message' => 'Pengajuan Surat Tugas berhasil dikirim',
            'application' => $this->forMahasiswaResponse($application->fresh('mahasiswaProfile')),
            'assigned_to' => $assignedTendik?->name,
        ]);
    }

    public function showForMahasiswa(SuratTugasApplication $application)
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

    public function showForReviewer(SuratTugasApplication $application)
    {
        $this->authorizeTendikDetailIfApplicable(SuratTugasApplication::LETTER_TYPE);
        $this->authorizeAcademicDetailIfApplicable($application);

        $application->load([
            'user.studyProgram.department.faculty',
            'user.department.faculty',
            'mahasiswaProfile',
            'assignedTendik',
        ]);

        return response()->json([
            'application' => $this->withSupportingDocumentMetadata(
                $application,
                SuratTugasApplication::LETTER_TYPE,
                $this->attachmentMetadataService,
                $this->retentionSummaryService,
            ),
            'profile_summary' => $this->profileDataService->profileSummaryForApplication($application),
        ]);
    }

    public function approveByTendik(
        Request $request,
        SuratTugasApplication $application,
        SuratTugasPreviewGenerationService $previewGenerationService,
    ) {
        $this->authorizeTendikAction(SuratTugasApplication::LETTER_TYPE);

        $validator = Validator::make($request->all(), [
            'nomor_surat_tugas' => ['required', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($application->status !== SuratTugasApplication::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Pengajuan tidak berada pada tahap verifikasi Tendik.'], 422);
        }

        $approvedAt = now();
        $actorId = Auth::id();
        $nomorTugas = $request->input('nomor_surat_tugas');

        try {
            $previewGenerationService->generateForPhase(
                $application,
                LetterDocumentArtifact::PHASE_PRODI_REVIEW,
                [
                    'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
                    'nomor_surat_tugas' => $nomorTugas,
                    'tendik_approved_at' => $approvedAt,
                    'tendik_approved_by' => $actorId,
                    'tanggal_surat' => $approvedAt,
                ],
                $actorId,
            );
        } catch (SuratTugasPreviewGenerationException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dokumen pratinjau verifikasi belum dapat dibuat. Silakan coba lagi.',
            ], 503);
        }

        try {
            $application = DB::transaction(function () use ($application, $approvedAt, $actorId, $nomorTugas) {
                $lockedApplication = SuratTugasApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $actor = Auth::user()?->fresh();
                if (
                    !$actor
                    || !$this->assignmentService->canHandleAny($actor, SuratTugasApplication::LETTER_TYPE)
                    || $lockedApplication->status !== SuratTugasApplication::STATUS_SUBMITTED
                ) {
                    throw new RuntimeException('Surat Tugas application is no longer approvable by Tendik.');
                }

                $lockedApplication->update([
                    'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
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
            'application' => $this->withRetiredAttachmentFieldsHidden(
                $application,
                SuratTugasApplication::LETTER_TYPE,
            ),
        ]);
    }

    public function reviseByTendik(Request $request, SuratTugasApplication $application)
    {
        $this->authorizeTendikAction(SuratTugasApplication::LETTER_TYPE);

        return $this->markRevision($request, $application, [
            SuratTugasApplication::STATUS_SUBMITTED,
        ]);
    }

    public function rejectByTendik(Request $request, SuratTugasApplication $application)
    {
        $this->authorizeTendikAction(SuratTugasApplication::LETTER_TYPE);

        return $this->markRejected($request, $application, [
            SuratTugasApplication::STATUS_SUBMITTED,
        ]);
    }

    public function approveByAkademik(
        SuratTugasApplication $application,
        SuratTugasPreviewGenerationService $previewGenerationService,
        AcademicSignatoryService $signatoryService,
    ) {
        $user = Auth::user();
        $subRole = $user->sub_role;
        $guardResponse = $this->guardAcademicAction(
            $application,
            $this->academicRoutingService,
            SuratTugasApplication::STATUS_APPROVED_TENDIK,
            SuratTugasApplication::STATUS_APPROVED_KAPRODI,
            'Pengajuan tidak berada pada tahap persetujuan Kaprodi/Sekprodi.',
            'Pengajuan tidak berada pada tahap persetujuan Kadep/Sekdep.'
        );
        if ($guardResponse) {
            return $guardResponse;
        }

        if (in_array($subRole, ['kaprodi', 'sekprodi'], true)) {
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
                        'status' => SuratTugasApplication::STATUS_APPROVED_KAPRODI,
                        'kaprodi_approved_at' => $approvedAt,
                        'kaprodi_approved_by' => $actorId,
                        'tanggal_surat' => $letterDate,
                    ],
                    $actorId,
                );
            } catch (SuratTugasPreviewGenerationException $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Dokumen pratinjau persetujuan Prodi belum dapat dibuat. Silakan coba lagi.',
                ], 503);
            }

            try {
                $application = DB::transaction(function () use ($application, $approvedAt, $actorId) {
                    $lockedApplication = SuratTugasApplication::query()
                        ->whereKey($application->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    $actor = Auth::user()?->fresh();
                    if (
                        !$actor
                        || !$this->academicRoutingService->isProdiApprover($actor)
                        || $lockedApplication->status !== SuratTugasApplication::STATUS_APPROVED_TENDIK
                        || !$this->academicRoutingService->canHandleProdiStage($actor, $lockedApplication)
                    ) {
                        throw new RuntimeException('Surat Tugas application is no longer approvable by Prodi.');
                    }

                    $lockedApplication->update([
                        'status' => SuratTugasApplication::STATUS_APPROVED_KAPRODI,
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
                'application' => $this->withRetiredAttachmentFieldsHidden(
                    $application,
                    SuratTugasApplication::LETTER_TYPE,
                ),
            ]);
        }

        if (in_array($subRole, ['kadep', 'sekdep'], true)) {
            $approvedAt = now();
            $actorId = Auth::id();
            $letterDate = $application->tendik_approved_at
                ?? $application->submitted_at
                ?? $application->created_at
                ?? $approvedAt;
            $officialKadep = $signatoryService->officialKadepForApplication($application);
            if (!$officialKadep) {
                return response()->json([
                    'message' => 'Konfigurasi Ketua Departemen aktif belum tersedia untuk departemen mahasiswa. Mohon hubungi administrator untuk menetapkan Ketua Departemen aktif sebelum dokumen final dapat dibuat.',
                    'reason' => 'missing_official_kadep',
                ], 422);
            }

            try {
                $previewGenerationService->generateForPhase(
                    $application,
                    LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
                    [
                        'status' => SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                        'kadep_approved_at' => $approvedAt,
                        'kadep_approved_by' => $actorId,
                        'official_kadep' => $officialKadep,
                        'tanggal_surat' => $letterDate,
                    ],
                    $actorId,
                );
            } catch (SuratTugasPreviewGenerationException $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Dokumen pratinjau review mahasiswa belum dapat dibuat. Silakan coba lagi.',
                ], 503);
            }

            try {
                $application = DB::transaction(function () use ($application, $approvedAt, $actorId) {
                    $lockedApplication = SuratTugasApplication::whereKey($application->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $actor = Auth::user()?->fresh();
                    if (
                        !$actor
                        || !$this->academicRoutingService->isDepartmentApprover($actor)
                        || $lockedApplication->status !== SuratTugasApplication::STATUS_APPROVED_KAPRODI
                        || !$this->academicRoutingService->canHandleDepartmentStage($actor, $lockedApplication)
                    ) {
                        throw new RuntimeException('Surat Tugas application is no longer approvable by Kadep/Sekdep.');
                    }

                    $lockedApplication->update([
                        'status' => SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW,
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
                'application' => $this->withRetiredAttachmentFieldsHidden(
                    $application,
                    SuratTugasApplication::LETTER_TYPE,
                ),
            ]);
        }

        return response()->json(['message' => 'Sub-role akademik tidak dikenali.'], 403);
    }

    public function reviseByAkademik(Request $request, SuratTugasApplication $application)
    {
        $guardResponse = $this->guardAcademicAction(
            $application,
            $this->academicRoutingService,
            SuratTugasApplication::STATUS_APPROVED_TENDIK,
            SuratTugasApplication::STATUS_APPROVED_KAPRODI,
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

    public function rejectByAkademik(Request $request, SuratTugasApplication $application)
    {
        $guardResponse = $this->guardAcademicAction(
            $application,
            $this->academicRoutingService,
            SuratTugasApplication::STATUS_APPROVED_TENDIK,
            SuratTugasApplication::STATUS_APPROVED_KAPRODI,
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

    public function complete(
        SuratTugasApplication $application,
        LetterDocumentArtifactService $artifactService
    ) {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        if ($application->status === SuratTugasApplication::STATUS_COMPLETED) {
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
                $lockedApplication = SuratTugasApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $lockedApplication->user_id !== (int) Auth::id()
                    || $lockedApplication->status !== SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW
                ) {
                    throw new RuntimeException('Surat Tugas application is no longer completable.');
                }

                $lockedApplication->update([
                    'status' => SuratTugasApplication::STATUS_COMPLETED,
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
            'message' => 'Pengajuan Surat Tugas telah diselesaikan.',
            'application' => $this->forMahasiswaResponse($application),
        ]);
    }

    private function completionArtifactError(
        SuratTugasApplication $application,
        LetterDocumentArtifactService $artifactService
    ): ?JsonResponse {
        $artifact = $artifactService->latestArtifact(
            SuratTugasApplication::LETTER_TYPE,
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
                'letter-document-artifacts/' . SuratTugasApplication::LETTER_TYPE . '/',
            )
            && str_ends_with(strtolower($path), '.pdf');
    }

    /**
     * Hard-gate Tendik action endpoints to Persuratan Tendik assigned to
     * Surat Tugas. Non-Persuratan Tendik and non-Tendik roles get 403.
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

    private function authorizeAcademicDetailIfApplicable(SuratTugasApplication $application): void
    {
        if (Auth::user()?->role !== 'akademik') {
            return;
        }

        $this->authorizeAcademicDetail($application, $this->academicRoutingService);
    }

    private function getOrCreateDraftApplication(): SuratTugasApplication
    {
        $user = Auth::user();
        $profile = $user->mahasiswaProfile ?? $user->mahasiswaProfile()->create([]);

        $editableApplication = SuratTugasApplication::where('user_id', $user->id)
            ->whereIn('status', [
                SuratTugasApplication::STATUS_DRAFT,
                SuratTugasApplication::STATUS_REVISION,
            ])
            ->latest()
            ->first();

        if ($editableApplication) {
            return $editableApplication;
        }

        return SuratTugasApplication::firstOrCreate(
            [
                'user_id' => $user->id,
                'status' => SuratTugasApplication::STATUS_DRAFT,
            ],
            [
                'mahasiswa_profile_id' => $profile->id,
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applicationRules(array $data): array
    {
        $tglSelesaiRules = ['nullable', 'date'];
        if (!empty($data['tgl_mulai']) && !empty($data['tgl_selesai'])) {
            $tglSelesaiRules[] = 'after_or_equal:tgl_mulai';
        }

        return [
            'nama_perusahaan' => 'nullable|string|max:255',
            'kegiatan' => 'nullable|string|max:500',
            'posisi' => 'nullable|string|max:255',
            'dosen_pembimbing_dpa' => 'nullable|string|max:255',
            'tgl_mulai' => 'nullable|date',
            'tgl_selesai' => $tglSelesaiRules,
            'proposal_kegiatan_magang' => ['nullable', 'file', 'mimes:pdf', 'max:2048'],
            'surat_pengantar_magang' => ['nullable', 'file', 'mimes:pdf', 'max:2048'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function submissionRules(array $data): array
    {
        $tglSelesaiRules = ['required', 'date'];
        if (!empty($data['tgl_mulai']) && !empty($data['tgl_selesai'])) {
            $tglSelesaiRules[] = 'after_or_equal:tgl_mulai';
        }

        return [
            'nama_perusahaan' => 'required|string|max:255',
            'kegiatan' => 'required|string|max:500',
            'posisi' => 'required|string|max:255',
            'dosen_pembimbing_dpa' => 'required|string|max:255',
            'tgl_mulai' => 'required|date',
            'tgl_selesai' => $tglSelesaiRules,
        ];
    }

    private function addMissingAttachmentRequirementErrors(
        \Illuminate\Validation\Validator $validator,
        LetterAttachmentRequirementService $attachmentRequirementService,
        string $letterType,
        int $applicationId,
    ): void {
        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($attachmentRequirementService, $letterType, $applicationId): void {
            foreach ($attachmentRequirementService->missingRequiredDocumentKeys($letterType, $applicationId) as $documentKey) {
                $attribute = $attachmentRequirementService->legacyValidationAttribute($letterType, $documentKey);
                $validator->errors()->add(
                    $attribute,
                    'The ' . str_replace('_', ' ', $attribute) . ' field is required.',
                );
            }
        });
    }

    private function markRevision(Request $request, SuratTugasApplication $application, array $allowedStatuses)
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
            'status' => SuratTugasApplication::STATUS_REVISION,
            'revision_note' => $request->note,
            'rejection_reason' => null,
            'revised_at' => now(),
            'revised_by' => Auth::id(),
            'assigned_to' => $application->assigned_to ?: Auth::id(),
        ]);

        return response()->json([
            'message' => 'Permintaan revisi berhasil dikirim',
            'application' => $this->withRetiredAttachmentFieldsHidden(
                $application->fresh(),
                SuratTugasApplication::LETTER_TYPE,
            ),
        ]);
    }

    private function markRejected(Request $request, SuratTugasApplication $application, array $allowedStatuses)
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
            'status' => SuratTugasApplication::STATUS_REJECTED,
            'rejection_reason' => $request->reason,
            'revision_note' => null,
            'rejected_at' => now(),
            'rejected_by' => Auth::id(),
            'assigned_to' => $application->assigned_to ?: Auth::id(),
        ]);

        return response()->json([
            'message' => 'Pengajuan berhasil ditolak',
            'application' => $this->withRetiredAttachmentFieldsHidden(
                $application->fresh(),
                SuratTugasApplication::LETTER_TYPE,
            ),
        ]);
    }

    private function forMahasiswaResponse(SuratTugasApplication $application): SuratTugasApplication
    {
        // Non-persisted compatibility attribute: Surat Tugas has no public
        // generated-path column; the FE reads the private generated-preview
        // endpoint instead. Mirrors the canonical letters' detail shape.
        $application->setAttribute('generated_pdf_path', null);

        return $this->withSupportingDocumentMetadata(
            $application,
            SuratTugasApplication::LETTER_TYPE,
            $this->attachmentMetadataService,
            $this->retentionSummaryService,
        );
    }
}
