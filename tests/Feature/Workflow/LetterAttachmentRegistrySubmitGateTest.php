<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Services\BeasiswaPreviewGenerationService;
use App\Services\LetterAttachmentRequirementService;
use App\Services\SuratPengantarMagangPreviewGenerationService;
use App\Services\SuratTugasPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class LetterAttachmentRegistrySubmitGateTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-04 10:00:00'));
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_required_submit_key_contract_comes_from_definition_registry(): void
    {
        $service = $this->app->make(LetterAttachmentRequirementService::class);

        $this->assertSame(['proposal'], $service->requiredDocumentKeys(SuratPengantarMagangApplication::LETTER_TYPE));
        $this->assertSame(['proposal', 'surat_pengantar_magang'], $service->requiredDocumentKeys(SuratTugasApplication::LETTER_TYPE));
        $this->assertSame(['transkrip_nilai', 'slip_gaji_ayah', 'slip_gaji_ibu'], $service->requiredDocumentKeys(ScholarshipApplication::LETTER_TYPE));
        $this->assertSame([], $service->requiredDocumentKeys(SuratKeteranganAktifApplication::LETTER_TYPE));
        $this->assertSame([], $service->requiredDocumentKeys(ProsesLuarNegeriApplication::LETTER_TYPE));

        $encoded = json_encode([
            $service->requiredDocumentKeys(SuratPengantarMagangApplication::LETTER_TYPE),
            $service->requiredDocumentKeys(SuratTugasApplication::LETTER_TYPE),
            $service->requiredDocumentKeys(ScholarshipApplication::LETTER_TYPE),
        ]);
        $this->assertStringNotContainsString('ktm', $encoded);
        $this->assertStringNotContainsString('attachment://', $encoded);
        $this->assertStringNotContainsString('/storage/', $encoded);
        $this->assertStringNotContainsString('letter-application-attachments', $encoded);
    }

    public function test_requirement_service_uses_one_registry_query_and_validates_managed_target(): void
    {
        $service = $this->app->make(LetterAttachmentRequirementService::class);
        $application = $this->suratTugasApplication(null, [
            'status' => SuratTugasApplication::STATUS_DRAFT,
            'submitted_at' => null,
        ]);
        $this->attachRegistryDocument($application, SuratTugasApplication::LETTER_TYPE, 'proposal', 'Proposal ST.pdf');
        LetterApplicationAttachment::create([
            'letter_type' => SuratTugasApplication::LETTER_TYPE,
            'application_id' => $application->getKey(),
            'document_key' => 'surat_pengantar_magang',
            'original_filename' => 'Wrong Prefix.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
            'storage_disk' => 'local',
            'storage_path' => 'letter-application-attachments/other/private.pdf',
            'checksum_sha256' => str_repeat('a', 64),
            'uploaded_by' => $application->user_id,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $missing = $service->missingRequiredDocumentKeys(SuratTugasApplication::LETTER_TYPE, (int) $application->getKey());

        $attachmentQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => preg_match('/from\s+[`"]?letter_application_attachments/i', $query['query']) === 1)
            ->values();
        DB::disableQueryLog();

        $this->assertSame(['surat_pengantar_magang'], $missing);
        $this->assertCount(1, $attachmentQueries);
        $this->assertStringNotContainsString('attachment://', json_encode($missing));
        $this->assertStringNotContainsString('letter-application-attachments', json_encode($missing));
    }

    public function test_magang_registry_row_allows_submit_when_legacy_marker_is_null(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
            'proposal_kegiatan_magang_path' => null,
        ]);
        $this->attachRegistryDocument($application, SuratPengantarMagangApplication::LETTER_TYPE, 'proposal', 'Proposal Magang.pdf');
        $this->mockMagangPreviewGenerationAlwaysReady();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-pengantar-magang/submit')
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertSame('2026-06-04 10:00:00', $fresh->submitted_at?->toDateTimeString());
    }

    public function test_magang_stale_legacy_marker_without_registry_row_blocks_submit(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
            'proposal_kegiatan_magang_path' => 'attachment://proposal/Stale.pdf',
        ]);
        $this->mockMagangPreviewGenerationNever();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-pengantar-magang/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['proposal_kegiatan_magang_path']);

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->assigned_to);
    }

    public function test_magang_missing_registry_row_blocks_submit(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
            'proposal_kegiatan_magang_path' => null,
        ]);
        $this->mockMagangPreviewGenerationNever();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-pengantar-magang/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['proposal_kegiatan_magang_path']);

        $this->assertSame(SuratPengantarMagangApplication::STATUS_DRAFT, $application->fresh()->status);
    }

    public function test_magang_draft_upload_writes_registry_row_and_leaves_legacy_column_null(): void
    {
        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', [
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
                'proposal_kegiatan_magang' => UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'),
            ])
            ->assertOk();

        $application = SuratPengantarMagangApplication::where('user_id', $student->id)->firstOrFail();
        $this->assertNull($application->proposal_kegiatan_magang_path);
        $this->assertDatabaseHas('letter_application_attachments', [
            'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'document_key' => 'proposal',
        ]);
    }

    public function test_surat_tugas_registry_rows_allow_submit_when_legacy_markers_are_null(): void
    {
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
            'proposal_kegiatan_magang_path' => null,
            'surat_pengantar_magang_path' => null,
        ]);
        $this->attachRegistryDocument($application, SuratTugasApplication::LETTER_TYPE, 'proposal', 'Proposal ST.pdf');
        $this->attachRegistryDocument($application, SuratTugasApplication::LETTER_TYPE, 'surat_pengantar_magang', 'Pengantar ST.pdf');
        $this->mockSuratTugasPreviewGenerationAlwaysReady();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-tugas/submit')
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);

        $this->assertSame(0, SuratPengantarMagangApplication::query()->count());
        $this->assertSame(2, LetterApplicationAttachment::query()
            ->where('letter_type', SuratTugasApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->count());
    }

    public function test_surat_tugas_missing_each_required_registry_key_blocks_submit(): void
    {
        [$studentA] = $this->completeMahasiswa();
        $missingProposal = $this->suratTugasApplication($studentA, [
            'status' => SuratTugasApplication::STATUS_DRAFT,
            'submitted_at' => null,
        ]);
        $this->attachRegistryDocument($missingProposal, SuratTugasApplication::LETTER_TYPE, 'surat_pengantar_magang', 'Pengantar ST.pdf');
        $this->actingAs($studentA, 'sanctum')
            ->postJson('/api/mahasiswa/surat-tugas/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['proposal_kegiatan_magang_path'])
            ->assertJsonMissingValidationErrors(['surat_pengantar_magang_path']);
        $this->assertSame(SuratTugasApplication::STATUS_DRAFT, $missingProposal->fresh()->status);

        [$studentB] = $this->completeMahasiswa();
        $missingPengantar = $this->suratTugasApplication($studentB, [
            'status' => SuratTugasApplication::STATUS_DRAFT,
            'submitted_at' => null,
        ]);
        $this->attachRegistryDocument($missingPengantar, SuratTugasApplication::LETTER_TYPE, 'proposal', 'Proposal ST.pdf');
        $this->actingAs($studentB, 'sanctum')
            ->postJson('/api/mahasiswa/surat-tugas/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['surat_pengantar_magang_path'])
            ->assertJsonMissingValidationErrors(['proposal_kegiatan_magang_path']);
        $this->assertSame(SuratTugasApplication::STATUS_DRAFT, $missingPengantar->fresh()->status);
    }

    public function test_surat_tugas_stale_legacy_markers_without_registry_rows_block_submit(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
            'proposal_kegiatan_magang_path' => 'attachment://proposal/Stale.pdf',
            'surat_pengantar_magang_path' => 'attachment://surat_pengantar_magang/Stale.pdf',
        ]);
        $this->mockSuratTugasPreviewGenerationNever();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-tugas/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'proposal_kegiatan_magang_path',
                'surat_pengantar_magang_path',
            ]);

        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->assigned_to);
    }

    public function test_surat_tugas_draft_upload_writes_registry_rows_and_leaves_legacy_columns_null(): void
    {
        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-tugas/draft', [
                'nama_perusahaan' => 'PT Test',
                'kegiatan' => 'Magang Kerja Praktik',
                'posisi' => 'Software Engineer Intern',
                'dosen_pembimbing_dpa' => 'Dr. Test',
                'tgl_mulai' => '2026-06-01',
                'tgl_selesai' => '2026-08-31',
                'proposal_kegiatan_magang' => UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'),
                'surat_pengantar_magang' => UploadedFile::fake()->create('pengantar.pdf', 100, 'application/pdf'),
            ])
            ->assertOk();

        $application = SuratTugasApplication::where('user_id', $student->id)->firstOrFail();
        $this->assertNull($application->proposal_kegiatan_magang_path);
        $this->assertNull($application->surat_pengantar_magang_path);
        $this->assertSame(2, LetterApplicationAttachment::query()
            ->where('letter_type', SuratTugasApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_beasiswa_registry_rows_allow_submit_when_legacy_fields_are_null(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
            'transkrip_nilai_path' => null,
            'slip_gaji_ayah_path' => null,
            'slip_gaji_ibu_path' => null,
        ]);
        $this->attachBeasiswaRequiredDocuments($application);
        $this->mockBeasiswaSubmitPreviewGenerationAlwaysReady();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/submit', [
                'declaration_accepted' => true,
            ])
            ->assertOk()
            ->assertJsonPath('application.status', ScholarshipApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);

        $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $application->fresh()->status);
        $this->assertSame($tendik->id, $application->fresh()->assigned_to);
        $this->assertSame(3, LetterApplicationAttachment::query()
            ->where('letter_type', ScholarshipApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->count());
    }

    public function test_beasiswa_missing_transkrip_blocks_submit(): void
    {
        $this->assertBeasiswaMissingRequiredDocumentBlocksSubmit('transkrip_nilai', 'transkrip_nilai_path');
    }

    public function test_beasiswa_missing_slip_gaji_ayah_blocks_submit(): void
    {
        $this->assertBeasiswaMissingRequiredDocumentBlocksSubmit('slip_gaji_ayah', 'slip_gaji_ayah_path');
    }

    public function test_beasiswa_missing_slip_gaji_ibu_blocks_submit(): void
    {
        $this->assertBeasiswaMissingRequiredDocumentBlocksSubmit('slip_gaji_ibu', 'slip_gaji_ibu_path');
    }

    public function test_beasiswa_stale_legacy_values_without_registry_rows_fail_submit(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
            'transkrip_nilai_path' => 'attachment://transkrip_nilai/Stale Transkrip.pdf',
            'slip_gaji_ayah_path' => '/storage/scholarships/slips/stale-ayah.pdf',
            'slip_gaji_ibu_path' => 'attachment://slip_gaji_ibu/Stale Ibu.pdf',
        ]);
        $this->mockBeasiswaSubmitPreviewGenerationNever();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/submit', [
                'declaration_accepted' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'transkrip_nilai_path',
                'slip_gaji_ayah_path',
                'slip_gaji_ibu_path',
            ]);

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->assigned_to);
        $this->assertSame(0, LetterApplicationAttachment::query()
            ->where('letter_type', ScholarshipApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->count());
    }

    public function test_beasiswa_declaration_gate_remains_unchanged(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/submit')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Anda harus menyetujui pernyataan kebenaran data sebelum mengirim.')
            ->assertJsonValidationErrors(['declaration_accepted']);

        $this->assertSame(ScholarshipApplication::STATUS_DRAFT, $application->fresh()->status);
    }

    private function assertBeasiswaMissingRequiredDocumentBlocksSubmit(string $missingKey, string $expectedErrorKey): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
            'transkrip_nilai_path' => null,
            'slip_gaji_ayah_path' => null,
            'slip_gaji_ibu_path' => null,
        ]);

        foreach (['transkrip_nilai', 'slip_gaji_ayah', 'slip_gaji_ibu'] as $documentKey) {
            if ($documentKey !== $missingKey) {
                $this->attachRegistryDocument($application, ScholarshipApplication::LETTER_TYPE, $documentKey, "{$documentKey}.pdf");
            }
        }
        $this->mockBeasiswaSubmitPreviewGenerationNever();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/submit', [
                'declaration_accepted' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$expectedErrorKey]);

        $fresh = $application->fresh();
        $this->assertSame(ScholarshipApplication::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->assigned_to);
    }

    private function mockMagangPreviewGenerationNever(): void
    {
        $mock = Mockery::mock(SuratPengantarMagangPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')->never();
        $this->app->instance(SuratPengantarMagangPreviewGenerationService::class, $mock);
    }

    private function mockSuratTugasPreviewGenerationAlwaysReady(): void
    {
        $mock = Mockery::mock(SuratTugasPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function ($application, string $phase) {
                return LetterDocumentArtifact::make([
                    'letter_type' => SuratTugasApplication::LETTER_TYPE,
                    'application_id' => $application->getKey(),
                    'phase' => $phase,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(SuratTugasPreviewGenerationService::class, $mock);
    }

    private function mockSuratTugasPreviewGenerationNever(): void
    {
        $mock = Mockery::mock(SuratTugasPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')->never();
        $this->app->instance(SuratTugasPreviewGenerationService::class, $mock);
    }

    private function mockBeasiswaSubmitPreviewGenerationAlwaysReady(): void
    {
        $mock = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function ($application, string $phase) {
                return LetterDocumentArtifact::make([
                    'letter_type' => ScholarshipApplication::LETTER_TYPE,
                    'application_id' => $application->getKey(),
                    'phase' => $phase,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(BeasiswaPreviewGenerationService::class, $mock);
    }

    private function mockBeasiswaSubmitPreviewGenerationNever(): void
    {
        $mock = Mockery::mock(BeasiswaPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')->never();
        $this->app->instance(BeasiswaPreviewGenerationService::class, $mock);
    }
}
