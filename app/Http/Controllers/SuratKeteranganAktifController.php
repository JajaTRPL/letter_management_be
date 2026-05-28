<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesAcademicApplications;
use App\Models\LetterDocumentArtifact;
use App\Models\SuratKeteranganAktifApplication;
use App\Services\AcademicRoutingService;
use App\Services\LetterAssignmentService;
use App\Services\LetterDocumentAccessService;
use App\Services\LetterDocumentArtifactService;
use App\Services\MahasiswaProfileDataService;
use App\Services\SuratKeteranganAktifPreviewGenerationException;
use App\Services\SuratKeteranganAktifPreviewGenerationService;
use App\Services\SuratKeteranganAktifService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class SuratKeteranganAktifController extends Controller
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
        $applications = SuratKeteranganAktifApplication::where('user_id', Auth::id())
            ->where('status', '!=', SuratKeteranganAktifApplication::STATUS_DRAFT)
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

        $application = SuratKeteranganAktifApplication::where('user_id', $user->id)
            ->whereIn('status', [
                SuratKeteranganAktifApplication::STATUS_DRAFT,
                SuratKeteranganAktifApplication::STATUS_REVISION,
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
            'application' => $application,
        ]);
    }

    public function saveDraft(Request $request)
    {
        $application = $this->getOrCreateDraftApplication();

        $validator = Validator::make($request->all(), $this->applicationRules());
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $application->update($request->only([
            'tempat_lahir',
            'tanggal_lahir',
            'jenis_kelamin',
            'keperluan',
            'nama_orang_tua_wali',
            'pekerjaan_orang_tua_wali',
            'nip_orang_tua_wali',
            'pangkat_gol_orang_tua_wali',
            'instansi_orang_tua_wali',
        ]));

        return response()->json([
            'message' => 'Draft Surat Keterangan Aktif berhasil disimpan',
            'application' => $application->fresh('mahasiswaProfile'),
        ]);
    }

    public function submitApplication(
        SuratKeteranganAktifService $service,
        SuratKeteranganAktifPreviewGenerationService $previewGenerationService,
    )
    {
        $application = SuratKeteranganAktifApplication::where('user_id', Auth::id())
            ->whereIn('status', [
                SuratKeteranganAktifApplication::STATUS_DRAFT,
                SuratKeteranganAktifApplication::STATUS_REVISION,
            ])
            ->latest()
            ->firstOrFail();

        $validator = Validator::make($application->toArray(), $this->submissionRules());
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
                    'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
                    'submitted_at' => $submittedAt,
                    'tanggal_surat' => $submittedAt,
                ],
                $actorId,
            );
        } catch (SuratKeteranganAktifPreviewGenerationException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dokumen pratinjau pengajuan belum dapat dibuat. Silakan coba lagi.',
            ], 503);
        }

        try {
            [$application, $assignedTendik] = DB::transaction(function () use ($application, $service, $submittedAt) {
                $lockedApplication = SuratKeteranganAktifApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $lockedApplication->user_id !== (int) Auth::id()
                    || !in_array($lockedApplication->status, [
                        SuratKeteranganAktifApplication::STATUS_DRAFT,
                        SuratKeteranganAktifApplication::STATUS_REVISION,
                    ], true)
                ) {
                    throw new RuntimeException('SKA application is no longer submittable.');
                }

                $lockedApplication->update([
                    'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
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
            'message' => 'Pengajuan Surat Keterangan Aktif berhasil dikirim',
            'application' => $application->fresh('mahasiswaProfile'),
            'assigned_to' => $assignedTendik?->name,
        ]);
    }

    public function showForMahasiswa(SuratKeteranganAktifApplication $application)
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

    public function showForReviewer(SuratKeteranganAktifApplication $application)
    {
        $this->authorizeTendikDetailIfApplicable(SuratKeteranganAktifApplication::LETTER_TYPE);
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
        SuratKeteranganAktifApplication $application,
        SuratKeteranganAktifPreviewGenerationService $previewGenerationService,
    )
    {
        $this->authorizeTendikAction(SuratKeteranganAktifApplication::LETTER_TYPE);

        $validator = Validator::make($request->all(), [
            'nomor_surat' => 'required|string|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($application->status !== SuratKeteranganAktifApplication::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Pengajuan tidak berada pada tahap verifikasi Tendik.'], 422);
        }

        $approvedAt = now();
        $actorId = Auth::id();
        $nomorSurat = $request->input('nomor_surat');

        try {
            $previewGenerationService->generateForPhase(
                $application,
                LetterDocumentArtifact::PHASE_PRODI_REVIEW,
                [
                    'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
                    'nomor_surat' => $nomorSurat,
                    'tendik_approved_at' => $approvedAt,
                    'tendik_approved_by' => $actorId,
                    'tanggal_surat' => $approvedAt,
                ],
                $actorId,
            );
        } catch (SuratKeteranganAktifPreviewGenerationException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dokumen pratinjau verifikasi belum dapat dibuat. Silakan coba lagi.',
            ], 503);
        }

        try {
            $application = DB::transaction(function () use ($application, $approvedAt, $actorId, $nomorSurat) {
                $lockedApplication = SuratKeteranganAktifApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $actor = Auth::user()?->fresh();
                if (
                    !$actor
                    || !$this->assignmentService->canHandleAny($actor, SuratKeteranganAktifApplication::LETTER_TYPE)
                    || $lockedApplication->status !== SuratKeteranganAktifApplication::STATUS_SUBMITTED
                ) {
                    throw new RuntimeException('SKA application is no longer approvable by Tendik.');
                }

                $lockedApplication->update([
                    'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
                    'nomor_surat' => $nomorSurat,
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

    public function reviseByTendik(Request $request, SuratKeteranganAktifApplication $application)
    {
        $this->authorizeTendikAction(SuratKeteranganAktifApplication::LETTER_TYPE);

        return $this->markRevision($request, $application, [
            SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);
    }

    public function rejectByTendik(Request $request, SuratKeteranganAktifApplication $application)
    {
        $this->authorizeTendikAction(SuratKeteranganAktifApplication::LETTER_TYPE);

        return $this->markRejected($request, $application, [
            SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);
    }

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

    private function authorizeAcademicDetailIfApplicable(SuratKeteranganAktifApplication $application): void
    {
        if (Auth::user()?->role !== 'akademik') {
            return;
        }

        $this->authorizeAcademicDetail($application, $this->academicRoutingService);
    }

    public function approveByAkademik(
        SuratKeteranganAktifApplication $application,
        SuratKeteranganAktifPreviewGenerationService $previewGenerationService,
    )
    {
        $subRole = Auth::user()->sub_role;
        $guardResponse = $this->guardAcademicAction(
            $application,
            $this->academicRoutingService,
            SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            'Pengajuan tidak berada pada tahap persetujuan Kaprodi/Sekprodi.',
            'Pengajuan tidak berada pada tahap persetujuan Kadep/Sekdep.'
        );
        if ($guardResponse) {
            return $guardResponse;
        }

        if (in_array($subRole, ['kaprodi', 'sekprodi'], true)) {
            if ($application->status !== SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK) {
                return response()->json(['message' => 'Pengajuan tidak berada pada tahap persetujuan Kaprodi/Sekprodi.'], 422);
            }

            $approvedAt = now();
            $actorId = Auth::id();

            try {
                $previewGenerationService->generateForPhase(
                    $application,
                    LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
                    [
                        'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
                        'kaprodi_approved_at' => $approvedAt,
                        'kaprodi_approved_by' => $actorId,
                    ],
                    $actorId,
                );
            } catch (SuratKeteranganAktifPreviewGenerationException $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Dokumen pratinjau persetujuan Prodi belum dapat dibuat. Silakan coba lagi.',
                ], 503);
            }

            try {
                $application = DB::transaction(function () use ($application, $approvedAt, $actorId) {
                    $lockedApplication = SuratKeteranganAktifApplication::query()
                        ->whereKey($application->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    $actor = Auth::user()?->fresh();
                    if (
                        !$actor
                        || !$this->academicRoutingService->canHandleProdiStage($actor, $lockedApplication)
                        || $lockedApplication->status !== SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK
                    ) {
                        throw new RuntimeException('SKA application is no longer approvable by Prodi.');
                    }

                    $lockedApplication->update([
                        'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
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
            if ($application->status !== SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI) {
                return response()->json(['message' => 'Pengajuan tidak berada pada tahap persetujuan Kadep/Sekdep.'], 422);
            }

            $approvedAt = now();
            $actorId = Auth::id();

            try {
                $previewGenerationService->generateForPhase(
                    $application,
                    LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
                    [
                        'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                        'kadep_approved_at' => $approvedAt,
                        'kadep_approved_by' => $actorId,
                    ],
                    $actorId,
                );
            } catch (SuratKeteranganAktifPreviewGenerationException $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Dokumen pratinjau persetujuan Kadep belum dapat dibuat. Silakan coba lagi.',
                ], 503);
            }

            try {
                $application = DB::transaction(function () use ($application, $approvedAt, $actorId) {
                    $lockedApplication = SuratKeteranganAktifApplication::query()
                        ->whereKey($application->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    $actor = Auth::user()?->fresh();
                    if (
                        !$actor
                        || !$this->academicRoutingService->canHandleDepartmentStage($actor, $lockedApplication)
                        || $lockedApplication->status !== SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI
                    ) {
                        throw new RuntimeException('SKA application is no longer approvable by Kadep/Sekdep.');
                    }

                    $lockedApplication->update([
                        'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
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
        SuratKeteranganAktifApplication $application,
        LetterDocumentArtifactService $artifactService
    )
    {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        if ($application->status === SuratKeteranganAktifApplication::STATUS_COMPLETED) {
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
                $lockedApplication = SuratKeteranganAktifApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $lockedApplication->user_id !== (int) Auth::id()
                    || $lockedApplication->status !== SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW
                ) {
                    throw new RuntimeException('SKA application is no longer completable.');
                }

                $lockedApplication->update([
                    'status' => SuratKeteranganAktifApplication::STATUS_COMPLETED,
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
            'message' => 'Pengajuan Surat Keterangan Aktif telah diselesaikan.',
            'application' => $this->forMahasiswaResponse($application),
        ]);
    }

    private function completionArtifactError(
        SuratKeteranganAktifApplication $application,
        LetterDocumentArtifactService $artifactService
    ): ?JsonResponse {
        $artifact = $artifactService->latestArtifact(
            SuratKeteranganAktifApplication::LETTER_TYPE,
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
                'letter-document-artifacts/' . SuratKeteranganAktifApplication::LETTER_TYPE . '/',
            )
            && str_ends_with(strtolower($path), '.pdf');
    }

    public function reviseByAkademik(Request $request, SuratKeteranganAktifApplication $application)
    {
        $guardResponse = $this->guardAcademicAction(
            $application,
            $this->academicRoutingService,
            SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
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

    public function rejectByAkademik(Request $request, SuratKeteranganAktifApplication $application)
    {
        $guardResponse = $this->guardAcademicAction(
            $application,
            $this->academicRoutingService,
            SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
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

    private function getOrCreateDraftApplication(): SuratKeteranganAktifApplication
    {
        $user = Auth::user();
        $profile = $user->mahasiswaProfile ?? $user->mahasiswaProfile()->create([]);

        $editableApplication = SuratKeteranganAktifApplication::where('user_id', $user->id)
            ->whereIn('status', [
                SuratKeteranganAktifApplication::STATUS_DRAFT,
                SuratKeteranganAktifApplication::STATUS_REVISION,
            ])
            ->latest()
            ->first();

        if ($editableApplication) {
            return $editableApplication;
        }

        return SuratKeteranganAktifApplication::firstOrCreate(
            [
                'user_id' => $user->id,
                'status' => SuratKeteranganAktifApplication::STATUS_DRAFT,
            ],
            [
                'mahasiswa_profile_id' => $profile->id,
            ]
        );
    }

    private function applicationRules(): array
    {
        return [
            'tempat_lahir' => 'required|string|max:255',
            'tanggal_lahir' => 'required|date',
            'jenis_kelamin' => 'required|in:Laki-laki,Perempuan',
            'keperluan' => 'required|string|max:2000',
            'nama_orang_tua_wali' => 'required|string|max:255',
            'pekerjaan_orang_tua_wali' => 'required|string|max:255',
            'nip_orang_tua_wali' => 'nullable|string|max:255',
            'pangkat_gol_orang_tua_wali' => 'nullable|string|max:255',
            'instansi_orang_tua_wali' => 'nullable|string|max:255',
        ];
    }

    private function submissionRules(): array
    {
        return $this->applicationRules();
    }

    private function markRevision(Request $request, SuratKeteranganAktifApplication $application, array $allowedStatuses)
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
            'status' => SuratKeteranganAktifApplication::STATUS_REVISION,
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

    private function markRejected(Request $request, SuratKeteranganAktifApplication $application, array $allowedStatuses)
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
            'status' => SuratKeteranganAktifApplication::STATUS_REJECTED,
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

    private function forMahasiswaResponse(SuratKeteranganAktifApplication $application): SuratKeteranganAktifApplication
    {
        $application->setAttribute('generated_pdf_path', null);

        return $application;
    }
}
