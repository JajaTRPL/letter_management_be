<?php

namespace App\Http\Controllers\Tendik;

use App\Http\Controllers\Controller;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Notifications\ScholarshipStatusNotification;
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
        $canHandleScholarship = is_array($user->assigned_tasks) && in_array('Beasiswa', $user->assigned_tasks);

        // 1. Fetch scholarship applications:
        // - Directly assigned to this user
        // - OR Unassigned (NULL) AND this user has "Beasiswa" task
        $tasks = ScholarshipApplication::where(function ($query) use ($user, $canHandleScholarship) {
            // If user can handle scholarship, they see all submitted ones (Pool System)
            if ($canHandleScholarship) {
                $query->where('status', 'Submitted')
                      ->orWhere('assigned_to', $user->id);
            } else {
                // Otherwise only see what's specifically assigned to them
                $query->where('assigned_to', $user->id);
            }
        })
        ->with(['mahasiswaProfile.user'])
        ->orderBy('submitted_at', 'desc')
        ->get();

        // 2. Calculate Stats
        $stats = [
            'total_incoming' => $tasks->count(),
            'needs_verification' => $tasks->where('status', 'Submitted')->count(),
            'finished_this_month' => $tasks->whereIn('status', ['Approved', 'Selesai', 'Verified', 'Menunggu Persetujuan Kaprodi/Sekprodi'])
                ->where('updated_at', '>=', now()->startOfMonth())
                ->count(),
        ];

        return response()->json([
            'stats' => $stats,
            'tasks' => $tasks->map(function ($task) {
                return [
                    'id' => $task->id,
                    'submitted_at' => $task->submitted_at ? $task->submitted_at->format('d M Y, H.i') : $task->created_at->format('d M Y, H.i'),
                    'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? ($task->mahasiswaProfile?->user?->name ?? '-'),
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
        $application->load(['mahasiswaProfile.user', 'mahasiswaProfile.keluarga']);
        
        return response()->json([
            'application' => $application,
            'student' => [
                'name' => $application->mahasiswaProfile?->nama_lengkap ?? $application->user->name,
                'nim' => $application->mahasiswaProfile?->nim,
                'photo' => $application->mahasiswaProfile?->pas_foto_path ? '/api/storage/' . ltrim(str_replace('/storage/', '', $application->mahasiswaProfile->pas_foto_path), '/') : null,
                'prodi' => $application->mahasiswaProfile?->program_studi,
                'email' => $application->user->email,
                'ipk' => $application->ipk,
                'phone' => $application->mahasiswaProfile?->phone ?? '0812345678910',
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
    public function approve(ScholarshipApplication $application)
    {
        $application->update(['status' => 'Menunggu Persetujuan Kaprodi/Sekprodi']);
        
        // Notify Kaprodi and Sekprodi
        $academics = User::where('role', 'akademik')
            ->whereIn('sub_role', ['kaprodi', 'sekprodi'])
            ->where('status', 'Active')
            ->get();
            
        if ($academics->count() > 0) {
            $application->load('mahasiswaProfile');
            Notification::send($academics, new ScholarshipStatusNotification(
                $application, 
                "Pendaftaran beasiswa baru memerlukan verifikasi Anda (Level: Kaprodi/Sekprodi)."
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
