<?php

namespace App\Http\Controllers\Tendik;

use App\Http\Controllers\Concerns\AddsSupportingDocumentMetadata;
use App\Http\Controllers\Controller;
use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Models\User;
use App\Services\BeasiswaPreviewGenerationException;
use App\Services\BeasiswaPreviewGenerationService;
use App\Services\LetterAssignmentService;
use App\Services\LetterAttachmentMetadataService;
use App\Services\LetterRetentionSummaryService;
use App\Services\LetterTaskCursorFeedService;
use App\Services\LetterTaskFeedService;
use App\Services\MahasiswaProfileDataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class TendikDashboardController extends Controller
{
    use AddsSupportingDocumentMetadata;

    // Eager-loads that the feed-row enrichment depends on.
    private const TENDIK_ROW_RELATIONS = [
        'mahasiswaProfile.user',
        'user',
        'assignedTendik',
        'tendikApprover',
        'reviser',
        'rejector',
    ];

    public function __construct(
        private LetterAssignmentService $assignmentService,
        private LetterTaskCursorFeedService $cursorFeedService,
        private LetterTaskFeedService $taskFeedService,
        private LetterAttachmentMetadataService $attachmentMetadataService,
        private LetterRetentionSummaryService $retentionSummaryService
    )
    {
    }

    /**
     * Get dashboard data for the authenticated Tendik.
     *
     * scope=mine (default) preserves the per-assignment visibility model: rows
     * are visible if assigned_to=me, plus unassigned-Submitted for Beasiswa.
     * scope=team is reserved for Persuratan team helpers and returns ALL admin
     * letters in the relevant status bucket regardless of assignment.
     */
    public function getDashboardData(Request $request)
    {
        $user = Auth::user();
        $scope = $this->resolveScope($request);

        if ($this->cursorFeedService->cursorModeRequested($request)) {
            $feed = $this->cursorFeedService->tendikDashboard($user, $request);

            return response()->json([
                'stats' => $this->dashboardStatsFor($user),
                'tasks' => $this->taskFeedService->orderedTendikRows($feed['models']),
                'meta' => $feed['meta'],
            ]);
        }

        $stats = ['total_incoming' => 0, 'needs_verification' => 0, 'finished_this_month' => 0];
        $tasksByType = [];

        foreach ($this->letterTypeModelMap() as $letterType => $modelClass) {
            if (!$this->canSeeForScope($user, $letterType, $scope)) {
                $tasksByType[$letterType] = collect();
                continue;
            }

            $unassignedStatuses = ($scope === 'mine' && $letterType === ScholarshipApplication::LETTER_TYPE)
                ? [$modelClass::STATUS_SUBMITTED]
                : null;

            $baseQuery = $this->applyScope(
                $modelClass::whereIn('status', [$modelClass::STATUS_SUBMITTED]),
                $user,
                $letterType,
                $scope,
                $unassignedStatuses
            );

            $stats['total_incoming'] += (clone $baseQuery)->count();
            $stats['needs_verification'] += (clone $baseQuery)
                ->where('status', $modelClass::STATUS_SUBMITTED)
                ->count();

            $finishedQuery = $this->applyScope(
                $modelClass::whereIn('status', $this->finishedStatusesFor($modelClass))
                    ->where('updated_at', '>=', now()->startOfMonth()),
                $user,
                $letterType,
                $scope
            );
            $stats['finished_this_month'] += $finishedQuery->count();

            $tasksByType[$letterType] = (clone $baseQuery)
                ->with(self::TENDIK_ROW_RELATIONS)
                ->orderBy('submitted_at', 'desc')
                ->limit(100)
                ->get();
        }

        return response()->json([
            'stats' => $stats,
            'tasks' => $this->taskFeedService->combinedTendikRows(
                $tasksByType[ScholarshipApplication::LETTER_TYPE] ?? collect(),
                $tasksByType[SuratPengantarMagangApplication::LETTER_TYPE] ?? collect(),
                $tasksByType[SuratKeteranganAktifApplication::LETTER_TYPE] ?? collect(),
                $tasksByType[ProsesLuarNegeriApplication::LETTER_TYPE] ?? collect(),
                $tasksByType[SuratTugasApplication::LETTER_TYPE] ?? collect()
            ),
            'scope' => $scope,
        ]);
    }

    /**
     * Get detailed application data
     */
    public function show(ScholarshipApplication $application, MahasiswaProfileDataService $profileDataService)
    {
        $this->authorizeTendikDetail(ScholarshipApplication::LETTER_TYPE);

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
     * Approve scholarship application (Tendik → forward to Kaprodi)
     */
    public function approve(
        ScholarshipApplication $application,
        Request $request,
        BeasiswaPreviewGenerationService $previewGenerationService
    )
    {
        $this->authorizeTendikAction(ScholarshipApplication::LETTER_TYPE);

        $validator = Validator::make($request->all(), [
            'nomor_surat' => 'required|string|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($application->status !== ScholarshipApplication::STATUS_SUBMITTED) {
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
                    'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
                    'nomor_surat' => $nomorSurat,
                    'tendik_approved_at' => $approvedAt,
                    'tendik_approved_by' => $actorId,
                    'tanggal_surat' => $approvedAt,
                ],
                $actorId,
            );
        } catch (BeasiswaPreviewGenerationException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dokumen pratinjau verifikasi belum dapat dibuat. Silakan coba lagi.',
            ], 503);
        }

        try {
            $application = DB::transaction(function () use ($application, $approvedAt, $actorId, $nomorSurat) {
                $lockedApplication = ScholarshipApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $actor = Auth::user()?->fresh();
                if (
                    !$actor
                    || !$this->assignmentService->canHandleAny($actor, ScholarshipApplication::LETTER_TYPE)
                    || $lockedApplication->status !== ScholarshipApplication::STATUS_SUBMITTED
                ) {
                    throw new RuntimeException('Scholarship application is no longer approvable by Tendik.');
                }

                $lockedApplication->update([
                    'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
                    'tendik_approved_at' => $approvedAt,
                    'tendik_approved_by' => $actorId,
                    'assigned_to' => $lockedApplication->assigned_to ?: $actorId,
                    'nomor_surat' => $nomorSurat,
                ]);

                return $lockedApplication->fresh();
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => 'Pengajuan sudah berubah dan tidak dapat diverifikasi ulang.',
            ], 409);
        }

        // Kaprodi/Sekprodi are notified by the shared C7N1 letter observer from the
        // APPROVED_TENDIK transition above (scoped prodi routing + unified email) —
        // no manual dispatch here.
        return response()->json(['message' => 'Pendaftaran berhasil diverifikasi dan diteruskan ke Kaprodi/Sekprodi']);
    }

    /**
     * Reject scholarship application
     */
    public function reject(ScholarshipApplication $application, Request $request)
    {
        $this->authorizeTendikAction(ScholarshipApplication::LETTER_TYPE);

        $updateData = [
            'status' => ScholarshipApplication::STATUS_REJECTED,
            'rejected_at' => now(),
            'rejected_by' => Auth::id(),
            'assigned_to' => $application->assigned_to ?: Auth::id(),
        ];

        if ($request->filled('reason')) {
            $updateData['rejection_reason'] = $request->input('reason');
        }

        // The applicant is notified (in-app + email) by the C7N1 letter observer
        // from the REJECTED transition above.
        $application->update($updateData);
        return response()->json(['message' => 'Pendaftaran berhasil ditolak']);
    }

    /**
     * Request revision for scholarship application
     */
    public function revise(ScholarshipApplication $application, Request $request)
    {
        $this->authorizeTendikAction(ScholarshipApplication::LETTER_TYPE);

        $updateData = [
            'status' => ScholarshipApplication::STATUS_REVISION,
            'revised_at' => now(),
            'revised_by' => Auth::id(),
            'assigned_to' => $application->assigned_to ?: Auth::id(),
        ];

        if ($request->filled('note')) {
            $updateData['revision_note'] = $request->input('note');
        }

        // The applicant is notified (in-app + email) by the C7N1 letter observer
        // from the REVISION transition above.
        $application->update($updateData);
        return response()->json(['message' => 'Permintaan revisi berhasil dikirim']);
    }

    public function getRiwayatData(Request $request)
    {
        $user = Auth::user();
        $scope = $this->resolveScope($request);

        if ($this->cursorFeedService->cursorModeRequested($request)) {
            $feed = $this->cursorFeedService->tendikRiwayat($user, $request);

            return response()->json([
                'tasks' => $this->taskFeedService->orderedTendikRows($feed['models']),
                'meta' => $feed['meta'],
            ]);
        }

        $historicalStatuses = [
            ScholarshipApplication::STATUS_APPROVED_TENDIK,
            ScholarshipApplication::STATUS_REVISION,
            ScholarshipApplication::STATUS_REJECTED,
            ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            ScholarshipApplication::STATUS_COMPLETED,
        ];

        $tasksByType = [];
        foreach ($this->letterTypeModelMap() as $letterType => $modelClass) {
            if (!$this->canSeeForScope($user, $letterType, $scope)) {
                $tasksByType[$letterType] = collect();
                continue;
            }

            $tasksByType[$letterType] = $this->applyScope(
                $modelClass::whereIn('status', $historicalStatuses),
                $user,
                $letterType,
                $scope
            )
                ->with(self::TENDIK_ROW_RELATIONS)
                ->orderBy('submitted_at', 'desc')
                ->limit(100)
                ->get();
        }

        return response()->json([
            'tasks' => $this->taskFeedService->combinedTendikRows(
                $tasksByType[ScholarshipApplication::LETTER_TYPE] ?? collect(),
                $tasksByType[SuratPengantarMagangApplication::LETTER_TYPE] ?? collect(),
                $tasksByType[SuratKeteranganAktifApplication::LETTER_TYPE] ?? collect(),
                $tasksByType[ProsesLuarNegeriApplication::LETTER_TYPE] ?? collect(),
                $tasksByType[SuratTugasApplication::LETTER_TYPE] ?? collect()
            ),
            'scope' => $scope,
        ]);
    }

    private function dashboardStatsFor(User $user): array
    {
        $baseQuery = $this->assignmentService->applyFeedVisibility(
            ScholarshipApplication::whereIn('status', [
                ScholarshipApplication::STATUS_SUBMITTED,
            ]),
            $user,
            ScholarshipApplication::LETTER_TYPE,
            [ScholarshipApplication::STATUS_SUBMITTED]
        );

        $stats = [
            'total_incoming' => (clone $baseQuery)->count(),
            'needs_verification' => (clone $baseQuery)->where('status', ScholarshipApplication::STATUS_SUBMITTED)->count(),
            'finished_this_month' => $this->assignmentService->applyFeedVisibility(
                ScholarshipApplication::whereIn('status', [
                    ScholarshipApplication::STATUS_APPROVED_TENDIK,
                    ScholarshipApplication::STATUS_APPROVED_KAPRODI,
                    ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                    ScholarshipApplication::STATUS_COMPLETED,
                ])->where('updated_at', '>=', now()->startOfMonth()),
                $user,
                ScholarshipApplication::LETTER_TYPE
            )->count(),
        ];

        foreach ([
            SuratPengantarMagangApplication::LETTER_TYPE => SuratPengantarMagangApplication::class,
            SuratKeteranganAktifApplication::LETTER_TYPE => SuratKeteranganAktifApplication::class,
            ProsesLuarNegeriApplication::LETTER_TYPE => ProsesLuarNegeriApplication::class,
            SuratTugasApplication::LETTER_TYPE => SuratTugasApplication::class,
        ] as $letterType => $modelClass) {
            $activeQuery = $this->assignmentService->applyFeedVisibility(
                $modelClass::whereIn('status', [
                    $modelClass::STATUS_SUBMITTED,
                ]),
                $user,
                $letterType
            );

            $stats['total_incoming'] += (clone $activeQuery)->count();
            $stats['needs_verification'] += (clone $activeQuery)
                ->where('status', $modelClass::STATUS_SUBMITTED)
                ->count();

            $finishedQuery = $this->assignmentService->applyFeedVisibility(
                $modelClass::where('status', $modelClass::STATUS_APPROVED_TENDIK),
                $user,
                $letterType
            );

            $stats['finished_this_month'] += $finishedQuery
                ->where('updated_at', '>=', now()->startOfMonth())
                ->count();
        }

        return $stats;
    }

    /**
     * Resolve the requested scope; 422 if the param is present but invalid.
     */
    private function resolveScope(Request $request): string
    {
        $scope = $request->query('scope');
        if ($scope === null) {
            return 'mine';
        }
        $request->validate(['scope' => 'in:mine,team']);
        return $scope;
    }

    /**
     * @return array<string, class-string>
     */
    private function letterTypeModelMap(): array
    {
        return [
            ScholarshipApplication::LETTER_TYPE => ScholarshipApplication::class,
            SuratPengantarMagangApplication::LETTER_TYPE => SuratPengantarMagangApplication::class,
            SuratKeteranganAktifApplication::LETTER_TYPE => SuratKeteranganAktifApplication::class,
            ProsesLuarNegeriApplication::LETTER_TYPE => ProsesLuarNegeriApplication::class,
            SuratTugasApplication::LETTER_TYPE => SuratTugasApplication::class,
        ];
    }

    private function canSeeForScope(User $user, string $letterType, string $scope): bool
    {
        // Both mine and team scopes are gated by assigned_tasks. Team scope
        // differs from mine only in whether the assigned_to filter is applied
        // (see applyScope below); a Tendik never sees letter types absent from
        // their assigned_tasks, regardless of scope.
        return $this->assignmentService->canHandle($user, $letterType);
    }

    private function applyScope(Builder $query, User $user, string $letterType, string $scope, ?array $unassignedStatuses = null): Builder
    {
        if ($scope === 'team') {
            return $this->assignmentService->applyTeamFeedVisibility($query, $user, $letterType);
        }
        return $this->assignmentService->applyFeedVisibility($query, $user, $letterType, $unassignedStatuses);
    }

    /**
     * @return string[]
     */
    private function finishedStatusesFor(string $modelClass): array
    {
        if ($modelClass === ScholarshipApplication::class) {
            return [
                ScholarshipApplication::STATUS_APPROVED_TENDIK,
                ScholarshipApplication::STATUS_APPROVED_KAPRODI,
                ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                ScholarshipApplication::STATUS_COMPLETED,
            ];
        }

        // Magang/SKA/PLN: "finished this month" semantics preserved from prior
        // controller (only APPROVED_TENDIK counted).
        return [$modelClass::STATUS_APPROVED_TENDIK];
    }

    /**
     * Hard-gate Tendik action endpoints. A Persuratan Tendik may act on any
     * admin-letter type as part of the team; non-Persuratan Tendik (Sarpras /
     * Kepala Lab / Laboran) and non-Tendik roles get 403.
     */
    private function authorizeTendikAction(string $letterType): void
    {
        $user = Auth::user();
        if (!$this->assignmentService->canHandleAny($user, $letterType)) {
            abort(403, 'Tidak berwenang memproses pengajuan ini.');
        }
    }

    private function authorizeTendikDetail(string $letterType): void
    {
        $user = Auth::user();
        if (!$this->assignmentService->canHandleAny($user, $letterType)) {
            abort(403, 'Tidak berwenang melihat detail pengajuan ini.');
        }
    }
}
