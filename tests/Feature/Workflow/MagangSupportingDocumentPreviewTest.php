<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MagangSupportingDocumentPreviewTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private const PDF_BYTES = "%PDF-1.4\n%fake test pdf body\n%%EOF\n";
    private const LETTER_TYPE = SuratPengantarMagangApplication::LETTER_TYPE;

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

    private function makeMagangWithProposal(?User $student = null): SuratPengantarMagangApplication
    {
        Storage::fake('local');
        Storage::fake('public');

        if (!$student) {
            [$student] = $this->completeMahasiswa();
        }

        Storage::disk('public')->put('surat-pengantar-magang/proposals/test.pdf', self::PDF_BYTES);

        $application = $this->magangApplication($student, [
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/test.pdf'),
        ]);

        $this->attachRegistryDocument($application, self::LETTER_TYPE, 'proposal', 'test.pdf', self::PDF_BYTES);

        return $application;
    }

    private function previewPath(SuratPengantarMagangApplication $application, string $field): string
    {
        return "/api/surat-pengantar-magang/{$application->id}/supporting-documents/{$field}/preview";
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

    public function test_persuratan_tendik_can_preview_proposal(): void
    {
        $application = $this->makeMagangWithProposal();
        $tendik = $this->tendikPersuratan([self::LETTER_TYPE]);

        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($tendik, 'sanctum')->get($this->previewPath($application, 'proposal'))
        );
    }

    public function test_non_persuratan_tendik_cannot_preview(): void
    {
        $application = $this->makeMagangWithProposal();
        $tendikSarpras = $this->tendikSarpras();

        $this->actingAs($tendikSarpras, 'sanctum')
            ->get($this->previewPath($application, 'proposal'))
            ->assertForbidden();
    }

    public function test_persuratan_tendik_without_magang_assignment_cannot_preview(): void
    {
        $application = $this->makeMagangWithProposal();
        // Persuratan but assigned to a different letter type — canHandle returns false for Magang.
        $tendik = $this->tendikPersuratan([\App\Models\ScholarshipApplication::LETTER_TYPE]);

        $this->actingAs($tendik, 'sanctum')
            ->get($this->previewPath($application, 'proposal'))
            ->assertForbidden();
    }

    public function test_owning_mahasiswa_can_preview_own_proposal(): void
    {
        $application = $this->makeMagangWithProposal();
        $owner = User::find($application->user_id);

        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($owner, 'sanctum')->get($this->previewPath($application, 'proposal'))
        );
    }

    public function test_other_mahasiswa_cannot_preview(): void
    {
        $application = $this->makeMagangWithProposal();
        [$intruder] = $this->completeMahasiswa();

        $this->actingAs($intruder, 'sanctum')
            ->get($this->previewPath($application, 'proposal'))
            ->assertForbidden();
    }

    public function test_scoped_kaprodi_can_preview(): void
    {
        $studyProgram = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $studyProgram);
        $application = $this->makeMagangWithProposal($student);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $studyProgram->id]);

        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($kaprodi, 'sanctum')->get($this->previewPath($application, 'proposal'))
        );
    }

    public function test_wrong_prodi_kaprodi_cannot_preview(): void
    {
        $studyProgramA = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $studyProgramA);
        $application = $this->makeMagangWithProposal($student);

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
        $application = $this->makeMagangWithProposal($student);

        $kadep = $this->akademik('kadep', ['department_id' => $studyProgram->department_id]);

        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($kadep, 'sanctum')->get($this->previewPath($application, 'proposal'))
        );
    }

    public function test_wrong_department_kadep_cannot_preview(): void
    {
        $studyProgramA = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $studyProgramA);
        $application = $this->makeMagangWithProposal($student);

        $otherDepartment = $this->department();
        $foreignKadep = $this->akademik('kadep', ['department_id' => $otherDepartment->id]);

        $this->actingAs($foreignKadep, 'sanctum')
            ->get($this->previewPath($application, 'proposal'))
            ->assertForbidden();
    }

    public function test_invalid_field_is_rejected_safely(): void
    {
        $application = $this->makeMagangWithProposal();
        $tendik = $this->tendikPersuratan([self::LETTER_TYPE]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->get($this->previewPath($application, 'generated_docx'));

        $response->assertNotFound();
        $this->assertEmpty($response->headers->get('Content-Disposition') ?? '');
    }

    public function test_missing_file_returns_safe_not_found(): void
    {
        $application = $this->makeMagangWithProposal();
        $path = LetterApplicationAttachment::query()
            ->where('letter_type', self::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->where('document_key', 'proposal')
            ->value('storage_path');
        Storage::disk('local')->delete($path);

        $tendik = $this->tendikPersuratan([self::LETTER_TYPE]);

        $this->actingAs($tendik, 'sanctum')
            ->get($this->previewPath($application, 'proposal'))
            ->assertNotFound();
    }

    public function test_unauthenticated_user_is_denied(): void
    {
        $application = $this->makeMagangWithProposal();

        $this->get($this->previewPath($application, 'proposal'))
            ->assertStatus(401);
    }

    public function test_path_outside_allowed_prefix_is_rejected(): void
    {
        $application = $this->makeMagangWithProposal();
        $attachment = LetterApplicationAttachment::query()
            ->where('letter_type', self::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->where('document_key', 'proposal')
            ->firstOrFail();
        $attachment->update(['storage_path' => 'letter-application-attachments/other/sample.pdf']);
        Storage::disk('local')->put('letter-application-attachments/other/sample.pdf', self::PDF_BYTES);

        $tendik = $this->tendikPersuratan([self::LETTER_TYPE]);

        $this->actingAs($tendik, 'sanctum')
            ->get($this->previewPath($application, 'proposal'))
            ->assertNotFound();
    }
}
