<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\LetterApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Enums\UserStatus;
use App\Services\AktifLetterAutomationService;

class AktifLetterController extends Controller
{
    public function getStep1()
    {
        $user = Auth::user();
        $application = LetterApplication::where('user_id', $user->id)
            ->where('type', 'aktif')
            ->where('status', 'Draft')
            ->first();

        return response()->json([
            'application' => $application
        ]);
    }

    public function saveStep1(Request $request)
    {
        $user = Auth::user();
        $profile = $user->mahasiswaProfile;

        if (!$profile) {
            return response()->json(['message' => 'Profil mahasiswa tidak ditemukan.'], 404);
        }

        $request->validate([
            'keperluan' => 'nullable|string',
            'pob' => 'nullable|string|max:255',
            'dob_day' => 'nullable|numeric|between:1,31',
            'dob_month' => 'nullable|numeric|between:1,12',
            'dob_year' => 'nullable|numeric',
            'gender' => 'nullable|in:L,P',
            'parent_name' => 'nullable|string|max:255',
            'parent_job' => 'nullable|string|max:255',
            'parent_job_type' => 'nullable|string|max:255',
            'parent_nip' => 'nullable|string|max:255',
            'parent_rank' => 'nullable|string|max:255',
            'parent_group' => 'nullable|string|max:255',
            'parent_institution' => 'nullable|string|max:255',
            'parent_employee_id' => 'nullable|string|max:255',
            'parent_position' => 'nullable|string|max:255',
            'parent_npwp' => 'nullable|string|max:255',
            'parent_business_name' => 'nullable|string|max:255',
        ]);

        // Construct DOB
        $dob = $request->dob;
        if ($request->dob_day && $request->dob_month && $request->dob_year) {
            $dob = "{$request->dob_year}-{$request->dob_month}-{$request->dob_day}";
        }

        // Sync to Profile if provided
        if ($request->pob) $profile->tempat_lahir = $request->pob;
        if ($dob) $profile->tanggal_lahir = $dob;
        if ($request->gender) $profile->jenis_kelamin = $request->gender;
        $profile->save();

        $application = LetterApplication::updateOrCreate(
            [
                'user_id' => $user->id,
                'type' => 'aktif',
                'status' => 'Draft',
            ],
            [
                'mahasiswa_profile_id' => $profile->id,
                'tujuan_surat' => $request->tujuan_surat ?? 'Umum',
                'keperluan' => $request->keperluan,
                'pob' => $request->pob,
                'dob' => $dob,
                'gender' => $request->gender,
                'parent_name' => $request->parent_name,
                'parent_job' => $request->parent_job,
                'parent_job_type' => $request->parent_job_type,
                'parent_nip' => $request->parent_nip,
                'parent_rank' => $request->parent_rank,
                'parent_group' => $request->parent_group,
                'parent_institution' => $request->parent_institution,
                'parent_employee_id' => $request->parent_employee_id,
                'parent_position' => $request->parent_position,
                'parent_npwp' => $request->parent_npwp,
                'parent_business_name' => $request->parent_business_name,
            ]
        );

        return response()->json([
            'message' => 'Draft berhasil disimpan',
            'application' => $application
        ]);
    }

    public function submitApplication(AktifLetterAutomationService $automationService)
    {
        $user = Auth::user();
        $application = LetterApplication::where('user_id', $user->id)
            ->where('type', 'aktif')
            ->where('status', 'Draft')
            ->first();

        if (!$application) {
            return response()->json(['message' => 'Draft pengajuan tidak ditemukan.'], 404);
        }

        // 1. Assign to Tendik
        $assignedTendik = $automationService->assignApplication($application);

        // 2. Update status
        $application->status = 'Pending Tendik Approval';
        $application->submitted_at = now();
        $application->save();

        // 3. Generate initial document (optional, but requested to "use this file")
        $automationService->generateDocument($application);

        return response()->json([
            'message' => 'Pengajuan berhasil dikirim',
            'application' => $application,
            'assigned_to' => $assignedTendik ? $assignedTendik->name : 'Staff terkait'
        ]);
    }
}
