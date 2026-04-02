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
        return [
            'mahasiswa' => User::where('role', 'mahasiswa')->count(),
            'tendik' => User::whereIn('role', ['tendik', 'tendik_1'])->count(),
            'akademik' => User::where('role', 'akademik')->count(),
            'super_admin' => User::where('role', 'super_admin')->count(),
            'total' => User::count(),
        ];
    }

    private function getStatusDistribution()
    {
        $total = User::count();

        $active = User::where('status', 'Active')->count();
        $inactive = User::where('status', 'Inactive')->count();
        $blocked = User::where('status', 'Blocked')->count();

        return [
            'active' => [
                'count' => $active,
                'percentage' => $total > 0 ? round(($active / $total) * 100) : 0
            ],
            'nonaktif' => [
                'count' => $inactive,
                'percentage' => $total > 0 ? round(($inactive / $total) * 100) : 0
            ],
            'suspended' => [
                'count' => $blocked,
                'percentage' => $total > 0 ? round(($blocked / $total) * 100) : 0
            ],
        ];
    }

    private function getActivityStats()
    {
        // Sample data for line chart (Last 7 days)
        $days = [];
        $counts = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $days[] = $date->format('D, d M');
            $counts[] = ActivityLog::where('type', 'login')->whereDate('created_at', $date)->count();
        }

        return ['labels' => $days, 'data' => $counts];
    }

    private function getScholarshipStats()
    {
        $days = [];
        $counts = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $days[] = $date->format('D, d M');
            $counts[] = ScholarshipApplication::whereDate('submitted_at', $date)->count();
        }

        return ['labels' => $days, 'data' => $counts];
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
