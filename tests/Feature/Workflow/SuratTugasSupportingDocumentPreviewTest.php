<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\SuratTugasApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuratTugasSupportingDocumentPreviewTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private const PDF_BYTES = "%PDF-1.4\n%fake test pdf body\n%%EOF\n";

    private const PROPOSAL_PATH = 'surat-tugas/supporting/proposals/test.pdf';
    private const PENGANTAR_PATH = 'surat-tugas/supporting/pengantar/test.pdf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->restoreRetiredAttachmentColumnsForLegacyFixtureTests();
    }

    protected function tearDown(): void
    {
        try {
            $this->dropRetiredAttachmentColumnsForLegacyFixtureTests();
        } finally {
            parent::tearDown();
        }
    }

    private function makeSuratTugasWithDocs(?User $student = null): SuratTugasApplication
    {
        // S2c-0 security: Surat Tugas supporting PDFs live on the PRIVATE local
        // disk (never the public/symlinked disk), reachable only via the
        // authenticated preview endpoint.
        Storage::fake('local');
        Storage::fake('public');

        if (!$student) {
            [$student] = $this->completeMahasiswa();
        }

        Storage::disk('local')->put(self::PROPOSAL_PATH, self::PDF_BYTES);
        Storage::disk('local')->put(self::PENGANTAR_PATH, self::PDF_BYTES);

        $application = $this->suratTugasApplication($student, [
            'proposal_kegiatan_magang_path' => self::PROPOSAL_PATH,
            'surat_pengantar_magang_path' => self::PENGANTAR_PATH,
        ]);

        $this->attachRegistryDocument($application, SuratTugasApplication::LETTER_TYPE, 'proposal', 'test.pdf', self::PDF_BYTES);
        $this->attachRegistryDocument($application, SuratTugasApplication::LETTER_TYPE, 'surat_pengantar_magang', 'pengantar.pdf', self::PDF_BYTES);

        return $application;
    }

    private function previewPath(SuratTugasApplication $application, string $field): string
    {
        return "/api/surat-tugas/{$application->id}/supporting-documents/{$field}/preview";
    }

    private function assertFetchOnlyPdfPayload($response): void
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
        $this->assertEmpty($response->headers->get('Content-Disposition') ?? '');
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    public function test_supporting_docs_are_on_private_local_disk_not_public(): void
    {
        $application = $this->makeSuratTugasWithDocs();

        // Stored on private local disk; NOT on the public (symlinked) disk → not
        // raw web-accessible. Reachable only via the authenticated endpoint.
        $this->assertTrue(Storage::disk('local')->exists(self::PROPOSAL_PATH));
        $this->assertTrue(Storage::disk('local')->exists(self::PENGANTAR_PATH));
        $this->assertFalse(Storage::disk('public')->exists(self::PROPOSAL_PATH));
        $this->assertFalse(Storage::disk('public')->exists(self::PENGANTAR_PATH));
        $this->assertStringStartsWith('surat-tugas/supporting/', $application->proposal_kegiatan_magang_path);
        $this->assertStringStartsWith('surat-tugas/supporting/', $application->surat_pengantar_magang_path);

        $rows = LetterApplicationAttachment::query()
            ->where('letter_type', SuratTugasApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->get()
            ->keyBy('document_key');
        $this->assertStringStartsWith('letter-application-attachments/', $rows['proposal']->storage_path);
        $this->assertStringStartsWith('letter-application-attachments/', $rows['surat_pengantar_magang']->storage_path);
        $this->assertTrue(Storage::disk('local')->exists($rows['proposal']->storage_path));
        $this->assertTrue(Storage::disk('local')->exists($rows['surat_pengantar_magang']->storage_path));
    }

    public function test_owning_mahasiswa_can_preview_both_fields(): void
    {
        $application = $this->makeSuratTugasWithDocs();
        $owner = User::find($application->user_id);

        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($owner, 'sanctum')->get($this->previewPath($application, 'proposal'))
        );
        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($owner, 'sanctum')->get($this->previewPath($application, 'surat_pengantar_magang'))
        );
    }

    public function test_other_mahasiswa_cannot_preview(): void
    {
        $application = $this->makeSuratTugasWithDocs();
        [$intruder] = $this->completeMahasiswa();

        $this->actingAs($intruder, 'sanctum')
            ->get($this->previewPath($application, 'proposal'))
            ->assertForbidden();
    }

    public function test_scoped_kaprodi_can_preview_and_wrong_prodi_cannot(): void
    {
        $studyProgram = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $studyProgram);
        $application = $this->makeSuratTugasWithDocs($student);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $studyProgram->id]);
        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($kaprodi, 'sanctum')->get($this->previewPath($application, 'proposal'))
        );

        $otherProgram = $this->studyProgram();
        $foreignKaprodi = $this->akademik('kaprodi', ['study_program_id' => $otherProgram->id]);
        $this->actingAs($foreignKaprodi, 'sanctum')
            ->get($this->previewPath($application, 'proposal'))
            ->assertForbidden();
    }

    public function test_scoped_kadep_can_preview(): void
    {
        $studyProgram = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $studyProgram);
        $application = $this->makeSuratTugasWithDocs($student);

        $kadep = $this->akademik('kadep', ['department_id' => $studyProgram->department_id]);
        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($kadep, 'sanctum')->get($this->previewPath($application, 'surat_pengantar_magang'))
        );
    }

    public function test_assigned_persuratan_tendik_can_preview(): void
    {
        // Pass A: surat-tugas is registered in LetterTypeRegistry, so a Persuratan
        // Tendik whose assigned_tasks include surat-tugas can now preview.
        $application = $this->makeSuratTugasWithDocs();
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);

        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($tendik, 'sanctum')->get($this->previewPath($application, 'proposal'))
        );
        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($tendik, 'sanctum')->get($this->previewPath($application, 'surat_pengantar_magang'))
        );
    }

    public function test_persuratan_tendik_without_surat_tugas_assignment_cannot_preview(): void
    {
        $application = $this->makeSuratTugasWithDocs();
        // Assigned to a different letter type → canHandle('surat-tugas') is false.
        $tendik = $this->tendikPersuratan([\App\Models\ScholarshipApplication::LETTER_TYPE]);

        $this->actingAs($tendik, 'sanctum')
            ->get($this->previewPath($application, 'proposal'))
            ->assertForbidden();
    }

    public function test_non_persuratan_tendik_cannot_preview(): void
    {
        $application = $this->makeSuratTugasWithDocs();
        $this->actingAs($this->tendikSarpras(), 'sanctum')
            ->get($this->previewPath($application, 'proposal'))
            ->assertForbidden();
    }

    public function test_invalid_field_is_rejected_safely(): void
    {
        $application = $this->makeSuratTugasWithDocs();
        $owner = User::find($application->user_id);

        $response = $this->actingAs($owner, 'sanctum')
            ->get($this->previewPath($application, 'generated_docx'));

        $response->assertNotFound();
        $this->assertEmpty($response->headers->get('Content-Disposition') ?? '');
    }

    public function test_missing_file_returns_safe_not_found(): void
    {
        $application = $this->makeSuratTugasWithDocs();
        $path = LetterApplicationAttachment::query()
            ->where('letter_type', SuratTugasApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->where('document_key', 'proposal')
            ->value('storage_path');
        Storage::disk('local')->delete($path);

        $owner = User::find($application->user_id);
        $this->actingAs($owner, 'sanctum')
            ->get($this->previewPath($application, 'proposal'))
            ->assertNotFound();
    }

    public function test_unauthenticated_user_is_denied(): void
    {
        $application = $this->makeSuratTugasWithDocs();
        $this->get($this->previewPath($application, 'proposal'))->assertStatus(401);
    }

    public function test_path_outside_allowed_prefix_is_rejected(): void
    {
        $application = $this->makeSuratTugasWithDocs();
        $attachment = LetterApplicationAttachment::query()
            ->where('letter_type', SuratTugasApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->where('document_key', 'proposal')
            ->firstOrFail();
        $attachment->update(['storage_path' => 'letter-application-attachments/other/sample.pdf']);
        Storage::disk('local')->put('letter-application-attachments/other/sample.pdf', self::PDF_BYTES);

        $owner = User::find($application->user_id);
        $this->actingAs($owner, 'sanctum')
            ->get($this->previewPath($application, 'proposal'))
            ->assertNotFound();
    }
}
