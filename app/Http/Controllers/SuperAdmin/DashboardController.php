<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use App\Models\ScholarshipApplication;
use App\Enums\UserStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
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
                $daysOverdue = Carbon::parse($app->submitted_at)->diffInDays(now());
                return [
                    'id' => $app->id,
                    'submitted_at' => Carbon::parse($app->submitted_at)->format('d M Y, H.i'),
                    'student_name' => $app->mahasiswaProfile?->nama_lengkap ?? $app->user?->name ?? '-',
                    'nim' => $app->mahasiswaProfile?->nim ?? '-',
                    'status' => $app->status,
                    'assigned_to_name' => $app->assignedUser?->name ?? '-',
                    'days_overdue' => $daysOverdue,
                    'type' => $app->scholarship_name ?? 'Beasiswa',
                    'letter_type' => ScholarshipApplication::LETTER_TYPE,
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

    private function getApprovalDurations()
    {
        $tendikAvg = ScholarshipApplication::whereNotNull('submitted_at')
            ->whereNotNull('tendik_approved_at')
            ->select(DB::raw('AVG(EXTRACT(EPOCH FROM (tendik_approved_at - submitted_at))) as avg_time'))
            ->first()->avg_time;

        $akademikAvg = ScholarshipApplication::whereNotNull('tendik_approved_at')
            ->whereNotNull('akademik_approved_at')
            ->select(DB::raw('AVG(EXTRACT(EPOCH FROM (akademik_approved_at - tendik_approved_at))) as avg_time'))
            ->first()->avg_time;

        return [
            'tendik' => $this->formatDuration($tendikAvg),
            'akademik' => $this->formatDuration($akademikAvg),
        ];
    }

    private function formatDuration($seconds)
    {
        if (!$seconds)
            return ['days' => 0, 'hours' => 0, 'minutes' => 0];

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return [
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes,
        ];
    }
}
