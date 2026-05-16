<?php

namespace App\Http\Controllers;

use App\Models\SuratPengantarMagangApplication;
use App\Services\LetterDocumentAccessService;
use App\Services\SuratPengantarMagangService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SuratPengantarMagangController extends Controller
{
    private const GENERATED_PDF_PREFIX = 'surat-pengantar-magang/generated/';

    public function __construct(private LetterDocumentAccessService $documentAccessService)
    {
    }

    public function getApplications()
    {
        $applications = SuratPengantarMagangApplication::where('user_id', Auth::id())
            ->where('status', '!=', SuratPengantarMagangApplication::STATUS_DRAFT)
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

        $application = SuratPengantarMagangApplication::where('user_id', $user->id)
            ->whereIn('status', [
                SuratPengantarMagangApplication::STATUS_DRAFT,
                SuratPengantarMagangApplication::STATUS_REVISION,
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

        $validator = Validator::make($request->all(), $this->applicationRules($application));
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only([
            'nama_penerima',
            'nama_perusahaan',
            'alamat_perusahaan',
            'peran',
            'rentang_tanggal',
            'dosen_pembimbing_dpa',
        ]);

        if ($request->hasFile('proposal_kegiatan_magang')) {
            $path = $request->file('proposal_kegiatan_magang')
                ->store('surat-pengantar-magang/proposals', 'public');
            $this->deletePublicFile($application->proposal_kegiatan_magang_path);
            $data['proposal_kegiatan_magang_path'] = Storage::url($path);
        }

        $application->update($data);

        return response()->json([
            'message' => 'Draft Surat Pengantar Magang berhasil disimpan',
            'application' => $application->fresh('mahasiswaProfile'),
        ]);
    }

    public function submitApplication(SuratPengantarMagangService $service)
    {
        $application = SuratPengantarMagangApplication::where('user_id', Auth::id())
            ->whereIn('status', [
                SuratPengantarMagangApplication::STATUS_DRAFT,
                SuratPengantarMagangApplication::STATUS_REVISION,
            ])
            ->latest()
            ->firstOrFail();

        $validator = Validator::make($application->toArray(), $this->submissionRules());
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $application->update([
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'revision_note' => null,
            'rejection_reason' => null,
        ]);

        $assignedTendik = $service->assignApplication($application);

        return response()->json([
            'message' => 'Pengajuan Surat Pengantar Magang berhasil dikirim',
            'application' => $application->fresh('mahasiswaProfile'),
            'assigned_to' => $assignedTendik?->name,
        ]);
    }

    public function showForMahasiswa(SuratPengantarMagangApplication $application)
    {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        return response()->json([
            'application' => $this->forMahasiswaResponse($application->load(['mahasiswaProfile', 'assignedTendik'])),
        ]);
    }

    public function showForReviewer(SuratPengantarMagangApplication $application)
    {
        // Load the canonical academic chain so the FE can render Prodi / Fakultas / Departemen
        // from the relation tree instead of the legacy mahasiswa_profiles.{program_studi,fakultas}
        // text columns (which may be null for admin-created accounts and are being deprecated).
        return response()->json([
            'application' => $application->load([
                'user.studyProgram.department.faculty',
                'user.department.faculty',
                'mahasiswaProfile',
                'assignedTendik',
            ]),
        ]);
    }

    public function approveByTendik(Request $request, SuratPengantarMagangApplication $application)
    {
        $validator = Validator::make($request->all(), [
            'nomor_surat' => 'required|string|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($application->status !== SuratPengantarMagangApplication::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Pengajuan tidak berada pada tahap verifikasi Tendik.'], 422);
        }

        $application->update([
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
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

    public function reviseByTendik(Request $request, SuratPengantarMagangApplication $application)
    {
        return $this->markRevision($request, $application, [
            SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);
    }

    public function rejectByTendik(Request $request, SuratPengantarMagangApplication $application)
    {
        return $this->markRejected($request, $application, [
            SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);
    }

    public function approveByAkademik(SuratPengantarMagangApplication $application, SuratPengantarMagangService $service)
    {
        $subRole = Auth::user()->sub_role;

        if (in_array($subRole, ['kaprodi', 'sekprodi'], true)) {
            if ($application->status !== SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK) {
                return response()->json(['message' => 'Pengajuan tidak berada pada tahap persetujuan Kaprodi/Sekprodi.'], 422);
            }

            $application->update([
                'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
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
                    $lockedApplication = SuratPengantarMagangApplication::whereKey($application->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($lockedApplication->status !== SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI) {
                        return response()->json(['message' => 'Pengajuan tidak berada pada tahap persetujuan Kadep/Sekdep.'], 422);
                    }

                    $shouldGeneratePdf = empty($lockedApplication->generated_pdf_path);

                    $lockedApplication->update([
                        'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
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

    public function preview(SuratPengantarMagangService $service, SuratPengantarMagangApplication $application)
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

    public function complete(SuratPengantarMagangService $service, SuratPengantarMagangApplication $application)
    {
        $this->documentAccessService->ensureOwner($application, Auth::user());

        if ($application->status === SuratPengantarMagangApplication::STATUS_COMPLETED) {
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
            'status' => SuratPengantarMagangApplication::STATUS_COMPLETED,
            'student_reviewed_at' => now(),
            'completed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Pengajuan Surat Pengantar Magang telah diselesaikan.',
            'application' => $this->forMahasiswaResponse($application->fresh(['mahasiswaProfile', 'assignedTendik'])),
        ]);
    }

    public function reviseByAkademik(Request $request, SuratPengantarMagangApplication $application)
    {
        return $this->markRevision($request, $application, [
            SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
        ]);
    }

    public function rejectByAkademik(Request $request, SuratPengantarMagangApplication $application)
    {
        return $this->markRejected($request, $application, [
            SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
        ]);
    }

    private function getOrCreateDraftApplication(): SuratPengantarMagangApplication
    {
        $user = Auth::user();
        $profile = $user->mahasiswaProfile ?? $user->mahasiswaProfile()->create([]);

        $editableApplication = SuratPengantarMagangApplication::where('user_id', $user->id)
            ->whereIn('status', [
                SuratPengantarMagangApplication::STATUS_DRAFT,
                SuratPengantarMagangApplication::STATUS_REVISION,
            ])
            ->latest()
            ->first();

        if ($editableApplication) {
            return $editableApplication;
        }

        return SuratPengantarMagangApplication::firstOrCreate(
            [
                'user_id' => $user->id,
                'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
            ],
            [
                'mahasiswa_profile_id' => $profile->id,
            ]
        );
    }

    private function applicationRules(?SuratPengantarMagangApplication $application = null): array
    {
        return [
            'nama_penerima' => 'required|string|max:255',
            'nama_perusahaan' => 'required|string|max:255',
            'alamat_perusahaan' => 'required|string|max:2000',
            'peran' => 'required|string|max:255',
            'rentang_tanggal' => 'required|string|max:255',
            'dosen_pembimbing_dpa' => 'required|string|max:255',
            'proposal_kegiatan_magang' => [
                $application?->proposal_kegiatan_magang_path ? 'nullable' : 'required',
                'file',
                'max:2048',
            ],
        ];
    }

    private function submissionRules(): array
    {
        return [
            'nama_penerima' => 'required|string|max:255',
            'nama_perusahaan' => 'required|string|max:255',
            'alamat_perusahaan' => 'required|string|max:2000',
            'peran' => 'required|string|max:255',
            'rentang_tanggal' => 'required|string|max:255',
            'dosen_pembimbing_dpa' => 'required|string|max:255',
            'proposal_kegiatan_magang_path' => 'required|string',
        ];
    }

    private function markRevision(Request $request, SuratPengantarMagangApplication $application, array $allowedStatuses)
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
            'status' => SuratPengantarMagangApplication::STATUS_REVISION,
            'revision_note' => $request->note,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Permintaan revisi berhasil dikirim',
            'application' => $application->fresh(),
        ]);
    }

    private function markRejected(Request $request, SuratPengantarMagangApplication $application, array $allowedStatuses)
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
            'status' => SuratPengantarMagangApplication::STATUS_REJECTED,
            'rejection_reason' => $request->reason,
            'revision_note' => null,
        ]);

        return response()->json([
            'message' => 'Pengajuan berhasil ditolak',
            'application' => $application->fresh(),
        ]);
    }

    private function forMahasiswaResponse(SuratPengantarMagangApplication $application): SuratPengantarMagangApplication
    {
        return $this->documentAccessService->redactGeneratedPathIfNeeded(
            $application,
            'generated_pdf_path',
            self::GENERATED_PDF_PREFIX,
            true
        );
    }

    private function documentFilename(SuratPengantarMagangApplication $application): string
    {
        $application->loadMissing('mahasiswaProfile');
        $identifier = $application->mahasiswaProfile?->nim ?: (string) $application->id;
        $identifier = preg_replace('/[^A-Za-z0-9_-]+/', '_', $identifier) ?: '';
        $identifier = trim($identifier, '_') ?: (string) $application->id;

        return 'Surat_Pengantar_Magang_' . $identifier . '.pdf';
    }

    private function deletePublicFile(?string $filePath): void
    {
        $path = $this->publicDiskPath($filePath);
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function publicDiskPath(?string $filePath): ?string
    {
        if (!$filePath) {
            return null;
        }

        $path = parse_url($filePath, PHP_URL_PATH) ?: $filePath;
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (str_starts_with($path, 'api/storage/')) {
            $path = substr($path, strlen('api/storage/'));
        }

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return str_starts_with($path, 'surat-pengantar-magang/proposals/') ? $path : null;
    }
}
