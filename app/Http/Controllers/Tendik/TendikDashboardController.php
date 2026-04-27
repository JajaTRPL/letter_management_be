<?php

namespace App\Http\Controllers\Tendik;

use App\Http\Controllers\Controller;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Notifications\ScholarshipStatusNotification;
use App\Enums\UserStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class TendikDashboardController extends Controller
{
    /**
     * Get dashboard data for the authenticated Tendik
     */
    public function getDashboardData()
    {
        $user = Auth::user();
        $canHandleScholarship = is_array($user->assigned_tasks) && 
            collect($user->assigned_tasks)->contains(fn($t) => str_contains(strtolower($t), 'beasiswa'));

        // Fetch scholarship applications:
        // - Directly assigned to this user (any status)
        // - OR status = 'Submitted' AND (assigned to this user OR this user can handle scholarship)
        $baseQuery = ScholarshipApplication::where(function ($query) use ($user, $canHandleScholarship) {
            $query->where('assigned_to', $user->id);
            if ($canHandleScholarship) {
                $query->orWhere(function ($q) {
                    $q->where('status', 'Submitted')
                      ->whereNull('assigned_to');
                });
            }
        })
        ->whereNotIn('status', ['Draft']);

        // Calculate Stats
        $stats = [
            'total_incoming' => (clone $baseQuery)->count(),
            'needs_verification' => (clone $baseQuery)->where('status', 'Submitted')->count(),
            'finished_this_month' => (clone $baseQuery)->whereIn('status', ['Approved_Tendik', 'Approved_Kaprodi', 'Approved_Kadep', 'Completed'])
                ->where('updated_at', '>=', now()->startOfMonth())
                ->count(),
        ];

        $tasks = (clone $baseQuery)
            ->with(['mahasiswaProfile.user', 'user'])
            ->orderBy('submitted_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'stats' => $stats,
            'tasks' => $tasks->map(function ($task) {
                return [
                    'id' => $task->id,
                    'submitted_at' => $task->submitted_at ? $task->submitted_at->format('d M Y, H.i') : $task->created_at->format('d M Y, H.i'),
                    'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? ($task->mahasiswaProfile?->user?->name ?? $task->user?->name ?? '-'),
                    'nim' => $task->mahasiswaProfile?->nim ?? '-',
                    'type' => 'Surat Beasiswa',
                    'scholarship_name' => $task->scholarship_name,
                    'status' => $task->status === 'Submitted' ? 'Menunggu Verifikasi' : $task->status,
                    'is_overdue' => $task->submitted_at && $task->submitted_at->diffInHours(now()) > 24,
                    'docx_url' => $task->generated_docx_path ? '/api/storage/' . $task->generated_docx_path : null,
                ];
            })
        ]);
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
     * Approve scholarship application (Tendik → forward to Kaprodi)
     */
    public function approve(ScholarshipApplication $application)
    {
        $application->update([
            'status' => 'Approved_Tendik',
            'tendik_approved_at' => now(),
        ]);
        
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
    public function reject(ScholarshipApplication $application)
    {
        $application->update(['status' => 'Rejected']);
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
        $application->update(['status' => 'Revision']);
        $application->load('user');
        $application->user->notify(new ScholarshipStatusNotification(
            $application,
            "Pendaftaran beasiswa Anda memerlukan revisi. Silakan cek catatan di dashboard mahasiswa."
        ));
        return response()->json(['message' => 'Permintaan revisi berhasil dikirim']);
    }
}
