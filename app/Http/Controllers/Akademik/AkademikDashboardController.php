<?php

namespace App\Http\Controllers\Akademik;

use App\Http\Controllers\Controller;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Notifications\ScholarshipStatusNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class AkademikDashboardController extends Controller
{
    /**
     * Get dashboard stats and task list for Kaprodi/Sekprodi
     */
    public function getDashboardData()
    {
        $user = auth()->user();
        $subRole = $user->sub_role; // kadep, sekdep, kaprodi, sekprodi

        // Determine which status this user should see
        $targetStatus = 'Menunggu Persetujuan Kaprodi/Sekprodi';
        if (in_array($subRole, ['kadep', 'sekdep'])) {
            $targetStatus = 'Menunggu Persetujuan Kadep/Sekdep';
        }

        $tasks = ScholarshipApplication::with('user', 'mahasiswaProfile')
            ->where('status', $targetStatus)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'stats' => [
                'total_incoming' => $tasks->count(),
                'needs_verification' => $tasks->count(),
                'finished_this_month' => ScholarshipApplication::where('status', 'Verified')
                    ->whereMonth('updated_at', now()->month)
                    ->count(),
            ],
            'tasks' => $tasks->map(function ($task) {
                return [
                    'id' => $task->id,
                    'submitted_at' => $task->created_at->format('d M Y, H.i'),
                    'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? $task->user?->name,
                    'nim' => $task->mahasiswaProfile?->nim,
                    'type' => $task->scholarship_name ?? 'Beasiswa',
                    'status' => $task->status,
                    'docx_url' => $task->generated_docx_path ? '/api/storage/' . $task->generated_docx_path : null,
                    'is_overdue' => $task->created_at->diffInHours(now()) > 24
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
     * Final Approval (Verified)
     */
    public function approve(ScholarshipApplication $application)
    {
        $user = auth()->user();
        $subRole = $user->sub_role;
        $application->load(['mahasiswaProfile', 'user']);

        // If approved by Kaprodi/Sekprodi, move to Kadep/Sekdep stage
        if (in_array($subRole, ['kaprodi', 'sekprodi'])) {
            $application->update(['status' => 'Menunggu Persetujuan Kadep/Sekdep']);
            
            // Notify Kadep and Sekdep
            $kadeps = User::where('role', 'akademik')
                ->whereIn('sub_role', ['kadep', 'sekdep'])
                ->where('status', 'Active')
                ->get();
            
            if ($kadeps->count() > 0) {
                Notification::send($kadeps, new ScholarshipStatusNotification(
                    $application,
                    "Pendaftaran beasiswa telah disetujui Kaprodi/Sekprodi dan kini menunggu persetujuan akhir Anda."
                ));
            }

            return response()->json(['message' => 'Pendaftaran disetujui dan diteruskan ke Kadep/Sekdep']);
        }

        // If Kadep/Sekdep approves, it is final
        $application->update(['status' => 'Verified']);
        
        // Notify Student
        $application->user->notify(new ScholarshipStatusNotification(
            $application,
            "Selamat! Pendaftaran beasiswa Anda telah disetujui oleh Fakultas (Verified)."
        ));

        return response()->json(['message' => 'Pendaftaran berhasil disetujui (Final Verified)']);
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
            "Maaf, pendaftaran beasiswa Anda ditolak oleh pihak pimpinan Fakultas/Prodi."
        ));
        return response()->json(['message' => 'Pendaftaran berhasil ditolak']);
    }

    /**
     * Request revision
     */
    public function revise(ScholarshipApplication $application, Request $request)
    {
        $application->update(['status' => 'Revision']);
        $application->load('user');
        $application->user->notify(new ScholarshipStatusNotification(
            $application,
            "Pendaftaran beasiswa Anda memerlukan revisi dari Kaprodi/Sekprodi/Kadep."
        ));
        return response()->json(['message' => 'Permintaan revisi berhasil dikirim']);
    }
}
