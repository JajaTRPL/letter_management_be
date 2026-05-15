<?php

namespace App\Http\Controllers\Tendik;

use App\Http\Controllers\Controller;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use App\Notifications\ScholarshipStatusNotification;
use App\Enums\UserStatus;
use App\Services\LetterAssignmentService;
use App\Services\LetterTaskCursorFeedService;
use App\Services\LetterTaskFeedService;
use App\Services\MahasiswaProfileDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class TendikDashboardController extends Controller
{
    public function __construct(
        private LetterAssignmentService $assignmentService,
        private LetterTaskCursorFeedService $cursorFeedService,
        private LetterTaskFeedService $taskFeedService
    )
    {
    }

    /**
     * Get dashboard data for the authenticated Tendik
     */
    public function getDashboardData(Request $request)
    {
        $user = Auth::user();

        if ($this->cursorFeedService->cursorModeRequested($request)) {
            $feed = $this->cursorFeedService->tendikDashboard($user, $request);

            return response()->json([
                'stats' => $this->dashboardStatsFor($user),
                'tasks' => $this->taskFeedService->orderedTendikRows($feed['models']),
                'meta' => $feed['meta'],
            ]);
        }

        $canHandleMagang = $this->assignmentService->canHandle($user, SuratPengantarMagangApplication::LETTER_TYPE);
        $canHandleAktif = $this->assignmentService->canHandle($user, SuratKeteranganAktifApplication::LETTER_TYPE);
        $canHandleProsesLuarNegeri = $this->assignmentService->canHandle($user, ProsesLuarNegeriApplication::LETTER_TYPE);

        $baseQuery = $this->assignmentService->applyFeedVisibility(
            ScholarshipApplication::whereIn('status', [
                ScholarshipApplication::STATUS_SUBMITTED,
                ScholarshipApplication::STATUS_APPROVED_TENDIK,
            ]),
            $user,
            ScholarshipApplication::LETTER_TYPE,
            [ScholarshipApplication::STATUS_SUBMITTED]
        );

        $finishedScholarshipCount = $this->assignmentService->applyFeedVisibility(
            ScholarshipApplication::whereIn('status', [
                ScholarshipApplication::STATUS_APPROVED_TENDIK,
                ScholarshipApplication::STATUS_APPROVED_KAPRODI,
                ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                ScholarshipApplication::STATUS_COMPLETED,
            ])->where('updated_at', '>=', now()->startOfMonth()),
            $user,
            ScholarshipApplication::LETTER_TYPE
        )->count();

        // Calculate Stats
        $stats = [
            'total_incoming' => (clone $baseQuery)->count(),
            'needs_verification' => (clone $baseQuery)->where('status', ScholarshipApplication::STATUS_SUBMITTED)->count(),
            'finished_this_month' => $finishedScholarshipCount,
        ];

        $tasks = (clone $baseQuery)
            ->with(['mahasiswaProfile.user', 'user'])
            ->orderBy('submitted_at', 'desc')
            ->limit(100)
            ->get();

        $magangTasks = collect();
        if ($canHandleMagang) {
            $magangBaseQuery = $this->assignmentService->applyFeedVisibility(
                SuratPengantarMagangApplication::whereIn('status', [
                    SuratPengantarMagangApplication::STATUS_SUBMITTED,
                    SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
                ]),
                $user,
                SuratPengantarMagangApplication::LETTER_TYPE
            );

            $stats['total_incoming'] += (clone $magangBaseQuery)->count();
            $stats['needs_verification'] += (clone $magangBaseQuery)
                ->where('status', SuratPengantarMagangApplication::STATUS_SUBMITTED)
                ->count();
            $stats['finished_this_month'] += (clone $magangBaseQuery)
                ->where('status', SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK)
                ->where('updated_at', '>=', now()->startOfMonth())
                ->count();

            $magangTasks = (clone $magangBaseQuery)
                ->with(['mahasiswaProfile.user', 'user'])
                ->orderBy('submitted_at', 'desc')
                ->limit(100)
                ->get();
        }

        $aktifTasks = collect();
        if ($canHandleAktif) {
            $aktifBaseQuery = $this->assignmentService->applyFeedVisibility(
                SuratKeteranganAktifApplication::whereIn('status', [
                    SuratKeteranganAktifApplication::STATUS_SUBMITTED,
                    SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
                ]),
                $user,
                SuratKeteranganAktifApplication::LETTER_TYPE
            );

            $stats['total_incoming'] += (clone $aktifBaseQuery)->count();
            $stats['needs_verification'] += (clone $aktifBaseQuery)
                ->where('status', SuratKeteranganAktifApplication::STATUS_SUBMITTED)
                ->count();
            $stats['finished_this_month'] += (clone $aktifBaseQuery)
                ->where('status', SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK)
                ->where('updated_at', '>=', now()->startOfMonth())
                ->count();

            $aktifTasks = (clone $aktifBaseQuery)
                ->with(['mahasiswaProfile.user', 'user'])
                ->orderBy('submitted_at', 'desc')
                ->limit(100)
                ->get();
        }

        $prosesLuarNegeriTasks = collect();
        if ($canHandleProsesLuarNegeri) {
            $prosesLuarNegeriBaseQuery = $this->assignmentService->applyFeedVisibility(
                ProsesLuarNegeriApplication::whereIn('status', [
                    ProsesLuarNegeriApplication::STATUS_SUBMITTED,
                    ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
                ]),
                $user,
                ProsesLuarNegeriApplication::LETTER_TYPE
            );

            $stats['total_incoming'] += (clone $prosesLuarNegeriBaseQuery)->count();
            $stats['needs_verification'] += (clone $prosesLuarNegeriBaseQuery)
                ->where('status', ProsesLuarNegeriApplication::STATUS_SUBMITTED)
                ->count();
            $stats['finished_this_month'] += (clone $prosesLuarNegeriBaseQuery)
                ->where('status', ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK)
                ->where('updated_at', '>=', now()->startOfMonth())
                ->count();

            $prosesLuarNegeriTasks = (clone $prosesLuarNegeriBaseQuery)
                ->with(['mahasiswaProfile.user', 'user'])
                ->orderBy('submitted_at', 'desc')
                ->limit(100)
                ->get();
        }

        return response()->json([
            'stats' => $stats,
            'tasks' => $this->taskFeedService->combinedTendikRows($tasks, $magangTasks, $aktifTasks, $prosesLuarNegeriTasks),
        ]);
    }

    /**
     * Get detailed application data
     */
    public function show(ScholarshipApplication $application, MahasiswaProfileDataService $profileDataService)
    {
        $application->load([
            'mahasiswaProfile.user',
            'mahasiswaProfile.keluarga',
            'user.studyProgram.department.faculty',
            'user.department.faculty',
        ]);

        $normalized = $profileDataService->forApplication($application);

        return response()->json([
            'application' => $application,
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
            'docx_url' => $application->generated_docx_path ? '/api/storage/' . $application->generated_docx_path : null
        ]);
    }

    /**
     * Approve scholarship application (Tendik → forward to Kaprodi)
     */
    public function approve(ScholarshipApplication $application, Request $request)
    {
        $updateData = [
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
            'tendik_approved_at' => now(),
        ];

        if ($request->filled('nomor_surat')) {
            $updateData['nomor_surat'] = $request->input('nomor_surat');
        }

        $application->update($updateData);
        
        // Notify Kaprodi and Sekprodi
        $academics = User::where('role', 'akademik')
            ->whereIn('sub_role', ['kaprodi', 'sekprodi'])
            ->where('status', UserStatus::Active)
            ->get();
            
        if ($academics->count() > 0) {
            $application->load('mahasiswaProfile');
            Notification::send($academics, new ScholarshipStatusNotification(
                $application, 
                "Pendaftaran beasiswa baru telah diverifikasi Tendik dan memerlukan persetujuan Anda."
            ));
        }

        return response()->json(['message' => 'Pendaftaran berhasil diverifikasi dan diteruskan ke Kaprodi/Sekprodi']);
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
            "Maaf, pendaftaran beasiswa Anda ditolak oleh staf verifikator."
        ));
        return response()->json(['message' => 'Pendaftaran berhasil ditolak']);
    }

    /**
     * Request revision for scholarship application
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
            "Pendaftaran beasiswa Anda memerlukan revisi. Silakan cek catatan di dashboard mahasiswa."
        ));
        return response()->json(['message' => 'Permintaan revisi berhasil dikirim']);
    }

    public function getRiwayatData(Request $request)
    {
        $user = Auth::user();

        if ($this->cursorFeedService->cursorModeRequested($request)) {
            $feed = $this->cursorFeedService->tendikRiwayat($user, $request);

            return response()->json([
                'tasks' => $this->taskFeedService->orderedTendikRows($feed['models']),
                'meta' => $feed['meta'],
            ]);
        }

        $canHandleMagang = $this->assignmentService->canHandle($user, SuratPengantarMagangApplication::LETTER_TYPE);
        $canHandleAktif = $this->assignmentService->canHandle($user, SuratKeteranganAktifApplication::LETTER_TYPE);
        $canHandleProsesLuarNegeri = $this->assignmentService->canHandle($user, ProsesLuarNegeriApplication::LETTER_TYPE);

        $historicalStatuses = [
            ScholarshipApplication::STATUS_REVISION,
            ScholarshipApplication::STATUS_REJECTED,
            ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            ScholarshipApplication::STATUS_COMPLETED,
        ];

        $scholarshipTasks = $this->assignmentService->applyFeedVisibility(
            ScholarshipApplication::whereIn('status', $historicalStatuses),
            $user,
            ScholarshipApplication::LETTER_TYPE
        )
            ->with(['mahasiswaProfile.user', 'user'])
            ->orderBy('submitted_at', 'desc')
            ->limit(100)
            ->get();

        $magangTasks = collect();
        if ($canHandleMagang) {
            $magangTasks = $this->assignmentService->applyFeedVisibility(
                SuratPengantarMagangApplication::whereIn('status', [
                    SuratPengantarMagangApplication::STATUS_REVISION,
                    SuratPengantarMagangApplication::STATUS_REJECTED,
                    SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
                    SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                    SuratPengantarMagangApplication::STATUS_COMPLETED,
                ]),
                $user,
                SuratPengantarMagangApplication::LETTER_TYPE
            )
                ->with(['mahasiswaProfile.user', 'user'])
                ->orderBy('submitted_at', 'desc')
                ->limit(100)
                ->get();
        }

        $aktifTasks = collect();
        if ($canHandleAktif) {
            $aktifTasks = $this->assignmentService->applyFeedVisibility(
                SuratKeteranganAktifApplication::whereIn('status', [
                    SuratKeteranganAktifApplication::STATUS_REVISION,
                    SuratKeteranganAktifApplication::STATUS_REJECTED,
                    SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
                    SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                    SuratKeteranganAktifApplication::STATUS_COMPLETED,
                ]),
                $user,
                SuratKeteranganAktifApplication::LETTER_TYPE
            )
                ->with(['mahasiswaProfile.user', 'user'])
                ->orderBy('submitted_at', 'desc')
                ->limit(100)
                ->get();
        }

        $prosesLuarNegeriTasks = collect();
        if ($canHandleProsesLuarNegeri) {
            $prosesLuarNegeriTasks = $this->assignmentService->applyFeedVisibility(
                ProsesLuarNegeriApplication::whereIn('status', [
                    ProsesLuarNegeriApplication::STATUS_REVISION,
                    ProsesLuarNegeriApplication::STATUS_REJECTED,
                    ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
                    ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                    ProsesLuarNegeriApplication::STATUS_COMPLETED,
                ]),
                $user,
                ProsesLuarNegeriApplication::LETTER_TYPE
            )
                ->with(['mahasiswaProfile.user', 'user'])
                ->orderBy('submitted_at', 'desc')
                ->limit(100)
                ->get();
        }

        return response()->json([
            'tasks' => $this->taskFeedService->combinedTendikRows($scholarshipTasks, $magangTasks, $aktifTasks, $prosesLuarNegeriTasks),
        ]);
    }

    private function dashboardStatsFor(User $user): array
    {
        $baseQuery = $this->assignmentService->applyFeedVisibility(
            ScholarshipApplication::whereIn('status', [
                ScholarshipApplication::STATUS_SUBMITTED,
                ScholarshipApplication::STATUS_APPROVED_TENDIK,
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
        ] as $letterType => $modelClass) {
            $query = $this->assignmentService->applyFeedVisibility(
                $modelClass::whereIn('status', [
                    $modelClass::STATUS_SUBMITTED,
                    $modelClass::STATUS_APPROVED_TENDIK,
                ]),
                $user,
                $letterType
            );

            $stats['total_incoming'] += (clone $query)->count();
            $stats['needs_verification'] += (clone $query)
                ->where('status', $modelClass::STATUS_SUBMITTED)
                ->count();
            $stats['finished_this_month'] += (clone $query)
                ->where('status', $modelClass::STATUS_APPROVED_TENDIK)
                ->where('updated_at', '>=', now()->startOfMonth())
                ->count();
        }

        return $stats;
    }

}
