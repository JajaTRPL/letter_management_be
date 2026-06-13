<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\ScholarshipApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LetterAttachmentMarkerWriteRetirementTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private const PDF_BYTES = "%PDF-1.4\nD2H3D fallback\n%%EOF\n";

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
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n{$name}\n%%EOF\n");
    }

    public function test_beasiswa_uploads_write_registry_rows_and_preserve_historical_legacy_values(): void
    {
        [$student] = $this->completeMahasiswa();

        Storage::disk('public')->put('scholarships/transcripts/legacy.pdf', self::PDF_BYTES);
        Storage::disk('local')->put('letter-application-attachments/legacy/slip-ayah.pdf', self::PDF_BYTES);

        $application = ScholarshipApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $student->mahasiswaProfile?->id,
            'scholarship_name' => 'Beasiswa Test',
            'status' => ScholarshipApplication::STATUS_DRAFT,
        ]);
        $application->forceFill([
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
            'slip_gaji_ayah_path' => 'letter-application-attachments/legacy/slip-ayah.pdf',
            'slip_gaji_ibu_path' => 'attachment://slip_gaji_ibu/Legacy Ibu.pdf',
            'ktm_path' => null,
        ])->save();

        $response = $this->actingAs($student, 'sanctum')->post('/api/mahasiswa/scholarship/step-3', [
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
            'transkrip_nilai' => $this->pdf('Transkrip Baru.pdf'),
            'slip_gaji_ayah' => $this->pdf('Slip Ayah Baru.pdf'),
            'slip_gaji_ibu' => $this->pdf('Slip Ibu Baru.pdf'),
        ]);

        $response->assertOk()
            ->assertJsonPath('application.supporting_documents.transkrip_nilai.original_filename', 'Transkrip Baru.pdf')
            ->assertJsonPath('application.supporting_documents.slip_gaji_ayah.original_filename', 'Slip Ayah Baru.pdf')
            ->assertJsonPath('application.supporting_documents.slip_gaji_ibu.original_filename', 'Slip Ibu Baru.pdf');
        $this->assertRetiredAttachmentFieldsAbsent($response->json('application'), [
            'transkrip_nilai_path',
            'slip_gaji_ayah_path',
            'slip_gaji_ibu_path',
            'ktm_path',
        ]);

        $fresh = $application->fresh();
        $this->assertSame(Storage::url('scholarships/transcripts/legacy.pdf'), $fresh->transkrip_nilai_path);
        $this->assertSame('letter-application-attachments/legacy/slip-ayah.pdf', $fresh->slip_gaji_ayah_path);
        $this->assertSame('attachment://slip_gaji_ibu/Legacy Ibu.pdf', $fresh->slip_gaji_ibu_path);
        $this->assertNull($fresh->ktm_path);

        $rows = LetterApplicationAttachment::query()
            ->where('letter_type', ScholarshipApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->get()
            ->keyBy('document_key');

        $this->assertSame(['slip_gaji_ayah', 'slip_gaji_ibu', 'transkrip_nilai'], $rows->keys()->sort()->values()->all());
        foreach ($rows as $row) {
            $this->assertSame('local', $row->storage_disk);
            $this->assertNotNull($row->checksum_sha256);
            $this->assertStringStartsWith('letter-application-attachments/', $row->storage_path);
            $this->assertTrue(Storage::disk('local')->exists($row->storage_path));
        }

        $metadataJson = json_encode($response->json('application.supporting_documents'));
        $this->assertStringNotContainsString('attachment://', $metadataJson);
        $this->assertStringNotContainsString('/storage/', $metadataJson);
        $this->assertStringNotContainsString('letter-application-attachments', $metadataJson);
    }

    public function test_magang_upload_preserves_null_legacy_column_and_submit_uses_registry_row(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();

        $draft = $this->actingAs($student, 'sanctum')->post('/api/mahasiswa/surat-pengantar-magang/draft', [
            'nama_penerima' => 'HR Department',
            'jabatan_penerima' => 'Kepala Divisi Teknologi',
            'nama_perusahaan' => 'PT Test',
            'alamat_perusahaan' => 'Jl. Test',
            'alamat_jalan' => 'Jl. Test No. 1',
            'alamat_kelurahan' => 'Caturtunggal',
            'alamat_kecamatan' => 'Depok',
            'alamat_kota_kabupaten' => 'Sleman',
            'alamat_provinsi' => 'Daerah Istimewa Yogyakarta',
            'kode_pos' => '55281',
            'peran' => 'Software Engineer Intern',
            'rentang_tanggal' => '1 Juni 2026 - 31 Agustus 2026',
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
            'dosen_pembimbing_dpa' => 'Dr. Test',
            'proposal_kegiatan_magang' => $this->pdf('Proposal Magang Baru.pdf'),
        ]);

        $draft->assertOk()
            ->assertJsonPath('application.supporting_documents.proposal.exists', true)
            ->assertJsonPath('application.supporting_documents.proposal.original_filename', 'Proposal Magang Baru.pdf');
        $this->assertRetiredAttachmentFieldsAbsent($draft->json('application'), [
            'proposal_kegiatan_magang_path',
        ]);

        $application = SuratPengantarMagangApplication::where('user_id', $student->id)->firstOrFail();
        $this->assertNull($application->proposal_kegiatan_magang_path);
        $this->assertManagedAttachment(SuratPengantarMagangApplication::LETTER_TYPE, $application->id, 'proposal');

        $this->mockMagangPreviewGenerationAlwaysReady();

        $submit = $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-pengantar-magang/submit')
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);
        $this->assertRetiredAttachmentFieldsAbsent($submit->json('application'), [
            'proposal_kegiatan_magang_path',
        ]);
    }

    public function test_magang_draft_with_registry_proposal_row_treats_upload_as_nullable(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'proposal_kegiatan_magang_path' => null,
        ]);
        $this->attachRegistryDocument(
            $application,
            SuratPengantarMagangApplication::LETTER_TYPE,
            'proposal',
            'Proposal Existing.pdf',
        );

        $response = $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', $this->magangDraftPayload([
                'nama_perusahaan' => 'PT Registry Existing',
            ]))
            ->assertOk()
            ->assertJsonPath('application.supporting_documents.proposal.exists', true)
            ->assertJsonPath('application.supporting_documents.proposal.original_filename', 'Proposal Existing.pdf');
        $this->assertRetiredAttachmentFieldsAbsent($response->json('application'), [
            'proposal_kegiatan_magang_path',
        ]);
    }

    public function test_magang_draft_without_registry_row_requires_upload_even_if_legacy_path_exists(): void
    {
        [$student] = $this->completeMahasiswa();
        Storage::disk('public')->put('surat-pengantar-magang/proposals/legacy-only.pdf', self::PDF_BYTES);
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/legacy-only.pdf'),
        ]);

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', $this->magangDraftPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('proposal_kegiatan_magang');

        $this->assertSame(0, LetterApplicationAttachment::query()
            ->where('letter_type', SuratPengantarMagangApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->count());
        $this->assertSame(
            Storage::url('surat-pengantar-magang/proposals/legacy-only.pdf'),
            $application->fresh()->proposal_kegiatan_magang_path,
        );
    }

    public function test_magang_replacement_updates_registry_cleans_old_private_file_and_preserves_historical_value(): void
    {
        [$student] = $this->completeMahasiswa();
        Storage::disk('public')->put('surat-pengantar-magang/proposals/legacy.pdf', self::PDF_BYTES);

        $application = SuratPengantarMagangApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $student->mahasiswaProfile?->id,
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
        ]);
        $application->forceFill([
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/legacy.pdf'),
        ])->save();

        $payload = [
            'nama_penerima' => 'HR Department',
            'nama_perusahaan' => 'PT Test',
            'alamat_perusahaan' => 'Jl. Test',
            'peran' => 'Intern',
            'rentang_tanggal' => '1 Juni 2026 - 31 Agustus 2026',
            'dosen_pembimbing_dpa' => 'Dr. Test',
        ];

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', $payload + [
                'proposal_kegiatan_magang' => $this->pdf('first.pdf'),
            ])
            ->assertOk();

        $application = SuratPengantarMagangApplication::where('user_id', $student->id)->firstOrFail();
        $first = $this->assertManagedAttachment(SuratPengantarMagangApplication::LETTER_TYPE, $application->id, 'proposal');

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', $payload + [
                'proposal_kegiatan_magang' => $this->pdf('second.pdf'),
            ])
            ->assertOk();

        $second = $this->assertManagedAttachment(SuratPengantarMagangApplication::LETTER_TYPE, $application->id, 'proposal');
        $this->assertNotSame($first->storage_path, $second->storage_path);
        $this->assertFalse(Storage::disk('local')->exists($first->storage_path));
        $this->assertTrue(Storage::disk('local')->exists($second->storage_path));
        $this->assertTrue(Storage::disk('public')->exists('surat-pengantar-magang/proposals/legacy.pdf'));
        $this->assertSame(
            Storage::url('surat-pengantar-magang/proposals/legacy.pdf'),
            $application->fresh()->proposal_kegiatan_magang_path,
        );
    }

    public function test_surat_tugas_upload_preserves_null_legacy_columns_and_submit_uses_registry_rows(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();

        $draft = $this->actingAs($student, 'sanctum')->post('/api/mahasiswa/surat-tugas/draft', [
            'nama_perusahaan' => 'PT Test',
            'kegiatan' => 'Magang Kerja Praktik',
            'posisi' => 'Software Engineer Intern',
            'dosen_pembimbing_dpa' => 'Dr. Test',
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
            'proposal_kegiatan_magang' => $this->pdf('Proposal ST Baru.pdf'),
            'surat_pengantar_magang' => $this->pdf('Pengantar ST Baru.pdf'),
        ]);

        $draft->assertOk()
            ->assertJsonPath('application.supporting_documents.proposal.original_filename', 'Proposal ST Baru.pdf')
            ->assertJsonPath('application.supporting_documents.surat_pengantar_magang.original_filename', 'Pengantar ST Baru.pdf');
        $this->assertRetiredAttachmentFieldsAbsent($draft->json('application'), [
            'proposal_kegiatan_magang_path',
            'surat_pengantar_magang_path',
        ]);

        $application = SuratTugasApplication::where('user_id', $student->id)->firstOrFail();
        $this->assertNull($application->proposal_kegiatan_magang_path);
        $this->assertNull($application->surat_pengantar_magang_path);
        $this->assertManagedAttachment(SuratTugasApplication::LETTER_TYPE, $application->id, 'proposal');
        $this->assertManagedAttachment(SuratTugasApplication::LETTER_TYPE, $application->id, 'surat_pengantar_magang');

        $this->mockSuratTugasPreviewGenerationAlwaysReady();

        $submit = $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-tugas/submit')
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);
        $this->assertRetiredAttachmentFieldsAbsent($submit->json('application'), [
            'proposal_kegiatan_magang_path',
            'surat_pengantar_magang_path',
        ]);

        $this->assertSame(0, SuratPengantarMagangApplication::query()->count());
    }

    public function test_historical_legacy_values_are_not_previewed_without_registry_rows(): void
    {
        [$beasiswaStudent] = $this->completeMahasiswa();
        Storage::disk('public')->put('scholarships/transcripts/legacy.pdf', self::PDF_BYTES);
        $beasiswa = $this->scholarshipApplication($beasiswaStudent, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/legacy.pdf'),
        ]);

        $this->assertSame(0, LetterApplicationAttachment::query()
            ->where('letter_type', ScholarshipApplication::LETTER_TYPE)
            ->where('application_id', $beasiswa->id)
            ->count());
        $this->actingAs($beasiswaStudent, 'sanctum')
            ->get("/api/scholarship/{$beasiswa->id}/supporting-documents/transkrip_nilai/preview")
            ->assertNotFound();
        $this->assertSame(
            Storage::url('scholarships/transcripts/legacy.pdf'),
            $beasiswa->fresh()->transkrip_nilai_path,
        );

        [$magangStudent] = $this->completeMahasiswa();
        Storage::disk('public')->put('surat-pengantar-magang/proposals/legacy.pdf', self::PDF_BYTES);
        $magang = $this->magangApplication($magangStudent, [
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/legacy.pdf'),
        ]);

        $this->assertSame(0, LetterApplicationAttachment::query()
            ->where('letter_type', SuratPengantarMagangApplication::LETTER_TYPE)
            ->where('application_id', $magang->id)
            ->count());
        $this->actingAs($magangStudent, 'sanctum')
            ->get("/api/surat-pengantar-magang/{$magang->id}/supporting-documents/proposal/preview")
            ->assertNotFound();
        $this->assertSame(
            Storage::url('surat-pengantar-magang/proposals/legacy.pdf'),
            $magang->fresh()->proposal_kegiatan_magang_path,
        );

        [$suratTugasStudent] = $this->completeMahasiswa();
        Storage::disk('local')->put('surat-tugas/supporting/proposals/legacy.pdf', self::PDF_BYTES);
        Storage::disk('local')->put('surat-tugas/supporting/pengantar/legacy.pdf', self::PDF_BYTES);
        $suratTugas = $this->suratTugasApplication($suratTugasStudent, [
            'proposal_kegiatan_magang_path' => 'surat-tugas/supporting/proposals/legacy.pdf',
            'surat_pengantar_magang_path' => 'surat-tugas/supporting/pengantar/legacy.pdf',
        ]);

        $this->assertSame(0, LetterApplicationAttachment::query()
            ->where('letter_type', SuratTugasApplication::LETTER_TYPE)
            ->where('application_id', $suratTugas->id)
            ->count());
        $this->actingAs($suratTugasStudent, 'sanctum')
            ->get("/api/surat-tugas/{$suratTugas->id}/supporting-documents/proposal/preview")
            ->assertNotFound();
        $this->actingAs($suratTugasStudent, 'sanctum')
            ->get("/api/surat-tugas/{$suratTugas->id}/supporting-documents/surat_pengantar_magang/preview")
            ->assertNotFound();
        $this->assertSame(
            'surat-tugas/supporting/proposals/legacy.pdf',
            $suratTugas->fresh()->proposal_kegiatan_magang_path,
        );
    }

    private function assertManagedAttachment(
        string $letterType,
        int $applicationId,
        string $documentKey,
    ): LetterApplicationAttachment {
        $attachment = LetterApplicationAttachment::query()
            ->where('letter_type', $letterType)
            ->where('application_id', $applicationId)
            ->where('document_key', $documentKey)
            ->firstOrFail();

        $this->assertSame('local', $attachment->storage_disk);
        $this->assertNotNull($attachment->checksum_sha256);
        $this->assertStringStartsWith('letter-application-attachments/', $attachment->storage_path);
        $this->assertTrue(Storage::disk('local')->exists($attachment->storage_path));

        return $attachment;
    }

    private function assertPreviewPdf($response): void
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
        $this->assertEmpty($response->headers->get('Content-Disposition') ?? '');
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    /**
     * @param array<string, mixed>|null $application
     * @param string[] $fields
     */
    private function assertRetiredAttachmentFieldsAbsent(?array $application, array $fields): void
    {
        $this->assertIsArray($application);

        foreach ($fields as $field) {
            $this->assertArrayNotHasKey($field, $application);
        }
    }

    private function magangDraftPayload(array $overrides = []): array
    {
        return array_merge([
            'nama_penerima' => 'HR Department',
            'jabatan_penerima' => 'Kepala Divisi Teknologi',
            'nama_perusahaan' => 'PT Test',
            'alamat_perusahaan' => 'Jl. Test',
            'alamat_jalan' => 'Jl. Test No. 1',
            'alamat_kelurahan' => 'Caturtunggal',
            'alamat_kecamatan' => 'Depok',
            'alamat_kota_kabupaten' => 'Sleman',
            'alamat_provinsi' => 'Daerah Istimewa Yogyakarta',
            'kode_pos' => '55281',
            'peran' => 'Software Engineer Intern',
            'rentang_tanggal' => '1 Juni 2026 - 31 Agustus 2026',
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
            'dosen_pembimbing_dpa' => 'Dr. Test',
        ], $overrides);
    }

    private function mockSuratTugasPreviewGenerationAlwaysReady(): void
    {
        $this->app->instance(
            \App\Services\SuratTugasPreviewGenerationService::class,
            \Mockery::mock(\App\Services\SuratTugasPreviewGenerationService::class, function ($mock): void {
                $mock->shouldReceive('generateForPhase')->once()->andReturnUsing(
                    fn ($application, string $phase) => \App\Models\LetterDocumentArtifact::make([
                        'letter_type' => SuratTugasApplication::LETTER_TYPE,
                        'application_id' => $application->getKey(),
                        'phase' => $phase,
                        'status' => \App\Models\LetterDocumentArtifact::STATUS_READY,
                    ])
                );
            }),
        );
    }
}
