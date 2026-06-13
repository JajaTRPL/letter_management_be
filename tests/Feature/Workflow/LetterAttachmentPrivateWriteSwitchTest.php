<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\ScholarshipApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Models\User;
use App\Services\LetterAttachmentRequirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * D2B controller-level proof that NEW supporting-document uploads write to the
 * private attachment registry (never the public disk). D2H3D retires future
 * legacy marker writes while preserving historical legacy values and the
 * registry-backed submit gates.
 */
class LetterAttachmentPrivateWriteSwitchTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restoreRetiredAttachmentColumnsForLegacyFixtureTests();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        try {
            $this->dropRetiredAttachmentColumnsForLegacyFixtureTests();
        } finally {
            parent::tearDown();
        }
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nswitch\n%%EOF\n");
    }

    private function beasiswaStep3Payload(array $overrides = []): array
    {
        return array_merge([
            'scholarship_name' => 'Beasiswa Test',
            'current_semester' => 5,
            'family_dependents' => 2,
            'gpa_last_semesters' => 3.5,
            'ipk' => 3.6,
            'sks_last_semesters' => 20,
            'total_sks_passed' => 100,
            'on_leave' => 'Belum',
            'thesis_status' => 'Belum',
            'has_scholarship_history' => 'Belum',
        ], $overrides);
    }

    private function draftScholarship(User $student): ScholarshipApplication
    {
        return ScholarshipApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $student->mahasiswaProfile?->id,
            'scholarship_name' => 'Beasiswa Test',
            'status' => ScholarshipApplication::STATUS_DRAFT,
        ]);
    }

    public function test_beasiswa_step3_writes_private_registry_rows_no_public(): void
    {
        [$student] = $this->completeMahasiswa();
        $this->draftScholarship($student);

        $response = $this->actingAs($student, 'sanctum')->post('/api/mahasiswa/scholarship/step-3', $this->beasiswaStep3Payload([
            'transkrip_nilai' => $this->pdf('transkrip.pdf'),
            'slip_gaji_ayah' => $this->pdf('ayah.pdf'),
            'slip_gaji_ibu' => $this->pdf('ibu.pdf'),
        ]));

        $response->assertOk();

        $rows = LetterApplicationAttachment::query()
            ->where('letter_type', ScholarshipApplication::LETTER_TYPE)
            ->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('local', $row->storage_disk);
            $this->assertNotNull($row->checksum_sha256);
            $this->assertStringStartsWith('letter-application-attachments/', $row->storage_path);
        }

        // No public attachment write expanded compatibility exposure.
        $this->assertEmpty(Storage::disk('public')->allFiles());

        // Legacy columns are not rewritten with compatibility markers.
        $application = ScholarshipApplication::where('user_id', $student->id)->first();
        $this->assertNull($application->transkrip_nilai_path);
        $this->assertNull($application->slip_gaji_ayah_path);
        $this->assertNull($application->slip_gaji_ibu_path);
    }

    public function test_beasiswa_step3_ignores_client_supplied_legacy_attachment_paths(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->draftScholarship($student);
        $application->forceFill([
            'transkrip_nilai_path' => 'legacy/transkrip.pdf',
            'slip_gaji_ayah_path' => 'legacy/slip-ayah.pdf',
            'slip_gaji_ibu_path' => 'attachment://slip_gaji_ibu/legacy.pdf',
            'ktm_path' => 'legacy/ktm.pdf',
        ])->save();

        $response = $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/scholarship/step-3', $this->beasiswaStep3Payload([
                'transkrip_nilai_path' => 'client/transkrip.pdf',
                'slip_gaji_ayah_path' => 'client/slip-ayah.pdf',
                'slip_gaji_ibu_path' => 'client/slip-ibu.pdf',
                'ktm_path' => 'client/ktm.pdf',
            ]));

        $response->assertOk();

        $fresh = $application->fresh();
        $this->assertSame('legacy/transkrip.pdf', $fresh->transkrip_nilai_path);
        $this->assertSame('legacy/slip-ayah.pdf', $fresh->slip_gaji_ayah_path);
        $this->assertSame('attachment://slip_gaji_ibu/legacy.pdf', $fresh->slip_gaji_ibu_path);
        $this->assertSame('legacy/ktm.pdf', $fresh->ktm_path);
        $this->assertSame(0, LetterApplicationAttachment::query()
            ->where('letter_type', ScholarshipApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->count());
    }

    public function test_legacy_attachment_columns_are_not_mass_assignable(): void
    {
        $this->assertNotContains('transkrip_nilai_path', (new ScholarshipApplication())->getFillable());
        $this->assertNotContains('slip_gaji_ayah_path', (new ScholarshipApplication())->getFillable());
        $this->assertNotContains('slip_gaji_ibu_path', (new ScholarshipApplication())->getFillable());
        $this->assertNotContains('ktm_path', (new ScholarshipApplication())->getFillable());
        $this->assertNotContains('proposal_kegiatan_magang_path', (new SuratPengantarMagangApplication())->getFillable());
        $this->assertNotContains('proposal_kegiatan_magang_path', (new SuratTugasApplication())->getFillable());
        $this->assertNotContains('surat_pengantar_magang_path', (new SuratTugasApplication())->getFillable());

        [$student] = $this->completeMahasiswa();

        $scholarship = $this->draftScholarship($student);
        $scholarship->fill([
            'transkrip_nilai_path' => 'client/transkrip.pdf',
            'slip_gaji_ayah_path' => 'client/slip-ayah.pdf',
            'slip_gaji_ibu_path' => 'client/slip-ibu.pdf',
            'ktm_path' => 'client/ktm.pdf',
        ])->save();
        $this->assertNull($scholarship->fresh()->transkrip_nilai_path);
        $this->assertNull($scholarship->fresh()->slip_gaji_ayah_path);
        $this->assertNull($scholarship->fresh()->slip_gaji_ibu_path);
        $this->assertNull($scholarship->fresh()->ktm_path);

        $magang = SuratPengantarMagangApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $student->mahasiswaProfile?->id,
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
        ]);
        $magang->fill(['proposal_kegiatan_magang_path' => 'client/proposal.pdf'])->save();
        $this->assertNull($magang->fresh()->proposal_kegiatan_magang_path);

        $suratTugas = SuratTugasApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $student->mahasiswaProfile?->id,
            'status' => SuratTugasApplication::STATUS_DRAFT,
        ]);
        $suratTugas->fill([
            'proposal_kegiatan_magang_path' => 'client/proposal.pdf',
            'surat_pengantar_magang_path' => 'client/pengantar.pdf',
        ])->save();
        $this->assertNull($suratTugas->fresh()->proposal_kegiatan_magang_path);
        $this->assertNull($suratTugas->fresh()->surat_pengantar_magang_path);
    }

    public function test_magang_draft_writes_private_registry_and_preserves_old_public_file(): void
    {
        [$student] = $this->completeMahasiswa();

        // Pre-existing legacy public proposal from before D2B.
        Storage::disk('public')->put('surat-pengantar-magang/proposals/old.pdf', '%PDF old legacy');
        SuratPengantarMagangApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $student->mahasiswaProfile?->id,
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
        ])->forceFill([
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/old.pdf'),
        ])->save();

        $response = $this->actingAs($student, 'sanctum')->post('/api/mahasiswa/surat-pengantar-magang/draft', [
            'nama_penerima' => 'HR',
            'nama_perusahaan' => 'PT Test',
            'alamat_perusahaan' => 'Jl. Test',
            'peran' => 'Intern',
            'rentang_tanggal' => '1 Juni - 31 Agustus',
            'dosen_pembimbing_dpa' => 'Dr. Test',
            'proposal_kegiatan_magang' => $this->pdf('new-proposal.pdf'),
        ]);

        $response->assertOk();

        $row = LetterApplicationAttachment::query()
            ->where('letter_type', SuratPengantarMagangApplication::LETTER_TYPE)
            ->where('document_key', 'proposal')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('local', $row->storage_disk);

        // Old public legacy proposal preserved untouched (no D2B deletion).
        $this->assertTrue(Storage::disk('public')->exists('surat-pengantar-magang/proposals/old.pdf'));

        $application = SuratPengantarMagangApplication::where('user_id', $student->id)->first();
        $this->assertSame(
            Storage::url('surat-pengantar-magang/proposals/old.pdf'),
            $application->proposal_kegiatan_magang_path,
        );
    }

    public function test_surat_tugas_draft_writes_two_private_registry_rows_no_public(): void
    {
        [$student] = $this->completeMahasiswa();
        SuratTugasApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $student->mahasiswaProfile?->id,
            'status' => SuratTugasApplication::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($student, 'sanctum')->post('/api/mahasiswa/surat-tugas/draft', [
            'nama_perusahaan' => 'PT Test',
            'kegiatan' => 'Magang',
            'posisi' => 'Intern',
            'dosen_pembimbing_dpa' => 'Dr. Test',
            'proposal_kegiatan_magang' => $this->pdf('proposal.pdf'),
            'surat_pengantar_magang' => $this->pdf('pengantar.pdf'),
        ]);

        $response->assertOk();

        $rows = LetterApplicationAttachment::query()
            ->where('letter_type', SuratTugasApplication::LETTER_TYPE)
            ->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('local', $row->storage_disk);
            $this->assertNotNull($row->checksum_sha256);
        }
        $this->assertEmpty(Storage::disk('public')->allFiles());

        $application = SuratTugasApplication::where('user_id', $student->id)->first();
        $this->assertNull($application->proposal_kegiatan_magang_path);
        $this->assertNull($application->surat_pengantar_magang_path);
    }

    public function test_registry_rows_satisfy_current_submit_gate_while_legacy_columns_remain_null(): void
    {
        [$student] = $this->completeMahasiswa();
        SuratTugasApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $student->mahasiswaProfile?->id,
            'status' => SuratTugasApplication::STATUS_DRAFT,
        ]);

        $this->actingAs($student, 'sanctum')->post('/api/mahasiswa/surat-tugas/draft', [
            'nama_perusahaan' => 'PT Test',
            'kegiatan' => 'Magang',
            'posisi' => 'Intern',
            'dosen_pembimbing_dpa' => 'Dr. Test',
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
            'proposal_kegiatan_magang' => $this->pdf('proposal.pdf'),
            'surat_pengantar_magang' => $this->pdf('pengantar.pdf'),
        ])->assertOk();

        $application = SuratTugasApplication::where('user_id', $student->id)->first();
        $this->assertNotNull($application);

        $this->assertSame([], $this->app
            ->make(LetterAttachmentRequirementService::class)
            ->missingRequiredDocumentKeys(SuratTugasApplication::LETTER_TYPE, (int) $application->getKey()));
        $this->assertNull($application->proposal_kegiatan_magang_path);
        $this->assertNull($application->surat_pengantar_magang_path);
    }
}
