<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use App\Models\ScholarshipApplication;
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

    private function getUserCounts()
    {
        $counts = User::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN role = 'mahasiswa' THEN 1 ELSE 0 END) as mahasiswa,
            SUM(CASE WHEN role = 'tendik' THEN 1 ELSE 0 END) as tendik,
            SUM(CASE WHEN role IN ('akademik', 'kadep', 'kaprodi', 'sekprodi', 'sekdep') THEN 1 ELSE 0 END) as akademik,
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
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive,
            SUM(CASE WHEN status = 'Blocked' THEN 1 ELSE 0 END) as blocked
        ")->first();

        $total = (int) $stats->total;

        return [
            'active' => [
                'count' => (int) $stats->active,
                'percentage' => $total > 0 ? round(($stats->active / $total) * 100) : 0
            ],
            'nonaktif' => [
                'count' => (int) $stats->inactive,
                'percentage' => $total > 0 ? round(($stats->inactive / $total) * 100) : 0
            ],
            'suspended' => [
                'count' => (int) $stats->blocked,
                'percentage' => $total > 0 ? round(($stats->blocked / $total) * 100) : 0
            ],
        ];
    }

    private function getActivityStats()
    {
        $startDate = Carbon::today()->subDays(6);

        $stats = ActivityLog::where('type', 'admin') // Diubah dari 'login' ke 'admin' (CRUD)
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
        // Calculate average duration from submitted_at to tendik_approved_at
        // And from tendik_approved_at to akademik_approved_at

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
