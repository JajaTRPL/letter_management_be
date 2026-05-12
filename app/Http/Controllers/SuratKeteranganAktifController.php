<?php

namespace App\Http\Controllers;

use App\Models\SuratKeteranganAktifApplication;
use App\Services\LetterDocumentAccessService;
use App\Services\SuratKeteranganAktifService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SuratKeteranganAktifController extends Controller
{
    private const GENERATED_PDF_PREFIX = 'surat-keterangan-aktif/generated/';

    public function __construct(private LetterDocumentAccessService $documentAccessService)
    {
    }

    public function getApplications()
    {
        $applications = SuratKeteranganAktifApplication::where('user_id', Auth::id())
            ->where('status', '!=', SuratKeteranganAktifApplication::STATUS_DRAFT)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn ($application) => $this->forMahasiswaResponse($application)),
        ]);
    }

    public function getDraft()
    {
        $user = Auth::user();
        $user->load('studyProgram.department.faculty', 'mahasiswaProfile');

        $application = SuratKeteranganAktifApplication::where('user_id', $user->id)
            ->whereIn('status', [
                SuratKeteranganAktifApplication::STATUS_DRAFT,
                SuratKeteranganAktifApplication::STATUS_REVISION,
            ])
            ->with('mahasiswaProfile')
            ->latest()
            ->first();

        return response()->json([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'study_program' => $user->studyProgram,
            ],
            'profile' => $user->mahasiswaProfile,
            'application' => $application,
        ]);
    }

    public function saveDraft(Request $request)
    {
        $application = $this->getOrCreateDraftApplication();

        $validator = Validator::make($request->all(), $this->applicationRules());
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $application->update($request->only([
            'tempat_lahir',
            'tanggal_lahir',
            'jenis_kelamin',
            'keperluan',
            'nama_orang_tua_wali',
            'pekerjaan_orang_tua_wali',
            'nip_orang_tua_wali',
            'pangkat_gol_orang_tua_wali',
            'instansi_orang_tua_wali',
        ]));

        return response()->json([
            'message' => 'Draft Surat Keterangan Aktif berhasil disimpan',
            'application' => $application->fresh('mahasiswaProfile'),
        ]);
    }

    public function submitApplication(SuratKeteranganAktifService $service)
    {
        $application = SuratKeteranganAktifApplication::where('user_id', Auth::id())
            ->whereIn('status', [
                SuratKeteranganAktifApplication::STATUS_DRAFT,
                SuratKeteranganAktifApplication::STATUS_REVISION,
            ])
            ->latest()
            ->firstOrFail();

        $validator = Validator::make($application->toArray(), $this->submissionRules());
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $application->update([
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'revision_note' => null,
            'rejection_reason' => null,
        ]);

        $assignedTendik = $service->assignApplication($application);

        return response()->json([
            'message' => 'Pengajuan Surat Keterangan Aktif berhasil dikirim',
            'application' => $application->fresh('mahasiswaProfile'),
            'assigned_to' => $assignedTendik?->name,
        ]);
    }

    public function showForMahasiswa(SuratKeteranganAktifApplication $application)
    {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        return response()->json([
            'application' => $this->forMahasiswaResponse($application->load(['mahasiswaProfile', 'assignedTendik'])),
        ]);
    }

    public function showForReviewer(SuratKeteranganAktifApplication $application)
    {
        return response()->json([
            'application' => $application->load(['user', 'mahasiswaProfile', 'assignedTendik']),
        ]);
    }

    public function approveByTendik(Request $request, SuratKeteranganAktifApplication $application)
    {
        $validator = Validator::make($request->all(), [
            'nomor_surat' => 'required|string|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($application->status !== SuratKeteranganAktifApplication::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Pengajuan tidak berada pada tahap verifikasi Tendik.'], 422);
        }

        $application->update([
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => $request->nomor_surat,
            'assigned_to' => $application->assigned_to ?: Auth::id(),
            'tendik_approved_at' => now(),
            'revision_note' => null,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Pengajuan berhasil diverifikasi dan diteruskan ke Kaprodi/Sekprodi',
            'application' => $application->fresh(),
        ]);
    }

    public function reviseByTendik(Request $request, SuratKeteranganAktifApplication $application)
    {
        return $this->markRevision($request, $application, [
            SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);
    }

    public function rejectByTendik(Request $request, SuratKeteranganAktifApplication $application)
    {
        return $this->markRejected($request, $application, [
            SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);
    }

    public function approveByAkademik(SuratKeteranganAktifApplication $application, SuratKeteranganAktifService $service)
    {
        $subRole = Auth::user()->sub_role;

        if (in_array($subRole, ['kaprodi', 'sekprodi'], true)) {
            if ($application->status !== SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK) {
                return response()->json(['message' => 'Pengajuan tidak berada pada tahap persetujuan Kaprodi/Sekprodi.'], 422);
            }

            $application->update([
                'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
                'kaprodi_approved_at' => now(),
                'kaprodi_approved_by' => Auth::id(),
                'revision_note' => null,
                'rejection_reason' => null,
            ]);

            return response()->json([
                'message' => 'Pengajuan disetujui dan diteruskan ke Kadep/Sekdep',
                'application' => $application->fresh(),
            ]);
        }

        if (in_array($subRole, ['kadep', 'sekdep'], true)) {
            try {
                return DB::transaction(function () use ($application, $service) {
                    $lockedApplication = SuratKeteranganAktifApplication::whereKey($application->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($lockedApplication->status !== SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI) {
                        return response()->json(['message' => 'Pengajuan tidak berada pada tahap persetujuan Kadep/Sekdep.'], 422);
                    }

                    $shouldGeneratePdf = empty($lockedApplication->generated_pdf_path);

                    $lockedApplication->update([
                        'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
                        'kadep_approved_at' => now(),
                        'kadep_approved_by' => Auth::id(),
                        'revision_note' => null,
                        'rejection_reason' => null,
                    ]);

                    if ($shouldGeneratePdf) {
                        $service->generateDocument($lockedApplication->fresh(), Auth::user());
                    }

                    return response()->json([
                        'message' => 'Pengajuan disetujui dan menunggu review mahasiswa',
                        'application' => $lockedApplication->fresh(),
                    ]);
                });
            } catch (\Throwable $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Pengajuan disetujui, tetapi dokumen PDF gagal dibuat. Status tidak diubah.',
                ], 500);
            }
        }

        return response()->json(['message' => 'Sub-role akademik tidak dikenali.'], 403);
    }

    public function preview(SuratKeteranganAktifApplication $application)
    {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        if (!$this->documentAccessService->canPreview($application)) {
            return response()->json([
                'message' => 'Dokumen belum tersedia untuk direview.',
            ], 422);
        }

        $path = $this->documentAccessService->resolveGeneratedDocumentPath(
            $application,
            'generated_pdf_path',
            self::GENERATED_PDF_PREFIX
        );
        if (!$path) {
            return response()->json([
                'message' => 'Dokumen PDF belum tersedia.',
            ], 404);
        }

        $this->documentAccessService->markPreviewedIfNeeded($application);

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $this->documentFilename($application) . '"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function complete(SuratKeteranganAktifApplication $application)
    {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        if ($application->status === SuratKeteranganAktifApplication::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'Pengajuan sudah selesai.',
                'application' => $this->forMahasiswaResponse($application->fresh(['mahasiswaProfile', 'assignedTendik'])),
            ]);
        }

        if (!$this->documentAccessService->canComplete($application)) {
            return response()->json([
                'message' => 'Pengajuan belum berada pada tahap review mahasiswa.',
            ], 422);
        }

        if (!$this->documentAccessService->hasBeenPreviewed($application)) {
            return response()->json([
                'message' => 'Silakan review dokumen sebelum menyelesaikan pengajuan.',
            ], 422);
        }

        $path = $this->documentAccessService->resolveGeneratedDocumentPath(
            $application,
            'generated_pdf_path',
            self::GENERATED_PDF_PREFIX
        );
        if (!$path) {
            return response()->json([
                'message' => 'Dokumen PDF belum tersedia.',
            ], 404);
        }

        $application->update([
            'status' => SuratKeteranganAktifApplication::STATUS_COMPLETED,
            'student_reviewed_at' => now(),
            'completed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Pengajuan Surat Keterangan Aktif telah diselesaikan.',
            'application' => $this->forMahasiswaResponse($application->fresh(['mahasiswaProfile', 'assignedTendik'])),
        ]);
    }

    public function reviseByAkademik(Request $request, SuratKeteranganAktifApplication $application)
    {
        return $this->markRevision($request, $application, [
            SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
        ]);
    }

    public function rejectByAkademik(Request $request, SuratKeteranganAktifApplication $application)
    {
        return $this->markRejected($request, $application, [
            SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
        ]);
    }

    private function getOrCreateDraftApplication(): SuratKeteranganAktifApplication
    {
        $user = Auth::user();
        $profile = $user->mahasiswaProfile ?? $user->mahasiswaProfile()->create([]);

        $editableApplication = SuratKeteranganAktifApplication::where('user_id', $user->id)
            ->whereIn('status', [
                SuratKeteranganAktifApplication::STATUS_DRAFT,
                SuratKeteranganAktifApplication::STATUS_REVISION,
            ])
            ->latest()
            ->first();

        if ($editableApplication) {
            return $editableApplication;
        }

        return SuratKeteranganAktifApplication::firstOrCreate(
            [
                'user_id' => $user->id,
                'status' => SuratKeteranganAktifApplication::STATUS_DRAFT,
            ],
            [
                'mahasiswa_profile_id' => $profile->id,
            ]
        );
    }

    private function applicationRules(): array
    {
        return [
            'tempat_lahir' => 'required|string|max:255',
            'tanggal_lahir' => 'required|date',
            'jenis_kelamin' => 'required|in:Laki-laki,Perempuan',
            'keperluan' => 'required|string|max:2000',
            'nama_orang_tua_wali' => 'required|string|max:255',
            'pekerjaan_orang_tua_wali' => 'required|string|max:255',
            'nip_orang_tua_wali' => 'nullable|string|max:255',
            'pangkat_gol_orang_tua_wali' => 'nullable|string|max:255',
            'instansi_orang_tua_wali' => 'nullable|string|max:255',
        ];
    }

    private function submissionRules(): array
    {
        return $this->applicationRules();
    }

    private function markRevision(Request $request, SuratKeteranganAktifApplication $application, array $allowedStatuses)
    {
        $validator = Validator::make($request->all(), [
            'note' => 'required|string|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!in_array($application->status, $allowedStatuses, true)) {
            return response()->json(['message' => 'Pengajuan tidak dapat direvisi pada tahap ini.'], 422);
        }

        $application->update([
            'status' => SuratKeteranganAktifApplication::STATUS_REVISION,
            'revision_note' => $request->note,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Permintaan revisi berhasil dikirim',
            'application' => $application->fresh(),
        ]);
    }

    private function markRejected(Request $request, SuratKeteranganAktifApplication $application, array $allowedStatuses)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!in_array($application->status, $allowedStatuses, true)) {
            return response()->json(['message' => 'Pengajuan tidak dapat ditolak pada tahap ini.'], 422);
        }

        $application->update([
            'status' => SuratKeteranganAktifApplication::STATUS_REJECTED,
            'rejection_reason' => $request->reason,
            'revision_note' => null,
        ]);

        return response()->json([
            'message' => 'Pengajuan berhasil ditolak',
            'application' => $application->fresh(),
        ]);
    }

    private function forMahasiswaResponse(SuratKeteranganAktifApplication $application): SuratKeteranganAktifApplication
    {
        return $this->documentAccessService->redactGeneratedPathIfNeeded(
            $application,
            'generated_pdf_path',
            self::GENERATED_PDF_PREFIX,
            true
        );
    }

    private function documentFilename(SuratKeteranganAktifApplication $application): string
    {
        $application->loadMissing('mahasiswaProfile');
        $identifier = $application->mahasiswaProfile?->nim ?: (string) $application->id;
        $identifier = preg_replace('/[^A-Za-z0-9_-]+/', '_', $identifier) ?: '';
        $identifier = trim($identifier, '_') ?: (string) $application->id;

        return 'Surat_Keterangan_Aktif_' . $identifier . '.pdf';
    }
}
