<?php

namespace App\Http\Controllers\Akademik;

use App\Http\Controllers\Concerns\AddsSupportingDocumentMetadata;
use App\Http\Controllers\Concerns\AuthorizesAcademicApplications;
use App\Http\Controllers\Controller;
use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Models\User;
use App\Services\AcademicRoutingService;
use App\Services\AcademicSignatoryService;
use App\Services\BeasiswaPreviewGenerationException;
use App\Services\BeasiswaPreviewGenerationService;
use App\Services\LetterAttachmentMetadataService;
use App\Services\LetterRetentionSummaryService;
use App\Services\LetterTaskCursorFeedService;
use App\Services\LetterTaskFeedService;
use App\Services\MahasiswaProfileDataService;
use App\Support\Workflow\LetterReviewStageClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AkademikDashboardController extends Controller
{
    use AuthorizesAcademicApplications;
    use AddsSupportingDocumentMetadata;

    private const DASHBOARD_TASK_LIMIT = 100;
    private const AKADEMIK_ROW_RELATIONS = [
        'user.studyProgram.department.faculty',
        'user.department.faculty',
        'mahasiswaProfile',
        'assignedTendik',
        'tendikApprover',
        'kaprodiApprover',
        'kadepApprover',
        'reviser',
        'rejector',
    ];

    public function __construct(
        private LetterTaskCursorFeedService $cursorFeedService,
        private LetterTaskFeedService $taskFeedService,
        private AcademicRoutingService $academicRoutingService,
        private LetterAttachmentMetadataService $attachmentMetadataService,
        private LetterRetentionSummaryService $retentionSummaryService
    )
    {
    }

    public function getRiwayatData()
    {
        $user = auth()->user();
        $tasksByType = [];

        foreach ($this->academicDashboardModels() as $modelClass) {
            $tasksByType[$modelClass] = $this->academicHistoryQuery($modelClass, $user)
                ->with(self::AKADEMIK_ROW_RELATIONS)
                ->orderBy('updated_at', 'desc')
                ->limit(self::DASHBOARD_TASK_LIMIT)
                ->get();
        }

        $taskRows = $this->taskFeedService->combinedAkademikRows(
            $tasksByType[ScholarshipApplication::class] ?? collect(),
            $tasksByType[SuratPengantarMagangApplication::class] ?? collect(),
            $tasksByType[SuratKeteranganAktifApplication::class] ?? collect(),
            $tasksByType[ProsesLuarNegeriApplication::class] ?? collect(),
            $tasksByType[SuratTugasApplication::class] ?? collect()
        );

        return response()->json([
            'tasks' => $taskRows,
            'meta' => [
                'displayed_tasks' => $taskRows->count(),
                'limit' => self::DASHBOARD_TASK_LIMIT,
            ],
        ]);
    }

    private function academicHistoryQuery(string $modelClass, User $user): Builder
    {
        $query = $modelClass::query();

        if ($this->academicRoutingService->isProdiApprover($user)) {
            return $this->academicRoutingService->applyProdiStageScope(
                $query->where(function (Builder $query) use ($modelClass) {
                    $query->whereIn('status', [
                        $modelClass::STATUS_APPROVED_KAPRODI,
                        $modelClass::STATUS_READY_FOR_STUDENT_REVIEW,
                        $modelClass::STATUS_COMPLETED,
                    ])->orWhere(function (Builder $query) use ($modelClass) {
                        $query->whereIn('status', [
                            $modelClass::STATUS_REVISION,
                            $modelClass::STATUS_REJECTED,
                        ])->whereNotNull('tendik_approved_at');
                    });
                }),
                $user
            );
        }

        if ($this->academicRoutingService->isDepartmentApprover($user)) {
            return $this->academicRoutingService->applyDepartmentStageScope(
                $query->where(function (Builder $query) use ($modelClass) {
                    $query->whereIn('status', [
                        $modelClass::STATUS_READY_FOR_STUDENT_REVIEW,
                        $modelClass::STATUS_COMPLETED,
                    ])->orWhere(function (Builder $query) use ($modelClass) {
                        $query->whereIn('status', [
                            $modelClass::STATUS_REVISION,
                            $modelClass::STATUS_REJECTED,
                        ])->whereNotNull('kaprodi_approved_at');
                    });
                }),
                $user
            );
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Get dashboard stats and task list for Kaprodi/Sekprodi/Kadep/Sekdep
     */
    public function getDashboardData(Request $request)
    {
        $user = auth()->user();
        $subRole = $user->sub_role; // kadep, sekdep, kaprodi, sekprodi

        if (in_array($subRole, ['kaprodi', 'sekprodi'], true)) {
            $targetStatus = ScholarshipApplication::STATUS_APPROVED_TENDIK;
            $applyAcademicScope = fn ($query) => $this->academicRoutingService->applyProdiStageScope($query, $user);
        } elseif (in_array($subRole, ['kadep', 'sekdep'], true)) {
            $targetStatus = ScholarshipApplication::STATUS_APPROVED_KAPRODI;
            $applyAcademicScope = fn ($query) => $this->academicRoutingService->applyDepartmentStageScope($query, $user);
        } else {
            $targetStatus = null;
            $applyAcademicScope = null;
        }

        if ($this->cursorFeedService->cursorModeRequested($request)) {
            $matchingTaskCount = $targetStatus && $applyAcademicScope
                ? $this->countAcademicDashboardTasks($targetStatus, $applyAcademicScope)
                : 0;
            $feed = $this->cursorFeedService->akademikDashboard($user, $request);
            $taskRows = $this->taskFeedService->orderedAkademikRows($feed['models']);
            $displayedTaskCount = $taskRows->count();

            return response()->json([
                'stats' => [
                    'total_incoming' => $matchingTaskCount,
                    'needs_verification' => $matchingTaskCount,
                    'finished_this_month' => $this->finishedThisMonthCount($user, $applyAcademicScope),
                ],
                'tasks' => $taskRows,
                'meta' => array_merge([
                    'displayed_tasks' => $displayedTaskCount,
                    'total_matching_tasks' => $matchingTaskCount,
                    'is_limited' => $matchingTaskCount > $displayedTaskCount,
                    'limit' => $feed['meta']['page_size'],
                    'per_type_limit' => null,
                    'limit_scope' => 'global_cursor_page',
                ], $feed['meta']),
            ]);
        }

        $tasks = collect();

        $magangTasks = collect();
        $aktifTasks = collect();
        $prosesLuarNegeriTasks = collect();
        $suratTugasTasks = collect();
        $matchingTaskCount = 0;

        if ($targetStatus && $applyAcademicScope) {
            $matchingTaskCount = $this->countAcademicDashboardTasks($targetStatus, $applyAcademicScope);

            $tasks = $this->academicDashboardQuery(ScholarshipApplication::class, $targetStatus, $applyAcademicScope, true)
                ->orderBy('created_at', 'desc')
                ->limit(self::DASHBOARD_TASK_LIMIT)
                ->get();

            $magangTasks = $this->academicDashboardQuery(SuratPengantarMagangApplication::class, $targetStatus, $applyAcademicScope, true)
                ->orderBy('created_at', 'desc')
                ->limit(self::DASHBOARD_TASK_LIMIT)
                ->get();

            $aktifTasks = $this->academicDashboardQuery(SuratKeteranganAktifApplication::class, $targetStatus, $applyAcademicScope, true)
                ->orderBy('created_at', 'desc')
                ->limit(self::DASHBOARD_TASK_LIMIT)
                ->get();

            $prosesLuarNegeriTasks = $this->academicDashboardQuery(ProsesLuarNegeriApplication::class, $targetStatus, $applyAcademicScope, true)
                ->orderBy('created_at', 'desc')
                ->limit(self::DASHBOARD_TASK_LIMIT)
                ->get();

            $suratTugasTasks = $this->academicDashboardQuery(SuratTugasApplication::class, $targetStatus, $applyAcademicScope, true)
                ->orderBy('created_at', 'desc')
                ->limit(self::DASHBOARD_TASK_LIMIT)
                ->get();
        }

        $taskRows = $this->taskFeedService->combinedAkademikRows($tasks, $magangTasks, $aktifTasks, $prosesLuarNegeriTasks, $suratTugasTasks);
        $displayedTaskCount = $taskRows->count();

        return response()->json([
            'stats' => [
                'total_incoming' => $matchingTaskCount,
                'needs_verification' => $matchingTaskCount,
                'finished_this_month' => $this->finishedThisMonthCount($user, $applyAcademicScope),
            ],
            'tasks' => $taskRows,
            'meta' => [
                'displayed_tasks' => $displayedTaskCount,
                'total_matching_tasks' => $matchingTaskCount,
                'is_limited' => $matchingTaskCount > $displayedTaskCount,
                'limit' => self::DASHBOARD_TASK_LIMIT,
                'per_type_limit' => self::DASHBOARD_TASK_LIMIT,
                'limit_scope' => 'per_letter_type',
            ],
        ]);
    }

    private function countAcademicDashboardTasks(string $targetStatus, callable $applyAcademicScope): int
    {
        return collect($this->academicDashboardModels())
            ->sum(fn (string $modelClass): int => $this->academicDashboardQuery($modelClass, $targetStatus, $applyAcademicScope)->count());
    }

    private function academicDashboardQuery(string $modelClass, string $targetStatus, callable $applyAcademicScope, bool $withRelations = false): Builder
    {
        $query = $modelClass::query()->where('status', $targetStatus);

        if ($withRelations) {
            $query->with($this->academicDashboardRelations());
        }

        return $applyAcademicScope($query);
    }

    private function academicDashboardModels(): array
    {
        return [
            ScholarshipApplication::class,
            SuratPengantarMagangApplication::class,
            SuratKeteranganAktifApplication::class,
            ProsesLuarNegeriApplication::class,
            SuratTugasApplication::class,
        ];
    }

    /**
     * How many letters THIS reviewer signed off this month.
     *
     * Previously this counted every ScholarshipApplication in the faculty whose
     * `updated_at` fell in the current month — no scoping to the reviewer's
     * study program or department, no coverage of the other four letter types,
     * and `updated_at` moves for reasons that have nothing to do with a decision.
     * Every Kaprodi and Kadep therefore saw the same number, mostly made up of
     * other people's work.
     *
     * Now it counts the reviewer's OWN stage-exit timestamp — `kaprodi_approved_at`
     * for a Prodi approver, `kadep_approved_at` for a Departemen one — across all
     * five letter types, narrowed by the same scope helper the queue uses. The
     * column comes from LetterReviewStageClock so this figure, the review-SLA
     * reminders and the analytics all agree on what "finished a stage" means.
     */
    private function finishedThisMonthCount(User $user, ?callable $applyAcademicScope): int
    {
        if (! $applyAcademicScope) {
            return 0;
        }

        $stage = $this->academicRoutingService->isDepartmentApprover($user)
            ? LetterReviewStageClock::STAGE_DEPARTEMEN
            : LetterReviewStageClock::STAGE_PRODI;
        $column = LetterReviewStageClock::exitAttributeForStage($stage);
        if (! $column) {
            return 0;
        }

        $start = now(config('app.timezone'))->startOfMonth();
        $end = now(config('app.timezone'))->endOfMonth();

        return collect($this->academicDashboardModels())->sum(
            fn (string $modelClass): int => $applyAcademicScope(
                $modelClass::query()->whereBetween($column, [$start, $end]),
            )->count(),
        );
    }

    private function academicDashboardRelations(): array
    {
        return ['user', 'mahasiswaProfile'];
    }

    /**
     * Get detailed application data
     */
    public function show(ScholarshipApplication $application, MahasiswaProfileDataService $profileDataService)
    {
        $this->authorizeAcademicDetail($application, $this->academicRoutingService);

        $application->load([
            'mahasiswaProfile.user',
            'mahasiswaProfile.keluarga',
            'user.studyProgram.department.faculty',
            'user.department.faculty',
        ]);

        $normalized = $profileDataService->forApplication($application);
        $application->setAttribute('generated_docx_path', null);

        return response()->json([
            'application' => $this->withSupportingDocumentMetadata(
                $application,
                ScholarshipApplication::LETTER_TYPE,
                $this->attachmentMetadataService,
                $this->retentionSummaryService,
            ),
            'profile_summary' => $profileDataService->profileSummaryForApplication($application),
            'student' => [
                'name' => $normalized['name'],
                'nim' => $normalized['nim'],
                'photo' => $application->mahasiswaProfile?->pas_foto_path ? '/api/storage/' . ltrim(str_replace('/storage/', '', $application->mahasiswaProfile->pas_foto_path), '/') : null,
                'prodi' => $normalized['program_studi_display'],
                'fakultas' => $normalized['fakultas_display'],
                'departemen' => $normalized['department_display'],
                'email' => $normalized['email'],
                'ipk' => $application->ipk,
                'phone' => $application->mahasiswaProfile?->no_hp ?? '-',
                'angkatan' => $normalized['angkatan'],
                'current_semester' => $normalized['current_semester'],
                'term' => 'Angkatan ' . ($normalized['angkatan'] ?? '-') . ' Semester ' . ($normalized['current_semester'] ?? '-'),
                'target' => $application->scholarship_name ?? 'Beasiswa',
                'submitted_at' => $application->submitted_at ? $application->submitted_at->format('d F Y, H.i') : $application->created_at->format('d F Y, H.i'),
            ],
            'docx_url' => null
        ]);
    }

    /**
     * Approve scholarship application
     */
    public function approve(
        ScholarshipApplication $application,
        AcademicSignatoryService $signatoryService,
        BeasiswaPreviewGenerationService $previewGenerationService
    )
    {
        $user = auth()->user();
        $subRole = $user->sub_role;
        $application->load(['mahasiswaProfile', 'user']);

        $guardResponse = $this->guardAcademicAction(
            $application,
            $this->academicRoutingService,
            ScholarshipApplication::STATUS_APPROVED_TENDIK,
            ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'Pengajuan tidak berada pada tahap persetujuan Kaprodi/Sekprodi.',
            'Pengajuan tidak berada pada tahap persetujuan Kadep/Sekdep.'
        );
        if ($guardResponse) {
            return $guardResponse;
        }

        // If approved by Kaprodi/Sekprodi, move to Kadep/Sekdep stage
        if (in_array($subRole, ['kaprodi', 'sekprodi'])) {
            $approvedAt = now();
            $letterDate = $application->tendik_approved_at
                ?? $application->submitted_at
                ?? $application->created_at
                ?? $approvedAt;

            try {
                $previewGenerationService->generateForPhase(
                    $application,
                    LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
                    [
                        'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
                        'kaprodi_approved_at' => $approvedAt,
                        'kaprodi_approved_by' => $user->id,
                        'tanggal_surat' => $letterDate,
                    ],
                    $user->id,
                );
            } catch (BeasiswaPreviewGenerationException $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Dokumen pratinjau persetujuan Prodi belum dapat dibuat. Silakan coba lagi.',
                ], 503);
            }

            try {
                $application = DB::transaction(function () use ($application, $approvedAt, $user) {
                    $lockedApplication = ScholarshipApplication::query()
                        ->whereKey($application->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    $actor = $user->fresh();
                    if (
                        !$actor
                        || !$this->academicRoutingService->isProdiApprover($actor)
                        || $lockedApplication->status !== ScholarshipApplication::STATUS_APPROVED_TENDIK
                        || !$this->academicRoutingService->canHandleProdiStage($actor, $lockedApplication)
                    ) {
                        throw new RuntimeException('Scholarship application is no longer approvable by Prodi.');
                    }

                    $lockedApplication->update([
                        'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
                        'kaprodi_approved_at' => $approvedAt,
                        'kaprodi_approved_by' => $actor->id,
                    ]);

                    return $lockedApplication->fresh(['mahasiswaProfile', 'user']);
                });
            } catch (RuntimeException $exception) {
                return response()->json([
                    'message' => 'Pengajuan sudah berubah dan tidak dapat disetujui ulang oleh Prodi.',
                ], 409);
            }

            // Kadep/Sekdep are notified by the shared C7N1 letter observer from the
            // status transition above (scoped department routing + unified email) —
            // no manual dispatch here.
            return response()->json(['message' => 'Pendaftaran disetujui dan diteruskan ke Kadep/Sekdep']);
        }

        // Pre-flight signatory check: if the official Kadep for the student's department
        // is missing/inactive, the document cannot be generated. Surface this as an
        // actionable 422 instead of letting the transaction fail with a generic 500.
        // Governance preserved: we never fall back to Sekdep as the visible signer.
        $officialKadep = $signatoryService->officialKadepForApplication($application);
        if (!$officialKadep) {
            return response()->json([
                'message' => 'Konfigurasi Ketua Departemen aktif belum tersedia untuk departemen mahasiswa. Mohon hubungi administrator untuk menetapkan Ketua Departemen aktif sebelum dokumen final dapat dibuat.',
                'reason' => 'missing_official_kadep',
            ], 422);
        }

        $approvedAt = now();
        $letterDate = $application->tendik_approved_at
            ?? $application->submitted_at
            ?? $application->created_at
            ?? $approvedAt;

        try {
            $previewGenerationService->generateForPhase(
                $application,
                LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
                [
                    'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                    'kadep_approved_at' => $approvedAt,
                    'kadep_approved_by' => $user->id,
                    'official_kadep' => $officialKadep,
                    'tanggal_surat' => $letterDate,
                ],
                $user->id,
            );
        } catch (BeasiswaPreviewGenerationException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dokumen pratinjau review mahasiswa belum dapat dibuat. Silakan coba lagi.',
            ], 503);
        }

        try {
            $approvedApplication = DB::transaction(function () use ($application, $user, $approvedAt) {
                $lockedApplication = ScholarshipApplication::whereKey($application->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $actor = $user->fresh();
                if (
                    !$actor
                    || !$this->academicRoutingService->isDepartmentApprover($actor)
                    || $lockedApplication->status !== ScholarshipApplication::STATUS_APPROVED_KAPRODI
                    || !$this->academicRoutingService->canHandleDepartmentStage($actor, $lockedApplication)
                ) {
                    throw new RuntimeException('Scholarship application is no longer approvable by Department.');
                }

                $lockedApplication->update([
                    'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                    'kadep_approved_at' => $approvedAt,
                    'kadep_approved_by' => $actor->id,
                ]);

                return $lockedApplication->fresh(['mahasiswaProfile', 'user']);
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => 'Pengajuan sudah berubah dan tidak dapat disetujui ulang oleh Departemen.',
            ], 409);
        }

        // The applicant is notified (in-app + email) by the C7N1 letter observer
        // from the READY_FOR_STUDENT_REVIEW transition above.
        return response()->json(['message' => 'Pendaftaran berhasil disetujui dan menunggu review mahasiswa']);
    }

    /**
     * Reject scholarship application
     */
    public function reject(ScholarshipApplication $application, Request $request)
    {
        $guardResponse = $this->guardAcademicAction(
            $application,
            $this->academicRoutingService,
            ScholarshipApplication::STATUS_APPROVED_TENDIK,
            ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'Pengajuan tidak dapat ditolak pada tahap ini.',
            'Pengajuan tidak dapat ditolak pada tahap ini.'
        );
        if ($guardResponse) {
            return $guardResponse;
        }

        $updateData = ['status' => ScholarshipApplication::STATUS_REJECTED];

        if ($request->filled('reason')) {
            $updateData['rejection_reason'] = $request->input('reason');
        }

        // The applicant is notified (in-app + email) by the C7N1 letter observer
        // from the REJECTED transition above.
        $application->update($updateData);
        return response()->json(['message' => 'Pendaftaran berhasil ditolak']);
    }

    /**
     * Request revision
     */
    public function revise(ScholarshipApplication $application, Request $request)
    {
        $guardResponse = $this->guardAcademicAction(
            $application,
            $this->academicRoutingService,
            ScholarshipApplication::STATUS_APPROVED_TENDIK,
            ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'Pengajuan tidak dapat direvisi pada tahap ini.',
            'Pengajuan tidak dapat direvisi pada tahap ini.'
        );
        if ($guardResponse) {
            return $guardResponse;
        }

        $updateData = ['status' => ScholarshipApplication::STATUS_REVISION];

        if ($request->filled('note')) {
            $updateData['revision_note'] = $request->input('note');
        }

        // The applicant is notified (in-app + email) by the C7N1 letter observer
        // from the REVISION transition above.
        $application->update($updateData);
        return response()->json(['message' => 'Permintaan revisi berhasil dikirim']);
    }
}
