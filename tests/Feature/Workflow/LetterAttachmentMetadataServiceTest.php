<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Services\LetterAttachmentMetadataService;
use App\Support\LetterAttachmentDefinitionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use stdClass;
use Tests\TestCase;

class LetterAttachmentMetadataServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private LetterAttachmentMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restoreRetiredAttachmentColumnsForLegacyFixtureTests();
        Storage::fake('local');
        Storage::fake('public');

        $this->service = $this->app->make(LetterAttachmentMetadataService::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->dropRetiredAttachmentColumnsForLegacyFixtureTests();
        } finally {
            parent::tearDown();
        }
    }

    public function test_document_key_contract_excludes_ktm_and_keeps_zero_document_letters_empty(): void
    {
        $scholarship = $this->scholarshipApplication();
        $magang = $this->magangApplication();
        $suratTugas = $this->suratTugasApplication();

        $scholarshipMetadata = (array) $this->service->forApplication($scholarship, ScholarshipApplication::LETTER_TYPE);

        $this->assertSame(['transkrip_nilai', 'slip_gaji_ayah', 'slip_gaji_ibu'], array_keys($scholarshipMetadata));
        $this->assertArrayNotHasKey('ktm', $scholarshipMetadata);
        $this->assertSame(['proposal'], array_keys((array) $this->service->forApplication($magang, SuratPengantarMagangApplication::LETTER_TYPE)));
        $this->assertSame(['proposal', 'surat_pengantar_magang'], array_keys((array) $this->service->forApplication($suratTugas, SuratTugasApplication::LETTER_TYPE)));
        $this->assertEquals(new stdClass(), $this->service->forApplicationId(SuratKeteranganAktifApplication::LETTER_TYPE, 123));
        $this->assertSame('{}', json_encode($this->service->forApplicationId(SuratKeteranganAktifApplication::LETTER_TYPE, 123)));
    }

    public function test_existing_registry_attachment_returns_safe_metadata_and_missing_rows_are_false(): void
    {
        $application = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => 'attachment://transkrip_nilai/Legacy Transkrip.pdf',
            'slip_gaji_ayah_path' => 'attachment://slip_gaji_ayah/Legacy Ayah.pdf',
        ]);
        $row = $this->registryAttachment(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            'Transkrip Metadata.pdf',
            "%PDF metadata\n"
        );

        $metadata = (array) $this->service->forApplication($application, ScholarshipApplication::LETTER_TYPE);

        $this->assertTrue($metadata['transkrip_nilai']['exists']);
        $this->assertSame('Transkrip Metadata.pdf', $metadata['transkrip_nilai']['original_filename']);
        $this->assertSame('application/pdf', $metadata['transkrip_nilai']['mime_type']);
        $this->assertSame(strlen("%PDF metadata\n"), $metadata['transkrip_nilai']['size_bytes']);
        $this->assertTrue($metadata['transkrip_nilai']['preview_available']);
        $this->assertFalse($metadata['slip_gaji_ayah']['exists']);
        $this->assertFalse($metadata['slip_gaji_ibu']['exists']);
        $this->assertMetadataDoesNotLeakPrivateState($metadata, $row->storage_path);
    }

    public function test_missing_or_invalid_registry_rows_do_not_fallback_to_legacy_markers(): void
    {
        $application = $this->scholarshipApplication(null, [
            'transkrip_nilai_path' => 'attachment://transkrip_nilai/Legacy Transkrip.pdf',
        ]);

        $metadata = (array) $this->service->forApplication($application, ScholarshipApplication::LETTER_TYPE);
        $this->assertFalse($metadata['transkrip_nilai']['exists']);
        $this->assertStringNotContainsString('Legacy Transkrip.pdf', json_encode($metadata));

        Storage::disk('local')->put('letter-application-attachments/other/private.pdf', '%PDF invalid');
        LetterApplicationAttachment::create([
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'document_key' => 'transkrip_nilai',
            'original_filename' => 'Invalid Prefix.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
            'storage_disk' => 'local',
            'storage_path' => 'letter-application-attachments/other/private.pdf',
            'checksum_sha256' => str_repeat('b', 64),
            'uploaded_by' => $application->user_id,
        ]);

        $metadata = (array) $this->service->forApplication($application, ScholarshipApplication::LETTER_TYPE);
        $this->assertFalse($metadata['transkrip_nilai']['exists']);
    }

    public function test_metadata_query_is_batched_for_active_documents(): void
    {
        $application = $this->scholarshipApplication();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->service->forApplication($application, ScholarshipApplication::LETTER_TYPE);

        $attachmentQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => preg_match('/from\s+[`"]?letter_application_attachments/i', $query['query']) === 1)
            ->values();

        DB::disableQueryLog();

        $this->assertCount(1, $attachmentQueries);
    }

    public function test_mahasiswa_draft_and_detail_include_magang_metadata_and_retire_legacy_marker(): void
    {
        [$student] = $this->completeMahasiswa();
        $marker = 'attachment://proposal/Proposal Magang.pdf';
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'proposal_kegiatan_magang_path' => $marker,
        ]);
        $this->registryAttachment(
            $application,
            SuratPengantarMagangApplication::LETTER_TYPE,
            'proposal',
            'Proposal Magang.pdf'
        );

        $draftResponse = $this->actingAs($student, 'sanctum')
            ->getJson('/api/mahasiswa/surat-pengantar-magang/draft');

        $draftResponse->assertOk()
            ->assertJsonPath('application.supporting_documents.proposal.exists', true)
            ->assertJsonPath('application.supporting_documents.proposal.original_filename', 'Proposal Magang.pdf');
        $this->assertRetiredAttachmentFieldsAbsent($draftResponse->json('application'), [
            'proposal_kegiatan_magang_path',
        ]);

        $detailResponse = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-pengantar-magang/{$application->id}");

        $detailResponse->assertOk()
            ->assertJsonPath('application.supporting_documents.proposal.exists', true);
        $this->assertRetiredAttachmentFieldsAbsent($detailResponse->json('application'), [
            'proposal_kegiatan_magang_path',
        ]);

        $this->assertSame($marker, $application->fresh()->proposal_kegiatan_magang_path);
    }

    public function test_beasiswa_detail_includes_metadata_and_retires_legacy_attachment_fields(): void
    {
        [$student] = $this->completeMahasiswa();
        $marker = 'attachment://transkrip_nilai/Transkrip Beasiswa.pdf';
        $application = $this->scholarshipApplication($student, [
            'transkrip_nilai_path' => $marker,
            'slip_gaji_ayah_path' => 'attachment://slip_gaji_ayah/Slip Ayah Legacy.pdf',
            'slip_gaji_ibu_path' => null,
            'ktm_path' => 'attachment://ktm/KTM Legacy.pdf',
        ]);
        $this->registryAttachment(
            $application,
            ScholarshipApplication::LETTER_TYPE,
            'transkrip_nilai',
            'Transkrip Beasiswa.pdf'
        );

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}");

        $response->assertOk()
            ->assertJsonPath('application.supporting_documents.transkrip_nilai.exists', true)
            ->assertJsonPath('application.supporting_documents.slip_gaji_ayah.exists', false)
            ->assertJsonPath('application.supporting_documents.slip_gaji_ibu.exists', false);
        $this->assertRetiredAttachmentFieldsAbsent($response->json('application'), [
            'transkrip_nilai_path',
            'slip_gaji_ayah_path',
            'slip_gaji_ibu_path',
            'ktm_path',
        ]);

        $this->assertStringNotContainsString('attachment://', json_encode($response->json('application.supporting_documents')));

        $fresh = $application->fresh();
        $this->assertSame($marker, $fresh->transkrip_nilai_path);
        $this->assertSame('attachment://slip_gaji_ayah/Slip Ayah Legacy.pdf', $fresh->slip_gaji_ayah_path);
        $this->assertNull($fresh->slip_gaji_ibu_path);
        $this->assertSame('attachment://ktm/KTM Legacy.pdf', $fresh->ktm_path);
    }

    public function test_reviewer_endpoints_include_metadata_for_tendik_and_akademik_surfaces(): void
    {
        $program = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
            'proposal_kegiatan_magang_path' => 'surat-tugas/supporting/proposals/legacy-proposal.pdf',
            'surat_pengantar_magang_path' => 'surat-tugas/supporting/pengantar/legacy-pengantar.pdf',
        ]);
        $this->registryAttachment($application, SuratTugasApplication::LETTER_TYPE, 'proposal', 'Proposal ST.pdf');
        $this->registryAttachment($application, SuratTugasApplication::LETTER_TYPE, 'surat_pengantar_magang', 'Pengantar ST.pdf');

        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $tendikResponse = $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-tugas/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.supporting_documents.proposal.exists', true)
            ->assertJsonPath('application.supporting_documents.surat_pengantar_magang.exists', true);
        $this->assertRetiredAttachmentFieldsAbsent($tendikResponse->json('application'), [
            'proposal_kegiatan_magang_path',
            'surat_pengantar_magang_path',
        ]);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $program->id]);
        $akademikResponse = $this->actingAs($kaprodi, 'sanctum')
            ->getJson("/api/akademik/surat-tugas/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.supporting_documents.proposal.original_filename', 'Proposal ST.pdf')
            ->assertJsonPath('application.supporting_documents.surat_pengantar_magang.original_filename', 'Pengantar ST.pdf');
        $this->assertRetiredAttachmentFieldsAbsent($akademikResponse->json('application'), [
            'proposal_kegiatan_magang_path',
            'surat_pengantar_magang_path',
        ]);

        $fresh = $application->fresh();
        $this->assertSame('surat-tugas/supporting/proposals/legacy-proposal.pdf', $fresh->proposal_kegiatan_magang_path);
        $this->assertSame('surat-tugas/supporting/pengantar/legacy-pengantar.pdf', $fresh->surat_pengantar_magang_path);
    }

    public function test_beasiswa_and_magang_reviewer_details_retain_metadata_without_legacy_attachment_fields(): void
    {
        $program = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $program);

        $beasiswa = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
            'transkrip_nilai_path' => 'attachment://transkrip_nilai/Legacy Transkrip.pdf',
            'slip_gaji_ayah_path' => 'attachment://slip_gaji_ayah/Legacy Ayah.pdf',
            'slip_gaji_ibu_path' => 'attachment://slip_gaji_ibu/Legacy Ibu.pdf',
            'ktm_path' => 'attachment://ktm/Legacy KTM.pdf',
        ]);
        $this->registryAttachment($beasiswa, ScholarshipApplication::LETTER_TYPE, 'transkrip_nilai', 'Transkrip.pdf');
        $this->registryAttachment($beasiswa, ScholarshipApplication::LETTER_TYPE, 'slip_gaji_ayah', 'Slip Ayah.pdf');
        $this->registryAttachment($beasiswa, ScholarshipApplication::LETTER_TYPE, 'slip_gaji_ibu', 'Slip Ibu.pdf');

        $magang = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            'proposal_kegiatan_magang_path' => 'attachment://proposal/Legacy Proposal.pdf',
        ]);
        $this->registryAttachment($magang, SuratPengantarMagangApplication::LETTER_TYPE, 'proposal', 'Proposal Magang.pdf');

        $tendik = $this->tendikPersuratan([
            ScholarshipApplication::LETTER_TYPE,
            SuratPengantarMagangApplication::LETTER_TYPE,
        ]);

        $beasiswaTendikResponse = $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-permohonan-beasiswa/{$beasiswa->id}")
            ->assertOk()
            ->assertJsonPath('application.supporting_documents.transkrip_nilai.exists', true)
            ->assertJsonPath('application.supporting_documents.slip_gaji_ayah.exists', true)
            ->assertJsonPath('application.supporting_documents.slip_gaji_ibu.exists', true);
        $this->assertRetiredAttachmentFieldsAbsent($beasiswaTendikResponse->json('application'), [
            'transkrip_nilai_path',
            'slip_gaji_ayah_path',
            'slip_gaji_ibu_path',
            'ktm_path',
        ]);

        $magangTendikResponse = $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-pengantar-magang/{$magang->id}")
            ->assertOk()
            ->assertJsonPath('application.supporting_documents.proposal.original_filename', 'Proposal Magang.pdf');
        $this->assertRetiredAttachmentFieldsAbsent($magangTendikResponse->json('application'), [
            'proposal_kegiatan_magang_path',
        ]);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $program->id]);

        $beasiswaAkademikResponse = $this->actingAs($kaprodi, 'sanctum')
            ->getJson("/api/akademik/surat-permohonan-beasiswa/{$beasiswa->id}")
            ->assertOk()
            ->assertJsonPath('application.supporting_documents.transkrip_nilai.original_filename', 'Transkrip.pdf');
        $this->assertRetiredAttachmentFieldsAbsent($beasiswaAkademikResponse->json('application'), [
            'transkrip_nilai_path',
            'slip_gaji_ayah_path',
            'slip_gaji_ibu_path',
            'ktm_path',
        ]);

        $magangAkademikResponse = $this->actingAs($kaprodi, 'sanctum')
            ->getJson("/api/akademik/surat-pengantar-magang/{$magang->id}")
            ->assertOk()
            ->assertJsonPath('application.supporting_documents.proposal.exists', true);
        $this->assertRetiredAttachmentFieldsAbsent($magangAkademikResponse->json('application'), [
            'proposal_kegiatan_magang_path',
        ]);

        $this->assertSame('attachment://transkrip_nilai/Legacy Transkrip.pdf', $beasiswa->fresh()->transkrip_nilai_path);
        $this->assertSame('attachment://proposal/Legacy Proposal.pdf', $magang->fresh()->proposal_kegiatan_magang_path);
    }

    public function test_zero_descriptor_endpoint_serializes_empty_supporting_documents_object(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}");

        $response->assertOk()
            ->assertJsonPath('application.generated_pdf_path', null);
        $this->assertStringContainsString('"supporting_documents":{}', $response->getContent());
    }

    private function registryAttachment(
        Model $application,
        string $letterType,
        string $documentKey,
        string $filename = 'Document.pdf',
        string $body = "%PDF metadata\n",
    ): LetterApplicationAttachment {
        $definition = LetterAttachmentDefinitionRegistry::document($letterType, $documentKey);
        $this->assertIsArray($definition);

        $storagePath = $definition['storage_prefix'] . $application->id . '/' . Str::uuid() . '.pdf';
        Storage::disk('local')->put($storagePath, $body);

        return LetterApplicationAttachment::create([
            'letter_type' => $letterType,
            'application_id' => $application->getKey(),
            'document_key' => $documentKey,
            'original_filename' => $filename,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($body),
            'storage_disk' => 'local',
            'storage_path' => $storagePath,
            'checksum_sha256' => hash('sha256', $body),
            'uploaded_by' => $application->getAttribute('user_id'),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function assertMetadataDoesNotLeakPrivateState(array $metadata, string $storagePath): void
    {
        $json = json_encode($metadata);

        foreach ([
            'storage_disk',
            'storage_path',
            'checksum_sha256',
            'uploaded_by',
            'letter-application-attachments',
            'attachment://',
            '/api/storage',
            '/storage/',
            $storagePath,
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
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
}
