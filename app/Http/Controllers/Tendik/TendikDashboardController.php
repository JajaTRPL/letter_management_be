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
        
        $canHandleLetter = is_array($user->assigned_tasks) && 
            collect($user->assigned_tasks)->contains(fn($t) => str_contains(strtolower($t), 'aktif'));

        // Fetch scholarship applications
        $scholarshipQuery = ScholarshipApplication::where(function ($query) use ($user, $canHandleScholarship) {
            $query->where('assigned_to', $user->id);
            if ($canHandleScholarship) {
                $query->orWhere(function ($q) {
                    $q->where('status', 'Submitted')
                      ->whereNull('assigned_to');
                });
            }
        })
        ->whereNotIn('status', ['Draft']);

        // Fetch letter applications
        $letterQuery = \App\Models\LetterApplication::where(function ($query) use ($user, $canHandleLetter) {
            $query->where('assigned_to', $user->id);
            if ($canHandleLetter) {
                $query->orWhere(function ($q) {
                    $q->where('status', 'Pending Tendik Approval')
                      ->whereNull('assigned_to');
                });
            }
        })
        ->whereNotIn('status', ['Draft']);

        // Calculate Stats
        $stats = [
            'total_incoming' => $scholarshipQuery->count() + $letterQuery->count(),
            'needs_verification' => (clone $scholarshipQuery)->where('status', 'Submitted')->count() + (clone $letterQuery)->where('status', 'Pending Tendik Approval')->count(),
            'finished_this_month' => (clone $scholarshipQuery)->whereIn('status', ['Approved_Tendik', 'Approved_Kaprodi', 'Approved_Kadep', 'Completed'])
                ->where('updated_at', '>=', now()->startOfMonth())->count() + 
                (clone $letterQuery)->whereIn('status', ['Approved_Tendik', 'Completed'])
                ->where('updated_at', '>=', now()->startOfMonth())->count(),
        ];

        $scholarshipTasks = $scholarshipQuery->with(['mahasiswaProfile.user', 'user'])->get();
        $letterTasks = $letterQuery->with(['mahasiswaProfile.user', 'user'])->get();

        $mergedTasks = $scholarshipTasks->map(function ($task) {
            return [
                'id' => $task->id,
                'db_id' => $task->id,
                'submitted_at' => $task->submitted_at ? $task->submitted_at->format('d M Y, H.i') : $task->created_at->format('d M Y, H.i'),
                'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? ($task->mahasiswaProfile?->user?->name ?? $task->user?->name ?? '-'),
                'nim' => $task->mahasiswaProfile?->nim ?? '-',
                'type' => 'Surat Beasiswa',
                'category' => 'beasiswa',
                'scholarship_name' => $task->scholarship_name,
                'status' => $task->status === 'Submitted' ? 'Menunggu Verifikasi' : $task->status,
                'is_overdue' => $task->submitted_at && $task->submitted_at->diffInHours(now()) > 24,
                'docx_url' => $task->generated_docx_path ? '/api/storage/' . $task->generated_docx_path : null,
            ];
        })->concat($letterTasks->map(function ($task) {
            return [
                'id' => 'letter-' . $task->id,
                'db_id' => $task->id,
                'submitted_at' => $task->submitted_at ? $task->submitted_at->format('d M Y, H.i') : $task->created_at->format('d M Y, H.i'),
                'student_name' => $task->mahasiswaProfile?->nama_lengkap ?? ($task->mahasiswaProfile?->user?->name ?? $task->user?->name ?? '-'),
                'nim' => $task->mahasiswaProfile?->nim ?? '-',
                'type' => 'Surat Keterangan Aktif',
                'category' => 'aktif',
                'scholarship_name' => $task->keperluan,
                'status' => $task->status === 'Pending Tendik Approval' ? 'Menunggu Verifikasi' : $task->status,
                'is_overdue' => $task->submitted_at && $task->submitted_at->diffInHours(now()) > 24,
                'docx_url' => $task->generated_docx_path ? '/api/storage/' . $task->generated_docx_path : null,
            ];
        }))->sortByDesc('submitted_at')->values();

        return response()->json([
            'stats' => $stats,
            'tasks' => $mergedTasks
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

    /*
    |--------------------------------------------------------------------------
    | Letter Application Handlers
    |--------------------------------------------------------------------------
    */

    public function showLetter(\App\Models\LetterApplication $application)
    {
        $application->load(['mahasiswaProfile.user', 'user']);
        
        return response()->json([
            'application' => $application,
            'student' => [
                'name' => $application->mahasiswaProfile?->nama_lengkap ?? $application->user->name,
                'nim' => $application->mahasiswaProfile?->nim,
                'prodi' => $application->mahasiswaProfile?->program_studi,
                'email' => $application->user->email,
                'submitted_at' => $application->submitted_at ? $application->submitted_at->format('d F Y, H.i') : $application->created_at->format('d F Y, H.i'),
            ],
            'docx_url' => $application->generated_docx_path ? '/api/storage/' . $application->generated_docx_path : null
        ]);
    }

    public function approveLetter(\App\Models\LetterApplication $application)
    {
        $application->update([
            'status' => 'Completed',
            'updated_at' => now(),
        ]);
        
        // You could add a notification here
        
        return response()->json(['message' => 'Surat Keterangan Aktif berhasil disetujui dan diselesaikan.']);
    }

    public function rejectLetter(\App\Models\LetterApplication $application)
    {
        $application->update(['status' => 'Rejected']);
        return response()->json(['message' => 'Surat Keterangan Aktif berhasil ditolak.']);
    }

    public function reviseLetter(\App\Models\LetterApplication $application)
    {
        $application->update(['status' => 'Revision']);
        return response()->json(['message' => 'Permintaan revisi berhasil dikirim.']);
    }
}
