<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use App\Models\ScholarshipApplication;
use App\Enums\UserStatus;
use App\Services\Analytics\ReviewPerformanceService;
use App\Services\Notifications\WorkflowReviewSlaPolicyService;
use App\Support\Analytics\ReviewAnalyticsPeriod;
use App\Support\Workflow\LetterReviewStageClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __construct(private ReviewPerformanceService $performance) {}

    public function getStats()
    {
        return response()->json([
            'user_counts' => $this->getUserCounts(),
            'status_distribution' => $this->getStatusDistribution(),
            'activity_stats' => $this->getActivityStats(),
            'scholarship_stats' => $this->getScholarshipStats(),
            'approval_durations' => $this->getApprovalDurations(),
        ]);
    }

    /**
     * Get monitoring data for LetterMonitoring page.
     * Returns real stats + real application list ordered by newest first.
     */
    public function getMonitoringData(Request $request)
    {
        $period = $request->query('period', 'today');

        // Determine date filter
        $startDate = match ($period) {
            'today' => Carbon::today(),
            'week' => Carbon::today()->subDays(7),
            '1month' => Carbon::today()->subMonth(),
            '3months' => Carbon::today()->subMonths(3),
            '6months' => Carbon::today()->subMonths(6),
            '12months' => Carbon::today()->subYear(),
            default => Carbon::today(),
        };

        // Stats counts
        $baseQuery = ScholarshipApplication::where('status', '!=', ScholarshipApplication::STATUS_DRAFT);

        $suratMasuk = (clone $baseQuery)
            ->where('submitted_at', '>=', $startDate)
            ->count();

        $menungguPersetujuan = (clone $baseQuery)
            ->whereIn('status', [
                ScholarshipApplication::STATUS_SUBMITTED,
                ScholarshipApplication::STATUS_APPROVED_TENDIK,
                ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            ])
            ->count();

        $perluRevisi = (clone $baseQuery)
            ->where('status', ScholarshipApplication::STATUS_REVISION)
            ->count();

        $selesai = (clone $baseQuery)
            ->where('status', ScholarshipApplication::STATUS_COMPLETED)
            ->where('submitted_at', '>=', $startDate)
            ->count();

        // Overdue applications (more than 3 days without progress)
        $overdueThreshold = Carbon::now()->subDays(3);
        
        $overdueApplications = ScholarshipApplication::with(['user', 'mahasiswaProfile', 'assignedUser'])
            ->whereNotIn('status', [
                ScholarshipApplication::STATUS_DRAFT,
                ScholarshipApplication::STATUS_COMPLETED,
                ScholarshipApplication::STATUS_REJECTED,
            ])
            ->where('submitted_at', '<=', $overdueThreshold)
            ->orderBy('submitted_at', 'desc')
            ->get()
            ->map(function ($app) {
                $daysOverdue = (int) Carbon::parse($app->submitted_at)->diffInDays(now());
                $statusLabels = [
                    'Draft' => 'Draft Pengajuan',
                    'Submitted' => 'Menunggu Verifikasi Persuratan',
                    'Revision' => 'Perlu Perbaikan (Revisi)',
                    'Rejected' => 'Pengajuan Ditolak',
                    'Approved_Tendik' => 'Diverifikasi — Menunggu Paraf Prodi',
                    'Approved_Kaprodi' => 'Diparaf — Menunggu Tanda Tangan Departemen',
                    'Ready_For_Student_Review' => 'Siap Ditinjau Mahasiswa',
                    'Completed' => 'Surat Selesai & Terbit',
                ];
                return [
                    'id' => $app->id,
                    'submitted_at' => Carbon::parse($app->submitted_at)->format('d M Y, H.i'),
                    'student_name' => $app->mahasiswaProfile?->nama_lengkap ?? $app->user?->name ?? '-',
                    'nim' => $app->mahasiswaProfile?->nim ?? '-',
                    'status' => $app->status,
                    'status_label' => $statusLabels[$app->status] ?? $app->status,
                    'assigned_to_name' => $app->assignedUser?->name ?? '-',
                    'days_overdue' => $daysOverdue,
                    'type' => $app->scholarship_name ?? 'Surat Permohonan Beasiswa',
                    'letter_type' => ScholarshipApplication::LETTER_TYPE,
                    'letter_type_label' => 'Surat Permohonan Beasiswa',
                ];
            });

        return response()->json([
            'stats' => [
                'surat_masuk' => $suratMasuk,
                'menunggu_persetujuan' => $menungguPersetujuan,
                'perlu_revisi' => $perluRevisi,
                'selesai' => $selesai,
            ],
            'overdue' => $overdueApplications,
        ]);
    }

    private function getUserCounts()
    {
        $counts = User::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN role = 'mahasiswa' THEN 1 ELSE 0 END) as mahasiswa,
            SUM(CASE WHEN role = 'tendik' THEN 1 ELSE 0 END) as tendik,
            SUM(CASE WHEN role = 'akademik' THEN 1 ELSE 0 END) as akademik,
            SUM(CASE WHEN role = 'super_admin' THEN 1 ELSE 0 END) as super_admin
        ")->first();

        return [
            'mahasiswa' => (int) $counts->mahasiswa,
            'tendik' => (int) $counts->tendik,
            'akademik' => (int) $counts->akademik,
            'super_admin' => (int) $counts->super_admin,
            'total' => (int) $counts->total,
        ];
    }

    private function getStatusDistribution()
    {
        $stats = User::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as suspended,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending
        ", [
            UserStatus::Active->value,
            UserStatus::Suspended->value,
            UserStatus::PendingProfile->value,
        ])->first();

        $total = (int) $stats->total;

        return [
            'active' => [
                'count' => (int) $stats->active,
                'percentage' => $total > 0 ? round(($stats->active / $total) * 100) : 0
            ],
            'suspended' => [
                'count' => (int) $stats->suspended,
                'percentage' => $total > 0 ? round(($stats->suspended / $total) * 100) : 0
            ],
            'pending' => [
                'count' => (int) $stats->pending,
                'percentage' => $total > 0 ? round(($stats->pending / $total) * 100) : 0
            ],
        ];
    }

    private function getActivityStats()
    {
        $startDate = Carbon::today()->subDays(6);

        $stats = ActivityLog::where('type', 'admin')
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->get()
            ->pluck('count', 'date');

        $days = [];
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $dateStr = $date->toDateString();
            $days[] = $date->format('D, d M');
            $data[] = $stats->get($dateStr, 0);
        }

        return ['labels' => $days, 'data' => $data];
    }

    private function getScholarshipStats()
    {
        $startDate = Carbon::today()->subDays(6);

        $stats = ScholarshipApplication::whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $startDate)
            ->selectRaw('DATE(submitted_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->get()
            ->pluck('count', 'date');

        $days = [];
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $dateStr = $date->toDateString();
            $days[] = $date->format('D, d M');
            $data[] = $stats->get($dateStr, 0);
        }

        return ['labels' => $days, 'data' => $data];
    }

    /**
     * @deprecated Superseded by GET /api/super-admin/review-performance, which
     * reports all five review stages across both workflow domains with sample
     * confidence and SLA context. Kept for one release so the dashboard keeps
     * rendering during the frontend cutover; delete this method, formatDuration(),
     * and the `approval_durations` key together once nothing reads them.
     *
     * Rewritten to delegate rather than query. The previous implementation had two
     * defects that made it permanently useless: it read `akademik_approved_at`, a
     * column no code has written since the workflow moved to Kaprodi/Kadep (so the
     * "Akademik" box could only ever show zero), and it used Postgres-only
     * `EXTRACT(EPOCH …)`, which throws on the sqlite test connection — which is
     * why neither defect was ever caught by a test.
     *
     * The legacy shape only has two slots, so it reports the first two letter
     * stages: `tendik` = Persuratan, `akademik` = Prodi (the stage that actually
     * follows Tendik). Departemen and both room-booking stages are visible only on
     * the new endpoint.
     */
    private function getApprovalDurations()
    {
        $summary = $this->performance->summary(ReviewAnalyticsPeriod::resolve(ReviewAnalyticsPeriod::DEFAULT));

        $stages = collect($summary['scopes'])
            ->firstWhere('scope', WorkflowReviewSlaPolicyService::SCOPE_LETTER)['stages'] ?? [];
        $byStage = collect($stages)->keyBy('stage');

        return [
            'tendik' => $this->formatDuration($byStage[LetterReviewStageClock::STAGE_PERSURATAN]['metric']['median_seconds'] ?? null),
            'akademik' => $this->formatDuration($byStage[LetterReviewStageClock::STAGE_PRODI]['metric']['median_seconds'] ?? null),
            'deprecated' => true,
        ];
    }

    /** @deprecated Retired together with getApprovalDurations(). */
    private function formatDuration($seconds)
    {
        if (! $seconds) {
            return ['days' => 0, 'hours' => 0, 'minutes' => 0];
        }

        $seconds = (int) $seconds;

        return [
            'days' => intdiv($seconds, 86400),
            'hours' => intdiv($seconds % 86400, 3600),
            'minutes' => intdiv($seconds % 3600, 60),
        ];
    }
}
