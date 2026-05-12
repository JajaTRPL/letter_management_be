<?php

namespace App\Http\Controllers\Akademik;

use App\Http\Controllers\Controller;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use App\Notifications\ScholarshipStatusNotification;
use App\Enums\UserStatus;
use App\Services\AcademicRoutingService;
use App\Services\LetterTaskCursorFeedService;
use App\Services\LetterTaskFeedService;
use App\Services\ScholarshipAutomationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

class AkademikDashboardController extends Controller
{
    private const DASHBOARD_TASK_LIMIT = 100;

    public function __construct(
        private LetterTaskCursorFeedService $cursorFeedService,
        private LetterTaskFeedService $taskFeedService,
        private AcademicRoutingService $academicRoutingService
    )
    {
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
                    'finished_this_month' => $this->finishedThisMonthCount(),
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
        }

        $taskRows = $this->taskFeedService->combinedAkademikRows($tasks, $magangTasks, $aktifTasks, $prosesLuarNegeriTasks);
        $displayedTaskCount = $taskRows->count();

        return response()->json([
            'stats' => [
                'total_incoming' => $matchingTaskCount,
                'needs_verification' => $matchingTaskCount,
                'finished_this_month' => $this->finishedThisMonthCount(),
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
        ];
    }

    private function finishedThisMonthCount(): int
    {
        return ScholarshipApplication::whereIn('status', [
            ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            ScholarshipApplication::STATUS_COMPLETED,
        ])
            ->whereMonth('updated_at', now()->month)
            ->count();
    }

    private function academicDashboardRelations(): array
    {
        return ['user', 'mahasiswaProfile'];
    }

    /**
     * Get detailed application data
     */
    public function show(ScholarshipApplication $application)
    {
        $application->load(['mahasiswaProfile.user', 'mahasiswaProfile.keluarga', 'user']);
        
        return response()->json([
            'application' => $application,
            'student' => [
                'name' => $application->mahasiswaProfile?->nama_lengkap ?? $application->user->name,
                'nim' => $application->mahasiswaProfile?->nim,
                'photo' => $application->mahasiswaProfile?->pas_foto_path ? '/api/storage/' . ltrim(str_replace('/storage/', '', $application->mahasiswaProfile->pas_foto_path), '/') : null,
                'prodi' => $application->mahasiswaProfile?->program_studi,
                'email' => $application->user->email,
                'ipk' => $application->ipk,
                'phone' => $application->mahasiswaProfile?->no_hp ?? '-',
                'term' => 'Angkatan ' . ($application->mahasiswaProfile?->tahun_masuk ?? '2023') . ' Semester ' . ($application->current_semester ?? '6'),
                'target' => $application->scholarship_name ?? 'Beasiswa',
                'submitted_at' => $application->submitted_at ? $application->submitted_at->format('d F Y, H.i') : $application->created_at->format('d F Y, H.i'),
            ],
            'docx_url' => $application->generated_docx_path ? '/api/storage/' . $application->generated_docx_path : null
        ]);
    }

    /**
     * Approve scholarship application
     */
    public function approve(ScholarshipApplication $application, ScholarshipAutomationService $automationService)
    {
        $user = auth()->user();
        $subRole = $user->sub_role;
        $application->load(['mahasiswaProfile', 'user']);

        // If approved by Kaprodi/Sekprodi, move to Kadep/Sekdep stage
        if (in_array($subRole, ['kaprodi', 'sekprodi'])) {
            $application->update([
                'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
                'kaprodi_approved_at' => now(),
                'kaprodi_approved_by' => $user->id,
            ]);
            
            // Notify Kadep and Sekdep
            $kadeps = User::where('role', 'akademik')
                ->whereIn('sub_role', ['kadep', 'sekdep'])
                ->where('status', UserStatus::Active)
                ->get();
            
            if ($kadeps->count() > 0) {
                Notification::send($kadeps, new ScholarshipStatusNotification(
                    $application,
                    "Pendaftaran beasiswa telah disetujui Kaprodi/Sekprodi dan kini menunggu persetujuan akhir Anda."
                ));
            }

            return response()->json(['message' => 'Pendaftaran disetujui dan diteruskan ke Kadep/Sekdep']);
        }

        $newDocumentPath = null;
        $oldDocumentPath = null;

        try {
            $approvedApplication = DB::transaction(function () use ($application, $automationService, $user, &$newDocumentPath, &$oldDocumentPath) {
                $lockedApplication = ScholarshipApplication::whereKey($application->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedApplication->status !== ScholarshipApplication::STATUS_APPROVED_KAPRODI) {
                    return null;
                }

                $oldDocumentPath = $lockedApplication->generated_docx_path;
                $generatedDocumentPath = $automationService->generateDocument($lockedApplication);

                if (!$generatedDocumentPath) {
                    throw new RuntimeException('Dokumen final beasiswa gagal dibuat.');
                }

                $newDocumentPath = $generatedDocumentPath;

                $lockedApplication->update([
                    'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                    'kadep_approved_at' => now(),
                    'kadep_approved_by' => $user->id,
                    'generated_docx_path' => $generatedDocumentPath,
                ]);

                return $lockedApplication->fresh(['mahasiswaProfile', 'user']);
            });
        } catch (Throwable $exception) {
            if ($newDocumentPath) {
                $automationService->deleteGeneratedDocument($newDocumentPath);
            }

            report($exception);

            return response()->json([
                'message' => 'Pengajuan disetujui, tetapi dokumen final gagal dibuat. Status tidak diubah.',
            ], 500);
        }

        if (!$approvedApplication) {
            return response()->json([
                'message' => 'Pengajuan tidak berada pada tahap persetujuan Kadep/Sekdep.',
            ], 422);
        }

        if ($oldDocumentPath && $oldDocumentPath !== $newDocumentPath) {
            $automationService->deleteGeneratedDocument($oldDocumentPath);
        }

        // Notify Student
        $approvedApplication->user->notify(new ScholarshipStatusNotification(
            $approvedApplication,
            "Pendaftaran beasiswa Anda telah disetujui. Silakan review dokumen sebelum menyelesaikan pengajuan."
        ));

        return response()->json(['message' => 'Pendaftaran berhasil disetujui dan menunggu review mahasiswa']);
    }

    /**
     * Reject scholarship application
     */
    public function reject(ScholarshipApplication $application, Request $request)
    {
        $updateData = ['status' => ScholarshipApplication::STATUS_REJECTED];

        if ($request->filled('reason')) {
            $updateData['rejection_reason'] = $request->input('reason');
        }

        $application->update($updateData);
        $application->load('user');
        $application->user->notify(new ScholarshipStatusNotification(
            $application,
            "Maaf, pendaftaran beasiswa Anda ditolak oleh pihak pimpinan Fakultas/Prodi."
        ));
        return response()->json(['message' => 'Pendaftaran berhasil ditolak']);
    }

    /**
     * Request revision
     */
    public function revise(ScholarshipApplication $application, Request $request)
    {
        $updateData = ['status' => ScholarshipApplication::STATUS_REVISION];

        if ($request->filled('note')) {
            $updateData['revision_note'] = $request->input('note');
        }

        $application->update($updateData);
        $application->load('user');
        $application->user->notify(new ScholarshipStatusNotification(
            $application,
            "Pendaftaran beasiswa Anda memerlukan revisi dari Kaprodi/Sekprodi/Kadep."
        ));
        return response()->json(['message' => 'Permintaan revisi berhasil dikirim']);
    }
}
